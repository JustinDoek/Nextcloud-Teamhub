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
