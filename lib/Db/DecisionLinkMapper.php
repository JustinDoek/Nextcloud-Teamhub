<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class DecisionLinkMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_dec_links', DecisionLink::class);
    }

    /**
     * Find a single link by id.
     */
    public function findById(int $id): ?DecisionLink {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        try {
            /** @var DecisionLink */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * All links where this decision appears on either side.
     * Returns rows ordered by creation time ascending.
     *
     * @return DecisionLink[]
     */
    public function findByDecisionId(int $decisionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('decision_id_a', $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq('decision_id_b', $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT))
                )
            )
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Check whether a link between two decisions already exists.
     * The canonical ordering (a < b) is applied by the caller before passing in.
     */
    public function findPair(int $aId, int $bId): ?DecisionLink {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('decision_id_a', $qb->createNamedParameter($aId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('decision_id_b', $qb->createNamedParameter($bId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        try {
            /** @var DecisionLink */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Insert a new link row.
     * Caller must ensure aId < bId before calling.
     */
    public function insertLink(string $teamId, int $aId, int $bId, string $createdBy): DecisionLink {
        $link = new DecisionLink();
        $link->setTeamId($teamId);
        $link->setDecisionIdA($aId);
        $link->setDecisionIdB($bId);
        $link->setCreatedBy($createdBy);
        $link->setCreatedAt(time());
        /** @var DecisionLink */
        return $this->insert($link);
    }

    /**
     * Delete a link by its row id.
     */
    public function deleteById(int $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Delete all links for a team — used when a team is deleted.
     */
    public function deleteByTeamId(string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
    }

    /**
     * Delete all links that involve a specific decision id (either side).
     * Called when a decision is deleted.
     */
    public function deleteByDecisionId(int $decisionId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('decision_id_a', $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq('decision_id_b', $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT))
                )
            );
        $qb->executeStatement();
    }
}
