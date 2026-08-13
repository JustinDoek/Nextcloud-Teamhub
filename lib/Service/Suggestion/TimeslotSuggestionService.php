<?php

declare(strict_types=1);

namespace OCA\TeamHub\Service\Suggestion;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\TimezoneService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Psr\Log\LoggerInterface;

/**
 * Stage-two suggester: given a half-day the user already picked (by going
 * through MeetingSuggestionService and choosing one of its half-day
 * suggestions), drill into that half-day and surface the best concrete
 * time windows of a requested duration.
 *
 * Why a separate service from MeetingSuggestionService:
 *   - The half-day scorer operates at half-day granularity over a week-ish
 *     window, scored on presence + team-calendar conflicts. Its unit of
 *     decision is "Tuesday morning vs Wednesday afternoon".
 *   - This service operates at 15-minute granularity inside a single
 *     half-day, scored on personal calendars. Its unit of decision is
 *     "10:00-11:00 vs 11:15-12:15".
 *   Different inputs, different algorithm, different output shape — they
 *   share only the team and the attendee list, so a single class would
 *   conflate two concerns. Keep them apart.
 *
 * Privacy contract:
 *   This service reads other users' personal-calendar event time-ranges
 *   directly via the calendarobjects table (mirroring the read-pattern
 *   the team-cal provider already uses through ActivityService — same
 *   trust model as the existing approved code path). It NEVER returns
 *   event titles, descriptions, attendees, UIDs, locations, or any
 *   identifying detail upstream; only integer timestamps. The class
 *   itself encloses everything that touches event data.
 *
 * Fall-back rule:
 *   The half-day was already chosen because presence said these
 *   attendees are available for it. If a user's calendar cannot be read
 *   (no personal calendar, app not installed, parse failure), this
 *   service treats them as having no events in the window — i.e. the
 *   presence verdict stands. This matches the layered design agreed in
 *   the design session for this feature.
 */
class TimeslotSuggestionService {

    /** Earliest start-of-meeting allowed in the morning half (organiser TZ). */
    public const MORNING_SEARCH_START_HOUR = 9;
    /** Latest end-of-meeting allowed in the morning half (organiser TZ). */
    public const MORNING_SEARCH_END_HOUR = 12;
    /** Earliest start-of-meeting allowed in the afternoon half (organiser TZ). */
    public const AFTERNOON_SEARCH_START_HOUR = 13;
    /** Latest end-of-meeting allowed in the afternoon half (organiser TZ). */
    public const AFTERNOON_SEARCH_END_HOUR = 17;

    /** Search grid: try every 15 minutes. */
    public const GRID_MINUTES = 15;

    /** Hard floor / ceiling on meeting duration the caller may request. */
    public const MIN_DURATION_MINUTES = 15;
    public const MAX_DURATION_MINUTES = 240;
    public const DEFAULT_DURATION_MINUTES = 60;

    /** Max distinct windows we return. */
    public const MAX_RESULTS = 3;

    public function __construct(
        private MemberService     $memberService,
        private IDBConnection     $db,
        private IConfig           $config,
        private TimezoneService   $timezoneService,
        private LoggerInterface   $logger,
    ) {
    }

