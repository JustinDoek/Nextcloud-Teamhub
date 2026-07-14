<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_project_member (v3.96.0 — Time investment).
 *
 * At most one row per (team_id, user_id) — enforced by unique index in the
 * migration. Callers use findByTeam for the report grid and existsForUser for
 * the tab-visibility precomputation in LayoutController.
 */
class ProjectMemberMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_project_member', ProjectMember::class);
    }

    /**
     * @return ProjectMember[]
     */
    public function findByTeam(string $teamId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)))
            ->orderBy('user_id', 'ASC');
        return $this->findEntities($qb);
    }

    public function findByTeamAndUser(string $teamId, string $userId): ?ProjectMember {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->setMaxResults(1);
        try {
            /** @var ProjectMember */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Cheap existence check — used by LayoutController::projectFacts to
     * compute timeConfig.can_view_time without pulling the full row.
     */
    public function existsForUser(string $teamId, string $userId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->setMaxResults(1);
        $r = $qb->executeQuery();
        $row = $r->fetch();
        $r->closeCursor();
        return $row !== false;
    }

    public function insertMember(string $teamId, string $userId, int $availableMinutes = 0): ProjectMember {
        $now = time();
        $row = new ProjectMember();
        $row->setTeamId($teamId);
        $row->setUserId($userId);
        $row->setAvailableMinutes($availableMinutes);
        $row->setCreatedAt($now);
        $row->setUpdatedAt($now);
        /** @var ProjectMember */
        return $this->insert($row);
    }

    public function deleteByTeamId(string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
    }

    public function deleteByTeamAndUser(string $teamId, string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
    }
}
