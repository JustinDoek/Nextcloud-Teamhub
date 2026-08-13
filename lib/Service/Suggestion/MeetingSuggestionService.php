<?php

declare(strict_types=1);

namespace OCA\TeamHub\Service\Suggestion;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\PresenceSlotMapper;
use OCA\TeamHub\Db\PresenceTypeMapper;
use OCA\TeamHub\Db\RoomMapper;
use OCA\TeamHub\Db\FloorMapper;
use OCA\TeamHub\Db\BuildingMapper;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\TimezoneService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Scores and ranks candidate meeting times for a team, using Presence data.
 *
 * The model (verified against the Presence schema, DESIGN.md §2.44–§2.47):
 *
 *   - A member's availability lives in teamhub_presence_slots as one row per
 *     (user, slot_date, half_day). half_day 0 = morning (local 00:00–12:00),
 *     1 = afternoon (local 12:00–24:00). These are FLOATING local times — they
 *     mean "this person's morning wherever they are", so we apply the member's
 *     own NC timezone at read time.
 *   - The slot's presence_type_id resolves (via teamhub_presence_types) to
 *     is_busy (0 = available/reachable) and requires_location (1 = at a
 *     physical room). location_room_id names which office.
 *   - We never hardcode slugs; we read the is_busy / requires_location flags.
 *
 * Candidate granularity is the half-day, because that is the resolution the
 * presence data actually has. A "candidate" is a (date, half_day) expressed in
 * the ORGANISER's timezone — that is the instant range the meeting would
 * occupy and what the organiser reads. To score it for each attendee we map
 * that instant range into the attendee's timezone and read off their local
 * (slot_date, half_day).
 *
 * Scoring:
 *   - online score   = number of attendees whose slot is available (is_busy=0),
 *                      regardless of location, and who have no calendar conflict.
 *   - in-office score= largest group of available attendees sharing one
 *                      location_room_id (requires_location=1), no conflict.
 *                      Home/remote-but-available attendees are reported
 *                      separately as "could join online".
 *
 * Personal-calendar free/busy is NOT part of v1; conflicts come from the
 * injected BusyProviderInterface implementations (team calendar in v1). Adding
 * a personal-calendar provider later needs no change here.
 */
class MeetingSuggestionService {

    /** How many working days either side of the picked date to consider. */
    private const WINDOW_WORKING_DAYS = 3;

    /** Local hour boundary between morning (0) and afternoon (1) half-days. */
    private const HALF_DAY_SPLIT_HOUR = 12;

    /**
     * @param BusyProviderInterface[] $busyProviders
     */
    public function __construct(
        private MemberService $memberService,
        private PresenceSlotMapper $slotMapper,
        private PresenceTypeMapper $typeMapper,
        private RoomMapper $roomMapper,
        private FloorMapper $floorMapper,
        private BuildingMapper $buildingMapper,
        private IConfig $config,
        private TimezoneService $timezoneService,
        private LoggerInterface $logger,
        private array $busyProviders = [],
    ) {
    }

