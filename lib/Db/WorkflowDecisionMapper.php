<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `teamhub_wf_decision` (v4.11.12) — the decisions linked to a
 * service request.
 *
 * No update path: a link is made or removed, never edited. The reads are
 * "the decisions of this request" (the detail) and "the decisions of these
 * requests" (a page of the desk's grid, one query for twenty-five rows
 * rather than twenty-five queries).
 *
 * Like `WorkflowAttachmentMapper`, the reads are deliberately unfiltered:
 * whether the person looking may open a linked decision is answered by the
 * Decisions module against that person, and the one place that question is
 * answered must stay the one place.
 *
 * @extends QBMapper<WorkflowDecision>
 */
class WorkflowDecisionMapper extends QBMapper {

    public const TABLE = 'teamhub_wf_decision';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, WorkflowDecision::class);
    }

    /**
     * Every decision linked to one request, oldest link first.
     *
     * @return WorkflowDecision[]
     */
    public function findByInstance(int $instanceId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('instance_id', $qb->createNamedParameter($instanceId, IQueryBuilder::PARAM_INT)))
            ->orderBy('added_at', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * The links of many requests in one query, keyed by instance — what a
     * page of grid rows needs instead of a query per row.
     *
     * @param int[] $instanceIds
     * @return array<int, WorkflowDecision[]>
     */
    public function findByInstances(array $instanceIds): array {
        $instanceIds = array_values(array_unique(array_map('intval', $instanceIds)));
        if ($instanceIds === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('instance_id', $qb->createNamedParameter($instanceIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('added_at', 'ASC')
            ->addOrderBy('id', 'ASC');
        $out = [];
        foreach ($this->findEntities($qb) as $row) {
            $out[$row->getInstanceId()][] = $row;
        }
        return $out;
    }

    /** The one link between a request and a decision, or null. */
    public function findLink(int $instanceId, int $decisionId): ?WorkflowDecision {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('instance_id', $qb->createNamedParameter($instanceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('decision_id', $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function deleteLink(int $instanceId, int $decisionId): int {
        $qb = $this->db->getQueryBuilder();
        return $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('instance_id', $qb->createNamedParameter($instanceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('decision_id', $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    /** Part of `WorkflowEngine::purge()`: an unlicensed ending takes the links with it. */
    public function deleteByInstance(int $instanceId): int {
        $qb = $this->db->getQueryBuilder();
        return $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('instance_id', $qb->createNamedParameter($instanceId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }
}
