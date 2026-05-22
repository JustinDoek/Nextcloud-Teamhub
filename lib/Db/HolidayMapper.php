<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_holidays.
 *
 * Holiday date is stored as ISO YYYY-MM-DD VARCHAR(10) per DESIGN.md §2.4.
 * Year-scoped queries use lexicographic prefix matching: BETWEEN 'YYYY-01-01'
 * AND 'YYYY-12-31'. This works on every supported DB.
 */
class HolidayMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_holidays', Holiday::class);
    }

    public function findById(int $id): ?Holiday {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        try {
            /** @var Holiday */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function findByDate(string $isoDate): ?Holiday {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq(
                'holiday_date',
                $qb->createNamedParameter($isoDate)
            ))
            ->setMaxResults(1);

        try {
            /** @var Holiday */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * All holidays, ordered by date ascending.
     *
     * @return Holiday[]
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('holiday_date', 'ASC');

        /** @var Holiday[] */
        return $this->findEntities($qb);
    }

    /**
     * All holidays in a given year, ordered by date ascending.
     *
     * @return Holiday[]
     */
    public function findByYear(int $year): array {
        $yearStart = sprintf('%04d-01-01', $year);
        $yearEnd   = sprintf('%04d-12-31', $year);

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->gte('holiday_date', $qb->createNamedParameter($yearStart)))
            ->andWhere($qb->expr()->lte('holiday_date', $qb->createNamedParameter($yearEnd)))
            ->orderBy('holiday_date', 'ASC');

        /** @var Holiday[] */
        return $this->findEntities($qb);
    }
}
