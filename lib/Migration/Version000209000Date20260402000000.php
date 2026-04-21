<?php

declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Version 2.26.0 migration: unified integration registry.
 *
 * Replaces the earlier widget-only design with a two-type integration system:
 *
 *   integration_type = 'widget'    — sidebar widget; TeamHub calls data_url server-side
 *                                    and renders the result natively. Optional action_url
 *                                    opens a quick-action modal in the TeamHub UI.
 *   integration_type = 'menu_item' — adds a tab to the team tab bar; content is loaded
 *                                    in a sandboxed iframe from iframe_url.
 *
 * Built-in integrations (Talk, Files, Calendar, Deck) are seeded as menu_item rows so
 * they can be enabled/disabled per team through the unified Manage Team → Integrations UI.
 *
 * Tables created:
 *   teamhub_integration_registry   one row per registered integration (global)
 *   teamhub_team_integrations      per-team opt-in (enabled/sort_order)
 *
 * NOTE: teamhub_widget_registry and teamhub_team_widgets are dropped if they exist
 * (safe because v2.26.0 was never deployed to a live instance).
 */
class Version000209000Date20260402000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ----------------------------------------------------------------
        // Drop beta tables from the original v2.26.0 if they exist.
        // ----------------------------------------------------------------
        if ($schema->hasTable('teamhub_team_widgets')) {
            $schema->dropTable('teamhub_team_widgets');
            $output->info('Version000209000: dropped teamhub_team_widgets (beta)');
        }
        if ($schema->hasTable('teamhub_widget_registry')) {
            $schema->dropTable('teamhub_widget_registry');
            $output->info('Version000209000: dropped teamhub_widget_registry (beta)');
        }

        // ----------------------------------------------------------------
        // 1. teamhub_integration_registry
        //    Global table — one row per registered integration.
        //    Built-in rows (Talk, Files, Calendar, Deck) seeded on first boot.
        // ----------------------------------------------------------------
        if (!$schema->hasTable('teamhub_integration_registry')) {
            $table = $schema->createTable('teamhub_integration_registry');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);

            // NC app ID of the registering app (e.g. 'spreed', 'deck', 'myplugin').
            $table->addColumn('app_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            // 'widget'    = sidebar panel; TeamHub calls data_url server-side.
            // 'menu_item' = tab bar entry that opens the canvas iframe or native NC view.
            $table->addColumn('integration_type', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
            ]);

            // Human-readable label shown in sidebar widget header or tab bar.
            $table->addColumn('title', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
            ]);

            // Short description shown only in Manage Team → Integrations.
            $table->addColumn('description', Types::STRING, [
                'notnull' => false,
                'length'  => 500,
                'default' => null,
            ]);

            // MDI icon name (e.g. 'CalendarMonth'). NULL = generic puzzle icon.
            $table->addColumn('icon', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
            ]);

            // WIDGET ONLY: TeamHub calls this endpoint server-side to fetch widget data.
            // Must be a relative NC path (/apps/myapp/api/…) or absolute https:// URL.
            // Response: { "items": [ { "label", "value", "icon"?, "url"? } ] }
            $table->addColumn('data_url', Types::STRING, [
                'notnull' => false,
                'length'  => 2048,
                'default' => null,
            ]);

            // WIDGET ONLY: Optional endpoint TeamHub POSTs to for the 3-dot action.
            // Response is rendered in a modal. Relative path or https://.
            $table->addColumn('action_url', Types::STRING, [
                'notnull' => false,
                'length'  => 2048,
                'default' => null,
            ]);

            // WIDGET ONLY: Label shown in the 3-dot action menu item.
            $table->addColumn('action_label', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
            ]);

            // MENU_ITEM ONLY: URL loaded in the sandboxed canvas iframe.
            // Must be https://. Built-in tabs leave this NULL — TeamHub resolves
            // their URL from team resources at runtime.
            $table->addColumn('iframe_url', Types::STRING, [
                'notnull' => false,
                'length'  => 2048,
                'default' => null,
            ]);

            // TRUE for Talk/Files/Calendar/Deck rows seeded by TeamHub itself.
            // Built-in integrations cannot be deregistered via the external API.
            $table->addColumn('is_builtin', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['app_id'], 'th_integ_registry_app_id');
            $table->addIndex(['integration_type'], 'th_integ_registry_type');

            $output->info('Version000209000: created teamhub_integration_registry');
        }

        // ----------------------------------------------------------------
        // 2. teamhub_team_integrations
        //    Per-team opt-in. Replaces both teamhub_team_apps and teamhub_team_widgets.
        // ----------------------------------------------------------------
        if (!$schema->hasTable('teamhub_team_integrations')) {
            $table = $schema->createTable('teamhub_team_integrations');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);

            // FK to teamhub_integration_registry.id (enforced in service, not DB FK).
            $table->addColumn('registry_id', Types::INTEGER, [
                'notnull'  => true,
                'unsigned' => true,
            ]);

            $table->addColumn('team_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            // Display order within this team. Lower = higher in sidebar / earlier in tab bar.
            $table->addColumn('sort_order', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->addColumn('enabled_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['registry_id', 'team_id'], 'th_team_integ_unique');
            $table->addIndex(['team_id'], 'th_team_integ_team_id');

            $output->info('Version000209000: created teamhub_team_integrations');
        }

        return $schema;
    }

    /**
     * Built-in rows are seeded via IntegrationService::seedBuiltins() called
     * from Application::boot() — not here — because IDBConnection is not
     * available in the migration context on all NC versions.
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $output->info('Version000209000: schema complete. Built-in integrations seeded on first boot.');
    }
}
