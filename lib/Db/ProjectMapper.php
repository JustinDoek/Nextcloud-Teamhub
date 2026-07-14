<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_project (Project Teams keystone, v3.88.0).
 *
 * At most one row per team (unique index on team_id), so findByTeam returns a
 * single entity or null rather than a list.
 */
class ProjectMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_project', Project::class);
    }

    /**
     * The project row for a team, or null when the team is not a project.
     */
    public function findByTeam(string $teamId): ?Project {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)))
            ->setMaxResults(1);
        try {
            /** @var Project */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function insertProject(
        string  $teamId,
        string  $mode,
        ?string $phase,
        ?int    $startDate,
        ?int    $targetEnd,
        string  $createdBy
    ): Project {
        $now = time();
        $row = new Project();
        $row->setTeamId($teamId);
        $row->setType('project');
        $row->setMode($mode);
        $row->setPhase($phase);
        $row->setStartDate($startDate);
        $row->setTargetEnd($targetEnd);
        $row->setCreatedBy($createdBy);
        $row->setCreatedAt($now);
        $row->setUpdatedAt($now);
        /** @var Project */
        return $this->insert($row);
    }

    /**
     * Delete the project row for a team — parity with the other teamhub_*
     * mappers' deleteByTeamId() helpers, for a future team-deletion cleanup
     * pass (no teamhub_* table is purged on team deletion today).
     */
    public function deleteByTeamId(string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
    }
}
