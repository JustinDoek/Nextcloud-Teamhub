<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_dec_categories.
 *
 * All queries are team-scoped. Authorisation that the caller belongs to the
 * team is the controller's job; this mapper assumes it has been done.
 */
class DecisionCategoryMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_dec_categories', DecisionCategory::class);
    }

    /**
     * All categories for a team, ordered by name (case-insensitive).
     *
     * @return DecisionCategory[]
     */
    public function findByTeam(string $teamId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->orderBy($qb->func()->lower('name'), 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Fetch one category by id, ensuring it belongs to the given team.
     * Throws DoesNotExistException if either condition fails — this is the
     * canonical authorisation gate for category mutations.
     */
    public function findByIdForTeam(int $id, string $teamId): DecisionCategory {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1);

        return $this->findEntity($qb);
    }

    /**
     * Check whether a name is already taken within a team. Optionally
     * exclude a specific id (used on rename to allow keeping the same name).
     */
    public function existsByName(string $teamId, string $name, ?int $excludeId = null): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq($qb->func()->lower('name'), $qb->createNamedParameter(mb_strtolower($name))))
            ->setMaxResults(1);

        if ($excludeId !== null) {
            $qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        }

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row !== false;
    }
}
