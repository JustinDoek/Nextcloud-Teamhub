<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_file_review (v4.8.18).
 *
 * Every read here is narrowed by something the caller already had to be
 * authorised for — a team id, a file the caller can open, or the caller's own
 * user id. There is no "all reviews" query, because there is no caller entitled
 * to that answer.
 *
 * @extends QBMapper<FileReview>
 */
class FileReviewMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_file_review', FileReview::class);
    }

    /**
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function findById(int $id): FileReview {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * @param int[] $ids
     * @return FileReview[] keyed by id
     */
    public function findByIds(array $ids): array {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($i): int => (int)$i, $ids),
            static fn (int $i): bool => $i > 0,
        )));
        if ($ids === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

        $out = [];
        foreach ($this->findEntities($qb) as $review) {
            $out[$review->getId()] = $review;
        }

        return $out;
    }

    /**
     * One team's reviews, newest first.
     *
     * @return FileReview[]
     */
    public function findByTeam(string $teamId, ?string $status = null, int $limit = 100, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));

        if ($status !== null) {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
        }

        $qb->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);
    }

    /**
     * Open reviews on one file, across every team.
     *
     * The caller narrows by team afterwards where it matters. This is the
     * modal's "a review is already open on this file" warning, and it is a
     * warning rather than a block, so over-returning here is harmless and
     * under-returning would silently drop the warning.
     *
     * @return FileReview[]
     */
    public function findOpenByFile(int $fileId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(FileReview::STATUS_OPEN)))
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Reviews this user requested, restricted to teams they may still see.
     *
     * `$teamIds` is the caller's resolved team set and is applied here rather
     * than trusted to a later filter: a user who has left a team must stop
     * seeing its rows even though `requested_by` still names them.
     *
     * @param string[] $teamIds
     * @return FileReview[]
     */
    public function findByRequester(string $userId, array $teamIds, ?string $status = null): array {
        $teamIds = array_values(array_unique(array_filter(
            $teamIds,
            static fn ($t): bool => is_string($t) && $t !== '',
        )));
        if ($teamIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('requested_by', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in(
                'team_id',
                $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY),
            ));

        if ($status !== null) {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
        }

        $qb->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Ids of a team's reviews, so a cleanup can then reach the reviewer rows.
     *
     * Scoping the reviewer delete by these ids, rather than driving it off a
     * join, keeps it portable across the databases TeamHub supports.
     *
     * @return int[]
     */
    public function findIdsByTeam(string $teamId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = (int)$row['id'];
        }
        $result->closeCursor();

        return $out;
    }
}
