<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork\Provider;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\MyWork\ActionResult;
use OCA\TeamHub\MyWork\ActionType;
use OCA\TeamHub\MyWork\Category;
use OCA\TeamHub\MyWork\IWorkProvider;
use OCA\TeamHub\MyWork\OpenTarget;
use OCA\TeamHub\MyWork\Priority;
use OCA\TeamHub\MyWork\WorkItem;
use OCA\TeamHub\MyWork\WorkItemPage;
use OCA\TeamHub\MyWork\WorkQuery;
use OCA\TeamHub\Service\CalendarIdentityService;
use OCA\TeamHub\Service\TaskNotificationService;
use OCA\TeamHub\Service\TaskService;
use OCA\TeamHub\Service\TeamCalendarLocator;
use OCA\TeamHub\Util\CalendarBlob;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * My Work provider for team tasks (v4.11.15).
 *
 * A task here is a VTODO on a calendar shared with a team's circle — the thing
 * *Create personal task* writes, and the thing the Tasks app shows. Justin,
 * 2026-10-01: a task was visible in the Upcoming tasks widget and nowhere in My
 * Work, which is the one list a person actually works from.
 *
 * ## Whose task is it
 *
 * The honest answer comes from the object, not from a guess. Since v4.11.15
 * `TaskService` writes an `ORGANIZER` and, when the task is for one person, a
 * single `ATTENDEE` — so the three cases are a lookup:
 *
 *  - **the `ATTENDEE` is you** → yours. **Action required** when it is overdue
 *    or inside the lead time, **Upcoming** otherwise.
 *  - **the `ATTENDEE` is somebody else and you are the `ORGANIZER`** → you
 *    asked for it, so **Waiting for others** with them named. One item per
 *    party, per the `/mywork-workflows` shape, and the row carries the whole
 *    two-step workflow.
 *  - **the `ATTENDEE` is somebody else and you are not the organiser** →
 *    nothing. Not your work and not your business.
 *  - **there is no `ATTENDEE`** → the team's own task list, shown to every
 *    member under **Upcoming**. Every task written before v4.11.15 is this
 *    case, which is why nothing had to be migrated.
 *
 * A finished task is a record under **Completed** for the window, and drops
 * out after it, like every other provider's.
 *
 * ## Undated tasks appear, unlike undated Deck cards
 *
 * `DeckWorkProvider` excludes an undated card: *an undated, unblocked card is
 * backlog, not a work queue item*. That rule is right there and wrong here,
 * and the difference is that a Deck card lives on a board you can go and look
 * at. A TeamHub task has no board — My Work and the widget are the only places
 * it is ever seen — so excluding it does not file it under backlog, it makes it
 * invisible. It was the first thing reported against this feature. An undated
 * task therefore gets a row under Upcoming, sorted after everything dated.
 *
 * ## Completing from the row
 *
 * Offered, which `MeetingWorkProvider` deliberately does not do. Its reason is
 * iTIP: answering an invitation means driving the scheduling stack from outside
 * NC Calendar, and HANDOFF issue #41 records how that behaves. Finishing a
 * VTODO is a plain object update with no scheduling in it at all, so the hazard
 * does not transfer. The write lives in `TaskService::completeTeamTask()`.
 *
 * Completing is offered on a task that is yours or the team's — never on a
 * Waiting-for-others row, because the next move there is genuinely somebody
 * else's and a queue that offers you their button teaches people to distrust
 * the buttons.
 */
class TaskWorkProvider implements IWorkProvider {

    public const ID = 'tasks';

    public const RESOURCE_TYPE = 'task';

    // Source statuses. Part of the stored contract (saved filters name them) —
    // do not rename.
    public const STATUS_ASSIGNED  = 'task_assigned';
    public const STATUS_OVERDUE   = 'task_overdue';
    public const STATUS_TEAM      = 'task_team';
    public const STATUS_DELEGATED = 'task_delegated';
    public const STATUS_DONE      = 'task_done';

    private ?string $unavailableReason = null;

