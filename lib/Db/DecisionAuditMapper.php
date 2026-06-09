<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_dec_audit. Append-only by design — no update method.
 *
 * Reads are always by decision_id, ordered by created_at ASC. That's the
 * only query pattern this table is for.
 */
class DecisionAuditMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_dec_audit', DecisionAudit::class);
    }

    /**
     * All audit events for one decision, oldest first.
     *
     * @return DecisionAudit[]
     */
    public function findByDecisionId(int $decisionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('decision_id', $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC'); // tiebreaker for same-second events

        return $this->findEntities($qb);
    }
}
