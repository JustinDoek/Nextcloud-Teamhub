<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Data-access layer for teamhub_team_registry (v4.10.6).
 *
 * One row per team TeamHub created. A circle without a row is not a TeamHub
 * team, whoever a user is to it: it was made in Contacts, Collectives, occ or
 * another app, bypassing the templates and policy profiles that only run on
 * the way through `TeamService::createTeam()`, and TeamHub does not show it.
 *
 * Every member-facing team reader gates on this table, through
 * {@see joinRegistered()} for a list query and {@see exists()} for a single
 * team. Admin-facing estate views (Maintenance, Telemetry) do not, on
 * purpose: an administrator repairing the instance needs to see every circle.
 *
 * A DI leaf — holds only IDBConnection — so any service may depend on it.
 */
class TeamRegistryMapper {

    public const TABLE = 'teamhub_team_registry';

    /** Created through TeamService::createTeam() — wizard, CSV import or provisioning. */
    public const ORIGIN_TEAMHUB       = 'teamhub';
    /** Existed when the 4.10.6 upgrade created the registry; nobody knows who made it. */
    public const ORIGIN_GRANDFATHERED = 'grandfathered';
    /** v4.10.50 — made outside TeamHub and accepted into it; `created_by` is the circle's owner. */
    public const ORIGIN_ADOPTED       = 'adopted';

    /** Alias every `joinRegistered()` uses, so a caller can reference its columns. */
    public const JOIN_ALIAS = 'treg';

    public function __construct(
        private IDBConnection $db,
    ) {}

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    public function exists(string $teamId): bool {
        if ($teamId === '') {
            return false;
        }
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('id')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        return $row !== false;
    }

    /**
     * The registry row, or null.
     *
     * @return array{team_id:string, origin:string, created_by:?string, created_at:int}|null
     */
    public function find(string $teamId): ?array {
        if ($teamId === '') {
            return null;
        }
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('team_id', 'origin', 'created_by', 'created_at')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        if ($row === false) {
            return null;
        }
        return [
            'team_id'    => (string)$row['team_id'],
            'origin'     => (string)$row['origin'],
            'created_by' => $row['created_by'] === null ? null : (string)$row['created_by'],
            'created_at' => (int)$row['created_at'],
        ];
    }

    /**
     * Every registered team id.
     *
     * @return string[]
     */
    public function allTeamIds(): array {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('team_id')->from(self::TABLE)->executeQuery();
        $ids = [];
        while ($row = $res->fetch()) {
            $ids[] = (string)$row['team_id'];
        }
        $res->closeCursor();
        return $ids;
    }

    /**
     * Restrict a query over `circles_circle` (aliased `$circleAlias`) to
     * registered teams, with an INNER JOIN on this table.
     *
     * The join is the whole gate: a circle without a row simply is not in the
     * result, which keeps every reader's own membership logic untouched.
     */
    public function joinRegistered(IQueryBuilder $qb, string $circleAlias): void {
        $qb->innerJoin(
            $circleAlias,
            self::TABLE,
            self::JOIN_ALIAS,
            $qb->expr()->eq(self::JOIN_ALIAS . '.team_id', $circleAlias . '.unique_id'),
        );
    }

    // ------------------------------------------------------------------
    // Write
    // ------------------------------------------------------------------

    /**
     * Register a team. Idempotent — a second call for the same team is a
     * no-op, so a retried provisioning step cannot trip the unique index.
     */
    public function insert(string $teamId, string $origin, ?string $createdBy): void {
        if ($teamId === '' || $this->exists($teamId)) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->values([
                'team_id'    => $qb->createNamedParameter($teamId),
                'origin'     => $qb->createNamedParameter($origin),
                'created_by' => $createdBy === null
                    ? $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
                    : $qb->createNamedParameter($createdBy),
                'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            ])
            ->executeStatement();
    }

    public function delete(string $teamId): void {
        if ($teamId === '') {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->executeStatement();
    }
}
