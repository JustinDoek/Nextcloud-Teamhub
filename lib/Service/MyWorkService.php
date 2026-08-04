<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\MyWorkState;
use OCA\TeamHub\Db\MyWorkStateMapper;
use OCA\TeamHub\MyWork\ActionResult;
use OCA\TeamHub\MyWork\ActionType;
use OCA\TeamHub\MyWork\Category;
use OCA\TeamHub\MyWork\Priority;
use OCA\TeamHub\MyWork\ProviderRegistry;
use OCA\TeamHub\MyWork\WorkItem;
use OCA\TeamHub\MyWork\WorkQuery;
use OCP\ICacheFactory;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * My Work orchestration (v4.5.21).
 *
 * Owns everything that is true across providers: which teams the user may see,
 * the day boundary, the snooze ledger, deduplication, filtering, grouping,
 * paging, and the counts behind the summary cards. Providers answer "what does
 * this user owe from my source"; this class turns those answers into a work
 * queue.
 *
 * ## The authorisation shape
 *
 * There is exactly one place the team boundary is established —
 * `resolveTeams()`, which delegates to `TeamService::getUserTeams()` — and it
 * is applied twice: once by passing the team list into the query so providers
 * can narrow their SQL, and once by re-filtering the merged result. The second
 * pass is not redundant. It is what makes a provider bug or a future
 * third-party provider unable to leak another team's work into this user's
 * queue.
 *
 * Actions never trust the client. `executeAction()` re-reads the item from its
 * provider, re-checks the team boundary, re-checks the action against the
 * provider's own permission logic and the administrator's allow-list, and only
 * then executes.
 */
class MyWorkService {

    private const CACHE_PREFIX = 'teamhub_mywork_';

    /** Snooze presets the UI offers. `custom` carries its own timestamp. */
    public const SNOOZE_PRESETS = ['later_today', 'tomorrow', 'next_week', 'custom'];

    /** Rows per rail panel (v4.5.25). A glance, not a list. */
    private const HIGHLIGHT_LIMIT = 4;

    public function __construct(
        private ProviderRegistry $registry,
        private MyWorkConfigService $config,
        private MyWorkStateMapper $stateMapper,
        private TeamService $teamService,
        private AuditService $auditService,
        private ICacheFactory $cacheFactory,
        private IL10N $l,
        private LoggerInterface $logger,
    ) {
    }

    // ---------------------------------------------------------------------
    // Read path
    // ---------------------------------------------------------------------

