<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `teamhub_wf_archive_view` — the archive projections
 * (WorkflowHub phase 6, v4.10.21).
 *
 * Insert and delete only, like the archive itself: a projection is written
 * with its record and removed with it.
 *
 * `search()` is the one interesting query. It answers *which archives are
 * in scope* — never *what is in them* — and it is given the scope by the
 * caller, as an explicit list of (audience, team) pairs
 * `WorkflowArchiveService` has already resolved from the viewer's live
 * roles. Passing the scope in rather than the uid is deliberate: a mapper
 * that took a uid would be a mapper that decides permissions, and the
 * decision would then live in two places.
 *
 * @extends QBMapper<WorkflowArchiveView>
 */
class WorkflowArchiveViewMapper extends QBMapper {

    public const TABLE = 'teamhub_wf_archive_view';

    /** The most rows one search may return, whatever the caller asks for. */
    public const MAX_LIMIT = 100;

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, WorkflowArchiveView::class);
    }

    /**
     * The projections of one archive — at most two.
     *
     * @return WorkflowArchiveView[]
     */
    public function findByArchive(int $archiveId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('archive_id', $qb->createNamedParameter($archiveId, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    /**
     * Search one viewer's archive scope.
     *
     * @param array<int, array{0: string, 1: string}> $scope (audience, teamId) pairs the
     *        caller has established this viewer may read at all. An empty
     *        scope returns nothing rather than everything — the difference
     *        between "no archives" and "every archive" must not depend on
     *        a falsy value reaching a query builder.
     * @param array{
     *     q?: string, outcome?: string, definitionKey?: string, serviceKey?: string,
     *     from?: int, to?: int, limit?: int, offset?: int
     * } $filters
     * @return WorkflowArchiveView[] most recently completed first
     */
    public function search(array $scope, array $filters = []): array {
        if ($scope === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName());

        $scopeExpr = [];
        foreach ($scope as [$audience, $teamId]) {
            $scopeExpr[] = $qb->expr()->andX(
                $qb->expr()->eq('audience', $qb->createNamedParameter($audience)),
                $qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)),
            );
        }
        $qb->where($qb->expr()->orX(...$scopeExpr));

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->like(
                    'search_text',
                    $qb->createNamedParameter('%' . $this->db->escapeLikeParameter(mb_strtolower($q)) . '%'),
                ),
                // A reference is quoted as a whole and in its own case;
                // matching it exactly is what somebody pasting "WF-2026-42"
                // into the box means, and it costs one indexed lookup.
                $qb->expr()->eq('ref_number', $qb->createNamedParameter(mb_strtoupper($q))),
            ));
        }
        foreach (['outcome' => 'outcome', 'definitionKey' => 'definition_key', 'serviceKey' => 'service_key'] as $key => $column) {
            $value = trim((string)($filters[$key] ?? ''));
            if ($value !== '') {
                $qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value)));
            }
        }
        if ((int)($filters['from'] ?? 0) > 0) {
            $qb->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter((int)$filters['from'], IQueryBuilder::PARAM_INT)));
        }
        if ((int)($filters['to'] ?? 0) > 0) {
            $qb->andWhere($qb->expr()->lte('completed_at', $qb->createNamedParameter((int)$filters['to'], IQueryBuilder::PARAM_INT)));
        }

        $limit = (int)($filters['limit'] ?? 25);
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $qb->orderBy('completed_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult(max(0, (int)($filters['offset'] ?? 0)));

        return $this->findEntities($qb);
    }

    public function deleteByArchive(int $archiveId): int {
        $qb = $this->db->getQueryBuilder();
        return $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('archive_id', $qb->createNamedParameter($archiveId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }
}
