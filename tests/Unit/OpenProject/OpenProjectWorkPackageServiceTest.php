<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\OpenProject;

use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\OpenProject\OpenProjectProjectService;
use OCA\TeamHub\Service\OpenProject\OpenProjectWorkPackageService;
use OCA\TeamHub\Service\TimezoneService;

/**
 * The two readers (v4.9.5): the Upcoming section's grammar, bounds and
 * paging; My Work's assigned-and-due read; the single re-read. And the
 * writer (v4.9.15): OpenProject's create form and the create itself.
 */
class OpenProjectWorkPackageServiceTest extends OpenProjectTestCase {

    /** @var list<array{0: string, 1: array}> */
    private array $requests = [];

    private function service(?callable $handler = null): OpenProjectWorkPackageService {
        $this->withHost();
        $this->withConnectedUser('alice');
        $this->requests = [];
        $op = $this->opService(function (string $uid, string $endpoint, array $params) use ($handler) {
            $this->requests[] = [$endpoint, $params];
            return $handler ? $handler($endpoint, $params) : self::collectionResponse([], 0);
        });
        $client = $this->client($op);
        $cache  = $this->cache();
        return new OpenProjectWorkPackageService(
            $client,
            $cache,
            new OpenProjectProjectService($client, $cache, new TimezoneService($this->config())),
        );
    }

    /** @return list<array{0: string, 1: array}> the requests to one endpoint */
    private function requestsTo(string $endpoint): array {
        return array_values(array_filter($this->requests, fn (array $r) => $r[0] === $endpoint));
    }

    public function testUnknownSectionIsRejectedBeforeAnyRequest(): void {
        $service = $this->service();
        $this->expectException(ValidationException::class);
        $service->section('alice', 't', 12, self::HOST, 'assigned');
    }

    // ── Upcoming: every assignee, dated, soonest first ─────────────────

    public function testUpcomingIsEveryAssigneesDatedOpenWorkSoonestFirst(): void {
        [$filters, $sort] = $this->service()->query('upcoming');

        $this->assertSame(['status', 'dueDate'], array_map(fn (array $f) => array_key_first($f), $filters), 'no assignee filter');
        $this->assertSame(['operator' => 'o', 'values' => []], $filters[0]['status']);
        $this->assertSame('<>d', $filters[1]['dueDate']['operator'], 'has-a-due-date as an explicit, documented range');
        $this->assertSame('1970-01-01', $filters[1]['dueDate']['values'][0]);
        $this->assertSame(['dueDate', 'asc'], $sort[0]);
        foreach ($filters as $f) {
            $this->assertContains($f[array_key_first($f)]['operator'], ['o', '=', '<>d', 'w', '>t-'], 'only documented operators');
        }
    }

    public function testSectionIsBoundedAndPaged(): void {
        $service = $this->service(fn () => self::collectionResponse(
            [self::workPackageResponse(1), self::workPackageResponse(2), ['_type' => 'Junk']],
            23,
        ));

        $page = $service->section('alice', 'team-a', 12, self::HOST, 'upcoming', page: 2, pageSize: 500);

        [$endpoint, $params] = $this->requests[0];
        $this->assertSame('projects/12/work_packages', $endpoint);
        $this->assertSame(OpenProjectWorkPackageService::MAX_PAGE_SIZE, $params['pageSize'], 'page size capped');
        $this->assertSame(2, $params['offset'], 'OpenProject offset is the page number');
        $this->assertSame([1, 2], array_column($page['items'], 'id'), 'non work packages dropped');
        $this->assertSame(self::HOST . '/work_packages/1', $page['items'][0]['url']);
        $this->assertSame(23, $page['total']);
        $this->assertSame(2, $page['page']);
        $this->assertFalse($page['hasMore'], '2 × 25 ≥ 23');
        $this->assertFalse($page['fromCache']);
    }

