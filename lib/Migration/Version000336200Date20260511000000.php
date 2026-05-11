<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.36.2 — Fix auto-generated primary key constraint name on teamhub_team_app_resources.
 *
 * Version000328200 created teamhub_team_app_resources without an explicit PK name.
 * On PostgreSQL, Doctrine DBAL auto-generates the name as:
 *
 *   oc_teamhub_team_app_resources_pkey   (34 chars)
 *
 * This exceeds NC DBAL's 30-char constraint-name limit and will cause failures
 * when NC re-validates the schema (e.g. occ db:add-missing-indices).
 *
 * This migration renames it to the short canonical name: th_tar_pk
 *
 * MySQL/MariaDB: PK is always "PRIMARY" (7 chars) — no rename needed, no-ops.
 * Fresh installs via the corrected Version000328200: already use th_tar_pk — no-ops.
 */
class Version000336200Date20260511000000 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $db,
        private IConfig       $config,
    ) {}

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $platform      = $this->db->getDatabasePlatform();
        $platformClass = strtolower(get_class($platform));

        // MySQL/MariaDB always uses "PRIMARY" — nothing to rename.
        if (!str_contains($platformClass, 'postgresql') && !str_contains($platformClass, 'pgsql')) {
            $output->info('Version000336200: non-PostgreSQL platform — PK rename not needed');
            return null;
        }

        // Table may not exist at all if Version000328200 never ran (failed installs).
        // In that case the corrected Version000328200 will create it with th_tar_pk directly.
        $prefix    = $this->config->getSystemValue('dbtableprefix', 'oc_');
        $fullTable = $prefix . 'teamhub_team_app_resources';
        $oldName   = $prefix . 'teamhub_team_app_resources_pkey';
        $newName   = 'th_tar_pk';

        // Check whether the table exists at all.
        $tableExists = $this->db->executeQuery(
            "SELECT 1 FROM pg_class WHERE relname = ? AND relkind = 'r'",
            [$fullTable]
        )->fetchOne();

        if (!$tableExists) {
            $output->info("Version000336200: {$fullTable} does not exist — skipping (Version000328200 will create it correctly)");
            return null;
        }

        // Check whether the old (too-long) PK constraint still exists.
        $exists = $this->db->executeQuery(
            "SELECT 1 FROM pg_constraint WHERE conname = ? AND contype = 'p'",
            [$oldName]
        )->fetchOne();

        if ($exists) {
            $this->db->executeStatement(
                "ALTER TABLE \"{$fullTable}\" RENAME CONSTRAINT \"{$oldName}\" TO \"{$newName}\""
            );
            $output->info("Version000336200: renamed PK {$oldName} → {$newName} on {$fullTable}");
        } else {
            $output->info("Version000336200: {$oldName} not found — already renamed or fresh install, skipping");
        }

        return null;
    }
}
