<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\OpenProject;

use OCA\TeamHub\Db\TeamOpenProjectLink;
use OCA\TeamHub\Db\TeamOpenProjectLinkMapper;
use OCA\TeamHub\Service\OpenProject\OpenProjectActivityService;
use OCA\TeamHub\Service\OpenProject\OpenProjectMessages;
use OCA\TeamHub\Service\OpenProject\OpenProjectProjectService;
use OCA\TeamHub\Service\OpenProject\OpenProjectReferenceService;
use OCA\TeamHub\Service\OpenProject\OpenProjectSyncHealth;
use OCA\TeamHub\Service\OpenProject\OpenProjectWorkPackageService;
use OCA\TeamHub\Service\TimezoneService;
use OCP\ICacheFactory;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * The What's new source (v4.9.7): one bounded read per linked project
 * inside the period, one row per work package, classified from
 * OpenProject's own lists, never a body; a failing project is a status,
 * never an exception; two viewers never share an answer.
 */
class OpenProjectActivityServiceTest extends OpenProjectTestCase {

    /** Noon UTC, 2026-09-12. */
    private const NOW = 1789214400;

    /** @var list<array{0: string, 1: array, 2: string, 3: string}> */
    private array $requests = [];
    private OpenProjectSyncHealth $health;

    /**
     * @param array<string, array{projectId: int, host?: string}> $linked
     */
    private function service(array $linked, ?callable $handler = null, bool $connected = true, bool $enabled = true): OpenProjectActivityService {
        $this->withHost();
        if ($connected) {
            $this->withConnectedUser('alice');
            $this->withConnectedUser('bob', 'Bob');
        }
        $this->requests = [];

        $op = $this->opService(function (string $uid, string $endpoint, array $params, string $method) use ($handler) {
            $this->requests[] = [$endpoint, $params, $method, $uid];
            if ($endpoint === 'statuses') {
                return self::collectionResponse([
                    ['_type' => 'Status', 'id' => 7, 'isClosed' => false],
                    ['_type' => 'Status', 'id' => 13, 'isClosed' => true],
                ], 2, 'Collection');
            }
            if ($endpoint === 'priorities') {
                return self::collectionResponse([], 0, 'Collection');
            }
            if (preg_match('#^projects/\d+/types$#', $endpoint)) {
                return self::collectionResponse([
                    ['_type' => 'Type', 'id' => 1, 'isMilestone' => false],
                    ['_type' => 'Type', 'id' => 2, 'isMilestone' => true],
                ], 2, 'Collection');
            }
            return $handler ? $handler($endpoint, $params, $uid) : self::collectionResponse([], 0);
        });
        $client = $this->client($op, enabled: $enabled);

        $mapper = $this->createMock(TeamOpenProjectLinkMapper::class);
        $mapper->method('findByTeams')->willReturnCallback(function (array $teamIds) use ($linked): array {
            $out = [];
            foreach ($teamIds as $id) {
                if (!isset($linked[$id])) {
                    continue;
                }
                $row = new TeamOpenProjectLink();
                $row->setTeamId($id);
                $row->setProjectId($linked[$id]['projectId']);
                $row->setProjectIdentifier('p' . $linked[$id]['projectId']);
                $row->setProjectName('Project ' . $linked[$id]['projectId']);
                $row->setHost($linked[$id]['host'] ?? self::HOST);
                $out[$id] = $row;
            }
            return $out;
        });

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn (string $s) => $s);

        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn(new \OC\Memcache\ArrayCache());
        $this->health = new OpenProjectSyncHealth($factory, $this->config(), $this->createMock(LoggerInterface::class));

