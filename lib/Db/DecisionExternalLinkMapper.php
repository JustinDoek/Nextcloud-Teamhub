<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class DecisionExternalLinkMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_dec_ext_links', DecisionExternalLink::class);
    }

    public function findById(int $id): ?DecisionExternalLink {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        try {
            /** @var DecisionExternalLink */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * All external links for a single decision, ordered oldest-first.
     *
     * @return DecisionExternalLink[]
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

    public function insertLink(
        string $teamId,
        int    $decisionId,
        string $url,
        string $label,
        string $createdBy
    ): DecisionExternalLink {
        $row = new DecisionExternalLink();
        $row->setTeamId($teamId);
        $row->setDecisionId($decisionId);
        $row->setUrl($url);
        $row->setLabel($label);
        $row->setCreatedBy($createdBy);
        $row->setCreatedAt(time());
        /** @var DecisionExternalLink */
        return $this->insert($row);
    }

    public function deleteById(int $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Delete all external links for a team — called on team deletion.
     */
    public function deleteByTeamId(string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
    }

    /**
     * Delete all external links for a specific decision — called on
     * decision deletion.
     */
    public function deleteByDecisionId(int $decisionId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('decision_id', $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
