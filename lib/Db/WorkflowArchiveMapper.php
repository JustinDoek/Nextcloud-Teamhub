<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `teamhub_wf_archive` (WorkflowHub phase 6, v4.10.21).
 *
 * Column names as `Version000410021Date20260922010000` wrote them. There
 * is **no update path**, deliberately: the archive record is written once
 * by `WorkflowArchiveService::record()` inside the transaction that ends
 * the workflow, and an archive that could be edited would not be one. The
 * single delete exists for the retention pass a later phase will add and
 * for `WorkflowEngine::purge()`, which must be able to remove everything
 * belonging to an instance.
 *
 * @extends QBMapper<WorkflowArchive>
 */
class WorkflowArchiveMapper extends QBMapper {

    public const TABLE = 'teamhub_wf_archive';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, WorkflowArchive::class);
    }

    public function findById(int $id): ?WorkflowArchive {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->firstOrNull($qb);
    }

    /**
     * The archive of one workflow instance, or null when it was never
     * archived — an unlicensed completion, or a workflow still running.
     * Also the idempotence check: `record()` refuses a second row.
     */
    public function findByInstance(int $instanceId): ?WorkflowArchive {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('instance_id', $qb->createNamedParameter($instanceId, IQueryBuilder::PARAM_INT)));
        return $this->firstOrNull($qb);
    }

    /** One archive by the reference people quote. */
    public function findByReference(string $reference): ?WorkflowArchive {
        if ($reference === '') {
            return null;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('ref_number', $qb->createNamedParameter($reference)));
        return $this->firstOrNull($qb);
    }

    /**
     * Archives by id, most recently completed first — the second half of a
     * search, after the projection rows have said which ids are in scope.
     *
     * @param int[] $ids
     * @return array<int, WorkflowArchive> keyed by id
     */
    public function findByIds(array $ids): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('completed_at', 'DESC')
            ->addOrderBy('id', 'DESC');
        $out = [];
        foreach ($this->findEntities($qb) as $row) {
            $out[(int)$row->getId()] = $row;
        }
        return $out;
    }

    /**
     * How many archives one team holds, per audience — the counter a
     * navigation entry needs without loading a page of rows.
     */
    public function countForTeam(string $teamId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'n'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
        $result = $qb->executeQuery();
        $n      = (int)$result->fetchOne();
        $result->closeCursor();
        return $n;
    }

    /**
     * Records whose retention window has passed and that are not on legal
     * hold. **Nothing calls this yet** — the retention pass is deferred
     * (`docs/workflow-archiving.md` § Deferred). It is here so the column
     * and the index it needs are proven by a query rather than asserted.
     *
     * @return WorkflowArchive[]
     */
    public function findExpired(int $now, int $limit = 100): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->gt('retention_until', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lte('retention_until', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('legal_hold', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->orderBy('retention_until', 'ASC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    /**
     * Remove the archive of one instance. Idempotent; returns the rows
     * removed. Used by `WorkflowEngine::purge()` — a licence that lapses
     * while a workflow runs means an ended workflow is purged after all,
     * and an archive row pointing at rows that no longer exist would be
     * worse than no archive.
     */
    public function deleteByInstance(int $instanceId): int {
        $qb = $this->db->getQueryBuilder();
        return $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('instance_id', $qb->createNamedParameter($instanceId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    private function firstOrNull(IQueryBuilder $qb): ?WorkflowArchive {
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }
}
