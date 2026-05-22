<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_buildings — top of the location hierarchy.
 */
class BuildingMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_buildings', Building::class);
    }

    /**
     * Return all buildings, ordered by sort_order then name.
     *
     * @return Building[]
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('name', 'ASC');

        /** @var Building[] */
        return $this->findEntities($qb);
    }

    public function findById(int $id): ?Building {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        try {
            /** @var Building */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }
}
