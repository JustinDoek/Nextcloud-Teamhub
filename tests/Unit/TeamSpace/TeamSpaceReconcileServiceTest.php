<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\TeamSpace;

use OCA\TeamHub\Db\TeamAppResource;
use OCA\TeamHub\Db\TeamAppResourceMapper;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\GroupFolderService;
use OCA\TeamHub\Service\LicenseService;
use OCA\TeamHub\Service\TeamSpaceReconcileService;
use OCA\TeamHub\Service\TeamSpaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The reconcile rules (v4.10.1) against an in-memory folder estate.
 *
 * The three database lookups the service makes itself (team names, file
 * names, "is the space empty") are overridden on a subclass; everything else
 * goes through the service's collaborators, scripted here to behave like the
 * real ones. The fake provider is the same one TeamSpaceServiceTest uses.
 */
class TeamSpaceReconcileServiceTest extends TestCase {

    private FakeTeamFolderProvider $provider;
    /** @var array<string,string> */
    private array $teamNames = [];
    /** @var array<int,bool> folder id → empty? (default true) */
    private array $emptySpaces = [];
    /** @var TeamAppResource[] */
    private array $rows = [];
    private int $nextRowId = 1;
    /** @var array<int, array{team: string, event: string, meta: ?array}> */
    private array $audit = [];
    /** @var array<int, array{user: string, subject: string, params: array}> */
    private array $notified = [];
    /** @var string[] object ids of withdrawn notifications */
    private array $withdrawn = [];
    /** @var array<string,string> */
    private array $appConfig = [];
    /** @var string[] My Work cache nonce keys bumped */
    private array $bumped = [];
    private bool $available = true;

