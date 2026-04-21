<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v2.41.0 — Replace HTTP-based widget data transport with PHP interface contract.
 *
 * Changes to teamhub_integ_registry:
 *   ADD    php_class   VARCHAR(255) NULL  — fully-qualified class name implementing ITeamHubWidget
 *   DROP   data_url                       — replaced by php_class for same-server NC apps
 *   DROP   action_url                     — replaced by dynamic actions returned from getWidgetData()
 *   DROP   action_label                   — no longer needed without action_url
 *
 * Built-in integrations (Talk, Files, Calendar, Deck) are menu_item type and
 * never used data_url/action_url — this migration does not affect them.
 *
 * Existing external widget registrations that stored a data_url will have
 * their data_url dropped. Those apps must be updated to implement
 * ITeamHubWidget and re-register with a phpClass value.
 */
class Version000210001Date20260407000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_integ_registry')) {
            // Table does not exist yet — nothing to migrate.
            return null;
        }

        $table   = $schema->getTable('teamhub_integ_registry');
        $changed = false;

        // ADD php_class — nullable, not required for menu_item registrations.
        if (!$table->hasColumn('php_class')) {
            $table->addColumn('php_class', Types::STRING, [
                'notnull' => false,
                'length'  => 255,
                'default' => null,
            ]);
            $output->info('teamhub_integ_registry: added php_class column');
            $changed = true;
        }

        // DROP data_url — replaced by php_class.
        if ($table->hasColumn('data_url')) {
            $table->dropColumn('data_url');
            $output->info('teamhub_integ_registry: dropped data_url column');
            $changed = true;
        }

        // DROP action_url — replaced by dynamic actions from ITeamHubWidget::getWidgetData().
        if ($table->hasColumn('action_url')) {
            $table->dropColumn('action_url');
            $output->info('teamhub_integ_registry: dropped action_url column');
            $changed = true;
        }

        // DROP action_label — no longer needed without action_url.
        if ($table->hasColumn('action_label')) {
            $table->dropColumn('action_label');
            $output->info('teamhub_integ_registry: dropped action_label column');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
