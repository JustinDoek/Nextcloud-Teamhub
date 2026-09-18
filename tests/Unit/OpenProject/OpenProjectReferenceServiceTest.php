<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\OpenProject;

use OCA\TeamHub\MyWork\Priority;
use OCA\TeamHub\Service\OpenProject\OpenProjectModuleService;
use OCA\TeamHub\Service\OpenProject\OpenProjectReferenceService;
use OCA\TeamHub\Service\OpenProject\OpenProjectSyncHealth;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * OpenProject's own vocabulary (v4.9.7): closed statuses, priority ranks
 * from positions rather than names, milestone types — read once per
 * viewer, degrading to "unknown" rather than failing. Plus the health
 * ledger's arithmetic, which records nothing personal.
 */
class OpenProjectReferenceServiceTest extends OpenProjectTestCase {

    /** @var list<array{0: string, 1: string}> endpoint, user */
    private array $requests = [];

    private function service(?callable $handler = null): OpenProjectReferenceService {
        $this->withHost();
        $this->withConnectedUser('alice');
        $this->withConnectedUser('bob', 'Bob');
        $this->requests = [];
        $op = $this->opService(function (string $uid, string $endpoint, array $params) use ($handler) {
            $this->requests[] = [$endpoint, $uid];
            return $handler ? $handler($endpoint, $params) : self::collectionResponse([], 0, 'Collection');
        });
        $client = $this->client($op);
        return new OpenProjectReferenceService($client, $this->cache());
    }

    public function testPriorityRanksComeFromPositionsRelativeToTheDefault(): void {
        $s = $this->service(fn (string $endpoint) => $endpoint === 'priorities'
            ? self::collectionResponse([
                ['_type' => 'Priority', 'id' => 1, 'name' => 'Niedrig', 'position' => 1, 'isDefault' => false],
                ['_type' => 'Priority', 'id' => 2, 'name' => 'Normal', 'position' => 2, 'isDefault' => true],
                ['_type' => 'Priority', 'id' => 3, 'name' => 'Hoch', 'position' => 3, 'isDefault' => false],
                ['_type' => 'Priority', 'id' => 4, 'name' => 'Sofort', 'position' => 4, 'isDefault' => false],
                ['_type' => 'Priority', 'id' => 5, 'name' => 'Odd', 'isDefault' => false],
                ['_type' => 'Junk', 'id' => 6],
            ], 6, 'Collection')
            : self::collectionResponse([], 0, 'Collection'));

        $ranks = $s->priorityRanks('alice');

        $this->assertSame([1 => Priority::LOW, 2 => Priority::NORMAL, 3 => Priority::HIGH, 4 => Priority::HIGH, 5 => Priority::NORMAL], $ranks);
    }

    public function testWithoutADefaultEverythingIsNormal(): void {
        $s = $this->service(fn () => self::collectionResponse([
            ['_type' => 'Priority', 'id' => 1, 'position' => 1, 'isDefault' => false],
            ['_type' => 'Priority', 'id' => 3, 'position' => 3, 'isDefault' => false],
        ], 2, 'Collection'));
        $this->assertSame([1 => Priority::NORMAL, 3 => Priority::NORMAL], $s->priorityRanks('alice'));
    }

    public function testClosedStatusesAndAnUnreadableListDegradesToEmpty(): void {
        $s = $this->service(fn (string $endpoint) => $endpoint === 'statuses'
            ? self::collectionResponse([
                ['_type' => 'Status', 'id' => 1, 'isClosed' => false],
                ['_type' => 'Status', 'id' => 2, 'isClosed' => true],
            ], 2, 'Collection')
            : ['error' => 'Forbidden', 'statusCode' => 403, 'message' => 'no']);

        $this->assertSame([1 => false, 2 => true], $s->closedStatuses('alice'));
        $this->assertSame([], $s->priorityRanks('alice'), 'a 403 on the list is "unknown", not a failure');
    }