        $cache = $this->cache();
        return new OpenProjectActivityService(
            $client,
            new OpenProjectWorkPackageService($client, $cache, new OpenProjectProjectService($client, $cache, new TimezoneService($this->config()))),
            new OpenProjectReferenceService($client, $cache),
            $mapper,
            $this->health,
            new OpenProjectMessages($l),
            $this->createMock(LoggerInterface::class),
        );
    }

    /** @param list<array<string, mixed>> $elements */
    private function answering(array $elements): callable {
        return fn () => self::collectionResponse($elements, count($elements));
    }

    private function wp(int $id, string $updatedAt, array $overrides = []): array {
        return self::workPackageResponse($id, array_replace(['updatedAt' => $updatedAt, 'createdAt' => '2026-08-01T00:00:00Z'], $overrides));
    }

    // ── Availability ───────────────────────────────────────────────────

    public function testWithoutTheIntegrationTheSourceIsUnavailableAndCostsNothing(): void {
        $s = $this->service(['team-a' => ['projectId' => 12]], enabled: false);
        $r = $s->feed('alice', ['team-a'], 0, 0, 20);
        $this->assertSame('unavailable', $r['status']['state']);
        $this->assertSame([], $r['items']);
        $this->assertSame([], $this->requests);
    }

    public function testAnUnconnectedViewerIsNotConnectedNotAnError(): void {
        $s = $this->service(['team-a' => ['projectId' => 12]], connected: false);
        $r = $s->feed('alice', ['team-a'], 0, 0, 20);
        $this->assertSame('not_connected', $r['status']['state']);
        $this->assertSame('user_not_connected', $r['status']['code']);
        $this->assertSame([], $this->requests);
    }

    public function testNoTeamsMeansNoRequest(): void {
        $s = $this->service(['team-a' => ['projectId' => 12]]);
        $this->assertSame('skipped', $s->feed('alice', [], 0, 0, 20)['status']['state']);
        $this->assertSame([], $this->requests);
    }

    // ── The read ───────────────────────────────────────────────────────

    public function testOneBoundedReadPerLinkedProjectInsideTheWindow(): void {
        $s = $this->service(['team-a' => ['projectId' => 12], 'team-b' => ['projectId' => 13], 'team-c' => ['projectId' => 14, 'host' => 'https://other.test']]);
        $from = self::NOW - 7 * 86400;

        $s->feed('alice', ['team-a', 'team-b', 'team-c'], $from, self::NOW, 20);

        $reads = array_values(array_filter($this->requests, static fn (array $r) => str_contains($r[0], '/work_packages')));
        $this->assertCount(2, $reads, 'a link to another host is stale and never asked');
        $this->assertSame('projects/12/work_packages', $reads[0][0]);
        $this->assertSame('projects/13/work_packages', $reads[1][0]);
        $filters = json_decode($reads[0][1]['filters'], true);
        $this->assertSame('<>d', $filters[0]['updatedAt']['operator']);
        // Bucketed to five minutes: the lower bound rounds down, the upper up.
        $this->assertSame('2026-09-05T12:00:00Z', $filters[0]['updatedAt']['values'][0]);
        $this->assertSame('2026-09-12T12:00:00Z', $filters[0]['updatedAt']['values'][1]);
        $this->assertSame([['updatedAt', 'desc']], json_decode($reads[0][1]['sortBy'], true));
        $this->assertSame(20, $reads[0][1]['pageSize']);
    }

    public function testAllTimeMeansThirtyDaysForOpenProjectAndAnOpenUpperBound(): void {
        $s = $this->service(['team-a' => ['projectId' => 12]]);
        $s->feed('alice', ['team-a'], 0, 0, 20);
        $read    = array_values(array_filter($this->requests, static fn (array $r) => str_contains($r[0], '/work_packages')))[0];
        $filters = json_decode($read[1]['filters'], true);
        $lower   = strtotime($filters[0]['updatedAt']['values'][0]);
        $this->assertGreaterThanOrEqual(time() - 30 * 86400 - 300, $lower);
        $this->assertLessThanOrEqual(time() - 30 * 86400, $lower);
        $this->assertSame('', $filters[0]['updatedAt']['values'][1], 'no upper bound = now');
    }

    // ── Classification + shape ─────────────────────────────────────────

    public function testEachWorkPackageIsOneRowClassifiedFromOpenProjectsOwnLists(): void {
        $s = $this->service(['team-a' => ['projectId' => 12]], $this->answering([
            $this->wp(1, '2026-09-12T10:00:00Z'),                                                                  // updated
            $this->wp(2, '2026-09-12T09:00:30Z', ['createdAt' => '2026-09-12T09:00:00Z']),                       // created
            $this->wp(3, '2026-09-12T08:00:00Z', ['_links' => ['status' => ['href' => '/api/v3/statuses/13', 'title' => 'Closed']]]),  // completed
            $this->wp(4, '2026-09-12T07:00:00Z', ['_links' => ['type' => ['href' => '/api/v3/types/2', 'title' => 'Milestone']]]),     // milestone updated
            $this->wp(5, '2026-09-12T06:00:00Z', ['_links' => [
                'type'   => ['href' => '/api/v3/types/2', 'title' => 'Milestone'],
                'status' => ['href' => '/api/v3/statuses/13', 'title' => 'Closed'],
            ]]),                                                                                                     // milestone completed
            $this->wp(6, ''),                                                                                        // no timestamp: dropped
        ]));

        $r = $s->feed('alice', ['team-a'], self::NOW - 86400, self::NOW, 20);

        $this->assertSame('ok', $r['status']['state']);
        $types = [];
        foreach ($r['items'] as $row) {
            $types[$row['workPackage']['id']] = $row['activityType'];
        }
        $this->assertSame([1 => 'updated', 2 => 'created', 3 => 'completed', 4 => 'milestone_updated', 5 => 'milestone_completed'], $types);

        $row = $r['items'][0];
        $this->assertSame('openproject', $row['source']);
        $this->assertSame('op:' . substr(md5(self::HOST), 0, 12) . ':12:1', $row['id']);
        $this->assertSame('team-a', $row['team_id']);
        $this->assertSame(strtotime('2026-09-12T10:00:00Z'), $row['created_at']);
        $this->assertSame('', $row['message'], 'never a body');
        $this->assertSame('', $row['author_id'], 'not a Nextcloud account');
        $this->assertSame('12', $row['project']['id']);
        $this->assertSame('Project 12', $row['project']['name']);
        $this->assertSame(self::HOST . '/projects/p12', $row['project']['url']);
        $this->assertSame(self::HOST . '/work_packages/1', $row['workPackage']['url']);
        $this->assertSame('In progress', $row['workPackage']['status']);
        $this->assertTrue($row['opens_externally']);
        $this->assertSame('Alice Example', $row['workPackage']['assignee']);
        $this->assertArrayNotHasKey('description', $row['workPackage']);
    }

    public function testTheActorIsKnownForACreationOnly(): void {
        $s = $this->service(['team-a' => ['projectId' => 12]], $this->answering([
            $this->wp(1, '2026-09-12T10:00:00Z', ['_links' => ['author' => ['href' => '/api/v3/users/9', 'title' => 'Carol']]]),
            $this->wp(2, '2026-09-12T09:00:30Z', ['createdAt' => '2026-09-12T09:00:00Z', '_links' => ['author' => ['href' => '/api/v3/users/9', 'title' => 'Carol <b>C</b>']]]),
        ]));

        $r = $s->feed('alice', ['team-a'], self::NOW - 86400, self::NOW, 20);

        $this->assertNull($r['items'][0]['actor_name'], 'the collection does not say who made the last change');
        $this->assertSame('Carol C', $r['items'][1]['actor_name']);
    }

    public function testNewestFirstWithAStableTieBreak(): void {
        $s = $this->service(['team-a' => ['projectId' => 12], 'team-b' => ['projectId' => 13]], function (string $endpoint): array {
            $id = str_contains($endpoint, '/12/') ? 1 : 2;
            return self::collectionResponse([$this->wp($id, '2026-09-12T10:00:00Z')], 1);
        });

        $r = $s->feed('alice', ['team-b', 'team-a'], self::NOW - 86400, self::NOW, 20);

        $ids = array_map(static fn (array $row) => $row['id'], $r['items']);
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $ids, 'same timestamp → ordered by id');
    }

    public function testTheExactWindowIsAppliedOnTopOfTheBucketedRead(): void {
        $s = $this->service(['team-a' => ['projectId' => 12]], $this->answering([
            $this->wp(1, '2026-09-12T11:59:00Z'),   // inside
            $this->wp(2, '2026-09-12T12:00:30Z'),   // after `to`
            $this->wp(3, '2026-09-11T11:59:00Z'),   // before `from`
        ]));

        $r = $s->feed('alice', ['team-a'], self::NOW - 86400 + 120, self::NOW, 20);

        $this->assertSame([1], array_map(static fn (array $row) => $row['workPackage']['id'], $r['items']));
    }

    // ── Narrowing + facets ─────────────────────────────────────────────

    public function testTypeAndProjectFiltersNarrowRowsButNotTheProjectFacet(): void {
        $s = $this->service(['team-a' => ['projectId' => 12], 'team-b' => ['projectId' => 13]], function (string $endpoint): array {
            if (str_contains($endpoint, '/12/')) {
                return self::collectionResponse([
                    $this->wp(1, '2026-09-12T10:00:00Z'),
                    $this->wp(2, '2026-09-12T09:00:30Z', ['createdAt' => '2026-09-12T09:00:00Z']),
                ], 2);
            }
            return self::collectionResponse([$this->wp(3, '2026-09-12T08:00:00Z')], 1);
        });

        $r = $s->feed('alice', ['team-a', 'team-b'], self::NOW - 86400, self::NOW, 20, ['created'], ['12']);

        $this->assertSame([2], array_map(static fn (array $row) => $row['workPackage']['id'], $r['items']));
        $this->assertSame(['12', '13'], array_map(static fn (array $p) => $p['id'], $r['projects']));
        $this->assertSame(2, $r['projects'][0]['count']);
    }

    // ── Failure isolation ──────────────────────────────────────────────

    public function testAForbiddenProjectIsSkippedAndTheRestIsPartial(): void {
        $s = $this->service(['team-a' => ['projectId' => 12], 'team-b' => ['projectId' => 13]], function (string $endpoint): array {
            if (str_contains($endpoint, '/13/')) {
                return ['error' => 'Forbidden', 'statusCode' => 403, 'message' => 'no'];
            }
            return self::collectionResponse([$this->wp(1, '2026-09-12T10:00:00Z')], 1);
        });

        $r = $s->feed('alice', ['team-a', 'team-b'], self::NOW - 86400, self::NOW, 20);

        $this->assertCount(1, $r['items']);
        $this->assertSame('partial', $r['status']['state']);
        $this->assertSame('permission_denied', $r['status']['code']);
        $this->assertNotSame('', $r['status']['message']);
        $this->assertSame(1, $r['status']['covered']);
        $this->assertSame(1, $r['status']['skipped']);
        $h = $this->health->describe(OpenProjectSyncHealth::CHANNEL_ACTIVITY);
        $this->assertSame(1, $h['counters']['permissionProblems']);
        $this->assertSame(1, $h['counters']['partial']);
    }

    public function testEveryProjectFailingIsAnErrorStateNotAnException(): void {
        $s = $this->service(['team-a' => ['projectId' => 12]], fn () => ['error' => 'x', 'statusCode' => 404]);
        $r = $s->feed('alice', ['team-a'], 0, 0, 20);
        $this->assertSame('error', $r['status']['state']);
        $this->assertSame('api_unavailable', $r['status']['code']);
        $this->assertSame([], $r['items']);
    }

    public function testARefusedTokenStopsAfterTheFirstProject(): void {
        $s = $this->service(['team-a' => ['projectId' => 12], 'team-b' => ['projectId' => 13]], fn () => ['error' => 'no', 'statusCode' => 401, 'message' => 'no']);
        $r = $s->feed('alice', ['team-a', 'team-b'], 0, 0, 20);
        $this->assertSame('auth_required', $r['status']['state']);
        foreach ($this->requests as $req) {
            $this->assertStringNotContainsString('/13/', $req[0]);
        }
    }

    public function testAtMostTenProjectsPerLoad(): void {
        $linked = [];
        for ($i = 1; $i <= 12; $i++) {
            $linked['team-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT)] = ['projectId' => 100 + $i];
        }
        $s = $this->service($linked);
        $s->feed('alice', array_keys($linked), 0, 0, 20);
        $reads = array_filter($this->requests, static fn (array $r) => str_contains($r[0], '/work_packages'));
        $this->assertCount(OpenProjectActivityService::PROJECT_CAP, $reads);
    }

    // ── Sanitising + isolation ─────────────────────────────────────────

    public function testHtmlNeverReachesARow(): void {
        $s = $this->service(['team-a' => ['projectId' => 12]], $this->answering([
            $this->wp(1, '2026-09-12T10:00:00Z', [
                'subject' => '<img src=x onerror=alert(1)>Plan [x](javascript:void(0))',
                '_links'  => ['status' => ['href' => '/api/v3/statuses/7', 'title' => '<script>x</script>Open']],
            ]),
        ]));
        $row = $s->feed('alice', ['team-a'], 0, 0, 20)['items'][0];
        $this->assertSame('Plan x', $row['subject']);
        $this->assertSame('xOpen', $row['workPackage']['status']);
        $this->assertStringNotContainsString('<', json_encode($row));
    }

    public function testTwoViewersNeverShareACachedAnswer(): void {
        $s = $this->service(['team-a' => ['projectId' => 12]], function (string $endpoint, array $params, string $uid): array {
            return self::collectionResponse([$this->wp($uid === 'alice' ? 1 : 2, '2026-09-12T10:00:00Z')], 1);
        });
        $a = $s->feed('alice', ['team-a'], self::NOW - 86400, self::NOW, 20);
        $b = $s->feed('bob', ['team-a'], self::NOW - 86400, self::NOW, 20);
        $a2 = $s->feed('alice', ['team-a'], self::NOW - 86400, self::NOW, 20);
        $this->assertSame(1, $a['items'][0]['workPackage']['id']);
        $this->assertSame(2, $b['items'][0]['workPackage']['id']);
        $this->assertSame(1, $a2['items'][0]['workPackage']['id']);
        $reads = array_filter($this->requests, static fn (array $r) => str_contains($r[0], '/work_packages'));
        $this->assertCount(2, $reads, 'the second read for alice is served from her own cache');
    }

    public function testATeamOutsideTheViewersSetIsNeverRead(): void {
        $s = $this->service(['team-a' => ['projectId' => 12], 'team-z' => ['projectId' => 99]]);
        $s->feed('alice', ['team-a'], 0, 0, 20);
        foreach ($this->requests as $r) {
            $this->assertStringNotContainsString('/99/', $r[0]);
        }
    }
}
