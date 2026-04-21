<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v2.42.3 — Allow one app to register both a widget and a menu_item.
 *
 * Previously the registry had a unique constraint on (app_id) alone, which
 * meant registering a second integration type for the same app_id silently
 * overwrote the first.
 *
 * This migration replaces that constraint with a composite unique on
 * (app_id, integration_type), allowing each app to hold at most one row
 * per type — two rows maximum (one widget + one menu_item).
 *
 * All existing data is preserved; only the index definition changes.
 * Apps that already have a single row are unaffected.
 */
class Version000210002Date20260409000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_integ_registry')) {
            // Table not yet created — the base migration will set the right index.
            return null;
        }

        $table   = $schema->getTable('teamhub_integ_registry');
        $changed = false;

        // Drop the old single-column unique index on app_id.
        // Index name matches what Version000209000 created.
        if ($table->hasIndex('th_integ_registry_app_id')) {
            $table->dropIndex('th_integ_registry_app_id');
            $output->info('teamhub_integ_registry: dropped unique index th_integ_registry_app_id');
            $changed = true;
        }

        // Add composite unique index on (app_id, integration_type).
        // Allows one widget row + one menu_item row per app_id.
        if (!$table->hasIndex('th_integ_registry_app_type')) {
            $table->addUniqueIndex(['app_id', 'integration_type'], 'th_integ_registry_app_type');
            $output->info('teamhub_integ_registry: added unique index th_integ_registry_app_type on (app_id, integration_type)');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
