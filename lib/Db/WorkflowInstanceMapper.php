<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `teamhub_wf_instance` (WorkflowHub phase 1, v4.10.13).
 *
 * Column names as `Version000410013Date20260921000000` wrote them. Only
 * `WorkflowEngine` writes through this class.
 *
 * @extends QBMapper<WorkflowInstance>
 */
class WorkflowInstanceMapper extends QBMapper {

    public const TABLE = 'teamhub_wf_instance';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, WorkflowInstance::class);
    }

    public function findById(int $id): ?WorkflowInstance {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Instances by id, most recently updated first, optionally narrowed to
     * one status.
     *
     * @param int[] $ids
     * @return WorkflowInstance[]
     */
    public function findByIds(array $ids, ?string $status = null): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('updated_at', 'DESC')
            ->addOrderBy('id', 'DESC');
        if ($status !== null) {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
        }
        return $this->findEntities($qb);
    }

    /**
     * Remove one instance row (WorkflowHub phase 4, v4.10.16 — the
     * unlicensed purge). Idempotent: returns the number of rows removed,
     * 0 when the row was already gone. The child tables are cleared by
     * their own mappers first; `WorkflowEngine::purge()` is the one caller
     * and does all four inside one transaction.
     */
    public function deleteById(int $id): int {
        $qb = $this->db->getQueryBuilder();
        return $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * Open instances of one definition on one team — the concurrency check.
     *
     * @param string[] $openStatuses
     * @return WorkflowInstance[]
     */
    public function findOpenForDefinitionAndTeam(string $definitionKey, string $teamId, array $openStatuses): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('definition_key', $qb->createNamedParameter($definitionKey)))
            ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->in('status', $qb->createNamedParameter($openStatuses, IQueryBuilder::PARAM_STR_ARRAY)));
        return $this->findEntities($qb);
    }
}
