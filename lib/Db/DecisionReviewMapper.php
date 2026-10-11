<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_dec_reviews (v4.11.18).
 *
 * All queries are decision- or team-scoped. Authorisation that the caller
 * belongs to the team is the service's job; this mapper assumes it has been
 * done — the same contract `DecisionReviewMapper`'s siblings carry.
 */
class DecisionReviewMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_dec_reviews', DecisionReview::class);
    }

    /**
     * One review by id, or null.
     *
     * `QBMapper` offers no `find($id)` — only the protected
     * `findEntity(IQueryBuilder)` — so every mapper in this app writes its
     * own, and this one returns null rather than throwing because its caller
     * (`DecisionWorkProvider`, resolving an item id from a client) treats a
     * stale id as "gone", not as an error.
     */
    public function findById(int $id): ?DecisionReview {
        if ($id <= 0) {
            return null;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        $rows = $this->findEntities($qb);
        return $rows[0] ?? null;
    }

    /**
     * The open review of a decision, if there is one.
     *
     * Returns null rather than throwing: "no review is running" is the
     * ordinary case, asked before every request and on every render of a
     * decision's panel, and an exception for the ordinary case makes every
     * caller write a try block.
     */
    public function findOpenForDecision(int $decisionId): ?DecisionReview {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('decision_id', $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(DecisionReview::STATUS_OPEN)))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);

        $rows = $this->findEntities($qb);
        return $rows[0] ?? null;
    }

    /**
     * Every review of a decision, newest first — the decision panel's
     * review history.
     *
     * @return DecisionReview[]
     */
    public function findByDecision(int $decisionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('decision_id', $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * The open reviews in a set of teams — what My Work asks once per
     * viewer, for the teams that viewer belongs to.
     *
     * @param string[] $teamIds
     * @return DecisionReview[]
     */
    public function findOpenForTeams(array $teamIds, int $limit = 200): array {
        if ($teamIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('team_id', $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(DecisionReview::STATUS_OPEN)))
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * The open reviews of a set of decisions, keyed by decision id — so a
     * list of decisions can be rendered with "a review is running" on each
     * without a query per row.
     *
     * @param int[] $decisionIds
     * @return array<int, DecisionReview>
     */
    public function findOpenByDecisions(array $decisionIds): array {
        if ($decisionIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('decision_id', $qb->createNamedParameter($decisionIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(DecisionReview::STATUS_OPEN)))
            ->orderBy('id', 'ASC');

        $out = [];
        foreach ($this->findEntities($qb) as $row) {
            // Last one wins, and `id ASC` makes that the newest. The service
            // allows one open review per decision; if an older instance ever
            // left two, the newest is the one the UI should act on.
            $out[$row->getDecisionId()] = $row;
        }
        return $out;
    }

    /**
     * When each of these decisions was last reviewed, keyed by decision id —
     * the anchor a recomputed review date starts from.
     *
     * Only completed reviews count: an open one has not answered anything,
     * so it cannot restart a clock. Decisions never reviewed are absent from
     * the result rather than present with 0, so the caller's `??` reads as
     * "no review" instead of "reviewed in 1970".
     *
     * `GROUP BY` names the one non-aggregated column, which Postgres
     * requires and MariaDB accepts (migrations skill, rule 3).
     *
     * @param int[] $decisionIds
     * @return array<int, int>
     */
    public function lastCompletedAtByDecisions(array $decisionIds): array {
        $decisionIds = array_values(array_unique(array_filter(
            array_map('intval', $decisionIds),
            static fn (int $i): bool => $i > 0,
        )));
        if ($decisionIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('decision_id')
            ->selectAlias($qb->func()->max('completed_at'), 'last_at')
            ->from($this->getTableName())
            ->where($qb->expr()->in('decision_id', $qb->createNamedParameter($decisionIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(DecisionReview::STATUS_COMPLETED)))
            ->andWhere($qb->expr()->isNotNull('completed_at'))
            ->groupBy('decision_id');

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $at = (int)($row['last_at'] ?? 0);
            if ($at > 0) {
                $out[(int)$row['decision_id']] = $at;
            }
        }
        $result->closeCursor();
        return $out;
    }

    /**
     * Drop every review row of a decision. Called when the decision itself
     * is deleted — the link is soft (no foreign key), so nothing else would.
     */
    public function deleteAllForDecision(int $decisionId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('decision_id', $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
    }
}
