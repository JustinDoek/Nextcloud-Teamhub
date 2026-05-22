<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\BackgroundJob\PresenceCalendarSyncJob;
use OCA\TeamHub\Db\HolidayMapper;
use OCA\TeamHub\Db\PresenceSlot;
use OCA\TeamHub\Db\PresenceSlotMapper;
use OCA\TeamHub\Db\PresenceTypeMapper;
use OCA\TeamHub\Exception\PresenceConflictException;
use OCA\TeamHub\Service\PresenceCalendarService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Per-slot user actions: read a date range, override a single slot.
 *
 * B4 addition: after every slot write the slot is synced to the user's default
 * calendar via PresenceCalendarService. Calendar sync failures are non-fatal —
 * logged as warnings, the slot write succeeds regardless.
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
        private PresenceTypeMapper      $typeMapper,
        private HolidayMapper           $holidayMapper,
        private PresenceCalendarService $calendarService,
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

        return array_map(
            fn(PresenceSlot $s) => $this->serialize($s, $types),
            $slots
        );
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
            ? $this->validateTypeId((int)$data['presence_type_id'])
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

        // Sync the calendar for this specific date synchronously — fast (2 slots max).
        // This handles the all-day merge/split logic for the affected date immediately.
        // Non-fatal: calendar failure must never prevent the slot save from succeeding.
        try {
            $this->calendarService->syncSlotsForDate($userId, $slotDate);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                '[TeamHub][PresenceSlotService] syncSlotsForDate failed for %s on %s: %s',
                $userId, $slotDate, $e->getMessage()
            ));
        }

        return $this->serialize($saved, $types);
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

    private function validateTypeId(int $typeId): int {
        if ($this->typeMapper->findById($typeId) === null) {
            throw new DoesNotExistException("Presence type {$typeId} not found");
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
