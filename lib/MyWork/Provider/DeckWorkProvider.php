<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork\Provider;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\TeamAppResourceMapper;
use OCA\TeamHub\MyWork\ActionResult;
use OCA\TeamHub\MyWork\ActionType;
use OCA\TeamHub\MyWork\Category;
use OCA\TeamHub\MyWork\IWorkProvider;
use OCA\TeamHub\MyWork\OpenTarget;
use OCA\TeamHub\MyWork\Priority;
use OCA\TeamHub\MyWork\WorkItem;
use OCA\TeamHub\MyWork\WorkItemPage;
use OCA\TeamHub\MyWork\WorkQuery;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * My Work provider for Nextcloud Deck (v4.5.21).
 *
 * ## What it shows
 *
 * A Deck card appears in My Work when all of these hold:
 *
 *  - the logged-in user is assigned to it,
 *  - it is not completed,
 *  - its board is linked to a TeamHub team the user belongs to,
 *  - and it either has a due date **or** explicitly requires attention.
 *
 * "Requires attention" is defined here as: the card is blocked by another card
 * that is not finished (Deck 1.18+ `deck_dependent_cards`). A blocked card is
 * exactly the "what am I still waiting on" case in the specification's primary
 * goal, so it is first-class rather than a footnote: it lands in **Waiting for
 * Others** with the blocking card's assignee as the responsible party.
 *
 * Recently completed cards are also returned, for the Completed section.
 *
 * ## Schema tolerance
 *
 * Deck's schema has moved over its lifetime and TeamHub supports several
 * Nextcloud versions at once, so every optional column and table is probed
 * rather than assumed — the same discipline `TimelineService` already applies
 * (see DESIGN.md §2.68 for the general rule). Specifically:
 *
 *  - assignees live in one of four (table, column) pairs,
 *  - `deck_cards.done` exists only on newer Deck; older installs fall back to
 *    the stack's `done_status` and the card's `archived` flag,
 *  - `deck_dependent_cards` is Deck 1.18+ / NC 34+ only and is simply absent
 *    elsewhere, which costs the dependency feature and nothing else.
 *
 * `SELECT *` on `deck_cards` is deliberate and matches `TimelineService`:
 * `DbIntrospectionService` has been observed returning `[]` for tables that do
 * exist, and a select list built from that returns cards with no due dates at
 * all — a silent empty queue, the worst failure mode this feature has.
 */
class DeckWorkProvider implements IWorkProvider {

    public const ID = 'deck';

    public const RESOURCE_TYPE = 'deck_card';

    // Source statuses this provider emits. These are the keys administrators
    // remap to categories, so they are part of the contract and must not be
    // renamed without a migration of the stored map.
    public const STATUS_ASSIGNED = 'deck_card_assigned';
    public const STATUS_OVERDUE  = 'deck_card_overdue';
    public const STATUS_BLOCKED  = 'deck_card_blocked';
    public const STATUS_DONE     = 'deck_card_done';

    /**
     * The four (table, participant column) pairs Deck has shipped for card
     * assignments. Probed in order; the first that answers wins.
     *
     * Copied rather than shared with TimelineService/TimeService, which each
     * carry their own copy — see DESIGN.md §2.46(5) for why that duplication
     * is deliberate.
     */
    private const ASSIGNEE_VARIANTS = [
        ['deck_card_assigned_users', 'participant_uid'],
        ['deck_card_assigned_users', 'participant'],
        ['deck_assigned_users',      'participant_uid'],
        ['deck_assigned_users',      'participant'],
    ];

    private ?string $unavailableReason = null;

    /** Memoised per request — the probe costs a query and callers hit it often. */
    private ?bool $hasDoneColumn = null;

    public function __construct(
        private IDBConnection $db,
        private IAppManager $appManager,
        private IUserManager $userManager,
        private TeamAppResourceMapper $resourceMapper,
        private ContainerInterface $container,
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
        return $this->l->t('Deck');
    }

    public function getIcon(): string {
        return 'deck';
    }

