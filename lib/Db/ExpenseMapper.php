<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_expense (v3.92.0 — Budget page).
 */
class ExpenseMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_expense', Expense::class);
    }

    /**
     * v3.98.0 — cheap existence check for the project-readiness signal.
     * Only reads whether any expense exists for the team, without loading
     * the row. Used by ProjectReadinessService (Execution phase "first
     * expense logged" item).
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

    public function findById(int $id): ?Expense {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        try {
            /** @var Expense */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Every expense across every lane for a team, oldest-created first. The
     * caller groups by lane_id and applies its own sort inside a lane.
     *
     * @return Expense[]
     */
    public function findByTeam(string $teamId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)))
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * @return Expense[]
     */
    public function findByLane(int $laneId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('lane_id', $qb->createNamedParameter($laneId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    public function insertExpense(
        string $teamId,
        int    $laneId,
        string $description,
        int    $projectedMinor,
        ?int   $realMinor,
        ?int   $incurredAt,
        string $createdBy
    ): Expense {
        $now = time();
        $row = new Expense();
        $row->setTeamId($teamId);
        $row->setLaneId($laneId);
        $row->setDescription($description);
        $row->setProjectedMinor($projectedMinor);
        $row->setRealMinor($realMinor);
        $row->setIncurredAt($incurredAt);
        $row->setCreatedBy($createdBy);
        $row->setCreatedAt($now);
        $row->setUpdatedAt($now);
        /** @var Expense */
        return $this->insert($row);
    }

    public function deleteByTeamId(string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
    }

    public function deleteByLaneId(int $laneId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('lane_id', $qb->createNamedParameter($laneId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
