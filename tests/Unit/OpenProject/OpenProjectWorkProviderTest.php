<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\OpenProject;

use OCA\TeamHub\MyWork\ActionType;
use OCA\TeamHub\MyWork\Category;
use OCA\TeamHub\MyWork\OpenTarget;
use OCA\TeamHub\MyWork\Priority;
use OCA\TeamHub\MyWork\Provider\OpenProjectWorkProvider;
use OCA\TeamHub\MyWork\WorkItem;
use OCA\TeamHub\MyWork\WorkItemPage;
use OCA\TeamHub\MyWork\WorkQuery;
use OCA\TeamHub\Service\MyWorkConfigService;
use OCA\TeamHub\Service\OpenProject\OpenProjectProjectService;
use OCA\TeamHub\Service\OpenProject\OpenProjectReferenceService;
use OCA\TeamHub\Service\OpenProject\OpenProjectSyncHealth;
use OCA\TeamHub\Service\OpenProject\OpenProjectWorkPackageService;
use OCA\TeamHub\Service\OpenProject\TeamOpenProjectLinkService;
use OCA\TeamHub\Service\TimezoneService;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * The My Work provider (v4.9.5; Phase 3 in v4.9.7): which teams cost a
 * request, what a row looks like, how categories and reasons come out of
 * the viewer's own calendar and OpenProject's own lists, that one work
 * package is one row, that a failing project or a refused token never
 * empties the rest, and that the re-read authorises against the source.
 */
class OpenProjectWorkProviderTest extends OpenProjectTestCase {

    /** Noon UTC, 2026-09-12 — a fixed "now" for every query. */
    private const NOW = 1789214400;

    /** @var list<array{0: string, 1: array, 2: string, 3: string}> endpoint, params, method, user */
    private array $requests = [];
    /** @var TeamOpenProjectLinkService&MockObject */
    private TeamOpenProjectLinkService $links;
    private OpenProjectSyncHealth $health;
    private IConfig $config;

    /**
     * @param array<string, array{projectId: int, stale?: bool}> $linked teams with a link
     * @param callable|null $handler answers OpenProject requests (endpoint, params, method)
     */
    private function provider(
        array $linked,
        ?callable $handler = null,
        bool $connected = true,
        bool $enabled = true,
        ?string $timezone = null,
        int $lastSeen = 0,
    ): OpenProjectWorkProvider {
        $this->withHost();
        if ($connected) {
            $this->withConnectedUser('alice');
            $this->withConnectedUser('bob', 'Bob Example');
        }
        if ($timezone !== null) {
            $this->userValues['alice']['core']['timezone'] = $timezone;
        }
        if ($lastSeen > 0) {
            $this->userValues['alice']['teamhub']['mywork_openproject_last_seen'] = (string)$lastSeen;
        }
        $this->requests = [];

        $op = $this->opService(function (string $uid, string $endpoint, array $params, string $method) use ($handler) {
            $this->requests[] = [$endpoint, $params, $method, $uid];
            $default = $this->referenceLists($endpoint);
            if ($default !== null) {
                return $default;
            }
            return $handler ? $handler($endpoint, $params, $uid) : self::collectionResponse([], 0);
        });
        $client = $this->client($op, enabled: $enabled);

        $this->links = $this->createMock(TeamOpenProjectLinkService::class);
        $this->links->method('linksForTeams')->willReturnCallback(function (array $teamIds) use ($linked): array {
            $out = [];
            foreach ($teamIds as $id) {
                if (isset($linked[$id])) {
                    $out[$id] = [
                        'projectId' => $linked[$id]['projectId'], 'projectIdentifier' => 'p' . $linked[$id]['projectId'], 'projectName' => 'Project ' . $linked[$id]['projectId'],
                        'host' => self::HOST, 'stale' => $linked[$id]['stale'] ?? false, 'url' => null,
                    ];
                }
            }
            return $out;
        });

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn (string $s) => $s);
        $l->method('n')->willReturnCallback(fn (string $s, string $p, int $n) => str_replace('%n', (string)$n, $n === 1 ? $s : $p));

        $myWorkConfig = $this->createMock(MyWorkConfigService::class);
        $myWorkConfig->method('getUpcomingDays')->willReturn(7);
        $myWorkConfig->method('getCompletedDays')->willReturn(7);
        $myWorkConfig->method('getActionRequiredDays')->willReturn(2);

        $this->config = $this->config();
        $this->config->method('setUserValue')->willReturnCallback(function (string $uid, string $app, string $key, $value): void {
            $this->userValues[$uid][$app][$key] = (string)$value;
        });

        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn(new \OC\Memcache\ArrayCache());
        $this->health = new OpenProjectSyncHealth($factory, $this->config, $this->createMock(LoggerInterface::class));