    /**
     * The whole My Work payload for one request.
     *
     * @param array<string,mixed> $params raw, already type-coerced by the controller
     * @return array<string,mixed>
     */
    public function getWork(string $userId, array $params): array {
        $teams = $this->resolveTeams();
        if ($teams === []) {
            return $this->emptyPayload();
        }

        $query   = $this->buildQuery($userId, $teams, $params);
        $groupBy = (string)($params['groupBy'] ?? 'category');

        // groupBy participates in the cache key because it changes the shape of
        // the response (`groups`, and the sort order inside them), not just its
        // presentation. Leaving it out served the previous grouping's headings
        // when a user flipped the selector.
        $cacheKey = $this->cacheKey($userId, $query, $groupBy);

        // An explicit Refresh must never be answered from cache. Justin's
        // smoke test (v4.5.21) saw a due-date change take minutes to appear,
        // because the frontend's own 60-second skip and this cache's 60-second
        // TTL stacked — and the Refresh button hit the same cached endpoint,
        // so the one control that means "I know it changed" was the one thing
        // that could not say so.
        $bypassCache = (bool)($params['nocache'] ?? false);

        if (!$bypassCache) {
            $cached = $this->readCache($cacheKey);
            if ($cached !== null) {
                $cached['cached'] = true;
                return $cached;
            }
        }

        $fetched = $this->registry->fetchAll($query);

        /** @var WorkItem[] $items */
        $items = $fetched['items'];

        // ── Authorisation re-filter (see class docblock) ──────────────────
        $allowed = array_flip(array_keys($teams));
        $items   = array_values(array_filter(
            $items,
            static fn (WorkItem $i): bool => $i->teamId !== '' && isset($allowed[$i->teamId]),
        ));

        // ── Team names ────────────────────────────────────────────────────
        // Stamped centrally rather than trusted from the provider. A provider
        // that resolves an item outside the list path — DeckWorkProvider and
        // ApprovalWorkProvider both build their own WorkQuery in getItem(),
        // which has no names map — falls back to the raw circle id, and the
        // row then reads "it started showing the team id instead of team name"
        // (v4.5.21 smoke test, on the since-removed follow path). One stamp
        // here fixes it for every provider, present and future, instead of
        // once per provider.
        $items = $this->stampTeamNames($items, $teams);

        // ── Personal state: snooze ────────────────────────────────────────
        $state = [];
        try {
            $state = $this->stateMapper->findAllForUserKeyed($userId);
        } catch (\Throwable $e) {
            // A missing state table (upgrade not yet applied) must not take
            // the whole view down — it costs snooze, not the queue.
            $this->logger->warning('[TeamHub][MyWork] Could not read personal state', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        $items = $this->applyPersonalState($items, $state, $query);

        // ── Category derivation + admin remapping ─────────────────────────
        $items = array_map(fn (WorkItem $i): WorkItem => $this->finaliseCategory($i, $query), $items);

        // ── Dedupe ────────────────────────────────────────────────────────
        $items = $this->dedupe($items);

        // ── Counts BEFORE the category filter, so the summary cards keep
        //    showing the other four sections while one is selected. Every
        //    other filter is already applied, so the numbers match what
        //    clicking through would show.
        $prefiltered = $this->applyFilters($items, $query, ignoreCategory: true);
        $counts      = $this->countByCategory($prefiltered, $query);
        $breakdown   = $this->breakdownByCategory($prefiltered, $query);

        // ── Per-source counts for the source tab bar (v4.5.25), on the same
        //    principle as the category counts above: every filter applies
        //    EXCEPT the one the control itself sets. A tab bar whose other
        //    tabs read zero the moment you pick one is not a navigation aid.
        //    The category filter deliberately still applies — with Action
        //    required selected, the tabs should say how much of it each
        //    source is responsible for.
        $sourceCounts = $this->countByProvider(
            $this->applyFilters($items, $query, ignoreCategory: false, ignoreProvider: true),
        );

        // ── The rail's contents (v4.5.25), from the SAME pre-category-filter
        //    set as the counts. The rail used to derive its rows from the
        //    current page, which meant selecting a summary card emptied every
        //    panel while its count kept reading five — the panel said "nothing
        //    finished this week" directly under a 5. A control's own data
        //    cannot be filtered by the thing it navigates to.
        // v4.5.30 — read from the raw params rather than WorkQuery, which has
        // no `dueWindow`: the client converts its presets to explicit
        // dueFrom/dueTo bounds precisely so the server never has to know what
        // "this week" means. 4.5.28 read `$query->dueWindow`, an undefined
        // property, so the Completed panel never narrowed.
        //
        // v4.5.31 — the day travels with the flag. The Today lens deliberately
        // sends no `dueFrom` (overdue work belongs in Today), so the bounds can
        // no longer be borrowed from the query, and the server's own midnight
        // is not the viewer's.
        $todayFrom = null;
        $todayTo   = null;
        if (!empty($params['todayLens'])) {
            // Falls back to the server's day only when the client sent no
            // bounds — an API caller rather than our own frontend.
            $todayFrom = isset($params['todayFrom']) ? (int)$params['todayFrom'] : $query->startOfToday();
            $todayTo   = isset($params['todayTo'])   ? (int)$params['todayTo']   : ($query->endOfToday() - 1);
        }
        $highlights = $this->buildHighlights($prefiltered, $query, $todayFrom, $todayTo);

        // ── The list itself ───────────────────────────────────────────────
        $filtered = $this->applyFilters($items, $query, ignoreCategory: false);
        $filtered = $this->sortItems(
            $filtered,
            (string)($params['groupBy'] ?? 'category'),
            $query->sortBy,
        );

        $total = count($filtered);
        $page  = array_slice($filtered, $query->offset, $query->limit);

        // Actions are resolved on the page only — asking every provider about
        // every item would defeat the paging.
        $page = $this->attachActions($userId, $page);

        $payload = [
            'items'          => array_map(static fn (WorkItem $i): array => $i->toArray(), $page),
            'groups'         => $this->buildGroups($page, (string)($params['groupBy'] ?? 'category'), $query),
            'counts'         => $counts,
            'breakdown'      => $breakdown,
            'sourceCounts'   => $sourceCounts,
            'highlights'     => $highlights,
            'sortBy'         => $query->sortBy,
            'total'          => $total,
            'limit'          => $query->limit,
            'offset'         => $query->offset,
            'hasMore'        => ($query->offset + $query->limit) < $total,
            'providerStatus' => $fetched['status'],
            'truncated'      => $fetched['truncated'],
            'teams'          => array_map(
                static fn (string $id, string $name): array => ['id' => $id, 'name' => $name],
                array_keys($teams),
                array_values($teams),
            ),
            'config'         => [
                'upcomingDays'  => $query->upcomingDays,
                'completedDays' => $query->completedDays,
            ],
            'generatedAt'    => $query->now,
            'cached'         => false,
        ];

        $this->writeCache($cacheKey, $payload);
        return $payload;
    }

    /**
     * Counts only — the cheap call the sidebar badge and a periodic refresh
     * use. Runs the same pipeline but skips action resolution and grouping.
     *
     * @return array<string,int>
     */
    public function getCounts(string $userId): array {
        $payload = $this->getWork($userId, ['limit' => 1, 'offset' => 0]);
        return $payload['counts'];
    }

    // ---------------------------------------------------------------------
    // Write path
    // ---------------------------------------------------------------------

    /**
     * Execute one action against one item.
     *
     * Every guard the specification asks for is here, in order: the provider
     * must exist and be enabled, the item must re-resolve from source inside
     * the user's team boundary, the action must be one the provider offers for
     * this item and this user, and it must be one the administrator permits.
     *
     * @param array<string,mixed> $params
     */
    public function executeAction(string $userId, string $providerId, string $itemId, string $action, array $params): ActionResult {
        if (!ActionType::isValid($action)) {
            return ActionResult::unsupported($this->l->t('Unknown action.'));
        }

        $provider = $this->registry->get($providerId);
        if ($provider === null) {
            return ActionResult::gone($this->l->t('That source is no longer available.'));
        }
        if (!$this->config->isProviderEnabled($providerId)) {
            return ActionResult::forbidden(
                $this->l->t('An administrator has disabled this source in My Work.'),
            );
        }

        $teams = $this->resolveTeams();
        if ($teams === []) {
            return ActionResult::forbidden($this->l->t('You are not a member of any team.'));
        }

        // Re-read from source. This is the authorisation step — nothing about
        // the item comes from the request beyond its id.
        try {
            $item = $provider->getItem($userId, $itemId, array_keys($teams));
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWork] Item re-read failed', [
                'provider' => $providerId, 'itemId' => $itemId,
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return ActionResult::failure($this->l->t('That item could not be read from its source.'));
        }

        if ($item === null) {
            return ActionResult::gone(
                $this->l->t('That item no longer exists, or you no longer have access to it.'),
            );
        }

        // Native actions are TeamHub's own and bypass the provider entirely.
        if (ActionType::isNative($action)) {
            return $this->executeNativeAction($userId, $item, $action, $params);
        }

        $allowedByAdmin = $this->config->getAllowedActions($providerId, $provider);
        if (!in_array($action, $allowedByAdmin, true)) {
            return ActionResult::forbidden(
                $this->l->t('An administrator has disabled this action for this source.'),
            );
        }

        try {
            $offered = $provider->getAvailableActions($userId, $item);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWork] Action resolution failed', [
                'provider' => $providerId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return ActionResult::failure($this->l->t('That action could not be checked.'));
        }

        if (!in_array($action, $offered, true)) {
            return ActionResult::conflict(
                $this->l->t('That action is not available for this item right now.'),
            );
        }

        try {
            $result = $provider->executeAction($userId, $item, $action, $params);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWork] Action execution failed', [
                'provider' => $providerId, 'action' => $action,
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return ActionResult::failure($this->l->t('That action could not be completed.'));
        }

