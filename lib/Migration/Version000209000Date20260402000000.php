<?php

declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Version 2.26.0 migration: external widget registry.
 *
 * Adds:
 * - teamhub_widget_registry   global table, one row per registered external widget
 * - teamhub_team_widgets      per-team opt-in table (team admin enables a widget)
 *
 * Version format: Version{major}{minor}{patch}Date{timestamp}
 * For version 2.26.0 = Version000209000Date20260402000000
 */
class Version000209000Date20260402000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ----------------------------------------------------------------
        // 1. teamhub_widget_registry
        //    One row per registered external widget (registered by an app).
        // ----------------------------------------------------------------
        if (!$schema->hasTable('teamhub_widget_registry')) {
            $table = $schema->createTable('teamhub_widget_registry');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);
            // The NC app ID of the registering app (e.g. 'myplugin').
            // Must match an installed and enabled NC app.
            $table->addColumn('app_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            // Human-readable title shown in the sidebar and Manage Team → Widgets tab.
            $table->addColumn('title', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
            ]);
            // Short description shown in the Manage Team → Widgets tab only.
            $table->addColumn('description', Types::STRING, [
                'notnull' => false,
                'length'  => 500,
                'default' => null,
            ]);
            // Optional MDI icon name (e.g. 'Widgets'). NULL = generic puzzle icon.
            $table->addColumn('icon', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
            ]);
            // The iframe URL. TeamHub will append ?teamId={teamId} when rendering.
            // Must be https://.
            $table->addColumn('iframe_url', Types::STRING, [
                'notnull' => true,
                'length'  => 2048,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
            ]);

            $table->setPrimaryKey(['id']);
            // One registration per app — an app can only register one widget.
            $table->addUniqueIndex(['app_id'], 'th_widget_registry_app_id');

            $output->info('Created teamhub_widget_registry table');
        }

        // ----------------------------------------------------------------
        // 2. teamhub_team_widgets
        //    Per-team opt-in. A team admin checks the box → row is inserted.
        // ----------------------------------------------------------------
        if (!$schema->hasTable('teamhub_team_widgets')) {
            $table = $schema->createTable('teamhub_team_widgets');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);
            // FK to teamhub_widget_registry.id (enforced in service layer, not DB FK,
            // to match the existing pattern used throughout this app).
            $table->addColumn('registry_id', Types::INTEGER, [
                'notnull'  => true,
                'unsigned' => true,
            ]);
            $table->addColumn('team_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            // Display order within this team's widget list. Lower = higher in sidebar.
            $table->addColumn('sort_order', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('enabled_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
            ]);

            $table->setPrimaryKey(['id']);
            // Each widget can only be enabled once per team.
            $table->addUniqueIndex(['registry_id', 'team_id'], 'th_team_widget_unique');
            // Fast lookups by team.
            $table->addIndex(['team_id'], 'th_team_widget_team_id');

            $output->info('Created teamhub_team_widgets table');
        }

        return $schema;
    }
}
