<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the teamhub_dec_tasks table — stores links between decisions
 * and external tasks (Deck cards, URLs, etc.).
 *
 * Each row is a one-way link: a decision references a task_path (e.g.
 * "apps/deck/board/2/card/9") with an optional human-readable label.
 */
class DecisionTaskMapper {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    /**
     * Find all task links for a given decision.
     *
     * @return array<int, array{ id: int, decision_id: int, team_id: string, task_path: string, label: ?string, created_by: string, created_at: int }>
     */
    public function findByDecision(int $decisionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_dec_tasks')
            ->where($qb->expr()->eq('decision_id', $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC');

        $result = $qb->executeQuery();
        $rows   = [];
        while ($row = $result->fetch()) {
            $rows[] = $this->rowToArray($row);
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Find a single task link by its ID.
     */
    public function findById(int $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_dec_tasks')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $row = $qb->executeQuery()->fetch();
        return $row ? $this->rowToArray($row) : null;
    }

    /**
     * Insert a new task link.
     */
    public function insert(int $decisionId, string $teamId, string $taskPath, ?string $label, string $createdBy): array {
        $now = time();
        $qb  = $this->db->getQueryBuilder();
        $qb->insert('teamhub_dec_tasks')
            ->values([
                'decision_id' => $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT),
                'team_id'     => $qb->createNamedParameter($teamId),
                'task_path'   => $qb->createNamedParameter($taskPath),
                'label'       => $qb->createNamedParameter($label),
                'created_by'  => $qb->createNamedParameter($createdBy),
                'created_at'  => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ]);
        $qb->executeStatement();
        $id = $qb->getLastInsertId();
        return [
            'id'          => (int)$id,
            'decision_id' => $decisionId,
            'team_id'     => $teamId,
            'task_path'   => $taskPath,
            'label'       => $label,
            'created_by'  => $createdBy,
            'created_at'  => $now,
        ];
    }

    /**
     * Delete a task link by its ID.
     */
    public function delete(int $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('teamhub_dec_tasks')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    private function rowToArray(array $row): array {
        return [
            'id'          => (int)$row['id'],
            'decision_id' => (int)$row['decision_id'],
            'team_id'     => (string)$row['team_id'],
            'task_path'   => (string)$row['task_path'],
            'label'       => $row['label'] !== null ? (string)$row['label'] : null,
            'created_by'  => (string)$row['created_by'],
            'created_at'  => (int)$row['created_at'],
        ];
    }
}