    public function testHasMoreWhenTotalExceedsThePagesSeen(): void {
        $service = $this->service(fn () => self::collectionResponse([self::workPackageResponse(1)], 30));
        $page = $service->section('alice', 'team-a', 12, self::HOST, 'upcoming', page: 1, pageSize: 10);
        $this->assertTrue($page['hasMore']);
    }

    public function testInvalidPageFallsBackToOne(): void {
        $service = $this->service();
        $page = $service->section('alice', 'team-a', 12, self::HOST, 'upcoming', page: -5, pageSize: 0);
        $this->assertSame(1, $page['page']);
        $this->assertSame(1, $page['pageSize']);
    }

    public function testNonCollectionIsUnsupported(): void {
        $service = $this->service(fn () => ['_type' => 'Error']);
        try {
            $service->section('alice', 'team-a', 12, self::HOST, 'upcoming');
            $this->fail('expected an exception');
        } catch (OpenProjectException $e) {
            $this->assertSame(OpenProjectException::UNSUPPORTED_RESPONSE, $e->getErrorCode());
        }
    }

    public function testPagesAreCachedSeparatelyPerUserAndPage(): void {
        $service = $this->service(fn () => self::collectionResponse([self::workPackageResponse(1)], 1));

        $service->section('alice', 'team-a', 12, self::HOST, 'upcoming');
        $service->section('alice', 'team-a', 12, self::HOST, 'upcoming');
        $this->assertCount(1, $this->requestsTo('projects/12/work_packages'), 'same user, same page: cached');

        $service->section('alice', 'team-a', 12, self::HOST, 'upcoming', page: 2);
        $this->assertCount(2, $this->requestsTo('projects/12/work_packages'), 'another page: fetched');

        $this->withConnectedUser('bob');
        $service->section('bob', 'team-a', 12, self::HOST, 'upcoming');
        $this->assertCount(3, $this->requestsTo('projects/12/work_packages'), 'another user: fetched, never Alice\'s copy');
    }

    // ── The create permission rides the widget's payload (v4.9.15) ─────

    public function testSectionCarriesWhetherTheViewerMayCreateAndSurvivesAFailedProjectRead(): void {
        $granted = $this->service(fn (string $endpoint) => $endpoint === 'projects/12'
            ? self::projectResponse(12, canCreate: true)
            : self::collectionResponse([self::workPackageResponse(1)], 1));
        $this->assertTrue($granted->section('alice', 'team-a', 12, self::HOST, 'upcoming')['canCreateWorkPackage']);

        $denied = $this->service(fn (string $endpoint) => $endpoint === 'projects/12'
            ? self::projectResponse(12, canCreate: false)
            : self::collectionResponse([self::workPackageResponse(1)], 1));
        $this->assertFalse($denied->section('alice', 'team-a', 12, self::HOST, 'upcoming')['canCreateWorkPackage']);

        $broken = $this->service(fn (string $endpoint) => $endpoint === 'projects/12'
            ? ['error' => '{}', 'message' => 'forbidden', 'statusCode' => 403]
            : self::collectionResponse([self::workPackageResponse(1)], 1));
        $page = $broken->section('alice', 'team-a', 12, self::HOST, 'upcoming');
        $this->assertFalse($page['canCreateWorkPackage'], 'a refused permission read is "no"');
        $this->assertSame([1], array_column($page['items'], 'id'), 'and never costs the rows');
    }

    // ── The create form (v4.9.15) ──────────────────────────────────────

    /** @return array<string, mixed> a Form the way OpenProject 17 shapes it */
    private static function formResponse(?string $assigneeHref = '/api/v3/workspaces/12/available_assignees', ?int $defaultType = 2): array {
        $schema = [
            'type' => ['_links' => ['allowedValues' => [
                ['href' => '/api/v3/types/1', 'title' => 'Task'],
                ['href' => '/api/v3/types/2', 'title' => 'Milestone <b>x</b>'],
                ['href' => '/api/v3/types/junk', 'title' => 'Broken'],
                ['href' => '/api/v3/types/3'],
                'not-a-link',
            ]]],
            'assignee' => ['_links' => ['allowedValues' => $assigneeHref === null ? [] : ['href' => $assigneeHref]]],
        ];
        return [
            '_type'     => 'Form',
            '_embedded' => [
                'payload' => ['_links' => ['type' => $defaultType === null ? ['href' => null] : ['href' => '/api/v3/types/' . $defaultType]]],
                'schema'  => $schema,
                'validationErrors' => [],
            ],
        ];
    }

