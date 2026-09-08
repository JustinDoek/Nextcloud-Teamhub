<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Raw QueryBuilder mapper for teamhub_policy_profile (v4.8.2, Track F2a).
 *
 * Raw rather than QBMapper for the same reason `TeamExpiryMapper` and
 * `TeamTypeMapper` are: the table has no surrogate id, `profile_key` *is* the
 * key, and QBMapper's insert/update split assumes an `id` it can read back.
 *
 * Every method returns plain arrays or scalars — no entity leaves this class,
 * so the service layer stays free to shape its own payloads (SKILLS.md §
 * Service layer rules).
 *
 * Authorisation is the service's job. Everything here is NC-admin territory
 * and this class assumes the gate has already run.
 */
class PolicyProfileMapper {

    private const TABLE = 'teamhub_policy_profile';

    public function __construct(private IDBConnection $db) {}

    /**
     * Every profile, least sensitive first.
     *
     * Ordered by `sort_index` then `profile_key` — the tiebreak matters because
     * two profiles may legitimately share an index after an admin inserts one,
     * and an unstable order makes the admin list jump between reloads.
     *
     * @return list<array<string,mixed>>
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->orderBy('sort_index', 'ASC')
            ->addOrderBy('profile_key', 'ASC');

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = $this->hydrate($row);
        }
        $result->closeCursor();

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function find(string $profileKey): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('profile_key', $qb->createNamedParameter($profileKey)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $this->hydrate($row);
    }

    public function exists(string $profileKey): bool {
        return $this->find($profileKey) !== null;
    }

    public function insert(
        string  $profileKey,
        string  $label,
        ?string $description,
        int     $sortIndex,
        string  $createdBy,
        int     $now,
        bool    $isSeeded = false,
    ): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)->values([
            'profile_key' => $qb->createNamedParameter($profileKey),
            'label'       => $qb->createNamedParameter($label),
            'description' => $qb->createNamedParameter($description),
            'sort_index'  => $qb->createNamedParameter($sortIndex, IQueryBuilder::PARAM_INT),
            // SMALLINT bound as PARAM_INT, never PARAM_BOOL — DESIGN §2.4.
            'is_seeded'   => $qb->createNamedParameter($isSeeded ? 1 : 0, IQueryBuilder::PARAM_INT),
            'created_by'  => $qb->createNamedParameter($createdBy),
            'created_at'  => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'updated_by'  => $qb->createNamedParameter($createdBy),
            'updated_at'  => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
        ]);
        $qb->executeStatement();
    }

    /**
     * Update the presentational fields. `profile_key` is never updated — it is
     * the identity, and changing it would silently rewrite what every
     * assignment and every drift finding points at.
     */
    public function update(
        string  $profileKey,
        string  $label,
        ?string $description,
        int     $sortIndex,
        string  $actor,
        int     $now,
    ): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('label', $qb->createNamedParameter($label))
            ->set('description', $qb->createNamedParameter($description))
            ->set('sort_index', $qb->createNamedParameter($sortIndex, IQueryBuilder::PARAM_INT))
            ->set('updated_by', $qb->createNamedParameter($actor))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('profile_key', $qb->createNamedParameter($profileKey)));
        $qb->executeStatement();
    }

    public function delete(string $profileKey): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('profile_key', $qb->createNamedParameter($profileKey)));
        $qb->executeStatement();
    }

    /**
     * How many teams carry each profile.
     *
     * Reads `teamhub_team_policy`, which nothing writes until F2b — so this
     * returns an empty array today and the admin list renders "0 teams"
     * everywhere. That is correct, not a stub: no team is classified yet.
     *
     * @return array<string,int> profile_key => team count
     */
    public function countAssignmentsByProfile(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('profile_key')
            ->addSelect($qb->func()->count('*', 'cnt'))
            ->from('teamhub_team_policy')
            ->groupBy('profile_key');

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[(string)$row['profile_key']] = (int)$row['cnt'];
        }
        $result->closeCursor();

        return $out;
    }

    /** @return array<string,mixed> */
    private function hydrate(array $row): array {
        return [
            'profileKey'  => (string)$row['profile_key'],
            'label'       => (string)$row['label'],
            'description' => $row['description'] !== null ? (string)$row['description'] : null,
            'sortIndex'   => (int)$row['sort_index'],
            'isSeeded'    => (int)$row['is_seeded'] === 1,
            'createdBy'   => (string)$row['created_by'],
            'createdAt'   => (int)$row['created_at'],
            'updatedBy'   => $row['updated_by'] !== null ? (string)$row['updated_by'] : null,
            'updatedAt'   => $row['updated_at'] !== null ? (int)$row['updated_at'] : null,
        ];
    }
}
