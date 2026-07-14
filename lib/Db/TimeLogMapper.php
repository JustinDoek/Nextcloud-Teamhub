<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_time_log (v3.96.0 — Time investment).
 *
 * Query patterns supported by the four indexes on the table:
 *   - findByTeam         → per-project rollup + report source
 *   - findByTeamAndUser  → per-member drill-down
 *   - findByCard         → per-card panel (future — not surfaced in v1)
 *   - sumByStack (via findByTeam grouping in PHP) → per-lane rollup
 */
class TimeLogMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_time_log', TimeLog::class);
    }

    /**
     * v3.98.0 — cheap existence check for the project-readiness signal.
     * Only reads whether any log exists for the team, without loading
     * the row. Used by ProjectReadinessService (Execution phase "first
     * time entry logged" item).
     */
    public function hasAnyForTeam(string $teamId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1);
        $r = $qb->executeQuery();
        $row = $r->fetch();
        $r->closeCursor();
        return $row !== false;
    }

    public function findById(int $id): ?TimeLog {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        try {
            /** @var TimeLog */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Every log for a team, newest work first. Report grouping and per-lane
     * rollups are done in PHP against this list — a single team's worth of
     * time logs is small enough (thousands, not millions) to fit comfortably
     * in memory, and it keeps the rollup logic in one place instead of five
     * DB-side GROUP BYs.
     *
     * @return TimeLog[]
     */
    public function findByTeam(string $teamId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)))
            ->orderBy('worked_at', 'DESC')
            ->addOrderBy('id', 'DESC');
        return $this->findEntities($qb);
    }

    /**
     * @return TimeLog[]
     */
    public function findByTeamAndUser(string $teamId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->orderBy('worked_at', 'DESC')
            ->addOrderBy('id', 'DESC');
        return $this->findEntities($qb);
    }

    /**
     * @return TimeLog[]
     */
    public function findByCard(int $cardId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
            ->orderBy('worked_at', 'DESC')
            ->addOrderBy('id', 'DESC');
        return $this->findEntities($qb);
    }

    public function insertLog(
        string $teamId,
        int    $cardId,
        int    $stackId,
        string $userId,
        int    $minutes,
        string $description,
        int    $workedAt,
        string $createdBy
    ): TimeLog {
        $now = time();
        $row = new TimeLog();
        $row->setTeamId($teamId);
        $row->setCardId($cardId);
        $row->setStackId($stackId);
        $row->setUserId($userId);
        $row->setMinutes($minutes);
        $row->setDescription($description);
        $row->setWorkedAt($workedAt);
        $row->setCreatedBy($createdBy);
        $row->setCreatedAt($now);
        $row->setUpdatedAt($now);
        /** @var TimeLog */
        return $this->insert($row);
    }

    public function deleteByTeamId(string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
    }
}
