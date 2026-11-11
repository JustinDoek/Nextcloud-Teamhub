<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\PresenceSlot;
use OCA\TeamHub\Db\PresenceSlotMapper;
use OCA\TeamHub\Db\PresenceTypeMapper;
use OCA\TeamHub\Db\RosterAssignment;
use OCA\TeamHub\Db\RosterAssignmentMapper;
use OCA\TeamHub\Db\RosterShift;
use OCA\TeamHub\Db\RosterShiftMapper;
use OCA\TeamHub\Db\ShiftType;
use OCA\TeamHub\Db\ShiftRequest;
use OCA\TeamHub\Db\ShiftRequestMapper;
use OCA\TeamHub\Db\ShiftTypeMapper;
use OCA\TeamHub\Exception\PresenceConflictException;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * The roster: who works when (DESIGN §2.233).
 *
 * ## What this is, against the thing next to it
 *
 * The People module already answers *where is this person*, from a half-day
 * workplace the person declares about themselves. The roster answers *who
 * works when* — decided by somebody else, with a coverage target, and with a
 * legal notice period attached in several of the countries we sell into. They
 * are different facts and they live in different tables.
 *
 * ## The one place they meet
 *
 * A **published** shift is written into its assignees' presence with
 * `source = 'roster'`, so the planning grid still answers "where is everyone"
 * from one place (Justin, 2026-11-01). The existing `source` column is what
 * makes that safe: a roster slot is distinguishable from a declared one, the
 * materialiser already preserves any half-day that has a slot, and
 * `PresenceSlotService` can refuse to let a member quietly delete a shift
 * somebody assigned them.
 *
 * **A draft writes nothing.** Publishing is the act that makes a roster real,
 * for the member and for the law, so it is also the act that touches presence.
 *
 * ## Why an absence blocks an assignment rather than losing to it
 *
 * Writing a shift over somebody's booked holiday would be a silent data loss
 * dressed as a feature. Timefold's employee-scheduling model states the rule
 * the industry settled on: availability is a **hard** constraint, and leaving
 * a shift unassigned is better than assigning somebody who is not available.
 * So an assignment onto a busy half-day is refused with the reason, and the
 * shift stays open where everyone can see it is open.
 */
class RosterService {

    /** `source` written into teamhub_presence_slots by a published shift. */
    public const PRESENCE_SOURCE = 'roster';

    /** Noon, in minutes. The boundary between the two presence half-days. */
    private const NOON = 720;

    /** A grid request is bounded; the UI asks for a week. */
    private const MAX_RANGE_DAYS = 31;

    private const ISO_DATE_RE = '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/';

    public function __construct(
        private RosterShiftMapper      $shiftMapper,
        private RosterAssignmentMapper $assignMapper,
        private ShiftTypeMapper        $typeMapper,
        private ShiftRequestMapper     $requestMapper,
        private PresenceSlotMapper     $slotMapper,
        private PresenceTypeMapper     $presenceTypeMapper,
        private \OCA\TeamHub\Db\HolidayMapper $holidayMapper,
        private ShiftAvailabilityService $availability,
        private RosterCalendarService  $calendarCopies,
        private RosterNotificationService $notices,
        private MemberService          $memberService,
        private MessageService         $messageService,
        private TalkService            $talkService,
        private ResourceService        $resourceService,
        private IURLGenerator          $urlGenerator,
        private IUserManager           $userManager,
        private IDBConnection          $db,
        private IL10N                  $l,
        private LoggerInterface        $logger,
    ) {}

    // ---------------------------------------------------------------------
    // Reading
    // ---------------------------------------------------------------------

    /**
     * The roster grid for one team over a date range.
     *
     * Drafts are included only for a team admin. A member sees the published
     * roster and nothing else, because a draft is explicitly not yet a
     * communication — and showing one would be worse than showing nothing,
     * since a member cannot tell a plan from a decision by looking at it.
     *
     * @return array<string, mixed>
     */
    public function getTeamRoster(string $teamId, string $fromDate, string $toDate, bool $isTeamAdmin): array {
        $this->assertIsoDate($fromDate);
        $this->assertIsoDate($toDate);
        $this->assertRange($fromDate, $toDate);

        $types = [];
        foreach ($this->typeMapper->findAll() as $type) {
            $types[$type->getId()] = $type;
        }

        $shifts = $this->shiftMapper->findByTeamAndRange($teamId, $fromDate, $toDate);
        if (!$isTeamAdmin) {
            $shifts = array_values(array_filter($shifts, static fn (RosterShift $s): bool => $s->isPublished()));
        }

        $shiftIds    = array_map(static fn (RosterShift $s): int => $s->getId(), $shifts);
        $assignments = $this->assignMapper->findByShiftIds($shiftIds);
        // Open hand-over requests, so the grid can mark a shift somebody is
        // trying to give away and offer the take-over to everybody else.
        $requests = $this->requestMapper->findOpenByShiftIds($shiftIds);

        $members = $this->memberService->getAllEffectiveMembers($teamId);
        $names   = [];
        foreach ($members as $member) {
            $names[(string)($member['userId'] ?? '')] = (string)($member['displayName'] ?? '');
        }

        $rows = [];
        foreach ($shifts as $shift) {
            $row = $this->serializeShift($shift, $types, $assignments[$shift->getId()] ?? [], $names);
            $request = $requests[$shift->getId()] ?? null;
            $row['handover'] = $request === null ? null : [
                'id'            => $request->getId(),
                'requesterUid'  => $request->getRequesterUid(),
                'requesterName' => $names[$request->getRequesterUid()] ?? $request->getRequesterUid(),
                'reason'        => $request->getReason(),
            ];
            $rows[] = $row;
        }

        // The holidays inside the window, so the grid can mark a day that is
        // normally not a working one. A marker, not a lock.
        $holidays = [];
        $day  = new \DateTimeImmutable($fromDate . ' 12:00:00');
        $last = new \DateTimeImmutable($toDate . ' 12:00:00');
        for ($i = 0; $i < 40 && $day <= $last; $i++, $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $holiday = $this->holidayMapper->findByDate($date);
            if ($holiday !== null) {
                $holidays[$date] = $holiday->getName();
            }
        }

        return [
            'shifts'      => $rows,
            'holidays'    => $holidays ?: (object)[],
            'types'       => array_values(array_map(
                fn (ShiftType $type): array => $this->serializeType($type),
                $this->typeMapper->findActive(),
            )),
            'members'     => $members,
            'from'        => $fromDate,
            'to'          => $toDate,
            'can_manage'  => $isTeamAdmin,
            // The draft count is what the Publish control is enabled by, and
            // it is the admin's only signal that a week is not out yet.
            'draft_count' => count(array_filter($shifts, static fn (RosterShift $s): bool => !$s->isPublished())),
        ];
    }

