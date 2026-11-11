<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_roster_cal (DESIGN §2.250).
 *
 * @extends QBMapper<RosterCalendarCopy>
 */
class RosterCalendarCopyMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_roster_cal', RosterCalendarCopy::class);
    }

    public function findOne(int $shiftId, string $userId): ?RosterCalendarCopy {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('shift_id', $qb->createNamedParameter($shiftId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->setMaxResults(1);

        try {
            /** @var RosterCalendarCopy */
            return $this->findEntity($qb);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return RosterCalendarCopy[] every copy of one shift, for each person on it */
    public function findByShift(int $shiftId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('shift_id', $qb->createNamedParameter($shiftId, IQueryBuilder::PARAM_INT)));

        /** @var RosterCalendarCopy[] */
        return $this->findEntities($qb);
    }

    /**
     * One person's copies among a set of shifts, keyed by shift id. The week
     * dialog asks this to say which shifts are already in the calendar.
     *
     * @param list<int> $shiftIds
     * @return array<int, RosterCalendarCopy>
     */
    public function findForUserAndShifts(string $userId, array $shiftIds): array {
        if ($shiftIds === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in(
                'shift_id',
                $qb->createNamedParameter($shiftIds, IQueryBuilder::PARAM_INT_ARRAY),
            ));

        $out = [];
        /** @var RosterCalendarCopy $row */
        foreach ($this->findEntities($qb) as $row) {
            $out[$row->getShiftId()] = $row;
        }
        return $out;
    }

    /** @return RosterCalendarCopy[] */
    public function findByTeam(string $teamId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));

        /** @var RosterCalendarCopy[] */
        return $this->findEntities($qb);
    }
}
