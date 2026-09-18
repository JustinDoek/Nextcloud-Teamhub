<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\OpenProject;

use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\OpenProject\OpenProjectProvisioningService;
use Psr\Log\LoggerInterface;

/**
 * The Phase 2 writes and reads against OpenProject (v4.9.6): templates,
 * identifiers, copy and its job, roles, memberships, user matching,
 * storages — and what a timeout, a 403 and a 422 become.
 */
class OpenProjectProvisioningServiceTest extends OpenProjectTestCase {

    /** @var list<array{0:string,1:string,2:array,3:string}> every request made */
    private array $requests = [];

    /** @param callable(string,string,array,string): mixed $handler */
    private function service(callable $handler): OpenProjectProvisioningService {
        $this->withHost();
        $this->withConnectedUser('alice');
        $this->requests = [];
        $op = $this->opService(function (string $uid, string $ep, array $params, string $method) use ($handler) {
            $this->requests[] = [$uid, $ep, $params, $method];
            return $handler($uid, $ep, $params, $method);
        });
        $client = $this->client($op);
        return new OpenProjectProvisioningService($client, $this->cache(), $this->createMock(LoggerInterface::class));
    }

    private static function jobStatus(string $status, ?int $projectId = null, ?string $message = null): array {
        $payload = $projectId !== null ? ['_links' => ['project' => ['href' => '/api/v3/projects/' . $projectId, 'title' => 'New']]] : [];
        return ['_type' => 'JobStatus', 'jobId' => 'abc-123', 'status' => $status, 'message' => $message, 'payload' => $payload, '_links' => ['self' => ['href' => '/api/v3/job_statuses/abc-123']]];
    }

    // ── Capabilities ───────────────────────────────────────────────────

    public function testCanCreateProjectsIsTheFormBeingOffered(): void {
        $svc = $this->service(fn ($u, $ep, $p, $m) => $ep === 'projects/form' && $m === 'POST' ? ['_type' => 'Form'] : ['error' => '', 'statusCode' => 500]);
        $this->assertTrue($svc->canCreateProjects('alice'));

        $svc = $this->service(fn () => ['error' => '{}', 'message' => 'no', 'statusCode' => 403]);
        $this->assertFalse($svc->canCreateProjects('alice'), '403 is "no", not an error');
    }

    public function testCanCreateProjectsPropagatesOtherFailures(): void {
        $svc = $this->service(fn () => ['error' => 'cURL error 28', 'statusCode' => 404]);
        $this->expectException(OpenProjectException::class);
        $svc->canCreateProjects('alice');
    }

    // ── Templates ──────────────────────────────────────────────────────

    public function testListTemplatesAsksForTemplatedProjectsTheUserMayCopy(): void {
        $svc = $this->service(fn () => self::collectionResponse([
            self::projectResponse(5, 'software-template') + ['name' => 'Software'],
            ['_type' => 'Program', 'id' => 6, 'identifier' => 'p'],
        ], 2, 'ProjectCollection'));
        $templates = $svc->listTemplates('alice');

        $this->assertCount(1, $templates, 'only Project resources');
        $this->assertSame(5, $templates[0]['id']);
        $filters = json_decode($this->requests[0][2]['filters'], true);
        $this->assertSame([
            ['templated' => ['operator' => '=', 'values' => ['t']]],
            ['active' => ['operator' => '=', 'values' => ['t']]],
            ['user_action' => ['operator' => '=', 'values' => ['projects/copy']]],
        ], $filters);
    }

    public function testInaccessibleTemplatesAreOpenProjectsRefusal(): void {
        $svc = $this->service(fn () => ['error' => '{}', 'message' => 'no', 'statusCode' => 403]);
        try {
            $svc->listTemplates('alice');
            $this->fail('expected an exception');
        } catch (OpenProjectException $e) {
            $this->assertSame(OpenProjectException::PERMISSION_DENIED, $e->getErrorCode());
        }
    }

    // ── Identifiers ────────────────────────────────────────────────────

    public function testIdentifierRulesAndSuggestions(): void {
        $this->assertTrue(OpenProjectProvisioningService::isValidIdentifier('proj-2026_a'));
        $this->assertFalse(OpenProjectProvisioningService::isValidIdentifier('-leading'));
        $this->assertFalse(OpenProjectProvisioningService::isValidIdentifier('Upper'));
        $this->assertFalse(OpenProjectProvisioningService::isValidIdentifier(''));
        $this->assertSame('marketing-2026', OpenProjectProvisioningService::suggestIdentifier('Marketing 2026!'));
        $this->assertSame('2026', OpenProjectProvisioningService::suggestIdentifier('2026'), 'digits are allowed first');
        $this->assertSame('project', OpenProjectProvisioningService::suggestIdentifier('???'));
    }

