<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork;

/**
 * Everything a provider needs to answer "what does this user owe?" (v4.5.21).
 *
 * The security-relevant field is `$teamIds`. It is the *resolved* set of teams
 * the calling user is a member of, computed once by MyWorkService from
 * TeamService::getUserTeams(), and a provider must never return an item for a
 * team outside it. MyWorkService re-filters the returned items against the
 * same list afterwards, so a buggy or hostile provider leaks nothing — but the
 * list is passed in so providers can also push the constraint down into their
 * SQL instead of over-fetching.
 *
 * ## The one exception: instance-scoped providers (v4.6.13)
 *
 * Some work is owed by a *Nextcloud administrator*, about teams they may have
 * no membership in at all — a team approaching its expiration date is the first
 * such case. Membership is the wrong boundary for that, because the whole point
 * is to reach an admin who is not in the team.
 *
 * A provider may declare `isInstanceScoped(): bool` (optional, discovered by
 * `method_exists` the way ProviderRegistry already discovers `getDiagnostics`).
 * MyWorkService puts its id in `$instanceScopedProviderIds` **only when the
 * viewer is a Nextcloud administrator**, and only then does `bypassesTeamScope`
 * let its items past the membership filter.
 *
 * Three properties keep that from being a hole:
 *
 *   1. The allow-list is built server-side from `IGroupManager::isAdmin()`. A
 *      client cannot ask to be on it.
 *   2. It is per-provider, not global. Every other provider keeps the
 *      membership boundary exactly as it was.
 *   3. An instance-scoped provider owes its own authorisation in **both**
 *      `fetchItems()` and `getItem()` — MyWorkService is no longer checking on
 *      its behalf. A provider that declares this and then does not check is
 *      the bug this contract makes findable.
 *
 * An explicit team filter still wins: `$teamFilterActive` turns the bypass off,
 * because a user who narrowed to one team asked to see that team.
 *
 * Filters are passed to providers as *hints* for server-side narrowing. A
 * provider that ignores them is still correct: MyWorkService applies every
 * filter again on the merged result. Providers should honour the cheap ones
 * (teamIds, dueTo, completedSince) because those bound the query; the rest are
 * optional.
 */
final class WorkQuery {

    /**
     * Sort modes (v4.5.25). These order items *within* a group; the groups
     * themselves are always ordered by category urgency, because that is the
     * structure of the page rather than a preference.
     */
    /**
     * Fallback retention window for the Completed category, in days.
     *
     * The configured value comes from MyWorkConfigService and reaches a query
     * through `$completedDays`. This constant is the default that applies when
     * nothing has been configured, and it exists as a name so a provider
     * re-reading a single completed item outside a real query can use the same
     * window rather than its own literal.
     */
    public const DEFAULT_COMPLETED_DAYS = 7;

    public const SORT_DEADLINE = 'deadline';
    public const SORT_PRIORITY = 'priority';
    public const SORT_TEAM     = 'team';
    public const SORT_RECENT   = 'recent';

    public const SORTS = [
        self::SORT_DEADLINE,
        self::SORT_PRIORITY,
        self::SORT_TEAM,
        self::SORT_RECENT,
    ];

    public static function isValidSort(string $sort): bool {
        return in_array($sort, self::SORTS, true);
    }

