<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\OpenProject;

use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\OpenProject\OpenProjectCache;
use OCA\TeamHub\Service\OpenProject\OpenProjectProjectService;
use OCA\TeamHub\Service\TimezoneService;

/**
 * Project search, project read, and the assembled overview — against a
 * scripted OpenProject that answers per endpoint.
 */
class OpenProjectProjectServiceTest extends OpenProjectTestCase {

    /** @var list<array{0: string, 1: array}> every (endpoint, params) the service asked for */
    private array $requests = [];

    /**
     * @param array<string, mixed|callable> $routes endpoint prefix → response or fn(params)
     */
    private function service(array $routes, ?OpenProjectCache $cache = null): OpenProjectProjectService {
        $this->withHost();
        $this->withConnectedUser('alice');
        $this->requests = [];
        $op = $this->opService(function (string $uid, string $endpoint, array $params) use ($routes) {
            $this->requests[] = [$endpoint, $params];
            foreach ($routes as $prefix => $response) {
                if ($endpoint === $prefix) {
                    return is_callable($response) ? $response($params) : $response;
                }
            }
            return ['error' => '{}', 'message' => 'not found', 'statusCode' => 404];
        });
        return new OpenProjectProjectService(
            $this->client($op),
            $cache ?? $this->cache(),
            new TimezoneService($this->config()),
        );
    }

    /** @return array<string, mixed> the decoded `filters` of the last request to `$endpoint` */
    private function filtersOf(string $endpoint, int $nth = 0): array {
        $matches = array_values(array_filter($this->requests, fn (array $r) => $r[0] === $endpoint));
        $this->assertArrayHasKey($nth, $matches, "request #$nth to $endpoint");
        return json_decode($matches[$nth][1]['filters'] ?? '[]', true);
    }

    // ── Search ─────────────────────────────────────────────────────────

    public function testSearchFiltersActiveProjectsByNameOrIdentifierAndCapsTheLimit(): void {
        $service = $this->service([
            'projects' => self::collectionResponse([
                self::projectResponse(1, 'one'),
                self::projectResponse(2, 'two', canEdit: false, public: true),
                self::projectResponse(3, 'three', canEdit: false, public: false),
                ['_type' => 'Junk'],
            ], 3, 'Collection'),
        ]);

        $out = $service->search('alice', '  demo ', 500);

        $this->assertSame([1, 2], array_column($out, 'id'), 'administered and public are offered; merely visible is not');
        $this->assertSame('one', $out[0]['identifier']);
        $this->assertArrayNotHasKey('description', $out[0], 'the picker gets the compact shape');

        [$endpoint, $params] = $this->requests[0];
        $this->assertSame('projects', $endpoint);
        $this->assertSame(OpenProjectProjectService::SEARCH_LIMIT, $params['pageSize'], 'limit capped');
        $filters = json_decode($params['filters'], true);
        $this->assertSame(['active' => ['operator' => '=', 'values' => ['t']]], $filters[0]);
        $this->assertSame(['name_and_identifier' => ['operator' => '~', 'values' => ['demo']]], $filters[1]);
    }

    public function testSearchWithoutQueryHasNoTextFilter(): void {
        $service = $this->service(['projects' => self::collectionResponse([], 0, 'Collection')]);
        $service->search('alice', '');
        $this->assertCount(1, json_decode($this->requests[0][1]['filters'], true));
    }

    public function testOverlongQueryIsRejectedBeforeAnyRequest(): void {
        $service = $this->service([]);
        $this->expectException(ValidationException::class);
        $service->search('alice', str_repeat('x', 201));
    }

    // ── Read ───────────────────────────────────────────────────────────

    public function testGetProjectRejectsInvalidIds(): void {
        $service = $this->service([]);
        $this->expectException(ValidationException::class);
        $service->getProject('alice', 0);
    }

    public function testMissingProjectIsProjectNotFound(): void {
        $service = $this->service([]);
        try {
            $service->getProject('alice', 99);
            $this->fail('expected an exception');
        } catch (OpenProjectException $e) {
            $this->assertSame(OpenProjectException::PROJECT_NOT_FOUND, $e->getErrorCode());
        }
    }

    public function testAProjectResourceWithTheWrongIdIsUnsupported(): void {
        $service = $this->service(['projects/12' => self::projectResponse(13)]);
        $this->expectException(OpenProjectException::class);
        $this->expectExceptionMessageMatches('/did not return that Project/');
        $service->getProject('alice', 12);
    }

    // ── Overview ───────────────────────────────────────────────────────