    public function testCreateFormListsTypesAndFollowsTheAssigneeCollectionTheSchemaNames(): void {
        $service = $this->service(function (string $endpoint, array $params) {
            if ($endpoint === 'work_packages/form') {
                return self::formResponse();
            }
            if ($endpoint === 'workspaces/12/available_assignees') {
                return self::collectionResponse([
                    ['_type' => 'User', 'id' => 6, 'name' => 'Lieke <i>adm</i>'],
                    ['_type' => 'Group', 'id' => 9, 'name' => 'Everybody'],
                    ['_type' => 'User', 'id' => 5, 'name' => 'Inge NC'],
                    ['_type' => 'User', 'id' => 7, 'name' => ''],
                ], 4, 'Collection');
            }
            $this->fail('unexpected endpoint ' . $endpoint);
        });

        $form = $service->createForm('alice', 12);

        [$endpoint, $params] = $this->requests[0];
        $this->assertSame('work_packages/form', $endpoint);
        $this->assertSame(['_links' => ['project' => ['href' => '/api/v3/projects/12']]], json_decode($params['body'], true), 'the generic form, the project in _links');
        $this->assertSame([[1, 'Task'], [2, 'Milestone x']], array_map(fn (array $t) => [$t['id'], $t['name']], $form['types']), 'malformed values dropped, titles as text');
        $this->assertSame(2, $form['defaultTypeId']);
        $this->assertSame([[5, 'Inge NC'], [6, 'Lieke adm']], array_map(fn (array $a) => [$a['id'], $a['name']], $form['assignees']), 'users only, by name');
        $this->assertFalse($form['assigneesUnavailable']);
        $this->assertSame(200, $this->requests[1][1]['pageSize'], 'one bounded read');
    }

    public function testCreateFormFollowsOnlyApiV3PathsAndSurvivesAFailedAssigneeRead(): void {
        $foreign = $this->service(fn (string $endpoint) => $endpoint === 'work_packages/form'
            ? self::formResponse('https://elsewhere.test/users')
            : $this->fail('the foreign href must not be followed'));
        $form = $foreign->createForm('alice', 12);
        $this->assertSame([], $form['assignees']);
        $this->assertTrue($form['assigneesUnavailable']);
        $this->assertCount(1, $this->requests);

        $refused = $this->service(fn (string $endpoint) => $endpoint === 'work_packages/form'
            ? self::formResponse()
            : ['error' => '{}', 'message' => 'forbidden', 'statusCode' => 403]);
        $form = $refused->createForm('alice', 12);
        $this->assertSame([[1, 'Task'], [2, 'Milestone x']], array_map(fn (array $t) => [$t['id'], $t['name']], $form['types']), 'the form survives');
        $this->assertTrue($form['assigneesUnavailable']);

        $noDefault = $this->service(fn () => self::formResponse(defaultType: 99));
        $this->assertNull($noDefault->createForm('alice', 12)['defaultTypeId'], 'a default outside the allowed list is no default');
    }

    public function testCreateFormThatIsNotAFormIsUnsupported(): void {
        $service = $this->service(fn () => ['_type' => 'Error']);
        $this->expectException(OpenProjectException::class);
        $service->createForm('alice', 12);
    }

    // ── The create (v4.9.15) ───────────────────────────────────────────