    /**
     * Produce the top-N suggested meeting half-days for a team.
     *
     * @param string   $teamId
     * @param string   $pickedDate   ISO YYYY-MM-DD the organiser started from.
     * @param string   $organiserUid Whose timezone candidates are expressed in.
     * @param string   $meetingType  'online' | 'office'.
     * @param string[] $attendeeIds  Explicit attendee userIds; empty = whole team.
     * @param int      $limit        Max suggestions to return (default 3).
     *
     * @return array<int, array<string, mixed>> Ranked suggestions.
     */
    public function suggest(
        string $teamId,
        string $pickedDate,
        string $organiserUid,
        string $meetingType = 'online',
        array $attendeeIds = [],
        int $limit = 3,
    ): array {
        $meetingType = $meetingType === 'office' ? 'office' : 'online';

        // 1. Resolve the attendee set. Empty = all effective team members.
        //    The organiser is ALWAYS included — they're on the meeting by
        //    definition, regardless of whether the wizard's picker shows
        //    them (the picker filters them out as a UX safeguard, so they
        //    aren't in $attendeeIds either). Including them here means the
        //    "N of M available" counts correctly reflect "M = everyone
        //    actually attending, organiser included."
        $attendees = $this->resolveAttendees($teamId, $attendeeIds, $organiserUid);
        if ($attendees === []) {
            return [];
        }

        // 2. Type catalogue: id => ['isBusy' => bool, 'requiresLocation' => bool].
        $typeFlags = $this->loadTypeFlags();

        // 3. Room id => ['buildingId' => int, 'buildingName' => string], for
        //    office reporting. We group by *building* rather than by room
        //    because for a meeting-suggestion wizard the actionable signal
        //    is "are people in the same building today" — picking a room is
        //    a downstream decision after attendees commit.
        $roomBuilding = $this->loadRoomBuildings();

        // 4. Organiser timezone defines the candidate grid.
        $organiserTz = $this->userTimezone($organiserUid);

        // 5. Build candidate (date, half) pairs in organiser-local time:
        //    WINDOW_WORKING_DAYS either side of the pick, Mon–Fri, AM + PM.
        $candidates = $this->buildCandidates($pickedDate, $organiserTz);
        if ($candidates === []) {
            return [];
        }

        // 6. Absolute window spanning all candidates — used to batch-load each
        //    attendee's slots and the busy intervals in one pass.
        $windowStartTs = $candidates[0]['startTs'];
        $windowEndTs   = $candidates[count($candidates) - 1]['endTs'];
        // Pad the slot-load date range by a day each side, because an
        // attendee in a far timezone can map an organiser-local candidate onto
        // an adjacent local date.
        $loadFrom = gmdate('Y-m-d', $windowStartTs - 86400);
        $loadTo   = gmdate('Y-m-d', $windowEndTs + 86400);

        // 7. Per-attendee timezone + slot index (keyed "date|half" in their TZ).
        $attendeeCtx = [];
        foreach ($attendees as $uid) {
            $tz    = $this->userTimezone($uid);
            $slots = $this->slotMapper->findByUserAndRange($uid, $loadFrom, $loadTo);
            $index = [];
            foreach ($slots as $slot) {
                $index[$slot->getSlotDate() . '|' . $slot->getHalfDay()] = $slot;
            }
            $attendeeCtx[$uid] = ['tz' => $tz, 'slots' => $index];
        }

        // 8. Busy intervals across the whole window, merged across providers.
        $busyByUser = $this->collectBusy($teamId, $attendees, $windowStartTs, $windowEndTs);

        // 9. Score every candidate.
        $scored = [];
        foreach ($candidates as $cand) {
            $scored[] = $this->scoreCandidate(
                $cand,
                $attendees,
                $attendeeCtx,
                $typeFlags,
                $roomBuilding,
                $busyByUser,
                $meetingType,
            );
        }

        // 10. Rank:
        //     1. score desc (more available wins)
        //     2. distance-to-picked-date asc (the user picked Thursday — they
        //        want Thursday first, not Monday). Without this tie-break the
        //        earliest candidate in the ±3-day window always wins ties and
        //        the picked day gets buried.
        //     3. earliest absolute start asc (morning before afternoon within
        //        the same day)
        //     4. fewest conflicts (final tie-break for genuine identicality).
        try {
            $pivotTs = (new \DateTimeImmutable($pickedDate . ' 12:00:00', $organiserTz))->getTimestamp();
        } catch (\Throwable $e) {
            $pivotTs = $windowStartTs; // Defensive — buildCandidates already guards this.
        }
        usort($scored, function (array $a, array $b) use ($pivotTs) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            $distA = abs($a['startTs'] - $pivotTs);
            $distB = abs($b['startTs'] - $pivotTs);
            if ($distA !== $distB) {
                return $distA <=> $distB;
            }
            if ($a['startTs'] !== $b['startTs']) {
                return $a['startTs'] <=> $b['startTs'];
            }
            return $a['conflictCount'] <=> $b['conflictCount'];
        });

        // 11. Drop zero-score candidates (nobody available) and trim to limit.
        $result = array_values(array_filter($scored, fn ($s) => $s['score'] > 0));
        $result = array_slice($result, 0, max(1, $limit));

