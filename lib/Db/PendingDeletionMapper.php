<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_pending_dels.
 *
 * All queries are intentionally narrow — this table is a coordination point
 * that must never be slow regardless of how many archived teams exist.
 */
class PendingDeletionMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_pending_dels', PendingDeletion::class);
    }

    /**
     * Find the pending-deletion row for a team, or return null.
     */
    public function findByTeamId(string $teamId): ?PendingDeletion {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1);

        try {
            /** @var PendingDeletion */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Returns true if this team has a 'pending' row — used to gate writes.
     */
    public function isTeamPendingDeletion(string $teamId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')));

        $result = $qb->executeQuery();
        $count  = (int)$result->fetchOne();
        $result->closeCursor();
        return $count > 0;
    }

    /**
     * Returns all rows where status='pending' AND hard_delete_at <= $now.
     * Used by PendingDeletionJob to find teams due for hard-delete.
     *
     * @return PendingDeletion[]
     */
    public function findDueForHardDelete(int $now): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
            ->andWhere($qb->expr()->lte(
                'hard_delete_at',
                $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)
            ))
            ->orderBy('hard_delete_at', 'ASC');

        /** @var PendingDeletion[] */
        return $this->findEntities($qb);
    }

    /**
     * Returns all rows, most-recently-archived first.
     * Used by the admin archived-teams list.
     *
     * @return PendingDeletion[]
     */
    public function listAll(int $limit = 50, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('archived_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        /** @var PendingDeletion[] */
        return $this->findEntities($qb);
    }

    /**
     * Total count of all rows (for admin pagination).
     */
    public function countAll(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName());
        $result = $qb->executeQuery();
        $count  = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * Delete the pending-deletion row for a team.
     * Used on restore: removes the marker so the team becomes visible again.
     */
    public function deleteByTeamId(string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
        $qb->executeStatement();
    }
}