    public function testCreatePostsOnlyWhatWasGivenAndForgetsTheTeamsCache(): void {
        $service = $this->service(fn (string $endpoint) => $endpoint === 'work_packages'
            ? self::workPackageResponse(41, ['subject' => 'Plan the review'])
            : self::collectionResponse([], 0));

        $wp = $service->create('alice', 'team-a', 12, [
            'subject' => '  Plan the review  ', 'typeId' => 1, 'assigneeId' => 6,
            'dueDate' => '2026-09-30', 'description' => "  Notes  ",
        ]);

        [$endpoint, $params] = $this->requests[0];
        $this->assertSame('work_packages', $endpoint);
        $this->assertSame([
            'subject' => 'Plan the review',
            '_links'  => [
                'project'  => ['href' => '/api/v3/projects/12'],
                'type'     => ['href' => '/api/v3/types/1'],
                'assignee' => ['href' => '/api/v3/users/6'],
            ],
            'dueDate'     => '2026-09-30',
            'description' => ['format' => 'markdown', 'raw' => 'Notes'],
        ], json_decode($params['body'], true));
        $this->assertSame(41, $wp['id']);
        $this->assertSame('Plan the review', $wp['subject']);
        $this->assertSame(self::HOST . '/work_packages/41', $wp['url']);

        // Minimal: no assignee, no date, no description → none of them in the body.
        $service->create('alice', 'team-a', 12, ['subject' => 'Only a subject', 'typeId' => 2, 'assigneeId' => '', 'dueDate' => '', 'description' => '']);
        $body = json_decode($this->requests[1][1]['body'], true);
        $this->assertSame(['subject', '_links'], array_keys($body));
        $this->assertSame(['project', 'type'], array_keys($body['_links']));
    }

    public function testCreateForgetsTheTeamsCachedPagesForEveryMember(): void {
        $service = $this->service(fn (string $endpoint) => $endpoint === 'work_packages'
            ? self::workPackageResponse(41)
            : self::collectionResponse([self::workPackageResponse(1)], 1));

        $service->section('alice', 'team-a', 12, self::HOST, 'upcoming');
        $this->assertCount(1, $this->requestsTo('projects/12/work_packages'));

        $service->create('alice', 'team-a', 12, ['subject' => 'x', 'typeId' => 1]);

        $service->section('alice', 'team-a', 12, self::HOST, 'upcoming');
        $this->assertCount(2, $this->requestsTo('projects/12/work_packages'), 'the page is read again after a create');
    }

    /** @dataProvider badInput */
    public function testCreateRefusesBadInputBeforeAnyRequest(array $input): void {
        $service = $this->service();
        try {
            $service->create('alice', 'team-a', 12, $input);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertCount(0, $this->requests, 'refused before OpenProject is asked');
        }
    }

    /** @return array<string, array{0: array}> */
    public static function badInput(): array {
        return [
            'no subject'        => [['subject' => '   ', 'typeId' => 1]],
            'subject too long'  => [['subject' => str_repeat('x', 256), 'typeId' => 1]],
            'no type'           => [['subject' => 'x', 'typeId' => 0]],
            'bad assignee'      => [['subject' => 'x', 'typeId' => 1, 'assigneeId' => -3]],
            'bad date'          => [['subject' => 'x', 'typeId' => 1, 'dueDate' => '30/09/2026']],
            'impossible date'   => [['subject' => 'x', 'typeId' => 1, 'dueDate' => '2026-02-30']],
            'description long'  => [['subject' => 'x', 'typeId' => 1, 'description' => str_repeat('y', 5001)]],
        ];
    }

    public function testCreateRefusedByOpenProjectCarriesItsSentence(): void {
        $service = $this->service(fn () => [
            'error' => '{}', 'message' => 'Subject can\'t be blank.', 'statusCode' => 422,
        ]);
        try {
            $service->create('alice', 'team-a', 12, ['subject' => 'x', 'typeId' => 1]);
            $this->fail('expected an exception');
        } catch (OpenProjectException $e) {
            $this->assertSame(OpenProjectException::VALIDATION_FAILED, $e->getErrorCode());
            $this->assertSame('Subject can\'t be blank.', $e->getUpstreamMessage());
        }
    }

