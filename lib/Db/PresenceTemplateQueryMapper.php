<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Narrow query helper against teamhub_presence_template.
 *
 * Symmetric counterpart to PresenceSlotQueryMapper. In B1 the table is empty;
 * these counts always return 0. The wiring exists so the service layer's
 * referential-integrity gates (in PresenceTypeService::deleteType and
 * PresenceLocationService::delete*) keep working unmodified once B2 starts
 * populating template rows.
 */
class PresenceTemplateQueryMapper {

    private const TABLE = 'teamhub_presence_template';

    public function __construct(private IDBConnection $db) {}

    /**
     * Count of template rows that reference a particular presence_type_id.
     */
    public function countByPresenceType(int $typeId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from(self::TABLE)
            ->where($qb->expr()->eq(
                'presence_type_id',
                $qb->createNamedParameter($typeId, IQueryBuilder::PARAM_INT)
            ));

        $result = $qb->executeQuery();
        $cnt    = (int)$result->fetchOne();
        $result->closeCursor();
        return $cnt;
    }

    /**
     * Count of template rows that reference any of the given room ids.
     *
     * @param int[] $roomIds
     */
    public function countByRoomIds(array $roomIds): int {
        if (count($roomIds) === 0) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from(self::TABLE)
            ->where($qb->expr()->in(
                'location_room_id',
                $qb->createNamedParameter($roomIds, IQueryBuilder::PARAM_INT_ARRAY)
            ));

        $result = $qb->executeQuery();
        $cnt    = (int)$result->fetchOne();
        $result->closeCursor();
        return $cnt;
    }
}
