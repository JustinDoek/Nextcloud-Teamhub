<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_presence_types.
 *
 * The catalogue is small (5 builtins plus a handful of admin-added customs),
 * read frequently, written rarely. All reads are full-table or single-row.
 */
class PresenceTypeMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_presence_types', PresenceType::class);
    }

    /**
     * Return all types, ordered by sort_order then label for stable display.
     *
     * @return PresenceType[]
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('label', 'ASC');

        /** @var PresenceType[] */
        return $this->findEntities($qb);
    }

    /**
     * Find a type by id, or null if absent.
     */
    public function findById(int $id): ?PresenceType {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        try {
            /** @var PresenceType */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Find a type by slug, or null if absent. Slugs are unique-indexed.
     */
    public function findBySlug(string $slug): ?PresenceType {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)))
            ->setMaxResults(1);

        try {
            /** @var PresenceType */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }
}
