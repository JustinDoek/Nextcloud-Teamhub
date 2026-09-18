<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_openproject_link (v4.9.3).
 *
 * Every read is narrowed by team ids the caller has already been authorised
 * for, or by project ids the caller has just been shown by OpenProject
 * itself. There is no "all links" query: the Maintenance grid reads the
 * links of the teams on its page (v4.9.4), which is still a bounded set.
 *
 * @extends QBMapper<TeamOpenProjectLink>
 */
class TeamOpenProjectLinkMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_openproject_link', TeamOpenProjectLink::class);
    }

    /**
     * The team's link, or null when the team is not linked.
     */
    public function findByTeam(string $teamId): ?TeamOpenProjectLink {
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
     * Every team linked to one OpenProject project. Feeds the "already linked
     * elsewhere" warning; the caller reduces it to the teams it may name.
     *
     * @return TeamOpenProjectLink[]
     */
    public function findByProject(int $projectId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * The links of several teams at once, keyed by team id — the Maintenance
     * grid's per-page read (v4.9.4). Teams without a link are absent.
     *
     * @param string[] $teamIds
     * @return array<string, TeamOpenProjectLink>
     */
    public function findByTeams(array $teamIds): array {
        $teamIds = array_values(array_filter(array_map('strval', $teamIds), static fn (string $id): bool => $id !== ''));
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

    /**
     * Every link to any of several projects, keyed by project id — how the
     * wizard's picker learns which of the projects OpenProject just listed
     * are taken (v4.9.4). Projects nobody links are absent.
     *
     * @param int[] $projectIds
     * @return array<int, TeamOpenProjectLink[]>
     */
    public function findByProjects(array $projectIds): array {
        $projectIds = array_values(array_filter(array_map('intval', $projectIds), static fn (int $id): bool => $id > 0));
        if ($projectIds === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('project_id', $qb->createNamedParameter($projectIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('created_at', 'ASC');

        $out = [];
        foreach ($this->findEntities($qb) as $row) {
            $out[$row->getProjectId()][] = $row;
        }
        return $out;
    }

    /**
     * Remove the team's link. Idempotent — a team without a link deletes
     * nothing and reports 0, which is what the team-delete cascade wants.
     */
    public function deleteByTeam(string $teamId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));

        return $qb->executeStatement();
    }
}
