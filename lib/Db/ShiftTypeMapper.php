<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_shift_type.
 *
 * The catalogue is three built-ins plus whatever an administrator adds: small,
 * read on every roster render, written rarely. Every read is full-table or
 * single-row, same shape as PresenceTypeMapper.
 */
class ShiftTypeMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_shift_type', ShiftType::class);
    }

    /**
     * Every type, active or not, ordered for display.
     *
     * Inactive ones come back too: a shift rostered before a type was retired
     * still has to render with its name and colour, and dropping them here
     * would make historical weeks unreadable.
     *
     * @return ShiftType[]
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('label', 'ASC');

        /** @var ShiftType[] */
        return $this->findEntities($qb);
    }

    /**
     * The types an administrator may roster with right now.
     *
     * @return ShiftType[]
     */
    public function findActive(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('label', 'ASC');

        /** @var ShiftType[] */
        return $this->findEntities($qb);
    }

    public function findById(int $id): ?ShiftType {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        try {
            /** @var ShiftType */
            return $this->findEntity($qb);
        } catch (\Throwable) {
            return null;
        }
    }

    public function findBySlug(string $slug): ?ShiftType {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)))
            ->setMaxResults(1);

        try {
            /** @var ShiftType */
            return $this->findEntity($qb);
        } catch (\Throwable) {
            return null;
        }
    }
}
