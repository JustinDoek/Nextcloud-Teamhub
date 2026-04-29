<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\IDBConnection;

/**
 * AuditLogMapper — read + insert + bulk-purge only.
 *
 * IMMUTABILITY CONTRACT
 * =====================
 * This mapper deliberately does NOT expose:
 *   - update of an existing row
 *   - delete of a single row by id
 * The only mutation paths are:
 *   - insert() — append a new event
 *   - purgeOlderThan() — bulk-delete rows older than a retention boundary
 *
 * Any code that needs to modify a single audit row should not exist.
 * If a future requirement seems to need it, treat it as a red flag and
 * add a *new* event to the log instead.
 *
 * No column mapping to an entity object — TeamHub's existing mappers use
 * raw arrays and direct QueryBuilder for simplicity, and we follow that.
 */
class AuditLogMapper {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // Insert (the ONLY mutation path other than purgeOlderThan).
    // -------------------------------------------------------------------------

    /**
     * Insert a single audit event.
     *
     * @param string      $teamId      Team unique_id.
     * @param string      $eventType   Namespaced event string (e.g. 'member.joined').
     * @param string|null $actorUid    UID of the human who triggered the event, or null for system events.
     * @param string|null $targetType  'team' | 'member' | 'file' | 'share' | 'invite' | 'app' (or null).
     * @param string|null $targetId    UID, fileid, shareid, app id, etc.
     * @param array|null  $metadata    Optional structured payload — encoded to JSON before insertion.
     *
     * @return int New row id.
     */
    public function insert(
        string $teamId,
        string $eventType,
        ?string $actorUid,
        ?string $targetType,
        ?string $targetId,
        ?array $metadata = null,
    ): int {
        return $this->insertWithTimestamp($teamId, $eventType, $actorUid, $targetType, $targetId, $metadata, time());
    }

