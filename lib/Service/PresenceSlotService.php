<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\HolidayMapper;
use OCA\TeamHub\Db\PresenceSlot;
use OCA\TeamHub\Db\PresenceSlotMapper;
use OCA\TeamHub\Db\PresenceTemplateMapper;
use OCP\IDBConnection;
use OCA\TeamHub\Db\PresenceTypeMapper;
use OCA\TeamHub\Exception\PresenceConflictException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Per-slot user actions: read a date range, override a single slot.
 *
 * Workplace overrides never write to a calendar; Nextcloud owns working hours
 * and free/busy. Legacy calendar events may need a separate manual cleanup.
 *
 * Slot sources:
 *   'template' — auto-materialised from the user's week template.
 *   'override' — the user explicitly set this slot, overriding the template.
 *   'holiday'  — admin-locked; users cannot edit these (returns 409).
 */
class PresenceSlotService {

    /** ISO-date regex used throughout */
    private const ISO_DATE_RE = '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/';

    public function __construct(
        private PresenceSlotMapper      $slotMapper,
        private PresenceTemplateMapper  $templateMapper,
        private IDBConnection           $db,
        private PresenceTypeMapper      $typeMapper,
        private HolidayMapper           $holidayMapper,
        private IJobList                $jobList,
        private LoggerInterface         $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Return all slots for a user in an inclusive ISO date range, enriched
     * with type metadata so the frontend can render each cell without a
     * second request.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSlotsForUser(string $userId, string $fromDate, string $toDate): array {
        $this->assertIsoDate($fromDate);
        $this->assertIsoDate($toDate);

        $slots = $this->slotMapper->findByUserAndRange($userId, $fromDate, $toDate);

        $types = [];
        foreach ($this->typeMapper->findAll() as $t) {
            $types[$t->getId()] = $t;
        }

        $out = [];
        foreach ($slots as $slot) {
            $type = $types[$slot->getPresenceTypeId() ?? -1] ?? null;
            if ($slot->getSource() === 'holiday' || $type === null
                || ($type->getIsBusy() === 1 && $slot->getSource() !== 'override')) {
                continue; // Ignore legacy busy templates, retain deliberate time-off overrides.
            }
            $out[$slot->getSlotDate() . '_' . $slot->getHalfDay()] = $this->serialize($slot, $types);
        }
        $day = new \DateTimeImmutable($fromDate . ' 12:00:00');
        $last = new \DateTimeImmutable($toDate . ' 12:00:00');
        // Bound the public endpoint to the range used by the calendar UI.
        for ($i = 0; $i < 150 && $day <= $last; $i++, $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $holiday = $this->holidayMapper->findByDate($date);
            if ($holiday === null) { continue; }
            foreach ([0, 1] as $half) {
                $out[$date . '_' . $half] = [
                    'slot_date' => $date, 'half_day' => $half,
                    'presence_type_id' => null, 'presence_type_slug' => 'holiday',
                    'presence_type_label' => $holiday->getName(),
                    'presence_type_color' => '#C2185B', 'is_locked' => true,
                    'source' => 'holiday', 'location_room_id' => null,
                ];
            }
        }
        return array_values($out);
    }

    // -------------------------------------------------------------------------
    // Write — single-slot override
    // -------------------------------------------------------------------------

    /**
     * Override a single slot for the current user. If the slot doesn't exist
     * yet, creates it with source='override'. If it does exist:
     *   - source='holiday' → 409 PresenceConflictException (admin-locked)
     *   - source='template' or 'override' → update to new values, set source='override'
     *
     * After the slot write, syncs the VEVENT to the user's default calendar.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function overrideSlot(string $userId, array $data): array {
        $slotDate  = $this->assertIsoDate($data['slot_date'] ?? '');
        $halfDay   = $this->validateHalfDay($data['half_day'] ?? null);
        $typeId    = isset($data['presence_type_id']) && $data['presence_type_id'] !== null
            ? $this->validateTypeId((int)$data['presence_type_id'], true)
            : null;
        $roomId    = isset($data['location_room_id']) && $data['location_room_id'] !== null
            ? (int)$data['location_room_id']
            : null;

        // Check the holiday table first — blocks override even if no slot row
        // exists yet for this user on this date (e.g. slot wasn't materialised
        // before the holiday was added).
        if ($this->holidayMapper->findByDate($slotDate) !== null) {
            throw new PresenceConflictException(
                'This date is an admin-locked holiday and cannot be changed'
            );
        }

        $now      = time();
        $existing = $this->slotMapper->findSlot($userId, $slotDate, $halfDay);

        // Belt-and-suspenders: also check the slot source for legacy rows.
        if ($existing !== null && $existing->getSource() === 'holiday') {
            throw new PresenceConflictException(
                'This slot is locked by an admin holiday and cannot be changed'
            );
        }

        $types = [];
        foreach ($this->typeMapper->findAll() as $t) {
            $types[$t->getId()] = $t;
        }

        if ($existing !== null) {
            $existing->setPresenceTypeId($typeId);
            $existing->setLocationRoomId($roomId);
            $existing->setSource('override');
            $existing->setUpdatedAt($now);
            /** @var PresenceSlot $saved */
            $saved = $this->slotMapper->update($existing);
        } else {
            $slot = new PresenceSlot();
            $slot->setUserId($userId);
            $slot->setSlotDate($slotDate);
            $slot->setHalfDay($halfDay);
            $slot->setPresenceTypeId($typeId);
            $slot->setLocationRoomId($roomId);
            $slot->setSource('override');
            $slot->setCreatedAt($now);
            $slot->setUpdatedAt($now);
            /** @var PresenceSlot $saved */
            $saved = $this->slotMapper->insert($slot);
        }

