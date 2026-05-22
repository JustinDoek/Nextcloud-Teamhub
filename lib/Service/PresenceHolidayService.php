<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\Holiday;
use OCA\TeamHub\Db\HolidayMapper;
use OCA\TeamHub\Db\PresenceSlot;
use OCA\TeamHub\Db\PresenceSlotMapper;
use OCA\TeamHub\Db\PresenceSlotQueryMapper;
use OCA\TeamHub\Db\PresenceTemplateMapper;
use OCA\TeamHub\Db\PresenceTypeMapper;
use OCA\TeamHub\Exception\PresenceConflictException;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Admin CRUD for teamhub_holidays.
 *
 * Holidays are admin-locked dates that destructively overwrite every slot on
 * that date with the built-in 'holiday' presence type. The user-side surface
 * shows these as non-editable slots in B3.
 *
 * The flow at the API surface is two-step on add:
 *   1. previewHoliday($date)  → returns { affectedSlots: N }  (no write)
 *   2. addHoliday($date, $name) → returns { holiday, affectedSlots: N }
 *
 * The preview step exists so the admin UI can show "this will overwrite N
 * user entries on date X. Continue?" before committing — required by
 * DESIGN.md's destructive-with-confirm pattern.
 *
 * On remove, every slot whose source='holiday' on that date is recomputed
 * from each user's week template via PresenceTemplateService (stub in B1,
 * real in B2). The holiday row is then deleted.
 *
 * The built-in 'holiday' presence type is referenced by slug ('holiday') —
 * its id is resolved at call time so the service is independent of seed
 * order. If the slug is missing (admin deleted it manually somehow), the
 * operation fails loudly rather than silently leaving the slot row in a
 * broken state.
 */
class PresenceHolidayService {

    /** Stable slug of the built-in "holiday" presence type. */
    private const HOLIDAY_TYPE_SLUG = 'holiday';

    /** ISO-date regex used everywhere in this service. */
    private const ISO_DATE_RE = '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/';

    public function __construct(
        private HolidayMapper           $holidays,
        private PresenceTypeMapper      $types,
        private PresenceSlotQueryMapper $slotQuery,
        private PresenceSlotMapper      $slotMapper,
        private PresenceTemplateMapper  $templateMapper,
        private PresenceTemplateService $templateService,
        private LoggerInterface         $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Holidays, optionally filtered to a single year.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHolidays(?int $year = null): array {
        $rows = $year !== null
            ? $this->holidays->findByYear($year)
            : $this->holidays->findAll();
        return array_map(fn(Holiday $h) => $this->serialize($h), $rows);
    }

    // -------------------------------------------------------------------------
    // Preview (no write)
    // -------------------------------------------------------------------------

    /**
     * Count of existing slot rows on this date. Drives the admin UI
     * confirmation dialog.
     *
     * In B1 the slots table is empty so this always returns 0 — but the
     * wiring is in place so the dialog works correctly the moment B2
     * starts populating slots.
     *
     * @return array{affectedSlots: int}
     */
    public function previewHoliday(string $isoDate): array {
        $this->assertIsoDate($isoDate);
        return ['affectedSlots' => $this->slotQuery->countByDate($isoDate)];
    }

    // -------------------------------------------------------------------------
    // Add (destructive overwrite)
    // -------------------------------------------------------------------------

    /**
     * Create a holiday row and overwrite every existing slot on that date
     * with the holiday representation (presence_type=holiday, source='holiday',
     * location_room_id=NULL). calendar_event_uid is preserved so B4 can
     * re-point any existing VEVENT cleanly.
     *
     * Rejects (409) if a holiday already exists for this date — the admin
     * UI should be calling update instead, but the constraint guards
     * against duplicate races.
     *
     * @return array{holiday: array<string, mixed>, affectedSlots: int}
     */
    public function addHoliday(string $isoDate, string $name): array {
        $this->assertIsoDate($isoDate);

        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('holiday name is required');
        }
        if (mb_strlen($name) > 128) {
            throw new \InvalidArgumentException('holiday name exceeds 128 characters');
        }

        if ($this->holidays->findByDate($isoDate) !== null) {
            throw new PresenceConflictException(
                'A holiday already exists for this date'
            );
        }

        $holidayTypeId = $this->resolveHolidayTypeId();

        // Insert the holiday row
        $h = new Holiday();
        $h->setHolidayDate($isoDate);
        $h->setName($name);
        $h->setCreatedAt(time());

        /** @var Holiday $saved */
        $saved = $this->holidays->insert($h);

        // Overwrite existing slots on this date with holiday values.
        $affected = $this->slotQuery->applyHolidayOverwriteByDate(
            $isoDate,
            $holidayTypeId,
            time()
        );

        // Also create holiday slots for active users who have no slot row yet
        // on this date. This ensures the holiday appears in their calendar view
        // even if the rolling-window cron hasn't run for this date yet.
        $created = $this->createMissingHolidaySlots($isoDate, $holidayTypeId);

        $this->logger->info(sprintf(
            '[TeamHub][PresenceHolidayService] addHoliday: %s "%s" — overwrote %d, created %d holiday slot(s)',
            $isoDate,
            $name,
            $affected,
            $created
        ));

        return [
            'holiday'        => $this->serialize($saved),
            'affectedSlots'  => $affected,
        ];
    }