    /**
     * @param array<int, ShiftType> $types
     * @param RosterAssignment[] $assignments
     * @param array<string, string> $names
     * @return array<string, mixed>
     */
    private function serializeShift(RosterShift $shift, array $types, array $assignments, array $names): array {
        $type = $types[$shift->getShiftTypeId()] ?? null;
        $assigned = [];
        foreach ($assignments as $row) {
            $assigned[] = [
                'userId'      => $row->getUserId(),
                // A uid with no name is somebody who has left the team but is
                // still on a shift somebody has to cover. Showing the uid is
                // the honest answer; dropping the row would hide the gap.
                'displayName' => $names[$row->getUserId()] ?? $row->getUserId(),
                'origin'      => $row->getOrigin(),
            ];
        }

        $required = max(0, $shift->getRequiredCount());

        return [
            'id'            => $shift->getId(),
            'date'          => $shift->getShiftDate(),
            'typeId'        => $shift->getShiftTypeId(),
            'typeLabel'     => $type?->getLabel() ?? $this->l->t('Removed shift type'),
            'typeSlug'      => $type?->getSlug() ?? '',
            'color'         => $type?->getColor() ?? '',
            'startTime'     => $type?->getStartTime() ?? '',
            'endTime'       => $type?->getEndTime() ?? '',
            'endsNextDay'   => $type?->endsNextDay() ?? false,
            'roomId'        => $shift->getRoomId(),
            'requiredCount' => $required,
            // v4.13.70 — `notes` became **Role** at the surface. The column is
            // still `notes`: renaming it would be a migration on every
            // supported database to change text no user ever sees, which is
            // the rule §2.210 set when Presence became People.
            'role'          => $shift->getNotes(),
            'assigned'      => $assigned,
            // The number the grid shows in red. Never negative: over-staffing
            // a shift is a choice somebody made, not a gap.
            'openCount'     => max(0, $required - count($assigned)),
            'isPublished'   => $shift->isPublished(),
            'publishedAt'   => $shift->getPublishedAt(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeType(ShiftType $type): array {
        return [
            'id'          => $type->getId(),
            'slug'        => $type->getSlug(),
            'label'       => $type->getLabel(),
            'startTime'   => $type->getStartTime(),
            'endTime'     => $type->getEndTime(),
            'endsNextDay' => $type->endsNextDay(),
            'color'       => $type->getColor(),
            'icon'        => $type->getIcon(),
            'isActive'    => $type->getIsActive() === 1,
            'isBuiltin'   => $type->getIsBuiltin() === 1,
        ];
    }

    // ---------------------------------------------------------------------
    // Writing shifts
    // ---------------------------------------------------------------------

    /**
     * Add a shift to the draft roster.
     *
     * @throws \InvalidArgumentException on a bad date, unknown type or a duplicate
     * @return array<string, mixed>
     */
    public function createShift(
        string $teamId,
        string $shiftDate,
        int $shiftTypeId,
        ?int $roomId,
        int $requiredCount,
        string $role,
        string $actorUid,
    ): array {
        $this->assertIsoDate($shiftDate);

        $type = $this->typeMapper->findById($shiftTypeId);
        if ($type === null) {
            throw new \InvalidArgumentException($this->l->t('Unknown shift type.'));
        }
        if ($type->getIsActive() !== 1) {
            throw new \InvalidArgumentException($this->l->t('That shift type is switched off.'));
        }
        if ($requiredCount < 1 || $requiredCount > 99) {
            throw new \InvalidArgumentException($this->l->t('A shift needs between 1 and 99 people.'));
        }
        // v4.13.74 — the role is part of the duplicate question. Two roles on
        // one shift type and day are two different things to cover; it is the
        // same role twice that doubles a coverage target by accident.
        $storedRole = mb_substr(trim($role), 0, 255);
        if ($this->shiftMapper->findDuplicate($teamId, $shiftDate, $shiftTypeId, $roomId, $storedRole) !== null) {
            throw new \InvalidArgumentException(
                $this->l->t('That shift is already on the roster for this day. Change how many people it needs instead.'),
            );
        }

        $now   = time();
        $shift = new RosterShift();
        $shift->setTeamId($teamId);
        $shift->setShiftDate($shiftDate);
        $shift->setShiftTypeId($shiftTypeId);
        $shift->setRoomId($roomId);
        $shift->setRequiredCount($requiredCount);
        $shift->setNotes($storedRole);
        $shift->setPublishedAt(0);
        $shift->setCreatedBy($actorUid);
        $shift->setCreatedAt($now);
        $shift->setUpdatedAt($now);

        /** @var RosterShift $saved */
        $saved = $this->shiftMapper->insert($shift);

        return $this->serializeShift($saved, [$type->getId() => $type], [], []);
    }

    /**
     * Change how many people a shift needs, its room or its note.
     *
     * The date and the type are not editable: a shift that changes either is a
     * different shift, and editing them in place would silently move people
     * who had already been assigned to the old one.
     *
     * @throws \InvalidArgumentException
     */
    public function updateShift(int $shiftId, string $teamId, ?int $requiredCount, ?string $role, ?int $roomId, string $actorUid = ''): array {
        $shift = $this->requireShift($shiftId, $teamId);
        $labelBefore = $this->shiftLabel($shift);

        if ($requiredCount !== null) {
            if ($requiredCount < 1 || $requiredCount > 99) {
                throw new \InvalidArgumentException($this->l->t('A shift needs between 1 and 99 people.'));
            }
            $shift->setRequiredCount($requiredCount);
        }
        if ($role !== null) {
            $shift->setNotes(mb_substr(trim($role), 0, 255));
        }
        if ($roomId !== null) {
            $shift->setRoomId($roomId === 0 ? null : $roomId);
        }
        $shift->setUpdatedAt(time());

        /** @var RosterShift $saved */
        $saved = $this->shiftMapper->update($shift);
        $type  = $this->typeMapper->findById($saved->getShiftTypeId());
        $assignments = $this->assignMapper->findByShift($shiftId);

        // A new role on a published shift changes what somebody turns up to
        // do, so the people on it are told (DESIGN §2.250). A new head count
        // or room is the admin's business and is not.
        if ($saved->isPublished() && $this->shiftLabel($saved) !== $labelBefore) {
            $facts = $this->shiftFacts($saved, $type) + ['oldLabel' => $labelBefore];
            foreach ($assignments as $row) {
                $this->notices->aboutShift(RosterNotificationService::ROLE_CHANGED, $row->getUserId(), $teamId, $shiftId, $facts, $actorUid);
            }
        }

        return $this->serializeShift(
            $saved,
            $type === null ? [] : [$type->getId() => $type],
            $assignments,
            [],
        );
    }

    /**
     * Remove a shift and everyone on it.
     *
     * A published shift takes its presence rows with it, or somebody who was
     * taken off a roster would still read as working it.
     */
    public function deleteShift(int $shiftId, string $teamId, string $actorUid = ''): void {
        $shift = $this->requireShift($shiftId, $teamId);
        $wasPublished = $shift->isPublished();
        $uids = array_map(
            static fn (RosterAssignment $a): string => $a->getUserId(),
            $this->assignMapper->findByShift($shiftId),
        );

        $this->db->beginTransaction();
        try {
            $this->assignMapper->deleteByShift($shiftId);
            $this->shiftMapper->delete($shift);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        if ($wasPublished) {
            $facts = $this->shiftFacts($shift, $this->typeMapper->findById($shift->getShiftTypeId()));
            foreach (array_unique($uids) as $uid) {
                $this->clearPresenceForShift($uid, $shift);
                $this->notices->aboutShift(RosterNotificationService::SHIFT_REMOVED, $uid, $teamId, $shiftId, $facts, $actorUid);
            }
        }
        // And out of every calendar it was copied into (DESIGN §2.250).
        $this->calendarCopies->removeAllFor($shiftId);
    }

    // ---------------------------------------------------------------------
    // Assigning
    // ---------------------------------------------------------------------

    /**
     * Put somebody on a shift, or let them take it themselves.
     *
     * @param string $origin RosterAssignment::ORIGIN_*
     * @throws \InvalidArgumentException  not a member, bad origin, shift full
     * @throws PresenceConflictException  the person is not available that day
     */
    public function assign(int $shiftId, string $teamId, string $userId, string $origin, string $actorUid): array {
        $shift = $this->requireShift($shiftId, $teamId);

        if (!in_array($origin, RosterAssignment::ORIGINS, true)) {
            throw new \InvalidArgumentException($this->l->t('Unknown assignment type.'));
        }
        // A draft is not an offer. The controller's docblock has always said
        // claim needs a published shift and nothing enforced it: a member is
        // never served a draft, but a shift id is guessable (v4.14.8).
        if ($origin === RosterAssignment::ORIGIN_CLAIMED && !$shift->isPublished()) {
            throw new \InvalidArgumentException($this->l->t('That shift has not been published yet.'));
        }
        if (!$this->memberService->isEffectiveMember($teamId, $userId, $this->db)) {
            throw new \InvalidArgumentException($this->l->t('That person is not a member of this team.'));
        }
        if ($this->assignMapper->findOne($shiftId, $userId) !== null) {
            throw new \InvalidArgumentException($this->l->t('That person is already on this shift.'));
        }

        $taken = count($this->assignMapper->findByShift($shiftId));
        if ($taken >= $shift->getRequiredCount()) {
            throw new \InvalidArgumentException($this->l->t('This shift is already fully covered.'));
        }

        // Availability is a hard constraint: an unassigned shift is a better
        // answer than one assigned to somebody who is on holiday.
        $blocked = $this->absenceBlocking($userId, $shift)
            ?? $this->overlapBlocking($userId, $shift, $actorUid === $userId);
        if ($blocked !== null) {
            throw new PresenceConflictException($blocked);
        }

        $row = new RosterAssignment();
        $row->setShiftId($shiftId);
        $row->setUserId($userId);
        $row->setOrigin($origin);
        $row->setAssignedBy($actorUid);
        $row->setCreatedAt(time());

        try {
            $this->assignMapper->insert($row);
        } catch (\Throwable $e) {
            // The unique index settled a race against another claimer. That is
            // the index doing its job, not an error worth a stack trace.
            $this->logger->debug('[TeamHub][RosterService] assign lost a race: ' . $e->getMessage());
            throw new \InvalidArgumentException($this->l->t('Somebody else took that place first.'));
        }

        if ($shift->isPublished()) {
            $this->writePresenceForShift($userId, $shift);
        }

        $type = $this->typeMapper->findById($shift->getShiftTypeId());

        // Put on a shift the team has already been told about. A claim is the
        // person acting on themselves, and a draft is told at publishing.
        if ($shift->isPublished() && $origin === RosterAssignment::ORIGIN_ASSIGNED) {
            $this->notices->aboutShift(RosterNotificationService::ASSIGNED, $userId, $teamId, $shiftId, $this->shiftFacts($shift, $type), $actorUid);
        }

        return $this->serializeShift(
            $shift,
            $type === null ? [] : [$type->getId() => $type],
            $this->assignMapper->findByShift($shiftId),
            [],
        );
    }

    /** Take somebody off a shift, and off the presence grid with it. */
    public function unassign(int $shiftId, string $teamId, string $userId, string $actorUid = ''): array {
        $shift = $this->requireShift($shiftId, $teamId);
        $row   = $this->assignMapper->findOne($shiftId, $userId);
        if ($row === null) {
            throw new \InvalidArgumentException($this->l->t('That person is not on this shift.'));
        }

        $this->assignMapper->delete($row);

        if ($shift->isPublished()) {
            $this->clearPresenceForShift($userId, $shift);
        }
        $this->calendarCopies->removeFor($shiftId, $userId);

        $type = $this->typeMapper->findById($shift->getShiftTypeId());
        if ($shift->isPublished()) {
            $this->notices->aboutShift(RosterNotificationService::UNASSIGNED, $userId, $teamId, $shiftId, $this->shiftFacts($shift, $type), $actorUid);
        }
        return $this->serializeShift(
            $shift,
            $type === null ? [] : [$type->getId() => $type],
            $this->assignMapper->findByShift($shiftId),
            [],
        );
    }

    /**
     * Who could work this shift, and what stands in each person's way.
     *
     * The staffing picker's whole answer, so the admin chooses from a list
     * that already says *Jaap, not available Tuesday mornings* rather than
     * finding out by being refused.
     *
     * Three states, and the difference matters:
     *
     *   - `ok`          — nothing known against it.
     *   - `unavailable` — they said they cannot work this half-day. **Advice,
     *                     not a lock**: somebody who says no to Saturdays may
     *                     still agree to one, and a rota that refuses the
     *                     conversation is a rota people work around.
     *   - `timeoff`     — booked time off, which **does** refuse, because it
     *                     is a fact about that day rather than a standing
     *                     preference.
     *   - `overlap`     — already on another shift at these hours (v4.14.8).
     *
     * ## Fair distribution (v4.14.9, DESIGN §2.251)
     *
     * Each person also carries what this team has already asked of them: the
     * minutes rostered in the shift's own week, and the weekend shifts over the
     * four weeks ending with it. Within each state the list is ordered by that
     * load, lightest first, so the first name under *ok* is the person a fair
     * rota would ask next. For a weekend shift the weekend count leads,
     * because that is the burden people keep score of; otherwise the week's
     * hours lead. It **orders and never filters**: the admin still picks, and
     * the numbers are on screen so the order explains itself.
     *
     * @return array{options: list<array<string, mixed>>, sortedBy: string, weekFrom: string, weekTo: string, weekendFrom: string}
     */
    public function staffingOptions(int $shiftId, string $teamId): array {
        $shift = $this->requireShift($shiftId, $teamId);
        $type  = $this->typeMapper->findById($shift->getShiftTypeId());

        $weekFrom    = $this->mondayOf($shift->getShiftDate());
        $weekTo      = $this->addDays($weekFrom, 6);
        $weekendFrom = $this->addDays($weekFrom, -21);
        $isWeekend   = self::isWeekendDate($shift->getShiftDate());
        $load        = $this->teamLoad($teamId, $weekFrom, $weekTo, $weekendFrom);

        $members = $this->memberService->getAllEffectiveMembers($teamId);
        $uids    = array_values(array_filter(array_map(
            static fn (array $m): string => (string)($m['userId'] ?? ''),
            $members,
        )));

        $onShift = [];
        foreach ($this->assignMapper->findByShift($shiftId) as $row) {
            $onShift[$row->getUserId()] = true;
        }

        // One query for the whole roster, asked per half-day the shift
        // occupies: a night shift reaches into the next morning, and somebody
        // unavailable then is unavailable for the shift.
        $unavailable = [];
        if ($type !== null) {
            foreach ($this->occupiedHalves($type, $shift->getShiftDate()) as [$date, $half]) {
                $dow = (int)(new \DateTimeImmutable($date . ' 12:00:00'))->format('N') - 1;
                foreach ($this->availability->unavailableOn($uids, $dow, $half) as $uid => $_) {
                    $unavailable[$uid] = true;
                }
            }
        }

        $out = [];
        foreach ($members as $member) {
            $uid = (string)($member['userId'] ?? '');
            if ($uid === '') {
                continue;
            }
            $timeOff = $this->absenceBlocking($uid, $shift);
            // Somebody already on this shift is not double-booked by it; the
            // row says "Already on this shift" instead.
            $overlap = ($timeOff === null && !isset($onShift[$uid]))
                ? $this->overlapBlocking($uid, $shift, false)
                : null;

            $state = 'ok';
            if ($timeOff !== null) {
                $state = 'timeoff';
            } elseif ($overlap !== null) {
                $state = 'overlap';
            } elseif (isset($unavailable[$uid])) {
                $state = 'unavailable';
            }

            $out[] = [
                'userId'      => $uid,
                'displayName' => (string)($member['displayName'] ?? $uid),
                'state'       => $state,
                'reason'      => match ($state) {
                    'timeoff'     => (string)$timeOff,
                    // Shorter than the refusal, because it sits beside a name
                    // in a 400px dialog rather than in a sentence on its own.
                    'overlap'     => $this->l->t('Already working at this time'),
                    'unavailable' => $this->l->t('Not available at this time'),
                    default       => '',
                },
                'onShift'     => isset($onShift[$uid]),
                'weekMinutes'   => $load[$uid]['weekMinutes'] ?? 0,
                'weekendShifts' => $load[$uid]['weekendShifts'] ?? 0,
            ];
        }

        usort($out, static fn (array $a, array $b): int => self::compareStaffing($a, $b, $isWeekend));

        return [
            'options'     => $out,
            'sortedBy'    => $isWeekend ? 'weekend' : 'week',
            'weekFrom'    => $weekFrom,
            'weekTo'      => $weekTo,
            'weekendFrom' => $weekendFrom,
        ];
    }

    /**
     * The staffing order. Available first, then those who said no, then those
     * who cannot — the order an admin reads it in; a double booking and time
     * off both refuse, so they sit together at the bottom. Within a state,
     * the lightest load first (DESIGN §2.251), then by name so the order is
     * stable from one opening of the dialog to the next.
     *
     * @param array{state: string, displayName: string, weekMinutes?: int, weekendShifts?: int} $a
     * @param array{state: string, displayName: string, weekMinutes?: int, weekendShifts?: int} $b
     */
    public static function compareStaffing(array $a, array $b, bool $weekendShift): int {
        $rank = ['ok' => 0, 'unavailable' => 1, 'overlap' => 2, 'timeoff' => 3];
        $key = static function (array $o) use ($rank, $weekendShift): array {
            $week    = (int)($o['weekMinutes'] ?? 0);
            $weekend = (int)($o['weekendShifts'] ?? 0);
            return [
                $rank[$o['state']] ?? 9,
                $weekendShift ? $weekend : $week,
                $weekendShift ? $week : $weekend,
                mb_strtolower((string)$o['displayName']),
            ];
        };
        return $key($a) <=> $key($b);
    }

    /** Saturday or Sunday, for a floating date. */
    public static function isWeekendDate(string $isoDate): bool {
        return (int)(new \DateTimeImmutable($isoDate . ' 12:00:00'))->format('N') >= 6;
    }

    /**
     * What this team has asked of each member around a shift: minutes in the
     * week, and weekend shifts over the four weeks ending with it.
     *
     * **This team only, drafts included.** The question the picker answers is
     * whether this team is sharing its own work out fairly, and the person
     * reading it is this team's admin, who has no business seeing another
     * team's plans even as a number. Drafts count because the admin is
     * looking at their own plan: a week half staffed in draft is exactly the
     * week whose balance they are deciding.
     *
     * @return array<string, array{weekMinutes: int, weekendShifts: int}>
     */
    private function teamLoad(string $teamId, string $weekFrom, string $weekTo, string $weekendFrom): array {
        $shifts = $this->shiftMapper->findByTeamAndRange($teamId, $weekendFrom, $weekTo);
        if ($shifts === []) {
            return [];
        }
        $types = [];
        foreach ($this->typeMapper->findAll() as $type) {
            $types[$type->getId()] = $type;
        }
        $assignments = $this->assignMapper->findByShiftIds(
            array_map(static fn (RosterShift $s): int => $s->getId(), $shifts),
        );
        $uidsByShift = [];
        foreach ($assignments as $shiftId => $rows) {
            $uidsByShift[$shiftId] = array_map(static fn (RosterAssignment $r): string => $r->getUserId(), $rows);
        }
        return self::tallyLoad($shifts, $uidsByShift, $types, $weekFrom, $weekTo);
    }

    /**
     * The arithmetic behind `teamLoad()`, pure so it is tested on its own.
     * Every shift passed in counts towards the weekend tally if it falls on a
     * Saturday or Sunday; only those inside [weekFrom, weekTo] count minutes.
     *
     * @param RosterShift[] $shifts
     * @param array<int, list<string>> $uidsByShift
     * @param array<int, ShiftType> $types
     * @return array<string, array{weekMinutes: int, weekendShifts: int}>
     */
    public static function tallyLoad(array $shifts, array $uidsByShift, array $types, string $weekFrom, string $weekTo): array {
        $out = [];
        foreach ($shifts as $shift) {
            $date    = $shift->getShiftDate();
            $inWeek  = $date >= $weekFrom && $date <= $weekTo;
            $weekend = self::isWeekendDate($date);
            $minutes = ($types[$shift->getShiftTypeId()] ?? null)?->lengthMinutes() ?? 0;
            foreach ($uidsByShift[$shift->getId()] ?? [] as $uid) {
                $out[$uid] ??= ['weekMinutes' => 0, 'weekendShifts' => 0];
                if ($inWeek) {
                    $out[$uid]['weekMinutes'] += $minutes;
                }
                if ($weekend) {
                    $out[$uid]['weekendShifts']++;
                }
            }
        }
        return $out;
    }

    /**
     * How this person came to be on this shift, or null if they are not.
     *
     * The controller needs it to answer "may this member drop this shift
     * themselves", which is true for one they claimed and false for one that
     * was assigned to them.
     */
    public function assignmentOrigin(int $shiftId, string $teamId, string $userId): ?string {
        $this->requireShift($shiftId, $teamId);
        return $this->assignMapper->findOne($shiftId, $userId)?->getOrigin();
    }

    // ---------------------------------------------------------------------
    // Publishing
    // ---------------------------------------------------------------------

    /**
     * Draft a week from one of the team's own templates (v4.13.74, §2.240).
     *
     * **Copies, not references.** A later edit to the template must never
     * reach back into a week somebody has already been told about, which is
     * what lets a team admin fix a template without rewriting next week.
     *
     * What it adds is the **role**. Every line names what somebody is needed
     * for, so a drafted week comes out reading *Receptionist 08:00 to 12:00*
     * rather than a third nameless Early, and the only thing left for the team
     * admin to do is put people in it. That is Justin's whole specification
     * for the feature, 2026-11-02.
     *
     * Idempotent through the duplicate guard, which since this release counts
     * the role as part of a shift's identity: applying the same template to
     * the same week twice adds nothing the second time, while two different
     * roles on one shift type and day both land.
     *
     * A line whose shift type has since been switched off is skipped rather
     * than refused. The administrator who retired *Night* did not thereby
     * invalidate every template that used it, and a week short one line is
     * visible on the grid where a 400 is not.
     *
     * @param list<array{dayOfWeek: int, shiftTypeId: int, role: string, requiredCount: int}> $lines
     *        the template's week, already validated by RosterTemplateService
     * @return array{created: int, skipped: int, from: string, to: string}
     */
    public function applyTemplateLines(string $teamId, string $weekStart, array $lines, string $actorUid): array {
        $this->assertIsoDate($weekStart);

        $monday = $this->mondayOf($weekStart);
        $types  = [];
        foreach ($this->typeMapper->findAll() as $type) {
            $types[$type->getId()] = $type;
        }

        $created = 0;
        $skipped = 0;
        $now     = time();

        $this->db->beginTransaction();
        try {
            foreach ($lines as $line) {
                $typeId = (int)($line['shiftTypeId'] ?? 0);
                $type   = $types[$typeId] ?? null;
                if ($type === null || $type->getIsActive() !== 1) {
                    $skipped++;
                    continue;
                }

                $role = mb_substr(trim((string)($line['role'] ?? '')), 0, 255);
                $date = $this->addDays($monday, (int)($line['dayOfWeek'] ?? 0));
                if ($this->shiftMapper->findDuplicate($teamId, $date, $typeId, null, $role) !== null) {
                    $skipped++;
                    continue;
                }

                $shift = new RosterShift();
                $shift->setTeamId($teamId);
                $shift->setShiftDate($date);
                $shift->setShiftTypeId($typeId);
                $shift->setRoomId(null);
                $shift->setRequiredCount(max(1, min(99, (int)($line['requiredCount'] ?? 1))));
                $shift->setNotes($role);
                // A drafted week is a draft. Publishing is a separate act.
                $shift->setPublishedAt(0);
                $shift->setCreatedBy($actorUid);
                $shift->setCreatedAt($now);
                $shift->setUpdatedAt($now);
                $this->shiftMapper->insert($shift);
                $created++;
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->logger->info(sprintf(
            '[TeamHub][RosterService] drafted %d shift(s) from a template for team %s, week of %s (%d skipped)',
            $created, $teamId, $monday, $skipped,
        ));

        return [
            'created' => $created,
            'skipped' => $skipped,
            'from'    => $monday,
            'to'      => $this->addDays($monday, 6),
        ];
    }

    /**
     * Publish every draft shift in a range, and write the result into presence.
     *
     * One timestamp for the whole act. Shifts already published keep the date
     * they were published on — that is the date that answers "was the notice
     * period met", and rewriting it because somebody edited the Thursday would
     * destroy the only record of it.
     *
     * @return array{published: int, from: string, to: string}
     */
    public function publishRange(string $teamId, string $fromDate, string $toDate, string $actorUid): array {
        $this->assertIsoDate($fromDate);
        $this->assertIsoDate($toDate);
        $this->assertRange($fromDate, $toDate);

        // Which shifts this act publishes, so only the people on them are
        // told. Re-publishing a settled week after adding one Thursday shift
        // tells the Thursday people, not the whole team again.
        $newly = [];
        foreach ($this->shiftMapper->findByTeamAndRange($teamId, $fromDate, $toDate) as $shift) {
            if (!$shift->isPublished()) {
                $newly[$shift->getId()] = true;
            }
        }

        $at    = time();
        $count = $this->shiftMapper->publishRange($teamId, $fromDate, $toDate, $actorUid, $at);

        $types = [];
        foreach ($this->typeMapper->findAll() as $type) {
            $types[$type->getId()] = $type;
        }

        // Write presence from the state *after* publishing, so a shift that
        // was already out and one that just went out are treated the same.
        $byUser = [];
        $toTell = [];
        foreach ($this->shiftMapper->findByTeamAndRange($teamId, $fromDate, $toDate) as $shift) {
            if (!$shift->isPublished()) {
                continue;
            }
            $facts = $this->shiftFacts($shift, $types[$shift->getShiftTypeId()] ?? null);
            foreach ($this->assignMapper->findByShift($shift->getId()) as $row) {
                $this->writePresenceForShift($row->getUserId(), $shift);
                $byUser[$row->getUserId()][] = $facts;
                if (isset($newly[$shift->getId()])) {
                    $toTell[$row->getUserId()] = true;
                }
            }
        }

        // One notice per person for the whole week, listing all of it: what
        // somebody needs is their week, not the delta (DESIGN §2.250).
        $this->notices->weekPublished(
            $teamId, $fromDate, $toDate,
            array_intersect_key($byUser, $toTell),
            $actorUid,
        );

        $this->logger->info(sprintf(
            '[TeamHub][RosterService] published %d shift(s) for team %s, %s to %s',
            $count, $teamId, $fromDate, $toDate,
        ));

        return ['published' => $count, 'from' => $fromDate, 'to' => $toDate];
    }

    /**
     * Take a published week back to concept (v4.13.68).
     *
     * Justin's rule: an admin may change a published roster at any time by
     * setting it back to concept, editing, and publishing again. So this is
     * the exact inverse of publishing and has to undo everything publishing
     * did, or the two would drift:
     *
     *   - `published_at` back to 0, so members stop seeing it;
     *   - the presence rows the shifts wrote are removed, because a plan that
     *     is no longer published must not keep telling the planning grid that
     *     somebody is working;
     *   - open hand-over requests are **withdrawn**, because an invitation to
     *     take over a shift in a roster that has been pulled is a dead link
     *     sitting in a Talk conversation.
     *
     * `published_by` is deliberately left as it was: it is the record of who
     * communicated the week, and the week *was* communicated. Clearing it
     * would destroy the only evidence of that.
     *
     * @return array{unpublished: int, from: string, to: string}
     */
    public function unpublishRange(string $teamId, string $fromDate, string $toDate, string $actorUid = ''): array {
        $this->assertIsoDate($fromDate);
        $this->assertIsoDate($toDate);
        $this->assertRange($fromDate, $toDate);

        $now   = time();
        $count = 0;
        $told  = [];

        foreach ($this->shiftMapper->findByTeamAndRange($teamId, $fromDate, $toDate) as $shift) {
            if (!$shift->isPublished()) {
                continue;
            }
            foreach ($this->assignMapper->findByShift($shift->getId()) as $row) {
                $this->clearPresenceForShift($row->getUserId(), $shift);
                $told[] = $row->getUserId();
            }
            $this->requestMapper->withdrawOpenByShift($shift->getId(), $now);
            // A week back in concept is not a week anybody was told, so the
            // copies of it come out of calendars too.
            $this->calendarCopies->removeAllFor($shift->getId());

            $shift->setPublishedAt(0);
            $shift->setUpdatedAt($now);
            $this->shiftMapper->update($shift);
            $count++;
        }

        // The people who had been told this week now hear it may change.
        $this->notices->weekWithdrawn($teamId, $fromDate, $toDate, $told, $actorUid);

        $this->logger->info(sprintf(
            '[TeamHub][RosterService] took %d shift(s) back to concept for team %s, %s to %s',
            $count, $teamId, $fromDate, $toDate,
        ));

        return ['unpublished' => $count, 'from' => $fromDate, 'to' => $toDate];
    }

    // ---------------------------------------------------------------------
    // Hand-over requests
    // ---------------------------------------------------------------------

    /**
     * "Can somebody take this shift?" — recorded, then announced in Talk.
     *
     * **The row is the record; the Talk message is only how people hear about
     * it.** A conversation can be deleted and a message scrolls away, and the
     * roster still has to be able to say who asked, who took it and when. So
     * the request is written first and the post is best-effort: a team with no
     * Talk conversation still gets a working request, visible on the roster,
     * and is told the announcement did not go out.
     *
     * @return array{request: array<string, mixed>, announced: bool}
     */
    public function requestHandover(int $shiftId, string $teamId, string $uid, string $reason): array {
        $shift = $this->requireShift($shiftId, $teamId);

        if (!$shift->isPublished()) {
            // A draft is not a commitment, so there is nothing to hand over.
            throw new \InvalidArgumentException($this->l->t('That shift has not been published yet.'));
        }
        if ($this->assignMapper->findOne($shiftId, $uid) === null) {
            throw new \InvalidArgumentException($this->l->t('You are not on that shift.'));
        }
        if ($this->requestMapper->findOpenFor($shiftId, $uid) !== null) {
            throw new \InvalidArgumentException($this->l->t('You have already asked about this shift.'));
        }

        $request = new ShiftRequest();
        $request->setShiftId($shiftId);
        $request->setTeamId($teamId);
        $request->setRequesterUid($uid);
        $request->setReason(mb_substr(trim($reason), 0, 255));
        $request->setStatus(ShiftRequest::STATUS_OPEN);
        $request->setCreatedAt(time());

        /** @var ShiftRequest $saved */
        $saved = $this->requestMapper->insert($request);

        return [
            'request'   => $this->serializeRequest($saved),
            'announced' => $this->announceHandover($teamId, $shift, $saved, $uid),
        ];
    }

    /**
     * Take over a shift somebody asked to be relieved of.
     *
     * A hand-over, not a swap: the requester comes off, the claimer goes on,
     * at once. Everything that guards a normal assignment guards this one —
     * membership, a free place once the requester has left it, and
     * availability — because a hand-over that rosters somebody onto their own
     * holiday is the same bug by another route.
     *
     * The whole exchange is one transaction. A claim that took the requester
     * off and then failed to put the claimer on would leave a shift short and
     * nobody aware of it.
     */
    public function claimHandover(int $requestId, string $claimerUid): array {
        $request = $this->requestMapper->findById($requestId);
        if ($request === null) {
            throw new \InvalidArgumentException($this->l->t('That request does not exist.'));
        }
        if (!$request->isOpen()) {
            throw new \InvalidArgumentException($this->l->t('Somebody has already taken that shift.'));
        }
        if ($request->getRequesterUid() === $claimerUid) {
            throw new \InvalidArgumentException($this->l->t('That is your own request.'));
        }

        $teamId = $request->getTeamId();
        $shift  = $this->requireShift($request->getShiftId(), $teamId);

        if (!$this->memberService->isEffectiveMember($teamId, $claimerUid, $this->db)) {
            throw new \InvalidArgumentException($this->l->t('You are not a member of that team.'));
        }
        if ($this->assignMapper->findOne($shift->getId(), $claimerUid) !== null) {
            throw new \InvalidArgumentException($this->l->t('You are already on that shift.'));
        }

        // Availability is a hard constraint here too, and so is being on
        // another shift at the same hours.
        $blocked = $this->absenceBlocking($claimerUid, $shift)
            ?? $this->overlapBlocking($claimerUid, $shift, true);
        if ($blocked !== null) {
            throw new PresenceConflictException($blocked);
        }

        $outgoing = $this->assignMapper->findOne($shift->getId(), $request->getRequesterUid());
        $now      = time();

        $this->db->beginTransaction();
        try {
            if ($outgoing !== null) {
                $this->assignMapper->delete($outgoing);
            }

            $row = new RosterAssignment();
            $row->setShiftId($shift->getId());
            $row->setUserId($claimerUid);
            $row->setOrigin(RosterAssignment::ORIGIN_CLAIMED);
            $row->setAssignedBy($claimerUid);
            $row->setCreatedAt($now);
            $this->assignMapper->insert($row);

            $request->setStatus(ShiftRequest::STATUS_CLAIMED);
            $request->setClaimedBy($claimerUid);
            $request->setResolvedAt($now);
            $this->requestMapper->update($request);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Presence follows the people, outside the transaction: a failure here
        // must not undo a hand-over that both parties have already agreed to.
        if ($shift->isPublished()) {
            if ($outgoing !== null) {
                $this->clearPresenceForShift($request->getRequesterUid(), $shift);
            }
            $this->writePresenceForShift($claimerUid, $shift);
        }
        // The shift left the requester's week, so it leaves their calendar.
        // The claimer's calendar is not written: they did not ask for that.
        if ($outgoing !== null) {
            $this->calendarCopies->removeFor($shift->getId(), $request->getRequesterUid());
        }
        // The requester asked and is waiting to hear; Talk may not reach them.
        $this->notices->aboutShift(
            RosterNotificationService::HANDOVER_TAKEN,
            $request->getRequesterUid(),
            $teamId,
            $shift->getId(),
            $this->shiftFacts($shift, $this->typeMapper->findById($shift->getShiftTypeId())),
            $claimerUid,
        );

        $this->announceClaim($teamId, $shift, $request, $claimerUid);

        return $this->serializeRequest($request);
    }

    /** The requester changing their mind. */
    public function withdrawHandover(int $requestId, string $uid): array {
        $request = $this->requestMapper->findById($requestId);
        if ($request === null || !$request->isOpen()) {
            throw new \InvalidArgumentException($this->l->t('That request does not exist.'));
        }
        if ($request->getRequesterUid() !== $uid) {
            throw new \InvalidArgumentException($this->l->t('That is not your request.'));
        }

        $request->setStatus(ShiftRequest::STATUS_WITHDRAWN);
        $request->setResolvedAt(time());
        $this->requestMapper->update($request);

        return $this->serializeRequest($request);
    }

    /** @return array<string, mixed> */
    private function serializeRequest(ShiftRequest $request): array {
        return [
            'id'           => $request->getId(),
            'shiftId'      => $request->getShiftId(),
            'requesterUid' => $request->getRequesterUid(),
            'reason'       => $request->getReason(),
            'status'       => $request->getStatus(),
            'claimedBy'    => $request->getClaimedBy(),
            'createdAt'    => $request->getCreatedAt(),
        ];
    }

    // ---------------------------------------------------------------------
    // Announcing
    // ---------------------------------------------------------------------

    /**
     * Announce a published roster in the team's **message stream**.
     *
     * The stream rather than Talk, deliberately (Justin, 2026-11-01): a
     * published roster is a notice that has to stay findable by somebody who
     * joins next week, and a chat message scrolls away. Hand-over requests go
     * to Talk instead, because those are a conversation and want an answer
     * now.
     *
     * @return array{posted: bool, error: string}
     */
    public function notifyTeam(string $teamId, string $fromDate, string $toDate): array {
        $this->assertIsoDate($fromDate);
        $this->assertIsoDate($toDate);

        $shifts = array_filter(
            $this->shiftMapper->findByTeamAndRange($teamId, $fromDate, $toDate),
            static fn (RosterShift $s): bool => $s->isPublished(),
        );
        if ($shifts === []) {
            throw new \InvalidArgumentException(
                $this->l->t('There is no published roster for that week to announce.'),
            );
        }

        $open = 0;
        foreach ($shifts as $shift) {
            $open += max(0, $shift->getRequiredCount() - count($this->assignMapper->findByShift($shift->getId())));
        }

        // IL10N::t() takes **sprintf** parameters, not the `{name}` form the
        // JavaScript t() uses. A named placeholder here is never substituted
        // and ships as literal braces, which is exactly what 4.13.68 did.
        // Positional `%1$s` rather than bare `%s`, so a translator can move
        // the dates within the sentence.
        $subject = $this->l->t('Roster published: %1$s to %2$s', [$fromDate, $toDate]);
        $body    = $open > 0
            ? $this->l->n(
                'The roster for %s to %s is published. %n place is still open.',
                'The roster for %s to %s is published. %n places are still open.',
                $open,
                [$fromDate, $toDate],
            )
            : $this->l->t('The roster for %1$s to %2$s is published and fully covered.', [$fromDate, $toDate]);

        try {
            $this->messageService->createMessage($teamId, $subject, $body);
            return ['posted' => true, 'error' => ''];
        } catch (\Throwable $e) {
            // Never fail the publish because the announcement did not land.
            $this->logger->warning('[TeamHub][RosterService] notifyTeam failed: ' . $e->getMessage());
            return ['posted' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Post the hand-over request into the team's Talk conversation, with a
     * link a colleague can open to take the shift.
     *
     * Best effort by design — see `requestHandover()`.
     */
    private function announceHandover(string $teamId, RosterShift $shift, ShiftRequest $request, string $uid): bool {
        $token = $this->teamTalkToken($teamId);
        if ($token === null) {
            return false;
        }

        $label = $this->shiftLabel($shift);
        $link  = $this->urlGenerator->linkToRouteAbsolute('teamhub.page.index', [
            'team'         => $teamId,
            'shiftRequest' => (string)$request->getId(),
        ]);

        // The question first, then why, then the link on its own line. Talk
        // renders a bare URL as a preview card, so it has to end the message
        // rather than sit mid-sentence.
        $text = $this->l->t(
            'Can somebody take the %1$s shift on %2$s?',
            [$label, $this->formatDate($shift->getShiftDate())],
        ) . "

";
        if ($request->getReason() !== '') {
            $text .= $request->getReason() . "
";
        }
        $text .= $link;

        try {
            return $this->talkService->postAsParticipant($token, $uid, $text);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][RosterService] handover announcement failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Tell the same conversation that the shift found a taker. */
    private function announceClaim(string $teamId, RosterShift $shift, ShiftRequest $request, string $claimerUid): void {
        $token = $this->teamTalkToken($teamId);
        if ($token === null) {
            return;
        }
        $label = $this->shiftLabel($shift);

        try {
            $this->talkService->postAsParticipant($token, $claimerUid, $this->l->t(
                '%1$s has taken the %2$s shift on %3$s from %4$s.',
                [
                    $this->displayName($claimerUid),
                    $label,
                    $this->formatDate($shift->getShiftDate()),
                    $this->displayName($request->getRequesterUid()),
                ],
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][RosterService] claim announcement failed: ' . $e->getMessage());
        }
    }

    /**
     * What this shift is called, in a sentence.
     *
     * The **Role** where one was given ("Receptionist"), because that is what
     * the person reading the roster sees on the card and what identifies the
     * shift to them. The shift type ("Early") only when there is no role.
     */
    private function shiftLabel(RosterShift $shift): string {
        $role = trim($shift->getNotes());
        if ($role !== '') {
            return $role;
        }
        return $this->typeMapper->findById($shift->getShiftTypeId())?->getLabel()
            ?? $this->l->t('shift');
    }

    /**
     * What a notice says about a shift: the facts, not the sentence. The
     * sentence is built per recipient language in `Notifier`.
     *
     * @return array{date: string, label: string, start: string, end: string}
     */
    private function shiftFacts(RosterShift $shift, ?ShiftType $type): array {
        return [
            'date'  => $shift->getShiftDate(),
            'label' => $this->shiftLabel($shift),
            'start' => $type?->getStartTime() ?? '',
            'end'   => $type?->getEndTime() ?? '',
        ];
    }

    /**
     * A shift date, written the way this instance writes dates.
     *
     * **A Talk message is one string posted once into a room**, so it can
     * follow one locale and not each reader's: this is the language the
     * poster's session resolves to. That is the right answer for the single
     * language most teams work in, and the honest limit of a shared message.
     *
     * Built at noon, because a shift date is a floating date (DESIGN §2.4):
     * at midnight a negative timezone offset formats the day before.
     */
    private function formatDate(string $isoDate): string {
        try {
            return (string)$this->l->l('date', new \DateTime($isoDate . ' 12:00:00'));
        } catch (\Throwable) {
            return $isoDate;
        }
    }

    /**
     * Somebody's display name, falling back to their uid.
     *
     * A Talk message that reads "jaap is asking..." is the uid leaking into a
     * sentence meant for people; the account name is an identifier, not a name.
     */
    private function displayName(string $uid): string {
        $name = $this->userManager->get($uid)?->getDisplayName() ?? '';
        return $name !== '' ? $name : $uid;
    }

    /** The team's Talk room, or null. Same lookup as DecisionService. */
    private function teamTalkToken(string $teamId): ?string {
        try {
            $resources = $this->resourceService->getTeamResources($teamId);
            $token     = $resources['talk']['token'] ?? null;
            return is_string($token) && $token !== '' ? $token : null;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][RosterService] teamTalkToken lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    // ---------------------------------------------------------------------
    // Presence write-through
    // ---------------------------------------------------------------------

    /**
     * The (date, half-day) pairs a shift occupies.
     *
     * The presence grid is half-days and a shift is a time range, so the two
     * have to be reconciled somewhere; it is done here, once, rather than
     * guessed at by each reader. The rule is the noon split: a shift occupies
     * the morning if it starts before 12:00 and the afternoon if it ends after
     * it. A shift that runs past midnight also occupies the next day's halves
     * up to where it ends — a night shift genuinely does make somebody
     * unavailable the following morning, and the grid should say so.
     *
     * @return list<array{0: string, 1: int}>
     */
    public function occupiedHalves(ShiftType $type, string $shiftDate): array {
        $start = $type->startMinutes();
        $end   = $type->endMinutes();
        if ($start === null || $end === null) {
            return [];
        }

        $out = [];
        if ($type->endsNextDay()) {
            // Runs to midnight on the shift's own date.
            if ($start < self::NOON) {
                $out[] = [$shiftDate, 0];
            }
            $out[] = [$shiftDate, 1];

            $next = $this->nextDate($shiftDate);
            if ($end > 0) {
                $out[] = [$next, 0];
            }
            if ($end > self::NOON) {
                $out[] = [$next, 1];
            }
            return $out;
        }

        if ($start < self::NOON) {
            $out[] = [$shiftDate, 0];
        }
        if ($end > self::NOON) {
            $out[] = [$shiftDate, 1];
        }
        return $out;
    }

    /**
     * Is this person unavailable for any half-day the shift occupies?
     *
     * Returns the sentence to refuse with, or null when they are free. Only a
     * deliberate absence blocks: a holiday the administrator locked, or a
     * busy override the person entered (vacation, sick, non-working). A
     * template-sourced workplace does not block — being down as *Office* on
     * Thursday is not a reason not to be rostered on Thursday.
     */
    private function absenceBlocking(string $userId, RosterShift $shift): ?string {
        $type = $this->typeMapper->findById($shift->getShiftTypeId());
        if ($type === null) {
            return null;
        }

        $busyTypeIds = [];
        foreach ($this->presenceTypeMapper->findAll() as $presenceType) {
            if ($presenceType->getIsBusy() === 1) {
                $busyTypeIds[$presenceType->getId()] = true;
            }
        }

        foreach ($this->occupiedHalves($type, $shift->getShiftDate()) as [$date, $half]) {
            $slot = $this->slotMapper->findSlot($userId, $date, $half);
            if ($slot === null) {
                continue;
            }
            // v4.13.71 — a public holiday no longer refuses. Plenty of teams
            // roster precisely on the days an office is shut, and a rota that
            // cannot say so is a rota kept on paper beside this one (Justin,
            // 2026-11-01). The day is still *marked* in the grid, so nobody
            // staffs Christmas without noticing; it is a colour, not a lock.
            if ($slot->getSource() === 'override' && isset($busyTypeIds[$slot->getPresenceTypeId()])) {
                return $this->l->t('That person has time off then.');
            }
        }

        return null;
    }

    /**
     * Is this person already on another shift whose hours overlap this one?
     *
     * Returns the sentence to refuse with, or null. v4.14.8 (DESIGN §2.250):
     * before this, `assign()` asked about membership, a free place and time
     * off and **never about time**, so since §2.240 made two roles on one type
     * and day expressible, one person could be *Receptionist on Early* and
     * *Barista on Early* on the same Monday. Being in two places at once is
     * not a preference somebody can be talked out of, so this refuses rather
     * than advises, like time off and unlike availability (§2.237).
     *
     * **Across every team, drafts included.** Somebody in two teams is one
     * person with one Monday; and a draft in the other team is already that
     * team's plan for them, which the admin there would otherwise discover by
     * publishing a week that cannot be worked. The sentence names neither the
     * other shift nor its team: a team admin here has no business learning
     * what another team has drafted.
     *
     * The window is the day before to the day after, because a night shift
     * that started yesterday reaches into this morning, and one starting
     * tonight reaches into tomorrow. Back-to-back shifts (Early ends 15:00,
     * Late starts 15:00) touch and do not overlap.
     *
     * @param bool $self the person is assigning themselves (a claim)
     */
    private function overlapBlocking(string $userId, RosterShift $shift, bool $self): ?string {
        $types = [];
        foreach ($this->typeMapper->findAll() as $type) {
            $types[$type->getId()] = $type;
        }

        $mine = self::shiftWindow($types[$shift->getShiftTypeId()] ?? null, $shift->getShiftDate());
        if ($mine === null) {
            return null;
        }

        $date = $shift->getShiftDate();
        foreach ($this->shiftMapper->findAssignedToUser($userId, $this->addDays($date, -1), $this->addDays($date, 1)) as $other) {
            if ($other->getId() === $shift->getId()) {
                continue;
            }
            $theirs = self::shiftWindow($types[$other->getShiftTypeId()] ?? null, $other->getShiftDate());
            if ($theirs !== null && self::windowsOverlap($mine, $theirs)) {
                return $self
                    ? $this->l->t('You are already working another shift at that time.')
                    : $this->l->t('That person is already working another shift at that time.');
            }
        }

        return null;
    }

    /**
     * A shift's hours as [start, end) in minutes on one continuous scale.
     *
     * Minutes since 1970-01-01 of the floating date, plus the type's times; a
     * shift that ends next day gets 1440 added to its end. No timezone is
     * involved anywhere, which is right for a floating date (DESIGN §2.4):
     * both shifts being compared are on the same wall clock.
     *
     * @return array{0: int, 1: int}|null null for a removed or malformed type
     */
    public static function shiftWindow(?ShiftType $type, string $shiftDate): ?array {
        if ($type === null) {
            return null;
        }
        $start = $type->startMinutes();
        if ($start === null || $type->lengthMinutes() <= 0) {
            return null;
        }
        try {
            $day = (int)floor((new \DateTimeImmutable($shiftDate . ' 00:00:00', new \DateTimeZone('UTC')))->getTimestamp() / 86400);
        } catch (\Throwable) {
            return null;
        }
        $from = $day * 1440 + $start;
        return [$from, $from + $type->lengthMinutes()];
    }

    /**
     * Half-open intervals: a shift ending at 15:00 and one starting at 15:00
     * touch and do not overlap.
     *
     * @param array{0: int, 1: int} $a
     * @param array{0: int, 1: int} $b
     */
    public static function windowsOverlap(array $a, array $b): bool {
        return $a[0] < $b[1] && $b[0] < $a[1];
    }

    /**
     * Write a published shift into its assignee's presence.
     *
     * Never over a holiday or a deliberate absence — `absenceBlocking()` has
     * already refused those at assignment time, and this is the second gate
     * for the case where the absence was entered afterwards. A
     * template-sourced slot **is** replaced: a rostered shift is a stronger
     * statement about the day than a weekly default.
     */
    private function writePresenceForShift(string $userId, RosterShift $shift): void {
        $type = $this->typeMapper->findById($shift->getShiftTypeId());
        if ($type === null) {
            return;
        }

        $presenceTypeId = $this->rosterPresenceTypeId();
        if ($presenceTypeId === null) {
            // No workplace type to stand in for a shift. Refusing to guess is
            // better than writing a slot that renders as something it is not.
            $this->logger->warning(
                '[TeamHub][RosterService] no presence type available for the roster write-through; skipping',
            );
            return;
        }

        $now = time();
        foreach ($this->occupiedHalves($type, $shift->getShiftDate()) as [$date, $half]) {
            $existing = $this->slotMapper->findSlot($userId, $date, $half);

            // v4.13.71 — a holiday is overwritten now that one no longer
            // blocks the assignment. Somebody rostered on a public holiday is
            // working it, and a grid that kept showing them as off would
            // contradict the roster beside it. A deliberate absence still
            // wins: that is the person's own statement about their day.
            if ($existing !== null
                && $existing->getSource() !== 'template'
                && $existing->getSource() !== 'holiday'
                && $existing->getSource() !== self::PRESENCE_SOURCE) {
                continue; // A deliberate absence. Leave it.
            }

            $slot = $existing ?? new PresenceSlot();
            $slot->setUserId($userId);
            $slot->setSlotDate($date);
            $slot->setHalfDay($half);
            $slot->setPresenceTypeId($presenceTypeId);
            $slot->setLocationRoomId($shift->getRoomId());
            $slot->setSource(self::PRESENCE_SOURCE);
            $slot->setUpdatedAt($now);

            if ($existing === null) {
                $slot->setCreatedAt($now);
                $this->slotMapper->insert($slot);
            } else {
                $this->slotMapper->update($slot);
            }
        }
    }

    /** Remove the presence rows a shift put there, leaving everything else. */
    private function clearPresenceForShift(string $userId, RosterShift $shift): void {
        $type = $this->typeMapper->findById($shift->getShiftTypeId());
        if ($type === null) {
            return;
        }

        foreach ($this->occupiedHalves($type, $shift->getShiftDate()) as [$date, $half]) {
            $slot = $this->slotMapper->findSlot($userId, $date, $half);
            // Only ever delete what the roster wrote. A member who declared
            // something for that half-day since keeps it.
            if ($slot !== null && $slot->getSource() === self::PRESENCE_SOURCE) {
                $this->slotMapper->delete($slot);
            }
        }
    }

    /**
     * The workplace type a rostered shift reads as in the presence grid.
     *
     * Prefers `office`, falls back to the first non-busy selectable type. A
     * shift means the person is working, so it must never land on a busy type
     * — that would make the grid report somebody as away on a day they are
     * rostered to work, which is the one answer worse than no answer.
     */
    private function rosterPresenceTypeId(): ?int {
        $fallback = null;
        foreach ($this->presenceTypeMapper->findAll() as $type) {
            if ($type->getIsBusy() === 1) {
                continue;
            }
            if ($type->getSlug() === 'office') {
                return $type->getId();
            }
            $fallback ??= $type->getId();
        }
        return $fallback;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function requireShift(int $shiftId, string $teamId): RosterShift {
        $shift = $this->shiftMapper->findById($shiftId);
        // The team is checked here rather than trusted from the route: a shift
        // id is guessable, and "the frontend only sends its own" is not a
        // boundary (CLAUDE.md).
        if ($shift === null || $shift->getTeamId() !== $teamId) {
            throw new \InvalidArgumentException($this->l->t('That shift does not exist.'));
        }
        return $shift;
    }

    private function assertIsoDate(string $date): void {
        if (preg_match(self::ISO_DATE_RE, $date) !== 1) {
            throw new \InvalidArgumentException($this->l->t('Dates must be in YYYY-MM-DD form.'));
        }
    }

    private function assertRange(string $fromDate, string $toDate): void {
        if ($fromDate > $toDate) {
            throw new \InvalidArgumentException($this->l->t('The range starts after it ends.'));
        }
        $from = new \DateTimeImmutable($fromDate . ' 12:00:00');
        $to   = new \DateTimeImmutable($toDate . ' 12:00:00');
        if ((int)$from->diff($to)->days >= self::MAX_RANGE_DAYS) {
            throw new \InvalidArgumentException($this->l->t('Ask for at most 31 days at a time.'));
        }
    }

    /** The next calendar day. A floating date, so no timezone is involved. */
    private function nextDate(string $isoDate): string {
        return $this->addDays($isoDate, 1);
    }

    /** $isoDate plus n days, as a floating date. */
    private function addDays(string $isoDate, int $days): string {
        return (new \DateTimeImmutable($isoDate . ' 12:00:00'))
            ->modify(sprintf('%+d day', $days))
            ->format('Y-m-d');
    }

    /**
     * The Monday of the week a date falls in.
     *
     * Monday-first throughout the module, so a template line's weekday 0 and
     * the roster grid's first column are the same day. PHP's `N` is 1 for
     * Monday.
     */
    private function mondayOf(string $isoDate): string {
        $day = new \DateTimeImmutable($isoDate . ' 12:00:00');
        return $this->addDays($isoDate, -((int)$day->format('N') - 1));
    }
}
