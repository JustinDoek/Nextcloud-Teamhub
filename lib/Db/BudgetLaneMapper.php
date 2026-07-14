<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_budget_lane (v3.92.0 — Budget page).
 *
 * At most one row per (team_id, deck_stack_id) — enforced by a unique index
 * in the migration. findByTeam returns every lane row for the team, in a
 * deterministic order (deck_stack_id ASC — the caller re-sorts against the
 * live Deck stack `order` column, which can change independently).
 */
class BudgetLaneMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_budget_lane', BudgetLane::class);
    }

    public function findById(int $id): ?BudgetLane {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        try {
            /** @var BudgetLane */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * @return BudgetLane[]
     */
    public function findByTeam(string $teamId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)))
            ->orderBy('deck_stack_id', 'ASC');
        return $this->findEntities($qb);
    }

    public function findByTeamAndStack(string $teamId, int $deckStackId): ?BudgetLane {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('deck_stack_id', $qb->createNamedParameter($deckStackId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        try {
            /** @var BudgetLane */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function insertLane(
        string $teamId,
        int    $deckStackId,
        ?int   $allocatedMinor = null,
        int    $viewMinLevel   = 1,
        int    $editMinLevel   = 8
    ): BudgetLane {
        $now = time();
        $row = new BudgetLane();
        $row->setTeamId($teamId);
        $row->setDeckStackId($deckStackId);
        $row->setAllocatedMinor($allocatedMinor);
        $row->setViewMinLevel($viewMinLevel);
        $row->setEditMinLevel($editMinLevel);
        $row->setCreatedAt($now);
        $row->setUpdatedAt($now);
        /** @var BudgetLane */
        return $this->insert($row);
    }

    public function deleteByTeamId(string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
    }
}
