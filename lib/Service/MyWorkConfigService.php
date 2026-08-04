<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\MyWork\ActionType;
use OCA\TeamHub\MyWork\Category;
use OCA\TeamHub\MyWork\IWorkProvider;
use OCA\TeamHub\MyWork\WorkQuery;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Administrator + per-user configuration for My Work (v4.5.21).
 *
 * Instance-wide settings live in `oc_appconfig` under the `teamhub` app id with
 * a `mywork_` prefix; personal preferences live in `oc_preferences` under the
 * same app id. No new tables — these are a handful of scalars and two small
 * JSON blobs, and `teamhub_mywork_state` is reserved for per-item state that
 * genuinely needs rows.
 *
 * **Everything here has a working default.** The specification's requirement
 * that "My Work is immediately useful without extensive configuration" is met
 * by never requiring an admin to visit the page at all: both providers are
 * enabled, the horizons are 7 days, every action a provider can perform is
 * allowed, and the category mappings are the ones the specification lists.
 */
class MyWorkConfigService {

    private const PREFIX = 'mywork_';

    // ── Instance defaults ────────────────────────────────────────────────
    public const DEFAULT_UPCOMING_DAYS  = 7;
    public const DEFAULT_COMPLETED_DAYS = 7;
    /**
     * How many days before its due date an item becomes Action required.
     *
     * Justin's smoke test (v4.5.21): a card due tomorrow sat under Upcoming,
     * and "all cards should be handled before the due date". Waiting for the
     * deadline to pass before calling something actionable is too late by
     * definition, so the actionable band now starts ahead of it.
     *
     * Two days rather than three: on the default 7-day Upcoming horizon,
     * three would promote nearly half of Upcoming into Action required and
     * the split would stop meaning anything. Configurable either way, and 0
     * restores the pre-4.5.22 behaviour where only overdue counted.
     */
    public const DEFAULT_ACTION_REQUIRED_DAYS = 2;
    public const DEFAULT_CACHE_TTL      = 60;    // seconds
    public const DEFAULT_BUDGET_MS      = 4000;  // wall-clock budget for all providers
    public const DEFAULT_PAGE_SIZE      = 50;

    // Bounds. Values outside these are clamped rather than rejected — an admin
    // typing 100000 into "Upcoming days" gets the maximum, not a broken page.
    public const MIN_UPCOMING_DAYS  = 1;
    public const MAX_UPCOMING_DAYS  = 90;
    public const MIN_ACTION_REQUIRED_DAYS = 0;
    public const MAX_ACTION_REQUIRED_DAYS = 30;
    public const MIN_COMPLETED_DAYS = 1;
    public const MAX_COMPLETED_DAYS = 90;
    public const MIN_CACHE_TTL      = 0;     // 0 disables caching
    public const MAX_CACHE_TTL      = 900;
    public const MIN_BUDGET_MS      = 500;
    public const MAX_BUDGET_MS      = 30000;

    /**
     * Ceiling on the stored collapsed-section list (v4.5.39).
     *
     * The keys follow whatever the user is grouping by, so switching between
     * category / team / resource-type grouping leaves keys behind that nothing
     * matches. Harmless individually, but the list must not grow without
     * bound in a user's config blob — a person in fifty teams who collapses
     * each of them once would otherwise keep every one forever.
     */
    private const MAX_COLLAPSED_GROUPS = 60;

    /**
     * The default source-status → category mapping, exactly as specified.
     *
     * Keys are `{providerId}.{sourceStatus}`; values are Category constants.
     * A provider proposes a category for each item it emits and this table
     * only *overrides* it, so an unmapped status is not an error — it is the
     * provider's own judgement standing.
     */
    public const DEFAULT_CATEGORY_MAP = [
        'approval.approval_requested' => Category::ACTION_REQUIRED,
        'approval.changes_requested'  => Category::ACTION_REQUIRED,
        'approval.approval_submitted' => Category::WAITING_FOR_OTHERS,
        'approval.approved'           => Category::COMPLETED,
        'approval.rejected'           => Category::COMPLETED,
        'deck.deck_card_assigned'     => Category::UPCOMING,
        'deck.deck_card_overdue'      => Category::ACTION_REQUIRED,
        'deck.deck_card_done'         => Category::COMPLETED,
        'decisions.decision_awaiting_approval' => Category::ACTION_REQUIRED,
        // v4.5.25 — an open proposal is Action required for its proposer
        // (finalize is proposer-only) and Waiting for others for an approver
        // (they are waiting on that finalize). Two entries, because they are
        // the two ends of the same decision and an administrator must be able
        // to move one without the other.
        'decisions.decision_awaiting_finalize' => Category::ACTION_REQUIRED,
        'decisions.decision_open_for_approver' => Category::WAITING_FOR_OTHERS,
        'decisions.decision_proposed'          => Category::WAITING_FOR_OTHERS,
        'decisions.decision_approved'          => Category::COMPLETED,
        'decisions.decision_denied'            => Category::COMPLETED,
        // v4.5.25 — meetings. Only the unanswered invitation is Action
        // required; an accepted meeting is Upcoming and reaches Action
        // required through the shared lead-time rule, like everything else.
        'meetings.meeting_invited'             => Category::ACTION_REQUIRED,
        'meetings.meeting_tentative'           => Category::UPCOMING,
        'meetings.meeting_accepted'            => Category::UPCOMING,
        'meetings.meeting_organiser'           => Category::UPCOMING,
        'meetings.meeting_team_event'          => Category::UPCOMING,
    ];

