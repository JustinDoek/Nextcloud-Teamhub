<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\TeamAppResource;
use OCA\TeamHub\Db\TeamAppResourceMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * TeamSpaceReconcileService — every TeamHub team a Nextcloud 35 team space (v4.10.1).
 *
 * Nextcloud 35 makes a team's folder an exclusive *team space*; TeamHub's
 * existing teams have plain group folders, or older shared folders, from
 * before there was such a thing. This service brings the estate over,
 * folder by folder, without moving a single file — linking a group folder as
 * a space is metadata (`group_folders.team_circle_id`), and the one thing
 * that ever gets created is an *empty* space next to a shared folder.
 *
 * Why a job and not a migration: a migration runs once, when TeamHub is
 * upgraded, and the provider is not there during `occ upgrade` (AIO disables
 * every app before a core update); an instance that reaches 35 *after* this
 * TeamHub version would never convert. `TeamSpaceReconcileJob` runs this
 * daily, a repair step asks for a run at the next cron tick after an
 * upgrade, and every step below is idempotent — a second run finds nothing
 * to do.
 *
 * ## The rules (Justin, 2026-09-19)
 *
 *  1. **A team folder is for its team only.** A group folder shared with
 *     other groups or teams has those shares removed before it becomes the
 *     space, and the Nextcloud administrators get a report of what went.
 *  2. **When two TeamHub teams both hold a folder, the oldest connection
 *     keeps it.** The others are disconnected (row → `disconnected`, folder
 *     untouched) and get an empty space of their own on the same run.
 *  3. **A team still on a shared folder gets an empty space next to it**,
 *     filed as the pending team-folder row Manage team already knows how to
 *     show (the dual-folder notice), and the administrators get one
 *     notification per team plus — licensed — a My Work item with the
 *     procedure. The files are the owner's to move; nothing is copied.
 *  4. **A team with a space *and* a different plain folder** (a team made
 *     on 35 before TeamHub opted out of Circles' auto-creation): the empty
 *     auto-created space is removed and TeamHub's folder becomes the space.
 *     If the space is not empty the job leaves both and reports a conflict.
 *
 * Every decision is written to the audit log under `teamspace.*`, which is
 * also where the My Work report rows come from. Notifications are deduped
 * per team in app config (`teamspace_notices`) and withdrawn when the
 * condition clears.
 *
 * On Nextcloud 33/34 `reconcile()` returns immediately.
 *
 * Design: DESIGN.md §2.133.
 */
class TeamSpaceReconcileService {

    public const AUDIT_LINKED            = 'teamspace.linked';
    public const AUDIT_SHARES_REMOVED    = 'teamspace.shares_removed';
    public const AUDIT_DISCONNECTED      = 'teamspace.disconnected';
    public const AUDIT_CREATED           = 'teamspace.created';
    public const AUDIT_DUPLICATE_REMOVED = 'teamspace.duplicate_removed';
    public const AUDIT_CONFLICT          = 'teamspace.conflict';
    public const AUDIT_TASK_ASSIGNED     = 'teamspace.task_assigned';
    public const AUDIT_TASK_COMPLETED    = 'teamspace.task_completed';
    public const AUDIT_TASK_CLOSED       = 'teamspace.task_closed';

    /** Notification subjects (`Notifier.php` renders them). */
    public const NOTIFY_SHARED_FOLDER  = 'teamspace_shared_folder';
    public const NOTIFY_SHARES_REMOVED = 'teamspace_shares_removed';
    public const NOTIFY_CONFLICT       = 'teamspace_conflict';

    /** Notification object type, so a notice can be withdrawn per team. */
    public const NOTIFY_OBJECT = 'teamspace';

    /** App-config key: JSON `{ teamId: { kind: 'shared_folder'|'conflict', at: ts } }`. */
    private const NOTICES_KEY = 'teamspace_notices';

    /** The docs page an unlicensed instance's notification points at. */
    public const DOCS_URL = 'https://teamhub.doekworks.eu/docs/nextcloud-admin/team-folders#team-spaces-on-nextcloud-35';

    public function __construct(
        private TeamSpaceService      $teamSpaceService,
        private GroupFolderService    $groupFolderService,
        private TeamAppResourceMapper $resourceMapper,
        private AuditService          $auditService,
        private IDBConnection         $db,
        private IGroupManager         $groupManager,
        private INotificationManager  $notificationManager,
        private IConfig               $config,
        private LicenseService        $licenseService,
        private ITimeFactory          $timeFactory,
        private ICacheFactory         $cacheFactory,
        private LoggerInterface       $logger,
    ) {}

    // ──────────────────────────────────────────────────────────────────────
    // The run
    // ──────────────────────────────────────────────────────────────────────

    /**
     * One pass over the estate. Returns counters for the log and tests.
     *
     * @return array<string, int|bool>
     */
    public function reconcile(): array {
        $summary = [
            'skipped'            => false,
            'linked'             => 0,
            'shares_removed'     => 0,
            'teams_disconnected' => 0,
            'spaces_created'     => 0,
            'duplicates_removed' => 0,
            'conflicts'          => 0,
            'notices_withdrawn'  => 0,
            'failures'           => 0,
        ];

        if (!$this->teamSpaceService->isAvailable()) {
            $summary['skipped'] = true;
            return $summary;
        }

        $rows      = $this->resourceMapper->findAllByApp('files', ['active', 'pending']);
        $teamNames = $this->teamNames(array_values(array_unique(array_map(
            static fn (TeamAppResource $r): string => $r->getTeamId(), $rows
        ))));

        // Only teams that still exist. A row whose circle is gone is somebody
        // else's cleanup (TeamService::deleteTeam removes them); touching the
        // folder it names could hand a stranger's folder to nobody.
        $rows = array_values(array_filter($rows, static fn (TeamAppResource $r): bool => isset($teamNames[$r->getTeamId()])));

        /** @var array<int, TeamAppResource[]> $byFolder active gf rows per folder, oldest first */
        $byFolder = [];
        /** @var array<string, array{active_gf: TeamAppResource[], pending_gf: TeamAppResource[], active_shared: TeamAppResource[]}> $byTeam */
        $byTeam = [];
        foreach ($rows as $row) {
            $teamId = $row->getTeamId();
            $byTeam[$teamId] ??= ['active_gf' => [], 'pending_gf' => [], 'active_shared' => []];
            $isGf = str_starts_with($row->getResourceId(), 'gf:');
            if ($row->getStatus() === 'active' && $isGf) {
                $byTeam[$teamId]['active_gf'][] = $row;
                $byFolder[(int)substr($row->getResourceId(), 3)][] = $row;
            } elseif ($row->getStatus() === 'pending' && $isGf) {
                $byTeam[$teamId]['pending_gf'][] = $row;
            } elseif ($row->getStatus() === 'active') {
                $byTeam[$teamId]['active_shared'][] = $row;
            }
        }

        /** @var array<string, bool> teams that lost their folder this run and need a space */
        $needsSpace = [];
        /** @var array<int, bool> resource row ids disconnected this run */
        $disconnectedRowIds = [];

        // ── Phase 1: every group folder TeamHub holds ─────────────────────
        foreach ($byFolder as $folderId => $claims) {
            try {
                $this->reconcileFolder((int)$folderId, $claims, $teamNames, $needsSpace, $disconnectedRowIds, $summary);
            } catch (\Throwable $e) {
                $summary['failures']++;
                $this->logger->error('[TeamHub][TeamSpaceReconcile] folder pass failed', [
                    'folderId' => $folderId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        // ── Phase 2: teams that need a space ──────────────────────────────
        foreach ($byTeam as $teamId => $state) {
            try {
                $activeGf = array_values(array_filter(
                    $state['active_gf'],
                    static fn (TeamAppResource $r): bool => !isset($disconnectedRowIds[$r->getId()])
                ));
                if (!empty($needsSpace[$teamId]) && $activeGf === []) {
                    $this->ensureOwnSpace($teamId, $teamNames[$teamId], 'lost_folder', 'active', $summary);
                    continue;
                }
                if ($state['active_shared'] !== [] && $activeGf === []) {
                    $this->ensureSpaceNextToSharedFolder($teamId, $teamNames[$teamId], $state, $summary);
                }
            } catch (\Throwable $e) {
                $summary['failures']++;
                $this->logger->error('[TeamHub][TeamSpaceReconcile] team pass failed', [
                    'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        // ── Phase 3: withdraw notices whose condition has cleared ─────────
        $summary['notices_withdrawn'] = $this->withdrawStaleNotices($byTeam, $teamNames);

        $this->logger->info('[TeamHub][TeamSpaceReconcile] run complete', $summary + ['app' => Application::APP_ID]);
        return $summary;
    }

    /**
     * One group folder: make it the space of the team that holds it, for that
     * team alone (rules 1, 2 and 4).
     *
     * @param TeamAppResource[]     $claims             active rows naming this folder, oldest first
     * @param array<string,string>  $teamNames
     * @param array<string,bool>    $needsSpace         out
     * @param array<int,bool>       $disconnectedRowIds out
     * @param array<string,int|bool> $summary           in/out
     */
    private function reconcileFolder(
        int $folderId,
        array $claims,
        array $teamNames,
        array &$needsSpace,
        array &$disconnectedRowIds,
        array &$summary,
    ): void {
        $claimTeams = [];
        foreach ($claims as $row) {
            if (!in_array($row->getTeamId(), $claimTeams, true)) {
                $claimTeams[] = $row->getTeamId();
            }
        }
        if ($claimTeams === []) {
            return;
        }

        // Already somebody's space: the owner keeps it, every other claimant
        // is disconnected (it cannot open the folder anyway — a space is
        // applicable to its own circle only).
        $owner = $this->teamSpaceService->spaceOwnerCircleId($folderId);
        if ($owner !== null) {
            foreach ($claims as $row) {
                if ($row->getTeamId() !== $owner) {
                    $this->disconnectRow($row, $folderId, $owner, $teamNames, $needsSpace, $disconnectedRowIds, $summary);
                }
            }
            return;
        }

        // Rule 2 — the oldest connection keeps the folder.
        $keeper     = $claimTeams[0];
        $keeperName = $teamNames[$keeper] ?? $keeper;
        $folder     = $this->groupFolderService->resolveGroupFolderResourceId('gf:' . $folderId);
        $mountPoint = $folder['mount_point'] ?? ('#' . $folderId);

        // Rule 4 — the keeper may already own a space elsewhere (Circles'
        // auto-creation, or NC's Teams page). One team, one space: the empty
        // auto-created one goes; a space with content in it is a conflict a
        // person has to resolve.
        $existing = $this->teamSpaceService->getTeamSpace($keeper);
        if ($existing !== null) {
            if ($this->spaceIsEmpty($existing['id'])) {
                $this->teamSpaceService->removeTeamSpace($keeper);
                $this->auditService->log($keeper, self::AUDIT_DUPLICATE_REMOVED, null, 'file', (string)$existing['id'], [
                    'removed_space' => $existing['mount_point'],
                    'kept_folder'   => $mountPoint,
                    'folder_id'     => $folderId,
                ]);
                $summary['duplicates_removed']++;
            } else {
                $this->recordConflict($keeper, $keeperName, $existing['mount_point'], $mountPoint, $summary);
                return;
            }
        }

        // A row whose folder the circle can no longer even open is stale —
        // the team lost the folder outside TeamHub. That is
        // ResourceDiscoveryService's finding to make (it moves the row to a
        // failed state); this pass has nothing to link and says so quietly.
        $applicable = $this->groupFolderService->listApplicable($folderId);
        $keeperHasAccess = false;
        foreach ($applicable as $principal) {
            if ($principal['kind'] === 'circle' && $principal['id'] === $keeper) {
                $keeperHasAccess = true;
                break;
            }
        }
        if (!$keeperHasAccess) {
            $this->logger->debug('[TeamHub][TeamSpaceReconcile] folder is not applicable to the team that holds it; left to discovery', [
                'folderId' => $folderId, 'teamId' => $keeper, 'app' => Application::APP_ID,
            ]);
            return;
        }

        // Rule 1 — for the team only.
        $removed           = [];
        $disconnectedTeams = [];
        foreach ($applicable as $principal) {
            if ($principal['kind'] === 'circle' && $principal['id'] === $keeper) {
                continue;
            }
            $name = $principal['kind'] === 'group'
                ? $this->groupDisplayName($principal['id'])
                : ($teamNames[$principal['id']] ?? $this->teamNames([$principal['id']])[$principal['id']] ?? $principal['id']);
            try {
                $this->groupFolderService->removeApplicable($folderId, $principal['id']);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][TeamSpaceReconcile] could not remove a share from the folder', [
                    'folderId' => $folderId, 'principal' => $principal, 'error' => $e->getMessage(),
                    'app' => Application::APP_ID,
                ]);
                $summary['failures']++;
                continue;
            }
            $removed[] = ['kind' => $principal['kind'], 'id' => $principal['id'], 'name' => $name];
            $summary['shares_removed']++;

            if ($principal['kind'] === 'circle' && in_array($principal['id'], $claimTeams, true)) {
                $disconnectedTeams[] = $name;
                foreach ($claims as $row) {
                    if ($row->getTeamId() === $principal['id']) {
                        $this->disconnectRow($row, $folderId, $keeper, $teamNames, $needsSpace, $disconnectedRowIds, $summary);
                    }
                }
            }
        }

        // The link itself — metadata only.
        if (!$this->teamSpaceService->isLinkable($keeper, $folderId)) {
            // Something on the applicable list survived, or the folder is
            // not applicable to the keeper after all. Reported, not forced.
            $this->logger->warning('[TeamHub][TeamSpaceReconcile] folder is not linkable after cleanup', [
                'folderId' => $folderId, 'teamId' => $keeper, 'app' => Application::APP_ID,
            ]);
            $summary['failures']++;
        } else {
            $this->teamSpaceService->linkTeamSpace($keeper, $folderId);
            $this->auditService->log($keeper, self::AUDIT_LINKED, null, 'file', (string)$folderId, [
                'folder_id' => $folderId, 'mount_point' => $mountPoint,
            ]);
            $summary['linked']++;
        }

        if ($removed !== []) {
            $this->auditService->log($keeper, self::AUDIT_SHARES_REMOVED, null, 'file', (string)$folderId, [
                'folder_id'          => $folderId,
                'mount_point'        => $mountPoint,
                'removed'            => $removed,
                'disconnected_teams' => $disconnectedTeams,
            ]);
            $this->notifyAdmins(self::NOTIFY_SHARES_REMOVED, $keeper, [
                'teamName'   => $keeperName,
                'folderName' => $mountPoint,
                'removed'    => implode(', ', array_map(static fn (array $r): string => $r['name'], $removed)),
                'count'      => count($removed),
            ]);
        }
    }

    /**
     * A team's row on a folder it does not get to keep.
     *
     * @param array<string,string>   $teamNames
     * @param array<string,bool>     $needsSpace         out
     * @param array<int,bool>        $disconnectedRowIds out
     * @param array<string,int|bool> $summary            in/out
     */
    private function disconnectRow(
        TeamAppResource $row,
        int $folderId,
        string $keptBy,
        array $teamNames,
        array &$needsSpace,
        array &$disconnectedRowIds,
        array &$summary,
    ): void {
        $this->resourceMapper->updateStatus($row->getId(), 'disconnected', null, $this->timeFactory->getTime());
        $disconnectedRowIds[$row->getId()] = true;
        $needsSpace[$row->getTeamId()]     = true;
        $this->auditService->log($row->getTeamId(), self::AUDIT_DISCONNECTED, null, 'file', (string)$folderId, [
            'folder_id'    => $folderId,
            'kept_by'      => $keptBy,
            'kept_by_name' => $teamNames[$keptBy] ?? $keptBy,
        ]);
        $summary['teams_disconnected']++;
    }

    /**
     * Rule 3, and the second half of rule 2: a space of the team's own.
     *
     * @param array<string,int|bool> $summary in/out
     */
    private function ensureOwnSpace(string $teamId, string $teamName, string $reason, string $rowStatus, array &$summary): void {
        $space = $this->teamSpaceService->getTeamSpace($teamId);
        if ($space === null) {
            $space = $this->teamSpaceService->createTeamSpace($teamId, $teamName);
            $this->auditService->log($teamId, self::AUDIT_CREATED, null, 'file', (string)$space['id'], [
                'folder_id' => $space['id'], 'mount_point' => $space['mount_point'], 'reason' => $reason,
            ]);
            $summary['spaces_created']++;
        }
        $resourceId = 'gf:' . $space['id'];
        $existing   = $this->resourceMapper->findByTeamAppResource($teamId, 'files', $resourceId);
        if ($existing === null) {
            $this->resourceMapper->insertResource(
                teamId:      $teamId,
                appId:       'files',
                resourceId:  $resourceId,
                origin:      'teamhub_create',
                status:      $rowStatus,
                riskStatus:  'none',
                displayOrder: 0,
                decidedBy:   null,
                decidedAt:   $rowStatus === 'active' ? $this->timeFactory->getTime() : null,
                ownerUid:    null,
            );
        } elseif ($rowStatus === 'active' && $existing->getStatus() !== 'active') {
            $this->resourceMapper->updateStatus($existing->getId(), 'active', null, $this->timeFactory->getTime());
        } elseif ($rowStatus === 'pending' && in_array($existing->getStatus(), ['ignored', 'disconnected'], true)) {
            // Seen on the instance on the first run: Circles had auto-created
            // the space while TeamHub's own folder was still active, so
            // discovery had filed the space as an ignored duplicate. Now that
            // the team is down to its shared folder, the space is the folder
            // to move into — it has to be pending for the dual-folder notice
            // and the picker to offer it.
            $this->resourceMapper->updateStatus($existing->getId(), 'pending', null, null);
        }
    }

    /**
     * Rule 3 — the shared-folder team: an empty space beside it, pending
     * until the owner attaches it, and the administrators told once.
     *
     * @param array{active_gf: TeamAppResource[], pending_gf: TeamAppResource[], active_shared: TeamAppResource[]} $state
     * @param array<string,int|bool> $summary in/out
     */
    private function ensureSpaceNextToSharedFolder(string $teamId, string $teamName, array $state, array &$summary): void {
        $this->ensureOwnSpace($teamId, $teamName, 'shared_folder', 'pending', $summary);

        $notices = $this->notices();
        if (isset($notices[$teamId]) && ($notices[$teamId]['kind'] ?? '') === 'shared_folder') {
            return;
        }
        $space  = $this->teamSpaceService->getTeamSpace($teamId);
        $shared = $state['active_shared'][0];
        $this->notifyAdmins(self::NOTIFY_SHARED_FOLDER, $teamId, [
            'teamName'     => $teamName,
            'sharedFolder' => $this->fileName((int)$shared->getResourceId()) ?? $shared->getResourceId(),
            'spaceName'    => $space['mount_point'] ?? $teamName,
        ]);
        $notices[$teamId] = ['kind' => 'shared_folder', 'at' => $this->timeFactory->getTime()];
        $this->saveNotices($notices);
    }

    /**
     * Rule 4's other half: two folders with content, a person decides.
     *
     * @param array<string,int|bool> $summary in/out
     */
    private function recordConflict(string $teamId, string $teamName, string $spaceName, string $folderName, array &$summary): void {
        $summary['conflicts']++;
        $notices = $this->notices();
        if (isset($notices[$teamId]) && ($notices[$teamId]['kind'] ?? '') === 'conflict') {
            return;
        }
        $this->auditService->log($teamId, self::AUDIT_CONFLICT, null, 'file', null, [
            'space' => $spaceName, 'folder' => $folderName,
        ]);
        $this->notifyAdmins(self::NOTIFY_CONFLICT, $teamId, [
            'teamName' => $teamName, 'spaceName' => $spaceName, 'folderName' => $folderName,
        ]);
        $notices[$teamId] = ['kind' => 'conflict', 'at' => $this->timeFactory->getTime(), 'space' => $spaceName, 'folder' => $folderName];
        $this->saveNotices($notices);
    }

    /**
     * A notice is withdrawn the moment its condition is gone: the shared
     * folder disconnected (the owner finished), the conflict resolved, or the
     * team deleted. The Nextcloud notifications go with it.
     *
     * @param array<string, array{active_gf: TeamAppResource[], pending_gf: TeamAppResource[], active_shared: TeamAppResource[]}> $byTeam
     * @param array<string,string> $teamNames
     */
    private function withdrawStaleNotices(array $byTeam, array $teamNames): int {
        $notices   = $this->notices();
        $withdrawn = 0;
        foreach ($notices as $teamId => $notice) {
            $kind  = (string)($notice['kind'] ?? '');
            $stale = !isset($teamNames[$teamId]);
            if (!$stale && $kind === 'shared_folder') {
                $stale = ($byTeam[$teamId]['active_shared'] ?? []) === [];
            }
            if (!$stale && $kind === 'conflict') {
                // Resolved when the team no longer holds a plain folder next
                // to its space — either was removed or became the space.
                $space = $this->teamSpaceService->getTeamSpace($teamId);
                $plain = array_filter(
                    $byTeam[$teamId]['active_gf'] ?? [],
                    static fn (TeamAppResource $r): bool => $space === null || (int)substr($r->getResourceId(), 3) !== $space['id']
                );
                $stale = $space === null || $plain === [];
            }
            if ($stale) {
                unset($notices[$teamId]);
                $this->withdrawNotifications($teamId);
                $withdrawn++;
            }
        }
        if ($withdrawn > 0) {
            $this->saveNotices($notices);
        }
        return $withdrawn;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Facts for the My Work provider
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Teams still on a shared folder, with what the administrator needs to
     * say to the owner. Live: the item is gone the run after the folder is.
     *
     * @return array<int, array{teamId: string, teamName: string, sharedFolderId: int, sharedFolderName: string, spaceName: string|null, spaceConnected: bool, since: int}>
     */
    public function listSharedFolderTeams(): array {
        if (!$this->teamSpaceService->isAvailable()) {
            return [];
        }
        $rows      = $this->resourceMapper->findAllByApp('files', ['active', 'pending']);
        $teamNames = $this->teamNames(array_values(array_unique(array_map(
            static fn (TeamAppResource $r): string => $r->getTeamId(), $rows
        ))));
        $notices = $this->notices();

        $out = [];
        foreach ($rows as $row) {
            if ($row->getStatus() !== 'active' || str_starts_with($row->getResourceId(), 'gf:')) {
                continue;
            }
            $teamId = $row->getTeamId();
            if (!isset($teamNames[$teamId]) || isset($out[$teamId])) {
                continue;
            }
            $space = $this->teamSpaceService->getTeamSpace($teamId);
            $spaceRow = $space === null ? null
                : $this->resourceMapper->findByTeamAppResource($teamId, 'files', 'gf:' . $space['id']);
            $out[$teamId] = [
                'teamId'           => $teamId,
                'teamName'         => $teamNames[$teamId],
                'sharedFolderId'   => (int)$row->getResourceId(),
                'sharedFolderName' => $this->fileName((int)$row->getResourceId()) ?? $row->getResourceId(),
                'spaceName'        => $space['mount_point'] ?? null,
                'spaceConnected'   => $spaceRow !== null && $spaceRow->getStatus() === 'active',
                'since'            => (int)($notices[$teamId]['at'] ?? $row->getCreatedAt() ?? 0),
                'task'             => $this->taskOf($notices[$teamId] ?? []),
            ];
        }
        return array_values($out);
    }

    /**
     * The shared-folder teams among `$teamIds` whose move is assigned to the
     * team owner and not yet reported done — the owner's side of the task.
     *
     * @param string[] $teamIds
     * @return array<int, array{teamId: string, teamName: string, sharedFolderId: int, sharedFolderName: string, spaceName: string|null, spaceConnected: bool, since: int, task: array<string,mixed>}>
     */
    public function listAssignedTasks(array $teamIds): array {
        $wanted = array_flip(array_map('strval', $teamIds));
        $out = [];
        foreach ($this->listSharedFolderTeams() as $team) {
            $task = $team['task'];
            if ($task !== null && ($task['status'] ?? '') === self::TASK_ASSIGNED && isset($wanted[$team['teamId']])) {
                $out[] = $team;
            }
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────────────────
    // The owner's task (Justin, 2026-09-19: keep the workflow in the app)
    // ──────────────────────────────────────────────────────────────────────

    public const TASK_ASSIGNED = 'assigned';
    public const TASK_DONE     = 'done';
    public const TASK_CLOSED   = 'closed';

    /** Notification subjects of the task's two hand-overs. */
    public const NOTIFY_TASK_ASSIGNED  = 'teamspace_task_assigned';
    public const NOTIFY_TASK_COMPLETED = 'teamspace_task_completed';

    /**
     * The task on a team's shared-folder notice, or null when none was
     * handed out yet.
     *
     * @return array{status: string, ownerUid: string, ownerName: string, assignedBy: string, assignedByName: string, assignedAt: int, note: string, completedAt: int|null, completedBy: string|null, closedAt: int|null, closedBy: string|null}|null
     */
    public function getTask(string $teamId): ?array {
        return $this->taskOf($this->notices()[$teamId] ?? []);
    }

    /**
     * The Nextcloud administrator hands the move to the team owner: the
     * owner gets the task (a My Work row in their team's admin queue and a
     * notification), the administrator's row waits for them.
     *
     * @param array{uid: string, displayName: string} $owner
     */
    public function assignTask(string $teamId, string $adminUid, string $adminName, array $owner, string $note = ''): array {
        $notices = $this->notices();
        if (!isset($notices[$teamId]) || ($notices[$teamId]['kind'] ?? '') !== 'shared_folder') {
            throw new \RuntimeException('This team is not on a shared folder any more.');
        }
        $existing = $this->taskOf($notices[$teamId]);
        if ($existing !== null && $existing['status'] !== self::TASK_CLOSED) {
            throw new \RuntimeException('This move has already been handed to the team owner.');
        }
        $task = [
            'status'         => self::TASK_ASSIGNED,
            'ownerUid'       => $owner['uid'],
            'ownerName'      => $owner['displayName'],
            'assignedBy'     => $adminUid,
            'assignedByName' => $adminName,
            'assignedAt'     => $this->timeFactory->getTime(),
            'note'           => mb_substr(trim($note), 0, 1000),
            'completedAt'    => null,
            'completedBy'    => null,
            'closedAt'       => null,
            'closedBy'       => null,
        ];
        $notices[$teamId]['task'] = $task;
        $this->saveNotices($notices);

        $this->auditService->log($teamId, self::AUDIT_TASK_ASSIGNED, $adminUid, 'user', $owner['uid'], [
            'note' => $task['note'],
        ]);

        // The administrators' "still uses a shared folder" notices have been
        // acted on; the owner gets theirs.
        $this->withdrawNotifications($teamId);
        $teamName = $this->teamNames([$teamId])[$teamId] ?? $teamId;
        $this->notifyUser($owner['uid'], self::NOTIFY_TASK_ASSIGNED, $teamId, [
            'teamName'   => $teamName,
            'adminName'  => $adminName,
            'adminUid'   => $adminUid,
            'note'       => $task['note'],
        ]);
        MyWorkService::bumpNonce($this->cacheFactory, $owner['uid']);
        return $task;
    }

    /**
     * The owner reports the move done: the administrators are told and their
     * row comes back for the close.
     */
    public function completeTask(string $teamId, string $uid, string $name): array {
        $notices = $this->notices();
        $task    = $this->taskOf($notices[$teamId] ?? []);
        if ($task === null || $task['status'] !== self::TASK_ASSIGNED) {
            throw new \RuntimeException('This task is not open.');
        }
        $task['status']      = self::TASK_DONE;
        $task['completedAt'] = $this->timeFactory->getTime();
        $task['completedBy'] = $uid;
        $notices[$teamId]['task'] = $task;
        $this->saveNotices($notices);

        $this->auditService->log($teamId, self::AUDIT_TASK_COMPLETED, $uid, 'user', $task['assignedBy'], null);

        $this->withdrawNotifications($teamId);
        $teamName = $this->teamNames([$teamId])[$teamId] ?? $teamId;
        $this->notifyAdmins(self::NOTIFY_TASK_COMPLETED, $teamId, [
            'teamName'  => $teamName,
            'ownerName' => $name,
            'ownerUid'  => $uid,
        ]);
        $this->bumpAdminCaches();
        return $task;
    }

    /** The administrator files the task away. */
    public function closeTask(string $teamId, string $adminUid): array {
        $notices = $this->notices();
        $task    = $this->taskOf($notices[$teamId] ?? []);
        if ($task === null || $task['status'] === self::TASK_CLOSED) {
            throw new \RuntimeException('This task is not open.');
        }
        $task['status']   = self::TASK_CLOSED;
        $task['closedAt'] = $this->timeFactory->getTime();
        $task['closedBy'] = $adminUid;
        $notices[$teamId]['task'] = $task;
        $this->saveNotices($notices);

        $this->auditService->log($teamId, self::AUDIT_TASK_CLOSED, $adminUid, 'user', $task['ownerUid'], null);
        $this->withdrawNotifications($teamId);
        $this->bumpAdminCaches();
        MyWorkService::bumpNonce($this->cacheFactory, $task['ownerUid']);
        return $task;
    }

    /**
     * The hand-over as a three-step workflow with its states (v4.10.2), the
     * same on the administrator's row and the owner's — only the words are
     * the caller's. `current` is 1-based; a closed task is past its last step.
     *
     * @param array<string,mixed>|null $task
     * @param string[] $labels three labels, in order
     * @return array{steps: array<int, array{label: string, state: string, actor: string|null, at: int|null}>, current: int}
     */
    public function handOverWorkflow(?array $task, ?string $ownerName, array $labels): array {
        $status = (string)($task['status'] ?? '');
        $handed = $task !== null;
        $done   = in_array($status, [self::TASK_DONE, self::TASK_CLOSED], true);
        $closed = $status === self::TASK_CLOSED;
        return [
            'steps' => [
                [
                    'label' => $labels[0],
                    'state' => $handed ? 'done' : 'current',
                    'actor' => $handed ? (string)($task['assignedByName'] ?? '') : null,
                    'at'    => $handed ? (int)($task['assignedAt'] ?? 0) : null,
                ],
                [
                    'label' => $labels[1],
                    'state' => $done ? 'done' : ($handed ? 'current' : 'pending'),
                    'actor' => $done ? $ownerName : null,
                    'at'    => $done ? (int)($task['completedAt'] ?? 0) : null,
                ],
                [
                    'label' => $labels[2],
                    'state' => $closed ? 'done' : ($done ? 'current' : 'pending'),
                    'actor' => $closed ? (string)($task['closedBy'] ?? '') : null,
                    'at'    => $closed ? (int)($task['closedAt'] ?? 0) : null,
                ],
            ],
            'current' => ($closed || $done) ? 3 : ($handed ? 2 : 1),
        ];
    }

    /** @param array<string,mixed> $notice */
    private function taskOf(array $notice): ?array {
        $task = $notice['task'] ?? null;
        if (!is_array($task) || !isset($task['status'], $task['ownerUid'])) {
            return null;
        }
        return [
            'status'         => (string)$task['status'],
            'ownerUid'       => (string)$task['ownerUid'],
            'ownerName'      => (string)($task['ownerName'] ?? $task['ownerUid']),
            'assignedBy'     => (string)($task['assignedBy'] ?? ''),
            'assignedByName' => (string)($task['assignedByName'] ?? ''),
            'assignedAt'     => (int)($task['assignedAt'] ?? 0),
            'note'           => (string)($task['note'] ?? ''),
            'completedAt'    => isset($task['completedAt']) ? (int)$task['completedAt'] : null,
            'completedBy'    => isset($task['completedBy']) ? (string)$task['completedBy'] : null,
            'closedAt'       => isset($task['closedAt']) ? (int)$task['closedAt'] : null,
            'closedBy'       => isset($task['closedBy']) ? (string)$task['closedBy'] : null,
        ];
    }

    /** Every Nextcloud administrator's My Work re-reads on their next visit. */
    private function bumpAdminCaches(): void {
        $adminGroup = $this->groupManager->get('admin');
        if ($adminGroup === null) {
            return;
        }
        foreach ($adminGroup->getUsers() as $admin) {
            MyWorkService::bumpNonce($this->cacheFactory, $admin->getUID());
        }
    }

    /**
     * Teams whose space and folder both hold content (rule 4, unresolved).
     *
     * @return array<int, array{teamId: string, teamName: string, space: string, folder: string, since: int}>
     */
    public function listConflicts(): array {
        if (!$this->teamSpaceService->isAvailable()) {
            return [];
        }
        $out = [];
        $notices = $this->notices();
        $names   = $this->teamNames(array_keys($notices));
        foreach ($notices as $teamId => $notice) {
            if (($notice['kind'] ?? '') !== 'conflict' || !isset($names[$teamId])) {
                continue;
            }
            $out[] = [
                'teamId'   => (string)$teamId,
                'teamName' => $names[$teamId],
                'space'    => (string)($notice['space'] ?? ''),
                'folder'   => (string)($notice['folder'] ?? ''),
                'since'    => (int)($notice['at'] ?? 0),
            ];
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Notifications
    // ──────────────────────────────────────────────────────────────────────

    /**
     * One notification per Nextcloud administrator. Licensed instances are
     * sent to My Work, where the item with the procedure lives; the rest to
     * the docs page. Text and link are rendered per recipient by Notifier.
     *
     * @param array<string, string|int> $params
     */
    private function notifyAdmins(string $subject, string $teamId, array $params): void {
        $adminGroup = $this->groupManager->get('admin');
        if ($adminGroup === null) {
            $this->logger->warning('[TeamHub][TeamSpaceReconcile] admin group missing; nobody to notify', [
                'app' => Application::APP_ID,
            ]);
            return;
        }
        $level    = $this->licenseService->getEnforcementLevel();
        $licensed = $level === 'none' || $level === 'grace';
        $params  += ['teamId' => $teamId, 'licensed' => $licensed ? 1 : 0];

        foreach ($adminGroup->getUsers() as $admin) {
            try {
                $notification = $this->notificationManager->createNotification();
                $notification->setApp(Application::APP_ID)
                    ->setUser($admin->getUID())
                    ->setDateTime(new \DateTime('@' . $this->timeFactory->getTime()))
                    ->setObject(self::NOTIFY_OBJECT, $teamId)
                    ->setSubject($subject, $params);
                $this->notificationManager->notify($notification);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][TeamSpaceReconcile] notification failed', [
                    'subject' => $subject, 'teamId' => $teamId, 'error' => $e->getMessage(),
                    'app' => Application::APP_ID,
                ]);
            }
        }
    }

    /**
     * One notification to one person — the team owner receiving the task.
     * Same object as the administrators' notices, so `withdrawNotifications()`
     * clears the owner's along with theirs when the team's condition clears.
     *
     * @param array<string, string|int> $params
     */
    private function notifyUser(string $uid, string $subject, string $teamId, array $params): void {
        $level    = $this->licenseService->getEnforcementLevel();
        $licensed = $level === 'none' || $level === 'grace';
        $params  += ['teamId' => $teamId, 'licensed' => $licensed ? 1 : 0];
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($uid)
                ->setDateTime(new \DateTime('@' . $this->timeFactory->getTime()))
                ->setObject(self::NOTIFY_OBJECT, $teamId)
                ->setSubject($subject, $params);
            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamSpaceReconcile] notification failed', [
                'subject' => $subject, 'teamId' => $teamId, 'user' => $uid, 'error' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
        }
    }

    /** Remove every team-space notification about a team, for everybody. */
    private function withdrawNotifications(string $teamId): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)->setObject(self::NOTIFY_OBJECT, $teamId);
            $this->notificationManager->markProcessed($notification);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamSpaceReconcile] could not withdraw notifications', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function notices(): array {
        $raw = $this->config->getAppValue(Application::APP_ID, self::NOTICES_KEY, '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, array<string, mixed>> $notices */
    private function saveNotices(array $notices): void {
        $this->config->setAppValue(Application::APP_ID, self::NOTICES_KEY, json_encode($notices, JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Lookups
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Display names of the circles that still exist, keyed by id. A team
     * missing from the result is gone.
     *
     * @param string[] $teamIds
     * @return array<string,string>
     */
    protected function teamNames(array $teamIds): array {
        $teamIds = array_values(array_filter(array_map('strval', $teamIds), static fn (string $id): bool => $id !== ''));
        if ($teamIds === []) {
            return [];
        }
        $names = [];
        try {
            foreach (array_chunk($teamIds, 500) as $chunk) {
                $qb  = $this->db->getQueryBuilder();
                $res = $qb->select('unique_id', 'name', 'display_name', 'sanitized_name')
                    ->from('circles_circle')
                    ->where($qb->expr()->in('unique_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
                    ->executeQuery();
                while ($row = $res->fetch()) {
                    $id   = (string)$row['unique_id'];
                    $name = (string)($row['display_name'] ?? '');
                    if ($name === '') {
                        $name = (string)($row['sanitized_name'] ?? '');
                    }
                    if ($name === '') {
                        $raw  = (string)($row['name'] ?? '');
                        $name = str_starts_with($raw, 'app:circles:') ? substr($raw, strlen('app:circles:')) : $raw;
                    }
                    $names[$id] = $name !== '' ? $name : $id;
                }
                $res->closeCursor();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamSpaceReconcile] team name lookup failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
        return $names;
    }

    protected function groupDisplayName(string $gid): string {
        try {
            $group = $this->groupManager->get($gid);
            return $group !== null ? ($group->getDisplayName() ?: $gid) : $gid;
        } catch (\Throwable) {
            return $gid;
        }
    }

    /** The name of a file-cache entry, for the shared folder's display. */
    protected function fileName(int $fileId): ?string {
        if ($fileId <= 0) {
            return null;
        }
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('name')
                ->from('filecache')
                ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();
            $name = $row === false ? '' : (string)($row['name'] ?? '');
            return $name !== '' ? $name : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Is a space empty apart from its `.system` folder? Reads the folder's
     * root from `group_folders` and counts its direct children. Any doubt —
     * a missing row, a failed query — answers "not empty", because the only
     * thing done with "empty" is deleting it.
     */
    protected function spaceIsEmpty(int $folderId): bool {
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('root_id')
                ->from('group_folders')
                ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();
            $rootId = $row === false ? 0 : (int)($row['root_id'] ?? 0);
            if ($rootId <= 0) {
                return false;
            }
            $cq  = $this->db->getQueryBuilder();
            $cnt = $cq->select($cq->func()->count('*', 'cnt'))
                ->from('filecache')
                ->where($cq->expr()->eq('parent', $cq->createNamedParameter($rootId, IQueryBuilder::PARAM_INT)))
                ->andWhere($cq->expr()->neq('name', $cq->createNamedParameter('.system')))
                ->executeQuery();
            $n = (int)$cnt->fetchOne();
            $cnt->closeCursor();
            return $n === 0;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamSpaceReconcile] emptiness check failed; treating the space as not empty', [
                'folderId' => $folderId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }
}