    /** @return array<string, mixed|callable> a fully answering OpenProject */
    private function happyRoutes(): array {
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d');
        $past  = (new \DateTimeImmutable('today -3 days', new \DateTimeZone('UTC')))->format('Y-m-d');
        $soon  = (new \DateTimeImmutable('today +2 days', new \DateTimeZone('UTC')))->format('Y-m-d');

        return [
            'projects/12' => self::projectResponse(12),
            'projects/12/work_packages' => function (array $params) use ($past, $soon) {
                $filters = json_decode($params['filters'], true);
                $names   = array_map(fn (array $f) => array_key_first($f), $filters);
                if (in_array('type', $names, true)) {
                    // milestone query — overdue first, then upcoming
                    return self::collectionResponse([
                        self::workPackageResponse(5, ['subject' => 'Kickoff', 'date' => $past, 'dueDate' => $past]),
                        self::workPackageResponse(6, ['subject' => 'Go live', 'date' => $soon, 'dueDate' => $soon]),
                    ]);
                }
                $status = null;
                foreach ($filters as $f) {
                    if (isset($f['status'])) $status = $f['status']['operator'];
                }
                if ($status === 'c') {
                    return self::collectionResponse([self::workPackageResponse(9, ['subject' => 'Done thing'])], 1);
                }
                $hasDue = in_array('dueDate', $names, true);
                if (!$hasDue) return self::collectionResponse([], 42);
                $range = null;
                foreach ($filters as $f) {
                    if (isset($f['dueDate'])) $range = $f['dueDate']['values'];
                }
                return self::collectionResponse([], $range[0] === '1970-01-01' ? 3 : 7);
            },
            'projects/12/types' => self::collectionResponse([
                ['_type' => 'Type', 'id' => 1, 'name' => 'Task', 'isMilestone' => false],
                ['_type' => 'Type', 'id' => 2, 'name' => 'Milestone', 'isMilestone' => true],
            ], 2, 'Collection'),
            'project_storages' => self::collectionResponse([
                ['_type' => 'ProjectStorage', 'id' => 4, '_links' => ['open' => ['href' => '/api/v3/project_storages/4/open']]],
            ], 1, 'Collection'),
        ];
    }

    public function testOverviewAssemblesEveryBlock(): void {
        $service = $this->service($this->happyRoutes());

        $o = $service->overview('alice', 'team-a', 12, self::HOST);

        $this->assertSame('Demo project', $o['project']['name']);
        $this->assertSame(self::HOST . '/projects/demo-project', $o['project']['url']);
        $this->assertSame(self::HOST . '/projects/demo-project/work_packages', $o['project']['workPackagesUrl']);
        $this->assertSame(self::HOST . '/projects/demo-project/work_packages/new', $o['project']['newWorkPackageUrl']);
        $this->assertSame(['open' => 42, 'overdue' => 3, 'dueSoon' => 7], $o['counts']);
        $this->assertSame('Go live', $o['nextMilestone']['subject'], 'the earliest milestone on or after today');
        $this->assertFalse($o['nextMilestone']['overdue']);
        $this->assertSame(self::HOST . '/work_packages/6', $o['nextMilestone']['url']);
        $this->assertSame([9], array_column($o['recentlyCompleted'], 'id'));
        $this->assertSame(['url' => self::HOST . '/api/v3/project_storages/4/open'], $o['files']);
        $this->assertSame([], $o['warnings']);
        $this->assertFalse($o['fromCache']);
        $this->assertIsInt($o['retrievedAt']);
    }

