<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.10.2 - Ensure teamhub_integ_registry exists; remove legacy over-length table.
 *
 * Background
 * ----------
 * Nextcloud's "Uninstall app + delete all data" UI removes app preferences and
 * files but retains database tables AND keeps the app's migration history in
 * oc_migrations. On reinstall NC sees every prior migration as already-run and
 * skips them all. The result when the old 28-char name "teamhub_integration_registry"
 * was still present:
 *
 *   oc_teamhub_integration_registry  -- survives uninstall (NC does not drop tables)
 *   oc_teamhub_integ_registry        -- never created (migration 000209000 skipped)
 *
 * This migration uses version number 000310001 which did not exist in any prior
 * release, so NC will always run it regardless of which prior migrations were skipped.
 *
 * changeSchema:
 *   1. Creates teamhub_integ_registry with the full current schema if absent.
 *   2. Drops teamhub_integration_registry if present (stale legacy name).
 *
 * Data migration is omitted: built-in rows are re-seeded by
 * IntegrationService::seedBuiltins() on every boot; external registrations are
 * re-registered by third-party apps on their next boot.
 *
 * DDL reflects the current schema -- columns dropped by migration 000210001
 * (data_url, action_url, action_label) are NOT recreated here.
 */
class Version000310001Date20260422000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        // 1. Create teamhub_integ_registry if it does not yet exist.
        if (!$schema->hasTable('teamhub_integ_registry')) {
            $output->info('Version000310001: teamhub_integ_registry missing -- creating');

            $table = $schema->createTable('teamhub_integ_registry');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);

            $table->addColumn('app_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            $table->addColumn('integration_type', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
            ]);

            $table->addColumn('title', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
            ]);

            $table->addColumn('description', Types::STRING, [
                'notnull' => false,
                'length'  => 500,
                'default' => null,
            ]);

            $table->addColumn('icon', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
            ]);

            $table->addColumn('iframe_url', Types::STRING, [
                'notnull' => false,
                'length'  => 2048,
                'default' => null,
            ]);

            $table->addColumn('php_class', Types::STRING, [
                'notnull' => false,
                'length'  => 255,
                'default' => null,
            ]);

            $table->addColumn('is_builtin', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
            ]);

            $table->setPrimaryKey(['id'], 'th_integ_reg_pk');
            $table->addUniqueIndex(['app_id', 'integration_type'], 'th_integ_registry_app_type');
            $table->addIndex(['integration_type'], 'th_integ_registry_type');

            $output->info('Version000310001: created teamhub_integ_registry');
            $changed = true;
        } else {
            $output->info('Version000310001: teamhub_integ_registry already exists -- skipping create');
        }

        // 2. Drop the legacy over-length table if it survived uninstall.
        if ($schema->hasTable('teamhub_integration_registry')) {
            $schema->dropTable('teamhub_integration_registry');
            $output->info('Version000310001: dropped legacy teamhub_integration_registry');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
