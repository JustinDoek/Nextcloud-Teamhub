<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_decisions.
 *
 * Conventions:
 *  - Reads return Decision entities or array<Decision>.
 *  - The list() method runs the team-scoped, filtered, sorted, paginated query
 *    that powers the Decisions tab. It is the one place where the COALESCE-based
 *    "primary timestamp" sort lives — see the comment on list() for the
 *    PostgreSQL compatibility note.
 */
class DecisionMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_decisions', Decision::class);
    }

    /**
     * Find a single decision by id.
     * Returns null when not found (callers usually want a 404 not an exception).
     */
    public function findById(int $id): ?Decision {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        try {
            /** @var Decision */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Find the decision attached to a given message, if any.
     * A message has at most one decision row (enforced at the app layer in propose()).
     */
    public function findByMessageId(int $messageId): ?Decision {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        try {
            /** @var Decision */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Batch lookup: return decisions whose message_id is in the given set,
     * keyed by message_id for direct hydration.
     *
     * @param int[] $messageIds
     * @return array<int, Decision>
     */
    public function findByMessageIds(array $messageIds): array {
        if (empty($messageIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'message_id',
                $qb->createNamedParameter($messageIds, IQueryBuilder::PARAM_INT_ARRAY)
            ));
        $rows = $this->findEntities($qb);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->getMessageId()] = $r;
        }
        return $out;
    }

    /**
     * Paginated, filtered, sorted list of decisions for a team.
     *
     * Filters (all optional, all anded):
     *   - status:    list of strings, e.g. ['proposed','decided']
     *   - impact:    list of strings, e.g. ['high']
     *   - category:  list of strings (exact match; null/empty in DB never matches)
     *   - proposedBy: list of user IDs
     *   - q:         substring match on `question` (LIKE %q%) — case-sensitive on
     *                PostgreSQL; case-insensitive on MySQL. Acceptable for v1.
     *
     * Sort:
     *   - 'recent' (default): COALESCE(decided_at, withdrawn_at, created_at) DESC, id DESC
     *     — the "primary timestamp" of the row. Both PostgreSQL and MySQL/MariaDB
     *     support COALESCE on bigint columns; verified working on PG by direct
     *     query test in the schema for 3.65.0. id DESC is the stable tiebreaker.
     *   - 'created': created_at DESC, id DESC
     *
     * Pagination:
     *   - before: a decision id; return rows with id < before (cursor-style).
     *     The cursor uses the id alone, so it is correct only when paired with
     *     a deterministic sort. For sort=recent we cannot fully cursor on the
     *     primary timestamp without exposing it, so we approximate with id <
     *     before. This is correct for the most common "scroll through recent"
     *     case and acceptable for v1.
     *   - limit: max rows (callers cap at 100).
     *
     * @param array{status?:array,impact?:array,category?:array,proposedBy?:array,q?:string} $filters
     * @return Decision[]
     */
    public function list(string $teamId, array $filters, string $sort, ?int $before, int $limit): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));

        if (!empty($filters['status'])) {
            $qb->andWhere($qb->expr()->in(
                'status',
                $qb->createNamedParameter($filters['status'], IQueryBuilder::PARAM_STR_ARRAY)
            ));
        }
        if (!empty($filters['impact'])) {
            $qb->andWhere($qb->expr()->in(
                'impact',
                $qb->createNamedParameter($filters['impact'], IQueryBuilder::PARAM_STR_ARRAY)
            ));
        }
        if (!empty($filters['level'])) {
            // Level filter: include null rows when 'operational' is in the
            // filter set — null means the row predates the field and should
            // be treated as operational.
            $levelParam = $filters['level'];
            if (in_array('operational', $levelParam, true)) {
                $qb->andWhere($qb->expr()->orX(
                    $qb->expr()->in('level', $qb->createNamedParameter($levelParam, IQueryBuilder::PARAM_STR_ARRAY)),
                    $qb->expr()->isNull('level')
                ));
            } else {
                $qb->andWhere($qb->expr()->in(
                    'level',
                    $qb->createNamedParameter($levelParam, IQueryBuilder::PARAM_STR_ARRAY)
                ));
            }
        }
        if (!empty($filters['category'])) {
            $qb->andWhere($qb->expr()->in(
                'category',
                $qb->createNamedParameter($filters['category'], IQueryBuilder::PARAM_STR_ARRAY)
            ));
        }
        if (!empty($filters['proposedBy'])) {
            $qb->andWhere($qb->expr()->in(
                'proposed_by',
                $qb->createNamedParameter($filters['proposedBy'], IQueryBuilder::PARAM_STR_ARRAY)
            ));
        }
        if (!empty($filters['q'])) {
            // LIKE substring match. We deliberately do not lowercase here:
            // PostgreSQL would need ILIKE for case-insensitive, MySQL is CI by
            // default on its standard collations. For v1 we accept the
            // platform-dependent behaviour rather than running LOWER() on a
            // potentially-large text column.
            $like = '%' . $this->db->escapeLikeParameter($filters['q']) . '%';
            $qb->andWhere($qb->expr()->like('question', $qb->createNamedParameter($like)));
        }

        if ($before !== null) {
            $qb->andWhere($qb->expr()->lt('id', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)));
        }

        // Sort.
        if ($sort === 'created') {
            $qb->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC');
        } else {
            // 'recent' (default). COALESCE is supported by both DB engines we
            // target. We construct the expression via createFunction since
            // QueryBuilder has no first-class COALESCE expression.
            $qb->orderBy(
                $qb->createFunction('COALESCE(decided_at, withdrawn_at, created_at)'),
                'DESC'
            )->addOrderBy('id', 'DESC');
        }

        $qb->setMaxResults(max(1, min(100, $limit)));

        return $this->findEntities($qb);
    }

    /**
     * Count decisions in a team. Used for telemetry only.
     */
    public function countByTeam(string $teamId): int {
        $qb = $this->db->getQueryBuilder();
        $r = $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->executeQuery();
        $val = $r->fetchOne();
        $r->closeCursor();
        return (int)$val;
    }

    /**
     * Count decisions in one of the given statuses. Used by the project-health
     * widget's Quality pillar ("open" = status in ['open','finalized']).
     *
     * @param string[] $statuses
     */
    public function countByTeamAndStatus(string $teamId, array $statuses): int {
        if (empty($statuses)) {
            return 0;
        }
        $qb = $this->db->getQueryBuilder();
        $r = $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->in(
                'status',
                $qb->createNamedParameter($statuses, IQueryBuilder::PARAM_STR_ARRAY)
            ))
            ->executeQuery();
        $val = $r->fetchOne();
        $r->closeCursor();
        return (int)$val;
    }

    /**
     * v3.99.7 — decisions in one of the given statuses that are linked to
     * a milestone. Used by the project-health widget's Milestones pillar:
     * if a milestone-linked decision is still open/finalised and today is
     * within N days of the milestone date, that milestone flips to
     * at-risk in the widget. Returns entities so the caller can read
     * milestone_id + status.
     *
     * @param string[] $statuses
     * @return Decision[]
     */
    public function findWithMilestoneByTeamAndStatus(string $teamId, array $statuses): array {
        if (empty($statuses)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->isNotNull('milestone_id'))
            ->andWhere($qb->expr()->in(
                'status',
                $qb->createNamedParameter($statuses, IQueryBuilder::PARAM_STR_ARRAY)
            ));
        return $this->findEntities($qb);
    }

    /**
     * Distinct non-null categories used in a team — for the filter picker.
     *
     * @return string[]
     */
    public function distinctCategoriesByTeam(string $teamId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('category')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->isNotNull('category'))
            ->orderBy('category', 'ASC');
        $r = $qb->executeQuery();
        $out = [];
        while ($row = $r->fetch()) {
            if (!empty($row['category'])) {
                $out[] = (string)$row['category'];
            }
        }
        $r->closeCursor();
        return $out;
    }

    /**
     * Full-text search across question and selected_answer, with team_name join.
     *
     * Used by DecisionSearchProvider for NC unified search.
     * Returns raw associative rows (not entities) — the search provider only
     * needs a subset of columns plus the joined team_name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $term, int $limit = 30, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('d.id', 'd.team_id', 'd.question', 'd.selected_answer',
                     'd.status', 'd.impact', 'd.proposed_by', 'd.created_at')
            ->addSelect($qb->createFunction("COALESCE(cc.display_name, '') AS team_name"))
            ->from('teamhub_decisions', 'd')
            ->leftJoin(
                'd',
                'circles_circle',
                'cc',
                $qb->expr()->eq('d.team_id', 'cc.unique_id')
            )
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->like(
                        $qb->createFunction('LOWER(d.question)'),
                        $qb->createNamedParameter('%' . strtolower($term) . '%')
                    ),
                    $qb->expr()->like(
                        $qb->createFunction('LOWER(COALESCE(d.selected_answer, \'\'))'),
                        $qb->createNamedParameter('%' . strtolower($term) . '%')
                    )
                )
            )
            ->orderBy('d.created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $result = $qb->executeQuery();
        $rows   = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();
        return $rows;
    }
}