    protected function setUp(): void {
        if (!interface_exists(\OCP\Teams\ITeamFolderProvider::class)) {
            $this->markTestSkipped('Nextcloud 35 API not present');
        }
        $this->provider = new FakeTeamFolderProvider();
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    private function row(string $team, string $resource, string $status = 'active', int $createdAt = 1000): TeamAppResource {
        $r = new TeamAppResource();
        $r->setId($this->nextRowId++);
        $r->setTeamId($team);
        $r->setAppId('files');
        $r->setResourceId($resource);
        $r->setStatus($status);
        $r->setOrigin('teamhub_create');
        $r->setCreatedAt($createdAt);
        $r->setUpdatedAt($createdAt);
        $this->rows[] = $r;
        return $r;
    }

    private function rowsOf(string $team): array {
        $out = [];
        foreach ($this->rows as $r) {
            if ($r->getTeamId() === $team) {
                $out[] = $r->getResourceId() . '=' . $r->getStatus();
            }
        }
        sort($out);
        return $out;
    }

    private function service(): TeamSpaceReconcileService {
        $provider = $this->provider;

        // TeamSpaceService: a thin adapter over the fake provider.
        $spaces = $this->createMock(TeamSpaceService::class);
        $spaces->method('isAvailable')->willReturnCallback(fn (): bool => $this->available);
        $spaces->method('getTeamSpace')->willReturnCallback(function (string $teamId) use ($provider): ?array {
            $f = $provider->getTeamFolder($teamId);
            return $f === null ? null : ['id' => $f->getId(), 'mount_point' => $f->getMountPoint(), 'quota' => $f->getQuota()];
        });
        $spaces->method('spaceOwnerCircleId')->willReturnCallback(fn (int $id): ?string => $provider->folders[$id]['team'] ?? null);
        $spaces->method('isLinkable')->willReturnCallback(function (string $teamId, int $folderId) use ($provider): bool {
            foreach ($provider->getLinkableTeamFolders($teamId) as $f) {
                if ($f->getId() === $folderId) {
                    return true;
                }
            }
            return false;
        });
        $spaces->method('linkTeamSpace')->willReturnCallback(function (string $teamId, int $folderId) use ($provider): array {
            $f = $provider->linkTeamFolder($teamId, $folderId);
            return ['id' => $f->getId(), 'mount_point' => $f->getMountPoint(), 'quota' => $f->getQuota()];
        });
        $spaces->method('createTeamSpace')->willReturnCallback(function (string $teamId, string $name) use ($provider): array {
            $f = $provider->createTeamFolder(new \OCP\Teams\Team($teamId, $name, null), 0);
            return ['id' => $f->getId(), 'mount_point' => $f->getMountPoint(), 'quota' => $f->getQuota()];
        });
        $spaces->method('removeTeamSpace')->willReturnCallback(fn (string $teamId): bool => $provider->removeTeamFolder($teamId));
        $spaces->method('unlinkTeamSpace')->willReturnCallback(function (string $teamId) use ($provider): ?array {
            $f = $provider->unlinkTeamFolder($teamId);
            return $f === null ? null : ['id' => $f->getId(), 'mount_point' => $f->getMountPoint(), 'quota' => $f->getQuota()];
        });

        // GroupFolderService: applicable list and removals on the fake estate.
        $gf = $this->createMock(GroupFolderService::class);
        $gf->method('listApplicable')->willReturnCallback(function (int $folderId) use ($provider): array {
            $out = [];
            foreach ($provider->applicable[$folderId] ?? [] as $id) {
                $out[] = ['id' => $id, 'kind' => str_starts_with($id, 'grp:') ? 'group' : 'circle'];
            }
            return $out;
        });
        $gf->method('removeApplicable')->willReturnCallback(function (int $folderId, string $id) use ($provider): void {
            $provider->applicable[$folderId] = array_values(array_diff($provider->applicable[$folderId] ?? [], [$id]));
        });
        $gf->method('resolveGroupFolderResourceId')->willReturnCallback(function (string $rid) use ($provider): ?array {
            $id = (int)substr($rid, 3);
            return isset($provider->folders[$id]) ? ['folder_id' => $id, 'mount_point' => $provider->folders[$id]['mount_point']] : null;
        });

        // The resource rows, in memory.
        $mapper = $this->createMock(TeamAppResourceMapper::class);
        $mapper->method('findAllByApp')->willReturnCallback(function (string $app, array $statuses): array {
            $out = array_filter($this->rows, static fn (TeamAppResource $r): bool => in_array($r->getStatus(), $statuses, true));
            usort($out, static fn (TeamAppResource $a, TeamAppResource $b): int => [$a->getCreatedAt(), $a->getId()] <=> [$b->getCreatedAt(), $b->getId()]);
            return array_values($out);
        });
        $mapper->method('updateStatus')->willReturnCallback(function (int $id, string $status): void {
            foreach ($this->rows as $r) {
                if ($r->getId() === $id) {
                    $r->setStatus($status);
                }
            }
        });
        $mapper->method('findByTeamAppResource')->willReturnCallback(function (string $team, string $app, string $rid): ?TeamAppResource {
            foreach ($this->rows as $r) {
                if ($r->getTeamId() === $team && $r->getResourceId() === $rid) {
                    return $r;
                }
            }
            return null;
        });
        $mapper->method('insertResource')->willReturnCallback(
            fn (string $teamId, string $appId, string $resourceId, string $origin, string $status = 'active'): TeamAppResource
                => $this->row($teamId, $resourceId, $status, 5000)
        );

        $audit = $this->createMock(AuditService::class);
        $audit->method('log')->willReturnCallback(function (string $team, string $event, ?string $actor, ?string $tt = null, ?string $ti = null, ?array $meta = null): void {
            $this->audit[] = ['team' => $team, 'event' => $event, 'meta' => $meta];
        });

        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');
        $adminGroup = $this->createMock(IGroup::class);
        $adminGroup->method('getUsers')->willReturn([$alice, $bob]);
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('get')->willReturnCallback(fn (string $gid) => $gid === 'admin' ? $adminGroup : null);

        $notifications = $this->createMock(INotificationManager::class);
        $notifications->method('createNotification')->willReturnCallback(function (): INotification {
            $n = $this->createMock(INotification::class);
            $state = ['user' => '', 'subject' => '', 'params' => [], 'object' => ''];
            $n->method('setApp')->willReturnSelf();
            $n->method('setDateTime')->willReturnSelf();
            $n->method('setUser')->willReturnCallback(function (string $u) use ($n, &$state) { $state['user'] = $u; return $n; });
            $n->method('setObject')->willReturnCallback(function (string $t, string $id) use ($n, &$state) { $state['object'] = $id; return $n; });
            $n->method('setSubject')->willReturnCallback(function (string $s, array $p = []) use ($n, &$state) { $state['subject'] = $s; $state['params'] = $p; return $n; });
            $n->method('getUser')->willReturnCallback(function () use (&$state) { return $state['user']; });
            $n->method('getSubject')->willReturnCallback(function () use (&$state) { return $state['subject']; });
            $n->method('getSubjectParameters')->willReturnCallback(function () use (&$state) { return $state['params']; });
            $n->method('getObjectId')->willReturnCallback(function () use (&$state) { return $state['object']; });
            return $n;
        });
        $notifications->method('notify')->willReturnCallback(function (INotification $n): void {
            $this->notified[] = ['user' => $n->getUser(), 'subject' => $n->getSubject(), 'params' => $n->getSubjectParameters()];
        });
        $notifications->method('markProcessed')->willReturnCallback(function (INotification $n): void {
            $this->withdrawn[] = $n->getObjectId();
        });

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->appConfig[$key] ?? $default);
        $config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
            $this->appConfig[$key] = $value;
        });

        $license = $this->createMock(LicenseService::class);
        $license->method('getEnforcementLevel')->willReturn('none');

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(2_000_000);

        // The My Work nonce bumps — what the hand-over invalidates for whom.
        $cache = $this->createMock(ICache::class);
        $cache->method('set')->willReturnCallback(function (string $key): bool {
            $this->bumped[] = $key;
            return true;
        });
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        $test = $this;
        return new class(
            $spaces, $gf, $mapper, $audit, $this->createMock(IDBConnection::class), $groups,
            $notifications, $config, $license, $time, $cacheFactory, $this->createMock(LoggerInterface::class), $test
        ) extends TeamSpaceReconcileService {
            public function __construct(...$args) {
                $this->test = array_pop($args);
                parent::__construct(...$args);
            }
            private TeamSpaceReconcileServiceTest $test;
            protected function teamNames(array $teamIds): array {
                return array_intersect_key($this->test->names(), array_flip($teamIds));
            }
            protected function groupDisplayName(string $gid): string {
                return 'Group ' . substr($gid, 4);
            }
            protected function fileName(int $fileId): ?string {
                return 'Shared folder ' . $fileId;
            }
            protected function spaceIsEmpty(int $folderId): bool {
                return $this->test->spaceEmpty($folderId);
            }
        };
    }

    /** @return array<string,string> */
    public function names(): array {
        return $this->teamNames;
    }

    public function spaceEmpty(int $folderId): bool {
        return $this->emptySpaces[$folderId] ?? true;
    }

    private function events(string $event): array {
        return array_values(array_filter($this->audit, static fn (array $a): bool => $a['event'] === $event));
    }

    // ── tests ─────────────────────────────────────────────────────────────

    public function testSkipsEntirelyWithoutAProvider(): void {
        $this->available = false;
        $this->teamNames = ['t1' => 'One'];
        $this->row('t1', 'gf:1');
        $summary = $this->service()->reconcile();
        $this->assertTrue($summary['skipped']);
        $this->assertSame([], $this->audit);
    }

    public function testAnExclusiveFolderIsLinkedAndNothingElseHappens(): void {
        $this->teamNames = ['t1' => 'One'];
        $id = $this->provider->addFolder('One', ['t1']);
        $this->row('t1', "gf:$id");

        $summary = $this->service()->reconcile();

        $this->assertSame(1, $summary['linked']);
        $this->assertSame('t1', $this->provider->folders[$id]['team'], 'now the team\'s space');
        $this->assertCount(1, $this->events(TeamSpaceReconcileService::AUDIT_LINKED));
        $this->assertSame([], $this->notified, 'nothing to report');
        $this->assertSame(["gf:$id=active"], $this->rowsOf('t1'), 'the row is untouched');
    }

    public function testAFolderTheTeamCanNoLongerOpenIsLeftToDiscovery(): void {
        $this->teamNames = ['t1' => 'One'];
        $id = $this->provider->addFolder('Lost', []);   // circle no longer applicable
        $this->row('t1', "gf:$id");
        $summary = $this->service()->reconcile();
        $this->assertSame(0, $summary['linked']);
        $this->assertSame(0, $summary['failures'], 'a stale row is not a failure of this pass');
        $this->assertNull($this->provider->folders[$id]['team']);
        $this->assertSame(["gf:$id=active"], $this->rowsOf('t1'), 'the row is discovery\'s to move');
    }

    public function testRowsOfDeletedTeamsAreLeftAlone(): void {
        $this->teamNames = [];
        $id = $this->provider->addFolder('Gone', ['ghost']);
        $this->row('ghost', "gf:$id");
        $summary = $this->service()->reconcile();
        $this->assertSame(0, $summary['linked']);
        $this->assertNull($this->provider->folders[$id]['team']);
    }

    public function testSharesWithOthersAreRemovedThenLinkedAndReported(): void {
        $this->teamNames = ['t1' => 'One', 'ext' => 'External team'];
        $id = $this->provider->addFolder('One', ['t1', 'grp:admin', 'ext']);
        $this->row('t1', "gf:$id");

        $summary = $this->service()->reconcile();

        $this->assertSame(2, $summary['shares_removed']);
        $this->assertSame(1, $summary['linked']);
        $this->assertSame(['t1'], $this->provider->applicable[$id], 'for the team only');
        $this->assertSame('t1', $this->provider->folders[$id]['team']);

        $report = $this->events(TeamSpaceReconcileService::AUDIT_SHARES_REMOVED);
        $this->assertCount(1, $report);
        $this->assertSame(
            [['kind' => 'group', 'id' => 'grp:admin', 'name' => 'Group admin'], ['kind' => 'circle', 'id' => 'ext', 'name' => 'External team']],
            $report[0]['meta']['removed'],
        );
        $this->assertSame([], $report[0]['meta']['disconnected_teams'], 'ext holds no TeamHub row on this folder');

        $this->assertCount(2, $this->notified, 'one notification per administrator');
        $this->assertSame(TeamSpaceReconcileService::NOTIFY_SHARES_REMOVED, $this->notified[0]['subject']);
        $this->assertSame('Group admin, External team', $this->notified[0]['params']['removed']);
        $this->assertSame(1, $this->notified[0]['params']['licensed']);
    }

    public function testTwoTeamsOnOneFolderTheOldestConnectionKeepsIt(): void {
        $this->teamNames = ['old' => 'Old', 'new' => 'New'];
        $id = $this->provider->addFolder('Shared dept folder', ['old', 'new']);
        $this->row('new', "gf:$id", 'active', 2000);
        $this->row('old', "gf:$id", 'active', 1000);

        $summary = $this->service()->reconcile();

        $this->assertSame('old', $this->provider->folders[$id]['team'], 'oldest connection keeps it');
        $this->assertSame(1, $summary['teams_disconnected']);
        $this->assertSame(1, $summary['spaces_created'], 'the loser gets an empty space of its own');
        $this->assertSame(["gf:$id=disconnected", 'gf:101=active'], $this->rowsOf('new'));
        $this->assertSame('New', $this->provider->folders[101]['mount_point']);
        $this->assertSame('new', $this->provider->folders[101]['team']);

        $disconnected = $this->events(TeamSpaceReconcileService::AUDIT_DISCONNECTED);
        $this->assertCount(1, $disconnected);
        $this->assertSame('new', $disconnected[0]['team']);
        $this->assertSame('Old', $disconnected[0]['meta']['kept_by_name']);
        $created = $this->events(TeamSpaceReconcileService::AUDIT_CREATED);
        $this->assertSame('lost_folder', $created[0]['meta']['reason']);

        $report = $this->events(TeamSpaceReconcileService::AUDIT_SHARES_REMOVED);
        $this->assertSame(['New'], $report[0]['meta']['disconnected_teams']);
    }

    public function testASharedFolderTeamGetsAPendingSpaceAndOneNotification(): void {
        $this->teamNames = ['t1' => 'Sales'];
        $this->row('t1', '4711');

        $s = $this->service();
        $summary = $s->reconcile();

        $this->assertSame(1, $summary['spaces_created']);
        $this->assertSame('Sales', $this->provider->folders[100]['mount_point']);
        $this->assertSame(['4711=active', 'gf:100=pending'], $this->rowsOf('t1'), 'pending: the dual-folder row Manage team shows');
        $this->assertCount(2, $this->notified);
        $this->assertSame(TeamSpaceReconcileService::NOTIFY_SHARED_FOLDER, $this->notified[0]['subject']);
        $this->assertSame('Shared folder 4711', $this->notified[0]['params']['sharedFolder']);
        $this->assertSame('Sales', $this->notified[0]['params']['spaceName']);

        // The provider's facts for the My Work row.
        $teams = $s->listSharedFolderTeams();
        $this->assertCount(1, $teams);
        $this->assertSame('Sales', $teams[0]['spaceName']);
        $this->assertFalse($teams[0]['spaceConnected']);

        // Second run: nothing new, nobody notified twice.
        $again = $s->reconcile();
        $this->assertSame(0, $again['spaces_created']);
        $this->assertCount(2, $this->notified);

        // The owner finishes: shared folder disconnected, space connected.
        foreach ($this->rows as $r) {
            if ($r->getResourceId() === '4711') {
                $r->setStatus('disconnected');
            }
            if ($r->getResourceId() === 'gf:100') {
                $r->setStatus('active');
            }
        }
        $done = $s->reconcile();
        $this->assertSame(1, $done['notices_withdrawn']);
        $this->assertSame(['t1'], $this->withdrawn, 'the notifications go with it');
        $this->assertSame([], $s->listSharedFolderTeams(), 'and so does the My Work row');
    }

    public function testAnEmptyAutoCreatedSpaceNextToTheTeamFolderIsRemoved(): void {
        $this->teamNames = ['t1' => 'One'];
        $auto = $this->provider->addFolder('One', ['t1'], 't1');          // Circles' auto-created, empty
        $mine = $this->provider->addFolder('One', ['t1']);                // TeamHub's, with the content
        $this->row('t1', "gf:$mine");
        $this->emptySpaces[$auto] = true;

        $summary = $this->service()->reconcile();

        $this->assertSame(1, $summary['duplicates_removed']);
        $this->assertArrayNotHasKey($auto, $this->provider->folders);
        $this->assertSame('t1', $this->provider->folders[$mine]['team'], 'TeamHub\'s folder is the space');
        $this->assertSame(1, $summary['linked']);
        $this->assertSame([], $this->notified);
    }

    public function testTwoFoldersWithContentIsAConflictNobodyMerges(): void {
        $this->teamNames = ['t1' => 'One'];
        $space = $this->provider->addFolder('One', ['t1'], 't1');
        $mine  = $this->provider->addFolder('One (1)', ['t1']);
        $this->row('t1', "gf:$mine");
        $this->emptySpaces[$space] = false;

        $s = $this->service();
        $summary = $s->reconcile();

        $this->assertSame(1, $summary['conflicts']);
        $this->assertSame(0, $summary['linked']);
        $this->assertArrayHasKey($space, $this->provider->folders, 'nothing deleted');
        $this->assertNull($this->provider->folders[$mine]['team']);
        $this->assertSame(TeamSpaceReconcileService::NOTIFY_CONFLICT, $this->notified[0]['subject']);
        $this->assertCount(1, $s->listConflicts());
        $this->assertSame('One (1)', $s->listConflicts()[0]['folder']);

        // Reported once, however often it runs.
        $s->reconcile();
        $this->assertCount(2, $this->notified);
    }

    public function testTheHandOverWalksAssignedDoneClosedAndTellsTheRightPeople(): void {
        $this->teamNames = ['t1' => 'Sales'];
        $this->row('t1', '4711');
        $s = $this->service();
        $s->reconcile();                                   // the space, the pending row, the admins' notice
        $this->notified = [];
        $this->withdrawn = [];
        $this->bumped = [];

        $this->assertNull($s->getTask('t1'));
        $this->assertSame([], $s->listAssignedTasks(['t1']));

        // Lieke hands it to Inge.
        $task = $s->assignTask('t1', 'lieke', 'Lieke Adm', ['uid' => 'inge', 'displayName' => 'Inge NC'], '  Before Friday  ');
        $this->assertSame(TeamSpaceReconcileService::TASK_ASSIGNED, $task['status']);
        $this->assertSame('Before Friday', $task['note']);
        $this->assertSame(['t1'], $this->withdrawn, 'the administrators\' notices are withdrawn — acted on');
        $this->assertCount(1, $this->notified, 'the owner is told, nobody else');
        $this->assertSame('inge', $this->notified[0]['user']);
        $this->assertSame(TeamSpaceReconcileService::NOTIFY_TASK_ASSIGNED, $this->notified[0]['subject']);
        $this->assertSame('Lieke Adm', $this->notified[0]['params']['adminName']);
        $this->assertSame(['nonce_inge'], $this->bumped, 'the owner\'s My Work re-reads');
        $assigned = $this->events(TeamSpaceReconcileService::AUDIT_TASK_ASSIGNED);
        $this->assertCount(1, $assigned);

        $this->assertSame('t1', $s->listAssignedTasks(['t1', 'other'])[0]['teamId']);
        $this->assertSame([], $s->listAssignedTasks(['other']), 'scoped to the teams asked for');
        $this->assertSame('assigned', $s->listSharedFolderTeams()[0]['task']['status']);

        // Handing it over twice is refused.
        try {
            $s->assignTask('t1', 'lieke', 'Lieke Adm', ['uid' => 'inge', 'displayName' => 'Inge NC']);
            $this->fail('expected a refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already been handed', $e->getMessage());
        }

        // Inge reports done.
        $this->notified = [];
        $this->withdrawn = [];
        $this->bumped = [];
        $done = $s->completeTask('t1', 'inge', 'Inge NC');
        $this->assertSame(TeamSpaceReconcileService::TASK_DONE, $done['status']);
        $this->assertSame('inge', $done['completedBy']);
        $this->assertSame(['t1'], $this->withdrawn, 'the owner\'s notice goes');
        $this->assertCount(2, $this->notified, 'every administrator hears it');
        $this->assertSame(TeamSpaceReconcileService::NOTIFY_TASK_COMPLETED, $this->notified[0]['subject']);
        $this->assertSame('Inge NC', $this->notified[0]['params']['ownerName']);
        $this->assertSame(['nonce_alice', 'nonce_bob'], $this->bumped, 'the administrators\' My Work re-reads');
        $this->assertSame([], $s->listAssignedTasks(['t1']), 'no longer the owner\'s task');

        // Completing twice is refused; so is closing something never handed over.
        try {
            $s->completeTask('t1', 'inge', 'Inge NC');
            $this->fail('expected a refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not open', $e->getMessage());
        }

        // Lieke closes it.
        $this->notified = [];
        $this->bumped = [];
        $closed = $s->closeTask('t1', 'lieke');
        $this->assertSame(TeamSpaceReconcileService::TASK_CLOSED, $closed['status']);
        $this->assertSame('lieke', $closed['closedBy']);
        $this->assertSame([], $this->notified, 'closing tells nobody');
        $this->assertSame(['nonce_alice', 'nonce_bob', 'nonce_inge'], $this->bumped);
        $this->assertCount(1, $this->events(TeamSpaceReconcileService::AUDIT_TASK_CLOSED));

        // A daily run afterwards changes nothing and re-notifies nobody.
        $this->notified = [];
        $s->reconcile();
        $this->assertSame([], $this->notified);
        $this->assertSame('closed', $s->getTask('t1')['status']);

        // And the shared folder going away withdraws the whole thing.
        foreach ($this->rows as $r) {
            if ($r->getResourceId() === '4711') {
                $r->setStatus('disconnected');
            }
        }
        $s->reconcile();
        $this->assertNull($s->getTask('t1'));
    }

    public function testATaskCannotBeHandedOutForATeamNotOnASharedFolder(): void {
        $this->teamNames = ['t1' => 'One'];
        $s = $this->service();
        $this->expectException(\RuntimeException::class);
        $s->assignTask('t1', 'lieke', 'Lieke Adm', ['uid' => 'inge', 'displayName' => 'Inge NC']);
    }

    public function testASecondRunIsANoOp(): void {
        $this->teamNames = ['t1' => 'One', 't2' => 'Two'];
        $a = $this->provider->addFolder('One', ['t1', 'grp:x']);
        $this->row('t1', "gf:$a");
        $this->row('t2', '99');

        $s = $this->service();
        $s->reconcile();
        $second = $s->reconcile();

        foreach (['linked', 'shares_removed', 'teams_disconnected', 'spaces_created', 'duplicates_removed', 'conflicts', 'failures'] as $k) {
            $this->assertSame(0, $second[$k], $k);
        }
    }
}