    public function testIdentifierAvailabilityIsA404(): void {
        $svc = $this->service(fn () => ['error' => '{}', 'message' => 'not found', 'statusCode' => 404]);
        $this->assertTrue($svc->isIdentifierAvailable('alice', 'free-one'));

        $svc = $this->service(fn () => self::projectResponse(3, 'taken'));
        $this->assertFalse($svc->isIdentifierAvailable('alice', 'taken'));

        $svc = $this->service(fn () => ['error' => '{}', 'message' => 'no', 'statusCode' => 403]);
        $this->assertFalse($svc->isIdentifierAvailable('alice', 'hidden'), 'a project you cannot see is a project all the same');

        $svc = $this->service(fn () => self::projectResponse(3, 'x'));
        $this->assertFalse($svc->isIdentifierAvailable('alice', 'Bad Id'), 'invalid never asks');
        $this->assertSame([], $this->requests);
    }

    // ── Create and copy ────────────────────────────────────────────────

    public function testCopyProjectSendsTheBodyOpenProjectExpectsAndReturnsTheJob(): void {
        $svc = $this->service(fn ($u, $ep, $p, $m) => $ep === 'projects/9/copy' && $m === 'POST' ? self::jobStatus('in_queue') : ['error' => '', 'statusCode' => 500]);
        $job = $svc->copyProject('alice', 9, 'New project', 'new-project', 'About', false, 4, ['members' => false, 'workPackages' => true]);

        $this->assertSame(['jobId' => 'abc-123', 'status' => 'in_queue', 'message' => null, 'projectId' => null], $job);
        $body = json_decode($this->requests[0][2]['body'], true);
        $this->assertSame('New project', $body['name']);
        $this->assertSame('new-project', $body['identifier']);
        $this->assertFalse($body['public']);
        $this->assertSame(['format' => 'markdown', 'raw' => 'About'], $body['description']);
        $this->assertSame('/api/v3/projects/4', $body['_links']['parent']['href']);
        $this->assertSame(['sendNotifications' => false, 'copyMembers' => false, 'copyWorkPackages' => true], $body['_meta']);
    }

    public function testJobStatusReportsTheProjectOnSuccessAndTheReasonOnFailure(): void {
        $svc = $this->service(fn () => self::jobStatus('success', 77));
        $this->assertSame(77, $svc->jobStatus('alice', 'abc-123')['projectId']);

        $svc = $this->service(fn () => ['_type' => 'JobStatus', 'jobId' => 'abc-123', 'status' => 'failure', 'message' => null, 'payload' => ['errors' => ['Identifier <b>taken</b>']]]);
        $this->assertSame('Identifier taken', $svc->jobStatus('alice', 'abc-123')['message']);

        $this->expectException(ValidationException::class);
        $svc->jobStatus('alice', '../etc');
    }

    public function testAnUnknownJobStatusIsUnsupported(): void {
        $svc = $this->service(fn () => self::jobStatus('done'));
        $this->expectException(OpenProjectException::class);
        $svc->jobStatus('alice', 'abc-123');
    }

    public function testCreateProjectRefusesBadInputBeforeAsking(): void {
        $svc = $this->service(fn () => self::projectResponse(1, 'x'));
        try {
            $svc->createProject('alice', '', 'x');
            $this->fail('expected an exception');
        } catch (ValidationException) {
        }
        $this->assertSame([], $this->requests);
    }

    public function testA422IsValidationFailedWithOpenProjectsSentence(): void {
        $svc = $this->service(fn () => ['error' => '{"message":"Identifier has already been taken."}', 'message' => 'Identifier has already been taken.', 'statusCode' => 422]);
        try {
            $svc->createProject('alice', 'X', 'x');
            $this->fail('expected an exception');
        } catch (OpenProjectException $e) {
            $this->assertSame(OpenProjectException::VALIDATION_FAILED, $e->getErrorCode());
            $this->assertSame('Identifier has already been taken.', $e->getUpstreamMessage());
        }
    }

    public function testATimeoutIsATemporaryFailure(): void {
        $svc = $this->service(function () {
            throw new \RuntimeException('cURL error 28: Operation timed out');
        });
        try {
            $svc->createProject('alice', 'X', 'x');
            $this->fail('expected an exception');
        } catch (OpenProjectException $e) {
            $this->assertSame(OpenProjectException::TEMPORARY_FAILURE, $e->getErrorCode());
        }
    }

