<?php

declare(strict_types=1);

namespace OCA\TeamHub\Service\Suggestion;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\MemberService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Busy intervals derived from each attendee's accessible calendars.
 *
 * What "accessible" means here (scoping decision locked in session 9):
 *   - The attendee's own personal calendars (anything owned by their
 *     `principals/users/{uid}` principal). Picks up their default
 *     `personal` calendar AND any extra personal calendars they've
 *     created (work, side-project, etc.).
 *   - Calendars owned by any TEAM whose principal collection they are a
 *     member of (`principals/circles/{teamId}` for each team). That's
 *     what fixes the cross-team blind spot: if user X is in team Y and
 *     team Z, and team Y already has a 10:00 meeting on Thursday, the
 *     wizard run from team Z must see X as busy at 10:00 Thursday.
 *
 * Out of scope (deliberately):
 *   - Calendars shared to the user individually via `dav_shares` outside
 *     any TeamHub-managed team. Including these would require a per-call
 *     share-table join across many users and pull in calendars TeamHub
 *     doesn't curate. Cost / benefit doesn't justify it for the
 *     scheduling-correctness goal.
 *
 * Performance shape:
 *   For each user we run ONE query that pulls VEVENT rows from all of
 *   their accessible calendars in the window. Even at a 33-person team
 *   with ~3 teams each (= ~4 calendars per attendee), the per-call cost
 *   is bounded by 33 small SELECTs returning a few rows each — single-
 *   digit-millisecond range total. We could go further (a single global
 *   IN-clause query across all attendees, partitioning by attendee in
 *   PHP), but per-user keeps the privacy story clear: we only ever read
 *   events that THIS user can see.
 *
 * Privacy:
 *   This provider returns only {start, end} integer timestamps. It never
 *   stores or surfaces event titles, attendees, UIDs, descriptions, or
 *   locations. The upstream scorer collapses these to a per-half-day
 *   conflict count — the wizard caller never even sees the intervals.
 *   Cross-team events from calendars the wizard caller can't otherwise
 *   see WILL contribute to a user's busy state; that's the whole point
 *   of the feature (scheduling needs to know X is busy), and the design
 *   §2.x scoping decision explicitly accepts this signal as legitimate.
 */
class PersonalAndTeamBusyProvider implements BusyProviderInterface {

