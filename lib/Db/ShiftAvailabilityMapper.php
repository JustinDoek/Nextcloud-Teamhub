<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_shift_avail.
 *
 * Only the exceptions are stored, so this table stays small even on a large
 * instance: most people never say anything and are available by default.
 */
class ShiftAvailabilityMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_shift_avail', ShiftAvailability::class);
    }

    /**
     * One person's whole week.
     *
     * @return ShiftAvailability[]
     */
    public function findByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('day_of_week', 'ASC')
            ->addOrderBy('half_day', 'ASC');

        /** @var ShiftAvailability[] */
        return $this->findEntities($qb);
    }

    /**
     * Who, among these people, is unavailable on this half-day.
     *
     * The staffing picker's one query. It asks the narrow question — *who has
     * said no to Tuesday morning* — rather than reading everybody's week and
     * filtering in PHP, because the answer is almost always a short list and
     * the absent rows are the common case.
     *
     * @param list<string> $userIds
     * @return array<string, true> keyed by uid, for an O(1) lookup
     */
    public function findUnavailable(array $userIds, int $dayOfWeek, int $halfDay): array {
        if ($userIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id')
            ->from($this->getTableName())
            ->where($qb->expr()->in('user_id', $qb->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->eq('day_of_week', $qb->createNamedParameter($dayOfWeek, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('half_day', $qb->createNamedParameter($halfDay, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('available', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $out = [];
        while ($row = $result->fetch()) {
            $out[(string)$row['user_id']] = true;
        }
        $result->closeCursor();

        return $out;
    }

    /** Replace a person's week wholesale. The caller owns the transaction. */
    public function deleteByUser(string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $qb->executeStatement();
    }
}