        $cache = $this->cache();
        return new OpenProjectWorkProvider(
            $client,
            new OpenProjectWorkPackageService($client, $cache, new OpenProjectProjectService($client, $cache, new TimezoneService($this->config))),
            new OpenProjectReferenceService($client, $cache),
            $this->links,
            $this->health,
            $myWorkConfig,
            new TimezoneService($this->config),
            $this->config,
            $l,
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * OpenProject's vocabulary as every test sees it: statuses 7 (open) and
     * 13 (closed); priorities Low 1 (position 1), Normal 8 (default,
     * position 2), High 9 (position 3), Immediate 10 (position 4); project
     * 12's types: Task 1 and Milestone 2 (a milestone).
     *
     * @return ?array<string, mixed>
     */
    private function referenceLists(string $endpoint): ?array {
        if ($endpoint === 'statuses') {
            return self::collectionResponse([
                ['_type' => 'Status', 'id' => 7, 'name' => 'In progress', 'isClosed' => false, 'isDefault' => false, 'position' => 2],
                ['_type' => 'Status', 'id' => 13, 'name' => 'Closed', 'isClosed' => true, 'isDefault' => false, 'position' => 5],
            ], 2, 'Collection');
        }
        if ($endpoint === 'priorities') {
            return self::collectionResponse([
                ['_type' => 'Priority', 'id' => 1, 'name' => 'Low', 'position' => 1, 'isDefault' => false, 'isActive' => true],
                ['_type' => 'Priority', 'id' => 8, 'name' => 'Normal', 'position' => 2, 'isDefault' => true, 'isActive' => true],
                ['_type' => 'Priority', 'id' => 9, 'name' => 'High', 'position' => 3, 'isDefault' => false, 'isActive' => true],
                ['_type' => 'Priority', 'id' => 10, 'name' => 'Immediate', 'position' => 4, 'isDefault' => false, 'isActive' => true],
            ], 4, 'Collection');
        }
        if (preg_match('#^projects/\d+/types$#', $endpoint)) {
            return self::collectionResponse([
                ['_type' => 'Type', 'id' => 1, 'name' => 'Task', 'isMilestone' => false],
                ['_type' => 'Type', 'id' => 2, 'name' => 'Milestone', 'isMilestone' => true],
            ], 2, 'Collection');
        }
        return null;
    }

    private function query(array $teamIds = ['team-a'], int $upcomingDays = 7, bool $includeCompleted = true, array $categories = [], int $actionRequiredDays = 2): WorkQuery {
        return new WorkQuery(
            userId: 'alice',
            teamIds: $teamIds,
            teamNames: ['team-a' => 'Team A', 'team-b' => 'Team B', 'team-c' => 'Team C'],
            categories: $categories,
            upcomingDays: $upcomingDays,
            actionRequiredDays: $actionRequiredDays,
            completedDays: 7,
            includeCompleted: $includeCompleted,
            now: self::NOW,
        );
    }

    /** Which of the per-project reads a request is, by its filters. */
    private function readKind(array $request): string {
        [$endpoint, $params] = $request;
        if (!str_contains($endpoint, '/work_packages')) {
            return $endpoint;
        }
        $filters = json_decode($params['filters'] ?? '[]', true);
        $keys    = [];
        foreach ($filters as $f) {
            foreach ($f as $name => $spec) {
                $keys[] = $name . ' ' . $spec['operator'];
            }
        }
        return implode(', ', $keys);
    }

    /**
     * A handler answering each read kind with its own elements.
     *
     * @param array<string, list<array<string, mixed>>> $byKind keyed by readKind()
     */
    private function answering(array $byKind): callable {
        return function (string $endpoint, array $params) use ($byKind): array {
            $kind = $this->readKind([$endpoint, $params]);
            $elements = $byKind[$kind] ?? [];
            return self::collectionResponse($elements, count($elements));
        };
    }

    private const KIND_DATED     = 'status o, assignee =, dueDate <>d';
    private const KIND_RECENT    = 'status o, assignee =, updatedAt >t-';
    private const KIND_AUTHORED  = 'status o, author =, assignee !*';
    private const KIND_COMPLETED = 'status c, assignee =, updatedAt >t-';
    private const KIND_MILESTONE = 'status o, type =, dueDate <>d';

    /** @return array<string, WorkItem> keyed by providerItemId */
    private function byId(WorkItemPage $page): array {
        $out = [];
        foreach ($page->items as $item) {
            $out[$item->providerItemId] = $item;
        }
        return $out;
    }

    // ── Identity ───────────────────────────────────────────────────────

    public function testIdentityAndCapabilities(): void {
        $p = $this->provider([]);
        $this->assertSame('openproject', $p->getId());
        $caps = $p->getCapabilities();
        $this->assertSame([ActionType::OPEN], $caps['actions']);
        $this->assertSame([Category::ACTION_REQUIRED, Category::UPCOMING, Category::COMPLETED], $caps['categories']);
        $this->assertSame(['openproject_work_package', 'openproject_milestone'], $caps['resourceTypes']);
        $this->assertSame(OpenProjectWorkProvider::STATUSES, $caps['statuses']);
        $this->assertContains('authored', $caps['statuses']);
        $this->assertTrue($p->isAvailable());
    }

    public function testUnavailableWithoutTheIntegrationApp(): void {
        $p = $this->provider([], enabled: false);
        $this->assertFalse($p->isAvailable());
        $this->assertNotNull($p->getUnavailableReason());
        $this->assertSame([], $p->fetchItems($this->query())->items);
        $this->assertSame([], $this->requests);
    }

    /**
     * v4.9.16 — the module off is its own reason, named before the app's,
     * and nothing is asked of OpenProject.
     */
    public function testUnavailableWhileTheModuleIsOffNamesTheModule(): void {
        $this->withModuleOff();
        $p = $this->provider(['team-a' => ['projectId' => 12]], $this->answering([]));
        $this->assertFalse($p->isAvailable());
        $this->assertStringContainsString('module', (string)$p->getUnavailableReason());
        $this->assertSame([], $p->fetchItems($this->query())->items);
        $this->assertSame([], $this->requests);
        $this->assertSame('unlicensed or disabled', $p->getDiagnostics()[0]['value']);
    }

    public function testDiagnosticsNeverCarryAViewersRows(): void {
        $p = $this->provider(['team-a' => ['projectId' => 12]], $this->answering([
            self::KIND_DATED => [self::workPackageResponse(1, ['subject' => 'Secret plan', 'dueDate' => '2026-09-10'])],
        ]));
        $p->fetchItems($this->query());
        $flat = json_encode($p->getDiagnostics());
        $this->assertStringNotContainsString('Secret plan', $flat);
        $this->assertStringNotContainsString('alice', $flat);
        $this->assertStringContainsString('My Work', $flat);
        $this->assertStringContainsString('1 covered, 0 skipped', $flat);
    }

    // ── Which teams and which reads ────────────────────────────────────

    public function testOnlyLinkedNonStaleTeamsCostRequestsAndEachCostsFiveReads(): void {
        $p = $this->provider([
            'team-a' => ['projectId' => 12],
            'team-b' => ['projectId' => 13, 'stale' => true],
        ]);

        $p->fetchItems($this->query(['team-a', 'team-b', 'team-c']));

        $kinds = array_map(fn (array $r) => $this->readKind($r), $this->requests);
        $this->assertSame([
            'statuses', 'priorities',
            self::KIND_DATED, self::KIND_RECENT, self::KIND_AUTHORED, self::KIND_COMPLETED,
            'projects/12/types', self::KIND_MILESTONE,
        ], $kinds);
        foreach ($this->requests as $r) {
            $this->assertStringNotContainsString('/13/', $r[0], 'a stale link costs nothing');
        }
    }

    public function testAnUnconnectedViewerGetsNoRowsNoRequestAndNoWarning(): void {
        $p = $this->provider(['team-a' => ['projectId' => 12]], connected: false);
        $page = $p->fetchItems($this->query());
        $this->assertSame([], $page->items);
        $this->assertSame([], $page->warnings);
        $this->assertSame([], $this->requests);
    }

    public function testTheHorizonIsPushedIntoTheFilterAsTheViewersDate(): void {
        // 23:30 in Auckland on the 12th is 11:30 UTC; "now" (12:00 UTC) is
        // already the 13th there, so the horizon is the 20th, not the 19th.
        $p = $this->provider(['team-a' => ['projectId' => 12]], timezone: 'Pacific/Auckland');

        $p->fetchItems($this->query(upcomingDays: 7));

        $filters = json_decode($this->requests[2][1]['filters'], true);
        $this->assertSame(['operator' => '=', 'values' => ['me']], $filters[1]['assignee']);
        $this->assertSame(['1970-01-01', '2026-09-20'], $filters[2]['dueDate']['values']);
    }

    public function testTheCompletedReadIsSkippedWhenNotWanted(): void {
        $p = $this->provider(['team-a' => ['projectId' => 12]]);
        $p->fetchItems($this->query(includeCompleted: false));
        $kinds = array_map(fn (array $r) => $this->readKind($r), $this->requests);
        $this->assertNotContains(self::KIND_COMPLETED, $kinds);
    }

    // ── Categories, reasons, priorities ────────────────────────────────

    public function testRowsAreCategorisedByDueDateInTheViewersCalendar(): void {
        $p = $this->provider(['team-a' => ['projectId' => 12]], $this->answering([
            self::KIND_DATED => [
                self::workPackageResponse(1, ['dueDate' => '2026-09-09']),   // overdue by 3 days
                self::workPackageResponse(2, ['dueDate' => '2026-09-12']),   // today
                self::workPackageResponse(3, ['dueDate' => '2026-09-13']),   // tomorrow
                self::workPackageResponse(4, ['dueDate' => '2026-09-16']),   // in 4 days
            ],
        ]));

        $rows = $this->byId($p->fetchItems($this->query()));
        $this->assertCount(4, $rows);

        $this->assertSame(Category::ACTION_REQUIRED, $rows['team-a/1']->category);
        $this->assertSame(Priority::URGENT, $rows['team-a/1']->priority);
        $this->assertSame('overdue', $rows['team-a/1']->status);
        $this->assertSame('Overdue by 3 days', $rows['team-a/1']->reason);

        // Inside the 2-day band: Action required, but not urgent.
        $this->assertSame(Category::ACTION_REQUIRED, $rows['team-a/2']->category);
        $this->assertSame(Priority::HIGH, $rows['team-a/2']->priority);
        $this->assertSame('due_today', $rows['team-a/2']->status);
        $this->assertSame('Due today', $rows['team-a/2']->reason);
        // Not overdue at noon: the day ends at its last second.
        $this->assertGreaterThan(self::NOW, $rows['team-a/2']->dueAt);

        $this->assertSame(Category::ACTION_REQUIRED, $rows['team-a/3']->category);
        $this->assertSame('due_soon', $rows['team-a/3']->status);
        $this->assertSame('Due tomorrow', $rows['team-a/3']->reason);

        // Beyond the band: Upcoming, with OpenProject's own priority.
        $this->assertSame(Category::UPCOMING, $rows['team-a/4']->category);
        $this->assertSame('Due in 4 days', $rows['team-a/4']->reason);
        $this->assertSame(Priority::NORMAL, $rows['team-a/4']->priority);
    }

    /**
     * Justin's review (2026-09-14): a work package due the day after
     * tomorrow read "Due in 2 days" and sat under Upcoming with the band
     * set to 2 days — the service measured the band from the end of the due
     * day. The band is whole days here, so the row and the setting agree.
     */
    public function testTheActionableBandIsMeasuredInWholeDays(): void {
        $p = $this->provider(['team-a' => ['projectId' => 12]], $this->answering([
            self::KIND_DATED => [
                self::workPackageResponse(1, ['dueDate' => '2026-09-14']),   // in 2 days, 23:59 local
                self::workPackageResponse(2, ['dueDate' => '2026-09-15']),   // in 3 days
            ],
        ]));

        $rows = $this->byId($p->fetchItems($this->query()));
        $this->assertSame('Due in 2 days', $rows['team-a/1']->reason);
        $this->assertSame(Category::ACTION_REQUIRED, $rows['team-a/1']->category);
        $this->assertSame(Priority::HIGH, $rows['team-a/1']->priority);
        $this->assertSame(Category::UPCOMING, $rows['team-a/2']->category);

        // A wider band takes the second one too; 0 means only overdue counts.
        $rows = $this->byId($p->fetchItems($this->query(actionRequiredDays: 3)));
        $this->assertSame(Category::ACTION_REQUIRED, $rows['team-a/2']->category);
        $rows = $this->byId($p->fetchItems($this->query(actionRequiredDays: 0)));
        $this->assertSame(Category::UPCOMING, $rows['team-a/1']->category);
        $this->assertSame(Priority::NORMAL, $rows['team-a/1']->priority);
    }

    public function testADueDateEndsAtTheEndOfTheViewersDayAcrossADstChange(): void {
        // Europe/Amsterdam, 2026-03-29: the clocks skip an hour, the day is
        // 23 hours long, and "due on the 29th" still ends at 23:59:59 local
        // (21:59:59 UTC) — not 22:59:59 UTC as 86400 seconds from midnight
        // would say. "Now" is 10:00 UTC on the 29th.
        $now = 1774778400;
        $p = $this->provider(['team-a' => ['projectId' => 12]], $this->answering([
            self::KIND_DATED => [self::workPackageResponse(1, ['dueDate' => '2026-03-29'])],
        ]), timezone: 'Europe/Amsterdam');
        $query = new WorkQuery(userId: 'alice', teamIds: ['team-a'], teamNames: ['team-a' => 'A'], now: $now);

        $rows = $this->byId($p->fetchItems($query));

        $this->assertSame(gmmktime(21, 59, 59, 3, 29, 2026), $rows['team-a/1']->dueAt);
        $this->assertSame('due_today', $rows['team-a/1']->status);
    }

    public function testTheAutumnDstDayIsTwentyFiveHoursLong(): void {
        // 2026-10-25 in Europe/Amsterdam: the clocks go back, the day is 25
        // hours, and it ends at 22:59:59 UTC. "Now" is 10:00 UTC.
        $now = 1792922400;
        $p = $this->provider(['team-a' => ['projectId' => 12]], $this->answering([
            self::KIND_DATED => [self::workPackageResponse(1, ['dueDate' => '2026-10-25'])],
        ]), timezone: 'Europe/Amsterdam');
        $query = new WorkQuery(userId: 'alice', teamIds: ['team-a'], teamNames: ['team-a' => 'A'], now: $now);

        $rows = $this->byId($p->fetchItems($query));

        $this->assertSame(gmmktime(22, 59, 59, 10, 25, 2026), $rows['team-a/1']->dueAt);
    }

    public function testMidnightBoundaryUsesTheViewersZoneNotTheServers(): void {
        // 23:30 in Auckland (UTC+12) on the 12th is 11:30 UTC on the 12th;
        // at "now" = 12:00 UTC it is already 00:00 on the 13th in Auckland.
        // A work package due on the 12th is overdue for that viewer while
        // the server's UTC clock still says the 12th.
        $p = $this->provider(['team-a' => ['projectId' => 12]], $this->answering([
            self::KIND_DATED => [self::workPackageResponse(1, ['dueDate' => '2026-09-12'])],
        ]), timezone: 'Pacific/Auckland');

        $rows = $this->byId($p->fetchItems($this->query()));

        $this->assertSame('overdue', $rows['team-a/1']->status);
        $this->assertSame('Overdue by 1 day', $rows['team-a/1']->reason);
    }

    public function testRecentlyTouchedUndatedWorkGetsARowAndUntouchedUndatedWorkDoesNot(): void {
        $p = $this->provider(['team-a' => ['projectId' => 12]], $this->answering([
            self::KIND_RECENT => [
                self::workPackageResponse(5, ['dueDate' => null, 'updatedAt' => '2026-09-11T09:00:00Z', 'createdAt' => '2026-08-01T00:00:00Z']),
                // Dated beyond the horizon but touched yesterday: a row too.
                self::workPackageResponse(6, ['dueDate' => '2026-12-01', 'updatedAt' => '2026-09-11T09:00:00Z', 'createdAt' => '2026-08-01T00:00:00Z']),
                // Touched a week ago — the read would not return it, but the
                // belt catches it anyway.
                self::workPackageResponse(7, ['dueDate' => null, 'updatedAt' => '2026-09-01T09:00:00Z', 'createdAt' => '2026-08-01T00:00:00Z']),
            ],
        ]));

        $rows = $this->byId($p->fetchItems($this->query()));

        $this->assertArrayHasKey('team-a/5', $rows);
        $this->assertSame(Category::UPCOMING, $rows['team-a/5']->category);
        $this->assertSame('updated', $rows['team-a/5']->status);
        $this->assertSame('Updated recently', $rows['team-a/5']->reason);
        $this->assertNull($rows['team-a/5']->dueAt);
        $this->assertArrayHasKey('team-a/6', $rows);
        $this->assertNotNull($rows['team-a/6']->dueAt);
        $this->assertArrayNotHasKey('team-a/7', $rows);
    }

    public function testWorkTheViewerCreatedAndLeftUnassignedIsTheirs(): void {
        // Justin, 2026-09-14: work packages he created were missing from My
        // Work — nobody was assigned. Created by me + unassigned earns a row
        // under the same date rules as assigned work.
        $unassigned = ['_links' => ['assignee' => ['href' => null], 'author' => ['href' => '/api/v3/users/3', 'title' => 'Alice Example']]];
        $p = $this->provider(['team-a' => ['projectId' => 12]], $this->answering([
            self::KIND_AUTHORED => [
                self::workPackageResponse(1, ['dueDate' => '2026-09-14', 'createdAt' => '2026-09-12T09:00:00Z', 'updatedAt' => '2026-09-12T09:00:00Z'] + $unassigned),
                self::workPackageResponse(2, ['dueDate' => null, 'createdAt' => '2026-09-12T09:00:00Z', 'updatedAt' => '2026-09-12T09:00:00Z'] + $unassigned),
                self::workPackageResponse(3, ['dueDate' => null, 'createdAt' => '2026-06-01T09:00:00Z', 'updatedAt' => '2026-06-01T09:00:00Z'] + $unassigned),
            ],
        ]));

        $rows = $this->byId($p->fetchItems($this->query()));

        $this->assertSame('Due in 2 days', $rows['team-a/1']->reason, 'a deadline still outranks authorship');
        $this->assertContains('authored', $rows['team-a/1']->metadata['reasons']);
        $this->assertSame('authored', $rows['team-a/2']->status);
        $this->assertSame('Created by you, not assigned', $rows['team-a/2']->reason);
        $this->assertSame(Category::UPCOMING, $rows['team-a/2']->category);
        $this->assertArrayNotHasKey('team-a/3', $rows, 'old, undated, untouched: stays in OpenProject');
    }

    public function testUpdatedSinceYourLastVisitAndRecentlyAssigned(): void {
        $lastSeen = self::NOW - 3600;
        $p = $this->provider(['team-a' => ['projectId' => 12]], $this->answering([
            self::KIND_RECENT => [
                // Changed after the last visit.
                self::workPackageResponse(1, ['dueDate' => null, 'updatedAt' => '2026-09-12T11:30:00Z', 'createdAt' => '2026-08-01T00:00:00Z']),
                // Changed before the last visit, but recently.
                self::workPackageResponse(2, ['dueDate' => null, 'updatedAt' => '2026-09-11T11:30:00Z', 'createdAt' => '2026-08-01T00:00:00Z']),
                // Created two days ago: assigned to the viewer since then.
                self::workPackageResponse(3, ['dueDate' => null, 'updatedAt' => '2026-09-10T11:30:00Z', 'createdAt' => '2026-09-10T11:00:00Z']),
            ],
        ]), lastSeen: $lastSeen);

        $rows = $this->byId($p->fetchItems($this->query()));

        $this->assertSame('Updated since your last visit', $rows['team-a/1']->reason);
        $this->assertTrue($rows['team-a/1']->metadata['updatedSinceVisit']);
        $this->assertSame('Updated recently', $rows['team-a/2']->reason);
        $this->assertSame('Recently assigned to you', $rows['team-a/3']->reason);
        $this->assertSame('assigned', $rows['team-a/3']->status);
    }

    public function testTheCheckpointMovesAtMostEveryFiveMinutes(): void {
        $p = $this->provider(['team-a' => ['projectId' => 12]], lastSeen: self::NOW - 120);
        $p->fetchItems($this->query());
        $this->assertSame((string)(self::NOW - 120), $this->userValues['alice']['teamhub']['mywork_openproject_last_seen'], 'too recent to move');

        $p = $this->provider(['team-a' => ['projectId' => 12]], lastSeen: self::NOW - 600);
        $p->fetchItems($this->query());
        $this->assertSame((string)self::NOW, $this->userValues['alice']['teamhub']['mywork_openproject_last_seen']);
    }

    public function testPriorityComesFromOpenProjectsOwnRankingNotFromNames(): void {
        $prio = static fn (int $id, string $title): array => ['_links' => ['priority' => ['href' => '/api/v3/priorities/' . $id, 'title' => $title]]];
        $p = $this->provider(['team-a' => ['projectId' => 12]], $this->answering([
            self::KIND_DATED => [
                self::workPackageResponse(1, ['dueDate' => '2026-09-15'] + $prio(10, 'Sofort')),   // above default → high
                self::workPackageResponse(2, ['dueDate' => '2026-09-15'] + $prio(1, 'Niedrig')),   // below default → low
                self::workPackageResponse(3, ['dueDate' => '2026-09-15'] + $prio(8, 'Normal')),    // the default
                self::workPackageResponse(4, ['dueDate' => '2026-09-15'] + $prio(99, 'Unknown')),  // not in the list
                self::workPackageResponse(5, ['dueDate' => '2026-09-01'] + $prio(1, 'Niedrig')),   // overdue wins
                self::workPackageResponse(6, ['dueDate' => '2026-09-13'] + $prio(1, 'Niedrig')),   // low, but due tomorrow
            ],
        ]));

        $rows = $this->byId($p->fetchItems($this->query()));

        $this->assertSame(Priority::HIGH, $rows['team-a/1']->priority);
        $this->assertSame(Priority::LOW, $rows['team-a/2']->priority);
        $this->assertSame(Priority::NORMAL, $rows['team-a/3']->priority);
        $this->assertSame(Priority::NORMAL, $rows['team-a/4']->priority);
        $this->assertSame(Priority::URGENT, $rows['team-a/5']->priority);
        $this->assertSame(Priority::HIGH, $rows['team-a/6']->priority, 'the actionable band lifts a low priority to high');
        // The source label is never hidden behind the bucket.
        $this->assertSame('Sofort', $rows['team-a/1']->metadata['openProjectPriority']);
    }

    public function testCompletedWorkLandsInCompletedWithItsChangeTimeAsCompletion(): void {
        $p = $this->provider(['team-a' => ['projectId' => 12]], $this->answering([
            self::KIND_COMPLETED => [self::workPackageResponse(9, [
                'dueDate' => '2026-09-01', 'updatedAt' => '2026-09-11T08:00:00Z',
                '_links' => ['status' => ['href' => '/api/v3/statuses/13', 'title' => 'Closed']],
            ])],
        ]));

        $rows = $this->byId($p->fetchItems($this->query()));

        $this->assertSame(Category::COMPLETED, $rows['team-a/9']->category);
        $this->assertSame('completed', $rows['team-a/9']->status);
        $this->assertSame(strtotime('2026-09-11T08:00:00Z'), $rows['team-a/9']->completedAt);
        $this->assertSame(Priority::NORMAL, $rows['team-a/9']->priority, 'an overdue date on closed work is not urgent');
        $this->assertSame('Closed', $rows['team-a/9']->metadata['openProjectStatus']);
    }

    public function testMilestonesAreInformationalUpcomingRowsOfTheirOwnType(): void {
        $p = $this->provider(['team-a' => ['projectId' => 12]], $this->answering([
            self::KIND_MILESTONE => [
                self::workPackageResponse(20, [
                    'dueDate' => '2026-09-15', 'date' => '2026-09-15',
                    '_links' => ['type' => ['href' => '/api/v3/types/2', 'title' => 'Milestone'], 'assignee' => ['href' => null]],
                ]),
                self::workPackageResponse(21, ['dueDate' => '2026-09-12', 'date' => '2026-09-12']),
            ],
        ]));

        $rows = $this->byId($p->fetchItems($this->query()));

        $ms = $rows['team-a/20'];
        $this->assertSame('openproject_milestone', $ms->resourceType);
        $this->assertSame(Category::UPCOMING, $ms->category);
        $this->assertSame('milestone', $ms->status);
        $this->assertSame('Milestone in 3 days', $ms->reason);
        $this->assertTrue($ms->metadata['informational']);
        $this->assertSame(3, $ms->metadata['daysUntil']);
        $this->assertSame('Milestone today', $rows['team-a/21']->reason);
        // The milestone window is [today, horizon] as the viewer's dates.
        $msRequest = array_values(array_filter($this->requests, fn (array $r) => $this->readKind($r) === self::KIND_MILESTONE))[0];
        $filters = json_decode($msRequest[1]['filters'], true);
        $this->assertSame(['2'], $filters[1]['type']['values']);
        $this->assertSame(['2026-09-12', '2026-09-19'], $filters[2]['dueDate']['values']);
    }

    // ── One row per work package ───────────────────────────────────────

    public function testAWorkPackageInTwoReadsIsOneRowWithMergedReasons(): void {
        $wp = self::workPackageResponse(1, ['dueDate' => '2026-09-13', 'updatedAt' => '2026-09-12T09:00:00Z', 'createdAt' => '2026-08-01T00:00:00Z']);
        $p = $this->provider(['team-a' => ['projectId' => 12]], $this->answering([
            self::KIND_DATED  => [$wp],
            self::KIND_RECENT => [$wp],
        ]));

        $page = $p->fetchItems($this->query());

        $this->assertCount(1, $page->items);
        $this->assertSame(1, $page->total);
        $row = $page->items[0];
        $this->assertSame('Due tomorrow', $row->reason, 'the deadline outranks the update');
        $this->assertSame(['due_soon', 'updated'], $row->metadata['reasons']);
    }

    public function testTwoTeamsLinkingOneProjectYieldOneRowUnderTheFirstTeamByName(): void {
        // `th_opl_proj_uq` forbids this since 4.9.4; the rule is exercised
        // anyway so a bypassed constraint cannot produce two rows.
        $wp = self::workPackageResponse(1, ['dueDate' => '2026-09-13']);
        $p = $this->provider(
            ['team-b' => ['projectId' => 12], 'team-a' => ['projectId' => 12]],
            $this->answering([self::KIND_DATED => [$wp]]),
        );

        $page = $p->fetchItems($this->query(['team-b', 'team-a']));

        $this->assertCount(1, $page->items);
        $this->assertSame('team-a', $page->items[0]->teamId, 'Team A sorts before Team B');
        $this->assertSame(['team-b'], $page->items[0]->metadata['additionalTeamIds']);
    }

    public function testProjectsAcrossTeamsAreEachReadOnceAndKeyedApart(): void {
        $p = $this->provider(
            ['team-a' => ['projectId' => 12], 'team-b' => ['projectId' => 13]],
            function (string $endpoint, array $params): array {
                if ($this->readKind([$endpoint, $params]) !== self::KIND_DATED) {
                    return self::collectionResponse([], 0);
                }
                // The same work-package id in two projects is two rows.
                $project = str_contains($endpoint, '/12/') ? 12 : 13;
                return self::collectionResponse([self::workPackageResponse(1, [
                    'dueDate' => '2026-09-13',
                    '_links' => ['project' => ['href' => '/api/v3/projects/' . $project, 'title' => 'P' . $project]],
                ])], 1);
            },
        );

        $rows = $this->byId($p->fetchItems($this->query(['team-a', 'team-b'])));

        $this->assertCount(2, $rows);
        $this->assertSame('12', $rows['team-a/1']->metadata['projectId']);
        $this->assertSame('13', $rows['team-b/1']->metadata['projectId']);
        $this->assertSame('Project 12', $rows['team-a/1']->metadata['projectName']);
        $this->assertStringEndsWith('Project 12', $rows['team-a/1']->subtitle);
    }

    // ── Partial results, failures, budget ──────────────────────────────

    public function testOneTeamsFailureDoesNotEmptyTheOthersAndIsReportedAsPartial(): void {
        $p = $this->provider(
            ['team-a' => ['projectId' => 12], 'team-b' => ['projectId' => 13]],
            function (string $endpoint, array $params): array {
                if (str_contains($endpoint, '/13/')) {
                    return ['error' => 'Forbidden', 'statusCode' => 403, 'message' => 'no'];
                }
                if ($this->readKind([$endpoint, $params]) === self::KIND_DATED) {
                    return self::collectionResponse([self::workPackageResponse(1, ['dueDate' => '2026-09-13'])], 1);
                }
                return self::collectionResponse([], 0);
            },
        );

        $page = $p->fetchItems($this->query(['team-a', 'team-b']));

        $this->assertCount(1, $page->items);
        $this->assertSame('team-a', $page->items[0]->teamId);
        $this->assertSame([WorkItemPage::WARN_PARTIAL], $page->warnings);
        $health = $this->health->describe(OpenProjectSyncHealth::CHANNEL_MY_WORK);
        $this->assertSame(1, $health['counters']['projectsCovered']);
        $this->assertSame(1, $health['counters']['projectsSkipped']);
        $this->assertGreaterThanOrEqual(1, $health['counters']['permissionProblems']);
        $this->assertSame('permission_denied', $health['lastErrorCode']);
    }

    public function testOneFailingReadOfAProjectKeepsTheProjectsOtherReads(): void {
        $p = $this->provider(['team-a' => ['projectId' => 12]], function (string $endpoint, array $params): array {
            $kind = $this->readKind([$endpoint, $params]);
            if ($kind === self::KIND_MILESTONE) {
                return ['error' => 'Bad request', 'statusCode' => 400, 'message' => 'no'];
            }
            if ($kind === self::KIND_DATED) {
                return self::collectionResponse([self::workPackageResponse(1, ['dueDate' => '2026-09-13'])], 1);
            }
            return self::collectionResponse([], 0);
        });

        $page = $p->fetchItems($this->query());

        $this->assertCount(1, $page->items);
        $this->assertSame([WorkItemPage::WARN_PARTIAL], $page->warnings);
    }

    public function testATimeoutIsATemporaryFailureAndTheRestStillRenders(): void {
        $p = $this->provider(
            ['team-a' => ['projectId' => 12], 'team-b' => ['projectId' => 13]],
            function (string $endpoint, array $params): array {
                if (str_contains($endpoint, '/12/')) {
                    // What the official app returns when the HTTP client gives up.
                    return ['error' => 'cURL error 28: Operation timed out', 'statusCode' => 500];
                }
                if ($this->readKind([$endpoint, $params]) === self::KIND_DATED) {
                    return self::collectionResponse([self::workPackageResponse(2, ['dueDate' => '2026-09-13'])], 1);
                }
                return self::collectionResponse([], 0);
            },
        );

        $page = $p->fetchItems($this->query(['team-a', 'team-b']));

        $this->assertCount(1, $page->items);
        $this->assertSame('team-b', $page->items[0]->teamId);
        $this->assertGreaterThanOrEqual(1, $this->health->describe('mywork')['counters']['timeouts']);
    }

    public function testARefusedTokenIsAnAuthWarningAndStopsFurtherProjects(): void {
        $p = $this->provider(
            ['team-a' => ['projectId' => 12], 'team-b' => ['projectId' => 13]],
            fn () => ['error' => 'Unauthorized', 'statusCode' => 401, 'message' => 'no'],
        );

        $page = $p->fetchItems($this->query(['team-a', 'team-b']));

        $this->assertSame([], $page->items);
        $this->assertContains(WorkItemPage::WARN_AUTH_REQUIRED, $page->warnings);
        foreach ($this->requests as $r) {
            $this->assertStringNotContainsString('/13/', $r[0], 'the second project is never asked');
        }
    }

    public function testAtMostTwelveProjectsAreReadPerFetch(): void {
        $linked = [];
        for ($i = 1; $i <= 14; $i++) {
            $linked['team-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT)] = ['projectId' => 100 + $i];
        }
        $p = $this->provider($linked);
        $query = new WorkQuery(userId: 'alice', teamIds: array_keys($linked), now: self::NOW);

        $page = $p->fetchItems($query);

        $projects = [];
        foreach ($this->requests as $r) {
            if (preg_match('#^projects/(\d+)/work_packages#', $r[0], $m)) {
                $projects[$m[1]] = true;
            }
        }
        $this->assertCount(OpenProjectWorkProvider::PROJECT_CAP, $projects);
        $this->assertTrue($page->truncated);
    }

    public function testTheProviderCapKeepsTheMostUrgentRows(): void {
        $elements = [];
        for ($i = 1; $i <= 6; $i++) {
            $elements[] = self::workPackageResponse($i, ['dueDate' => $i <= 2 ? '2026-09-01' : '2026-09-15']);
        }
        $p = $this->provider(['team-a' => ['projectId' => 12]], $this->answering([self::KIND_DATED => $elements]));
        $query = new WorkQuery(userId: 'alice', teamIds: ['team-a'], now: self::NOW, perProviderCap: 3);

        $page = $p->fetchItems($query);

        $this->assertCount(3, $page->items);
        $this->assertTrue($page->truncated);
        $this->assertSame(Category::ACTION_REQUIRED, $page->items[0]->category);
        $this->assertSame(Category::ACTION_REQUIRED, $page->items[1]->category);
    }

    // ── Sanitising + links ─────────────────────────────────────────────

    public function testHtmlInOpenProjectFieldsNeverReachesARow(): void {
        $p = $this->provider(['team-a' => ['projectId' => 12]], $this->answering([
            self::KIND_DATED => [self::workPackageResponse(1, [
                'dueDate' => '2026-09-13',
                'subject' => 'Fix <script>alert(1)</script> [link](javascript:alert(2)) login',
                '_links'  => [
                    'status'   => ['href' => '/api/v3/statuses/7', 'title' => '<b>In progress</b>'],
                    'type'     => ['href' => '/api/v3/types/1', 'title' => 'Task<img src=x onerror=alert(1)>'],
                    'assignee' => ['href' => '/api/v3/users/3', 'title' => '<i>Alice</i>'],
                ],
            ])],
        ]));

        $row = $p->fetchItems($this->query())->items[0];

        $this->assertSame('Fix alert(1) link login', $row->title);
        $this->assertStringNotContainsString('<', $row->subtitle);
        $this->assertStringNotContainsString('<', json_encode($row->metadata));
        $this->assertStringNotContainsString('javascript:', json_encode($row->toArray()));
    }

    public function testARowIsAHandOffToOpenProjectOnTheConfiguredHost(): void {
        $p = $this->provider(['team-a' => ['projectId' => 12]], $this->answering([
            self::KIND_DATED => [self::workPackageResponse(1, ['dueDate' => '2026-09-13'])],
        ]));

        $row = $p->fetchItems($this->query())->items[0];

        $this->assertSame(OpenTarget::external(), $row->openTarget);
        $this->assertSame(self::HOST . '/work_packages/1', $row->resourceUrl);
        $this->assertSame(self::HOST . '/projects/p12', $row->metadata['projectUrl']);
        $this->assertTrue($row->metadata['opensInOpenProject']);
        $this->assertSame('OpenProject', $row->metadata['opensIn']);
        $this->assertSame([ActionType::OPEN], $p->getAvailableActions('alice', $row));
    }

    // ── Cache isolation + permission loss ──────────────────────────────

    public function testTwoViewersNeverShareAnAnswer(): void {
        $p = $this->provider(['team-a' => ['projectId' => 12]], function (string $endpoint, array $params, string $uid): array {
            if ($this->readKind([$endpoint, $params]) !== self::KIND_DATED) {
                return self::collectionResponse([], 0);
            }
            return self::collectionResponse([self::workPackageResponse($uid === 'alice' ? 1 : 2, ['dueDate' => '2026-09-13'])], 1);
        });

        $alice = $p->fetchItems(new WorkQuery(userId: 'alice', teamIds: ['team-a'], now: self::NOW));
        $bob   = $p->fetchItems(new WorkQuery(userId: 'bob', teamIds: ['team-a'], now: self::NOW));
        $aliceAgain = $p->fetchItems(new WorkQuery(userId: 'alice', teamIds: ['team-a'], now: self::NOW));

        $this->assertSame('team-a/1', $alice->items[0]->providerItemId);
        $this->assertSame('team-a/2', $bob->items[0]->providerItemId);
        $this->assertSame('team-a/1', $aliceAgain->items[0]->providerItemId);
        $users = array_unique(array_map(static fn (array $r) => $r[3], $this->requests));
        $this->assertSame(['alice', 'bob'], array_values($users));
    }

    public function testAccessLostAfterCachingIsCaughtByTheReReadBeforeAnyAction(): void {
        $forbidden = false;
        $p = $this->provider(['team-a' => ['projectId' => 12]], function (string $endpoint, array $params) use (&$forbidden): array {
            if ($forbidden) {
                return ['error' => 'Forbidden', 'statusCode' => 403, 'message' => 'no'];
            }
            if ($endpoint === 'work_packages/1') {
                return self::workPackageResponse(1, ['dueDate' => '2026-09-13']);
            }
            if ($this->readKind([$endpoint, $params]) === self::KIND_DATED) {
                return self::collectionResponse([self::workPackageResponse(1, ['dueDate' => '2026-09-13'])], 1);
            }
            return self::collectionResponse([], 0);
        });

        $this->assertCount(1, $p->fetchItems($this->query())->items, 'listed while access holds');
        $this->assertNotNull($p->getItem('alice', 'team-a/1', ['team-a']));

        // Access is withdrawn in OpenProject. The list is cached for two
        // minutes; the action path is not.
        $forbidden = true;
        $this->assertNull($p->getItem('alice', 'team-a/1', ['team-a']), 'a snooze on a row the viewer lost is refused');
    }

    // ── getItem ────────────────────────────────────────────────────────

    public function testGetItemRefusesATeamTheCallerMayNotSeeWithoutARequest(): void {
        $p = $this->provider(['team-a' => ['projectId' => 12]]);
        $this->assertNull($p->getItem('alice', 'team-a/1', ['team-b']));
        $this->assertNull($p->getItem('alice', 'garbage', ['team-a']));
        $this->assertSame([], $this->requests);
    }

    public function testGetItemReReadsFromSourceAndChecksTheProject(): void {
        $p = $this->provider(['team-a' => ['projectId' => 12]], function (string $endpoint): array {
            if ($endpoint === 'work_packages/1') {
                return self::workPackageResponse(1, ['dueDate' => '2030-12-01']);
            }
            if ($endpoint === 'work_packages/2') {
                return self::workPackageResponse(2, ['_links' => ['project' => ['href' => '/api/v3/projects/99', 'title' => 'Other']]]);
            }
            if ($endpoint === 'work_packages/3') {
                return ['error' => 'Not found', 'statusCode' => 404, 'message' => 'gone'];
            }
            if ($endpoint === 'work_packages/4') {
                return self::workPackageResponse(4, ['_links' => ['status' => ['href' => '/api/v3/statuses/13', 'title' => 'Closed']]]);
            }
            return self::collectionResponse([], 0);
        });

        $item = $p->getItem('alice', 'team-a/1', ['team-a']);
        $this->assertNotNull($item, 'due beyond the horizon still re-reads: a snoozed row may be');
        $this->assertSame('team-a/1', $item->providerItemId);
        $this->assertSame(Category::UPCOMING, $item->category);

        $this->assertNull($p->getItem('alice', 'team-a/2', ['team-a']), 'moved to another project');
        $this->assertNull($p->getItem('alice', 'team-a/3', ['team-a']), 'deleted or no longer visible');

        $closed = $p->getItem('alice', 'team-a/4', ['team-a']);
        $this->assertSame(Category::COMPLETED, $closed->category, 'closed since it was listed');
    }

    public function testNothingIsChangedInOpenProject(): void {
        $p = $this->provider(['team-a' => ['projectId' => 12]], $this->answering([
            self::KIND_DATED => [self::workPackageResponse(1, ['dueDate' => '2026-09-13'])],
        ]));
        $row = $p->fetchItems($this->query())->items[0];

        $result = $p->executeAction('alice', $row, ActionType::COMPLETE, []);

        $this->assertFalse($result->ok);
        foreach ($this->requests as $r) {
            $this->assertSame('GET', $r[2]);
        }
    }

    // ── Attention summary ──────────────────────────────────────────────

    public function testAttentionSummaryCountsTheViewersWorkForOneTeam(): void {
        // The summary runs on the real clock (it is a widget, not a query
        // with an injected "now"), so every date here is relative to today.
        $now   = time();
        $day   = static fn (int $offset) => gmdate('Y-m-d', $now + $offset * 86400);
        $stamp = static fn (int $offset) => gmdate('Y-m-d\TH:i:s\Z', $now + $offset);
        $p = $this->provider(['team-a' => ['projectId' => 12]], $this->answering([
            self::KIND_DATED => [
                self::workPackageResponse(1, ['dueDate' => $day(-5)]),
                self::workPackageResponse(2, ['dueDate' => $day(0)]),
                self::workPackageResponse(3, ['dueDate' => $day(3)]),
            ],
            self::KIND_RECENT => [
                self::workPackageResponse(4, ['dueDate' => null, 'updatedAt' => $stamp(-3600), 'createdAt' => $stamp(-40 * 86400)]),
            ],
            self::KIND_MILESTONE => [
                self::workPackageResponse(20, ['dueDate' => $day(6), 'date' => $day(6), '_links' => ['type' => ['href' => '/api/v3/types/2', 'title' => 'Milestone']]]),
                self::workPackageResponse(21, ['dueDate' => $day(2), 'date' => $day(2), '_links' => ['type' => ['href' => '/api/v3/types/2', 'title' => 'Milestone']]]),
            ],
        ]), lastSeen: $now - 600);

        $s = $p->attentionSummary('alice', 'team-a', 'Team A');

        $this->assertSame(1, $s['overdue']);
        $this->assertSame(1, $s['dueToday']);
        $this->assertSame(2, $s['dueThisWeek']);
        $this->assertSame(1, $s['updatedRecently']);
        $this->assertSame(21, $s['nextMilestone']['id']);
        $this->assertSame($day(2), $s['nextMilestone']['date']);
        $this->assertStringStartsWith(self::HOST . '/projects/p12/work_packages?query_props=', $s['urls']['myWorkPackages']);
        $this->assertSame([], $s['warnings']);
        // A widget read is not a visit: the checkpoint stays.
        $this->assertSame((string)($now - 600), $this->userValues['alice']['teamhub']['mywork_openproject_last_seen']);
    }
}
