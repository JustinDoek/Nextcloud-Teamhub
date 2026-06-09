<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class DecisionTaskLinkMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_dec_tasks', DecisionTaskLink::class);
    }

    /**
     * Find a single link row by id.
     */
    public function findById(int $id): ?DecisionTaskLink {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        try {
            /** @var DecisionTaskLink */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * All links for a given decision, ordered by creation time (stable).
     *
     * @return DecisionTaskLink[]
     */
    public function findByDecisionId(int $decisionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('decision_id', $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Lookup a specific (decision, card) pair. Returns null if no row.
     * Used to prevent duplicate links (the unique index also enforces this at the DB).
     */
    public function findPair(int $decisionId, int $deckCardId): ?DecisionTaskLink {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('decision_id', $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('deck_card_id', $qb->createNamedParameter($deckCardId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        try {
            /** @var DecisionTaskLink */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Find all link rows whose deck_card_id is in the given set.
     * Used to render the "linked decisions" callout on a Deck card view
     * (Session E.5 — Deck card action).
     *
     * @param int[] $deckCardIds
     * @return DecisionTaskLink[]
     */
    public function findByDeckCardIds(array $deckCardIds): array {
        if (empty($deckCardIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'deck_card_id',
                $qb->createNamedParameter($deckCardIds, IQueryBuilder::PARAM_INT_ARRAY)
            ));
        return $this->findEntities($qb);
    }
}
