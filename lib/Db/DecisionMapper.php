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
     * Decisions across several teams that could belong in a personal work
     * queue (v4.5.23, My Work Decisions provider).
     *
     * One query across all of a user's teams rather than one per team: a user
     * in fifteen teams would otherwise pay fifteen round trips for a section
     * that is usually empty.
     *
     * `withdrawn` is excluded — nobody owes anything on a withdrawn proposal —
     * and terminal rows older than the Completed window are dropped in SQL so
     * they never cross the wire. The *per-user* relevance test (am I the
     * proposer, am I an approver) stays in the provider, because it depends on
     * category approver sets this mapper has no business knowing about.
     *
     * **Two queries, not one, and each with its own cap (v4.5.25.)** The single
     * query this replaced applied `$limit` to *candidates* and let the provider
     * discard most of them afterwards, so a team's completed decisions — which
     * are only ever relevant to two people — could fill the budget and push out
     * live ones that needed somebody's attention. Splitting them means a busy
     * Completed history cannot starve Action required, and it lets the terminal
     * half be narrowed by `$userId` in SQL, which is the one per-user test this
     * mapper *can* honestly make: `proposed_by` and `resolved_by` are columns.
     *
     * Active rows are ordered **oldest first**: if the cap ever does bite, the
     * decision that has been waiting longest is the one to keep.
     *
     * @param string[]  $teamIds
     * @param string    $userId         whose queue this is, for the terminal half
     * @param int       $completedSince earliest `decided_at` still worth showing
     * @param bool|null $truncated      set true when either half hit its cap.
     *                                  Out-parameter because the caller cannot
     *                                  infer it from a merged row count, and
     *                                  guessing would put a "some items may be
     *                                  missing" warning on a complete list.
     * @return Decision[]
     */
    public function findForWorkQueue(
        array $teamIds,
        string $userId,
        int $completedSince,
        int $limit,
        ?bool &$truncated = null,
    ): array {
        $truncated = false;
        if ($teamIds === []) {
            return [];
        }
        $cap = max(1, $limit);

        // Live decisions. Every one is a candidate for somebody, and approver
        // sets are not columns, so this half cannot be narrowed further here.
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('team_id',
                $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
                ['open', 'finalized'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('created_at', 'ASC')
            ->setMaxResults($cap);
        $active = $this->findEntities($qb);

        // Terminal decisions, which only ever concern the person who proposed
        // one or the person who resolved it.
        $tb = $this->db->getQueryBuilder();
        $tb->select('*')
            ->from($this->getTableName())
            ->where($tb->expr()->in('team_id',
                $tb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($tb->expr()->in('status', $tb->createNamedParameter(
                ['approved', 'denied'], IQueryBuilder::PARAM_STR_ARRAY)))
            // v4.5.25 — resolved_at is when it was approved or denied;
            // decided_at is when it was *finalized* and is not overwritten by
            // either. Rows written before 4.5.25 have no resolved_at, so fall
            // back to decided_at for them — which is why this is an OR and not
            // a COALESCE: a NULL comparison would silently drop every legacy
            // row, and COALESCE on a nullable BIGINT is the sort of thing that
            // behaves differently on MySQL and Postgres.
            ->andWhere($tb->expr()->orX(
                $tb->expr()->gte('resolved_at', $tb->createNamedParameter(
                    $completedSince, IQueryBuilder::PARAM_INT)),
                $tb->expr()->andX(
                    $tb->expr()->isNull('resolved_at'),
                    $tb->expr()->gte('decided_at', $tb->createNamedParameter(
                        $completedSince, IQueryBuilder::PARAM_INT)),
                ),
            ))
            ->andWhere($tb->expr()->orX(
                $tb->expr()->eq('proposed_by', $tb->createNamedParameter($userId)),
                $tb->expr()->eq('resolved_by', $tb->createNamedParameter($userId)),
            ))
            ->orderBy('decided_at', 'DESC')
            ->setMaxResults($cap);
        $terminal = $this->findEntities($tb);


        $truncated = count($active) >= $cap || count($terminal) >= $cap;

        return array_merge($active, $terminal);
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
     * Open proposals in these teams that have a Talk conversation attached
     * (v4.5.45).
     *
     * Narrow by design: What's New calls this once per request to learn which
     * proposal rooms it should also read, so it must not return the whole
     * table. `talk_token IS NOT NULL` is the selective half — most decisions
     * never get one.
     *
     * Both spellings of "open" are matched. HANDOFF's 4.5.30 note stands: if
     * you touch decision status anywhere, grep for `proposed` too.
     *
     * @param string[] $teamIds
     * @return Decision[]
     */
    public function findOpenSharedProposals(array $teamIds): array {
        if (empty($teamIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'team_id',
                $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY)
            ))
            ->andWhere($qb->expr()->in(
                'status',
                $qb->createNamedParameter(['open', 'proposed'], IQueryBuilder::PARAM_STR_ARRAY)
            ))
            ->andWhere($qb->expr()->isNotNull('talk_token'))
            ->andWhere($qb->expr()->neq('talk_token', $qb->createNamedParameter('')));

        return $this->findEntities($qb);
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
     * One team's **approved, public** decisions whose question or answer
     * contains the words given (v4.11.12) — the desk's picker when it links a
     * decision into a service request's conversation.
     *
     * Both narrowings are the product rule, not shortcuts (Justin,
     * 2026-09-30): only an **approved** decision answers a request, and only a
     * **public** one opens for the requester who reads that conversation.
     * `decided` is the pre-4.5.x spelling of `approved` and counts as one.
     *
     * `is_public` is compared against the integer 1: the column is a
     * SMALLINT, and comparing it against a boolean is exactly the
     * MariaDB/Postgres difference the migrations skill warns about.
     *
     * @return Decision[]
     */
    public function searchPublicInTeam(string $teamId, string $term, int $limit = 4): array {
        $qb     = $this->db->getQueryBuilder();
        $needle = '%' . mb_strtolower($term) . '%';
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('is_public', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['approved', 'decided'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults(max(1, min(50, $limit)));
        if ($term !== '') {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->like(
                    $qb->createFunction('LOWER(question)'),
                    $qb->createNamedParameter($needle)
                ),
                $qb->expr()->like(
                    $qb->createFunction('LOWER(COALESCE(selected_answer, \'\'))'),
                    $qb->createNamedParameter($needle)
                )
            ));
        }
        return $this->findEntities($qb);
    }

    /**
     * Several decisions by id in one query (v4.11.12) — the links on a page
     * of the desk's request grid, which would otherwise be a lookup per row.
     *
     * @param int[] $ids
     * @return array<int, Decision> keyed by id
     */
    public function findByIds(array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        $out = [];
        foreach ($this->findEntities($qb) as $decision) {
            $out[(int)$decision->getId()] = $decision;
        }
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

    /**
     * The decision that supersedes this one, if any (v4.11.18).
     *
     * `supersedes_id` lives on the *newer* decision — one column, one
     * direction, no second answer to disagree with it — so reading "what
     * replaced this" is a lookup by that column rather than a field on the
     * row being asked about. Served by `teamhub_dec_super_idx`, which
     * v3.64 already put there.
     *
     * Called from `DecisionService::get()` and not from `serialize()`: the
     * detail panel is the only surface that shows the successor, and doing
     * it per row would be a query per row in every list (the reasoning
     * DESIGN §2.79 recorded for the proposal body).
     */
    public function findBySupersedesId(int $decisionId): ?Decision {
        if ($decisionId <= 0) {
            return null;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('supersedes_id', $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT)))
            // Oldest first: if an earlier version ever allowed two, the one
            // that claimed it first is the honest answer.
            ->orderBy('id', 'ASC')
            ->setMaxResults(1);

        $rows = $this->findEntities($qb);
        return $rows[0] ?? null;
    }

    /**
     * Approved decisions whose review has come within the notice window and
     * that have not had a notice written for *this* cycle (v4.11.18).
     *
     * The second condition is `review_notified_at <> review_due_at` rather
     * than "is null", because the column stores the cycle the notice was
     * written for, not the moment of writing: completing a review moves
     * `review_due_at`, the stored value stops matching, and the next cycle
     * gets its own notice without anybody clearing a flag.
     *
     * Served by `th_dec_rev_due_idx` (status, review_due_at). Instance-wide
     * and not team-scoped — it is the daily pass, and the teams it should
     * touch are exactly the teams that have a decision falling due.
     *
     * @return Decision[]
     */
    public function findReviewDue(int $noticeCutoff, int $limit = 500): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('approved')))
            ->andWhere($qb->expr()->isNotNull('review_due_at'))
            ->andWhere($qb->expr()->lte('review_due_at', $qb->createNamedParameter($noticeCutoff, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('review_notified_at'),
                $qb->expr()->neq('review_notified_at', 'review_due_at'),
            ))
            ->orderBy('review_due_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * Every approved decision of one category in one team (v4.11.18) — what
     * a change to the category's review period has to recompute.
     *
     * `category` is a name on this table rather than an id, so the match is
     * on the name. Case-sensitive, like `DecisionService::assertApproverFor`
     * and for the same reason: names are stored case-preserved and two
     * categories differing only in case are two categories.
     *
     * @return Decision[]
     */
    public function findApprovedByTeamCategory(string $teamId, string $category, int $limit = 1000): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('category', $qb->createNamedParameter($category)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('approved')))
            ->orderBy('id', 'ASC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }
}
