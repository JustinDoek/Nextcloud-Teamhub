<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `teamhub_wf_step` (WorkflowHub phase 1, v4.10.13).
 *
 * @extends QBMapper<WorkflowStep>
 */
class WorkflowStepMapper extends QBMapper {

    public const TABLE = 'teamhub_wf_step';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, WorkflowStep::class);
    }

    /**
     * Every step of one instance, in execution order.
     *
     * @return WorkflowStep[]
     */
    public function findByInstance(int $instanceId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('instance_id', $qb->createNamedParameter($instanceId, IQueryBuilder::PARAM_INT)))
            ->orderBy('step_order', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * The steps of many instances, keyed by instance id, each in order —
     * one query for a listing instead of one per row.
     *
     * @param int[] $instanceIds
     * @return array<int, WorkflowStep[]>
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
            ->orderBy('instance_id', 'ASC')
            ->addOrderBy('step_order', 'ASC');
        $out = [];
        foreach ($this->findEntities($qb) as $step) {
            $out[$step->getInstanceId()][] = $step;
        }
        return $out;
    }

    /**
     * Every *active* step waiting on one actor (WorkflowHub phase 5,
     * v4.10.20) - the service team's incoming queue, answered from the
     * `th_wfs_actor_idx` index rather than by scanning open workflows.
     *
     * Active is the three statuses somebody can still act on; the caller
     * filters out any instance that is no longer open, which a step row
     * cannot know on its own.
     *
     * @return WorkflowStep[]
     */
    public function findActiveForActor(string $actorType, string $actorId): array {
        if ($actorId === '') {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('actor_type', $qb->createNamedParameter($actorType)))
            ->andWhere($qb->expr()->eq('actor_id', $qb->createNamedParameter($actorId)))
            ->andWhere($qb->expr()->in('step_status', $qb->createNamedParameter(
                // Inlined rather than taken from WorkflowStepStatus::ACTIVE:
                // a mapper states the values it queries, so a later change to
                // the vocabulary is a visible change here too.
                ['available', 'in_progress', 'waiting_for_information'],
                IQueryBuilder::PARAM_STR_ARRAY,
            )))
            ->orderBy('entered_at', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Every step of one actor that **entered** the queue or **finished**
     * since a moment (v4.10.27) — the service team's Closed tab and its
     * statistics, from the same `th_wfs_actor_idx` rows as the queue.
     *
     * A step that never entered (`entered_at` null: a pending step the
     * workflow ended before reaching) is not the desk's and is excluded by
     * both conditions.
     *
     * @return WorkflowStep[]
     */
    public function findForActorSince(string $actorType, string $actorId, int $since): array {
        if ($actorId === '') {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('actor_type', $qb->createNamedParameter($actorType)))
            ->andWhere($qb->expr()->eq('actor_id', $qb->createNamedParameter($actorId)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->gte('entered_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)),
                $qb->expr()->gte('completed_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)),
            ))
            ->orderBy('entered_at', 'DESC')
            ->addOrderBy('id', 'DESC');
        return $this->findEntities($qb);
    }

    public function deleteByInstance(int $instanceId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('instance_id', $qb->createNamedParameter($instanceId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }
}
