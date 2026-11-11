<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * `teamhub_audit_anchor` — the chain over the audit trail (v4.13.17,
 * DESIGN §2.191).
 *
 * Append and read only, for the same reason {@see AuditLogMapper} is: an anchor
 * that can be rewritten proves nothing. There is deliberately **no** update and
 * **no** single-row delete. The one bulk removal is
 * {@see purgeCoveredBefore()}, which the retention job uses to drop anchors
 * whose rows it has itself deleted — and that removal is immediately preceded
 * by a `purge` anchor recording what went, so the chain states what happened
 * rather than losing it.
 */
class AuditAnchorMapper {

    public const KIND_SEGMENT = 'segment';
    public const KIND_PURGE   = 'purge';

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    /**
     * Append an anchor.
     *
     * @return int the new anchor's id
     */
    public function insert(
        int    $fromId,
        int    $toId,
        int    $rowCount,
        string $rowsHash,
        string $chainHash,
        string $kind,
        string $keyFingerprint,
        int    $createdAt,
    ): int {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('teamhub_audit_anchor')
            ->values([
                'from_id'         => $qb->createNamedParameter($fromId, IQueryBuilder::PARAM_INT),
                'to_id'           => $qb->createNamedParameter($toId, IQueryBuilder::PARAM_INT),
                'row_count'       => $qb->createNamedParameter($rowCount, IQueryBuilder::PARAM_INT),
                'rows_hash'       => $qb->createNamedParameter($rowsHash),
                'chain_hash'      => $qb->createNamedParameter($chainHash),
                'kind'            => $qb->createNamedParameter($kind),
                'key_fingerprint' => $qb->createNamedParameter($keyFingerprint),
                'created_at'      => $qb->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
            ])
            ->executeStatement();

        return (int)$this->db->lastInsertId('*PREFIX*teamhub_audit_anchor');
    }

    /** The most recent anchor, or null when none has been written yet. */
    public function latest(): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_audit_anchor')
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);

        $r = $qb->executeQuery();
        $row = $r->fetch();
        $r->closeCursor();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Every anchor in id order — the order the chain is verified in.
     *
     * Unpaginated on purpose: one anchor an hour is 8,760 a year, and the
     * verifier has to walk all of them to say anything about the chain. The
     * rows are small; the ranges they point at are what costs, and the caller
     * caps that.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_audit_anchor')
            ->orderBy('id', 'ASC');

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = $this->hydrate($row);
        }
        $result->closeCursor();

        return $rows;
    }

    public function count(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))->from('teamhub_audit_anchor');
        $r = $qb->executeQuery();
        $n = $r->fetchOne();
        $r->closeCursor();

        return (int)$n;
    }

    /**
     * Drop every segment anchor that overlaps the purged region — anything
     * starting at or below $boundaryId.
     *
     * Only ever called by the retention purge, which appends a `purge` anchor
     * recording what it removed. **Overlapping, not merely covered**, and that
     * is the whole subtlety: a retention boundary is a timestamp and an anchor
     * is an id range, so the boundary almost always lands *inside* a range. An
     * anchor left half-emptied can never reconcile again — its row count is
     * permanently short — so leaving it would make every instance's chain read
     * as tampered with within an hour of the first purge, which is the one
     * outcome that would make the whole feature worthless.
     *
     * The rows that survived inside a half-purged range are not left
     * unprovable: the next anchor pass starts at the purge anchor's `to_id + 1`
     * and anchors them again.
     *
     * A `purge` anchor is never deleted. The record of what an instance removed
     * outlives the rows.
     */
    public function purgeOverlapping(int $boundaryId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('teamhub_audit_anchor')
            ->where($qb->expr()->lte('from_id', $qb->createNamedParameter($boundaryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->neq('kind', $qb->createNamedParameter(self::KIND_PURGE)));

        return (int)$qb->executeStatement();
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): array {
        return [
            'id'             => (int)$row['id'],
            'fromId'         => (int)$row['from_id'],
            'toId'           => (int)$row['to_id'],
            'rowCount'       => (int)$row['row_count'],
            'rowsHash'       => (string)$row['rows_hash'],
            'chainHash'      => (string)$row['chain_hash'],
            'kind'           => (string)$row['kind'],
            'keyFingerprint' => (string)$row['key_fingerprint'],
            'createdAt'      => (int)$row['created_at'],
        ];
    }
}
