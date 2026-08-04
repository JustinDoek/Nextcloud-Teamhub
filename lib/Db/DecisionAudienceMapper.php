<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_decision_audience (v4.5.42).
 *
 * @extends QBMapper<DecisionAudience>
 */
class DecisionAudienceMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_decision_audience', DecisionAudience::class);
    }

    /**
     * Replace the audience for one decision.
     *
     * Delete-then-insert rather than a diff: the set is small (the people a
     * proposer picked), the operation is rare (create, and edit-while-open),
     * and a diff would have to reason about a partial failure leaving the
     * audience half-updated. Wrapped in a transaction so it is all or nothing.
     *
     * @param string[] $userIds
     */
    public function replaceAudience(int $decisionId, array $userIds): void {
        $now    = time();
        $unique = array_values(array_unique(array_filter(
            $userIds,
            static fn ($u): bool => is_string($u) && $u !== '',
        )));

        $this->db->beginTransaction();
        try {
            $this->deleteForDecision($decisionId);

            foreach ($unique as $userId) {
                $row = new DecisionAudience();
                $row->setDecisionId($decisionId);
                $row->setUserId($userId);
                $row->setCreatedAt($now);
                $this->insert($row);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return string[] user ids, in insertion order */
    public function findUserIds(int $decisionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq(
                'decision_id',
                $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT),
            ))
            ->orderBy('id', 'ASC');

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = (string)$row['user_id'];
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Every decision id this user has been invited to.
     *
     * One query per feed request, answering "which restricted proposals may
     * this person see" — the opposite direction from findUserIds, and why
     * `th_dau_user_idx` exists.
     *
     * @return int[]
     */
    public function findDecisionIdsForUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('decision_id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = (int)$row['decision_id'];
        }
        $result->closeCursor();

        return $out;
    }

    public function isInAudience(int $decisionId, string $userId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq(
                'decision_id',
                $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT),
            ))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row !== false && $row !== null;
    }

    public function deleteForDecision(int $decisionId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq(
                'decision_id',
                $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT),
            ));

        return $qb->executeStatement();
    }
}
