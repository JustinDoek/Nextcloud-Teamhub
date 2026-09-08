<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Raw QueryBuilder mapper for teamhub_policy_value (v4.8.2, Track F2a).
 *
 * One row per (profile, field). **A field with no row is ungoverned by that
 * profile — not "false".** Absence is the third state and it is the common one:
 * a profile that only pins visibility has one row.
 *
 * v4.8.3 — the `mode` column is gone (Version000408003). A governed field is
 * locked; there is no longer a looser "starting value" setting.
 *
 * Raw rather than QBMapper: composite primary key, no surrogate id.
 */
class PolicyValueMapper {

    private const TABLE = 'teamhub_policy_value';

    public function __construct(private IDBConnection $db) {}

    /**
     * Every value for one profile, keyed by field.
     *
     * @return array<string, string>
     */
    public function findByProfile(string $profileKey): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('field_key', 'field_value')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('profile_key', $qb->createNamedParameter($profileKey)));

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[(string)$row['field_key']] = $row['field_value'] !== null ? (string)$row['field_value'] : '';
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Values for several profiles at once, keyed by profile then field.
     *
     * The admin list renders every profile with the number of settings it
     * governs; without this it would be one query per profile.
     *
     * @param list<string> $profileKeys
     * @return array<string, array<string, string>>
     */
    public function findByProfiles(array $profileKeys): array {
        if ($profileKeys === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('profile_key', 'field_key', 'field_value')
            ->from(self::TABLE)
            ->where($qb->expr()->in(
                'profile_key',
                $qb->createNamedParameter($profileKeys, IQueryBuilder::PARAM_STR_ARRAY),
            ));

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[(string)$row['profile_key']][(string)$row['field_key']] =
                $row['field_value'] !== null ? (string)$row['field_value'] : '';
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Replace a profile's whole value set.
     *
     * Delete-then-insert rather than a per-field diff. The set is at most a
     * dozen rows, the admin UI submits the whole profile anyway, and a diff
     * would have to handle "field removed" as a distinct case — which is
     * exactly the ungoverned state, and the easiest one to get wrong.
     *
     * Wrapped in a transaction so a profile is never left with half its values:
     * a partial value set is a policy nobody wrote.
     *
     * @param array<string, string> $values field key => serialised value
     */
    public function replaceForProfile(string $profileKey, array $values): void {
        $this->db->beginTransaction();
        try {
            $this->deleteByProfile($profileKey);

            foreach ($values as $fieldKey => $value) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert(self::TABLE)->values([
                    'profile_key' => $qb->createNamedParameter($profileKey),
                    'field_key'   => $qb->createNamedParameter($fieldKey),
                    'field_value' => $qb->createNamedParameter($value),
                ]);
                $qb->executeStatement();
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function deleteByProfile(string $profileKey): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('profile_key', $qb->createNamedParameter($profileKey)));
        $qb->executeStatement();
    }
}
