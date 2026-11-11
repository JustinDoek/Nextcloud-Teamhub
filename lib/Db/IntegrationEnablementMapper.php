<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Data access for `teamhub_integ_adm` — the Nextcloud administrator's
 * enablement of an integration package (v4.13.23).
 *
 * ## No row means disabled
 *
 * The default that makes installing an app safe. A package that has just
 * registered has no row here, so it is off: it appears on the administration
 * screen as available and disabled, it cannot be put in a template, and no
 * existing team changes in any way. An administrator turns it on deliberately
 * or it never runs.
 *
 * ## A disable keeps its row
 *
 * {@see setEnabled()} with false writes `enabled = 0` rather than deleting, so
 * the screen can say who turned it off and when. It also keeps "never decided"
 * distinguishable from "decided against", which matters the first time someone
 * asks why an integration an app shipped two releases ago is not running.
 *
 * ## Why this is its own table
 *
 * An administrator's decision is an administrative act with an actor and a
 * timestamp, not an attribute of a registration. Keeping it apart from
 * `teamhub_integ_pkg` is also the structural reason enablement cannot be
 * confused with registration — the old model had exactly one notion of
 * "registered and therefore available to every team", and that conflation is
 * the gap this work closes.
 */
class IntegrationEnablementMapper {

    private const TABLE = 'teamhub_integ_adm';

    public function __construct(
        private IDBConnection $db,
    ) {}

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    /** Has an administrator enabled this package? Absent row = false. */
    public function isEnabled(int $pkgId): bool {
        $row = $this->find($pkgId);

        return $row !== null && $row['enabled'];
    }

    /** @return array<string,mixed>|null */
    public function find(int $pkgId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('pkg_id', $qb->createNamedParameter($pkgId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Every decision on record, keyed by package id.
     *
     * Read once by the administration screen and once per effective-state
     * batch, rather than one query per package.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::TABLE);

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $hydrated = $this->hydrate($row);
            $out[(int)$hydrated['pkg_id']] = $hydrated;
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Package ids an administrator has enabled.
     *
     * @return list<int>
     */
    public function enabledPackageIds(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('pkg_id')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('enabled', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = (int)$row['pkg_id'];
        }
        $result->closeCursor();

        return $out;
    }

    // ------------------------------------------------------------------
    // Write
    // ------------------------------------------------------------------

    /** Record the administrator's decision. Idempotent. */
    public function setEnabled(int $pkgId, bool $enabled, string $actor, int $now): void {
        $existing = $this->find($pkgId);

        if ($existing !== null) {
            $qb = $this->db->getQueryBuilder();
            $qb->update(self::TABLE)
                ->set('enabled', $qb->createNamedParameter($enabled ? 1 : 0, IQueryBuilder::PARAM_INT))
                ->set('changed_by', $qb->createNamedParameter($actor))
                ->set('changed_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();

            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->values([
                'pkg_id'     => $qb->createNamedParameter($pkgId, IQueryBuilder::PARAM_INT),
                'enabled'    => $qb->createNamedParameter($enabled ? 1 : 0, IQueryBuilder::PARAM_INT),
                'changed_by' => $qb->createNamedParameter($actor),
                'changed_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ]);
        $qb->executeStatement();
    }

    /** @return array<string,mixed> */
    private function hydrate(array $row): array {
        return [
            'id'         => (int)$row['id'],
            'pkg_id'     => (int)$row['pkg_id'],
            'enabled'    => (int)($row['enabled'] ?? 0) === 1,
            'changed_by' => (string)($row['changed_by'] ?? ''),
            'changed_at' => (int)($row['changed_at'] ?? 0),
        ];
    }
}