    /**
     * @param string $teamId       Team the suggestion is scoped to.
     * @param string $date         Half-day's date, YYYY-MM-DD (organiser TZ).
     * @param int    $half         0 = morning, 1 = afternoon.
     * @param string $organiserUid The organiser, used for timezone resolution.
     * @param int    $durationMin  Requested meeting length in minutes.
     * @param array  $attendeeIds  Attendees; empty array = all team members.
     *
     * @return array{
     *   suggestions: array<int, array{
     *       start: string,
     *       end: string,
     *       startTs: int,
     *       endTs: int,
     *       availableCount: int,
     *       attendeeCount: int,
     *       conflictCount: int
     *   }>,
     *   attendeeCount: int,
     *   durationMinutes: int
     * }
     */
    public function suggest(
        string $teamId,
        string $date,
        int $half,
        string $organiserUid,
        int $durationMin = self::DEFAULT_DURATION_MINUTES,
        array $attendeeIds = [],
        string $meetingType = 'online',
        string $buildingName = '',
    ): array {
        $meetingType = $meetingType === 'office' ? 'office' : 'online';
        // Clamp duration into sane bounds. Caller may have already validated,
        // but defence in depth: the service must never search a negative or
        // absurd window.
        if ($durationMin < self::MIN_DURATION_MINUTES) {
            $durationMin = self::MIN_DURATION_MINUTES;
        }
        if ($durationMin > self::MAX_DURATION_MINUTES) {
            $durationMin = self::MAX_DURATION_MINUTES;
        }

        if ($half !== 0 && $half !== 1) {
            return ['suggestions' => [], 'attendeeCount' => 0, 'durationMinutes' => $durationMin];
        }

        $attendees = $this->resolveAttendees($teamId, $attendeeIds, $organiserUid);
        if ($attendees === []) {
            return ['suggestions' => [], 'attendeeCount' => 0, 'durationMinutes' => $durationMin];
        }

        $tz = $this->userTimezone($organiserUid);

        // Search bounds in absolute time, in organiser TZ.
        try {
            $dayMidnight = new \DateTimeImmutable($date . ' 00:00:00', $tz);
        } catch (\Throwable $e) {
            return ['suggestions' => [], 'attendeeCount' => count($attendees), 'durationMinutes' => $durationMin];
        }

        if ($half === 0) {
            $searchStart = $dayMidnight->setTime(self::MORNING_SEARCH_START_HOUR, 0, 0);
            $searchEnd   = $dayMidnight->setTime(self::MORNING_SEARCH_END_HOUR, 0, 0);
        } else {
            $searchStart = $dayMidnight->setTime(self::AFTERNOON_SEARCH_START_HOUR, 0, 0);
            $searchEnd   = $dayMidnight->setTime(self::AFTERNOON_SEARCH_END_HOUR, 0, 0);
        }

        $searchStartTs = $searchStart->getTimestamp();
        $searchEndTs   = $searchEnd->getTimestamp();
        $durationSec   = $durationMin * 60;

        // If the requested duration doesn't even fit in the half's search
        // window, surface zero results — the caller (UI) can ask for shorter.
        if ($searchStartTs + $durationSec > $searchEndTs) {
            return ['suggestions' => [], 'attendeeCount' => count($attendees), 'durationMinutes' => $durationMin];
        }

        // Read each attendee's personal-calendar busy intervals across the
        // search window. The window is small (a few hours), so we read once
        // per attendee, not per candidate.
        $busyByUser = [];
        foreach ($attendees as $uid) {
            $busyByUser[$uid] = $this->readPersonalBusy($uid, $searchStartTs, $searchEndTs);
        }

        // Walk the grid. For each candidate start, count how many attendees
        // have NO overlapping busy interval (= available). Tie-breaks:
        // (1) more available, (2) on-the-hour, (3) earlier start.
        $gridSec   = self::GRID_MINUTES * 60;
        $candidates = [];
        for ($startTs = $searchStartTs; $startTs + $durationSec <= $searchEndTs; $startTs += $gridSec) {
            $endTs = $startTs + $durationSec;
            $available = 0;
            foreach ($attendees as $uid) {
                if (!$this->hasConflict($busyByUser[$uid] ?? [], $startTs, $endTs)) {
                    $available++;
                }
            }
            $isOnHour = ((int)(($startTs - $searchStartTs) % 3600) === 0) ? 1 : 0;
            $candidates[] = [
                'startTs'   => $startTs,
                'endTs'     => $endTs,
                'available' => $available,
                'onHour'    => $isOnHour,
            ];
        }

        // Rank: descending available, descending onHour, ascending start.
        usort($candidates, static function (array $a, array $b): int {
            if ($a['available'] !== $b['available']) {
                return $b['available'] <=> $a['available'];
            }
            if ($a['onHour'] !== $b['onHour']) {
                return $b['onHour'] <=> $a['onHour'];
            }
            return $a['startTs'] <=> $b['startTs'];
        });

        // De-duplicate near-misses: take the top-ranked windows that do not
        // overlap each other, so the user sees 3 visibly distinct options
        // rather than 09:00 / 09:15 / 09:30.
        $picked = [];
        foreach ($candidates as $c) {
            $overlaps = false;
            foreach ($picked as $p) {
                if ($c['startTs'] < $p['endTs'] && $c['endTs'] > $p['startTs']) {
                    $overlaps = true;
                    break;
                }
            }
            if (!$overlaps) {
                $picked[] = $c;
            }
            if (count($picked) >= self::MAX_RESULTS) {
                break;
            }
        }

        // Re-sort the final picks chronologically for display.
        usort($picked, static fn (array $a, array $b): int => $a['startTs'] <=> $b['startTs']);

        $attendeeCount = count($attendees);
        $suggestions = [];
        // Echo the building back on each suggestion only when it's both
        // an office meeting AND we have a known building from the half-day
        // scorer. The timeslot suggester does not recompute this — within
        // one half-day every attendee's presence is the same, so the
        // building does not vary between e.g. 13:00-14:00 and 14:00-15:00.
        $emitBuilding = $meetingType === 'office' && $buildingName !== '';
        foreach ($picked as $p) {
            $row = [
                'start'          => (new \DateTimeImmutable('@' . $p['startTs']))->setTimezone($tz)->format('H:i'),
                'end'            => (new \DateTimeImmutable('@' . $p['endTs']))->setTimezone($tz)->format('H:i'),
                'startTs'        => $p['startTs'],
                'endTs'          => $p['endTs'],
                'availableCount' => $p['available'],
                'attendeeCount'  => $attendeeCount,
                'conflictCount'  => $attendeeCount - $p['available'],
            ];
            if ($emitBuilding) {
                $row['bestBuildingName'] = $buildingName;
            }
            $suggestions[] = $row;
        }

        $this->logger->debug('[TeamHub][TimeslotSuggestionService] suggest', [
            'teamId'         => $teamId,
            'date'           => $date,
            'half'           => $half,
            'durationMin'    => $durationMin,
            'meetingType'    => $meetingType,
            'attendeeCount'  => $attendeeCount,
            'candidateCount' => count($candidates),
            'returned'       => count($suggestions),
            'app'            => Application::APP_ID,
        ]);

        return [
            'suggestions'     => $suggestions,
            'attendeeCount'   => $attendeeCount,
            'durationMinutes' => $durationMin,
            'meetingType'     => $meetingType,
            'bestBuildingName' => $emitBuilding ? $buildingName : null,
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Intersect the requested attendee ids with actual team members. Empty
     * input = everyone. Never returns a non-member. Mirrors the rule used by
     * MeetingSuggestionService so the two services agree on who's invited.
     *
     * @param string[] $attendeeIds
     * @return string[]
     */
    private function resolveAttendees(string $teamId, array $attendeeIds, string $organiserUid = ''): array {
        $members = $this->memberService->getAllEffectiveMembers($teamId);
        $memberIds = array_map(static fn ($m) => (string)$m['userId'], $members);
        if ($attendeeIds === []) {
            return $memberIds;
        }
        $set = array_flip($memberIds);
        $out = [];
        $seen = [];
        // Organiser is always counted — they're on the meeting by
        // definition. See MeetingSuggestionService::resolveAttendees for
        // the full rationale; this is the symmetric implementation.
        if ($organiserUid !== '' && isset($set[$organiserUid])) {
            $out[] = $organiserUid;
            $seen[strtolower($organiserUid)] = true;
        }
        foreach ($attendeeIds as $id) {
            $id = (string)$id;
            if (!isset($set[$id])) {
                continue;
            }
            $key = strtolower($id);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $id;
        }
        return $out;
    }

    /**
     * Read a user's personal-calendar busy intervals overlapping the window.
     *
     * Behaviour:
     *   - Identifies the principal's default personal calendar by URI
     *     'personal' first. If that calendar doesn't exist (deleted,
     *     renamed, install-specific), falls back to the first calendar
     *     owned by the principal (alphabetical).
     *   - Reads VEVENTs from that one calendar only — never shared-in
     *     calendars, never tasks/journals.
     *   - Honours iCalendar TRANSP: only OPAQUE events (or events with
     *     TRANSP omitted, which RFC 5545 defines as OPAQUE) are busy.
     *     TRANSPARENT events (e.g. all-day "WFH" or birthdays) are skipped.
     *   - Honours STATUS: CANCELLED events are skipped; TENTATIVE and
     *     CONFIRMED are busy.
     *   - Does NOT expand recurring-event occurrences beyond the master
     *     event row. This matches the data fidelity of the existing
     *     team-cal provider (ActivityService::getTeamCalendarEventsForWeek)
     *     — both operate on master-event timestamps. Improving recurrence
     *     handling is a future-session concern that should apply to both
     *     providers symmetrically.
     *   - On ANY failure (no calendar, parse error, DB error) returns []
     *     and logs at debug. The presence verdict stands; the user is
     *     treated as free for this window.
     *
     * Never returns event titles, attendees, UIDs, descriptions, locations,
     * or any other identifying detail. Only {start, end} integer timestamps.
     *
     * @return array<int, array{start: int, end: int}>
     */
    /**
     * Read a user's busy intervals across every calendar they can attend
     * events on:
     *   - personal calendars they own (principals/users/{uid})
     *   - calendars of every team they're a member of (principals/circles/{teamId})
     *
     * This matches the scope used by PersonalAndTeamBusyProvider on the
     * half-day scorer side. Previously this method read only the user's
     * `personal` default calendar, which missed cross-team conflicts —
     * if user X has a meeting in team Y at 10:00, a wizard run from
     * team Z would not see it. With this change, both code paths
     * (half-day scoring and timeslot picking) consult the same set of
     * calendars and agree on what counts as a conflict.
     *
     * Privacy: still returns only {start, end} integer timestamps. Never
     * stores or surfaces event titles, attendees, UIDs, descriptions, or
     * locations.
     *
     * @return array<int, array{start: int, end: int}>
     */
    private function readPersonalBusy(string $uid, int $startTs, int $endTs): array {
        try {
            $calendarIds = $this->resolveAccessibleCalendarIds($uid);
            if ($calendarIds === []) {
                $this->logger->debug('[TeamHub][TimeslotSuggestionService] no accessible calendars for user', [
                    'uid' => $uid,
                    'app' => Application::APP_ID,
                ]);
                return [];
            }

            // One query, all calendar ids in the IN-clause. Same shape as
            // PersonalAndTeamBusyProvider — we keep the two implementations
            // separate (rather than sharing a helper) because TimeslotSugges-
            // tionService is the inner-loop service consulted per half-day
            // and a shared helper would make its hot path harder to read.
            // If a third caller appears we should factor it out.
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('uri', 'calendardata')
                ->from('calendarobjects')
                ->where($qb->expr()->in('calendarid', $qb->createNamedParameter($calendarIds, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->eq('componenttype', $qb->createNamedParameter('VEVENT')))
                // Pre-filter on Sabre's pre-computed bounds. Cheap and
                // safe — Sabre updates these on every write.
                ->andWhere($qb->expr()->lt('firstoccurence', $qb->createNamedParameter($endTs, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->gt('lastoccurence', $qb->createNamedParameter($startTs, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->notLike('uri', $qb->createNamedParameter('%-deleted.ics')))
                ->executeQuery();

            $intervals = [];
            while ($row = $res->fetch()) {
                try {
                    $vcalendar = \Sabre\VObject\Reader::read($row['calendardata']);
                    if (!isset($vcalendar->VEVENT)) {
                        continue;
                    }

                    // Detect recurring events. A VCALENDAR with a master
                    // VEVENT carrying RRULE needs Sabre's EventIterator to
                    // expand occurrences within the window — without this,
                    // a weekly meeting from months ago whose master DTSTART
                    // is outside our window is invisible to the picker.
                    // Symmetric with PersonalAndTeamBusyProvider; see that
                    // class for the original commentary on this fix.
                    $uidProp = null;
                    $hasRRule = false;
                    foreach ($vcalendar->VEVENT as $vevent) {
                        if ($uidProp === null && isset($vevent->UID)) {
                            $uidProp = (string)$vevent->UID;
                        }
                        if (isset($vevent->RRULE)) {
                            $hasRRule = true;
                        }
                    }

                    if ($hasRRule && $uidProp !== null) {
                        try {
                            $iter = new \Sabre\VObject\Recur\EventIterator(
                                $vcalendar,
                                $uidProp,
                                new \DateTimeZone('UTC'),
                            );
                            $iter->fastForward(new \DateTimeImmutable('@' . $startTs));
                            $safety = 0;
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
                                    $event = $iter->getEventObject();
                                    // STATUS / TRANSP filter for THIS
                                    // occurrence (overrides may re-mark).
                                    $skip = false;
                                    if (isset($event->STATUS) && strtoupper((string)$event->STATUS) === 'CANCELLED') {
                                        $skip = true;
                                    } elseif (isset($event->TRANSP) && strtoupper((string)$event->TRANSP) === 'TRANSPARENT') {
                                        $skip = true;
                                    }
                                    if (!$skip) {
                                        $intervals[] = ['start' => $evStart, 'end' => $evEnd];
                                    }
                                }
                                $iter->next();
                            }
                        } catch (\Throwable $e) {
                            $this->logger->debug('[TeamHub][TimeslotSuggestionService] RRULE expansion failed', [
                                'uid' => $uid, 'err' => $e->getMessage(), 'app' => Application::APP_ID,
                            ]);
                        }
                        continue; // Done with this row.
                    }

                    // Non-recurring path (original inline logic).
                    foreach ($vcalendar->VEVENT as $vevent) {
                        if (!isset($vevent->DTSTART)) {
                            continue;
                        }

                        // STATUS:CANCELLED → not busy. Anything else → busy.
                        if (isset($vevent->STATUS)) {
                            $status = strtoupper((string)$vevent->STATUS);
                            if ($status === 'CANCELLED') {
                                continue;
                            }
                        }

                        // TRANSP:TRANSPARENT → user explicitly marked as not
                        // busy. RFC 5545 default when absent is OPAQUE (=
                        // busy), so absence does NOT skip.
                        if (isset($vevent->TRANSP)) {
                            $transp = strtoupper((string)$vevent->TRANSP);
                            if ($transp === 'TRANSPARENT') {
                                continue;
                            }
                        }

                        $dtstart   = $vevent->DTSTART;
                        $startTime = $dtstart->getDateTime();
                        $evStart   = $startTime->getTimestamp();

                        if (isset($vevent->DTEND)) {
                            $evEnd = $vevent->DTEND->getDateTime()->getTimestamp();
                        } elseif (isset($vevent->DURATION)) {
                            $end   = clone $startTime;
                            $end->add($vevent->DURATION->getDateInterval());
                            $evEnd = $end->getTimestamp();
                        } else {
                            // RFC 5545: a VEVENT without DTEND or DURATION
                            // has an implicit duration of zero (timed) or
                            // 1 day (all-day). Treat zero-length timed
                            // events as a 30-minute block so they still
                            // register near the start, matching sibling
                            // providers' behaviour.
                            $evEnd = $dtstart->hasTime() ? $evStart + 1800 : $evStart + 86400;
                        }

                        if ($evEnd <= $startTs || $evStart >= $endTs) {
                            continue;
                        }

                        $intervals[] = ['start' => $evStart, 'end' => $evEnd];
                    }
                } catch (\Throwable $e) {
                    // One bad event must not poison the whole user's read.
                    $this->logger->debug('[TeamHub][TimeslotSuggestionService] VEVENT parse failed', [
                        'uid' => $uid,
                        'uri' => (string)($row['uri'] ?? ''),
                        'err' => $e->getMessage(),
                        'app' => Application::APP_ID,
                    ]);
                }
            }
            $res->closeCursor();
            return $intervals;
        } catch (\Throwable $e) {
            // DB or schema error: silent, user falls back to presence-only.
            $this->logger->debug('[TeamHub][TimeslotSuggestionService] readPersonalBusy failed', [
                'uid' => $uid,
                'err' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Calendar IDs the user can attend events on. Mirrors
     * PersonalAndTeamBusyProvider::resolveAccessibleCalendarIds — see
     * that class's docblock for the scoping rationale (personal +
     * team-membership; no third-party shares).
     *
     * @return int[] calendar ids
     */
    private function resolveAccessibleCalendarIds(string $uid): array {
        $principals = ['principals/users/' . $uid];

        // Resolve team membership via circles_membership (which
        // materializes both direct AND via-group membership), keyed by
        // the user's single_id.
        try {
            $single = $this->memberService->resolveUserSingleId($uid, $this->db);
            if ($single !== null && $single !== '') {
                $qb = $this->db->getQueryBuilder();
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
            $this->logger->debug('[TeamHub][TimeslotSuggestionService] team lookup failed', [
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

    /**
     * True if any interval in the list overlaps [$startTs, $endTs).
     *
     * @param array<int, array{start: int, end: int}> $intervals
     */
    private function hasConflict(array $intervals, int $startTs, int $endTs): bool {
        foreach ($intervals as $iv) {
            if ($iv['start'] < $endTs && $iv['end'] > $startTs) {
                return true;
            }
        }
        return false;
    }

    /**
     * User TZ resolution identical to MeetingSuggestionService's, so the
     * two services agree on what "morning" means for a given organiser.
     */
    private function userTimezone(string $uid): \DateTimeZone {
        return $this->timezoneService->forUser($uid);
    }
}
