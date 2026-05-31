<?php

declare(strict_types=1);

namespace OCA\TeamHub\Service\Suggestion;

/**
 * A source of "busy" intervals for a set of users over a time window.
 *
 * The meeting-time scorer asks one or more providers for the absolute-time
 * intervals during which each user is already occupied, then treats any
 * candidate slot that overlaps such an interval as a conflict for that user.
 *
 * v1 ships a single implementation backed by the team calendar
 * (TeamCalendarBusyProvider). A future session can add a provider backed by
 * each member's personal calendar free/busy (CalDAV) and register it
 * alongside this one — the scorer consumes the merged result and needs no
 * change. This is the seam that keeps personal-calendar free/busy (deferred
 * to the next session) from requiring a scorer rewrite.
 */
interface BusyProviderInterface {

    /**
     * Return busy intervals per user across [$startTs, $endTs).
     *
     * Implementations must be resilient: a failure to read one source must
     * not throw, but return what it can (an empty array for the failed user),
     * so a single broken calendar can never sink the whole suggestion.
     *
     * @param string   $teamId  The team whose context this query runs in.
     * @param string[] $userIds The users to fetch busy intervals for.
     * @param int      $startTs Unix timestamp, inclusive lower bound.
     * @param int      $endTs   Unix timestamp, exclusive upper bound.
     *
     * @return array<string, array<int, array{start: int, end: int}>>
     *         Map of userId → list of {start, end} unix-timestamp intervals.
     *         Users with no busy intervals may be omitted or map to [].
     */
    public function getBusyIntervals(string $teamId, array $userIds, int $startTs, int $endTs): array;
}