    public function testOverviewFiltersUseDocumentedOperatorsOnly(): void {
        $service = $this->service($this->happyRoutes());
        $service->overview('alice', 'team-a', 12, self::HOST);

        $open    = $this->filtersOf('projects/12/work_packages', 0);
        $overdue = $this->filtersOf('projects/12/work_packages', 1);
        $dueSoon = $this->filtersOf('projects/12/work_packages', 2);

        $this->assertSame([['status' => ['operator' => 'o', 'values' => []]]], $open);
        $this->assertSame('<>d', $overdue[1]['dueDate']['operator']);
        $this->assertSame('1970-01-01', $overdue[1]['dueDate']['values'][0], 'explicit range, epoch to yesterday');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $overdue[1]['dueDate']['values'][1]);
        $this->assertSame('<>d', $dueSoon[1]['dueDate']['operator']);
        $this->assertSame(OpenProjectProjectService::DUE_SOON_DAYS, (int)((strtotime($dueSoon[1]['dueDate']['values'][1]) - strtotime($dueSoon[1]['dueDate']['values'][0])) / 86400));
    }

    public function testOverviewWithoutMilestoneTypeHasNoMilestoneAndNoWarning(): void {
        $routes = $this->happyRoutes();
        $routes['projects/12/types'] = self::collectionResponse([['_type' => 'Type', 'id' => 1, 'isMilestone' => false]], 1, 'Collection');
        $o = $this->service($routes)->overview('alice', 'team-a', 12, self::HOST);

        $this->assertNull($o['nextMilestone']);
        $this->assertSame([], $o['warnings']);
    }

    public function testOverviewWithoutProjectStorageHasNoFilesAction(): void {
        $routes = $this->happyRoutes();
        $routes['project_storages'] = self::collectionResponse([], 0, 'Collection');
        $this->assertNull($this->service($routes)->overview('alice', 'team-a', 12, self::HOST)['files']);
    }

    public function testOverviewStorageLinkOffHostIsDropped(): void {
        $routes = $this->happyRoutes();
        $routes['project_storages'] = self::collectionResponse([
            ['_type' => 'ProjectStorage', 'id' => 4, '_links' => ['open' => ['href' => 'https://evil.example/open']]],
        ], 1, 'Collection');
        $this->assertNull($this->service($routes)->overview('alice', 'team-a', 12, self::HOST)['files']);
    }

    public function testPermissionDeniedOnABlockIsPartialNotFatal(): void {
        $routes = $this->happyRoutes();
        $routes['projects/12/types'] = ['error' => '{}', 'message' => 'no', 'statusCode' => 403];
        $o = $this->service($routes)->overview('alice', 'team-a', 12, self::HOST);

        $this->assertNull($o['nextMilestone']);
        $this->assertContains('milestone:' . OpenProjectException::PERMISSION_DENIED, $o['warnings']);
        $this->assertSame(42, $o['counts']['open'], 'the other blocks still answer');
    }

    public function testCountsThatCannotBeReadAreNullNotZero(): void {
        $routes = $this->happyRoutes();
        $routes['projects/12/work_packages'] = ['_type' => 'Error'];
        $o = $this->service($routes)->overview('alice', 'team-a', 12, self::HOST);

        $this->assertNull($o['counts']['open']);
        $this->assertNull($o['counts']['overdue']);
        $this->assertContains('counts:open:' . OpenProjectException::UNSUPPORTED_RESPONSE, $o['warnings']);
    }

    public function testAnAuthFailureOnABlockFailsTheWholeOverview(): void {
        $routes = $this->happyRoutes();
        $routes['projects/12/work_packages'] = ['error' => '', 'message' => 'x', 'statusCode' => 401];
        try {
            $this->service($routes)->overview('alice', 'team-a', 12, self::HOST);
            $this->fail('expected an exception');
        } catch (OpenProjectException $e) {
            $this->assertSame(OpenProjectException::AUTH_FAILED, $e->getErrorCode());
        }
    }

    public function testAMissingProjectFailsTheOverview(): void {
        $routes = $this->happyRoutes();
        unset($routes['projects/12']);
        try {
            $this->service($routes)->overview('alice', 'team-a', 12, self::HOST);
            $this->fail('expected an exception');
        } catch (OpenProjectException $e) {
            $this->assertSame(OpenProjectException::PROJECT_NOT_FOUND, $e->getErrorCode());
        }
    }

    public function testOverviewIsCachedPerUserAndRefreshHonoursTheCooldown(): void {
        $cache   = $this->cache();
        $service = $this->service($this->happyRoutes(), $cache);

        $first = $service->overview('alice', 'team-a', 12, self::HOST);
        $requestsAfterFirst = count($this->requests);
        $this->assertGreaterThan(1, $requestsAfterFirst);

        $second = $service->overview('alice', 'team-a', 12, self::HOST);
        $this->assertTrue($second['fromCache']);
        $this->assertSame($requestsAfterFirst, count($this->requests), 'served from cache');

        $third = $service->overview('alice', 'team-a', 12, self::HOST, refresh: true);
        $this->assertFalse($third['fromCache'], 'first refresh is allowed');
        $requestsAfterRefresh = count($this->requests);
        $this->assertGreaterThan($requestsAfterFirst, $requestsAfterRefresh);

        $fourth = $service->overview('alice', 'team-a', 12, self::HOST, refresh: true);
        $this->assertTrue($fourth['fromCache'], 'a second refresh inside the cooldown is served from cache');
        $this->assertSame($requestsAfterRefresh, count($this->requests));

        // Another user never sees Alice's cached copy.
        $this->withConnectedUser('bob');
        $service->overview('bob', 'team-a', 12, self::HOST);
        $this->assertGreaterThan($requestsAfterRefresh, count($this->requests));
        $this->assertSame($first['project']['name'], $second['project']['name']);
    }

    public function testDescriptionExcerptNeverContainsMarkup(): void {
        $routes = $this->happyRoutes();
        $routes['projects/12']['description']['raw'] = '<img src=x onerror=alert(1)> **Danger** [x](javascript:alert(1))';
        $o = $this->service($routes)->overview('alice', 'team-a', 12, self::HOST);

        $this->assertStringNotContainsString('<', $o['project']['description']);
        $this->assertStringNotContainsString('javascript:', $o['project']['description']);
        $this->assertSame('Danger x', $o['project']['description']);
    }
}
