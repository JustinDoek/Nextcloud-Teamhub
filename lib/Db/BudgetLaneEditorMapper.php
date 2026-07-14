<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_budget_lane_editor (v3.93.0). Editor sets are usually
 * small (0–10 UIDs per lane) so we read them fully and diff in PHP rather
 * than issuing per-user inserts/deletes.
 */
class BudgetLaneEditorMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_budget_lane_editor', BudgetLaneEditor::class);
    }

    /**
     * @return BudgetLaneEditor[]
     */
    public function findByLane(int $laneId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('lane_id', $qb->createNamedParameter($laneId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Fetch every editor row for every lane in the given set — the Budget
     * page uses this to group editors by lane_id in one query rather than N.
     *
     * @param int[] $laneIds
     * @return BudgetLaneEditor[]
     */
    public function findByLaneIds(array $laneIds): array {
        if (empty($laneIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('lane_id',
                $qb->createNamedParameter($laneIds, IQueryBuilder::PARAM_INT_ARRAY)));
        return $this->findEntities($qb);
    }

    /**
     * Atomically replace the editor set for one lane with the given UIDs.
     * Deletes rows for removed UIDs, inserts rows for new UIDs, leaves
     * existing rows alone — so `created_at` reflects when a UID was first
     * added, not every save.
     *
     * @param string[] $uids  — deduplicated + trimmed by the caller
     */
    public function replaceForLane(int $laneId, array $uids): void {
        $existing = [];
        foreach ($this->findByLane($laneId) as $row) {
            $existing[$row->getUserId()] = $row;
        }

        $wanted = array_flip($uids);

        // Delete rows for UIDs no longer in the set.
        foreach ($existing as $uid => $row) {
            if (!isset($wanted[$uid])) {
                $this->delete($row);
            }
        }

        // Insert rows for UIDs newly in the set.
        $now = time();
        foreach ($uids as $uid) {
            if (isset($existing[$uid])) {
                continue;
            }
            $row = new BudgetLaneEditor();
            $row->setLaneId($laneId);
            $row->setUserId($uid);
            $row->setCreatedAt($now);
            $this->insert($row);
        }
    }

    public function deleteByLaneId(int $laneId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('lane_id', $qb->createNamedParameter($laneId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Bulk delete every editor row whose lane belongs to $teamId. Used from
     * a future team-delete cleanup pass — parity with the other teamhub_*
     * mappers' deleteByTeamId() helpers. Two-step because named-parameter
     * binding across a subquery in IQueryBuilder is fiddly across MySQL /
     * Postgres; the lane count per team is small so the two round-trips are
     * negligible and this stays portable.
     */
    public function deleteByTeamId(string $teamId): void {
        $qb1 = $this->db->getQueryBuilder();
        $res = $qb1->select('id')
            ->from('teamhub_budget_lane')
            ->where($qb1->expr()->eq('team_id', $qb1->createNamedParameter($teamId)))
            ->executeQuery();
        $laneIds = [];
        while ($row = $res->fetch()) {
            $laneIds[] = (int)$row['id'];
        }
        $res->closeCursor();
        if (empty($laneIds)) {
            return;
        }

        $qb2 = $this->db->getQueryBuilder();
        $qb2->delete($this->getTableName())
            ->where($qb2->expr()->in('lane_id',
                $qb2->createNamedParameter($laneIds, IQueryBuilder::PARAM_INT_ARRAY)));
        $qb2->executeStatement();
    }
}