    // ── Roles and memberships ──────────────────────────────────────────

    public function testRolesAreProjectGrantableOnes(): void {
        $svc = $this->service(fn () => self::collectionResponse([
            ['_type' => 'Role', 'id' => 3, 'name' => 'Project admin'],
            ['_type' => 'Role', 'id' => 4, 'name' => 'Member'],
        ], 2, 'Collection'));
        $this->assertSame([['id' => 3, 'name' => 'Project admin'], ['id' => 4, 'name' => 'Member']], $svc->listRoles('alice'));
        $filters = json_decode($this->requests[0][2]['filters'], true);
        $this->assertSame('project', $filters[0]['unit']['values'][0]);
        $this->assertSame('t', $filters[1]['grantable']['values'][0]);
    }

    public function testCreateMembershipBuildsTheLinksAndSendsNoNotification(): void {
        $svc = $this->service(fn ($u, $ep, $p, $m) => $ep === 'memberships' && $m === 'POST'
            ? ['_type' => 'Membership', 'id' => 55, '_links' => ['principal' => ['href' => '/api/v3/users/14', 'title' => 'Bob'], 'roles' => [['href' => '/api/v3/roles/4', 'title' => 'Member']]]]
            : ['error' => '', 'statusCode' => 500]);
        $m = $svc->createMembership('alice', 21, 14, [4, 4]);

        $this->assertSame(55, $m['id']);
        $this->assertSame(14, $m['principalId']);
        $this->assertSame('user', $m['principalType']);
        $this->assertSame([['id' => 4, 'name' => 'Member']], $m['roles']);
        $body = json_decode($this->requests[0][2]['body'], true);
        $this->assertSame('/api/v3/users/14', $body['_links']['principal']['href']);
        $this->assertSame('/api/v3/projects/21', $body['_links']['project']['href']);
        $this->assertSame([['href' => '/api/v3/roles/4']], $body['_links']['roles'], 'deduplicated');
        $this->assertFalse($body['_meta']['sendNotifications']);
    }

    public function testFindUserMatchesExactlyOrNotAtAll(): void {
        $users = self::collectionResponse([
            ['_type' => 'User', 'id' => 8, 'login' => 'bobby', 'name' => 'Bobby', 'email' => 'bob@example.test'],
            ['_type' => 'User', 'id' => 9, 'login' => 'bob', 'name' => 'Bob', 'email' => 'other@example.test'],
        ], 2, 'Collection');
        $svc = $this->service(fn ($u, $ep) => $ep === 'users' ? $users : ['error' => '', 'statusCode' => 500]);
        $this->assertSame(9, $svc->findUser('alice', 'bob')['id'], 'exact login, not the prefix match');

        // No manage_user: the users list is refused, principals answer.
        $svc = $this->service(fn ($u, $ep) => $ep === 'users'
            ? ['error' => '{}', 'message' => 'no', 'statusCode' => 403]
            : self::collectionResponse([['_type' => 'User', 'id' => 8, 'login' => 'bobby', 'name' => 'Bobby', 'email' => 'bob@example.test']], 1, 'Collection'));
        $this->assertSame(8, $svc->findUser('alice', 'bob', 'bob@example.test')['id'], 'exact e-mail');
        $this->assertNull($svc->findUser('alice', 'carol', 'carol@example.test'), 'a near miss is never a match');
    }

    // ── Storages ───────────────────────────────────────────────────────

    public function testProjectStoragesExposeTheManagedFolder(): void {
        $svc = $this->service(fn () => self::collectionResponse([[
            '_type' => 'ProjectStorage', 'id' => 2, 'projectFolderMode' => 'automatic',
            '_links' => [
                'storage'       => ['href' => '/api/v3/storages/1', 'title' => 'Nextcloud'],
                'projectFolder' => ['href' => '/api/v3/storages/1/files/4711'],
                'open'          => ['href' => '/api/v3/project_storages/2/open'],
            ],
        ]], 1, 'Collection'));
        $storages = $svc->projectStorages('alice', 12);
        $this->assertSame(4711, $storages[0]['projectFolderFileId']);
        $this->assertSame('automatic', $storages[0]['projectFolderMode']);
        $this->assertSame(self::HOST . '/api/v3/project_storages/2/open', $storages[0]['openUrl']);
        $this->assertSame('Nextcloud', $storages[0]['storageName']);
    }
}
