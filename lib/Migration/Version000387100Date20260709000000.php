<?php

declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.87.1 — Safety-net migration for the integration registry tables.
 *
 * On Nextcloud 34 we saw fresh installs where oc_teamhub_team_integrations and
 * oc_teamhub_integ_registry were absent even though Version000209000 (the
 * original create-table migration) was recorded as executed. Every call into
 * IntegrationController::getEnabledIntegrations then failed with
 * SQLSTATE[42P01] "Undefined table" and the sidebar / tab bar loaded empty.
 *
 * This migration re-runs the create-table logic idempotently:
 *   - hasTable() guards mean upgrades where the tables already exist do
 *     nothing at all
 *   - fresh NC 34 installs missing the tables get them created here with the
 *     final schema (v2.9.0 base + the php_class column added in v2.41.0 by
 *     Version000210001, plus the composite unique index from v2.42.3 by
 *     Version000210002)
 *
 * The migration class name is deliberately picked so it always sorts after
 * the last v3.87 migration, so NC records it as a new step and re-runs the
 * schema check regardless of what oc_migrations already contains for this
 * app.
 */
class Version000387100Date20260709000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        // ----------------------------------------------------------------
        // teamhub_integ_registry — global registry of registered integrations.
        // Schema mirrors the final state after Version000209000 +
        // Version000210001 (php_class column) + Version000210002 (composite
        // unique index on app_id + integration_type).
        // ----------------------------------------------------------------
        if (!$schema->hasTable('teamhub_integ_registry')) {
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
            // php_class — added by Version000210001; nullable, only used by
            // widget-type integrations that implement ITeamHubWidget.
            $table->addColumn('php_class', Types::STRING, [
                'notnull' => false,
                'length'  => 255,
                'default' => null,
            ]);
            $table->addColumn('iframe_url', Types::STRING, [
                'notnull' => false,
                'length'  => 2048,
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
            // Composite unique from Version000210002 — allows one widget row
            // AND one menu_item row per app_id.
            $table->addUniqueIndex(['app_id', 'integration_type'], 'th_integ_registry_app_type');
            $table->addIndex(['integration_type'], 'th_integ_registry_type');

            $output->info('Version000387100: created teamhub_integ_registry');
            $changed = true;
        }

        // ----------------------------------------------------------------
        // teamhub_team_integrations — per-team opt-in.
        // ----------------------------------------------------------------
        if (!$schema->hasTable('teamhub_team_integrations')) {
            $table = $schema->createTable('teamhub_team_integrations');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);
            $table->addColumn('registry_id', Types::INTEGER, [
                'notnull'  => true,
                'unsigned' => true,
            ]);
            $table->addColumn('team_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            $table->addColumn('sort_order', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('enabled_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
            ]);

            $table->setPrimaryKey(['id'], 'th_team_integ_pk');
            $table->addUniqueIndex(['registry_id', 'team_id'], 'th_team_integ_unique');
            $table->addIndex(['team_id'], 'th_team_integ_team_id');

            $output->info('Version000387100: created teamhub_team_integrations');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