    public function __construct(
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    // ---------------------------------------------------------------------
    // Providers
    // ---------------------------------------------------------------------

    /**
     * Providers default to ENABLED. An admin who installs TeamHub and the
     * Deck app should see Deck cards without configuring anything; a provider
     * whose backing app is missing hides itself via `isAvailable()` instead.
     */
    public function isProviderEnabled(string $providerId): bool {
        return $this->getAppValue('provider_' . $providerId . '_enabled', '1') === '1';
    }

    public function setProviderEnabled(string $providerId, bool $enabled): void {
        $this->setAppValue('provider_' . $providerId . '_enabled', $enabled ? '1' : '0');
    }

    /**
     * Which of a provider's actions administrators permit.
     *
     * Empty stored value = "all of them", so the default needs no write and a
     * provider that gains a new action in a later release does not silently
     * arrive disabled. Native actions (snooze/follow) are never restrictable —
     * they are personal queue management and touch no source app. Navigation
     * actions (join/agenda, v4.5.25) are never restrictable either, for a
     * stronger reason: they open a link the user could already click in the
     * source app, so there is no permission for an administrator to withhold.
     *
     * @return string[]
     */
    public function getAllowedActions(string $providerId, IWorkProvider $provider): array {
        $capable = [];
        try {
            $capable = (array)($provider->getCapabilities()['actions'] ?? []);
        } catch (\Throwable) {
            $capable = [];
        }

        $raw = $this->getAppValue('provider_' . $providerId . '_actions', '');
        if ($raw === '') {
            return array_values(array_unique(array_merge($capable, ActionType::NATIVE)));
        }

        $allowed = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $a): bool => ActionType::isValid($a),
        ));

        // Intersect with what the provider can actually do, then re-add the
        // native ones. An admin cannot grant an action the provider lacks.
        $allowed = array_values(array_intersect($allowed, $capable));
        return array_values(array_unique(array_merge(
            $allowed, ActionType::NATIVE, array_intersect(ActionType::NAVIGATION, $capable),
        )));
    }

    /** @param string[] $actions */
    public function setAllowedActions(string $providerId, array $actions): void {
        $clean = array_values(array_unique(array_filter(
            array_map(static fn ($a): string => trim((string)$a), $actions),
            static fn (string $a): bool => ActionType::isValid($a)
                && !ActionType::isNative($a)
                && !in_array($a, ActionType::NAVIGATION, true),
        )));
        // Storing the empty string would mean "all"; store a sentinel instead
        // so "the admin turned everything off" is representable.
        $this->setAppValue('provider_' . $providerId . '_actions', $clean === [] ? 'none' : implode(',', $clean));
    }

    // ---------------------------------------------------------------------
    // Horizons, caching, budget
    // ---------------------------------------------------------------------

    public function getUpcomingDays(): int {
        return $this->clamp(
            (int)$this->getAppValue('upcoming_days', (string)self::DEFAULT_UPCOMING_DAYS),
            self::MIN_UPCOMING_DAYS,
            self::MAX_UPCOMING_DAYS,
        );
    }

    /**
     * Days before the due date at which an item becomes Action required.
     * 0 = only overdue items are actionable.
     */
    public function getActionRequiredDays(): int {
        return $this->clamp(
            (int)$this->getAppValue('action_required_days', (string)self::DEFAULT_ACTION_REQUIRED_DAYS),
            self::MIN_ACTION_REQUIRED_DAYS,
            self::MAX_ACTION_REQUIRED_DAYS,
        );
    }

    public function getCompletedDays(): int {
        return $this->clamp(
            (int)$this->getAppValue('completed_days', (string)self::DEFAULT_COMPLETED_DAYS),
            self::MIN_COMPLETED_DAYS,
            self::MAX_COMPLETED_DAYS,
        );
    }

    public function getCacheTtl(): int {
        return $this->clamp(
            (int)$this->getAppValue('cache_ttl', (string)self::DEFAULT_CACHE_TTL),
            self::MIN_CACHE_TTL,
            self::MAX_CACHE_TTL,
        );
    }

    public function getProviderBudgetMs(): int {
        return $this->clamp(
            (int)$this->getAppValue('budget_ms', (string)self::DEFAULT_BUDGET_MS),
            self::MIN_BUDGET_MS,
            self::MAX_BUDGET_MS,
        );
    }

    /**
     * Days after which a still-pending approval counts as expiring.
     *
     * The Nextcloud Approval app has no expiry concept of its own, so "a
     * requested approval has expired or is about to expire" is a TeamHub-side
     * derivation from how long the request has been pending. Making it
     * configurable is the honest way to expose that it is our policy, not the
     * source app's fact.
     */
    public function getApprovalStaleDays(): int {
        return $this->clamp((int)$this->getAppValue('approval_stale_days', '14'), 1, 365);
    }

    /** Warn this many days before the stale threshold ("about to expire"). */
    public function getApprovalWarnDays(): int {
        $stale = $this->getApprovalStaleDays();
        return $this->clamp((int)$this->getAppValue('approval_warn_days', '3'), 1, max(1, $stale - 1));
    }

    // ---------------------------------------------------------------------
    // Category mappings
    // ---------------------------------------------------------------------

    /**
     * Effective source-status → category map: shipped defaults with any admin
     * overrides layered on top.
     *
     * @return array<string,string>
     */
    public function getCategoryMappings(): array {
        $stored = $this->decodeJson($this->getAppValue('category_map', ''));
        $out    = self::DEFAULT_CATEGORY_MAP;
        foreach ($stored as $key => $value) {
            $key   = (string)$key;
            $value = (string)$value;
            if ($key !== '' && Category::isValid($value)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Category for a provider's source status, or null to let the provider's
     * own choice stand.
     */
    public function mapCategory(string $providerId, string $sourceStatus): ?string {
        if ($sourceStatus === '') {
            return null;
        }
        return $this->getCategoryMappings()[$providerId . '.' . $sourceStatus] ?? null;
    }

    /** @param array<string,string> $map */
    public function setCategoryMappings(array $map): void {
        $clean = [];
        foreach ($map as $key => $value) {
            $key   = trim((string)$key);
            $value = trim((string)$value);
            // Key must be `{provider}.{status}` and both halves non-empty, so a
            // malformed row can never shadow a real mapping.
            if ($key === '' || !str_contains($key, '.') || !Category::isValid($value)) {
                continue;
            }
            [$p, $s] = explode('.', $key, 2);
            if ($p === '' || $s === '') {
                continue;
            }
            $clean[$key] = $value;
        }
        $this->setAppValue('category_map', json_encode($clean, JSON_THROW_ON_ERROR));
    }

    // ---------------------------------------------------------------------
    // Provider sync bookkeeping (admin status page)
    // ---------------------------------------------------------------------

    /**
     * @return array{lastSyncAt:int|null, lastErrorAt:int|null, lastError:string|null}
     */
    public function getProviderSync(string $providerId): array {
        $ok  = (int)$this->getAppValue('sync_' . $providerId . '_ok', '0');
        $err = (int)$this->getAppValue('sync_' . $providerId . '_err_at', '0');
        $msg = $this->getAppValue('sync_' . $providerId . '_err', '');
        return [
            'lastSyncAt'  => $ok > 0 ? $ok : null,
            'lastErrorAt' => $err > 0 ? $err : null,
            'lastError'   => $msg !== '' ? $msg : null,
        ];
    }

    /**
     * How often a *successful* sync timestamp is actually written.
     *
     * My Work is fetched on every visit by every member, and an appconfig
     * write per provider per request would be a real load for a field an
     * administrator reads occasionally. Successes are therefore throttled;
     * failures are always written, because "when did this last break" is the
     * question the field exists to answer.
     */
    private const SYNC_WRITE_INTERVAL = 300;

    /**
     * Record the outcome of a provider fetch.
     *
     * A success clears the stored error so the admin page shows a green row
     * again once a transient fault has passed — a stale error message that
     * never clears trains admins to ignore the page.
     */
    public function recordProviderSync(string $providerId, bool $ok, ?string $error): void {
        try {
            if ($ok) {
                $hadError = $this->getAppValue('sync_' . $providerId . '_err', '') !== '';
                $last     = (int)$this->getAppValue('sync_' . $providerId . '_ok', '0');

                // Always write when recovering from an error, so the admin page
                // goes green immediately; otherwise only every few minutes.
                if ($hadError || (time() - $last) >= self::SYNC_WRITE_INTERVAL) {
                    $this->setAppValue('sync_' . $providerId . '_ok', (string)time());
                }
                if ($hadError) {
                    $this->setAppValue('sync_' . $providerId . '_err', '');
                    $this->setAppValue('sync_' . $providerId . '_err_at', '0');
                }
                return;
            }
            $this->setAppValue('sync_' . $providerId . '_err_at', (string)time());
            // Cap the stored message — a stack-trace-length string in appconfig
            // helps nobody and some backends truncate mid-UTF-8.
            $this->setAppValue('sync_' . $providerId . '_err', mb_substr((string)$error, 0, 500));
        } catch (\Throwable $e) {
            // Bookkeeping must never break a fetch.
            $this->logger->debug('[TeamHub][MyWorkConfig] Could not record sync state', [
                'provider' => $providerId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    // ---------------------------------------------------------------------
    // Admin bundle
    // ---------------------------------------------------------------------

    /**
     * Everything the admin page renders, in one read.
     *
     * @return array<string,mixed>
     */
    public function getAdminConfig(): array {
        return [
            'upcomingDays'      => $this->getUpcomingDays(),
            'actionRequiredDays' => $this->getActionRequiredDays(),
            'completedDays'     => $this->getCompletedDays(),
            'cacheTtl'          => $this->getCacheTtl(),
            'budgetMs'          => $this->getProviderBudgetMs(),
            'approvalStaleDays' => $this->getApprovalStaleDays(),
            'approvalWarnDays'  => $this->getApprovalWarnDays(),
            'categoryMap'       => $this->getCategoryMappings(),
            'defaultCategoryMap' => self::DEFAULT_CATEGORY_MAP,
            'bounds'            => [
                'upcomingDays'  => ['min' => self::MIN_UPCOMING_DAYS,  'max' => self::MAX_UPCOMING_DAYS],
                'actionRequiredDays' => ['min' => self::MIN_ACTION_REQUIRED_DAYS, 'max' => self::MAX_ACTION_REQUIRED_DAYS],
                'completedDays' => ['min' => self::MIN_COMPLETED_DAYS, 'max' => self::MAX_COMPLETED_DAYS],
                'cacheTtl'      => ['min' => self::MIN_CACHE_TTL,      'max' => self::MAX_CACHE_TTL],
                'budgetMs'      => ['min' => self::MIN_BUDGET_MS,      'max' => self::MAX_BUDGET_MS],
            ],
            'categories'        => Category::ORDERED,
        ];
    }

    /**
     * Write the admin bundle. Only keys present are written, so the page can
     * PATCH a single field without shipping the whole object back.
     *
     * @param array<string,mixed> $body
     */
    public function saveAdminConfig(array $body): void {
        if (array_key_exists('upcomingDays', $body)) {
            $this->setAppValue('upcoming_days', (string)$this->clamp(
                (int)$body['upcomingDays'], self::MIN_UPCOMING_DAYS, self::MAX_UPCOMING_DAYS));
        }
        if (array_key_exists('actionRequiredDays', $body)) {
            $this->setAppValue('action_required_days', (string)$this->clamp(
                (int)$body['actionRequiredDays'], self::MIN_ACTION_REQUIRED_DAYS, self::MAX_ACTION_REQUIRED_DAYS));
        }
        if (array_key_exists('completedDays', $body)) {
            $this->setAppValue('completed_days', (string)$this->clamp(
                (int)$body['completedDays'], self::MIN_COMPLETED_DAYS, self::MAX_COMPLETED_DAYS));
        }
        if (array_key_exists('cacheTtl', $body)) {
            $this->setAppValue('cache_ttl', (string)$this->clamp(
                (int)$body['cacheTtl'], self::MIN_CACHE_TTL, self::MAX_CACHE_TTL));
        }
        if (array_key_exists('budgetMs', $body)) {
            $this->setAppValue('budget_ms', (string)$this->clamp(
                (int)$body['budgetMs'], self::MIN_BUDGET_MS, self::MAX_BUDGET_MS));
        }
        if (array_key_exists('approvalStaleDays', $body)) {
            $this->setAppValue('approval_stale_days', (string)$this->clamp(
                (int)$body['approvalStaleDays'], 1, 365));
        }
        if (array_key_exists('approvalWarnDays', $body)) {
            $this->setAppValue('approval_warn_days', (string)$this->clamp(
                (int)$body['approvalWarnDays'], 1, 364));
        }
        if (array_key_exists('categoryMap', $body) && is_array($body['categoryMap'])) {
            $this->setCategoryMappings($body['categoryMap']);
        }
    }

    // ---------------------------------------------------------------------
    // Personal preferences
    // ---------------------------------------------------------------------

    /**
     * Per-user My Work view preferences.
     *
     * The specification is explicit that "the meaning and fundamental
     * structure of My Work must not be removable by users" — so what is stored
     * here is presentation only: grouping, whether snoozed and completed rows
     * are shown, page size, and the last filter set. There is deliberately no
     * "hide the Action Required section" preference, and the server always
     * returns all five categories' counts regardless of what is stored.
     *
     * @return array<string,mixed>
     */
    public function getUserPreferences(string $uid): array {
        $stored = $this->decodeJson(
            $this->config->getUserValue($uid, Application::APP_ID, self::PREFIX . 'prefs', ''),
        );

        $groupBy = (string)($stored['groupBy'] ?? 'category');
        if (!in_array($groupBy, ['category', 'date', 'team', 'resource_type'], true)) {
            $groupBy = 'category';
        }

        // v4.5.25 — validated against WorkQuery rather than a second list here,
        // so a sort mode cannot exist in one file and not the other.
        $sortBy = (string)($stored['sortBy'] ?? WorkQuery::SORT_DEADLINE);
        if (!WorkQuery::isValidSort($sortBy)) {
            $sortBy = WorkQuery::SORT_DEADLINE;
        }

        return [
            'groupBy'         => $groupBy,
            'sortBy'          => $sortBy,
            'showSnoozed'     => (bool)($stored['showSnoozed'] ?? false),
            'collapsedGroups' => $this->normaliseCollapsedGroups($stored['collapsedGroups'] ?? null),
            'compact'         => (bool)($stored['compact'] ?? false),
            'pageSize'        => $this->clamp((int)($stored['pageSize'] ?? self::DEFAULT_PAGE_SIZE), 10, 200),
            'filters'         => is_array($stored['filters'] ?? null) ? $stored['filters'] : [],
        ];
    }

    /**
     * Which sections the user has folded shut (v4.5.39).
     *
     * Replaces the `completedExpanded` boolean. **Not migrated**: that key was
     * one flag about one category and this is a set of section keys, so a
     * stored `false` carried across would read as "everything collapsed" — the
     * same reasoning that dropped `mentionsOnly` in 4.5.29 rather than
     * renaming it. An old blob simply loses the key on the next write.
     *
     * The keys are whatever the current grouping produces — category names,
     * team ids, priorities — so this cannot be validated against a fixed list
     * without teaching the server which groupings exist, which is the client's
     * fact (the same reasoning as `openTarget.view` in 4.5.24). It is bounded
     * and type-checked instead: presentation state that at worst leaves a
     * section folded.
     *
     * @param mixed $raw
     * @return list<string>
     */
    private function normaliseCollapsedGroups($raw): array {
        if (!is_array($raw)) {
            return [];
        }
        $keys = [];
        foreach ($raw as $key) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            $key = substr((string)$key, 0, 64);
            if ($key !== '' && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
            if (count($keys) >= self::MAX_COLLAPSED_GROUPS) {
                break;
            }
        }
        return $keys;
    }

    /** @param array<string,mixed> $prefs */
    public function saveUserPreferences(string $uid, array $prefs): array {
        $current = $this->getUserPreferences($uid);
        $merged  = array_merge($current, array_intersect_key($prefs, $current));
        // Re-normalise through the reader's validation by writing then reading.
        $this->config->setUserValue(
            $uid, Application::APP_ID, self::PREFIX . 'prefs',
            json_encode($merged, JSON_THROW_ON_ERROR),
        );
        return $this->getUserPreferences($uid);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function getAppValue(string $key, string $default): string {
        return $this->config->getAppValue(Application::APP_ID, self::PREFIX . $key, $default);
    }

    private function setAppValue(string $key, string $value): void {
        $this->config->setAppValue(Application::APP_ID, self::PREFIX . $key, $value);
    }

    private function clamp(int $value, int $min, int $max): int {
        return max($min, min($max, $value));
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $raw): array {
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            // Corrupt stored JSON falls back to defaults rather than 500ing
            // the whole view.
            return [];
        }
    }
}
