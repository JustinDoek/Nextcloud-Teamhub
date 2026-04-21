<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.9.1 — Fix auto-generated primary key constraint names that exceed 27 chars.
 *
 * NC's schema validator enforces a 27-character limit on all constraint/index names.
 * On PostgreSQL, Doctrine DBAL auto-generates the primary key constraint name as
 * "{prefix}{tablename}_pkey", which exceeds 27 chars for our three longest tables:
 *
 *   oc_teamhub_integ_registry_pkey        (30 chars)  → th_integ_reg_pk
 *   oc_teamhub_team_integrations_pkey     (33 chars)  → th_team_integ_pk
 *   oc_teamhub_widget_layouts_pkey        (30 chars)  → th_widget_lyt_pk
 *
 * MySQL/MariaDB always names the primary key "PRIMARY" (7 chars), so this
 * migration no-ops on those platforms.
 *
 * Fresh installs: the base migrations now pass explicit names to setPrimaryKey(),
 * so this migration will find the constraints already correctly named and skip them.
 */
class Version000300901Date20260421000000 extends SimpleMigrationStep {

    public function __construct(private IDBConnection $db) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $platform    = $this->db->getDatabasePlatform();
        $platformClass = strtolower(get_class($platform));

        // Only PostgreSQL auto-generates _pkey names. MySQL is always "PRIMARY".
        if (!str_contains($platformClass, 'postgresql') && !str_contains($platformClass, 'pgsql')) {
            $output->info('Version000300901: non-PostgreSQL platform — PK rename not needed');
            return null;
        }

        error_log('[TeamHub][Version000300901] PostgreSQL detected — checking PK constraint names');

        $prefix = $this->db->getPrefix();

        $renames = [
            'teamhub_integ_registry'    => ['old' => 'oc_teamhub_integ_registry_pkey',    'new' => 'th_integ_reg_pk'],
            'teamhub_team_integrations' => ['old' => 'oc_teamhub_team_integrations_pkey',  'new' => 'th_team_integ_pk'],
            'teamhub_widget_layouts'    => ['old' => 'oc_teamhub_widget_layouts_pkey',     'new' => 'th_widget_lyt_pk'],
        ];

        foreach ($renames as $tableSuffix => $names) {
            $fullTable   = $prefix . $tableSuffix;
            $oldName     = $names['old'];
            $newName     = $names['new'];

            // Check whether the old (too-long) constraint still exists.
            $exists = $this->db->executeQuery(
                "SELECT 1 FROM pg_constraint WHERE conname = ? AND contype = 'p'",
                [$oldName]
            )->fetchOne();

            if ($exists) {
                $this->db->executeStatement(
                    "ALTER TABLE \"{$fullTable}\" RENAME CONSTRAINT \"{$oldName}\" TO \"{$newName}\""
                );
                $output->info("Version000300901: renamed PK {$oldName} → {$newName} on {$fullTable}");
                error_log("[TeamHub][Version000300901] PK renamed: {$oldName} → {$newName}");
            } else {
                $output->info("Version000300901: {$oldName} not found — already renamed or fresh install, skipping");
                error_log("[TeamHub][Version000300901] PK {$oldName} not found — skipping");
            }
        }

        return null;
    }
}
