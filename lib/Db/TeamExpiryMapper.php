<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Raw QueryBuilder mapper for teamhub_team_expiry (v4.6.13).
 *
 * Raw rather than QBMapper for the same reason TeamTypeMapper is: the table has
 * no surrogate id, the team id *is* the key, and every write is an upsert or a
 * delete. QBMapper's insert/update split assumes an `id` it can read back,
 * which there is nothing here to read.
 *
 * Every method returns plain arrays or scalars — no entity leaves this class,
 * so the service layer stays free to shape its own payloads (SKILLS.md §
 * Service layer rules).
 */
class TeamExpiryMapper {

    public function __construct(private IDBConnection $db) {}

    /**
     * @return array{teamId: string, expiresAt: int, setBy: string, setAt: int,
     *               lastExtendedBy: ?string, lastExtendedAt: ?int}|null
     *         Null when the team has no expiry.
     */
    public function findByTeam(string $teamId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_team_expiry')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Batch lookup — the admin All-teams grid renders up to 100 rows a page and
     * must not issue one query per row.
     *
     * @param string[] $teamIds
     * @return array<string, array<string,mixed>> [teamId => expiry row]
     */
    public function findByTeams(array $teamIds): array {
        if ($teamIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_team_expiry')
            ->where($qb->expr()->in(
                'team_id',
                $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY),
            ));

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[(string)$row['team_id']] = $this->hydrate($row);
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Every team whose expiry falls in [$fromTs, $toTs] inclusive.
     *
     * Used by the My Work providers to find teams inside the warning window,
     * and deliberately given a lower bound as well as an upper one: an expiry
     * that passed months ago is not "expiring soon", and the caller decides
     * how far back it still wants to look.
     *
     * @return list<array<string,mixed>> ordered soonest-first
     */
    public function findExpiringBetween(int $fromTs, int $toTs, int $limit = 500): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_team_expiry')
            ->where($qb->expr()->gte('expires_at', $qb->createNamedParameter($fromTs, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lte('expires_at', $qb->createNamedParameter($toTs, IQueryBuilder::PARAM_INT)))
            ->orderBy('expires_at', 'ASC')
            ->setMaxResults(max(1, $limit));

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = $this->hydrate($row);
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Insert-or-update, preserving the original author.
     *
     * DELETE-then-INSERT inside a transaction rather than a portable upsert,
     * for the reason TeamTypeMapper documents: `REPLACE INTO` is not portable
     * and `ON CONFLICT` syntax differs between MariaDB and Postgres. The
     * caller passes the set_by/set_at it wants preserved — this mapper does not
     * decide policy, it writes what it is given.
     */
    public function upsert(
        string  $teamId,
        int     $expiresAt,
        string  $setBy,
        int     $setAt,
        ?string $lastExtendedBy = null,
        ?int    $lastExtendedAt = null,
    ): void {
        $this->db->beginTransaction();
        try {
            $del = $this->db->getQueryBuilder();
            $del->delete('teamhub_team_expiry')
                ->where($del->expr()->eq('team_id', $del->createNamedParameter($teamId)));
            $del->executeStatement();

            $ins = $this->db->getQueryBuilder();
            $ins->insert('teamhub_team_expiry')
                ->values([
                    'team_id'          => $ins->createNamedParameter($teamId),
                    'expires_at'       => $ins->createNamedParameter($expiresAt, IQueryBuilder::PARAM_INT),
                    'set_by'           => $ins->createNamedParameter($setBy),
                    'set_at'           => $ins->createNamedParameter($setAt, IQueryBuilder::PARAM_INT),
                    'last_extended_by' => $ins->createNamedParameter($lastExtendedBy),
                    'last_extended_at' => $lastExtendedAt === null
                        ? $ins->createNamedParameter(null)
                        : $ins->createNamedParameter($lastExtendedAt, IQueryBuilder::PARAM_INT),
                ]);
            $ins->executeStatement();

            $this->db->commit();
        } catch (\Throwable $e) {
            try { $this->db->rollBack(); } catch (\Throwable) {}
            throw $e;
        }
    }

    /** Remove a team's expiry entirely. Returns the number of rows deleted. */
    public function deleteByTeam(string $teamId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('teamhub_team_expiry')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
        return $qb->executeStatement();
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrate(array $row): array {
        return [
            'teamId'         => (string)$row['team_id'],
            'expiresAt'      => (int)$row['expires_at'],
            'setBy'          => (string)$row['set_by'],
            'setAt'          => (int)$row['set_at'],
            'lastExtendedBy' => $row['last_extended_by'] !== null ? (string)$row['last_extended_by'] : null,
            'lastExtendedAt' => $row['last_extended_at'] !== null ? (int)$row['last_extended_at'] : null,
        ];
    }
}
