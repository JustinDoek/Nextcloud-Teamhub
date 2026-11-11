<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_roster_assign.
 *
 * A double-claim on the last open place of a shift is a race, and a race is
 * settled by the unique index `th_rassign_uq` or not at all — the service
 * catches the constraint violation rather than asking first and hoping.
 */
class RosterAssignmentMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_roster_assign', RosterAssignment::class);
    }

    /**
     * Assignments for a set of shift ids, keyed by shift id.
     *
     * One query for a whole week's grid rather than one per shift: a fortnight
     * of a three-shift team is 42 shifts, and 42 round trips to draw one
     * screen is the N+1 the roster would be reported for.
     *
     * @param list<int> $shiftIds
     * @return array<int, RosterAssignment[]>
     */
    public function findByShiftIds(array $shiftIds): array {
        if ($shiftIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'shift_id',
                $qb->createNamedParameter($shiftIds, IQueryBuilder::PARAM_INT_ARRAY),
            ))
            ->orderBy('id', 'ASC');

        $out = [];
        /** @var RosterAssignment $row */
        foreach ($this->findEntities($qb) as $row) {
            $out[$row->getShiftId()][] = $row;
        }
        return $out;
    }

    /** @return RosterAssignment[] */
    public function findByShift(int $shiftId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('shift_id', $qb->createNamedParameter($shiftId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC');

        /** @var RosterAssignment[] */
        return $this->findEntities($qb);
    }

    public function findOne(int $shiftId, string $userId): ?RosterAssignment {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('shift_id', $qb->createNamedParameter($shiftId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->setMaxResults(1);

        try {
            /** @var RosterAssignment */
            return $this->findEntity($qb);
        } catch (\Throwable) {
            return null;
        }
    }

    public function deleteByShift(int $shiftId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('shift_id', $qb->createNamedParameter($shiftId, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
    }

    /**
     * Every assignment a user holds on published shifts in a date range.
     *
     * The presence write-through reads this. Joined rather than two queries
     * because the date lives on the shift and the person on the assignment,
     * and the caller needs them together.
     *
     * @return list<array{shift_id: int, shift_date: string, shift_type_id: int, room_id: ?int}>
     */
    public function findPublishedForUserInRange(string $userId, string $fromDate, string $toDate): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('a.shift_id', 's.shift_date', 's.shift_type_id', 's.room_id')
            ->from($this->getTableName(), 'a')
            ->innerJoin('a', 'teamhub_roster_shift', 's', $qb->expr()->eq('a.shift_id', 's.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gte('s.shift_date', $qb->createNamedParameter($fromDate)))
            ->andWhere($qb->expr()->lte('s.shift_date', $qb->createNamedParameter($toDate)))
            ->andWhere($qb->expr()->gt('s.published_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->orderBy('s.shift_date', 'ASC');

        $result = $qb->executeQuery();
        $out = [];
        while ($row = $result->fetch()) {
            $out[] = [
                'shift_id'      => (int)$row['shift_id'],
                'shift_date'    => (string)$row['shift_date'],
                'shift_type_id' => (int)$row['shift_type_id'],
                'room_id'       => $row['room_id'] === null ? null : (int)$row['room_id'],
            ];
        }
        $result->closeCursor();

        return $out;
    }
}