    // -------------------------------------------------------------------------
    // Remove (recompute slots from template)
    // -------------------------------------------------------------------------

    /**
     * Delete a holiday row and recompute every source='holiday' slot on
     * that date back to its template-driven value.
     *
     * The recompute is delegated to PresenceTemplateService::recomputeSlotsForDate,
     * which is a stub in B1 (logs only) and gains the real implementation in B2.
     * No caller change is needed when B2 lands.
     */
    public function removeHoliday(int $id): void {
        $h = $this->holidays->findById($id);
        if ($h === null) {
            throw new DoesNotExistException("Holiday {$id} not found");
        }

        $date = $h->getHolidayDate();

        // Delete the holiday row first so the recompute (which runs against
        // the slot table, not the holidays table) sees consistent state.
        $this->holidays->delete($h);

        // Stub in B1, real in B2.
        $this->templateService->recomputeSlotsForDate($date);

        $this->logger->info(sprintf(
            '[TeamHub][PresenceHolidayService] removeHoliday: removed holiday on %s',
            $date
        ));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create source='holiday' slot rows for every active user who has no slot
     * on $isoDate. Active = has at least one template cell.
     *
     * We insert AM (half_day=0) and PM (half_day=1) for each missing user.
     * This ensures that when an admin adds a holiday, the date appears as
     * locked in every user's calendar view immediately, even before the
     * nightly cron fills the rolling window.
     *
     * @return int Number of slot rows created.
     */
    private function createMissingHolidaySlots(string $isoDate, int $holidayTypeId): int {
        $userIds = $this->templateMapper->getAllUserIds();
        $now     = time();
        $created = 0;

        foreach ($userIds as $userId) {
            foreach ([0, 1] as $halfDay) {
                $existing = $this->slotMapper->findSlot($userId, $isoDate, $halfDay);
                if ($existing !== null) {
                    continue; // Already handled by applyHolidayOverwriteByDate.
                }

                $slot = new PresenceSlot();
                $slot->setUserId($userId);
                $slot->setSlotDate($isoDate);
                $slot->setHalfDay($halfDay);
                $slot->setPresenceTypeId($holidayTypeId);
                $slot->setLocationRoomId(null);
                $slot->setSource('holiday');
                $slot->setCreatedAt($now);
                $slot->setUpdatedAt($now);

                try {
                    $this->slotMapper->insert($slot);
                    $created++;
                } catch (\Throwable $e) {
                    // Duplicate key — another process beat us to it. Non-fatal.
                    $this->logger->debug(sprintf(
                        '[TeamHub][PresenceHolidayService] createMissingHolidaySlots: '
                        . 'skipped %s/%d for %s (already exists)',
                        $isoDate, $halfDay, $userId
                    ));
                }
            }
        }

        return $created;
    }

    /**
     * Resolve the built-in 'holiday' presence type id, or throw if missing.
     * The repair step seeds this on every NC update so absence implies an
     * admin manually deleted the row from the DB — a state we can't repair
     * cleanly from here.
     */
    private function resolveHolidayTypeId(): int {
        $type = $this->types->findBySlug(self::HOLIDAY_TYPE_SLUG);
        if ($type === null) {
            $this->logger->error(
                '[TeamHub][PresenceHolidayService] Built-in "holiday" presence type '
                . 'is missing — was it manually deleted from teamhub_presence_types? '
                . 'Re-run "occ maintenance:repair" to re-seed it.'
            );
            throw new \RuntimeException(
                'Built-in "holiday" presence type is missing — '
                . 'run "occ maintenance:repair" to restore it'
            );
        }
        return $type->getId();
    }

    /**
     * Strict ISO-date validator. NC's request params arrive as strings; we
     * validate format here rather than at the controller because the
     * service is the authority for what it accepts.
     */
    private function assertIsoDate(string $isoDate): void {
        if (!preg_match(self::ISO_DATE_RE, $isoDate)) {
            throw new \InvalidArgumentException(
                "Date must be ISO YYYY-MM-DD, got: {$isoDate}"
            );
        }
        // checkdate disambiguates real-but-malformed values like 2026-02-30
        [$y, $m, $d] = array_map('intval', explode('-', $isoDate));
        if (!checkdate($m, $d, $y)) {
            throw new \InvalidArgumentException(
                "Date is not a valid calendar date: {$isoDate}"
            );
        }
    }

    /** @return array<string, mixed> */
    private function serialize(Holiday $h): array {
        return [
            'id'           => $h->getId(),
            'holiday_date' => $h->getHolidayDate(),
            'name'         => $h->getName(),
            'created_at'   => $h->getCreatedAt(),
        ];
    }
}
