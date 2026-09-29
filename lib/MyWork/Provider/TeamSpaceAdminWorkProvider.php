<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork\Provider;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\AuditLogMapper;
use OCA\TeamHub\MyWork\ActionResult;
use OCA\TeamHub\MyWork\ActionType;
use OCA\TeamHub\MyWork\Category;
use OCA\TeamHub\MyWork\IWorkProvider;
use OCA\TeamHub\MyWork\OpenTarget;
use OCA\TeamHub\MyWork\Priority;
use OCA\TeamHub\MyWork\WorkItem;
use OCA\TeamHub\MyWork\WorkItemPage;
use OCA\TeamHub\MyWork\WorkQuery;
use OCA\TeamHub\Service\TeamExpiryService;
use OCA\TeamHub\Service\TeamSpaceReconcileService;
use OCA\TeamHub\Service\TeamSpaceService;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * My Work provider for the Nextcloud administrator's side of team spaces
 * (v4.10.1) — the second instance-scoped provider after
 * `TeamExpiryAdminWorkProvider`, and built to its rules: every entry point
 * establishes `IGroupManager::isAdmin()` first and returns nothing otherwise.
 *
 * Three kinds of row, all from `TeamSpaceReconcileService`:
 *
 *   - **A team still on a shared folder.** The reconcile pass created an
 *     empty team space next to it; somebody has to move the files, and that
 *     somebody is the owner — the files are in their Files. The row carries
 *     the procedure as `metadata.steps` (the frontend renders any provider's
 *     steps the same way) and a hand-over that stays in the app (Justin,
 *     2026-09-19): *Hand to team owner* gives the owner the same task in
 *     their own My Work (`TeamAdminWorkProvider`) and parks this row under
 *     Waiting for others; the owner's *Complete* brings it back with a
 *     *Close*; the daily pass withdraws everything once the shared folder is
 *     no longer an active resource.
 *   - **A report of what the pass removed** (Completed): the groups and
 *     teams that lost access to a folder when it became a space, and the
 *     empty duplicate spaces that were deleted. Read from the audit log,
 *     because a removal cannot be recomputed from the state afterwards.
 *   - **A conflict**: a team whose space and folder both hold content. The
 *     pass merges nothing there; a person decides.
 *
 * Unavailable on Nextcloud 33/34 (no team-folder provider), so the source
 * gets no tab there at all rather than an empty one.
 */
class TeamSpaceAdminWorkProvider implements IWorkProvider {

    public const ID = 'teamspace_admin';

    private const TYPE_SHARED   = 'team_space_shared_folder';
    private const TYPE_REPORT   = 'team_space_report';
    private const TYPE_CONFLICT = 'team_space_conflict';

    public const STATUS_SHARED_FOLDER     = 'shared_folder';
    /** The move is with the team owner; the administrator's row waits for them. */
    public const STATUS_ASSIGNED          = 'assigned_to_owner';
    /** The owner reported it done; the administrator closes the row. */
    public const STATUS_OWNER_DONE        = 'owner_completed';
    public const STATUS_CLOSED            = 'closed';
    public const STATUS_SHARES_REMOVED    = 'shares_removed';
    public const STATUS_DUPLICATE_REMOVED = 'duplicate_removed';
    public const STATUS_CONFLICT          = 'conflict';

    /** Rows per request; an estate with more shared folders than this is a rollout to plan. */
    private const MAX_ITEMS = 50;

    private ?string $unavailableReason = null;

    public function __construct(
        private TeamSpaceReconcileService $reconcileService,
        private TeamSpaceService          $teamSpaceService,
        private AuditLogMapper            $auditMapper,
        private TeamExpiryService         $expiryService,
        private IGroupManager             $groupManager,
        private IUserManager              $userManager,
        private IL10N                     $l,
        private LoggerInterface           $logger,
    ) {}

    // ---------------------------------------------------------------------
    // Identity + capabilities
    // ---------------------------------------------------------------------

    public function getId(): string {
        return self::ID;
    }

    public function getName(): string {
        // TRANSLATORS: My Work source name — the Nextcloud 35 team spaces of
        // teams, seen by Nextcloud administrators.
        return $this->l->t('Team spaces');
    }

    public function getIcon(): string {
        return 'teamspace';
    }

    /** Found by `method_exists`, like TeamExpiryAdminWorkProvider's. */
    public function isInstanceScoped(): bool {
        return true;
    }

