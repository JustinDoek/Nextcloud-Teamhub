<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_presence_slots.
 *
 * One row per (user_id, slot_date, half_day). The unique index th_pslot_uniq
 * enforces this at the DB level. Upsert logic (find-then-insert-or-update)
 * lives in PresenceMaterialisationService and PresenceSlotService.
 */
class PresenceSlotMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_presence_slots', PresenceSlot::class);
    }

    /**
     * Find a specific slot for a user, or null if absent.
     */
    public function findSlot(string $userId, string $slotDate, int $halfDay): ?PresenceSlot {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id',   $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('slot_date', $qb->createNamedParameter($slotDate)))
            ->andWhere($qb->expr()->eq('half_day',  $qb->createNamedParameter($halfDay, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        try {
            /** @var PresenceSlot */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Return all slots for a user within an inclusive ISO date range.
     * Ordered by slot_date, half_day for chronological display.
     *
     * @return PresenceSlot[]
     */
    public function findByUserAndRange(string $userId, string $fromDate, string $toDate): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id',   $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gte('slot_date', $qb->createNamedParameter($fromDate)))
            ->andWhere($qb->expr()->lte('slot_date', $qb->createNamedParameter($toDate)))
            ->orderBy('slot_date', 'ASC')
            ->addOrderBy('half_day', 'ASC');

        /** @var PresenceSlot[] */
        return $this->findEntities($qb);
    }

    /**
     * Return all source='holiday' slots on a given date across all users.
     * Used by PresenceTemplateService::recomputeSlotsForDate after holiday removal.
     *
     * @return PresenceSlot[]
     */
    public function findHolidaySlotsOnDate(string $slotDate): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('slot_date', $qb->createNamedParameter($slotDate)))
            ->andWhere($qb->expr()->eq('source',    $qb->createNamedParameter('holiday')));

        /** @var PresenceSlot[] */
        return $this->findEntities($qb);
    }

    /**
     * Check whether a user already has a slot materialised for the given date.
     * Used by the materialisation service to skip already-filled dates.
     *
     * @param string[] $dates ISO date strings
     */
    public function findExistingDatesForUser(string $userId, array $dates): array {
        if (count($dates) === 0) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('slot_date')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in(
                'slot_date',
                $qb->createNamedParameter($dates, IQueryBuilder::PARAM_STR_ARRAY)
            ));

        $result = $qb->executeQuery();
        $found  = [];
        while ($row = $result->fetch()) {
            $found[] = $row['slot_date'];
        }
        $result->closeCursor();
        return $found;
    }

    /**
     * Delete all template-sourced slots for a user on or after a given date.
     * Called before re-materialising after a template save, so stale
     * template-derived slots don't linger. Override and holiday slots are
     * intentionally preserved — source='template' only.
     */
    public function deleteTemplateSlotsByUserFromDate(string $userId, string $fromDate): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id',   $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('source',    $qb->createNamedParameter('template')))
            ->andWhere($qb->expr()->gte('slot_date', $qb->createNamedParameter($fromDate)));
        $qb->executeStatement();
    }
}
