<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Narrow query helper against teamhub_presence_slots.
 *
 * In B1 there is no full mapper or entity for presence slots — no surface
 * reads or writes whole rows yet (that lands in B2/B3 when the user-side
 * UI starts populating the table). What B1 *does* need is two operations
 * against the table from the holiday flow:
 *
 *   - countByDate($date)
 *       Holiday-preview endpoint. Returns "how many existing slot rows fall
 *       on this date" so the confirmation dialog can show
 *       "this will overwrite N user entries".
 *
 *   - applyHolidayOverwriteByDate($date, $holidayTypeId)
 *       Holiday-add endpoint. Sets every existing slot on this date to
 *       (presence_type_id=$holidayTypeId, source='holiday', location_room_id=null)
 *       and bumps updated_at. The calendar_event_uid column is left in
 *       place so B4's calendar-propagation update logic can re-point the
 *       VEVENT correctly on next propagation rather than orphaning it.
 *
 *   - countByDates($dates)
 *       Bulk count for a set of dates (used internally by service-layer
 *       referential-integrity checks: "is this presence type / this room
 *       referenced by any slot?").
 *
 *   - countByPresenceType($typeId)
 *       "Does any active template or slot row reference this type id?"
 *       Drives the 409 returned from PresenceTypeService::deleteType when
 *       the type is in use. In B1 the slots table is empty so this returns
 *       0 in practice, but the wiring is in place.
 *
 *   - countByRoomId($roomId)
 *       Same idea for room ids — used by the location-delete flow.
 *
 * Once B2 lands and the slots table starts being populated, a full
 * PresenceSlot entity + PresenceSlotMapper will replace this helper, with
 * these methods retained as convenience.
 */
class PresenceSlotQueryMapper {

    private const TABLE = 'teamhub_presence_slots';

    public function __construct(private IDBConnection $db) {}

    /**
     * Count of slot rows on a given ISO YYYY-MM-DD date.
     * Used by the holiday-preview endpoint.
     */
    public function countByDate(string $isoDate): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from(self::TABLE)
            ->where($qb->expr()->eq(
                'slot_date',
                $qb->createNamedParameter($isoDate)
            ));

        $result = $qb->executeQuery();
        $cnt    = (int)$result->fetchOne();
        $result->closeCursor();
        return $cnt;
    }

    /**
     * Set every slot on the given date to a "holiday" representation:
     *   presence_type_id = $holidayTypeId
     *   source           = 'holiday'
     *   location_room_id = NULL
     *   updated_at       = $now
     *
     * Returns the number of rows affected.
     *
     * The calendar_event_uid column is deliberately NOT cleared — B4's
     * propagation logic uses it to re-point an existing VEVENT in the user's
     * calendar rather than creating a new one, which would leave the old
     * VEVENT orphaned.
     */
    public function applyHolidayOverwriteByDate(
        string $isoDate,
        int $holidayTypeId,
        int $now,
    ): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('presence_type_id', $qb->createNamedParameter(
                $holidayTypeId, IQueryBuilder::PARAM_INT
            ))
            ->set('source', $qb->createNamedParameter('holiday'))
            ->set('location_room_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq(
                'slot_date',
                $qb->createNamedParameter($isoDate)
            ));

        return $qb->executeStatement();
    }

    /**
     * Count of slot rows that reference a particular presence_type_id.
     * Returns 0 in B1 (slots table empty) but the gate is wired in so
     * PresenceTypeService::deleteType behaves correctly in B2+.
     */
    public function countByPresenceType(int $typeId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from(self::TABLE)
            ->where($qb->expr()->eq(
                'presence_type_id',
                $qb->createNamedParameter($typeId, IQueryBuilder::PARAM_INT)
            ));

        $result = $qb->executeQuery();
        $cnt    = (int)$result->fetchOne();
        $result->closeCursor();
        return $cnt;
    }

    /**
     * Count of slot rows that reference any of the given room ids.
     * Used by location-delete to reject deleting a building/floor/room
     * whose subtree contains rooms still referenced by slot rows.
     *
     * @param int[] $roomIds
     */
    public function countByRoomIds(array $roomIds): int {
        if (count($roomIds) === 0) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from(self::TABLE)
            ->where($qb->expr()->in(
                'location_room_id',
                $qb->createNamedParameter($roomIds, IQueryBuilder::PARAM_INT_ARRAY)
            ));

        $result = $qb->executeQuery();
        $cnt    = (int)$result->fetchOne();
        $result->closeCursor();
        return $cnt;
    }
}