    public function getCapabilities(): array {
        return [
            // Delegate hands the move to the team owner (the workflow stays in
            // the app — Justin, 2026-09-19); Complete files the row away once
            // the owner has reported it done. Both are mapped onto the shared
            // vocabulary rather than given new ActionTypes; the row's own
            // labels come from the item (`metadata.actionLabels`).
            // v4.10.29 — the quota request's Grant / Decline (Approve /
            // Reject) left with it: it is a Nextcloud service on the engine
            // now, answered by the desk that holds the bundle.
            'actions'       => [ActionType::OPEN, ActionType::DELEGATE, ActionType::COMPLETE],
            'resourceTypes' => [self::TYPE_SHARED, self::TYPE_REPORT, self::TYPE_CONFLICT],
            'statuses'      => [
                self::STATUS_SHARED_FOLDER, self::STATUS_ASSIGNED, self::STATUS_OWNER_DONE, self::STATUS_CLOSED,
                self::STATUS_SHARES_REMOVED, self::STATUS_DUPLICATE_REMOVED, self::STATUS_CONFLICT,
            ],
            'categories'    => [Category::ACTION_REQUIRED, Category::WAITING_FOR_OTHERS, Category::COMPLETED],
            'pagination'    => false,
            'incremental'   => false,
        ];
    }

    /**
     * Available exactly when Nextcloud has a team-folder provider. On 33/34
     * the question the rows answer does not exist, and an empty queue would
     * read as "nothing to do" — rule 3 of the contract.
     */
    public function isAvailable(): bool {
        if ($this->teamSpaceService->isAvailable()) {
            $this->unavailableReason = null;
            return true;
        }
        $this->unavailableReason = $this->l->t('Team spaces need Nextcloud 35 with the Team folders app.');
        return false;
    }

    public function getUnavailableReason(): ?string {
        return $this->unavailableReason;
    }

    public function getSupportedFilters(): array {
        return [];
    }

    public function getConfigSchema(): array {
        return [];
    }

    // ---------------------------------------------------------------------
    // Fetch
    // ---------------------------------------------------------------------

