<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `teamhub_wf_attachment` (WorkflowHub phase 6, v4.10.21).
 *
 * No update path: a document's classification is decided when it is
 * attached and never changes, so the only writes are an insert, a delete
 * of one row while the workflow is still open, and the delete of an
 * instance's rows that goes with `WorkflowEngine::purge()`.
 *
 * @extends QBMapper<WorkflowAttachment>
 */
class WorkflowAttachmentMapper extends QBMapper {

    public const TABLE = 'teamhub_wf_attachment';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, WorkflowAttachment::class);
    }

    public function findById(int $id): ?WorkflowAttachment {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Every document on one workflow, oldest first.
     *
     * Deliberately **unfiltered**: the visibility filter belongs to the
     * audience that renders the list, not to the read. A mapper that
     * filtered would have to be told who is asking, and the one place that
     * question is answered would stop being the one place.
     *
     * @return WorkflowAttachment[]
     */
    public function findByInstance(int $instanceId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('instance_id', $qb->createNamedParameter($instanceId, IQueryBuilder::PARAM_INT)))
            ->orderBy('added_at', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * The documents of many workflows in one query, keyed by instance —
     * what a page of archive rows needs instead of a query per row.
     *
     * @param int[] $instanceIds
     * @return array<int, WorkflowAttachment[]>
     */
    public function findByInstances(array $instanceIds): array {
        $instanceIds = array_values(array_unique(array_map('intval', $instanceIds)));
        if ($instanceIds === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('instance_id', $qb->createNamedParameter($instanceIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('added_at', 'ASC')
            ->addOrderBy('id', 'ASC');
        $out = [];
        foreach ($this->findEntities($qb) as $row) {
            $out[$row->getInstanceId()][] = $row;
        }
        return $out;
    }

    public function findByFile(int $instanceId, int $fileId): ?WorkflowAttachment {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('instance_id', $qb->createNamedParameter($instanceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function deleteById(int $id): int {
        $qb = $this->db->getQueryBuilder();
        return $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * The shares TeamHub made that are past their date and not yet removed
     * (v4.10.38) — the daily job's list (`th_wfat_share_idx`).
     *
     * @return WorkflowAttachment[]
     */
    public function findSharesDue(int $now): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->isNull('unshared_at'))
            ->andWhere($qb->expr()->isNotNull('share_until'))
            ->andWhere($qb->expr()->lt('share_until', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(500);
        return $this->findEntities($qb);
    }

    /** Part of `WorkflowEngine::purge()`: an unlicensed ending takes the documents with it. */
    public function deleteByInstance(int $instanceId): int {
        $qb = $this->db->getQueryBuilder();
        return $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('instance_id', $qb->createNamedParameter($instanceId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }
}