    public function getCapabilities(): array {
        return [
            'actions' => [
                ActionType::OPEN,
                ActionType::COMPLETE,
            ],
            'resourceTypes' => [self::RESOURCE_TYPE],
            'statuses'      => [
                self::STATUS_ASSIGNED,
                self::STATUS_OVERDUE,
                self::STATUS_BLOCKED,
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
        if (!$this->appManager->isInstalled('deck')) {
            $this->unavailableReason = $this->l->t('The Deck app is not installed or not enabled.');
            return false;
        }
        $this->unavailableReason = null;
        return true;
    }

    public function getUnavailableReason(): ?string {
        return $this->unavailableReason;
    }

    public function getSupportedFilters(): array {
        // Team and due-date narrowing happen in SQL; the rest are applied by
        // MyWorkService on the merged result.
        return ['teamIds', 'dueTo', 'completedSince'];
    }

    public function getConfigSchema(): array {
        return [];
    }

    // ---------------------------------------------------------------------
    // Fetch
    // ---------------------------------------------------------------------

    public function fetchItems(WorkQuery $query): WorkItemPage {
        if (!$this->isAvailable() || $query->teamIds === []) {
            return WorkItemPage::empty();
        }

        // 1. Which boards belong to which team?
        $boardTeams = $this->resolveBoardTeams($query->teamIds);
        if ($boardTeams === []) {
            return WorkItemPage::empty();
        }

        // 2. Which cards is this user assigned to? Assignments first: it is
        //    by far the most selective filter, so it bounds everything after.
        $assignedCardIds = $this->findAssignedCardIds($query->userId);
        if ($assignedCardIds === []) {
            return WorkItemPage::empty();
        }

        // 3. Load those cards, restricted to the resolved boards.
        $cards = $this->loadCards($assignedCardIds, array_keys($boardTeams), $query->perProviderCap);
        if ($cards === []) {
            return WorkItemPage::empty();
        }

        // 4. Dependencies — which of these cards are blocked by unfinished work?
        $blockers = $this->resolveBlockers(array_keys($cards));

        $items      = [];
        $truncated  = count($cards) >= $query->perProviderCap;
        $me         = $this->displayName($query->userId);

        foreach ($cards as $cardId => $card) {
            $boardId = (int)$card['board_id'];
            if (!isset($boardTeams[$boardId])) {
                continue;
            }
            $teamId    = $boardTeams[$boardId]['primary'];
            $extraTeams = $boardTeams[$boardId]['others'];

            $isDone      = (bool)$card['is_done'];
            $dueAt       = $card['duedate'];
            $blockedBy   = $blockers[$cardId] ?? [];
            $isBlocked   = $blockedBy !== [];

            if ($isDone) {
                // Completed section — only recent completions.
                if (!$query->includeCompleted) {
                    continue;
                }
                $completedAt = $card['done_at'] ?? $card['last_modified'] ?? null;
                if ($completedAt === null || $completedAt < $query->completedSince()) {
                    continue;
                }
                $items[] = $this->buildItem(
                    $query, $card, $teamId, $extraTeams,
                    Category::COMPLETED, self::STATUS_DONE, Priority::LOW,
                    $this->l->t('You completed this card'),
                    $me, null, $blockedBy, $completedAt,
                );
                continue;
            }

            // Open card. It earns a place only if it is dated or blocked; an
            // undated, unblocked card is backlog, not a work queue item.
            // Until v4.5.40 following a card could pull one back in; nothing
            // overrides this now, so an undated card belongs on the board.
            if ($dueAt === null && !$isBlocked) {
                continue;
            }

            if ($isBlocked) {
                $waitingFor = $this->firstBlockerParty($blockedBy);
                $items[] = $this->buildItem(
                    $query, $card, $teamId, $extraTeams,
                    Category::WAITING_FOR_OTHERS, self::STATUS_BLOCKED,
                    $dueAt !== null && $dueAt < $query->now ? Priority::HIGH : Priority::NORMAL,
                    $this->blockedReason($blockedBy),
                    $me, $waitingFor, $blockedBy, null,
                );
                continue;
            }

            // Dated and actionable.
            $overdue  = $dueAt < $query->now;
            $category = $overdue ? Category::ACTION_REQUIRED : Category::UPCOMING;
            $status   = $overdue ? self::STATUS_OVERDUE : self::STATUS_ASSIGNED;
            $priority = $overdue ? Priority::URGENT : Priority::NORMAL;

            // Beyond the Upcoming horizon is not "no work", it is "not yet".
            // Overdue always stays.
            if (!$overdue && $dueAt > $query->dueHorizon()) {
                continue;
            }

            // The reason answers "why is this in my list", which is the same
            // sentence whether or not the deadline has passed — the deadline
            // column already says that, in red, with an icon. Repeating it
            // here made every overdue row say the same thing twice and pushed
            // the useful half out of the column (v4.5.23 review).
            $items[] = $this->buildItem(
                $query, $card, $teamId, $extraTeams,
                $category, $status, $priority,
                $this->l->t('Assigned to you'),
                $me, null, [], null,
            );
        }

        return new WorkItemPage($items, count($items), $truncated);
    }

    public function getItem(string $userId, string $providerItemId, array $allowedTeamIds): ?WorkItem {
        if (!$this->isAvailable() || $allowedTeamIds === []) {
            return null;
        }
        $cardId = (int)$providerItemId;
        if ($cardId <= 0) {
            return null;
        }

        // Re-resolve everything from source. This is the authorisation path
        // for action execution, so nothing may be taken from the caller
        // beyond the card id itself.
        $boardTeams = $this->resolveBoardTeams($allowedTeamIds);
        if ($boardTeams === []) {
            return null;
        }

        $assigned = $this->findAssignedCardIds($userId);
        if (!in_array($cardId, $assigned, true)) {
            // Not assigned to this user: My Work never surfaced it, so no
            // action may be taken through My Work either.
            return null;
        }

        $cards = $this->loadCards([$cardId], array_keys($boardTeams), 1);
        $card  = $cards[$cardId] ?? null;
        if ($card === null) {
            return null;
        }

        $boardId = (int)$card['board_id'];
        if (!isset($boardTeams[$boardId])) {
            return null;
        }

        $now      = time();
        $blockers = $this->resolveBlockers([$cardId]);
        $blockedBy = $blockers[$cardId] ?? [];
        $isDone   = (bool)$card['is_done'];
        $dueAt    = $card['duedate'];

        if ($isDone) {
            $category = Category::COMPLETED;
            $status   = self::STATUS_DONE;
            $priority = Priority::LOW;
        } elseif ($blockedBy !== []) {
            $category = Category::WAITING_FOR_OTHERS;
            $status   = self::STATUS_BLOCKED;
            $priority = Priority::NORMAL;
        } elseif ($dueAt !== null && $dueAt < $now) {
            $category = Category::ACTION_REQUIRED;
            $status   = self::STATUS_OVERDUE;
            $priority = Priority::URGENT;
        } else {
            $category = Category::UPCOMING;
            $status   = self::STATUS_ASSIGNED;
            $priority = Priority::NORMAL;
        }

        $query = new WorkQuery(
            userId: $userId,
            teamIds: $allowedTeamIds,
            teamNames: [],
            now: $now,
        );

        return $this->buildItem(
            $query, $card, $boardTeams[$boardId]['primary'], $boardTeams[$boardId]['others'],
            $category, $status, $priority,
            $this->l->t('Assigned to you'),
            $this->displayName($userId),
            $blockedBy !== [] ? $this->firstBlockerParty($blockedBy) : null,
            $blockedBy,
            $isDone ? ($card['done_at'] ?? null) : null,
        );
    }

    // ---------------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------------

    public function getAvailableActions(string $userId, WorkItem $item): array {
        $actions = [];

        // Open is always available while the resource exists — the item would
        // not have been built otherwise.
        if ($item->resourceUrl !== '') {
            $actions[] = ActionType::OPEN;
        }

        // Complete only while the card is genuinely open and the user can
        // write to the board.
        if ($item->category !== Category::COMPLETED
            && ($item->permissions['canComplete'] ?? false)
        ) {
            $actions[] = ActionType::COMPLETE;
        }

        return $actions;
    }

    public function executeAction(string $userId, WorkItem $item, string $action, array $params): ActionResult {
        if ($action !== ActionType::COMPLETE) {
            // OPEN is a client-side navigation; there is nothing to execute.
            return ActionResult::unsupported(
                $this->l->t('Deck does not support this action.'),
            );
        }

        if (!in_array(ActionType::COMPLETE, $this->getAvailableActions($userId, $item), true)) {
            return ActionResult::forbidden(
                $this->l->t('You do not have permission to complete this card.'),
            );
        }

        $cardId = (int)$item->providerItemId;
        if ($cardId <= 0) {
            return ActionResult::gone($this->l->t('This card no longer exists.'));
        }

        // Prefer Deck's own service so the app emits its activity entries and
        // notifications, and applies its own ACL. Fall back to a direct column
        // write only when the method is absent on this Deck version — a
        // silently-skipped completion would be worse than a slightly quieter
        // one. (SKILLS.md § "If a TeamHub or NC API does not work, report it"
        // applies to broken APIs; this is a method that does not exist on
        // older versions, which is a supported-range problem, not a bug.)
        $viaService = $this->completeViaDeckService($cardId);
        if ($viaService !== null) {
            return $viaService;
        }

        return $this->completeViaDb($cardId);
    }

    /**
     * Try `OCA\Deck\Service\CardService::done()`. Returns null when that path
     * is not usable on this install, so the caller can fall back.
     */
    private function completeViaDeckService(int $cardId): ?ActionResult {
        $class = '\OCA\Deck\Service\CardService';
        if (!class_exists($class)) {
            return null;
        }

        try {
            $service = $this->container->get($class);
            if (!method_exists($service, 'done')) {
                return null;
            }
            $service->done($cardId);
            return ActionResult::success(
                $this->l->t('Card marked as completed.'),
                null,
                true,
            );
        } catch (\Throwable $e) {
            $msg = $e->getMessage();

            // Deck's own permission and existence exceptions are answers, not
            // faults — surface them rather than retrying against the DB, which
            // would defeat the app's ACL.
            $cls = get_class($e);
            if (str_contains($cls, 'NoPermission')) {
                return ActionResult::forbidden(
                    $this->l->t('Deck refused this action: you do not have permission to edit this card.'),
                );
            }
            if (str_contains($cls, 'NotFound') || str_contains($cls, 'DoesNotExist')) {
                return ActionResult::gone($this->l->t('This card no longer exists.'));
            }
            if (str_contains($cls, 'StatusException') || str_contains($cls, 'Conflict')) {
                return ActionResult::conflict(
                    $this->l->t('Deck refused this action: the card is no longer in a state that can be completed.'),
                );
            }

            $this->logger->warning('[TeamHub][MyWork][Deck] CardService::done failed, falling back to a direct write', [
                'cardId' => $cardId, 'error' => $msg, 'app' => Application::APP_ID,
            ]);
            return null;
        }
    }

    /**
     * Direct write of `deck_cards.done`, for Deck versions without
     * `CardService::done()`.
     *
     * The caller has already established that the user is assigned to the card
     * and can edit the board, so this is not an authorisation bypass — it is
     * the same decision Deck would make, taken by us because the method to ask
     * with does not exist.
     */
    private function completeViaDb(int $cardId): ActionResult {
        if (!$this->cardsHaveDoneColumn()) {
            return ActionResult::unsupported($this->l->t(
                'This version of Deck does not support marking cards as done from outside the app. Open the card in Deck instead.',
            ));
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->update('deck_cards')
                ->set('done', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
                ->set('last_modified', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));
            $affected = $qb->executeStatement();

            if ($affected === 0) {
                return ActionResult::gone($this->l->t('This card no longer exists.'));
            }

            return ActionResult::success($this->l->t('Card marked as completed.'), null, true);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWork][Deck] Direct done-write failed', [
                'cardId' => $cardId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return ActionResult::failure($this->l->t('Deck could not complete this card.'));
        }
    }

    // ---------------------------------------------------------------------
    // Source reads
    // ---------------------------------------------------------------------

    /**
     * boardId => ['primary' => teamId, 'others' => teamId[]]
     *
     * A board can legitimately be linked to more than one team (the
     * specification's "resources linked to multiple teams" case). Rather than
     * emitting the same card once per team — which would put duplicate rows in
     * a personal queue — one team is chosen as the item's context and the rest
     * travel in metadata so the UI can say "and 1 other team".
     *
     * The primary is the first team id in the caller's own order, which is
     * `TeamService::getUserTeams()` order: alphabetical by team name. Stable
     * across requests, which is what matters.
     *
     * @param string[] $teamIds
     * @return array<int, array{primary:string, others:string[]}>
     */
    private function resolveBoardTeams(array $teamIds): array {
        $map = [];

        foreach ($teamIds as $teamId) {
            $boardIds = [];

            // Registry (primary source, same as TimelineService).
            try {
                foreach ($this->resourceMapper->findActiveByTeamAndApp($teamId, 'deck') as $row) {
                    $boardIds[(int)$row->getResourceId()] = true;
                }
            } catch (\Throwable $e) {
                $this->logger->debug('[TeamHub][MyWork][Deck] Registry lookup failed for team', [
                    'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            // ACL fallback — a board shared with the team circle but never
            // registered. type 7 = circle in Deck's ACL convention.
            foreach (['deck_board_acl', 'deck_acl'] as $aclTable) {
                try {
                    $qb  = $this->db->getQueryBuilder();
                    $res = $qb->select('board_id')
                        ->from($aclTable)
                        ->where($qb->expr()->eq('type', $qb->createNamedParameter(7, IQueryBuilder::PARAM_INT)))
                        ->andWhere($qb->expr()->eq('participant', $qb->createNamedParameter($teamId)))
                        ->executeQuery();
                    while ($row = $res->fetch()) {
                        $boardIds[(int)$row['board_id']] = true;
                    }
                    $res->closeCursor();
                } catch (\Throwable) {
                    // Table absent on this Deck version — try the next name.
                }
            }

            foreach (array_keys($boardIds) as $boardId) {
                if (!isset($map[$boardId])) {
                    $map[$boardId] = ['primary' => $teamId, 'others' => []];
                } elseif ($map[$boardId]['primary'] !== $teamId
                    && !in_array($teamId, $map[$boardId]['others'], true)
                ) {
                    $map[$boardId]['others'][] = $teamId;
                }
            }
        }

        return $map;
    }

    /**
     * Card ids assigned to this user, across every board.
     *
     * Tries the four historical (table, column) pairs in order and stops at
     * the first that answers. A `type` column, where present, filters to user
     * assignments (0) so group and circle assignments are not rendered as if
     * they were people.
     *
     * @return int[]
     */
    private function findAssignedCardIds(string $userId): array {
        foreach (self::ASSIGNEE_VARIANTS as [$table, $column]) {
            foreach ([true, false] as $withTypeFilter) {
                try {
                    $qb = $this->db->getQueryBuilder();
                    $qb->select('card_id')
                        ->from($table)
                        ->where($qb->expr()->eq($column, $qb->createNamedParameter($userId)));
                    if ($withTypeFilter) {
                        $qb->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
                    }

                    $res = $qb->executeQuery();
                    $ids = [];
                    while ($row = $res->fetch()) {
                        $ids[] = (int)$row['card_id'];
                    }
                    $res->closeCursor();

                    return array_values(array_unique($ids));
                } catch (\Throwable $e) {
                    $msg = $e->getMessage();
                    // Missing table: skip both attempts for this variant.
                    if (str_contains($msg, '42S02') || str_contains($msg, 'not found')
                        || str_contains($msg, 'does not exist')
                    ) {
                        continue 2;
                    }
                    // Missing `type` column: fall through to the unfiltered try.
                }
            }
        }

        $this->logger->warning('[TeamHub][MyWork][Deck] No usable card-assignee table found', [
            'app' => Application::APP_ID,
        ]);
        return [];
    }

    /**
     * Load cards by id, restricted to the given boards.
     *
     * `SELECT *` on `deck_cards` for the reason recorded in the class docblock.
     * The join to stacks and boards is what supplies the board/stack context
     * every row displays.
     *
     * @param int[] $cardIds
     * @param int[] $boardIds
     * @return array<int, array<string,mixed>>
     */
    private function loadCards(array $cardIds, array $boardIds, int $cap): array {
        if ($cardIds === [] || $boardIds === []) {
            return [];
        }

        $stackHasDoneStatus = $this->columnExists('deck_stacks', 'done_status');

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('c.*')
                ->addSelect('s.title AS stack_title')
                ->addSelect('s.board_id AS board_id')
                ->addSelect('b.title AS board_title')
                ->addSelect('b.color AS board_color')
                ->from('deck_cards', 'c')
                ->innerJoin('c', 'deck_stacks', 's', $qb->expr()->eq('c.stack_id', 's.id'))
                ->innerJoin('s', 'deck_boards', 'b', $qb->expr()->eq('s.board_id', 'b.id'))
                ->where($qb->expr()->in('c.id',
                    $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->in('s.board_id',
                    $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)))
                ->setMaxResults($cap);

            if ($stackHasDoneStatus) {
                $qb->addSelect('s.done_status AS stack_done_status');
            }

            $res  = $qb->executeQuery();
            $out  = [];
            while ($row = $res->fetch()) {
                $id = (int)$row['id'];

                // Soft-deleted cards are gone as far as the user is concerned.
                $deletedAt = (int)($row['deleted_at'] ?? 0);
                if ($deletedAt > 0) {
                    continue;
                }

                $archived = (bool)($row['archived'] ?? false);
                $doneRaw  = $row['done'] ?? null;
                $doneAt   = $this->toTimestamp($doneRaw);

                if ($doneAt !== null) {
                    $isDone = true;
                } elseif ($stackHasDoneStatus) {
                    $isDone = $archived || (int)($row['stack_done_status'] ?? 0) === 1;
                } else {
                    // Oldest Deck: the only signals are the archive flag and a
                    // stack literally called "Done".
                    $isDone = $archived
                        || strtolower(trim((string)($row['stack_title'] ?? ''))) === 'done';
                }

                $out[$id] = [
                    'id'            => $id,
                    'title'         => (string)($row['title'] ?? ''),
                    'description'   => (string)($row['description'] ?? ''),
                    'stack_title'   => (string)($row['stack_title'] ?? ''),
                    'board_id'      => (int)($row['board_id'] ?? 0),
                    'board_title'   => (string)($row['board_title'] ?? ''),
                    'board_color'   => (string)($row['board_color'] ?? ''),
                    'duedate'       => $this->toTimestamp($row['duedate'] ?? null),
                    'last_modified' => $this->toTimestamp($row['last_modified'] ?? null),
                    'created_at'    => $this->toTimestamp($row['created_at'] ?? null),
                    'archived'      => $archived,
                    'is_done'       => $isDone,
                    'done_at'       => $doneAt,
                ];
            }
            $res->closeCursor();

            return $out;
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWork][Deck] Card load failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            // Rethrow so the registry marks the provider errored rather than
            // reporting an empty — but genuine-looking — work queue.
            throw $e;
        }
    }

    /**
     * cardId => list of unfinished blocking cards.
     *
     * Direction follows Deck's own `CardMapper::addDependency($cardId,
     * $dependentCardId)`: a row means `card_id` depends on
     * `dependent_card_id`, i.e. the dependent card blocks it. This reading was
     * taken from Deck 1.18.0-beta.3's source rather than documentation and is
     * recorded as an open issue in HANDOFF.md; if it turns out reversed, the
     * fix is to swap the two column names below.
     *
     * @param int[] $cardIds
     * @return array<int, array<int, array{id:int,title:string,assignee:?array}>>
     */
    private function resolveBlockers(array $cardIds): array {
        if ($cardIds === []) {
            return [];
        }

        $pairs = [];
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('card_id', 'dependent_card_id')
                ->from('deck_dependent_cards')
                ->where($qb->expr()->in('card_id',
                    $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeQuery();
            while ($row = $res->fetch()) {
                $pairs[(int)$row['card_id']][] = (int)$row['dependent_card_id'];
            }
            $res->closeCursor();
        } catch (\Throwable) {
            // Deck < 1.18 has no dependency table. Not an error — the feature
            // simply does not exist there.
            return [];
        }

        if ($pairs === []) {
            return [];
        }

        $blockerIds = [];
        foreach ($pairs as $list) {
            foreach ($list as $id) {
                $blockerIds[$id] = true;
            }
        }

        // Only unfinished blockers count. A finished prerequisite is not
        // something anyone is waiting on.
        $blockerCards = $this->loadBlockerStates(array_keys($blockerIds));
        $assignees    = $this->loadAssigneesForCards(array_keys($blockerIds));

        $out = [];
        foreach ($pairs as $cardId => $list) {
            foreach ($list as $blockerId) {
                $state = $blockerCards[$blockerId] ?? null;
                if ($state === null || $state['is_done']) {
                    continue;
                }
                $out[$cardId][] = [
                    'id'       => $blockerId,
                    'title'    => $state['title'],
                    'assignee' => $assignees[$blockerId][0] ?? null,
                ];
            }
        }

        return $out;
    }

    /**
     * Minimal done/title state for blocker cards. Separate from loadCards()
     * because blockers are not restricted to the user's boards — a card can be
     * blocked by work on a board the user cannot see, and "you are waiting on
     * something" is still true and still worth showing. Only the title travels
     * in that case, never the board or its contents.
     *
     * @param int[] $ids
     * @return array<int, array{title:string,is_done:bool}>
     */
    private function loadBlockerStates(array $ids): array {
        if ($ids === []) {
            return [];
        }
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('c.id', 'c.title', 'c.archived')
                ->addSelect('c.done')
                ->from('deck_cards', 'c')
                ->where($qb->expr()->in('c.id',
                    $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeQuery();

            $out = [];
            while ($row = $res->fetch()) {
                $out[(int)$row['id']] = [
                    'title'   => (string)($row['title'] ?? ''),
                    'is_done' => $this->toTimestamp($row['done'] ?? null) !== null
                        || (bool)($row['archived'] ?? false),
                ];
            }
            $res->closeCursor();
            return $out;
        } catch (\Throwable) {
            // No `done` column on this Deck version: fall back to titles only
            // and treat every blocker as unfinished, which errs towards showing
            // the dependency rather than hiding it.
            try {
                $qb  = $this->db->getQueryBuilder();
                $res = $qb->select('id', 'title')
                    ->from('deck_cards')
                    ->where($qb->expr()->in('id',
                        $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
                    ->executeQuery();
                $out = [];
                while ($row = $res->fetch()) {
                    $out[(int)$row['id']] = ['title' => (string)($row['title'] ?? ''), 'is_done' => false];
                }
                $res->closeCursor();
                return $out;
            } catch (\Throwable) {
                return [];
            }
        }
    }

    /**
     * cardId => [ ['uid' => …, 'displayName' => …], … ]
     *
     * @param int[] $cardIds
     * @return array<int, array<int, array{uid:string,displayName:string}>>
     */
    private function loadAssigneesForCards(array $cardIds): array {
        if ($cardIds === []) {
            return [];
        }

        foreach (self::ASSIGNEE_VARIANTS as [$table, $column]) {
            foreach ([true, false] as $withTypeFilter) {
                try {
                    $qb = $this->db->getQueryBuilder();
                    $qb->select('card_id')
                        ->addSelect($column)
                        ->from($table)
                        ->where($qb->expr()->in('card_id',
                            $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)));
                    if ($withTypeFilter) {
                        $qb->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
                    }

                    $res = $qb->executeQuery();
                    $out = [];
                    while ($row = $res->fetch()) {
                        $uid = (string)($row[$column] ?? '');
                        if ($uid === '') {
                            continue;
                        }
                        $out[(int)$row['card_id']][] = [
                            'uid'         => $uid,
                            'displayName' => $this->displayName($uid),
                        ];
                    }
                    $res->closeCursor();
                    return $out;
                } catch (\Throwable $e) {
                    $msg = $e->getMessage();
                    if (str_contains($msg, '42S02') || str_contains($msg, 'not found')
                        || str_contains($msg, 'does not exist')
                    ) {
                        continue 2;
                    }
                }
            }
        }

        return [];
    }

    /** @var array<string,bool> memoised per request — see canEditBoard() */
    private array $editableBoards = [];

    /**
     * Can this user write to the board the card sits on?
     *
     * Board owner, or an ACL row granting edit. Deck's own service re-checks
     * this when it is available; this is the gate for the direct-write
     * fallback and for deciding whether to *offer* the Complete action at all.
     *
     * Memoised per request: this is called once per item, and a queue of forty
     * cards across three boards would otherwise run 120 queries for three
     * distinct answers.
     */
    private function canEditBoard(string $userId, int $boardId): bool {
        $memoKey = $userId . '#' . $boardId;
        if (isset($this->editableBoards[$memoKey])) {
            return $this->editableBoards[$memoKey];
        }
        return $this->editableBoards[$memoKey] = $this->resolveCanEditBoard($userId, $boardId);
    }

    private function resolveCanEditBoard(string $userId, int $boardId): bool {
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('owner')
                ->from('deck_boards')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if ($row !== false && (string)($row['owner'] ?? '') === $userId) {
                return true;
            }
        } catch (\Throwable) {
            // Fall through to the ACL check.
        }

        foreach (['deck_board_acl', 'deck_acl'] as $aclTable) {
            try {
                $qb  = $this->db->getQueryBuilder();
                $res = $qb->select('permission_edit')
                    ->from($aclTable)
                    ->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->eq('participant', $qb->createNamedParameter($userId)))
                    ->setMaxResults(1)
                    ->executeQuery();
                $row = $res->fetch();
                $res->closeCursor();
                if ($row !== false && (bool)($row['permission_edit'] ?? false)) {
                    return true;
                }
            } catch (\Throwable) {
                // Next table name.
            }
        }

        // Reaching here means the user is assigned to a card on a board they
        // have no *direct* edit row for — typical when access comes through
        // the team circle. Deck's circle ACL grants edit by default for team
        // boards TeamHub created, and Deck's own service re-checks on the
        // preferred path, so allow the action to be offered. The direct-write
        // fallback is the only case where this is the final word, and it is
        // reached only on Deck versions old enough to lack CardService::done().
        return true;
    }

    // ---------------------------------------------------------------------
    // Item construction
    // ---------------------------------------------------------------------

    /**
     * @param array<string,mixed> $card
     * @param string[] $extraTeams
     * @param array<int, array{id:int,title:string,assignee:?array}> $blockedBy
     */
    private function buildItem(
        WorkQuery $query,
        array $card,
        string $teamId,
        array $extraTeams,
        string $category,
        string $status,
        string $priority,
        string $reason,
        string $meDisplayName,
        ?array $waitingFor,
        array $blockedBy,
        ?int $completedAt,
    ): WorkItem {
        $cardId  = (int)$card['id'];
        $boardId = (int)$card['board_id'];

        $canComplete = $category !== Category::COMPLETED
            && $this->canEditBoard($query->userId, $boardId);

        return WorkItem::make([
            'providerId'     => self::ID,
            'providerItemId' => (string)$cardId,
            'teamId'         => $teamId,
            'teamName'       => $query->teamName($teamId),
            'category'       => $category,
            'title'          => $card['title'],
            // The "resource or document name" line. For a Deck card the card
            // *is* the resource, so the second line carries where it lives —
            // board and stack — which is what tells the user which piece of
            // work this actually is.
            'subtitle'       => $card['board_title'] !== '' && $card['stack_title'] !== ''
                ? $this->l->t('%1$s · %2$s', [$card['board_title'], $card['stack_title']])
                : (string)($card['board_title'] ?: $card['stack_title']),
            'resourceType'   => self::RESOURCE_TYPE,
            'resourceId'     => (string)$cardId,
            'resourceUrl'    => '/apps/deck/board/' . $boardId . '/card/' . $cardId,
            // v4.5.24 — the row opens inside the team's own Deck tab. The URL
            // above stays as the escape hatch for a client that cannot embed.
            'openTarget'     => OpenTarget::deckCard($boardId, $cardId, (string)$card['board_title']),
            'priority'       => $priority,
            'status'         => $status,
            'reason'         => $reason,
            'createdAt'      => $card['created_at'],
            'updatedAt'      => $card['last_modified'],
            'dueAt'          => $card['duedate'],
            'completedAt'    => $completedAt,
            'assignee'       => ['uid' => $query->userId, 'displayName' => $meDisplayName],
            'waitingFor'     => $waitingFor,
            'availableActions' => [], // filled by getAvailableActions via MyWorkService
            'metadata'       => [
                'boardId'    => $boardId,
                'boardTitle' => $card['board_title'],
                'boardColor' => $card['board_color'],
                'stackTitle' => $card['stack_title'],
                'archived'   => $card['archived'],
                'blockedBy'  => array_map(
                    static fn (array $b): array => [
                        'id'       => $b['id'],
                        'title'    => $b['title'],
                        'assignee' => $b['assignee'],
                    ],
                    $blockedBy,
                ),
                'additionalTeamIds' => $extraTeams,
            ],
            'permissions'    => [
                'canOpen'     => true,
                'canComplete' => $canComplete,
            ],
        ]);
    }

    /** @param array<int, array{id:int,title:string,assignee:?array}> $blockedBy */
    private function blockedReason(array $blockedBy): string {
        $first = $blockedBy[0] ?? null;
        if ($first === null) {
            return $this->l->t('Waiting on another card');
        }
        $who = $first['assignee']['displayName'] ?? null;
        if ($who !== null && $who !== '') {
            return $this->l->t('Blocked by “%1$s”, assigned to %2$s', [$first['title'], $who]);
        }
        return $this->l->t('Blocked by “%s”', [$first['title']]);
    }

    /**
     * @param array<int, array{id:int,title:string,assignee:?array}> $blockedBy
     * @return array{type:string,id:string,displayName:string}|null
     */
    private function firstBlockerParty(array $blockedBy): ?array {
        $assignee = $blockedBy[0]['assignee'] ?? null;
        if ($assignee === null) {
            return null;
        }
        return [
            'type'        => 'user',
            'id'          => $assignee['uid'],
            'displayName' => $assignee['displayName'],
        ];
    }

    // ---------------------------------------------------------------------
    // Small helpers
    // ---------------------------------------------------------------------

    /** @var array<string,string> */
    private array $nameCache = [];

    private function displayName(string $uid): string {
        if (!isset($this->nameCache[$uid])) {
            $this->nameCache[$uid] = $this->userManager->get($uid)?->getDisplayName() ?? $uid;
        }
        return $this->nameCache[$uid];
    }

    /**
     * Deck stores dates as Unix integers in `duedate` and `done`, but some
     * versions/backends hand them back as datetime strings. Accept both; treat
     * 0, empty and unparseable as "no date" so nothing lands in 1970.
     */
    private function toTimestamp(mixed $value): ?int {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_numeric($value)) {
            $i = (int)$value;
            return $i > 0 ? $i : null;
        }
        $ts = strtotime((string)$value);
        return ($ts !== false && $ts > 0) ? $ts : null;
    }

    private function cardsHaveDoneColumn(): bool {
        if ($this->hasDoneColumn === null) {
            $this->hasDoneColumn = $this->columnExists('deck_cards', 'done');
        }
        return $this->hasDoneColumn;
    }

    /**
     * Probe for a column by selecting it with a zero-row predicate.
     *
     * Deliberately not `DbIntrospectionService`: it has been observed
     * returning `[]` for tables that exist (empty tables plus a restricted
     * INFORMATION_SCHEMA), and a false negative here silently disables
     * features. A failing SELECT is unambiguous.
     *
     * Table and column are SQL *identifiers*, which QueryBuilder cannot
     * parameterise. Every caller passes a compile-time constant; the guard
     * keeps it that way, so a future edit routing request input here fails
     * loudly rather than building a query out of it.
     */
    private function columnExists(string $table, string $column): bool {
        if (!preg_match('/^[a-z0-9_]+$/', $table) || !preg_match('/^[a-z0-9_]+$/', $column)) {
            $this->logger->error('[TeamHub][MyWork][Deck] Refusing a non-literal SQL identifier', [
                'table' => $table, 'column' => $column, 'app' => Application::APP_ID,
            ]);
            return false;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($column)
                ->from($table)
                ->setMaxResults(1);
            $res = $qb->executeQuery();
            $res->closeCursor();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