    /**
     * @param string   $userId          calling user's UID
     * @param string[] $teamIds         teams the user may see — the authorisation boundary
     * @param array<string,string> $teamNames  teamId => display name
     * @param string[] $categories      restrict to these categories ([] = all)
     * @param string[] $providerIds     restrict to these providers ([] = all enabled)
     * @param string[] $resourceTypes   restrict to these resource types ([] = all)
     * @param string[] $priorities      restrict to these priorities ([] = all)
     * @param string[] $statuses        restrict to these provider statuses ([] = all)
     * @param string   $search          free-text, matched against title/subtitle/team
     * @param int|null $dueFrom         lower bound on dueAt (Unix seconds)
     * @param int|null $dueTo           upper bound on dueAt (Unix seconds)
     * @param bool     $includeSnoozed  when false, snoozed items are hidden
     * @param bool     $includeCompleted fetch the Completed section at all
     * @param int      $upcomingDays    horizon for the Upcoming category
     * @param int      $actionRequiredDays how many days before its due date an
     *                                  item becomes Action required (0 = only
     *                                  overdue items are actionable)
     * @param int      $completedDays   retention window for the Completed category
     * @param int      $now             clock, injected so tests and the TODAY
     *                                  derivation share one "now"
     * @param int      $limit           page size after merge
     * @param int      $offset          page offset after merge
     * @param int      $perProviderCap  hard ceiling on rows a single provider may
     *                                  return, so one noisy source cannot starve
     *                                  the others out of the merged page
     * @param string   $sortBy          one of SORTS; orders items within a group
     * @param bool     $isInstanceAdmin viewer holds Nextcloud admin. Set from
     *                                  IGroupManager, never from the request.
     * @param bool     $teamFilterActive the client narrowed to specific teams,
     *                                  which disables the instance-scope bypass
     * @param string[] $instanceScopedProviderIds providers allowed past the
     *                                  membership filter for this viewer
     */
    public function __construct(
        public readonly string $userId,
        public readonly array $teamIds,
        public readonly array $teamNames = [],
        public readonly array $categories = [],
        public readonly array $providerIds = [],
        public readonly array $resourceTypes = [],
        public readonly array $priorities = [],
        public readonly array $statuses = [],
        public readonly string $search = '',
        public readonly ?int $dueFrom = null,
        public readonly ?int $dueTo = null,
        public readonly bool $includeSnoozed = false,
        public readonly bool $includeCompleted = true,
        public readonly int $upcomingDays = 7,
        public readonly int $actionRequiredDays = 2,
        public readonly int $completedDays = self::DEFAULT_COMPLETED_DAYS,
        public readonly int $now = 0,
        public readonly int $limit = 50,
        public readonly int $offset = 0,
        public readonly int $perProviderCap = 200,
        public readonly string $sortBy = self::SORT_DEADLINE,
        public readonly bool $isInstanceAdmin = false,
        public readonly bool $teamFilterActive = false,
        public readonly array $instanceScopedProviderIds = [],
    ) {
    }

    /** Resolved display name for a team, falling back to the raw id. */
    public function teamName(string $teamId): string {
        return $this->teamNames[$teamId] ?? $teamId;
    }

    /**
     * May this provider's items skip the membership filter for this viewer?
     *
     * See the class docblock. All three conditions must hold, and the first two
     * are decided by the server rather than the request.
     */
    public function bypassesTeamScope(string $providerId): bool {
        return $this->isInstanceAdmin
            && !$this->teamFilterActive
            && in_array($providerId, $this->instanceScopedProviderIds, true);
    }

    /** True when the caller asked for this category (or for everything). */
    public function wantsCategory(string $category): bool {
        return $this->categories === [] || in_array($category, $this->categories, true);
    }

    /**
     * Upper bound on due dates worth fetching at all: the end of the Upcoming
     * horizon. Providers use this to bound their SQL. Overdue items have a
     * due date in the past and are always in range, so there is no lower bound.
     */
    public function dueHorizon(): int {
        return $this->now + ($this->upcomingDays * 86400);
    }

    /** Earliest completion timestamp still worth showing under Completed. */
    public function completedSince(): int {
        return $this->now - ($this->completedDays * 86400);
    }

    /** Start of the current day in the server's timezone. */
    public function startOfToday(): int {
        return (int)strtotime('today', $this->now);
    }

    /** First second of tomorrow — the exclusive upper bound of "today". */
    public function endOfToday(): int {
        return (int)strtotime('tomorrow', $this->now);
    }

    /**
     * The moment up to which a dated item counts as needing action now.
     *
     * Anything due at or before this is Action required, whether or not the
     * deadline has passed — waiting for it to pass is too late by definition.
     */
    public function actionRequiredBy(): int {
        return $this->now + ($this->actionRequiredDays * 86400);
    }
}
