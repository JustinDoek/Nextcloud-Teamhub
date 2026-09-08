<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Raw QueryBuilder mapper for teamhub_team_policy (v4.8.4, Track F2b).
 *
 * The team → profile assignment. **Row existence is the fact**: a team with no
 * row is unclassified, which is an empty policy — it governs nothing, locks
 * nothing and is compared against nothing. Same argument
 * `Version000406013` makes for `teamhub_team_expiry`, and the reason there is
 * no nullable `profile_key` to be ambiguous about.
 *
 * Raw rather than QBMapper: `team_id` is the primary key, no surrogate id.
 */
class TeamPolicyMapper {

    private const TABLE = 'teamhub_team_policy';

    public function __construct(private IDBConnection $db) {}

    /** @return array{profileKey: string, assignedBy: string, assignedAt: int, source: string}|null */
    public function findByTeam(string $teamId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            return null;
        }

        return [
            'profileKey' => (string)$row['profile_key'],
            'assignedBy' => (string)$row['assigned_by'],
            'assignedAt' => (int)$row['assigned_at'],
            'source'     => (string)$row['source'],
        ];
    }

    /**
     * Batch lookup — the drift scan and the admin grid both want a page of
     * teams at once rather than one query per row.
     *
     * @param list<string> $teamIds
     * @return array<string,string> teamId => profileKey
     */
    public function findByTeams(array $teamIds): array {
        if ($teamIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('team_id', 'profile_key')
            ->from(self::TABLE)
            ->where($qb->expr()->in(
                'team_id',
                $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY),
            ));

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[(string)$row['team_id']] = (string)$row['profile_key'];
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Every assignment on the instance (v4.8.15).
     *
     * The compliance sweep's starting set: it reads classified teams only, so
     * this is also the definition of "classified" the count on the Compliance
     * tab reports. Unbounded by design — the row count is the number of teams
     * an administrator has deliberately classified, and a `LIMIT` here would
     * silently under-report the very number the tab exists to state.
     *
     * @return array<string,string> teamId => profileKey
     */
    public function findAllAssignments(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('team_id', 'profile_key')
            ->from(self::TABLE)
            ->orderBy('team_id', 'ASC');

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[(string)$row['team_id']] = (string)$row['profile_key'];
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Assign, or reassign. Delete-then-insert rather than an upsert branch:
     * one row per team, and the two engines disagree about upsert syntax.
     */
    public function assign(string $teamId, string $profileKey, string $actor, int $now, string $source): void {
        $this->clear($teamId);

        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)->values([
            'team_id'     => $qb->createNamedParameter($teamId),
            'profile_key' => $qb->createNamedParameter($profileKey),
            'assigned_by' => $qb->createNamedParameter($actor),
            'assigned_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'source'      => $qb->createNamedParameter($source),
        ]);
        $qb->executeStatement();
    }

    /** Back to unclassified. Writes no team settings — see DESIGN §2.108. */
    public function clear(string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
        $qb->executeStatement();
    }
}
