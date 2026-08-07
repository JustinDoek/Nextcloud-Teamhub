<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Raw QueryBuilder mapper for the two bulk-import tables (v4.6.6).
 *
 * Same lightweight shape as {@see TeamTypeMapper}: no QBMapper entities, plain
 * arrays out. The rows are short-lived bookkeeping for one admin operation —
 * nothing else in the app reads them, and an entity class per table would be
 * two more files for no reader.
 *
 * Every value is bound through `createNamedParameter()`; there is no string
 * concatenation into SQL anywhere in this file.
 */
class TeamImportMapper {

    public function __construct(private IDBConnection $db) {}

    // -------------------------------------------------------------------------
    // Imports
    // -------------------------------------------------------------------------

    /** @return int The new import's id. */
    public function createImport(string $createdBy, string $filename, int $totalRows): int {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('teamhub_team_imports')
            ->values([
                'created_by'    => $qb->createNamedParameter($createdBy),
                'filename'      => $qb->createNamedParameter(mb_substr($filename, 0, 255)),
                'status'        => $qb->createNamedParameter('validated'),
                'total_rows'    => $qb->createNamedParameter($totalRows, IQueryBuilder::PARAM_INT),
                'created_count' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                'skipped_count' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                'failed_count'  => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                'created_at'    => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            ]);
        $qb->executeStatement();

        // '*PREFIX*' form matches every other mapper here — Postgres needs the
        // real table name to resolve the sequence, and NC expands the token.
        return (int)$this->db->lastInsertId('*PREFIX*teamhub_team_imports');
    }

    /** @return array<string,mixed>|null */
    public function findImport(int $importId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_team_imports')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($importId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $this->hydrateImport($row);
    }