    /**
     * Insert with an explicit created_at timestamp.
     *
     * Used by the AuditIngestionService when mirroring oc_activity rows so the
     * mirrored audit row keeps the original event's chronology rather than the
     * (much later) ingestion time.
     *
     * This preserves the immutability contract: it is still an INSERT-only path,
     * just one that does not implicitly use time(). No existing rows are touched.
     */
    public function insertWithTimestamp(
        string $teamId,
        string $eventType,
        ?string $actorUid,
        ?string $targetType,
        ?string $targetId,
        ?array $metadata,
        int $createdAt,
    ): int {
        $metadataJson = null;
        if ($metadata !== null) {
            // JSON_UNESCAPED_SLASHES keeps file paths readable in the export.
            $encoded = json_encode($metadata, JSON_UNESCAPED_SLASHES);
            $metadataJson = ($encoded === false) ? null : $encoded;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->insert('teamhub_audit_log')
            ->values([
                'team_id'     => $qb->createNamedParameter($teamId),
                'event_type'  => $qb->createNamedParameter($eventType),
                'actor_uid'   => $qb->createNamedParameter($actorUid),
                'target_type' => $qb->createNamedParameter($targetType),
                'target_id'   => $qb->createNamedParameter($targetId),
                'metadata'    => $qb->createNamedParameter($metadataJson),
                'created_at'  => $qb->createNamedParameter($createdAt, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
            ])
            ->executeStatement();

        return (int)$this->db->lastInsertId('*PREFIX*teamhub_audit_log');
    }

    // -------------------------------------------------------------------------
    // Reads.
    // -------------------------------------------------------------------------

    /**
     * Paginated read of events for a team, with optional filters.
     *
     * @param string      $teamId
     * @param int         $offset
     * @param int         $limit       Capped by caller.
     * @param string[]    $eventTypes  Empty array = no filter.
     * @param int|null    $fromTs      Unix seconds — inclusive lower bound.
     * @param int|null    $toTs        Unix seconds — inclusive upper bound.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findByTeam(
        string $teamId,
        int $offset,
        int $limit,
        array $eventTypes = [],
        ?int $fromTs = null,
        ?int $toTs = null,
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_audit_log')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if (!empty($eventTypes)) {
            $qb->andWhere($qb->expr()->in(
                'event_type',
                $qb->createNamedParameter(
                    $eventTypes,
                    \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY
                )
            ));
        }
        if ($fromTs !== null) {
            $qb->andWhere($qb->expr()->gte(
                'created_at',
                $qb->createNamedParameter($fromTs, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
            ));
        }
        if ($toTs !== null) {
            $qb->andWhere($qb->expr()->lte(
                'created_at',
                $qb->createNamedParameter($toTs, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
            ));
        }

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = $this->hydrate($row);
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Count total events for a team (used by NcPagination on the frontend).
     */
    public function countByTeam(
        string $teamId,
        array $eventTypes = [],
        ?int $fromTs = null,
        ?int $toTs = null,
    ): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) AS cnt'))
            ->from('teamhub_audit_log')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));

        if (!empty($eventTypes)) {
            $qb->andWhere($qb->expr()->in(
                'event_type',
                $qb->createNamedParameter(
                    $eventTypes,
                    \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY
                )
            ));
        }
        if ($fromTs !== null) {
            $qb->andWhere($qb->expr()->gte(
                'created_at',
                $qb->createNamedParameter($fromTs, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
            ));
        }
        if ($toTs !== null) {
            $qb->andWhere($qb->expr()->lte(
                'created_at',
                $qb->createNamedParameter($toTs, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
            ));
        }

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Per-team summary used by the audit-tab team picker:
     *   [['team_id' => '...', 'event_count' => N, 'last_event_at' => ts], ...]
     */
    public function summarize(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(
                'team_id',
                $qb->createFunction('COUNT(*) AS event_count'),
                $qb->createFunction('MAX(created_at) AS last_event_at'),
            )
            ->from('teamhub_audit_log')
            ->groupBy('team_id');

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = [
                'team_id'       => (string)$row['team_id'],
                'event_count'   => (int)$row['event_count'],
                'last_event_at' => (int)$row['last_event_at'],
            ];
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Streamed read for export — no offset/limit, ordered ascending by time
     * so the JSON file reads chronologically.
     *
     * @return \Generator<int,array<string,mixed>>
     */
    public function streamByTeam(string $teamId): \Generator {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_audit_log')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC');

        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            yield $this->hydrate($row);
        }
        $result->closeCursor();
    }

    // -------------------------------------------------------------------------
    // Bulk purge — the only DELETE path in the entire mapper.
    // -------------------------------------------------------------------------

    /**
     * Delete all rows older than the given unix-seconds boundary.
     * Returns the number of rows deleted.
     */
    public function purgeOlderThan(int $boundaryTs): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('teamhub_audit_log')
            ->where($qb->expr()->lt(
                'created_at',
                $qb->createNamedParameter($boundaryTs, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
            ));
        return (int)$qb->executeStatement();
    }

    // -------------------------------------------------------------------------
    // Helpers.
    // -------------------------------------------------------------------------

    /**
     * Decode a DB row into the canonical array shape used by services and the API.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array {
        $metadata = null;
        if (!empty($row['metadata'])) {
            $decoded = json_decode((string)$row['metadata'], true);
            $metadata = is_array($decoded) ? $decoded : null;
        }

        return [
            'id'          => (int)$row['id'],
            'team_id'     => (string)$row['team_id'],
            'event_type'  => (string)$row['event_type'],
            'actor_uid'   => $row['actor_uid'] !== null ? (string)$row['actor_uid'] : null,
            'target_type' => $row['target_type'] !== null ? (string)$row['target_type'] : null,
            'target_id'   => $row['target_id'] !== null ? (string)$row['target_id'] : null,
            'metadata'    => $metadata,
            'created_at'  => (int)$row['created_at'],
        ];
    }
}
