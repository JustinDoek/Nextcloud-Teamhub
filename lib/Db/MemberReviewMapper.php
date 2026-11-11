<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * `teamhub_member_review` — the record of every completed membership
 * recertification (v4.13.17, DESIGN §2.193).
 *
 * **Append-only, and a table rather than the ledger.** Every other workflow in
 * this app keeps its state in `WorkflowLedgerService` — one JSON document per
 * kind, holding the *open* instance. A recertification is the opposite shape:
 * the open instance is derived (a due date against the last completed one), and
 * what has to survive is the **history**, for years, because that history is
 * the evidence an ISO 27001 access review is audited on. App config holds one
 * document per kind and would have to grow a row per team per cycle inside it.
 *
 * It is also deliberately **not only** in the audit log: the audit trail is
 * purged on a retention horizon that defaults to 90 days, and an attestation
 * that disappears before the audit it exists for is worse than none. Both are
 * written — the audit row for the actor trail, this table for the record.
 */
class MemberReviewMapper {

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    /**
     * Record one completed review.
     *
     * @param array<string,mixed>|null $snapshot what the roster looked like
     * @return int the new row's id
     */
    public function insert(
        string  $teamId,
        string  $reviewedBy,
        int     $reviewedAt,
        int     $memberCount,
        int     $removedCount,
        int     $nextDueAt,
        string  $note,
        ?array  $snapshot = null,
    ): int {
        $snapshotJson = null;
        if ($snapshot !== null) {
            $encoded = json_encode($snapshot, JSON_UNESCAPED_SLASHES);
            $snapshotJson = $encoded === false ? null : $encoded;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->insert('teamhub_member_review')
            ->values([
                'team_id'       => $qb->createNamedParameter($teamId),
                'reviewed_by'   => $qb->createNamedParameter($reviewedBy),
                'reviewed_at'   => $qb->createNamedParameter($reviewedAt, IQueryBuilder::PARAM_INT),
                'member_count'  => $qb->createNamedParameter($memberCount, IQueryBuilder::PARAM_INT),
                'removed_count' => $qb->createNamedParameter($removedCount, IQueryBuilder::PARAM_INT),
                'next_due_at'   => $qb->createNamedParameter($nextDueAt, IQueryBuilder::PARAM_INT),
                'note'          => $qb->createNamedParameter($note),
                'snapshot'      => $qb->createNamedParameter($snapshotJson),
            ])
            ->executeStatement();

        return (int)$this->db->lastInsertId('*PREFIX*teamhub_member_review');
    }

    /** The most recent review of one team, or null when it has never had one. */
    public function latestForTeam(string $teamId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_member_review')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->orderBy('reviewed_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(1);

        $r = $qb->executeQuery();
        $row = $r->fetch();
        $r->closeCursor();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * The most recent review of each of several teams, keyed by team id.
     *
     * One query for the whole set, because the daily pass asks about every team
     * on the instance and the My Work provider about every team one person
     * administers. Newest-first ordering with a first-wins loop rather than a
     * correlated subquery: portable across MySQL and Postgres, and the row
     * count is one per completed review which is small by construction.
     *
     * @param list<string> $teamIds
     * @return array<string,array<string,mixed>>
     */
    public function latestForTeams(array $teamIds): array {
        if ($teamIds === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($teamIds, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from('teamhub_member_review')
                ->where($qb->expr()->in('team_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
                ->orderBy('reviewed_at', 'DESC')
                ->addOrderBy('id', 'DESC');

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $teamId = (string)$row['team_id'];
                if (!isset($out[$teamId])) {
                    $out[$teamId] = $this->hydrate($row);
                }
            }
            $result->closeCursor();
        }

        return $out;
    }

    /**
     * One team's review history, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function historyForTeam(string $teamId, int $limit = 50): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_member_review')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->orderBy('reviewed_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(max(1, min(500, $limit)));

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = $this->hydrate($row);
        }
        $result->closeCursor();

        return $rows;
    }

    /**
     * Remove a deleted team's reviews.
     *
     * The one delete, and it exists because the alternative is worse: rows
     * about a team nobody can name, kept forever, which is the shape of a data
     * retention finding rather than evidence. Called from the team's own
     * deletion path, never from a user action.
     */
    public function deleteForTeam(string $teamId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('teamhub_member_review')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));

        return (int)$qb->executeStatement();
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): array {
        $snapshot = null;
        if (!empty($row['snapshot'])) {
            $decoded = json_decode((string)$row['snapshot'], true);
            $snapshot = is_array($decoded) ? $decoded : null;
        }

        return [
            'id'           => (int)$row['id'],
            'teamId'       => (string)$row['team_id'],
            'reviewedBy'   => (string)$row['reviewed_by'],
            'reviewedAt'   => (int)$row['reviewed_at'],
            'memberCount'  => (int)$row['member_count'],
            'removedCount' => (int)$row['removed_count'],
            'nextDueAt'    => (int)$row['next_due_at'],
            'note'         => (string)($row['note'] ?? ''),
            'snapshot'     => $snapshot,
        ];
    }
}