        return $result;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @param string[] $attendeeIds
     * @return string[] userIds
     */
    private function resolveAttendees(string $teamId, array $attendeeIds, string $organiserUid = ''): array {
        $members = $this->memberService->getAllEffectiveMembers($teamId);
        $memberIds = array_map(static fn ($m) => (string)$m['userId'], $members);

        if ($attendeeIds === []) {
            // "Empty selection" means "every team member" — organiser
            // is already in $memberIds, no special handling needed.
            return $memberIds;
        }
        // Intersect requested with actual members — never score a non-member,
        // and silently drop ids that aren't on the team.
        $set = array_flip($memberIds);
        $out = [];
        $seen = [];
        // Organiser is always at the front of the list when they're a
        // member of the team. Case-insensitive match on the seen-set so
        // a casing-mismatch from the wizard can't slip the organiser in
        // as a second copy (we already burned a session on this class
        // of bug earlier).
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
     * @return array<int, array{isBusy: bool, requiresLocation: bool}>
     */
    private function loadTypeFlags(): array {
        $flags = [];
        foreach ($this->typeMapper->findAll() as $type) {
            $flags[$type->getId()] = [
                'isBusy'           => $type->getIsBusy() === 1,
                'requiresLocation' => $type->getRequiresLocation() === 1,
            ];
        }
        return $flags;
    }

    /**
     * Build a flat map roomId => { buildingId, buildingName }. Used by
     * scoreCandidate to group office attendees by the building they're in,
     * rather than by the specific room. Three small queries (rooms, floors,
     * buildings) joined in PHP — cheaper than a 3-way DB JOIN on every
     * suggestion call given the catalogue size.
     *
     * @return array<int, array{buildingId: int, buildingName: string}>
     */
    private function loadRoomBuildings(): array {
        // floorId => buildingId
        $floorToBuilding = [];
        foreach ($this->floorMapper->findAll() as $floor) {
            $floorToBuilding[$floor->getId()] = $floor->getBuildingId();
        }
        // buildingId => name
        $buildingNames = [];
        foreach ($this->buildingMapper->findAll() as $building) {
            $buildingNames[$building->getId()] = $building->getName();
        }
        // roomId => { buildingId, buildingName } (skip rooms whose
        // floor or building has been deleted under them)
        $map = [];
        foreach ($this->roomMapper->findAll() as $room) {
            $bId = $floorToBuilding[$room->getFloorId()] ?? null;
            if ($bId === null || !isset($buildingNames[$bId])) {
                continue;
            }
            $map[$room->getId()] = [
                'buildingId'   => $bId,
                'buildingName' => $buildingNames[$bId],
            ];
        }
        return $map;
    }

    /**
     * Resolve a user's NC timezone, falling back to the server default.
     */
    private function userTimezone(string $uid): \DateTimeZone {
        return $this->timezoneService->forUser($uid);
    }

    /**
     * Build candidate half-days: WINDOW_WORKING_DAYS (Mon–Fri) either side of
     * the picked date, inclusive, each as morning + afternoon, expressed in the
     * organiser's timezone. Sorted ascending by start.
     *
     * @return array<int, array{date: string, half: int, startTs: int, endTs: int}>
     */
    private function buildCandidates(string $pickedDate, \DateTimeZone $organiserTz): array {
        try {
            $pivot = new \DateTimeImmutable($pickedDate . ' 00:00:00', $organiserTz);
        } catch (\Throwable $e) {
            return [];
        }

        // Collect working days: walk outward from the pivot until we have
        // WINDOW_WORKING_DAYS on each side (plus the pivot day if it's a
        // working day; if the pivot is a weekend we still anchor around it).
        $days = [];

        // Backward.
        $back = [];
        $cursor = $pivot;
        $found = 0;
        while ($found < self::WINDOW_WORKING_DAYS) {
            $cursor = $cursor->modify('-1 day');
            if ($this->isWorkingDay($cursor)) {
                $back[] = $cursor;
                $found++;
            }
            // Safety bound — never loop more than ~3 weeks.
            if ($pivot->getTimestamp() - $cursor->getTimestamp() > 21 * 86400) {
                break;
            }
        }
        $back = array_reverse($back);

        // Pivot day itself, if a working day.
        $mid = $this->isWorkingDay($pivot) ? [$pivot] : [];

        // Forward.
        $fwd = [];
        $cursor = $pivot;
        $found = 0;
        while ($found < self::WINDOW_WORKING_DAYS) {
            $cursor = $cursor->modify('+1 day');
            if ($this->isWorkingDay($cursor)) {
                $fwd[] = $cursor;
                $found++;
            }
            if ($cursor->getTimestamp() - $pivot->getTimestamp() > 21 * 86400) {
                break;
            }
        }

        $days = array_merge($back, $mid, $fwd);

        $candidates = [];
        foreach ($days as $day) {
            $dateStr = $day->format('Y-m-d');
            // Morning 00:00–12:00, Afternoon 12:00–24:00 in organiser TZ.
            $amStart = $day->setTime(0, 0, 0);
            $amEnd   = $day->setTime(self::HALF_DAY_SPLIT_HOUR, 0, 0);
            $pmStart = $amEnd;
            $pmEnd   = $day->setTime(0, 0, 0)->modify('+1 day');

            $candidates[] = [
                'date' => $dateStr, 'half' => 0,
                'startTs' => $amStart->getTimestamp(), 'endTs' => $amEnd->getTimestamp(),
            ];
            $candidates[] = [
                'date' => $dateStr, 'half' => 1,
                'startTs' => $pmStart->getTimestamp(), 'endTs' => $pmEnd->getTimestamp(),
            ];
        }

        usort($candidates, fn ($a, $b) => $a['startTs'] <=> $b['startTs']);
        return $candidates;
    }

    private function isWorkingDay(\DateTimeInterface $d): bool {
        $dow = (int)$d->format('N'); // 1 (Mon) .. 7 (Sun)
        return $dow >= 1 && $dow <= 5;
    }

    /**
     * Merge busy intervals from all registered providers.
     *
     * @param string[] $attendees
     * @return array<string, array<int, array{start: int, end: int}>>
     */
    private function collectBusy(string $teamId, array $attendees, int $startTs, int $endTs): array {
        $merged = [];
        foreach ($this->busyProviders as $provider) {
            try {
                $partial = $provider->getBusyIntervals($teamId, $attendees, $startTs, $endTs);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][MeetingSuggestionService] busy provider failed', [
                    'provider' => $provider::class,
                    'error'    => $e->getMessage(),
                    'app'      => Application::APP_ID,
                ]);
                continue;
            }
            foreach ($partial as $uid => $intervals) {
                foreach ($intervals as $iv) {
                    $merged[$uid][] = $iv;
                }
            }
        }
        return $merged;
    }

    /**
     * Score a single candidate half-day across all attendees.
     *
     * @param array{date: string, half: int, startTs: int, endTs: int} $cand
     * @param string[] $attendees
     * @param array<string, array{tz: \DateTimeZone, slots: array<string, \OCA\TeamHub\Db\PresenceSlot>}> $attendeeCtx
     * @param array<int, array{isBusy: bool, requiresLocation: bool}> $typeFlags
     * @param array<int, array{buildingId: int, buildingName: string}> $roomBuilding roomId => building meta
     * @param array<string, array<int, array{start: int, end: int}>> $busyByUser
     */
    private function scoreCandidate(
        array $cand,
        array $attendees,
        array $attendeeCtx,
        array $typeFlags,
        array $roomBuilding,
        array $busyByUser,
        string $meetingType,
    ): array {
        $availableOnline   = [];   // userIds available (reachable), any location
        $conflictCount     = 0;
        $unknownCount      = 0;    // no materialised slot — excluded, not penalised
        $inOfficeCount     = 0;    // attendees with requiresLocation=true presence (room or not)
        $buildingGroups    = [];   // buildingId => [userIds] (only those with a known room)
        $buildingNames     = [];   // buildingId => name (captured the first time we see one)
        $remoteAvailable   = [];   // available but not location-bound (home)

        // Candidate midpoint as the representative instant for half-day mapping.
        $midTs = intdiv($cand['startTs'] + $cand['endTs'], 2);

        foreach ($attendees as $uid) {
            $ctx = $attendeeCtx[$uid];
            // Map the candidate's midpoint into the attendee's timezone, then
            // derive their local (slot_date, half_day).
            $local = (new \DateTimeImmutable('@' . $midTs))->setTimezone($ctx['tz']);
            $localDate = $local->format('Y-m-d');
            $localHalf = (int)$local->format('G') < self::HALF_DAY_SPLIT_HOUR ? 0 : 1;

            $slot = $ctx['slots'][$localDate . '|' . $localHalf] ?? null;
            if ($slot === null) {
                $unknownCount++;
                continue; // No declared presence — exclude from available count.
            }

            $typeId = $slot->getPresenceTypeId();
            $flags  = $typeId !== null ? ($typeFlags[$typeId] ?? null) : null;
            if ($flags === null || $flags['isBusy']) {
                continue; // Busy/unavailable presence type.
            }

            // Calendar conflict? Overlap of the candidate's absolute range with
            // any of this attendee's busy intervals.
            if ($this->hasConflict($busyByUser[$uid] ?? [], $cand['startTs'], $cand['endTs'])) {
                $conflictCount++;
                continue;
            }

            // Available.
            $availableOnline[] = $uid;

            // Two parallel groupings for the office case:
            //
            //   1. inOfficeCount — anyone whose presence type requires a
            //      location ("Office", "Site X", etc.), regardless of
            //      whether they assigned a specific room. This is what we
            //      score on, because "I'm in the office today" is the
            //      signal that matters for an in-person meeting.
            //   2. buildingGroups — the same people, bucketed by the
            //      building of their assigned room. Used only for the
            //      "best building" descriptor; missing-room attendees
            //      contribute to inOfficeCount but not to buildingGroups.
            //
            // Earlier versions (3.59.3 – 3.59.8) scored office candidates
            // by buildingGroups headcount only, which meant a half-day
            // where everyone was "at the office" but nobody had picked a
            // specific room scored 0 and got dropped. The session log
            // confirmed this: candidates with availableCount=2 / score=0.
            $roomId = $slot->getLocationRoomId();
            if ($flags['requiresLocation']) {
                $inOfficeCount++;
                if ($roomId !== null && isset($roomBuilding[$roomId])) {
                    $meta = $roomBuilding[$roomId];
                    $bId  = $meta['buildingId'];
                    $buildingGroups[$bId][] = $uid;
                    $buildingNames[$bId]    = $meta['buildingName'];
                }
            } else {
                // Remote / no-location presence type (e.g. "Home").
                $remoteAvailable[] = $uid;
            }
        }

        // Determine the dominant building (only useful when at least one
        // person assigned a room — otherwise we surface no building).
        $bestBuildingId    = null;
        $bestBuildingCount = 0;
        foreach ($buildingGroups as $bId => $members) {
            if (count($members) > $bestBuildingCount) {
                $bestBuildingCount = count($members);
                $bestBuildingId    = $bId;
            }
        }

        if ($meetingType === 'office') {
            // Office score = number of attendees with an in-office presence,
            // independent of whether they specified a room. Picking a room
            // is rarely done in practice; gating office candidates on it
            // (as the prior formula did) wiped out most viable suggestions.
            $score = $inOfficeCount;
        } else {
            $score = count($availableOnline);
        }

        return [
            'date'              => $cand['date'],
            'half'              => $cand['half'],
            'startTs'           => $cand['startTs'],
            'endTs'             => $cand['endTs'],
            'startIso'          => (new \DateTimeImmutable('@' . $cand['startTs']))->format('c'),
            'endIso'            => (new \DateTimeImmutable('@' . $cand['endTs']))->format('c'),
            'meetingType'       => $meetingType,
            'score'             => $score,
            'availableCount'    => count($availableOnline),
            'attendeeCount'     => count($attendees),
            'conflictCount'     => $conflictCount,
            'unknownCount'      => $unknownCount,
            // Office-meeting reporting: inOfficeCount is the score for
            // office candidates; bestBuilding* describes which site is
            // most populated when at least one attendee picked a room.
            'inOfficeCount'     => $inOfficeCount,
            'bestBuildingId'    => $bestBuildingId,
            'bestBuildingName'  => $bestBuildingId !== null ? ($buildingNames[$bestBuildingId] ?? null) : null,
            'bestBuildingCount' => $bestBuildingCount,
            'remoteCount'       => count($remoteAvailable),
        ];
    }

    /**
     * @param array<int, array{start: int, end: int}> $intervals
     */
    private function hasConflict(array $intervals, int $startTs, int $endTs): bool {
        foreach ($intervals as $iv) {
            // Overlap if the event starts before the candidate ends and ends
            // after the candidate starts.
            if ($iv['start'] < $endTs && $iv['end'] > $startTs) {
                return true;
            }
        }
        return false;
    }
}