    /**
     * Recent runs, newest first. Used by the admin panel's history list and
     * bounded there — an admin who has run fifty imports does not want fifty
     * rows, they want the last handful.
     *
     * @return list<array<string,mixed>>
     */
    public function findRecentImports(int $limit = 10): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_team_imports')
            ->orderBy('id', 'DESC')
            ->setMaxResults(max(1, min(50, $limit)));
        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = $this->hydrateImport($row);
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Imports the sweeper should adopt: still `running`, but whose heartbeat
     * has gone quiet (or was never written, which is the same thing — a run
     * that flipped to `running` and whose pump never made a single call).
     *
     * @return list<array<string,mixed>>
     */
    public function findStalledRunning(int $olderThan, int $limit = 5): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_team_imports')
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('running')))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('heartbeat_at'),
                $qb->expr()->lt('heartbeat_at', $qb->createNamedParameter($olderThan, IQueryBuilder::PARAM_INT)),
            ))
            ->orderBy('id', 'ASC')
            ->setMaxResults(max(1, $limit));
        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = $this->hydrateImport($row);
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Ids of runs that reached a terminal state before $before. Used by the
     * pruner; ids only, because the caller deletes rather than reads.
     *
     * @return list<int>
     */
    public function findFinishedBefore(int $before, int $limit = 50): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('teamhub_team_imports')
            ->where($qb->expr()->in('status', $qb->createNamedParameter(
                ['completed', 'cancelled'],
                IQueryBuilder::PARAM_STR_ARRAY,
            )))
            ->andWhere($qb->expr()->isNotNull('finished_at'))
            ->andWhere($qb->expr()->lt('finished_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(max(1, $limit));
        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = (int)$row['id'];
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Flip a validated import to running. Conditional on the current status so
     * two clicks on "Create N teams" cannot both start it — the second one
     * updates zero rows and the caller reports the run as already started.
     *
     * @return bool Whether this call is the one that started it.
     */
    public function startImport(int $importId): bool {
        $now = time();
        $qb  = $this->db->getQueryBuilder();
        $affected = $qb->update('teamhub_team_imports')
            ->set('status',       $qb->createNamedParameter('running'))
            ->set('started_at',   $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->set('heartbeat_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($importId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('validated')))
            ->executeStatement();

        return $affected > 0;
    }

    public function touchHeartbeat(int $importId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('teamhub_team_imports')
            ->set('heartbeat_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($importId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * Write the counters back after a chunk. Recomputed from the row table by
     * the caller rather than incremented here, so a chunk that is retried
     * after a crash cannot double-count.
     */
    public function updateCounters(int $importId, int $created, int $skipped, int $failed): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('teamhub_team_imports')
            ->set('created_count', $qb->createNamedParameter($created, IQueryBuilder::PARAM_INT))
            ->set('skipped_count', $qb->createNamedParameter($skipped, IQueryBuilder::PARAM_INT))
            ->set('failed_count',  $qb->createNamedParameter($failed,  IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($importId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    public function finishImport(int $importId, string $status): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('teamhub_team_imports')
            ->set('status',      $qb->createNamedParameter($status))
            ->set('finished_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($importId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    /** Deletes the run and its rows. Rows first, so a failure never orphans them. */
    public function deleteImport(int $importId): void {
        $rowQb = $this->db->getQueryBuilder();
        $rowQb->delete('teamhub_team_import_rows')
            ->where($rowQb->expr()->eq('import_id', $rowQb->createNamedParameter($importId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        $impQb = $this->db->getQueryBuilder();
        $impQb->delete('teamhub_team_imports')
            ->where($impQb->expr()->eq('id', $impQb->createNamedParameter($importId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    // -------------------------------------------------------------------------
    // Rows
    // -------------------------------------------------------------------------

    /**
     * Insert one row. Called once per CSV line at validation time.
     *
     * @param array<string,mixed>|null $payload Normalised row, stored as JSON.
     */
    public function insertRow(
        int     $importId,
        int     $rowNum,
        ?array  $payload,
        string  $status,
        ?string $message,
    ): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('teamhub_team_import_rows')
            ->values([
                'import_id' => $qb->createNamedParameter($importId, IQueryBuilder::PARAM_INT),
                'row_num'   => $qb->createNamedParameter($rowNum, IQueryBuilder::PARAM_INT),
                'payload'   => $qb->createNamedParameter(
                    $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE)
                ),
                'status'    => $qb->createNamedParameter($status),
                'message'   => $qb->createNamedParameter($message),
            ]);
        $qb->executeStatement();
    }

    /**
     * Every row of an import, in file order.
     *
     * @return list<array<string,mixed>>
     */
    public function findRows(int $importId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_team_import_rows')
            ->where($qb->expr()->eq('import_id', $qb->createNamedParameter($importId, IQueryBuilder::PARAM_INT)))
            ->orderBy('row_num', 'ASC');
        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = $this->hydrateRow($row);
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * The next pending rows, in file order.
     *
     * Reads then claims one id at a time via {@see claimRow()} rather than a
     * blanket `UPDATE … LIMIT`: MySQL and Postgres disagree about UPDATE with
     * LIMIT, and the per-id claim is portable and lets a losing racer skip that
     * row instead of failing the chunk.
     *
     * @return list<array<string,mixed>>
     */
    public function findPendingRows(int $importId, int $limit): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_team_import_rows')
            ->where($qb->expr()->eq('import_id', $qb->createNamedParameter($importId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
            ->orderBy('row_num', 'ASC')
            ->setMaxResults(max(1, $limit));
        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = $this->hydrateRow($row);
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Take a pending row. The `status = 'pending'` predicate is the lock: two
     * pumps racing on the same import (an open tab and the sweeper that decided
     * it was stale) cannot both win, so a team is never created twice.
     *
     * @return bool Whether this caller got the row.
     */
    public function claimRow(int $rowId): bool {
        $qb = $this->db->getQueryBuilder();
        $affected = $qb->update('teamhub_team_import_rows')
            ->set('status', $qb->createNamedParameter('running'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($rowId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
            ->executeStatement();

        return $affected > 0;
    }

    public function finishRow(int $rowId, string $status, ?string $teamId, ?string $message): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('teamhub_team_import_rows')
            ->set('status',  $qb->createNamedParameter($status))
            ->set('team_id', $qb->createNamedParameter($teamId))
            ->set('message', $qb->createNamedParameter($message))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($rowId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * Return any `running` rows of this import to `pending`.
     *
     * Called by the sweeper when it adopts a stalled run: a row left `running`
     * is one whose request died mid-provision, and leaving it there would mean
     * the import can never reach a terminal state.
     *
     * @return int Rows reset.
     */
    public function releaseRunningRows(int $importId): int {
        $qb = $this->db->getQueryBuilder();

        return $qb->update('teamhub_team_import_rows')
            ->set('status', $qb->createNamedParameter('pending'))
            ->where($qb->expr()->eq('import_id', $qb->createNamedParameter($importId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('running')))
            ->executeStatement();
    }

    /**
     * Row counts per status for one import, e.g. ['pending' => 4, 'created' => 16].
     * One grouped query rather than one per status.
     *
     * @return array<string,int>
     */
    public function countRowsByStatus(int $importId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('status')
            ->selectAlias($qb->func()->count('*'), 'cnt')
            ->from('teamhub_team_import_rows')
            ->where($qb->expr()->eq('import_id', $qb->createNamedParameter($importId, IQueryBuilder::PARAM_INT)))
            ->groupBy('status');
        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[(string)$row['status']] = (int)$row['cnt'];
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Team names already claimed by an earlier row of the same import.
     *
     * The in-file duplicate check at validation time runs in PHP over the
     * parsed rows; this exists for the collision check the *provisioning* step
     * repeats, because a run resumed hours later by the sweeper may find names
     * that were free at validation time and are not any more.
     *
     * @return list<string> Lower-cased names.
     */
    public function findCreatedNames(int $importId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('payload')
            ->from('teamhub_team_import_rows')
            ->where($qb->expr()->eq('import_id', $qb->createNamedParameter($importId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('created')));
        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $decoded = json_decode((string)($row['payload'] ?? ''), true);
            if (is_array($decoded) && isset($decoded['name'])) {
                $out[] = mb_strtolower((string)$decoded['name']);
            }
        }
        $result->closeCursor();

        return $out;
    }

    // -------------------------------------------------------------------------
    // Hydration
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrateImport(array $row): array {
        return [
            'id'            => (int)$row['id'],
            'created_by'    => (string)$row['created_by'],
            'filename'      => (string)($row['filename'] ?? ''),
            'status'        => (string)$row['status'],
            'total_rows'    => (int)$row['total_rows'],
            'created_count' => (int)$row['created_count'],
            'skipped_count' => (int)$row['skipped_count'],
            'failed_count'  => (int)$row['failed_count'],
            'created_at'    => (int)$row['created_at'],
            'started_at'    => $row['started_at']   === null ? null : (int)$row['started_at'],
            'finished_at'   => $row['finished_at']  === null ? null : (int)$row['finished_at'],
            'heartbeat_at'  => $row['heartbeat_at'] === null ? null : (int)$row['heartbeat_at'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrateRow(array $row): array {
        $payload = null;
        if (!empty($row['payload'])) {
            $decoded = json_decode((string)$row['payload'], true);
            $payload = is_array($decoded) ? $decoded : null;
        }

        return [
            'id'      => (int)$row['id'],
            'row_num' => (int)$row['row_num'],
            'status'  => (string)$row['status'],
            'team_id' => $row['team_id'] === null ? null : (string)$row['team_id'],
            'message' => $row['message'] === null ? null : (string)$row['message'],
            'payload' => $payload,
        ];
    }
}
