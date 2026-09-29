<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `teamhub_service_team` (WorkflowHub phase 5, v4.10.20).
 *
 * @extends QBMapper<ServiceTeam>
 */
class ServiceTeamMapper extends QBMapper {

    public const TABLE = 'teamhub_service_team';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, ServiceTeam::class);
    }

    public function findByTeam(string $teamId): ?ServiceTeam {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1);
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Every service team, newest first. Small by nature — an instance has a
     * handful of these, not one per team — so there is no paging.
     *
     * @return ServiceTeam[]
     */
    public function findAll(bool $activeOnly = false): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('created_at', 'DESC');
        if ($activeOnly) {
            $qb->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
        }
        return $this->findEntities($qb);
    }

    /**
     * The service teams these ids name, keyed by team id — one query for a
     * queue listing rather than one per row.
     *
     * @param string[] $teamIds
     * @return array<string, ServiceTeam>
     */
    public function findByTeams(array $teamIds): array {
        $teamIds = array_values(array_unique(array_filter(array_map('strval', $teamIds))));
        if ($teamIds === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('team_id', $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY)));
        $out = [];
        foreach ($this->findEntities($qb) as $row) {
            $out[$row->getTeamId()] = $row;
        }
        return $out;
    }

    public function deleteByTeam(string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->executeStatement();
    }
}
