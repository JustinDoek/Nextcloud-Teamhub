<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_file_reviewer (v4.8.18).
 *
 * @extends QBMapper<FileReviewer>
 */
class FileReviewerMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_file_reviewer', FileReviewer::class);
    }

    /**
     * Write the roster for a new review.
     *
     * Transactional and all-or-nothing: a review whose roster is half-written
     * would show some people an obligation and silently drop others, and the
     * requester would have no way to tell. The unique index on
     * (review_id, user_id) makes a duplicate impossible rather than merely
     * unlikely.
     *
     * @param string[] $userIds
     */
    public function insertRoster(int $reviewId, array $userIds): void {
        $now    = time();
        $unique = array_values(array_unique(array_filter(
            $userIds,
            static fn ($u): bool => is_string($u) && $u !== '',
        )));
        if ($unique === []) {
            return;
        }

        $this->db->beginTransaction();
        try {
            foreach ($unique as $userId) {
                $row = new FileReviewer();
                $row->setReviewId($reviewId);
                $row->setUserId($userId);
                $row->setCompletedAt(null);
                $row->setRemark(null);
                $row->setCreatedAt($now);
                $this->insert($row);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return FileReviewer[] in the order they were asked */
    public function findByReview(int $reviewId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('review_id', $qb->createNamedParameter($reviewId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Rosters for many reviews in one query.
     *
     * My Work assembles a page of items and then needs every roster; asking per
     * review would put a query inside a loop, which is the shape this method
     * exists to prevent.
     *
     * @param int[] $reviewIds
     * @return array<int, FileReviewer[]> keyed by review id, in ask order
     */
    public function findByReviewIds(array $reviewIds): array {
        $reviewIds = array_values(array_unique(array_filter(
            array_map(static fn ($i): int => (int)$i, $reviewIds),
            static fn (int $i): bool => $i > 0,
        )));
        if ($reviewIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'review_id',
                $qb->createNamedParameter($reviewIds, IQueryBuilder::PARAM_INT_ARRAY),
            ))
            ->orderBy('id', 'ASC');

        $out = [];
        foreach ($this->findEntities($qb) as $row) {
            $out[$row->getReviewId()][] = $row;
        }

        return $out;
    }

    public function findOne(int $reviewId, string $userId): ?FileReviewer {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('review_id', $qb->createNamedParameter($reviewId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Every review id this user is a reviewer on.
     *
     * `$onlyOutstanding` narrows to the obligations they still owe, which is
     * the leftmost-column scan `th_frevu_user_idx` was built for.
     *
     * @return int[]
     */
    public function findReviewIdsForUser(string $userId, bool $onlyOutstanding = false): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('review_id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        if ($onlyOutstanding) {
            $qb->andWhere($qb->expr()->isNull('completed_at'));
        }

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = (int)$row['review_id'];
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * How many people still owe this review.
     *
     * Zero is what moves the requester's My Work row from "waiting for others"
     * to "action required".
     */
    public function countOutstanding(int $reviewId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('review_id', $qb->createNamedParameter($reviewId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('completed_at'));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Drop one person's rows across a set of reviews.
     *
     * The departure path: the caller resolves the team's review ids and this
     * removes that person's obligations within them. Scoped by review id rather
     * than deleting every row the user has, because leaving one team must not
     * touch the reviews they owe in another.
     *
     * @param int[] $reviewIds
     */
    public function deleteForUserInReviews(string $userId, array $reviewIds): int {
        $reviewIds = array_values(array_unique(array_filter(
            array_map(static fn ($i): int => (int)$i, $reviewIds),
            static fn (int $i): bool => $i > 0,
        )));
        if ($reviewIds === []) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in(
                'review_id',
                $qb->createNamedParameter($reviewIds, IQueryBuilder::PARAM_INT_ARRAY),
            ));

        return $qb->executeStatement();
    }
}
