<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Raw QueryBuilder mapper for teamhub_team_type — the per-team template
 * label written by CreateTeamView after team creation. Matches the shape
 * of the other lightweight mappers in this app (MessageMapper etc.).
 */
class TeamTypeMapper {
    public function __construct(private IDBConnection $db) {}

    /**
     * @return string|null 'collaboration'|'project'|'department', or null when
     *                     the team has no row (legacy teams, or a future new
     *                     team where the create flow's PUT hasn't fired yet).
     */
    public function findTypeByTeam(string $teamId): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('type')
            ->from('teamhub_team_type')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();
        return $row === false ? null : (string)$row['type'];
    }

    /**
     * Batch lookup for browseAllTeams — avoids N+1 queries when rendering the
     * team-picker cards.
     *
     * @param string[] $teamIds
     * @return array<string,string> [teamId => type]
     */
    public function findTypesByTeams(array $teamIds): array {
        if ($teamIds === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('team_id', 'type')
            ->from('teamhub_team_type')
            ->where($qb->expr()->in('team_id', $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY)));
        $result = $qb->executeQuery();
        $out = [];
        while ($row = $result->fetch()) {
            $out[(string)$row['team_id']] = (string)$row['type'];
        }
        $result->closeCursor();
        return $out;
    }

    /**
     * Every team made from one template (v4.8.27).
     *
     * The propagation plan's population: when a template changes, these are the
     * teams a deployment report has to account for, compliant or not.
     *
     * Unbounded, for the same reason `TeamPolicyMapper::findAllAssignments()`
     * is: the count is the number of teams of that kind, and a `LIMIT` would
     * silently under-report the population the report exists to enumerate.
     *
     * @return list<string> team ids
     */
    public function findTeamsByType(string $type): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('team_id')
            ->from('teamhub_team_type')
            ->where($qb->expr()->eq('type', $qb->createNamedParameter($type)))
            ->orderBy('team_id', 'ASC');

        $result = $qb->executeQuery();
        $out = [];
        while ($row = $result->fetch()) {
            $out[] = (string)$row['team_id'];
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * How many teams carry each template key — v4.13.24.
     *
     * One grouped query rather than a `findTeamsByType()` per template: the
     * Policy panel wants the figure for every row at once, and five or fifty
     * round trips for a number shown beside a Delete button is not a trade
     * worth making. No `LIMIT` for the same reason `findTeamsByType()` has
     * none — a truncated count is a wrong count.
     *
     * A key with no teams is simply absent from the result, so the caller
     * reads it as 0 rather than having to distinguish "none" from "unknown".
     *
     * @return array<string,int> template key => team count
     */
    public function countByType(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('type')
            ->selectAlias($qb->func()->count('team_id'), 'team_count')
            ->from('teamhub_team_type')
            ->groupBy('type');

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[(string)$row['type']] = (int)$row['team_count'];
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Insert-or-update. team_id is PK so we do a DELETE-then-INSERT rather
     * than a portable upsert (NC apps target both MariaDB and Postgres; a
     * REPLACE INTO isn't portable and ON CONFLICT syntax differs between
     * dialects). Two round-trips inside a transaction keeps behaviour
     * identical on both.
     */
    public function upsert(string $teamId, string $type, string $createdBy): void {
        $this->db->beginTransaction();
        try {
            $del = $this->db->getQueryBuilder();
            $del->delete('teamhub_team_type')
                ->where($del->expr()->eq('team_id', $del->createNamedParameter($teamId)));
            $del->executeStatement();

            $ins = $this->db->getQueryBuilder();
            $ins->insert('teamhub_team_type')
                ->values([
                    'team_id'    => $ins->createNamedParameter($teamId),
                    'type'       => $ins->createNamedParameter($type),
                    'created_by' => $ins->createNamedParameter($createdBy),
                    'created_at' => $ins->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
                ]);
            $ins->executeStatement();

            $this->db->commit();
        } catch (\Throwable $e) {
            try { $this->db->rollBack(); } catch (\Throwable) {}
            throw $e;
        }
    }
}
