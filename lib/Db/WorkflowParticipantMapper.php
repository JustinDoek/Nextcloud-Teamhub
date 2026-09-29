<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `teamhub_wf_participant` (WorkflowHub phase 1, v4.10.13).
 *
 * @extends QBMapper<WorkflowParticipant>
 */
class WorkflowParticipantMapper extends QBMapper {

    public const TABLE = 'teamhub_wf_participant';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, WorkflowParticipant::class);
    }

    /** @return WorkflowParticipant[] every row of the instance, removed ones included */
    public function findByInstance(int $instanceId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('instance_id', $qb->createNamedParameter($instanceId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Instance ids where one of the given actors is a current participant.
     * `$actors` is a list of `[type, id]` pairs — the viewer's uid, their
     * groups.
     *
     * @param array<int, array{0: string, 1: string}> $actors
     * @return int[]
     */
    public function findInstanceIdsForActors(array $actors): array {
        if ($actors === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $or = $qb->expr()->orX();
        foreach ($actors as [$type, $id]) {
            $or->add($qb->expr()->andX(
                $qb->expr()->eq('actor_type', $qb->createNamedParameter($type)),
                $qb->expr()->eq('actor_id',   $qb->createNamedParameter($id)),
            ));
        }
        $qb->selectDistinct('instance_id')
            ->from($this->getTableName())
            ->where($or)
            ->andWhere($qb->expr()->isNull('removed_at'));
        $out = [];
        $res = $qb->executeQuery();
        while ($row = $res->fetch()) {
            $out[] = (int)$row['instance_id'];
        }
        $res->closeCursor();
        return $out;
    }

    /**
     * Team-relative participant rows (team, team owner, team moderator) on
     * instances of the given teams — the candidates a role holder may see;
     * the caller resolves each against the viewer.
     *
     * @param string[] $teamIds
     * @param string[] $teamRelativeTypes
     * @return array<int, array{instanceId: int, teamId: string, actorType: string}>
     */
    public function findTeamRelativeForTeams(array $teamIds, array $teamRelativeTypes): array {
        if ($teamIds === [] || $teamRelativeTypes === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('p.instance_id', 'p.actor_type', 'i.team_id')
            ->from($this->getTableName(), 'p')
            ->innerJoin('p', WorkflowInstanceMapper::TABLE, 'i', $qb->expr()->eq('i.id', 'p.instance_id'))
            ->where($qb->expr()->in('i.team_id', $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->in('p.actor_type', $qb->createNamedParameter($teamRelativeTypes, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->isNull('p.removed_at'));
        $out = [];
        $res = $qb->executeQuery();
        while ($row = $res->fetch()) {
            $out[] = [
                'instanceId' => (int)$row['instance_id'],
                'teamId'     => (string)$row['team_id'],
                'actorType'  => (string)$row['actor_type'],
            ];
        }
        $res->closeCursor();
        return $out;
    }

    /**
     * The participants of many instances, keyed by instance id - one query
     * for a queue listing instead of one per row (v4.10.20).
     *
     * @param int[] $instanceIds
     * @return array<int, WorkflowParticipant[]>
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
            ->addOrderBy('id', 'ASC');
        $out = [];
        foreach ($this->findEntities($qb) as $row) {
            $out[$row->getInstanceId()][] = $row;
        }
        return $out;
    }

    public function deleteByInstance(int $instanceId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('instance_id', $qb->createNamedParameter($instanceId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }
}