    public function __construct(
        private IDBConnection $db,
        private IAppManager $appManager,
        private TeamCalendarLocator $calendars,
        private CalendarIdentityService $identity,
        private TaskService $taskService,
        private TaskNotificationService $notifications,
        private IL10N $l,
        private LoggerInterface $logger,
    ) {
    }

    // ---------------------------------------------------------------------
    // Identity + capabilities
    // ---------------------------------------------------------------------

    public function getId(): string {
        return self::ID;
    }

    public function getName(): string {
        return $this->l->t('Tasks');
    }

    public function getIcon(): string {
        return 'tasks';
    }

    public function getCapabilities(): array {
        return [
            'actions'       => [ActionType::OPEN, ActionType::COMPLETE],
            'resourceTypes' => [self::RESOURCE_TYPE],
            'statuses'      => [
                self::STATUS_ASSIGNED,
                self::STATUS_OVERDUE,
                self::STATUS_TEAM,
                self::STATUS_DELEGATED,
                self::STATUS_DONE,
            ],
            'categories' => [
                Category::ACTION_REQUIRED,
                Category::UPCOMING,
                Category::WAITING_FOR_OTHERS,
                Category::COMPLETED,
            ],
            'pagination'  => false,
            'incremental' => false,
        ];
    }

    public function isAvailable(): bool {
        $this->unavailableReason = null;

        if (!$this->appManager->isInstalled('tasks')) {
            $this->unavailableReason = $this->l->t('The Tasks app is not installed.');
            return false;
        }
        if (!$this->appManager->isInstalled('calendar')) {
            $this->unavailableReason = $this->l->t('The Calendar app is not installed.');
            return false;
        }

        // A missing column here is the difference between "you have no tasks"
        // and "TeamHub cannot see your tasks", and only one of those is safe to
        // show silently (the provider contract's rule 3).
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('co.id', 'co.uri', 'co.componenttype', 'co.calendardata')
                ->from('calendarobjects', 'co')
                ->setMaxResults(1)
                ->executeQuery()
                ->closeCursor();
        } catch (\Throwable $e) {
            $this->unavailableReason = $this->l->t(
                'The calendar tables are not in the expected shape: {error}',
                ['error' => $e->getMessage()],
            );
            return false;
        }

