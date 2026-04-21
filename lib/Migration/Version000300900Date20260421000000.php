<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.9.0 — Rename teamhub_integration_registry → teamhub_integ_registry.
 *
 * The old name (28 chars) exceeds Nextcloud's 27-character table-name limit,
 * which causes schema validation failures on strict DB back-ends.
 *
 * Fresh installs: Version000209000 already creates the table under the new
 * name, so this migration no-ops gracefully when the old name is absent.
 *
 * Live installs (1-2 known): the old table exists and must be renamed.
 * Doctrine ISchemaWrapper does not expose RENAME TABLE directly, so we
 * issue the appropriate raw DDL for each supported DB platform.
 */
class Version000300900Date20260421000000 extends SimpleMigrationStep {

    public function __construct(private IDBConnection $db) {
    }

    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_integration_registry')) {
            $output->info('Version000300900: teamhub_integration_registry not found — rename not needed');
            return;
        }

        if ($schema->hasTable('teamhub_integ_registry')) {
            $output->info('Version000300900: teamhub_integ_registry already exists — rename not needed');
            return;
        }

        $prefix   = $this->db->getPrefix();
        $oldTable = $prefix . 'teamhub_integration_registry';
        $newTable = $prefix . 'teamhub_integ_registry';

        $driverClass = get_class($this->db->getDatabasePlatform());
        error_log('[TeamHub][Version000300900] Renaming table, platform class: ' . $driverClass);

        $lowerDriver = strtolower($driverClass);

        if (str_contains($lowerDriver, 'mysql') || str_contains($lowerDriver, 'mariadb')) {
            $this->db->executeStatement("RENAME TABLE `{$oldTable}` TO `{$newTable}`");
        } elseif (str_contains($lowerDriver, 'postgresql') || str_contains($lowerDriver, 'pgsql')) {
            // PostgreSQL requires an unqualified new name (no schema prefix)
            $this->db->executeStatement("ALTER TABLE \"{$oldTable}\" RENAME TO \"{$newTable}\"");
        } elseif (str_contains($lowerDriver, 'sqlite')) {
            $this->db->executeStatement("ALTER TABLE \"{$oldTable}\" RENAME TO \"{$newTable}\"");
        } else {
            $output->warning("Version000300900: unrecognised DB platform '{$driverClass}' — manual rename of {$oldTable} → {$newTable} required");
            error_log('[TeamHub][Version000300900] Unknown platform, cannot rename: ' . $driverClass);
            return;
        }

        $output->info("Version000300900: renamed {$oldTable} → {$newTable}");
        error_log("[TeamHub][Version000300900] Rename complete: {$oldTable} → {$newTable}");
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        // No schema-object changes — rename was performed in preSchemaChange via raw DDL.
        return null;
    }
}