    public function testCreateThatDoesNotAnswerWithAWorkPackageIsUnsupported(): void {
        $service = $this->service(fn () => ['_type' => 'Error']);
        $this->expectException(OpenProjectException::class);
        $service->create('alice', 'team-a', 12, ['subject' => 'x', 'typeId' => 1]);
    }

    // ── My Work: mine, dated, up to the horizon ────────────────────────

    public function testAssignedDueByIsMineOpenAndBoundedByTheHorizon(): void {
        $service = $this->service(fn () => self::collectionResponse(
            [self::workPackageResponse(1), self::workPackageResponse(2)],
            2,
        ));

        $result = $service->assignedDueBy('alice', 'team-a', 12, self::HOST, '2026-09-19', 500);

        [$endpoint, $params] = $this->requests[0];
        $this->assertSame('projects/12/work_packages', $endpoint);
        $filters = json_decode($params['filters'], true);
        $this->assertSame(['status', 'assignee', 'dueDate'], array_map(fn (array $f) => array_key_first($f), $filters));
        $this->assertSame(['operator' => '=', 'values' => ['me']], $filters[1]['assignee']);
        $this->assertSame(['operator' => '<>d', 'values' => ['1970-01-01', '2026-09-19']], $filters[2]['dueDate'], 'overdue is before any horizon');
        $this->assertSame([['dueDate', 'asc'], ['updatedAt', 'desc']], json_decode($params['sortBy'], true));
        $this->assertSame(OpenProjectWorkPackageService::MY_WORK_LIMIT, $params['pageSize'], 'limit capped');
        $this->assertSame(1, $params['offset']);
        $this->assertSame([1, 2], array_column($result['items'], 'id'));
        $this->assertFalse($result['truncated']);
    }

    public function testAssignedDueByReportsTruncationAndCachesPerUser(): void {
        $service = $this->service(fn () => self::collectionResponse([self::workPackageResponse(1)], 40));

        $result = $service->assignedDueBy('alice', 'team-a', 12, self::HOST, '2026-09-19');
        $this->assertTrue($result['truncated'], 'total 40, one row returned');

        $service->assignedDueBy('alice', 'team-a', 12, self::HOST, '2026-09-19');
        $this->assertCount(1, $this->requests, 'cached');

        $this->withConnectedUser('bob');
        $service->assignedDueBy('bob', 'team-a', 12, self::HOST, '2026-09-19');
        $this->assertCount(2, $this->requests, 'never Alice\'s copy');
    }

    public function testAssignedDueByRefusesABadHorizon(): void {
        $service = $this->service();
        $this->expectException(ValidationException::class);
        $service->assignedDueBy('alice', 'team-a', 12, self::HOST, 'next week');
    }

    // ── One work package ───────────────────────────────────────────────

    public function testWorkPackageIsReadFreshAndCarriesItsUrl(): void {
        $service = $this->service(fn (string $endpoint) => $endpoint === 'work_packages/7'
            ? self::workPackageResponse(7)
            : ['error' => '{}', 'message' => 'not found', 'statusCode' => 404]);

        $wp = $service->workPackage('alice', 7);
        $wp = $service->workPackage('alice', 7);

        $this->assertSame(7, $wp['id']);
        $this->assertSame(self::HOST . '/work_packages/7', $wp['url']);
        $this->assertCount(2, $this->requests, 'the authorisation re-read is never served from cache');
    }

    public function testAMissingWorkPackageIsNotFound(): void {
        $service = $this->service(fn () => ['error' => '{}', 'message' => 'not found', 'statusCode' => 404]);
        try {
            $service->workPackage('alice', 7);
            $this->fail('expected an exception');
        } catch (OpenProjectException $e) {
            $this->assertSame(OpenProjectException::PROJECT_NOT_FOUND, $e->getErrorCode());
        }
    }

    public function testTheWrongWorkPackageBackIsUnsupported(): void {
        $service = $this->service(fn () => self::workPackageResponse(8));
        try {
            $service->workPackage('alice', 7);
            $this->fail('expected an exception');
        } catch (OpenProjectException $e) {
            $this->assertSame(OpenProjectException::UNSUPPORTED_RESPONSE, $e->getErrorCode());
        }
    }

