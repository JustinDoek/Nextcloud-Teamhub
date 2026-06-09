<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_dec_cat_apprs.
 *
 * All writes go through DecisionCategoryService so the team-owner default
 * is preserved and the approver list never goes empty.
 */
class DecisionCategoryApproverMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_dec_cat_apprs', DecisionCategoryApprover::class);
    }

    /**
     * Approver user IDs for a single category.
     *
     * @return string[]  array of user IDs
     */
    public function findUserIdsByCategory(int $categoryId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();
        return array_map(fn($r) => (string)$r['user_id'], $rows);
    }

    /**
     * Approver user IDs grouped by category id, for many categories in one
     * query. Used by the manage-team list view.
     *
     * @param int[] $categoryIds
     * @return array<int, string[]>  [categoryId => [userId, ...]]
     */
    public function findUserIdsByCategories(array $categoryIds): array {
        $out = [];
        foreach ($categoryIds as $id) {
            $out[(int)$id] = [];
        }
        if (!$categoryIds) {
            return $out;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('category_id', 'user_id')
            ->from($this->getTableName())
            ->where($qb->expr()->in('category_id', $qb->createNamedParameter($categoryIds, IQueryBuilder::PARAM_INT_ARRAY)));

        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $out[(int)$row['category_id']][] = (string)$row['user_id'];
        }
        $result->closeCursor();
        return $out;
    }

    /**
     * Delete every approver row for a category. Used both when replacing the
     * approver list wholesale and when deleting the category itself.
     */
    public function deleteAllForCategory(int $categoryId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Quick existence check: is this user an approver for this category?
     * Used by the Session I "Approve" tab gate.
     */
    public function isApprover(int $categoryId, string $userId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row !== false;
    }

    /**
     * All category ids in a team that this user can approve in.
     * Used by the widget "Approve" tab gate to decide whether the tab shows
     * at all (Session I).
     *
     * @return int[]
     */
    public function findCategoryIdsApprovableBy(string $teamId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('a.category_id')
            ->from($this->getTableName(), 'a')
            ->innerJoin('a', 'teamhub_dec_categories', 'c', $qb->expr()->eq('a.category_id', 'c.id'))
            ->where($qb->expr()->eq('c.team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();
        return array_map(fn($r) => (int)$r['category_id'], $rows);
    }
}