    public function testListsAreCachedPerViewerAndHost(): void {
        $s = $this->service(fn () => self::collectionResponse([['_type' => 'Status', 'id' => 1, 'isClosed' => true]], 1, 'Collection'));

        $s->closedStatuses('alice');
        $s->closedStatuses('alice');
        $s->closedStatuses('bob');

        $this->assertSame([['statuses', 'alice'], ['statuses', 'bob']], $this->requests);
    }

    public function testMilestoneTypesArePerProjectAndCached(): void {
        $s = $this->service(fn (string $endpoint) => str_ends_with($endpoint, '/types')
            ? self::collectionResponse([
                ['_type' => 'Type', 'id' => 1, 'isMilestone' => false],
                ['_type' => 'Type', 'id' => 2, 'isMilestone' => true],
                ['_type' => 'Type', 'id' => 3, 'isMilestone' => true],
            ], 3, 'Collection')
            : self::collectionResponse([], 0, 'Collection'));

        $this->assertSame([2, 3], $s->milestoneTypeIds('alice', 12));
        $this->assertSame([2, 3], $s->milestoneTypeIds('alice', 12));
        $this->assertCount(1, $this->requests);
    }

    // ── Health ledger ──────────────────────────────────────────────────

    public function testTheHealthLedgerCountsWithoutRecordingAnythingPersonal(): void {
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn(new \OC\Memcache\ArrayCache());
        $health = new OpenProjectSyncHealth($factory, $this->config(), $this->createMock(LoggerInterface::class));

        $health->recordRun('mywork', 3, 0, []);
        $health->recordRun('mywork', 2, 1, ['permission_denied']);
        $health->recordRun('mywork', 1, 2, ['auth_failed', 'temporary_failure'], budgetExhausted: true);
        $health->recordFailure('activity', 'user_not_connected');
        $health->recordRun('bogus', 1, 0, []);

        $m = $health->describe('mywork');
        $this->assertSame(3, $m['counters']['attempts']);
        $this->assertSame(1, $m['counters']['successes']);
        $this->assertSame(2, $m['counters']['partial']);
        $this->assertSame(6, $m['counters']['projectsCovered']);
        $this->assertSame(3, $m['counters']['projectsSkipped']);
        $this->assertSame(1, $m['counters']['permissionProblems']);
        $this->assertSame(1, $m['counters']['authProblems']);
        $this->assertSame(1, $m['counters']['timeouts']);
        $this->assertSame(1, $m['counters']['budgetExhausted']);
        $this->assertSame('temporary_failure', $m['lastErrorCode']);
        $this->assertNotNull($m['lastSuccessAt']);
        $this->assertNotNull($m['lastErrorAt']);

        $a = $health->describe('activity');
        $this->assertSame(1, $a['counters']['authProblems']);
        $this->assertSame('user_not_connected', $a['lastErrorCode']);

        $rows = json_encode($health->diagnosticRows());
        $this->assertStringContainsString('6 covered, 3 skipped', $rows);
        $this->assertStringNotContainsString('alice', $rows);
        foreach ($this->appValues['teamhub'] ?? [] as $key => $value) {
            if ($key === OpenProjectModuleService::CONFIG_ENABLED) {
                continue; // seeded by OpenProjectTestCase::setUp(), not written by the ledger
            }
            $this->assertStringStartsWith('openproject_health_', $key);
            $this->assertMatchesRegularExpression('/^[0-9a-z_]*$/', $value, 'timestamps and codes only');
        }
    }

    public function testAnUnknownErrorCodeIsNotStoredVerbatim(): void {
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn(new \OC\Memcache\ArrayCache());
        $health = new OpenProjectSyncHealth($factory, $this->config(), $this->createMock(LoggerInterface::class));
        $health->recordRun('activity', 0, 1, ['<script>alert(1)</script>']);
        $this->assertSame('unknown', $health->describe('activity')['lastErrorCode']);
    }
}