    // ── Phase 3 readers (v4.9.7) ───────────────────────────────────────

    public function testRecentlyUpdatedIsMineOpenAndRelativeNewestFirst(): void {
        $service = $this->service();
        $service->assignedRecentlyUpdated('alice', 'team-a', 12, self::HOST, 3);

        $filters = json_decode($this->requests[0][1]['filters'], true);
        $this->assertSame(['status' => ['operator' => 'o', 'values' => []]], $filters[0]);
        $this->assertSame(['assignee' => ['operator' => '=', 'values' => ['me']]], $filters[1]);
        $this->assertSame(['updatedAt' => ['operator' => '>t-', 'values' => ['3']]], $filters[2]);
        $this->assertSame([['updatedAt', 'desc']], json_decode($this->requests[0][1]['sortBy'], true));
        $this->assertSame(OpenProjectWorkPackageService::RECENT_LIMIT, $this->requests[0][1]['pageSize']);
    }

    public function testCompletedSinceIsMineClosedAndBounded(): void {
        $service = $this->service();
        $service->assignedCompletedSince('alice', 'team-a', 12, self::HOST, 400);

        $filters = json_decode($this->requests[0][1]['filters'], true);
        $this->assertSame('c', $filters[0]['status']['operator']);
        $this->assertSame(['me'], $filters[1]['assignee']['values']);
        $this->assertSame(['90'], $filters[2]['updatedAt']['values'], 'the window is capped at 90 days');
    }

    public function testMilestonesNeedTypeIdsAndAWindow(): void {
        $service = $this->service();
        $this->assertSame([], $service->upcomingMilestones('alice', 'team-a', 12, self::HOST, '2026-09-12', '2026-09-19', []));
        $this->assertSame([], $this->requests, 'no milestone types, no request');

        $service->upcomingMilestones('alice', 'team-a', 12, self::HOST, '2026-09-12', '2026-09-19', [2, 0, -1, 5]);
        $filters = json_decode($this->requests[0][1]['filters'], true);
        $this->assertSame(['2', '5'], $filters[1]['type']['values']);
        $this->assertSame(['2026-09-12', '2026-09-19'], $filters[2]['dueDate']['values']);

        $this->expectException(ValidationException::class);
        $service->upcomingMilestones('alice', 'team-a', 12, self::HOST, 'yesterday', '2026-09-19', [2]);
    }

    public function testUpdatedBetweenIsADatetimeRangeBucketedForTheCache(): void {
        $service = $this->service();
        // 12:03:20 → floors to 12:00; 12:07:00 → ceils to 12:10.
        $service->updatedBetween('alice', 'team-a', 12, self::HOST, 1789214600, 1789214820);
        $service->updatedBetween('alice', 'team-a', 12, self::HOST, 1789214500, 1789214900, 25);

        $this->assertCount(1, $this->requests, 'two windows inside the same buckets share one read');
        $filters = json_decode($this->requests[0][1]['filters'], true);
        $this->assertSame(['2026-09-12T12:00:00Z', '2026-09-12T12:10:00Z'], $filters[0]['updatedAt']['values']);
        $this->assertSame('<>d', $filters[0]['updatedAt']['operator']);
    }

    public function testAuthoredUnassignedIsMineOpenAndWithoutAnAssignee(): void {
        $service = $this->service();
        $service->authoredUnassigned('alice', 'team-a', 12, self::HOST);

        $filters = json_decode($this->requests[0][1]['filters'], true);
        $this->assertSame(['status' => ['operator' => 'o', 'values' => []]], $filters[0]);
        $this->assertSame(['author' => ['operator' => '=', 'values' => ['me']]], $filters[1]);
        $this->assertSame(['assignee' => ['operator' => '!*', 'values' => []]], $filters[2]);
        $this->assertSame([['dueDate', 'asc'], ['updatedAt', 'desc']], json_decode($this->requests[0][1]['sortBy'], true));
    }
}
