<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_rooms — bottom of the location hierarchy.
 */
class RoomMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_rooms', Room::class);
    }

    public function findById(int $id): ?Room {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        try {
            /** @var Room */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * All rooms on one floor, ordered for stable display.
     *
     * @return Room[]
     */
    public function findByFloor(int $floorId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq(
                'floor_id',
                $qb->createNamedParameter($floorId, IQueryBuilder::PARAM_INT)
            ))
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('name', 'ASC');

        /** @var Room[] */
        return $this->findEntities($qb);
    }

    /**
     * All rooms across all floors — used by the tree-load to pull every
     * room in a single query (then bucket by floor_id in PHP).
     *
     * @return Room[]
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('name', 'ASC');

        /** @var Room[] */
        return $this->findEntities($qb);
    }

    /**
     * Delete every room on a given floor.
     */
    public function deleteByFloor(int $floorId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq(
                'floor_id',
                $qb->createNamedParameter($floorId, IQueryBuilder::PARAM_INT)
            ));
        $qb->executeStatement();
    }

    /**
     * Delete every room under any floor of a given building. Used by
     * PresenceLocationService::deleteBuilding to flatten the subtree in
     * one query rather than O(floors).
     */
    public function deleteByBuilding(int $buildingId): void {
        // Subquery returns floor ids under this building; we delete rooms
        // whose floor_id is IN that set. Avoids fetching the floors first.
        $qb = $this->db->getQueryBuilder();

        $sub = $this->db->getQueryBuilder();
        $sub->select('id')
            ->from('teamhub_floors')
            ->where($sub->expr()->eq(
                'building_id',
                $sub->createNamedParameter($buildingId, IQueryBuilder::PARAM_INT)
            ));

        $floorIds = [];
        $result   = $sub->executeQuery();
        while ($r = $result->fetch()) {
            $floorIds[] = (int)$r['id'];
        }
        $result->closeCursor();

        if (count($floorIds) === 0) {
            return;
        }

        $qb->delete($this->getTableName())
            ->where($qb->expr()->in(
                'floor_id',
                $qb->createNamedParameter($floorIds, IQueryBuilder::PARAM_INT_ARRAY)
            ));
        $qb->executeStatement();
    }
}