        return true;
    }

    public function getUnavailableReason(): ?string {
        return $this->unavailableReason;
    }

    public function getSupportedFilters(): array {
        // Team and component type are narrowed in SQL; whose task it is and
        // when it is due are decided after the VTODO is parsed.
        return ['teamIds'];
    }

    public function getConfigSchema(): array {
        return [];
    }

    /**
     * Admin-only structural diagnostics, discovered by ProviderRegistry via
     * `method_exists`. Counts and facts only — never a task title.
     *
     * @return array<int,array{label:string,value:string}>
     */
    public function getDiagnostics(): array {
        $rows = [];

        $rows[] = [
            'label' => $this->l->t('Tasks app installed'),
            'value' => $this->appManager->isInstalled('tasks') ? 'yes' : 'no',
        ];

        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select($qb->func()->count('*', 'c'))
                ->from('calendarobjects')
                ->where($qb->expr()->eq('componenttype', $qb->createNamedParameter('VTODO')))
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();
            $rows[] = [
                'label' => $this->l->t('Tasks on the instance'),
                'value' => (string)(int)($row['c'] ?? 0),
            ];
        } catch (\Throwable $e) {
            $rows[] = [
                'label' => $this->l->t('Tasks on the instance'),
                'value' => 'error: ' . $e->getMessage(),
            ];
        }

        return $rows;
    }

    // ---------------------------------------------------------------------
    // Fetch
    // ---------------------------------------------------------------------

    public function fetchItems(WorkQuery $query): WorkItemPage {
        if (!$this->isAvailable() || $query->teamIds === []) {
            return WorkItemPage::empty();
        }

        $items     = [];
        $truncated = false;
        $budget    = max(1, $query->perProviderCap);

        foreach ($this->calendars->forTeams($query->teamIds) as $calendar) {
            if (count($items) >= $budget) {
                $truncated = true;
                break;
            }
            try {
                $rows = $this->vtodoRows($calendar['id'], $budget);
            } catch (\Throwable $e) {
                // One unreadable calendar must not cost the others.
                $this->logger->warning('[TeamHub][MyWork][Tasks] Calendar read failed', [
                    'calendarId' => $calendar['id'], 'error' => $e->getMessage(),
                    'app' => Application::APP_ID,
                ]);
                continue;
            }

            foreach ($rows as $row) {
                if (count($items) >= $budget) {
                    $truncated = true;
                    break;
                }
                // Per row, not per calendar. The first build caught around the
                // whole loop, so a single object Sabre would not parse took
                // every task on that calendar down with it — which is how a
                // task assigned to a colleague reached neither queue
                // (v4.11.16). Logged without the task's own content.
                try {
                    $item = $this->buildItem($query, $calendar, $row);
                } catch (\Throwable $e) {
                    $this->logger->warning('[TeamHub][MyWork][Tasks] Unreadable task skipped', [
                        'calendarId' => $calendar['id'], 'objectUri' => (string)($row['uri'] ?? ''),
                        'error' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                    continue;
                }
                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }

        return new WorkItemPage($items, count($items), $truncated);
    }

    public function getItem(string $userId, string $providerItemId, array $allowedTeamIds): ?WorkItem {
        if (!$this->isAvailable() || $allowedTeamIds === []) {
            return null;
        }

        [$calendarId, $uri] = self::splitItemId($providerItemId);
        if ($calendarId <= 0 || $uri === '') {
            return null;
        }

        // Widened window and completed included: this path authorises an
        // action, so "not found" because of a horizon would read as "somebody
        // deleted it".
        $query = new WorkQuery(
            userId: $userId,
            teamIds: $allowedTeamIds,
            now: time(),
            includeCompleted: true,
            upcomingDays: 366,
            completedDays: 3650,
        );

        foreach ($this->calendars->forTeams($allowedTeamIds) as $calendar) {
            if ((int)$calendar['id'] !== $calendarId) {
                continue;
            }
            try {
                foreach ($this->vtodoRows($calendarId, 500, $uri) as $row) {
                    if ((string)$row['uri'] !== $uri) {
                        continue;
                    }
                    return $this->buildItem($query, $calendar, $row);
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------------

    public function getAvailableActions(string $userId, WorkItem $item): array {
        $actions = [ActionType::OPEN];

        if (($item->permissions['canComplete'] ?? false) === true) {
            $actions[] = ActionType::COMPLETE;
        }

        return $actions;
    }

    public function executeAction(string $userId, WorkItem $item, string $action, array $params): ActionResult {
        if ($action === ActionType::OPEN) {
            return ActionResult::success($this->l->t('Opening the task.'));
        }

        if ($action !== ActionType::COMPLETE) {
            return ActionResult::unsupported($this->l->t('Tasks can only be opened or completed from here.'));
        }

        // Rule 4 of the provider contract: re-check, never trust the item the
        // client sent. `$item` came from `getItem()`, which recomputed the
        // permission from the object itself.
        if (($item->permissions['canComplete'] ?? false) !== true) {
            return ActionResult::forbidden($this->l->t('This task is not yours to complete.'));
        }

        [$calendarId, $uri] = self::splitItemId($item->providerItemId);

        try {
            $changed = $this->taskService->completeTeamTask($calendarId, $uri);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][Tasks] Complete failed', [
                'calendarId' => $calendarId, 'error' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
            return ActionResult::failure($this->l->t('The task could not be completed: {error}', [
                'error' => $e->getMessage(),
            ]));
        }

        if (!$changed) {
            return ActionResult::conflict($this->l->t('This task was already completed.'));
        }

        // Somebody asked for this and is waiting on it — tell them, and make
        // their My Work re-read. Nothing is sent when you finish your own task
        // or one of the team's: there is no other party.
        $organiser = (string)($item->metadata['organiserUid'] ?? '');
        if ($organiser !== '' && $organiser !== $userId) {
            $this->notifications->taskCompleted(
                $organiser,
                $userId,
                $item->teamId,
                $item->teamName,
                $item->title,
            );
        }

        // `removed`: the row leaves this queue. It reappears under Completed on
        // the next read, like a finished Deck card.
        return ActionResult::success($this->l->t('Task completed.'), null, true);
    }

    // ---------------------------------------------------------------------
    // Reading the calendar
    // ---------------------------------------------------------------------

    /**
     * VTODO rows on a calendar.
     *
     * apps.md R-2: direct SELECT, as `MeetingWorkProvider` and
     * `TaskService::getTeamTasks()` do. Unlike VEVENTs there is no date column
     * to push a window into — NC does not maintain `firstoccurence` for a
     * VTODO — so the due date is read from the object and the row budget is
     * what bounds the work.
     *
     * `protected` so the tests can hand this provider VTODOs without faking a
     * query builder — the same seam `TeamSpaceReconcileService::teamNames()`
     * exists for. Everything worth testing here is what the provider decides
     * about a task, not how the rows were fetched.
     *
     * @return array<int,array{id:int,uri:string,calendardata:string}>
     */
    protected function vtodoRows(int $calendarId, int $budget, string $onlyUri = ''): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('co.id', 'co.uri', 'co.calendardata')
            ->from('calendarobjects', 'co')
            ->where($qb->expr()->eq('co.calendarid',
                $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('co.componenttype',
                $qb->createNamedParameter('VTODO')))
            ->andWhere($qb->expr()->notLike('co.uri',
                $qb->createNamedParameter('%-deleted.ics')))
            ->setMaxResults(max(1, $budget));

        if ($onlyUri !== '') {
            $qb->andWhere($qb->expr()->eq('co.uri', $qb->createNamedParameter($onlyUri)));
        }

        $res  = $qb->executeQuery();
        $rows = [];
        while ($row = $res->fetch()) {
            $rows[] = $row;
        }
        $res->closeCursor();

        return $rows;
    }

    // ---------------------------------------------------------------------
    // Item construction
    // ---------------------------------------------------------------------

    /**
     * @param array{id:int,teamId:string,name:string,owner:string,slug:string} $calendar
     * @param array{id:int,uri:string,calendardata:string} $row
     */
    private function buildItem(WorkQuery $query, array $calendar, array $row): ?WorkItem {
        $parsed = $this->parse($row);
        if ($parsed === null) {
            return null;
        }

        $me          = $query->userId;
        $isMine      = $parsed['assigneeUid'] !== null
            ? $parsed['assigneeUid'] === $me
            : $this->identity->propertyIsUser($parsed['attendee'], $me);
        $isOrganiser = $parsed['organiserUid'] !== null
            ? $parsed['organiserUid'] === $me
            : $this->identity->propertyIsUser($parsed['organiser'], $me);
        $hasAssignee = $parsed['attendee'] !== null;

        // Declined is the one PARTSTAT that is read back. See TaskService.
        if ($isMine && $parsed['partstat'] === 'DECLINED') {
            return null;
        }

        // Somebody else's task that you did not ask for.
        if ($hasAssignee && !$isMine && !$isOrganiser) {
            return null;
        }

        $dueAt = $parsed['dueAt'];

        [$category, $status, $priority, $reason, $canComplete] = $this->classify(
            $query, $parsed, $isMine, $isOrganiser, $hasAssignee, $dueAt,
        );
        if ($category === null) {
            return null;
        }

        $taskUrl = $this->taskUrl($calendar, $row, $me);

        return WorkItem::make([
            'providerId'     => self::ID,
            'providerItemId' => self::itemId((int)$calendar['id'], (string)$row['uri']),
            'teamId'         => $calendar['teamId'],
            'teamName'       => $query->teamName((string)$calendar['teamId']),
            'category'       => $category,
            'title'          => $parsed['title'],
            // Where it lives, the way every other provider's second line reads.
            'subtitle'       => (string)$calendar['name'],
            'resourceType'   => self::RESOURCE_TYPE,
            'resourceId'     => (string)$row['uri'],
            'resourceUrl'    => $taskUrl,
            // The Tasks app is where a task is edited; TeamHub has no task
            // view to host, so this is an honest hand-off rather than a tab.
            'openTarget'     => OpenTarget::external(),
            'priority'       => $priority,
            'status'         => $status,
            'reason'         => $reason,
            'createdAt'      => $parsed['createdAt'],
            'updatedAt'      => $parsed['modifiedAt'],
            'dueAt'          => $dueAt,
            'completedAt'    => $parsed['completedAt'],
            'assignee'       => $parsed['assigneeUid'] !== null ? [
                'uid'         => $parsed['assigneeUid'],
                'displayName' => $parsed['assigneeName'] !== ''
                    ? $parsed['assigneeName']
                    : $this->identity->displayNameFor($parsed['assigneeUid']),
            ] : null,
            'waitingFor'     => $category === Category::WAITING_FOR_OTHERS && $parsed['assigneeUid'] !== null
                ? [
                    'type'        => 'user',
                    'id'          => $parsed['assigneeUid'],
                    'displayName' => $parsed['assigneeName'] !== ''
                        ? $parsed['assigneeName']
                        : $this->identity->displayNameFor($parsed['assigneeUid']),
                ]
                : null,
            'availableActions' => [],
            'metadata'       => array_merge([
                'calendarId'   => (int)$calendar['id'],
                'calendarName' => (string)$calendar['name'],
                'taskUri'      => (string)$row['uri'],
                'organiserUid' => $parsed['organiserUid'],
                'assigneeUid'  => $parsed['assigneeUid'],
                // A task nobody was given is the team's, so the shared
                // lead-time rule leaves it in Upcoming instead of promoting it
                // to Action required as its date approaches — the same mark a
                // team diary entry carries (`MyWorkService::finaliseCategory`).
                //
                // Not because it does not matter: because nobody was asked
                // personally, and promoting it would put the same demand in
                // every member's queue at once. It is still *marked* late —
                // `finaliseCategory` sets `overdue` whatever this says — so an
                // overdue team task reads as overdue without claiming to be
                // one person's problem.
                'informational' => !$hasAssignee,
            ], $this->workflow($parsed, $isMine, $isOrganiser, $hasAssignee)),
            'permissions'    => [
                'canOpen'     => true,
                'canComplete' => $canComplete,
            ],
        ]);
    }

    /**
     * Category, status, priority, the row's sentence, and whether this viewer
     * may complete it. A null category means the task earns no row.
     *
     * @param array<string,mixed> $parsed
     * @return array{0:?string,1:string,2:string,3:string,4:bool}
     */
    private function classify(
        WorkQuery $query,
        array $parsed,
        bool $isMine,
        bool $isOrganiser,
        bool $hasAssignee,
        ?int $dueAt,
    ): array {
        // Finished: a record for the window, for whoever it concerned.
        if ($parsed['isComplete']) {
            $completedAt = $parsed['completedAt'];
            if ($completedAt !== null && $completedAt < $query->completedSince()) {
                return [null, '', '', '', false];
            }
            return [
                Category::COMPLETED,
                self::STATUS_DONE,
                Priority::LOW,
                $isMine ? $this->l->t('You completed this') : $this->l->t('This task is done'),
                false,
            ];
        }

        // Assigned to a colleague, by you.
        if ($hasAssignee && !$isMine) {
            return [
                Category::WAITING_FOR_OTHERS,
                self::STATUS_DELEGATED,
                $dueAt !== null && $dueAt < $query->now ? Priority::HIGH : Priority::NORMAL,
                $this->l->t('You asked somebody else to do this'),
                false,
            ];
        }

        // Yours.
        if ($isMine) {
            if ($dueAt !== null && $dueAt < $query->now) {
                return [
                    Category::ACTION_REQUIRED,
                    self::STATUS_OVERDUE,
                    Priority::URGENT,
                    $isOrganiser
                        ? $this->l->t('Your own task, overdue')
                        : $this->l->t('Assigned to you, overdue'),
                    true,
                ];
            }
            // Beyond the horizon is "not yet", not "no work" — but an undated
            // task has no horizon to be beyond, so it stays. See the class
            // docblock on why undated tasks are not treated as backlog.
            if ($dueAt !== null && $dueAt > $query->dueHorizon()) {
                return [null, '', '', '', false];
            }
            return [
                Category::UPCOMING,
                self::STATUS_ASSIGNED,
                Priority::NORMAL,
                $isOrganiser
                    ? $this->l->t('Your own task')
                    : $this->l->t('Assigned to you'),
                true,
            ];
        }

        // Nobody's in particular: the team's own task list.
        if ($dueAt !== null && $dueAt > $query->dueHorizon()) {
            return [null, '', '', '', false];
        }
        return [
            Category::UPCOMING,
            self::STATUS_TEAM,
            Priority::NORMAL,
            $isOrganiser
                ? $this->l->t('You created this for the team')
                : $this->l->t('On your team’s task list'),
            true,
        ];
    }

    /**
     * The two-step workflow an assigned task is, for the row's tracker.
     *
     * Only assigned tasks get one: a task for the whole team has no second
     * party, and a tracker that reads *Step 1 of 1* says nothing. Both parties
     * compute the same states from the same object, which is what the
     * `/mywork-workflows` rule asks for.
     *
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    private function workflow(array $parsed, bool $isMine, bool $isOrganiser, bool $hasAssignee): array {
        if (!$hasAssignee) {
            return [];
        }

        $organiserName = $parsed['organiserUid'] !== null
            ? $this->identity->displayNameFor($parsed['organiserUid'])
            : null;
        $assigneeName = $parsed['assigneeUid'] !== null
            ? ($parsed['assigneeName'] !== ''
                ? $parsed['assigneeName']
                : $this->identity->displayNameFor($parsed['assigneeUid']))
            : null;

        $done = (bool)$parsed['isComplete'];

        return [
            'workflow' => [
                'steps' => [
                    [
                        'label' => $isOrganiser && $isMine
                            ? $this->l->t('You gave yourself this task')
                            : $this->l->t('Task assigned'),
                        'state' => 'done',
                        'actor' => $organiserName,
                        'at'    => $parsed['createdAt'],
                    ],
                    [
                        'label' => $this->l->t('Assignee completes it'),
                        'state' => $done ? 'done' : 'current',
                        'actor' => $done ? $assigneeName : null,
                        'at'    => $done ? $parsed['completedAt'] : null,
                    ],
                ],
                'current' => 2,
            ],
        ];
    }

    /**
     * Parse one VTODO row into the facts this provider decides on.
     *
     * @param array{id:int,uri:string,calendardata:string} $row
     * @return array<string,mixed>|null
     */
    private function parse(array $row): ?array {
        // CalendarBlob: Postgres hands this back as a stream (v4.11.16).
        $data = CalendarBlob::read($row['calendardata'] ?? null);
        if ($data === '') {
            return null;
        }

        $vcalendar = \Sabre\VObject\Reader::read($data, \Sabre\VObject\Reader::OPTION_FORGIVING);
        $vtodo     = $vcalendar->VTODO;
        if ($vtodo === null) {
            return null;
        }

        // A cancelled task is not work anybody owes. A completed one is a
        // record, so it is kept and classified below.
        if (strtoupper((string)($vtodo->STATUS ?? '')) === 'CANCELLED') {
            return null;
        }

        $organiser = $vtodo->ORGANIZER ?? null;
        $attendee  = null;
        $partstat  = null;
        if (isset($vtodo->ATTENDEE)) {
            foreach ($vtodo->ATTENDEE as $candidate) {
                $cutype = strtoupper((string)($candidate['CUTYPE'] ?? 'INDIVIDUAL'));
                if ($cutype === 'ROOM' || $cutype === 'RESOURCE') {
                    continue;
                }
                $attendee = $candidate;
                $partstat = strtoupper((string)($candidate['PARTSTAT'] ?? 'NEEDS-ACTION'));
                break;
            }
        }

        return [
            'title'        => $this->titleOf($vtodo),
            'organiser'    => $organiser,
            'attendee'     => $attendee,
            'organiserUid' => $this->identity->uidFromProperty($organiser),
            'assigneeUid'  => $this->identity->uidFromProperty($attendee),
            'assigneeName' => $attendee !== null ? (string)($attendee['CN'] ?? '') : '',
            'partstat'     => $partstat,
            'isComplete'   => TaskService::vtodoIsComplete($vtodo),
            'dueAt'        => $this->timestampOf($vtodo, ['DUE', 'DTSTART']),
            'createdAt'    => $this->timestampOf($vtodo, ['CREATED']),
            'modifiedAt'   => $this->timestampOf($vtodo, ['LAST-MODIFIED']),
            'completedAt'  => $this->timestampOf($vtodo, ['COMPLETED']),
        ];
    }

    /** @param mixed $vtodo a Sabre VObject VTODO component */
    private function titleOf(mixed $vtodo): string {
        $title = trim((string)($vtodo->SUMMARY ?? ''));
        if ($title !== '') {
            return $title;
        }
        $title = trim((string)($vtodo->DESCRIPTION ?? ''));
        return $title !== '' ? $title : $this->l->t('Untitled task');
    }

    /**
     * The first of these properties that carries a usable date.
     *
     * @param mixed    $vtodo a Sabre VObject VTODO component
     * @param string[] $props
     */
    private function timestampOf(mixed $vtodo, array $props): ?int {
        foreach ($props as $prop) {
            if (!isset($vtodo->$prop)) {
                continue;
            }
            try {
                $ts = $vtodo->$prop->getDateTime()->getTimestamp();
                if ($ts > 0) {
                    return $ts;
                }
            } catch (\Throwable) {
                // Malformed date — try the next property.
            }
        }
        return null;
    }

    /**
     * The task's URL in the Tasks app:
     * `/apps/tasks/calendars/{calendarId}/tasks/{objectUri}`.
     *
     * Two things here are easy to get wrong, and v4.11.16 got both (Justin,
     * 2026-10-03 — the row opened the Tasks app on nothing).
     *
     * **No `#`.** The Tasks app routes on the path, not on a fragment.
     *
     * **The calendar segment is encoded twice.** The app's calendar `id` is
     * `url.split("/").slice(-2,-1)[0]` — the basename of the CalDAV href,
     * *still percent-encoded*. A team calendar shared by somebody whose uid
     * contains a space therefore has an id with a literal `%20` in it
     * (`…_shared_by_Lieke%20Adm`), and putting that in a URL path means
     * encoding the `%` as well, giving `%2520`. Encoding once produces a
     * calendar id the app has never heard of.
     *
     * The object segment is encoded once, with `@` left as itself: it is a
     * legal path character (RFC 3986 `pchar`) and the app's own links carry it
     * literally, so the link reads like one the app wrote.
     *
     * @param array{id:int,teamId:string,name:string,owner:string,slug:string} $calendar
     * @param array{id:int,uri:string,calendardata:string} $row
     */
    private function taskUrl(array $calendar, array $row, string $viewerUid): string {
        $calendarUri = TeamCalendarLocator::uriFor($calendar, $viewerUid);
        $objectUri   = (string)$row['uri'];
        if ($calendarUri === '' || $objectUri === '') {
            return '/apps/tasks';
        }

        return '/apps/tasks/calendars/' . self::pathSegment(rawurlencode($calendarUri))
            . '/tasks/' . self::pathSegment($objectUri);
    }

    /**
     * Percent-encode one path segment, keeping `@` literal.
     *
     * `rawurlencode()` escapes `@` although RFC 3986 allows it unescaped in a
     * path. Either form decodes to the same value, so this is about the link
     * looking like the application's own rather than about correctness.
     */
    private static function pathSegment(string $value): string {
        return str_replace('%40', '@', rawurlencode($value));
    }

    // ---------------------------------------------------------------------
    // Item ids
    // ---------------------------------------------------------------------

    /**
     * `{calendarId}:{objectUri}`.
     *
     * The uri carries no colon (it is a hex uid plus `.ics`), and the split
     * below is limited to two parts anyway, so a uri that somehow did would
     * still round-trip.
     */
    public static function itemId(int $calendarId, string $uri): string {
        return $calendarId . ':' . $uri;
    }

    /** @return array{0:int,1:string} */
    public static function splitItemId(string $providerItemId): array {
        [$calendarId, $uri] = array_pad(explode(':', $providerItemId, 2), 2, '');
        return [(int)$calendarId, (string)$uri];
    }
}
