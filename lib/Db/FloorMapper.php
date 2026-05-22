<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_floors — middle of the location hierarchy.
 */
class FloorMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_floors', Floor::class);
    }

    public function findById(int $id): ?Floor {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        try {
            /** @var Floor */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * All floors belonging to one building, ordered for stable display.
     *
     * @return Floor[]
     */
    public function findByBuilding(int $buildingId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq(
                'building_id',
                $qb->createNamedParameter($buildingId, IQueryBuilder::PARAM_INT)
            ))
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('name', 'ASC');

        /** @var Floor[] */
        return $this->findEntities($qb);
    }

    /**
     * All floors across all buildings — used by the tree-load to pull every
     * floor in a single query (then bucket by building_id in PHP).
     *
     * @return Floor[]
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('name', 'ASC');

        /** @var Floor[] */
        return $this->findEntities($qb);
    }

    /**
     * Delete every floor under a given building. Called by
     * PresenceLocationService::deleteBuilding as part of cascading delete
     * (after deleteRoomsForBuilding has run).
     */
    public function deleteByBuilding(int $buildingId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq(
                'building_id',
                $qb->createNamedParameter($buildingId, IQueryBuilder::PARAM_INT)
            ));
        $qb->executeStatement();
    }
}
