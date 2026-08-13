<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\PresenceSlot;
use OCA\TeamHub\Db\PresenceSlotMapper;
use OCA\TeamHub\Db\PresenceTypeMapper;
use OCA\TeamHub\Db\RoomMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * One-way presence → calendar propagation (B4).
 *
 * When a slot is created, updated, or deleted, TeamHub writes/updates/removes
 * a VEVENT in the user's default calendar. This drives NC 28+'s calendar-status
 * integration, which reads the user's default calendar and sets their
 * user_status dot accordingly.
 *
 * Design decisions (HANDOFF.md B4 + planning session):
 *
 *   UID scheme: `teamhub-presence-{userId}-{date}-{half}@teamhub`
 *     where half is "AM" or "PM". Stable across updates — we always know
 *     the UID from the slot data without storing it.
 *
 *   However, we also persist the UID in teamhub_presence_slots.calendar_event_uid
 *   so that holiday-overwrite and other server-side writes can re-point
 *   existing VEVENTs cleanly (B1 already preserves this column on overwrite).
 *
 *   Custom property: X-TEAMHUB-PRESENCE:1
 *     Secondary marker allowing us to recognise our events even if UID
 *     conventions ever change. We never touch events that lack this marker.
 *
 *   TRANSP:
 *     OPAQUE  (busy)        when presence_type.is_busy = 1
 *     TRANSPARENT (free)   when presence_type.is_busy = 0
 *
 *   Duration: exactly one half-day.
 *     Morning:   00:00 → 12:00 (local date, all-day-ish but timed)
 *     Afternoon: 12:00 → 24:00
 *   Using DTSTART/DTEND with DATE-TIME (UTC midnight of the date is NOT right
 *   because it shifts with the user's timezone). We use floating time
 *   (no TZID, no Z suffix) so the event sits at the right local slot regardless
 *   of timezone. This is the same approach NC Calendar uses for all-day events.
 *
 *   Calendar selection: the user's first calendar by ID (oldest = default).
 *   Excludes 'contact_birthdays'. Falls back gracefully if the user has no
 *   calendars — no write, no error.
 *
 *   Idempotency: syncSlot() checks whether an object with our UID already
 *   exists in the calendar using getCalendarObject(). If yes → update. If
 *   no → create. Delete is always by URI derived from UID.
 */
class PresenceCalendarService {

    /** Static suffix matching MeetingService's @teamhub convention */
    private const UID_SUFFIX = '@teamhub-presence';

    /** Custom property written on every VEVENT we create */
    private const MARKER_PROP = 'X-TEAMHUB-PRESENCE:1';

    /** Prodid */
    private const PRODID = '-//TeamHub//TeamHub Presence//EN';

    public function __construct(
        private PresenceSlotMapper  $slotMapper,
        private PresenceTypeMapper  $typeMapper,
        private RoomMapper          $roomMapper,
        private IDBConnection       $db,
        private ContainerInterface  $container,
        private TimezoneService     $timezoneService,
        private LoggerInterface     $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Public API — called from PresenceSlotService and PresenceMaterialisationService
    // -------------------------------------------------------------------------

    /**
     * Create or update the VEVENT for a slot.
     * Call this after inserting or updating a slot row.
     *
     * Returns the UID written (for storing in slot.calendar_event_uid),
     * or null if no calendar is available or propagation was skipped.
     */
    public function syncSlot(PresenceSlot $slot): ?string {
        $uid = $this->deriveUid($slot->getUserId(), $slot->getSlotDate(), $slot->getHalfDay());

        try {
            $calId = $this->findDefaultCalendarId($slot->getUserId());
            if ($calId === null) {
                $this->logger->debug(sprintf(
                    '[TeamHub][PresenceCalendarService] syncSlot: no calendar for %s — skipping',
                    $slot->getUserId()
                ));
                return null;
            }

            $ical   = $this->buildVEvent($slot, $uid);
            $objUri = $this->uidToUri($uid);
            $caldav = $this->getCaldav();

            $existing = $caldav->getCalendarObject($calId, $objUri);
            if ($existing !== null) {
                $caldav->updateCalendarObject($calId, $objUri, $ical);
                $this->logger->debug(sprintf(
                    '[TeamHub][PresenceCalendarService] updated %s in calendar %d',
                    $objUri, $calId
                ));
            } else {
                $caldav->createCalendarObject($calId, $objUri, $ical);
                $this->logger->debug(sprintf(
                    '[TeamHub][PresenceCalendarService] created %s in calendar %d',
                    $objUri, $calId
                ));
            }

            // Persist the UID back to the slot row if it wasn't already stored.
            if ($slot->getCalendarEventUid() !== $uid) {
                $slot->setCalendarEventUid($uid);
                $this->slotMapper->update($slot);
            }

            return $uid;
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                '[TeamHub][PresenceCalendarService] syncSlot failed for %s on %s half=%d: %s',
                $slot->getUserId(), $slot->getSlotDate(), $slot->getHalfDay(), $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * Delete the VEVENT for a slot, if it exists.
     * Call this before deleting a slot row.
     *
     * Accepts the slot object (preferred — uses stored calendar_event_uid if
     * available) or userId + date + half (fallback).
     */
    public function deleteSlotEvent(PresenceSlot $slot): void {
        // Prefer the stored UID; fall back to deriving it so we still find
        // events created before calendar_event_uid was stored.
        $uid = $slot->getCalendarEventUid()
            ?? $this->deriveUid($slot->getUserId(), $slot->getSlotDate(), $slot->getHalfDay());

        try {
            $calId = $this->findDefaultCalendarId($slot->getUserId());
            if ($calId === null) {
                return;
            }

            $caldav  = $this->getCaldav();
            $objUri  = $this->uidToUri($uid);
            $existing = $caldav->getCalendarObject($calId, $objUri);
            if ($existing !== null) {
                $caldav->deleteCalendarObject($calId, $objUri);
                $this->logger->debug(sprintf(
                    '[TeamHub][PresenceCalendarService] deleted %s from calendar %d',
                    $objUri, $calId
                ));
            }
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                '[TeamHub][PresenceCalendarService] deleteSlotEvent failed for %s on %s half=%d: %s',
                $slot->getUserId(), $slot->getSlotDate(), $slot->getHalfDay(), $e->getMessage()
            ));
        }
    }

    /**
     * Sync the calendar events for a single date's two slots (AM + PM).
     *
     * Called synchronously after an override so the calendar reflects the
     * new state immediately without waiting for the background job.
     * Handles the all-day merge/split logic for just that date.
     */
    public function syncSlotsForDate(string $userId, string $isoDate): void {
        $this->logger->debug(sprintf(
            '[TeamHub][PresenceCalendarService] syncSlotsForDate called: user=%s date=%s',
            $userId, $isoDate
        ));

        $calId = $this->findDefaultCalendarId($userId);
        if ($calId === null) {
            $this->logger->warning(sprintf(
                '[TeamHub][PresenceCalendarService] syncSlotsForDate: no calendar for %s — skip',
                $userId
            ));
            return;
        }

        $slots = $this->slotMapper->findByUserAndRange($userId, $isoDate, $isoDate);
        $this->logger->debug(sprintf(
            '[TeamHub][PresenceCalendarService] syncSlotsForDate: calId=%d slots=%d',
            $calId, count($slots)
        ));

        $caldav = $this->getCaldav();

        $am  = null;
        $pm  = null;
        foreach ($slots as $slot) {
            if ($slot->getHalfDay() === 0) $am = $slot;
            else                            $pm = $slot;
        }

        $allDayUid = $this->deriveUid($userId, $isoDate, -1);
        $amUid     = $this->deriveUid($userId, $isoDate, 0);
        $pmUid     = $this->deriveUid($userId, $isoDate, 1);

        $sameType = $am !== null && $pm !== null
            && $am->getPresenceTypeId() === $pm->getPresenceTypeId()
            && $am->getPresenceTypeId() !== null;

        if ($sameType) {
            // Delete any half-day events and write one all-day event.
            $this->deleteByUid($caldav, $calId, $amUid);
            $this->deleteByUid($caldav, $calId, $pmUid);
            $ical = $this->buildAllDayVEvent($am, $allDayUid, $isoDate);
            $this->upsertByUid($caldav, $calId, $allDayUid, $ical);
            foreach (array_filter([$am, $pm]) as $s) {
                if ($s->getCalendarEventUid() !== $allDayUid) {
                    $s->setCalendarEventUid($allDayUid);
                    $this->slotMapper->update($s);
                }
            }
        } else {
            // Delete any all-day event and write up to two half-day events.
            $this->deleteByUid($caldav, $calId, $allDayUid);
            foreach ([$am, $pm] as $half => $slot) {
                if ($slot === null) continue;
                $uid  = $this->deriveUid($userId, $isoDate, $slot->getHalfDay());
                $ical = $this->buildVEvent($slot, $uid);
                $this->upsertByUid($caldav, $calId, $uid, $ical);
                if ($slot->getCalendarEventUid() !== $uid) {
                    $slot->setCalendarEventUid($uid);
                    $this->slotMapper->update($slot);
                }
            }
            // If one half is now null (cleared), delete its calendar event.
            if ($am === null) $this->deleteByUid($caldav, $calId, $amUid);
            if ($pm === null) $this->deleteByUid($caldav, $calId, $pmUid);
        }

        $this->logger->debug(sprintf(
            '[TeamHub][PresenceCalendarService] syncSlotsForDate %s on %s: am=%s pm=%s sameType=%s',
            $userId, $isoDate,
            $am?->getPresenceTypeId() ?? 'null',
            $pm?->getPresenceTypeId() ?? 'null',
            $sameType ? 'yes' : 'no'
        ));
    }

    /**
     *
     * Merging rule: if both Morning and Afternoon on a date have the same
     * presence_type_id, emit a single all-day VEVENT (DTSTART;VALUE=DATE)
     * with UID …-ALLDAY@… and delete any existing half-day events for that date.
     * If they differ, emit two half-day events and delete any existing all-day event.
     *
     * IMPORTANT: We look up existing calendar objects by UID in oc_calendarobjects
     * directly, NOT by URI via CalDavBackend::getCalendarObject(). The reason:
     * CalDAV enforces a unique constraint on (calendarid, uid). If a previous event
     * was stored with a truncated or different URI, getCalendarObject returns null
     * (URI not found) but createCalendarObject then fails with a duplicate-key error
     * because the UID is already in the table under a different URI.
     * Looking up by UID ensures we always find the row and update rather than create.
     */
    public function syncAllSlotsForUser(string $userId): void {
        // The user's today, not the server's — slot_date is a floating local
        // date, so a UTC "today" would drop or duplicate the boundary day for
        // anyone whose offset has already rolled them over.
        $today   = $this->timezoneService->today($userId);
        $endYear = sprintf('%04d-12-31', (int)substr($today, 0, 4) + 1);

        $slots = $this->slotMapper->findByUserAndRange($userId, $today, $endYear);
        if (count($slots) === 0) {
            return;
        }

        $calId = $this->findDefaultCalendarId($userId);
        if ($calId === null) {
            return;
        }

        $caldav = $this->getCaldav();

        // Group slots by date.
        $byDate = [];
        foreach ($slots as $slot) {
            $byDate[$slot->getSlotDate()][$slot->getHalfDay()] = $slot;
        }

        $count = 0;
        foreach ($byDate as $date => $halves) {
            $am  = $halves[0] ?? null;
            $pm  = $halves[1] ?? null;
            $allDayUid = $this->deriveUid($userId, $date, -1);
            $amUid     = $this->deriveUid($userId, $date, 0);
            $pmUid     = $this->deriveUid($userId, $date, 1);

            $sameType = $am !== null && $pm !== null
                && $am->getPresenceTypeId() === $pm->getPresenceTypeId()
                && $am->getPresenceTypeId() !== null;

            try {
                if ($sameType) {
                    // Delete any existing half-day events by UID.
                    foreach ([$amUid, $pmUid] as $halfUid) {
                        $this->deleteByUid($caldav, $calId, $halfUid);
                    }
                    // Upsert the all-day event.
                    $ical = $this->buildAllDayVEvent($am, $allDayUid, $date);
                    $this->upsertByUid($caldav, $calId, $allDayUid, $ical);
                    // Store allDayUid in both slots.
                    foreach (array_filter([$am, $pm]) as $s) {
                        if ($s->getCalendarEventUid() !== $allDayUid) {
                            $s->setCalendarEventUid($allDayUid);
                            $this->slotMapper->update($s);
                        }
                    }
                    $count++;
                } else {
                    // Delete any existing all-day event by UID.
                    $this->deleteByUid($caldav, $calId, $allDayUid);
                    // Upsert each half-day event.
                    foreach ($halves as $half => $slot) {
                        $uid  = $this->deriveUid($userId, $date, $half);
                        $ical = $this->buildVEvent($slot, $uid);
                        $this->upsertByUid($caldav, $calId, $uid, $ical);
                        if ($slot->getCalendarEventUid() !== $uid) {
                            $slot->setCalendarEventUid($uid);
                            $this->slotMapper->update($slot);
                        }
                        $count++;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    '[TeamHub][PresenceCalendarService] syncAllSlotsForUser: date %s failed: %s',
                    $date, $e->getMessage()
                ));
            }
        }

        $this->logger->info(sprintf(
            '[TeamHub][PresenceCalendarService] syncAllSlotsForUser %s: synced %d event(s)',
            $userId, $count
        ));
    }

    /**
     * Upsert a calendar object identified by UID.
     * Looks up by UID in oc_calendarobjects to avoid the duplicate-key error
     * that occurs when a previous sync stored the event under a different URI.
     */
    private function upsertByUid(\OCA\DAV\CalDAV\CalDavBackend $caldav, int $calId, string $uid, string $ical): void {
        // First purge any stale soft-deleted rows for this UID. NC marks deleted
        // objects by appending '-deleted' to the URI; these rows still hold the
        // UID and cause duplicate-key errors on createCalendarObject.
        $this->purgeDeletedRowsForUid($calId, $uid);

        $existingUri = $this->findUriByUid($calId, $uid);
        $targetUri   = $this->uidToUri($uid);

        if ($existingUri !== null) {
            if ($existingUri !== $targetUri) {
                $this->logger->debug(sprintf(
                    '[TeamHub][PresenceCalendarService] upsertByUid: delete old URI %s, create %s',
                    $existingUri, $targetUri
                ));
                $caldav->deleteCalendarObject($calId, $existingUri);
                $caldav->createCalendarObject($calId, $targetUri, $ical);
            } else {
                $this->logger->debug('[TeamHub][PresenceCalendarService] upsertByUid: update ' . $targetUri);
                $caldav->updateCalendarObject($calId, $targetUri, $ical);
            }
        } else {
            $this->logger->debug('[TeamHub][PresenceCalendarService] upsertByUid: create ' . $targetUri);
            $caldav->createCalendarObject($calId, $targetUri, $ical);
        }
    }

    /**
     * Hard-delete any soft-deleted calendar object rows for this UID.
     *
     * NC's CalDAV stack soft-deletes objects by appending '-deleted' to the URI
     * and (in NC 28+) setting a deleted_at timestamp. These rows still carry the
     * original UID and block createCalendarObject with a unique-constraint error.
     *
     * We bypass CalDavBackend here intentionally — it would just append another
     * '-deleted' suffix. We go directly to the DB to remove stale rows that are
     * already past the point of recovery.
     */
    private function purgeDeletedRowsForUid(int $calId, string $uid): void {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('calendarobjects')
                ->where($qb->expr()->eq(
                    'calendarid',
                    $qb->createNamedParameter($calId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                ))
                ->andWhere($qb->expr()->eq(
                    'uid',
                    $qb->createNamedParameter($uid)
                ))
                ->andWhere($qb->expr()->like(
                    'uri',
                    $qb->createNamedParameter('%-deleted%')
                ));
            $deleted = $qb->executeStatement();
            if ($deleted > 0) {
                $this->logger->debug(sprintf(
                    '[TeamHub][PresenceCalendarService] purgeDeletedRowsForUid: removed %d stale row(s) for %s',
                    $deleted, $uid
                ));
            }
        } catch (\Throwable $e) {
            // Non-fatal — log and continue. The upsert may still succeed if the
            // stale rows don't actually block the write.
            $this->logger->warning(sprintf(
                '[TeamHub][PresenceCalendarService] purgeDeletedRowsForUid failed for %s: %s',
                $uid, $e->getMessage()
            ));
        }
    }

    /**
     * Delete a calendar object by UID, regardless of what URI it was stored under.
     */
    private function deleteByUid(\OCA\DAV\CalDAV\CalDavBackend $caldav, int $calId, string $uid): void {
        $uri = $this->findUriByUid($calId, $uid);
        if ($uri !== null) {
            try {
                $caldav->deleteCalendarObject($calId, $uri);
            } catch (\Throwable $e) {
                $this->logger->debug(sprintf(
                    '[TeamHub][PresenceCalendarService] deleteByUid: could not delete %s: %s',
                    $uid, $e->getMessage()
                ));
            }
        }
    }

    /**
     * Look up the stored URI (filename) for a calendar object by its UID.
     * Returns null if no matching row exists in oc_calendarobjects.
     *
     * We query oc_calendarobjects directly because CalDavBackend::getCalendarObject
     * looks up by URI, not by UID — the two can diverge when the event was created
     * under a different URI (e.g. after a code change or a failed previous sync).
     */
    private function findUriByUid(int $calId, string $uid): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('uri')
            ->from('calendarobjects')
            ->where($qb->expr()->eq(
                'calendarid',
                $qb->createNamedParameter($calId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
            ))
            ->andWhere($qb->expr()->eq(
                'uid',
                $qb->createNamedParameter($uid)
            ))
            // Exclude soft-deleted objects — NC marks deleted objects by appending
            // '-deleted' to the URI. Without this filter, upsertByUid finds the
            // deleted row, sees URI mismatch, deletes it again (appending another
            // '-deleted'), and the URI grows on every sync call.
            ->andWhere($qb->expr()->notLike(
                'uri',
                $qb->createNamedParameter('%-deleted%')
            ))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row !== false ? (string)$row['uri'] : null;
    }

    // -------------------------------------------------------------------------
    // VEVENT construction
    // -------------------------------------------------------------------------

    private function buildVEvent(PresenceSlot $slot, string $uid): string {
        $typeId  = $slot->getPresenceTypeId();
        $type    = $typeId !== null ? $this->typeMapper->findById($typeId) : null;

        $summary  = $type?->getLabel() ?? 'Presence';
        $transp   = ($type !== null && $type->getIsBusy() === 1) ? 'OPAQUE' : 'TRANSPARENT';
        $location = $this->resolveLocationName($slot->getLocationRoomId());

        [$dtStart, $dtEnd] = $this->halfDayTimes($slot->getSlotDate(), $slot->getHalfDay());

        // DTSTAMP is the creation time in UTC.
        $dtstamp = gmdate('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . self::PRODID,
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $dtstamp,
            'DTSTART:' . $dtStart,
            'DTEND:' . $dtEnd,
            'SUMMARY:' . $this->escapeText($summary . ($location !== null ? ' — ' . $location : '')),
            'TRANSP:' . $transp,
            self::MARKER_PROP,
        ];

        if ($location !== null) {
            $lines[] = 'LOCATION:' . $this->escapeText($location);
        }

        // STATUS mirrors TRANSP: CONFIRMED when busy, TENTATIVE when free.
        $lines[] = 'STATUS:' . ($transp === 'OPAQUE' ? 'CONFIRMED' : 'TENTATIVE');

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        // iCal spec requires CRLF line endings.
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Returns [DTSTART, DTEND] as floating local time strings (yyyymmddThhmmss).
     * Morning: 00:00–12:00, Afternoon: 12:00–24:00 (represented as next day 00:00).
     *
     * Floating time means the event sits at the right local time regardless of
     * the user's timezone — the same approach NC Calendar uses for all-day events.
     * NC's calendar-status integration reads TRANSP, not the time itself.
     */
    private function halfDayTimes(string $isoDate, int $halfDay): array {
        [$y, $m, $d] = array_map('intval', explode('-', $isoDate));

        if ($halfDay === 0) {
            // Morning: 00:00 → 12:00 same day
            $start = sprintf('%04d%02d%02dT000000', $y, $m, $d);
            $end   = sprintf('%04d%02d%02dT120000', $y, $m, $d);
        } else {
            // Afternoon: 12:00 → 24:00 (= next day 00:00)
            $start    = sprintf('%04d%02d%02dT120000', $y, $m, $d);
            $nextDay  = new \DateTimeImmutable("{$isoDate} 12:00:00");
            $nextDay  = $nextDay->modify('+1 day');
            $end      = $nextDay->format('Ymd') . 'T000000';
        }

        return [$start, $end];
    }

    // -------------------------------------------------------------------------
    // Calendar lookup
    // -------------------------------------------------------------------------

    /**
     * Returns the calendar ID of the user's default calendar, or null.
     *
     * "Default" = the user's first calendar by id (oldest, excluding
     * contact_birthdays and soft-deleted calendars). Mirrors the approach
     * NC Calendar uses to determine which calendar to write new events to.
     */
    private function findDefaultCalendarId(string $userId): ?int {
        $principalUri = 'principals/users/' . $userId;
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('calendars')
            ->where($qb->expr()->eq(
                'principaluri',
                $qb->createNamedParameter($principalUri)
            ))
            ->andWhere($qb->expr()->neq(
                'uri',
                $qb->createNamedParameter('contact_birthdays')
            ));

        // Exclude soft-deleted calendars where the column exists.
        try {
            $qb->andWhere($qb->expr()->isNull('deleted_at'));
        } catch (\Throwable) {
            // Column may not exist on older NC versions — proceed without.
        }

        $qb->orderBy('id', 'ASC')
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        $calId = $row !== false ? (int)$row['id'] : null;
        $this->logger->debug(sprintf(
            '[TeamHub][PresenceCalendarService] findDefaultCalendarId %s → %s',
            $userId, $calId ?? 'null (no calendar found)'
        ));
        return $calId;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Derive the stable UID for a slot.
     * half = 0 → AM, 1 → PM, -1 → ALLDAY (merged same-type event).
     */
    private function deriveUid(string $userId, string $date, int $half): string {
        $suffix   = match($half) { 0 => 'AM', 1 => 'PM', default => 'ALLDAY' };
        $safeUser = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $userId) ?? $userId;
        return sprintf(
            'teamhub-presence-%s-%s-%s%s',
            $safeUser,
            str_replace('-', '', $date),
            $suffix,
            self::UID_SUFFIX
        );
    }

    /**
     * Build an all-day VEVENT covering a full calendar date.
     * Used when Morning and Afternoon have the same presence type.
     * DTSTART;VALUE=DATE and DTEND;VALUE=DATE (next day) per RFC 5545.
     */
    private function buildAllDayVEvent(PresenceSlot $slot, string $uid, string $isoDate): string {
        $typeId   = $slot->getPresenceTypeId();
        $type     = $typeId !== null ? $this->typeMapper->findById($typeId) : null;
        $summary  = $type?->getLabel() ?? 'Presence';
        $transp   = ($type !== null && $type->getIsBusy() === 1) ? 'OPAQUE' : 'TRANSPARENT';
        $location = $this->resolveLocationName($slot->getLocationRoomId());

        [$y, $m, $d] = array_map('intval', explode('-', $isoDate));
        $dtStart  = sprintf('%04d%02d%02d', $y, $m, $d);
        $nextDay  = (new \DateTimeImmutable($isoDate))->modify('+1 day');
        $dtEnd    = $nextDay->format('Ymd');
        $dtstamp  = gmdate('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . self::PRODID,
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $dtstamp,
            'DTSTART;VALUE=DATE:' . $dtStart,
            'DTEND;VALUE=DATE:' . $dtEnd,
            'SUMMARY:' . $this->escapeText($summary . ($location !== null ? ' — ' . $location : '')),
            'TRANSP:' . $transp,
            'STATUS:' . ($transp === 'OPAQUE' ? 'CONFIRMED' : 'TENTATIVE'),
            self::MARKER_PROP,
        ];

        if ($location !== null) {
            $lines[] = 'LOCATION:' . $this->escapeText($location);
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    /** Convert a UID to the .ics object filename. */
    private function uidToUri(string $uid): string {
        return strtolower($uid) . '.ics';
    }

    /** Resolve a room id to a displayable location string, or null. */
    private function resolveLocationName(?int $roomId): ?string {
        if ($roomId === null) {
            return null;
        }
        try {
            $room = $this->roomMapper->findById($roomId);
            return $room?->getName();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Escape text for use in iCal property values.
     * Per RFC 5545: backslash, semicolon, comma, newline must be escaped.
     */
    private function escapeText(string $text): string {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace(';', '\\;', $text);
        $text = str_replace(',', '\\,', $text);
        $text = str_replace(["\r\n", "\n", "\r"], '\\n', $text);
        return $text;
    }

    /** Lazy-load CalDavBackend via the container — avoids circular DI at boot. */
    private function getCaldav(): \OCA\DAV\CalDAV\CalDavBackend {
        // Container injection matches the pattern in CalendarService and MeetingService.
        return $this->container->get(\OCA\DAV\CalDAV\CalDavBackend::class);
    }
}
