<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_presence_template.
 *
 * One row per (user_id, day_of_week, half_day). The unique index
 * th_ptmpl_uniq enforces this at the DB level; upsert logic lives in
 * the service layer (find-then-insert-or-update pattern).
 */
class PresenceTemplateMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_presence_template', PresenceTemplate::class);
    }

    /**
     * Return all 14 template rows for a user (or fewer if not all cells set).
     * Ordered by day_of_week then half_day for predictable iteration.
     *
     * @return PresenceTemplate[]
     */
    public function findByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('day_of_week', 'ASC')
            ->addOrderBy('half_day', 'ASC');

        /** @var PresenceTemplate[] */
        return $this->findEntities($qb);
    }

    /**
     * Find a specific cell for a user, or null if unset.
     */
    public function findCell(string $userId, int $dayOfWeek, int $halfDay): ?PresenceTemplate {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id',     $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('day_of_week', $qb->createNamedParameter($dayOfWeek, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('half_day',    $qb->createNamedParameter($halfDay,   IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        try {
            /** @var PresenceTemplate */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Delete all template rows for a user. Used when a user clears their template.
     */
    public function deleteByUser(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }

    /**
     * Count of users who have at least one template row set.
     * Used by telemetry / stats (future).
     */
    public function countDistinctUsers(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('user_id')
            ->from($this->getTableName());
        // Wrap in a COUNT subquery — QBMapper has no direct countDistinct helper.
        // We iterate instead (catalogue is small: one row per user per set cell).
        $result = $qb->executeQuery();
        $count  = 0;
        while ($result->fetch()) {
            $count++;
        }
        $result->closeCursor();
        return $count;
    }

    /**
     * Return distinct user IDs of every user who has at least one template cell.
     * Used by PresenceHolidayService to create holiday slots for all active users.
     *
     * @return string[]
     */
    public function getAllUserIds(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('user_id')
            ->from($this->getTableName())
            ->orderBy('user_id', 'ASC');
        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (string)$row['user_id'];
        }
        $result->closeCursor();
        return $ids;
    }
}
