<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\PresenceSlotMapper;
use OCA\TeamHub\Db\PresenceTemplate;
use OCA\TeamHub\Db\PresenceTemplateMapper;
use OCA\TeamHub\Db\PresenceTypeMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * User week-template CRUD and holiday-revert logic.
 *
 * A user's presence template is a 14-cell grid: 7 days (Mon–Sun) × 2 half-days
 * (Morning=0, Afternoon=1). Each cell holds an optional presence_type_id and
 * optional location_room_id. Cells with presence_type_id=null are "empty" —
 * no presence shown for that cell.
 *
 * Holiday revert (real implementation, replacing B1 stub):
 *   When an admin removes a holiday from date D, every source='holiday' slot on
 *   that date reverts. If the user has a template row for (day_of_week, half_day),
 *   revert the slot to those template values. Otherwise, delete the slot.
 */
class PresenceTemplateService {

    public function __construct(
        private PresenceTemplateMapper         $templateMapper,
        private PresenceSlotMapper             $slotMapper,
        private PresenceTypeMapper             $typeMapper,
        private PresenceMaterialisationService $materialisation,
        private PresenceCalendarService        $calendarService,
        private LoggerInterface                $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Return the full 14-cell template as a plain array.
     * Missing cells are included with presence_type_id=null.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTemplate(string $userId): array {
        $rows = $this->templateMapper->findByUser($userId);

        $indexed = [];
        foreach ($rows as $r) {
            $indexed[$r->getDayOfWeek() * 2 + $r->getHalfDay()] = $r;
        }

        $out = [];
        for ($day = 0; $day <= 6; $day++) {
            foreach ([0, 1] as $half) {
                $key = $day * 2 + $half;
                $row = $indexed[$key] ?? null;
                $out[] = [
                    'day_of_week'      => $day,
                    'half_day'         => $half,
                    'presence_type_id' => $row?->getPresenceTypeId(),
                    'location_room_id' => $row?->getLocationRoomId(),
                    'updated_at'       => $row?->getUpdatedAt(),
                ];
            }
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Bulk write — saves all cells, materialises once
    // -------------------------------------------------------------------------

    /**
     * Save up to 14 template cells in one call, then trigger a single
     * rematerialisation. This avoids the duplicate-key race that occurs
     * when 14 concurrent setCell calls each trigger rematerialiseForUser.
     *
     * $cells is an array of arrays, each with keys:
     *   day_of_week, half_day, presence_type_id (nullable), location_room_id (nullable)
     *
     * Returns the full 14-cell template (same shape as getTemplate).
     *
     * @param list<array<string, mixed>> $cells
     * @return array<int, array<string, mixed>>
     */
    public function setBulk(string $userId, array $cells): array {
        $now = time();

        foreach ($cells as $data) {
            $dayOfWeek = $this->validateDayOfWeek($data['day_of_week'] ?? null);
            $halfDay   = $this->validateHalfDay($data['half_day'] ?? null);
            $typeId    = isset($data['presence_type_id']) && $data['presence_type_id'] !== null
                ? $this->validateTypeId((int)$data['presence_type_id'])
                : null;
            $roomId    = isset($data['location_room_id']) && $data['location_room_id'] !== null
                ? (int)$data['location_room_id']
                : null;

            $existing = $this->templateMapper->findCell($userId, $dayOfWeek, $halfDay);

            if ($typeId === null) {
                if ($existing !== null) {
                    $this->templateMapper->delete($existing);
                }
                continue;
            }

            if ($existing !== null) {
                $existing->setPresenceTypeId($typeId);
                $existing->setLocationRoomId($roomId);
                $existing->setUpdatedAt($now);
                $this->templateMapper->update($existing);
            } else {
                $t = new PresenceTemplate();
                $t->setUserId($userId);
                $t->setDayOfWeek($dayOfWeek);
                $t->setHalfDay($halfDay);
                $t->setPresenceTypeId($typeId);
                $t->setLocationRoomId($roomId);
                $t->setCreatedAt($now);
                $t->setUpdatedAt($now);
                $this->templateMapper->insert($t);
            }
        }

        // Single rematerialisation after all cells are saved.
        $this->materialisation->rematerialiseForUser($userId);

        return $this->getTemplate($userId);
    }

    // -------------------------------------------------------------------------
    // Write — one cell at a time
    // -------------------------------------------------------------------------

    /**
     * Upsert a single template cell, then trigger immediate re-materialisation.
     * Pass presence_type_id=null to clear the cell (deletes the row).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function setCell(string $userId, array $data): array {
        $dayOfWeek = $this->validateDayOfWeek($data['day_of_week'] ?? null);
        $halfDay   = $this->validateHalfDay($data['half_day'] ?? null);
        $typeId    = isset($data['presence_type_id']) && $data['presence_type_id'] !== null
            ? $this->validateTypeId((int)$data['presence_type_id'])
            : null;
        $roomId    = isset($data['location_room_id']) && $data['location_room_id'] !== null
            ? (int)$data['location_room_id']
            : null;

        $now      = time();
        $existing = $this->templateMapper->findCell($userId, $dayOfWeek, $halfDay);

        if ($typeId === null) {
            if ($existing !== null) {
                $this->templateMapper->delete($existing);
            }
            $this->materialisation->rematerialiseForUser($userId);
            return [
                'day_of_week'      => $dayOfWeek,
                'half_day'         => $halfDay,
                'presence_type_id' => null,
                'location_room_id' => null,
                'updated_at'       => $now,
            ];
        }

        if ($existing !== null) {
            $existing->setPresenceTypeId($typeId);
            $existing->setLocationRoomId($roomId);
            $existing->setUpdatedAt($now);
            /** @var PresenceTemplate $saved */
            $saved = $this->templateMapper->update($existing);
        } else {
            $t = new PresenceTemplate();
            $t->setUserId($userId);
            $t->setDayOfWeek($dayOfWeek);
            $t->setHalfDay($halfDay);
            $t->setPresenceTypeId($typeId);
            $t->setLocationRoomId($roomId);
            $t->setCreatedAt($now);
            $t->setUpdatedAt($now);
            /** @var PresenceTemplate $saved */
            $saved = $this->templateMapper->insert($t);
        }

        $this->materialisation->rematerialiseForUser($userId);
        return $this->serializeCell($saved);
    }

    // -------------------------------------------------------------------------
    // Holiday revert — real implementation of the B1 stub
    // -------------------------------------------------------------------------

    /**
     * For each source='holiday' slot on $isoDate: revert to template values
     * if a template row exists for that (user, day_of_week, half_day), or
     * delete the slot if no template entry for that cell (per §D, B2 plan).
     */
    public function recomputeSlotsForDate(string $isoDate): void {
        $holidaySlots = $this->slotMapper->findHolidaySlotsOnDate($isoDate);

        if (count($holidaySlots) === 0) {
            $this->logger->debug(
                '[TeamHub][PresenceTemplateService] recomputeSlotsForDate: '
                . "no holiday slots on {$isoDate}"
            );
            return;
        }

        $dow      = $this->isoDayOfWeek($isoDate);
        $now      = time();
        $reverted = 0;
        $deleted  = 0;

        foreach ($holidaySlots as $slot) {
            $tmpl = $this->templateMapper->findCell(
                $slot->getUserId(), $dow, $slot->getHalfDay()
            );

            if ($tmpl !== null && $tmpl->getPresenceTypeId() !== null) {
                $slot->setPresenceTypeId($tmpl->getPresenceTypeId());
                $slot->setLocationRoomId($tmpl->getLocationRoomId());
                $slot->setSource('template');
                $slot->setUpdatedAt($now);
                $this->slotMapper->update($slot);
                // Update the VEVENT to reflect the reverted template values (B4).
                $this->calendarService->syncSlot($slot);
                $reverted++;
            } else {
                // Delete the VEVENT before deleting the slot row (B4).
                $this->calendarService->deleteSlotEvent($slot);
                $this->slotMapper->delete($slot);
                $deleted++;
            }
        }

        $this->logger->info(sprintf(
            '[TeamHub][PresenceTemplateService] recomputeSlotsForDate %s: reverted=%d deleted=%d',
            $isoDate, $reverted, $deleted
        ));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** ISO date → 0=Mon … 6=Sun */
    private function isoDayOfWeek(string $isoDate): int {
        [$y, $m, $d] = array_map('intval', explode('-', $isoDate));
        return ((int)date('N', mktime(12, 0, 0, $m, $d, $y))) - 1;
    }

    private function validateDayOfWeek(mixed $v): int {
        $i = (int)$v;
        if ($i < 0 || $i > 6) {
            throw new \InvalidArgumentException('day_of_week must be 0–6');
        }
        return $i;
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

    /** @return array<string, mixed> */
    private function serializeCell(PresenceTemplate $t): array {
        return [
            'day_of_week'      => $t->getDayOfWeek(),
            'half_day'         => $t->getHalfDay(),
            'presence_type_id' => $t->getPresenceTypeId(),
            'location_room_id' => $t->getLocationRoomId(),
            'updated_at'       => $t->getUpdatedAt(),
        ];
    }
}