    public function __construct(
        private MemberService    $memberService,
        private IDBConnection    $db,
        private LoggerInterface  $logger,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getBusyIntervals(string $teamId, array $userIds, int $startTs, int $endTs): array {
        $out = [];
        foreach ($userIds as $uid) {
            try {
                $out[$uid] = $this->readBusyForUser($uid, $startTs, $endTs);
            } catch (\Throwable $e) {
                // Interface contract: a failure on one user must not sink
                // the whole call. Log and omit (treated as "no known
                // intervals" by the scorer — falls through to presence).
                $this->logger->debug('[TeamHub][PersonalAndTeamBusyProvider] read failed for user', [
                    'uid' => $uid,
                    'err' => $e->getMessage(),
                    'app' => Application::APP_ID,
                ]);
            }
        }
        return $out;
    }

    /**
     * @return array<int, array{start: int, end: int}>
     */
    private function readBusyForUser(string $uid, int $startTs, int $endTs): array {
        $calendarIds = $this->resolveAccessibleCalendarIds($uid);
        if ($calendarIds === []) {
            return [];
        }

        // One query, all calendar ids in the IN-clause.
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('calendardata')
            ->from('calendarobjects')
            ->where($qb->expr()->in('calendarid', $qb->createNamedParameter($calendarIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('componenttype', $qb->createNamedParameter('VEVENT')))
            // firstoccurence / lastoccurence are NC's pre-computed bounds.
            // Cheap pre-filter — Sabre maintains these on every write.
            // Using lt/gt with windowed bounds catches any overlap.
            ->andWhere($qb->expr()->lt('firstoccurence', $qb->createNamedParameter($endTs, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gt('lastoccurence', $qb->createNamedParameter($startTs, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->notLike('uri', $qb->createNamedParameter('%-deleted.ics')))
            ->executeQuery();

        $intervals = [];
        while ($row = $res->fetch()) {
            try {
                $vcal = \Sabre\VObject\Reader::read($row['calendardata']);
                if (!isset($vcal->VEVENT)) {
                    continue;
                }
                // Find a UID; recurring events have one UID across the
                // master + override VEVENTs in the same VCALENDAR.
                $uid = null;
                foreach ($vcal->VEVENT as $vevent) {
                    if (isset($vevent->UID)) {
                        $uid = (string)$vevent->UID;
                        break;
                    }
                }

                // Three execution paths:
                //   1. VCALENDAR has an RRULE somewhere on its master event:
                //      use Sabre's EventIterator to expand occurrences.
                //   2. VCALENDAR has overrides but no master RRULE: treat
                //      each VEVENT as a standalone (rare; malformed).
                //   3. Single-instance event: read directly.
                $hasRRule = false;
                foreach ($vcal->VEVENT as $vevent) {
                    if (isset($vevent->RRULE)) {
                        $hasRRule = true;
                        break;
                    }
                }

                if ($hasRRule && $uid !== null) {
                    // Sabre's EventIterator iterates concrete occurrences
                    // of a recurring master, applying RECURRENCE-ID
                    // overrides and EXDATE exclusions along the way. We
                    // construct a UTC DateTime range and let the iterator
                    // walk it.
                    try {
                        $iter = new \Sabre\VObject\Recur\EventIterator(
                            $vcal,
                            $uid,
                            new \DateTimeZone('UTC'),
                        );
                        // Fast-forward to the window. fastForward() jumps
                        // the cursor to the first occurrence at or after
                        // its argument — avoids walking every weekly
                        // occurrence since the master DTSTART (which for
                        // a year-old weekly recurrence is ~50 useless
                        // iterations).
                        $iter->fastForward(new \DateTimeImmutable('@' . $startTs));
                        $safety = 0; // Belt-and-braces: hard cap on the loop in
                                     // case Sabre gives us a misbehaving RRULE
                                     // (e.g. MINUTELY recurrence). 1000 hits
                                     // is enormous for any realistic window.
                        while ($iter->valid() && $safety < 1000) {
                            $safety++;
                            $occStart = $iter->getDtStart();
                            if ($occStart->getTimestamp() >= $endTs) {
                                break;
                            }
                            $occEnd = $iter->getDtEnd();
                            $evStart = $occStart->getTimestamp();
                            $evEnd   = $occEnd ? $occEnd->getTimestamp() : ($evStart + 1800);
                            if ($evEnd > $startTs) {
                                // Honor STATUS/TRANSP on THIS occurrence
                                // (override may have re-marked it).
                                $event = $iter->getEventObject();
                                if (!$this->isOccurrenceBusy($event)) {
                                    $iter->next();
                                    continue;
                                }
                                $intervals[] = ['start' => $evStart, 'end' => $evEnd];
                            }
                            $iter->next();
                        }
                    } catch (\Throwable $e) {
                        // Sabre throws on malformed RRULE / no occurrences.
                        // Silent skip — common in the wild, not actionable.
                        $this->logger->debug('[TeamHub][PersonalAndTeamBusyProvider] RRULE expansion failed', [
                            'uid' => $uid, 'err' => $e->getMessage(), 'app' => Application::APP_ID,
                        ]);
                    }
                } else {
                    // Non-recurring: per-VEVENT path (original behaviour).
                    foreach ($vcal->VEVENT as $vevent) {
                        $iv = $this->veventToInterval($vevent, $startTs, $endTs);
                        if ($iv !== null) {
                            $intervals[] = $iv;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // One bad event must not poison the rest of the user's
                // read. We choose silence-over-noise here because parse
                // failures on third-party-authored events are common in
                // the wild and not actionable for TeamHub.
            }
        }
        $res->closeCursor();

        return $intervals;
    }

    /**
     * STATUS / TRANSP check for a single VEVENT (used for both the
     * non-recurring path and recurrence overrides). Returns false when
     * the event should NOT count as busy.
     */
    private function isOccurrenceBusy(\Sabre\VObject\Component $vevent): bool {
        if (isset($vevent->STATUS)) {
            $status = strtoupper((string)$vevent->STATUS);
            if ($status === 'CANCELLED') {
                return false;
            }
        }
        if (isset($vevent->TRANSP)) {
            $transp = strtoupper((string)$vevent->TRANSP);
            if ($transp === 'TRANSPARENT') {
                return false;
            }
        }
        return true;
    }

    /**
     * Apply the same VEVENT-to-interval rules as TimeslotSuggestionService:
     * skip CANCELLED, skip TRANSPARENT, prune outside window, handle
     * DTEND vs DURATION vs implicit duration.
     *
     * Note on recurrence: we do NOT expand RRULE here. That matches the
     * data fidelity of the existing TeamCalendarBusyProvider — both
     * operate on master-event timestamps. Improving recurrence handling
     * is a future-session concern that should apply to all providers
     * symmetrically.
     *
     * @return array{start: int, end: int}|null
     */
    private function veventToInterval(\Sabre\VObject\Component $vevent, int $startTs, int $endTs): ?array {
        if (!isset($vevent->DTSTART)) {
            return null;
        }
        if (isset($vevent->STATUS)) {
            $status = strtoupper((string)$vevent->STATUS);
            if ($status === 'CANCELLED') {
                return null;
            }
        }
        if (isset($vevent->TRANSP)) {
            $transp = strtoupper((string)$vevent->TRANSP);
            if ($transp === 'TRANSPARENT') {
                return null;
            }
        }
        $dtstart = $vevent->DTSTART;
        try {
            $startTime = $dtstart->getDateTime();
        } catch (\Throwable $e) {
            return null;
        }
        $evStart = $startTime->getTimestamp();
        if (isset($vevent->DTEND)) {
            try {
                $evEnd = $vevent->DTEND->getDateTime()->getTimestamp();
            } catch (\Throwable $e) {
                return null;
            }
        } elseif (isset($vevent->DURATION)) {
            try {
                $end = clone $startTime;
                $end->add($vevent->DURATION->getDateInterval());
                $evEnd = $end->getTimestamp();
            } catch (\Throwable $e) {
                return null;
            }
        } else {
            // RFC 5545: VEVENT with no DTEND or DURATION → zero duration
            // (timed) or 1 day (all-day). 30-min sentinel for timed
            // matches sibling providers.
            $evEnd = $dtstart->hasTime() ? $evStart + 1800 : $evStart + 86400;
        }
        if ($evEnd <= $startTs || $evStart >= $endTs) {
            return null;
        }
        return ['start' => $evStart, 'end' => $evEnd];
    }

    /**
     * Calendar IDs the user can attend events on:
     *   1. Calendars owned by their personal principal.
     *   2. Calendars owned by each team they're a member of (effective
     *      membership: direct + via group).
     *
     * @return int[] calendar ids
     */
    private function resolveAccessibleCalendarIds(string $uid): array {
        // 1. Personal principal: principals/users/{uid}
        $principals = ['principals/users/' . $uid];

        // 2. Team principals. Effective membership = the user's single_id
        //    appears in circles_membership.single_id, joined back to the
        //    enclosing circle. circles_membership materializes both direct
        //    and via-group memberships, which is what we want.
        try {
            $single = $this->memberService->resolveUserSingleId($uid, $this->db);
            if ($single !== null && $single !== '') {
                $qb = $this->db->getQueryBuilder();
                // circles_membership.circle_id is the team's unique_id
                // (the same string TeamHub uses as the team_id and as the
                // principal segment).
                $res = $qb->selectDistinct('circle_id')
                    ->from('circles_membership')
                    ->where($qb->expr()->eq('single_id', $qb->createNamedParameter($single)))
                    ->executeQuery();
                while ($row = $res->fetch()) {
                    $tid = (string)$row['circle_id'];
                    if ($tid !== '') {
                        $principals[] = 'principals/circles/' . $tid;
                    }
                }
                $res->closeCursor();
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][PersonalAndTeamBusyProvider] team lookup failed', [
                'uid' => $uid, 'err' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        $qb = $this->db->getQueryBuilder();
        $res = $qb->select('id')
            ->from('calendars')
            ->where($qb->expr()->in('principaluri', $qb->createNamedParameter($principals, IQueryBuilder::PARAM_STR_ARRAY)))
            ->executeQuery();
        $ids = [];
        while ($row = $res->fetch()) {
            $ids[] = (int)$row['id'];
        }
        $res->closeCursor();
        return $ids;
    }
}