        $this->logger->debug(sprintf(
            '[TeamHub][PresenceSlotService] overrideSlot: user=%s date=%s half=%d type=%s',
            $userId, $slotDate, $halfDay, $typeId ?? 'null'
        ));

        return $this->serialize($saved, $types);
    }

    // -------------------------------------------------------------------------
    // Date-range absences (and restoring the regular workplace)
    // -------------------------------------------------------------------------

    /**
     * Apply one user-selected absence type to a whole date range, choosing
     * morning, afternoon, or both. A special restore action brings back the
     * regular workplace template instead of storing a null override.
     *
     * We validate before opening a transaction, skip admin-locked public
     * holidays, then commit all remaining days or none of them.
     *
     * @return array{updated: int, skipped: int}
     */
    public function overrideRange(
        string $userId,
        string $fromDate,
        string $toDate,
        string $part,
        ?int $typeId,
        bool $restore = false,
    ): array {
        $from = new \DateTimeImmutable($this->assertIsoDate($fromDate) . ' 12:00:00');
        $to = new \DateTimeImmutable($this->assertIsoDate($toDate) . ' 12:00:00');
        $days = (int)$from->diff($to)->format('%r%a') + 1;
        if ($days < 1 || $days > 120) {
            throw new \InvalidArgumentException('Date range must contain 1 to 120 days');
        }
        if (!in_array($part, ['all', 'morning', 'afternoon'], true)) {
            throw new \InvalidArgumentException('part must be all, morning or afternoon');
        }
        if (!$restore) {
            if ($typeId === null) {
                throw new \InvalidArgumentException('Choose an absence type');
            }
            $this->validateTypeId($typeId, true);
            $type = $this->typeMapper->findById($typeId);
            if ($type === null || !in_array($type->getSlug(), ['vacation', 'sick', 'non_working_day'], true)) {
                throw new \InvalidArgumentException('Ranges can only be used for vacation or non-working time');
            }
        }

        $halves = $part === 'all' ? [0, 1] : [$part === 'morning' ? 0 : 1];
        $dates = [];
        $skipped = 0;
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            if ($this->holidayMapper->findByDate($date) !== null) {
                $skipped++;
                continue;
            }
            $locked = false;
            foreach ($halves as $half) {
                $existing = $this->slotMapper->findSlot($userId, $date, $half);
                if ($existing?->getSource() === 'holiday') {
                    $locked = true;
                    break;
                }
            }
            if ($locked) {
                $skipped++;
                continue;
            }
            $dates[] = $date;
        }
        if ($dates === []) {
            throw new PresenceConflictException('All selected dates are protected public holidays');
        }

        $this->db->beginTransaction();
        try {
            $updated = 0;
            foreach ($dates as $date) {
                foreach ($halves as $half) {
                    if ($restore) {
                        $this->restoreDefault($userId, $date, $half);
                    } else {
                        $this->overrideSlot($userId, [
                            'slot_date' => $date,
                            'half_day' => $half,
                            'presence_type_id' => $typeId,
                            'location_room_id' => null,
                        ]);
                    }
                    $updated++;
                }
            }
            $this->db->commit();
            return ['updated' => $updated, 'skipped' => $skipped];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Restore the user's normal workplace after removing a date override. */
    private function restoreDefault(string $userId, string $date, int $half): void {
        $slot = $this->slotMapper->findSlot($userId, $date, $half);
        if ($slot?->getSource() === 'holiday') {
            throw new PresenceConflictException('This date is an admin-locked public holiday');
        }
        $dow = (int)(new \DateTimeImmutable($date))->format('N') - 1;
        $template = $this->templateMapper->findCell($userId, $dow, $half);
        if ($template === null || $template->getPresenceTypeId() === null) {
            if ($slot !== null) {
                $this->slotMapper->delete($slot);
            }
            return;
        }
        $slot ??= new PresenceSlot();
        $isNew = $slot->getId() === null;
        if ($isNew) {
            $slot->setUserId($userId);
            $slot->setSlotDate($date);
            $slot->setHalfDay($half);
            $slot->setCreatedAt(time());
        }
        $slot->setPresenceTypeId($template->getPresenceTypeId());
        $slot->setLocationRoomId($template->getLocationRoomId());
        $slot->setSource('template');
        $slot->setUpdatedAt(time());
        if ($isNew) {
            $this->slotMapper->insert($slot);
        } else {
            $this->slotMapper->update($slot);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<int, \OCA\TeamHub\Db\PresenceType> $types Keyed by id
     * @return array<string, mixed>
     */
    private function serialize(PresenceSlot $s, array $types): array {
        $typeId   = $s->getPresenceTypeId();
        $typeRow  = $typeId !== null ? ($types[$typeId] ?? null) : null;

        return [
            'id'                 => $s->getId(),
            'slot_date'          => $s->getSlotDate(),
            'half_day'           => $s->getHalfDay(),
            'half_day_label'     => $s->getHalfDay() === 0 ? 'Morning' : 'Afternoon',
            'presence_type_id'   => $typeId,
            'presence_type_slug' => $typeRow?->getSlug(),
            'presence_type_label'=> $typeRow?->getLabel(),
            'presence_type_icon' => $typeRow?->getIcon(),
            'presence_type_color'=> $typeRow?->getColor(),
            'requires_location'  => $typeRow !== null && $typeRow->getRequiresLocation() === 1,
            'is_locked'          => $s->getSource() === 'holiday',
            'location_room_id'   => $s->getLocationRoomId(),
            'source'             => $s->getSource(),
            'updated_at'         => $s->getUpdatedAt(),
        ];
    }

    private function validateHalfDay(mixed $v): int {
        $i = (int)$v;
        if ($i !== 0 && $i !== 1) {
            throw new \InvalidArgumentException('half_day must be 0 (Morning) or 1 (Afternoon)');
        }
        return $i;
    }

    private function validateTypeId(int $typeId, bool $allowAbsence = false): int {
        $type = $this->typeMapper->findById($typeId);
        if ($type === null) {
            throw new DoesNotExistException("Presence type {$typeId} not found");
        }
        if ($type->getSelectableByUser() !== 1) {
            throw new \InvalidArgumentException('This type is not selectable by users');
        }
        if ($type->getIsBusy() === 1 && (!$allowAbsence
            || !in_array($type->getSlug(), ['vacation', 'sick', 'non_working_day'], true))) {
            throw new \InvalidArgumentException('Only approved time-off types can be used for non-working time');
        }
        return $typeId;
    }

    private function assertIsoDate(string $isoDate): string {
        if (!preg_match(self::ISO_DATE_RE, $isoDate)) {
            throw new \InvalidArgumentException("Invalid date: {$isoDate}");
        }
        [$y, $m, $d] = array_map('intval', explode('-', $isoDate));
        if (!checkdate($m, $d, $y)) {
            throw new \InvalidArgumentException("Not a valid calendar date: {$isoDate}");
        }
        return $isoDate;
    }
}