    public function fetchItems(WorkQuery $query): WorkItemPage {
        if (!$this->isNcAdmin($query->userId) || !$this->teamSpaceService->isAvailable()) {
            return WorkItemPage::empty();
        }
        $items     = [];
        $truncated = false;

        // Conflicts first: a person has to decide, and nothing moves until they do.
        try {
            foreach ($this->reconcileService->listConflicts() as $conflict) {
                if (count($items) >= self::MAX_ITEMS) {
                    $truncated = true;
                    break;
                }
                $items[] = $this->buildConflictItem($conflict);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][TeamSpaceAdmin] conflict lookup failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // Then the shared-folder teams, the reason this provider exists.
        try {
            foreach ($this->reconcileService->listSharedFolderTeams() as $team) {
                if (count($items) >= self::MAX_ITEMS) {
                    $truncated = true;
                    break;
                }
                $items[] = $this->buildSharedFolderItem($team);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][TeamSpaceAdmin] shared-folder lookup failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // Last, the reports of what the pass did — a record, not a task.
        try {
            foreach ($this->recentReports($query->completedSince()) as $row) {
                if (count($items) >= self::MAX_ITEMS) {
                    $truncated = true;
                    break;
                }
                $items[] = $this->buildReportItem($row);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][TeamSpaceAdmin] report lookup failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        return new WorkItemPage($items, count($items), $truncated);
    }

    /** `$allowedTeamIds` unused on purpose — the boundary is administrator-or-not. */
    public function getItem(string $userId, string $itemId, array $allowedTeamIds): ?WorkItem {
        if (!$this->isNcAdmin($userId) || !$this->teamSpaceService->isAvailable()) {
            return null;
        }
        $parts = explode(':', $itemId, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$kind, $ref] = $parts;
        try {
            switch ($kind) {
                case 'shared':
                    foreach ($this->reconcileService->listSharedFolderTeams() as $team) {
                        if ($team['teamId'] === $ref) {
                            return $this->buildSharedFolderItem($team);
                        }
                    }
                    return null;
                case 'conflict':
                    foreach ($this->reconcileService->listConflicts() as $conflict) {
                        if ($conflict['teamId'] === $ref) {
                            return $this->buildConflictItem($conflict);
                        }
                    }
                    return null;
                case 'report':
                    $since = time() - (WorkQuery::DEFAULT_COMPLETED_DAYS * 86400);
                    foreach ($this->recentReports($since) as $row) {
                        if ((string)$row['id'] === $ref) {
                            return $this->buildReportItem($row);
                        }
                    }
                    return null;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][TeamSpaceAdmin] item re-read failed', [
                'itemId' => $itemId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
        return null;
    }

    // ---------------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------------

    /**
     * The shared-folder row walks a three-step hand-over: *Hand to team
     * owner* while nobody has it, nothing but Open while the owner has it
     * (the row sits in Waiting for others), *Close* once the owner reported
     * it done. Hand-over needs an owner to hand to — a team without one gets
     * no button, not a disabled one. Reports and conflicts only open.
     */
    public function getAvailableActions(string $userId, WorkItem $item): array {
        if (!$this->isNcAdmin($userId)) {
            return [];
        }
        if ($item->resourceType !== self::TYPE_SHARED) {
            return [ActionType::OPEN];
        }
        switch ((string)($item->metadata['taskStatus'] ?? '')) {
            case TeamSpaceReconcileService::TASK_ASSIGNED:
                return [ActionType::OPEN];
            case TeamSpaceReconcileService::TASK_DONE:
                return [ActionType::OPEN, ActionType::COMPLETE];
            case TeamSpaceReconcileService::TASK_CLOSED:
                return [];
            default:
                return ($item->metadata['ownerUid'] ?? null) !== null
                    ? [ActionType::OPEN, ActionType::DELEGATE]
                    : [ActionType::OPEN];
        }
    }

    public function executeAction(string $userId, WorkItem $item, string $action, array $params): ActionResult {
        if (!$this->isNcAdmin($userId)) {
            return ActionResult::forbidden($this->l->t('Nextcloud administrator privileges are required.'));
        }
        if ($item->resourceType !== self::TYPE_SHARED) {
            return ActionResult::unsupported($this->l->t('This row is a record; there is nothing to do on it.'));
        }
        try {
            if ($action === ActionType::DELEGATE) {
                $ownerUid  = (string)($item->metadata['ownerUid'] ?? '');
                $ownerName = (string)($item->metadata['ownerName'] ?? $ownerUid);
                if ($ownerUid === '') {
                    return ActionResult::conflict($this->l->t('This team has no owner to hand the move to.'));
                }
                $admin = $this->userManager->get($userId);
                $this->reconcileService->assignTask(
                    $item->teamId,
                    $userId,
                    $admin !== null ? ($admin->getDisplayName() ?: $userId) : $userId,
                    ['uid' => $ownerUid, 'displayName' => $ownerName],
                    (string)($params['reason'] ?? ''),
                );
                return ActionResult::success(
                    $this->l->t('Handed to %s. This row waits for them; you will be notified when they report it done.', [$ownerName]),
                    null,
                    false,
                );
            }
            if ($action === ActionType::COMPLETE) {
                $this->reconcileService->closeTask($item->teamId, $userId);
                return ActionResult::success($this->l->t('Closed. The row is filed under Completed.'), null, false);
            }
        } catch (\RuntimeException $e) {
            // The ledger moved under the row — somebody else handed it over or
            // closed it while it was on screen. A refresh shows the truth.
            return ActionResult::conflict($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWork][TeamSpaceAdmin] action failed', [
                'action' => $action, 'teamId' => $item->teamId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return ActionResult::failure($this->l->t('That could not be saved.'), 'failed');
        }
        return ActionResult::unsupported($this->l->t('Unknown action.'));
    }

    // ---------------------------------------------------------------------
    // Item builders
    // ---------------------------------------------------------------------

    /**
     * The shared-folder row, in the state of its hand-over (v4.10.1, Justin:
     * the workflow stays in the app):
     *
     *   - **nobody has it** — Team admin, *Hand to team owner* offered;
     *   - **the owner has it** — Waiting for others, with the owner's name,
     *     Open only; the owner's own My Work carries the task and its steps;
     *   - **the owner reported it done** — back in Team admin with *Close*;
     *   - **closed** — Completed, for the window, then gone.
     *
     * The daily pass withdraws the whole notice once the shared folder is no
     * longer an active resource, whatever the state, so nothing lingers.
     *
     * @param array{teamId: string, teamName: string, sharedFolderId: int, sharedFolderName: string, spaceName: string|null, spaceConnected: bool, since: int, task: array<string,mixed>|null} $team
     */
    private function buildSharedFolderItem(array $team): WorkItem {
        $teamId   = $team['teamId'];
        $teamName = $team['teamName'];
        $shared   = $team['sharedFolderName'];
        $space    = $team['spaceName'] ?? $teamName;
        $task     = $team['task'] ?? null;
        $status   = (string)($task['status'] ?? '');
        $steps    = $this->sharedFolderSteps($teamName, $shared, $space, $team['spaceConnected']);

        // Before the hand-over the owner is looked up live; afterwards the
        // task remembers who got it, which is who the row waits for even if
        // ownership moves in the meantime.
        if ($task !== null) {
            $owner = ['uid' => (string)$task['ownerUid'], 'displayName' => (string)$task['ownerName']];
        } else {
            $resolved = $this->expiryService->resolveTeamOwner($teamId);
            $owner = $resolved !== null ? ['uid' => $resolved['uid'], 'displayName' => $resolved['displayName']] : null;
        }
        $ownerName = $owner['displayName'] ?? $this->l->t('the team owner');

        $category    = Category::ACTION_REQUIRED;
        $priority    = Priority::NORMAL;
        $itemStatus  = self::STATUS_SHARED_FOLDER;
        $completedAt = null;
        $updatedAt   = $team['since'] > 0 ? $team['since'] : null;
        $reason      = $this->l->t('Nextcloud 35 gives every team a team space. TeamHub created it next to the shared folder; the files are still in the shared folder.');
        $confirm     = [
            ActionType::DELEGATE => [
                'title'       => $this->l->t('Hand the move to %s?', [$ownerName]),
                'body'        => $this->l->t('%s gets this as a task in their own My Work, with the steps, and a notification. This row waits for them; you are notified when they report it done.', [$ownerName]),
                // TRANSLATORS: label of an optional free-text field when handing a task to a team owner
                'reasonLabel' => $this->l->t('Note to the owner (optional)'),
            ],
        ];

        switch ($status) {
            case TeamSpaceReconcileService::TASK_ASSIGNED:
                $category   = Category::WAITING_FOR_OTHERS;
                $itemStatus = self::STATUS_ASSIGNED;
                $updatedAt  = (int)$task['assignedAt'];
                $reason     = $this->l->t('Handed to %s. You are notified when they report the move done.', [$ownerName]);
                break;
            case TeamSpaceReconcileService::TASK_DONE:
                $priority   = Priority::HIGH;
                $itemStatus = self::STATUS_OWNER_DONE;
                $updatedAt  = (int)($task['completedAt'] ?? $task['assignedAt']);
                $reason     = $this->l->t('%s reported the move done. Check and close this row.', [$ownerName]);
                $confirm    = [
                    ActionType::COMPLETE => [
                        'title' => $this->l->t('Close the move for %s?', [$teamName]),
                        'body'  => $this->l->t('The row is filed under Completed. If the shared folder is still connected, it stays in Manage team until the owner disconnects it; nothing is deleted.'),
                    ],
                ];
                break;
            case TeamSpaceReconcileService::TASK_CLOSED:
                $category    = Category::COMPLETED;
                $priority    = Priority::LOW;
                $itemStatus  = self::STATUS_CLOSED;
                $completedAt = (int)($task['closedAt'] ?? $task['completedAt'] ?? $team['since']);
                $updatedAt   = $completedAt;
                $reason      = $this->l->t('Closed after %s reported the move done.', [$ownerName]);
                $confirm     = [];
                break;
        }

        return WorkItem::make([
            'providerId'     => self::ID,
            'providerItemId' => 'shared:' . $teamId,
            'teamId'         => $teamId,
            'teamName'       => $teamName,
            'category'       => $category,
            'title'          => $this->l->t('Move the shared folder of %s into its team space', [$teamName]),
            // TRANSLATORS: %1$s is the old shared folder's name, %2$s the new team space's name
            'subtitle'       => $this->l->t('"%1$s" → "%2$s"', [$shared, $space]),
            'resourceType'   => self::TYPE_SHARED,
            'resourceId'     => $teamId,
            'resourceUrl'    => '/settings/admin/teamhub',
            'openTarget'     => OpenTarget::external(),
            'priority'       => $priority,
            'status'         => $itemStatus,
            'reason'         => $reason,
            'createdAt'      => $team['since'] > 0 ? $team['since'] : null,
            'updatedAt'      => $updatedAt,
            'dueAt'          => null,
            'completedAt'    => $completedAt,
            'assignee'       => null,
            'waitingFor'     => $owner !== null
                ? ['type' => 'user', 'id' => $owner['uid'], 'displayName' => $owner['displayName']]
                : null,
            'availableActions' => [],
            'metadata'       => [
                'steps'          => $steps,
                'sharedFolder'   => $shared,
                'spaceName'      => $space,
                'spaceConnected' => $team['spaceConnected'],
                'ownerUid'       => $owner['uid'] ?? null,
                'ownerName'      => $owner['displayName'] ?? null,
                'taskStatus'     => $status !== '' ? $status : null,
                'workflow'       => $this->handOverWorkflow($task, $owner['displayName'] ?? null),
                'taskNote'       => (string)($task['note'] ?? ''),
                // The row's own words for the shared verbs — see the
                // frontend's generic `metadata.actionLabels` / `confirm`.
                'actionLabels'   => [
                    // TRANSLATORS: button — give the folder move to the team's owner as a task in their My Work
                    ActionType::DELEGATE => $this->l->t('Hand to team owner'),
                    // TRANSLATORS: button — the administrator files the finished folder move away
                    ActionType::COMPLETE => $this->l->t('Close'),
                ],
                'confirm'        => $confirm,
            ],
            'permissions'    => [],
        ]);
    }

    /**
     * @param array{teamId: string, teamName: string, space: string, folder: string, since: int} $conflict
     */
    private function buildConflictItem(array $conflict): WorkItem {
        $teamId   = $conflict['teamId'];
        $teamName = $conflict['teamName'];
        $owner    = $this->expiryService->resolveTeamOwner($teamId);
        $steps    = [
            $this->l->t('Decide which folder the team keeps: the team space "%1$s" or the team folder "%2$s".', [$conflict['space'], $conflict['folder']]),
            $this->l->t('Ask the team owner to move the files of the other folder into it (Files → select all → Move).'),
            $this->l->t('Disconnect the emptied folder in Manage team → Modules & integrations → Files. If the team space is the one to go, delete it from the Team folders admin page after unlinking it on Nextcloud\'s Teams page.'),
            $this->l->t('The next daily pass makes the remaining folder the team space; this item clears itself.'),
        ];
        return WorkItem::make([
            'providerId'     => self::ID,
            'providerItemId' => 'conflict:' . $teamId,
            'teamId'         => $teamId,
            'teamName'       => $teamName,
            'category'       => Category::ACTION_REQUIRED,
            'title'          => $this->l->t('%s has two folders with content', [$teamName]),
            'subtitle'       => $this->l->t('"%1$s" and "%2$s"', [$conflict['space'], $conflict['folder']]),
            'resourceType'   => self::TYPE_CONFLICT,
            'resourceId'     => $teamId,
            'resourceUrl'    => '/settings/admin/teamhub',
            'openTarget'     => OpenTarget::external(),
            'priority'       => Priority::HIGH,
            'status'         => self::STATUS_CONFLICT,
            'reason'         => $this->l->t('Both folders contain files, so TeamHub did not merge them.'),
            'createdAt'      => $conflict['since'] > 0 ? $conflict['since'] : null,
            'updatedAt'      => $conflict['since'] > 0 ? $conflict['since'] : null,
            'dueAt'          => null,
            'completedAt'    => null,
            'assignee'       => null,
            'waitingFor'     => $owner !== null
                ? ['type' => 'user', 'id' => $owner['uid'], 'displayName' => $owner['displayName']]
                : null,
            'availableActions' => [],
            'metadata'       => [
                'steps'      => $steps,
                'spaceName'  => $conflict['space'],
                'folderName' => $conflict['folder'],
                'ownerUid'   => $owner['uid'] ?? null,
                'ownerName'  => $owner['displayName'] ?? null,
            ],
            'permissions'    => [],
        ]);
    }

    /**
     * The hand-over's three steps with their states, for `metadata.workflow`
     * on the shared-folder row (v4.10.2). The states are the reconcile
     * service's (the owner's row shows the same workflow); the words are
     * this provider's.
     *
     * @param array<string,mixed>|null $task
     * @return array{steps: array<int, array{label: string, state: string, actor: string|null, at: int|null}>, current: int}
     */
    private function handOverWorkflow(?array $task, ?string $ownerName): array {
        return $this->reconcileService->handOverWorkflow($task, $ownerName, [
            // TRANSLATORS: workflow step — a Nextcloud administrator hands the folder move to the team owner
            $this->l->t('Handed to the team owner'),
            // TRANSLATORS: workflow step — the team owner moves the files and disconnects the shared folder
            $this->l->t('Owner moves the folder'),
            // TRANSLATORS: workflow step — a Nextcloud administrator closes the finished folder move
            $this->l->t('Administrator closes'),
        ]);
    }

    /** @param array<string, mixed> $row an audit row */
    private function buildReportItem(array $row): WorkItem {
        $teamId   = (string)$row['team_id'];
        $teamName = $this->expiryService->resolveTeamName($teamId);
        $meta     = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $at       = (int)$row['created_at'];

        if ((string)$row['event_type'] === TeamSpaceReconcileService::AUDIT_DUPLICATE_REMOVED) {
            $status = self::STATUS_DUPLICATE_REMOVED;
            $title  = $this->l->t('An empty duplicate team space of %s was removed', [$teamName]);
            $reason = $this->l->t('"%1$s" was created automatically next to the team folder "%2$s" and held no files; the team folder is now the team space.', [
                (string)($meta['removed_space'] ?? ''), (string)($meta['kept_folder'] ?? ''),
            ]);
            $steps  = [];
        } else {
            $removed = is_array($meta['removed'] ?? null) ? $meta['removed'] : [];
            $status  = self::STATUS_SHARES_REMOVED;
            $title   = $this->l->t('The team folder of %s became a team space', [$teamName]);
            $reason  = $this->l->n(
                'Access to "%1$s" was removed for %n group or team; a team space belongs to its team only.',
                'Access to "%1$s" was removed for %n groups or teams; a team space belongs to its team only.',
                count($removed),
                [(string)($meta['mount_point'] ?? '')],
            );
            $steps = [];
            foreach ($removed as $r) {
                $name = (string)($r['name'] ?? $r['id'] ?? '');
                $steps[] = ($r['kind'] ?? '') === 'group'
                    ? $this->l->t('Group "%s" no longer has access.', [$name])
                    : $this->l->t('Team "%s" no longer has access.', [$name]);
            }
            foreach ((array)($meta['disconnected_teams'] ?? []) as $name) {
                $steps[] = $this->l->t('Team "%s" was disconnected from the folder and received an empty team space of its own.', [(string)$name]);
            }
        }

        return WorkItem::make([
            'providerId'     => self::ID,
            'providerItemId' => 'report:' . (int)$row['id'],
            'teamId'         => $teamId,
            'teamName'       => $teamName,
            'category'       => Category::COMPLETED,
            'title'          => $title,
            'subtitle'       => (string)($meta['mount_point'] ?? $meta['kept_folder'] ?? ''),
            'resourceType'   => self::TYPE_REPORT,
            'resourceId'     => (string)$row['id'],
            'resourceUrl'    => '/settings/admin/teamhub',
            'openTarget'     => OpenTarget::external(),
            'priority'       => Priority::LOW,
            'status'         => $status,
            'reason'         => $reason,
            'createdAt'      => $at,
            'updatedAt'      => $at,
            'dueAt'          => null,
            'completedAt'    => $at,
            'assignee'       => null,
            'waitingFor'     => null,
            'availableActions' => [],
            'metadata'       => ['steps' => $steps],
            'permissions'    => [],
        ]);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * The procedure, as the administrator will read it and as the email will
     * carry it. Written for the owner's hands: the files sit in the owner's
     * own Files, which is why the administrator cannot do this part.
     *
     * @return string[]
     */
    private function sharedFolderSteps(string $teamName, string $shared, string $space, bool $spaceConnected): array {
        $steps = [
            $this->l->t('Ask the team owner to open Files, select everything in the shared folder "%1$s" and move it into the team space "%2$s".', [$shared, $space]),
        ];
        if (!$spaceConnected) {
            $steps[] = $this->l->t('In Manage team → Modules & integrations → Files, the owner connects the team space with "+ Connect team folder" — it is already listed.');
        }
        $steps[] = $this->l->t('The owner then disconnects the shared folder "%s" in the same section. The folder itself stays in their Files; only the team\'s access to it ends.', [$shared]);
        $steps[] = $this->l->t('This item clears itself once the shared folder is disconnected. Nothing is deleted at any step.');
        return $steps;
    }

    /** @return array<int, array<string, mixed>> */
    private function recentReports(int $since): array {
        return $this->auditMapper->findByEventTypes(
            [TeamSpaceReconcileService::AUDIT_SHARES_REMOVED, TeamSpaceReconcileService::AUDIT_DUPLICATE_REMOVED],
            $since,
            self::MAX_ITEMS,
        );
    }

    /** Fails closed: an error establishing group membership means "not an administrator". */
    private function isNcAdmin(string $userId): bool {
        if ($userId === '') {
            return false;
        }
        try {
            return $this->groupManager->isAdmin($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][TeamSpaceAdmin] admin check failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }
}