        // Auditable action execution. Source-mutating actions only — logging
        // every `open` would drown the audit log in navigation noise.
        if ($result->ok && in_array($action, ActionType::SOURCE_MUTATING, true)) {
            $this->auditService->log(
                $item->teamId,
                'mywork.action.' . $action,
                $userId,
                $item->resourceType,
                $item->resourceId,
                [
                    'provider' => $providerId,
                    'itemId'   => $itemId,
                    'title'    => mb_substr($item->title, 0, 200),
                ],
            );
        }

        if ($result->ok) {
            $this->invalidateUser($userId);
        }

        return $result;
    }

    /**
     * Snooze and unsnooze — TeamHub's own state, never the source app's.
     *
     * @param array<string,mixed> $params
     */
    private function executeNativeAction(string $userId, WorkItem $item, string $action, array $params): ActionResult {
        try {
            switch ($action) {
                case ActionType::SNOOZE:
                    $until = $this->resolveSnoozeUntil($params);
                    if ($until === null) {
                        return ActionResult::failure(
                            $this->l->t('Choose when this should come back.'), 'failed',
                        );
                    }
                    $this->stateMapper->upsert(
                        $userId, $item->providerId, $item->providerItemId, $item->teamId,
                        snoozeUntil: $until,
                    );
                    $this->invalidateUser($userId);

                    // The warning the specification asks for is a fact about
                    // the item, so it travels with the result rather than
                    // being hardcoded in the frontend.
                    $overdue = $item->dueAt !== null && $item->dueAt < time();
                    return ActionResult::success(
                        $overdue
                            ? $this->l->t('Snoozed. The due date in the source app has not changed.')
                            : $this->l->t('Snoozed.'),
                        null,
                        true,
                    );

                case ActionType::UNSNOOZE:
                    $this->stateMapper->upsert(
                        $userId, $item->providerId, $item->providerItemId, $item->teamId,
                        snoozeUntil: 0,
                    );
                    $this->invalidateUser($userId);
                    return ActionResult::success($this->l->t('No longer snoozed.'));
            }
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWork] Native action failed', [
                'action' => $action, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return ActionResult::failure($this->l->t('That could not be saved.'));
        }

        return ActionResult::unsupported($this->l->t('Unknown action.'));
    }

    /**
     * Turn a snooze preset (or a custom timestamp) into an absolute moment.
     *
     * **TeamHub's own frontend no longer takes the preset branch** (v4.5.24).
     * It resolves `later_today` / `tomorrow` / `next_week` against the
     * browser's clock and sends `custom` with the result, because a preset
     * resolved here lands in the *server's* tomorrow morning — wrong for
     * everyone not sharing its timezone. Storing an absolute timestamp is what
     * made that a frontend change with no migration behind it.
     *
     * The preset branch stays for API callers that have no clock of their own,
     * and its semantics are what `resolveSnoozePreset()` in
     * `src/constants/myWork.js` mirrors. If one moves, move both.
     *
     * @param array<string,mixed> $params
     */
    private function resolveSnoozeUntil(array $params): ?int {
        $preset = (string)($params['preset'] ?? 'tomorrow');
        $now    = time();

        if ($preset === 'custom') {
            $until = (int)($params['until'] ?? 0);
            // Reject the past and anything absurdly far out — a snooze to the
            // year 3000 is indistinguishable from a mute, and mute is a
            // different action with a different label.
            if ($until <= $now || $until > $now + (365 * 86400)) {
                return null;
            }
            return $until;
        }

        return match ($preset) {
            // 18:00 today, or three hours out if the working day is already done.
            'later_today' => (static function () use ($now): int {
                $six = (int)strtotime('today 18:00', $now);
                return $six > $now ? $six : $now + (3 * 3600);
            })(),
            'tomorrow'  => (int)strtotime('tomorrow 09:00', $now),
            'next_week' => (int)strtotime('monday next week 09:00', $now),
            default     => null,
        };
    }

    // ---------------------------------------------------------------------
    // Pipeline steps
    // ---------------------------------------------------------------------

    /**
     * teamId => team name, for every team the current user belongs to.
     *
     * The single authorisation boundary. `getUserTeams()` already handles
     * direct and indirect (group/circle) membership and excludes teams pending
     * deletion, so nothing here re-derives membership.
     *
     * @return array<string,string>
     */
    private function resolveTeams(): array {
        $out = [];
        try {
            foreach ($this->teamService->getUserTeams() as $team) {
                $id = (string)($team['id'] ?? '');
                if ($id !== '') {
                    $out[$id] = (string)($team['name'] ?? $id);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWork] Team resolution failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
        }
        return $out;
    }

    /**
     * @param array<string,string> $teams
     * @param array<string,mixed>  $params
     */
    private function buildQuery(string $userId, array $teams, array $params): WorkQuery {
        $upcomingDays  = $this->config->getUpcomingDays();
        $completedDays = $this->config->getCompletedDays();

        $categories = $this->stringList($params['categories'] ?? []);
        $categories = array_values(array_filter($categories, static fn (string $c): bool => Category::isValid($c)));

        $priorities = $this->stringList($params['priorities'] ?? []);
        $priorities = array_values(array_filter($priorities, static fn (string $p): bool => Priority::isValid($p)));

        // A team filter can only ever narrow the user's own team set — it can
        // never widen it, whatever the client sends.
        $requestedTeams = $this->stringList($params['teamIds'] ?? []);
        $teamIds        = array_keys($teams);
        if ($requestedTeams !== []) {
            $narrowed = array_values(array_intersect($teamIds, $requestedTeams));
            if ($narrowed !== []) {
                $teamIds = $narrowed;
            }
        }

        // Completed is always fetched, even when the user has filtered to a
        // single other category. The summary cards show all five counts at all
        // times — that is what makes them navigation rather than decoration —
        // and skipping the Completed fetch would render its card as 0 whenever
        // any other filter was active. The cost is bounded: providers only
        // return completions inside the (7-day by default) retention window.
        $includeCompleted = true;

        return new WorkQuery(
            userId:           $userId,
            teamIds:          $teamIds,
            teamNames:        $teams,
            categories:       $categories,
            providerIds:      $this->stringList($params['providerIds'] ?? []),
            resourceTypes:    $this->stringList($params['resourceTypes'] ?? []),
            priorities:       $priorities,
            statuses:         $this->stringList($params['statuses'] ?? []),
            search:           trim((string)($params['search'] ?? '')),
            dueFrom:          isset($params['dueFrom']) ? (int)$params['dueFrom'] : null,
            dueTo:            isset($params['dueTo'])   ? (int)$params['dueTo']   : null,
            includeSnoozed:   (bool)($params['includeSnoozed'] ?? false),
            includeCompleted: $includeCompleted,
            upcomingDays:     $upcomingDays,
            actionRequiredDays: $this->config->getActionRequiredDays(),
            completedDays:    $completedDays,
            now:              time(),
            limit:            max(1, min(200, (int)($params['limit']  ?? 50))),
            offset:           max(0, (int)($params['offset'] ?? 0)),
            sortBy:           $this->resolveSort($params['sortBy'] ?? null),
        );
    }

    /**
     * Apply snooze state.
     *
     * Snoozed items disappear unless the user asked to see them, in which case
     * they are marked so the row can render as snoozed. That is the whole of
     * it since v4.5.40 — a state row can no longer hide an item permanently.
     *
     * @param WorkItem[] $items
     * @param array<string, MyWorkState> $state
     * @return WorkItem[]
     */
    private function applyPersonalState(array $items, array $state, WorkQuery $query): array {
        if ($state === []) {
            return $items;
        }

        $out = [];
        foreach ($items as $item) {
            $row = $state[$item->id] ?? null;
            if ($row === null) {
                $out[] = $item;
                continue;
            }

            $snoozed = $row->isSnoozed($query->now);
            if ($snoozed && !$query->includeSnoozed) {
                continue;
            }

            $out[] = $item->with(metadata: [
                'snoozed'      => $snoozed,
                'snoozedUntil' => $snoozed ? $row->getSnoozeUntil() : null,
            ]);
        }

        return $out;
    }

    /**
     * Replace each item's team name with the one TeamHub resolved.
     *
     * @param WorkItem[] $items
     * @param array<string,string> $teams
     * @return WorkItem[]
     */
    private function stampTeamNames(array $items, array $teams): array {
        return array_map(
            static function (WorkItem $item) use ($teams): WorkItem {
                $name = $teams[$item->teamId] ?? null;
                return ($name === null || $name === $item->teamName)
                    ? $item
                    : $item->with(teamName: $name);
            },
            $items,
        );
    }

    /**
     * Settle the item's final category and priority.
     *
     * Order matters and encodes the specification's rules:
     *  1. an administrator's status→category mapping overrides the provider,
     *  2. an active item due today becomes TODAY — unless it is already
     *     ACTION_REQUIRED, which wins and merely gains a `dueToday` flag. That
     *     is the "avoid duplicates with Action Required" rule, implemented as
     *     precedence rather than as a de-duplication pass,
     *  3. anything overdue and still actionable is URGENT.
     */
    private function finaliseCategory(WorkItem $item, WorkQuery $query): WorkItem {
        $category = $item->category;

        $mapped = $this->config->mapCategory($item->providerId, $item->status);
        if ($mapped !== null && !in_array($mapped, Category::DERIVED, true)) {
            $category = $mapped;
        }

        $priority = $item->priority;
        $meta     = [];

        $isOpen = $category !== Category::COMPLETED;
        $due    = $item->dueAt;

        if ($isOpen && $due !== null) {
            $dueToday = $due >= $query->startOfToday() && $due < $query->endOfToday();
            $overdue  = $due < $query->now;
            // The actionable band opens BEFORE the deadline, not after it —
            // see MyWorkConfigService::DEFAULT_ACTION_REQUIRED_DAYS.
            $dueSoon  = $due <= $query->actionRequiredBy();

            if ($dueToday) {
                $meta['dueToday'] = true;
            }

            // Waiting for others is never promoted: the whole point of that
            // section is that the next step is not yours, however close the
            // deadline is.
            //
            // Neither is anything a provider marked `informational` (v4.5.25):
            // an item with a date that is not a task. A team calendar entry you
            // were never personally invited to is the first of these — it earns
            // its place under Today because it is happening, and promoting it
            // to Action required as the hour approaches would be the queue
            // demanding something nobody asked of you.
            $informational = (bool)($item->metadata['informational'] ?? false);

            if (($overdue || $dueSoon)
                && $category !== Category::WAITING_FOR_OTHERS
                && !$informational
            ) {
                $category = Category::ACTION_REQUIRED;
                $priority = $overdue ? Priority::URGENT : Priority::HIGH;
                $meta['dueSoon'] = true;
            }

            if ($overdue) {
                $meta['overdue'] = true;
            }
        }

        if ($category === $item->category && $priority === $item->priority && $meta === []) {
            return $item;
        }

        return $item->with(category: $category, priority: $priority, metadata: $meta);
    }

    /**
     * Collapse items that are the same piece of work.
     *
     * Two passes. The first is exact — one row per `{provider}:{itemId}`,
     * which is what happens when a board is linked to two teams. The second is
     * cross-provider: the same underlying resource surfaced by two sources
     * (a file under approval that is also a Deck card's attachment, say)
     * collapses to whichever row is more urgent, because the user has one
     * piece of work, not two.
     *
     * @param WorkItem[] $items
     * @return WorkItem[]
     */
    private function dedupe(array $items): array {
        $byId = [];
        foreach ($items as $item) {
            $existing = $byId[$item->id] ?? null;
            if ($existing === null || Category::rank($item->category) < Category::rank($existing->category)) {
                $byId[$item->id] = $item;
            }
        }

        $byResource = [];
        foreach ($byId as $item) {
            $key = $item->resourceType . '#' . $item->resourceId . '#' . $item->teamId;
            if ($item->resourceId === '') {
                // Nothing to key on — keep it, uniquely.
                $byResource[$item->id] = $item;
                continue;
            }
            $existing = $byResource[$key] ?? null;
            if ($existing === null) {
                $byResource[$key] = $item;
                continue;
            }
            if (Category::rank($item->category) < Category::rank($existing->category)) {
                $byResource[$key] = $item;
            }
        }

        return array_values($byResource);
    }

    /**
     * @param WorkItem[] $items
     * @return WorkItem[]
     */
    private function applyFilters(
        array $items,
        WorkQuery $query,
        bool $ignoreCategory,
        bool $ignoreProvider = false,
    ): array {
        $search = $query->search !== '' ? mb_strtolower($query->search) : '';

        return array_values(array_filter($items, function (WorkItem $item) use ($query, $ignoreCategory, $ignoreProvider, $search): bool {
            if (!$ignoreCategory && $query->categories !== []
                && !in_array($item->category, $query->categories, true)
            ) {
                return false;
            }
            if (!$ignoreProvider && $query->providerIds !== []
                && !in_array($item->providerId, $query->providerIds, true)
            ) {
                return false;
            }
            if ($query->resourceTypes !== [] && !in_array($item->resourceType, $query->resourceTypes, true)) {
                return false;
            }
            if ($query->priorities !== [] && !in_array($item->priority, $query->priorities, true)) {
                return false;
            }
            if ($query->statuses !== [] && !in_array($item->status, $query->statuses, true)) {
                return false;
            }
            if ($query->teamIds !== [] && !in_array($item->teamId, $query->teamIds, true)) {
                return false;
            }
            if ($query->dueFrom !== null && ($item->dueAt === null || $item->dueAt < $query->dueFrom)) {
                return false;
            }
            if ($query->dueTo !== null && ($item->dueAt === null || $item->dueAt > $query->dueTo)) {
                return false;
            }
            if ($search !== '') {
                $haystack = mb_strtolower(
                    $item->title . ' ' . $item->subtitle . ' ' . $item->teamName . ' ' . $item->reason,
                );
                if (!str_contains($haystack, $search)) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * An unknown sort falls back to the default rather than erroring — a bad
     * sort key is a cosmetic problem and refusing the whole queue over one
     * would not be proportionate.
     */
    private function resolveSort(mixed $raw): string {
        $sort = (string)($raw ?? '');
        return WorkQuery::isValidSort($sort) ? $sort : WorkQuery::SORT_DEADLINE;
    }

    /**
     * The few rows each rail panel shows (v4.5.25).
     *
     * Three keys, each a short list: what is on today, what you are waiting on
     * somebody else for, and what you finished recently. Computed from the
     * pre-category-filter set for the same reason the counts are — the rail is
     * navigation, and navigation cannot be filtered by its own destination.
     *
     * Deliberately capped server-side rather than sliced by the client: these
     * travel on every page of every fetch, and a team with two hundred
     * completed items should not send two hundred rows to render three.
     *
     * @param WorkItem[] $items already filtered except for category
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function buildHighlights(array $items, WorkQuery $query, ?int $todayFrom = null, ?int $todayTo = null): array {
        $today     = [];
        $waiting   = [];
        $completed = [];

        $startOfToday = $query->startOfToday();
        $endOfToday   = $query->endOfToday();

        // v4.5.28 — when the user has the Today lens on, the Completed panel
        // narrows to today too. "Completed this week" sitting beside a page
        // that says Today is answering a question nobody asked; the panel is
        // context for what you are looking at, so it follows the lens.
        //
        // The window is the **client's** day. Its midnight is the user's
        // midnight; the server's is the server's, and on an instance whose
        // users are not in its timezone those differ by hours right where this
        // matters most — late in the evening.
        $todayLens     = $todayFrom !== null && $todayTo !== null;
        $completedFrom = $todayFrom ?? $startOfToday;
        $completedTo   = ($todayTo ?? ($endOfToday - 1)) + 1;

        foreach ($items as $item) {
            if ($item->category === Category::COMPLETED) {
                if ($todayLens
                    && !($item->completedAt !== null
                        && $item->completedAt >= $completedFrom
                        && $item->completedAt < $completedTo)) {
                    continue;
                }
                $completed[] = $item;
                continue;
            }
            if ($item->category === Category::WAITING_FOR_OTHERS) {
                $waiting[] = $item;
                continue;
            }
            if ($item->dueAt !== null && $item->dueAt >= $startOfToday && $item->dueAt < $endOfToday) {
                $today[] = $item;
            }
        }

        // Today reads chronologically — it is a schedule, not a ranking.
        usort($today, static fn (WorkItem $a, WorkItem $b): int => ($a->dueAt ?? 0) <=> ($b->dueAt ?? 0));
        // Completed reads newest-first: "what did I just finish".
        usort($completed, static fn (WorkItem $a, WorkItem $b): int
            => ($b->completedAt ?? 0) <=> ($a->completedAt ?? 0));

        $take = static fn (array $list): array => array_map(
            static fn (WorkItem $i): array => $i->toArray(),
            array_slice($list, 0, self::HIGHLIGHT_LIMIT),
        );

        return [
            'today'                    => $take($today),
            Category::WAITING_FOR_OTHERS => $take($waiting),
            Category::COMPLETED        => $take($completed),
            // The panel's own count has to come from the same narrowed set —
            // the top-level `counts` block is deliberately pre-filter, and
            // pairing "Completed today" with the week's number is the exact
            // contradiction §2.74 fixed the other way round.
            'completedScope'           => $todayLens ? 'today' : 'week',
            'completedCount'           => count($completed),
        ];
    }

    /**
     * How many items each source is responsible for (v4.5.25).
     *
     * Keyed by provider id, and providers with nothing are simply absent — the
     * frontend knows which sources exist from `/mywork/providers` and renders a
     * zero for the ones missing here, which is one fewer thing for this method
     * to know about.
     *
     * @param WorkItem[] $items
     * @return array<string,int>
     */
    private function countByProvider(array $items): array {
        $counts = [];
        foreach ($items as $item) {
            $counts[$item->providerId] = ($counts[$item->providerId] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * Default order is "category and urgency": most urgent category first,
     * then priority, then the nearest due date, then title so the order is
     * stable between refreshes.
     *
     * `$sortBy` (v4.5.25) reorders **within** a group; it never reorders the
     * groups themselves. Action required stays above Upcoming whatever the
     * user sorts by, because the categories are the structure of the page and
     * a sort control is not supposed to be able to dismantle it.
     *
     * @param WorkItem[] $items
     * @return WorkItem[]
     */
    private function sortItems(array $items, string $groupBy, string $sortBy = WorkQuery::SORT_DEADLINE): array {
        usort($items, static function (WorkItem $a, WorkItem $b) use ($groupBy, $sortBy): int {
            if ($groupBy === 'team' && $a->teamName !== $b->teamName) {
                return strcasecmp($a->teamName, $b->teamName);
            }
            if ($groupBy === 'resource_type' && $a->resourceType !== $b->resourceType) {
                return strcmp($a->resourceType, $b->resourceType);
            }

            if ($groupBy !== 'date') {
                $ca = Category::rank($a->category);
                $cb = Category::rank($b->category);
                if ($ca !== $cb) {
                    return $ca <=> $cb;
                }
            }

            // Undated work sorts after dated work — a deadline is more
            // pressing than its absence.
            $da = $a->dueAt ?? PHP_INT_MAX;
            $db = $b->dueAt ?? PHP_INT_MAX;
            $pa = Priority::rank($a->priority);
            $pb = Priority::rank($b->priority);

            switch ($sortBy) {
                case WorkQuery::SORT_PRIORITY:
                    if ($pa !== $pb) {
                        return $pa <=> $pb;
                    }
                    if ($da !== $db) {
                        return $da <=> $db;
                    }
                    break;

                case WorkQuery::SORT_TEAM:
                    $byTeam = strcasecmp($a->teamName, $b->teamName);
                    if ($byTeam !== 0) {
                        return $byTeam;
                    }
                    if ($da !== $db) {
                        return $da <=> $db;
                    }
                    break;

                case WorkQuery::SORT_RECENT:
                    // Newest first. An item with no timestamps sorts last
                    // rather than pretending to be from 1970.
                    $ra = $a->updatedAt ?? $a->createdAt ?? 0;
                    $rb = $b->updatedAt ?? $b->createdAt ?? 0;
                    if ($ra !== $rb) {
                        return $rb <=> $ra;
                    }
                    break;

                case WorkQuery::SORT_DEADLINE:
                default:
                    // The historic order: priority, then the nearest deadline.
                    if ($pa !== $pb) {
                        return $pa <=> $pb;
                    }
                    if ($da !== $db) {
                        return $da <=> $db;
                    }
                    break;
            }

            return strcasecmp($a->title, $b->title);
        });

        return $items;
    }

    /**
     * Ask each provider which actions apply to the items on this page, then
     * intersect with the administrator's allow-list and append the native
     * actions every item gets.
     *
     * @param WorkItem[] $items
     * @return WorkItem[]
     */
    private function attachActions(string $userId, array $items): array {
        $cacheAllowed = [];

        return array_map(function (WorkItem $item) use ($userId, &$cacheAllowed): WorkItem {
            $provider = $this->registry->get($item->providerId);
            if ($provider === null) {
                return $item->with(availableActions: $this->nativeActionsFor($item));
            }

            if (!isset($cacheAllowed[$item->providerId])) {
                $cacheAllowed[$item->providerId] = $this->config->getAllowedActions($item->providerId, $provider);
            }
            $allowed = $cacheAllowed[$item->providerId];

            try {
                $offered = $provider->getAvailableActions($userId, $item);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][MyWork] Could not resolve actions for item', [
                    'provider' => $item->providerId, 'error' => $e->getMessage(),
                    'app' => Application::APP_ID,
                ]);
                $offered = [];
            }

            $final = array_values(array_intersect($offered, $allowed));
            return $item->with(availableActions: array_merge($final, $this->nativeActionsFor($item)));
        }, $items);
    }

    /**
     * Native actions available on an item.
     *
     * Snooze is offered on anything still open; a completed item has nothing
     * to come back to. Since v4.5.40 that is the only native pair — follow and
     * unfollow are gone.
     *
     * @return string[]
     */
    private function nativeActionsFor(WorkItem $item): array {
        if ($item->category === Category::COMPLETED) {
            return [];
        }

        return [
            ($item->metadata['snoozed'] ?? false)
                ? ActionType::UNSNOOZE
                : ActionType::SNOOZE,
        ];
    }

    /**
     * Group headings for the current page.
     *
     * The server decides grouping so every client (and any future mobile view)
     * groups identically, and so the "default grouping is by category and
     * urgency" rule lives in one place.
     *
     * @param WorkItem[] $items
     * @return array<int, array<string,mixed>>
     */
    private function buildGroups(array $items, string $groupBy, WorkQuery $query): array {
        $groups = [];

        foreach ($items as $item) {
            [$key, $label] = match ($groupBy) {
                'team'          => [$item->teamId, $item->teamName],
                'resource_type' => [$item->resourceType, $this->resourceTypeLabel($item->resourceType)],
                'date'          => $this->dateBucket($item, $query),
                default         => [$item->category, $this->categoryLabel($item->category)],
            };

            if (!isset($groups[$key])) {
                $groups[$key] = ['key' => $key, 'label' => $label, 'itemIds' => []];
            }
            $groups[$key]['itemIds'][] = $item->id;
        }

        $ordered = array_values($groups);

        if ($groupBy === 'category') {
            usort($ordered, static fn (array $a, array $b): int =>
                Category::rank((string)$a['key']) <=> Category::rank((string)$b['key']));
        } else {
            usort($ordered, static fn (array $a, array $b): int =>
                strcasecmp((string)$a['label'], (string)$b['label']));
        }

        return $ordered;
    }

    /** @return array{0:string,1:string} */
    private function dateBucket(WorkItem $item, WorkQuery $query): array {
        if ($item->dueAt === null) {
            return ['no_date', $this->l->t('No due date')];
        }
        if ($item->dueAt < $query->startOfToday()) {
            return ['overdue', $this->l->t('Overdue')];
        }
        if ($item->dueAt < $query->endOfToday()) {
            return ['today', $this->l->t('Today')];
        }
        if ($item->dueAt < $query->endOfToday() + 86400) {
            return ['tomorrow', $this->l->t('Tomorrow')];
        }
        if ($item->dueAt < $query->endOfToday() + (7 * 86400)) {
            return ['this_week', $this->l->t('This week')];
        }
        return ['later', $this->l->t('Later')];
    }

    private function categoryLabel(string $category): string {
        return match ($category) {
            Category::ACTION_REQUIRED    => $this->l->t('Action required'),
            Category::TODAY              => $this->l->t('Today'),
            Category::UPCOMING           => $this->l->t('Upcoming'),
            Category::WAITING_FOR_OTHERS => $this->l->t('Waiting for others'),
            Category::COMPLETED          => $this->l->t('Completed'),
            default                      => $category,
        };
    }

    private function resourceTypeLabel(string $type): string {
        return match ($type) {
            'deck_card' => $this->l->t('Deck cards'),
            'file'      => $this->l->t('Files'),
            default     => $type,
        };
    }

    /**
     * Counts behind the five summary cards.
     *
     * Four of them are category counts. **Today is a lens, not a bucket** —
     * it counts every open item due today whatever category it landed in.
     *
     * That is a deliberate consequence of the action-required lead time: with
     * a lead of two days, an actionable item due today is (correctly) in
     * Action required, so a Today card counting only `category === today`
     * would read 0 on a day the user has five things due. The specification
     * anticipated this collision — "if an item requires immediate action and
     * is due today, it should primarily appear under Action Required and
     * display the label Today" — and a lens is how you honour both halves of
     * that sentence. The Today card therefore filters by due date rather than
     * by category, and its number legitimately overlaps the others.
     *
     * @param WorkItem[] $items
     * @return array<string,int>
     */
    private function countByCategory(array $items, WorkQuery $query): array {
        $counts = [];
        foreach (Category::ORDERED as $category) {
            $counts[$category] = 0;
        }

        $startOfToday = $query->startOfToday();
        $endOfToday   = $query->endOfToday();

        foreach ($items as $item) {
            if ($item->category === Category::TODAY) {
                // Nothing assigns this category any more; guard anyway so a
                // provider that sets it directly is not silently uncounted.
                $counts[Category::ACTION_REQUIRED]++;
            } elseif (isset($counts[$item->category])) {
                $counts[$item->category]++;
            }

            if ($item->category !== Category::COMPLETED
                && $item->dueAt !== null
                && $item->dueAt >= $startOfToday
                && $item->dueAt < $endOfToday
            ) {
                $counts[Category::TODAY]++;
            }
        }

        return $counts;
    }

    /**
     * Per-category, per-source breakdown behind the summary cards (v4.5.23).
     *
     * Justin's review: *"it says 7 / 6 critical — I'd like to see 7 and then
     * per category a line. Say Deck 5 cards, 2 critical."* A single sub-label
     * under the number could only ever say one thing about a mixed total; with
     * four providers, "which source is this seven coming from" is the more
     * useful second fact, and it is the one a user acts on ("that's all Deck,
     * I'll open the board").
     *
     * **The `critical` sub-count was removed in v4.5.25**, also at Justin's
     * request. It answered "how many of these are urgent", which the deadline
     * column already answers per row and the category answers per section — a
     * third place to learn the same thing, on the card where the total is
     * supposed to be the loudest number. The card now renders each source as
     * its glyph and its count, so the sub-line is one row rather than three.
     *
     * Shape: `category => [ { providerId, count } ]`, ordered by count
     * descending so the dominant source reads first. Today follows the same
     * lens rule as the counts.
     *
     * @param WorkItem[] $items
     * @return array<string, array<int, array{providerId:string,count:int}>>
     */
    private function breakdownByCategory(array $items, WorkQuery $query): array {
        $acc = [];
        foreach (Category::ORDERED as $category) {
            $acc[$category] = [];
        }

        $startOfToday = $query->startOfToday();
        $endOfToday   = $query->endOfToday();

        $bump = static function (array &$bucket, string $providerId): void {
            if (!isset($bucket[$providerId])) {
                $bucket[$providerId] = ['providerId' => $providerId, 'count' => 0];
            }
            $bucket[$providerId]['count']++;
        };

        foreach ($items as $item) {
            $category = $item->category === Category::TODAY ? Category::ACTION_REQUIRED : $item->category;

            if (isset($acc[$category])) {
                $bump($acc[$category], $item->providerId);
            }

            if ($item->category !== Category::COMPLETED
                && $item->dueAt !== null
                && $item->dueAt >= $startOfToday
                && $item->dueAt < $endOfToday
            ) {
                $bump($acc[Category::TODAY], $item->providerId);
            }
        }

        $out = [];
        foreach ($acc as $category => $bucket) {
            $rows = array_values($bucket);
            usort($rows, static fn (array $a, array $b): int =>
                $b['count'] <=> $a['count'] ?: strcmp($a['providerId'], $b['providerId']));
            $out[$category] = $rows;
        }

        return $out;
    }

    // ---------------------------------------------------------------------
    // Cache
    // ---------------------------------------------------------------------

    /**
     * Cache key includes a per-user nonce, so invalidating a user's view is a
     * single nonce bump rather than an enumeration of every filter combination
     * they might have cached.
     */
    private function cacheKey(string $userId, WorkQuery $query, string $groupBy): string {
        $shape = [
            $query->categories, $query->providerIds, $query->resourceTypes,
            $query->priorities, $query->statuses, $query->search,
            $query->dueFrom, $query->dueTo, $query->includeSnoozed,
            $query->teamIds, $query->limit, $query->offset,
            $query->upcomingDays, $query->completedDays,
            $query->actionRequiredDays, $groupBy, $query->sortBy,
        ];
        return $userId . ':' . $this->userNonce($userId) . ':' . md5(json_encode($shape));
    }

    private function userNonce(string $userId): string {
        $cache = $this->cacheFactory->createDistributed(self::CACHE_PREFIX);
        $nonce = $cache->get('nonce_' . $userId);
        return is_string($nonce) ? $nonce : '0';
    }

    public function invalidateUser(string $userId): void {
        try {
            $cache = $this->cacheFactory->createDistributed(self::CACHE_PREFIX);
            $cache->set('nonce_' . $userId, (string)microtime(true), 3600);
        } catch (\Throwable) {
            // No distributed cache configured — nothing to invalidate, because
            // nothing was cached.
        }
    }

    /** @return array<string,mixed>|null */
    private function readCache(string $key): ?array {
        $ttl = $this->config->getCacheTtl();
        if ($ttl <= 0) {
            return null;
        }
        try {
            $cache = $this->cacheFactory->createDistributed(self::CACHE_PREFIX);
            $raw   = $cache->get($key);
            return is_array($raw) ? $raw : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $payload */
    private function writeCache(string $key, array $payload): void {
        $ttl = $this->config->getCacheTtl();
        if ($ttl <= 0) {
            return;
        }
        try {
            $cache = $this->cacheFactory->createDistributed(self::CACHE_PREFIX);
            $cache->set($key, $payload, $ttl);
        } catch (\Throwable) {
            // Caching is an optimisation; failing to cache is not an error.
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function emptyPayload(): array {
        $counts = [];
        foreach (Category::ORDERED as $c) {
            $counts[$c] = 0;
        }
        $breakdown = [];
        foreach (Category::ORDERED as $c) {
            $breakdown[$c] = [];
        }
        return [
            'items'          => [],
            'groups'         => [],
            'counts'         => $counts,
            'breakdown'      => $breakdown,
            'total'          => 0,
            'limit'          => 50,
            'offset'         => 0,
            'hasMore'        => false,
            'providerStatus' => [],
            'truncated'      => [],
            'teams'          => [],
            'config'         => [
                'upcomingDays'  => $this->config->getUpcomingDays(),
                'completedDays' => $this->config->getCompletedDays(),
            ],
            'generatedAt'    => time(),
            'cached'         => false,
        ];
    }

    /**
     * Accept a filter as either an array or a comma-separated string, because
     * `axios` serialises array params differently depending on how they are
     * passed and both forms reach the controller in practice.
     *
     * @return string[]
     */
    private function stringList(mixed $raw): array {
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            $v = trim((string)$v);
            if ($v !== '' && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }
        return $out;
    }

    /** Provider descriptors for the filter UI. @return array<int,array<string,mixed>> */
    public function describeProviders(): array {
        return $this->registry->describeAll();
    }
}
