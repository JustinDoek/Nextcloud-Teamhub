<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.8.1 — Fix BOOLEAN NOT NULL columns that cannot store false.
 *
 * Two columns had Types::BOOLEAN + notnull => true, which causes Doctrine
 * to reject PHP false on MySQL/MariaDB with the error:
 *   "Column X is type Bool and also NotNull, so it can not store false."
 *
 * Affected columns fixed here (existing installs):
 *   teamhub_team_apps.enabled               → SMALLINT, default 1
 *   teamhub_integration_registry.is_builtin → SMALLINT, default 0
 *
 * Fresh installs are covered by the corrected base migrations
 * (Version000200000 and Version000209000). This migration no-ops gracefully
 * if the columns are already the correct type.
 */
class Version000300801Date20260421000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        // ------------------------------------------------------------------
        // 1. teamhub_team_apps.enabled
        // ------------------------------------------------------------------
        if ($schema->hasTable('teamhub_team_apps')) {
            $table = $schema->getTable('teamhub_team_apps');

            if ($table->hasColumn('enabled')) {
                $column = $table->getColumn('enabled');

                if ($column->getType()->getName() === 'boolean') {
                    $column->setType(\Doctrine\DBAL\Types\Type::getType('smallint'));
                    $column->setOptions(['notnull' => true, 'default' => 1]);
                    $output->info('teamhub_team_apps.enabled changed BOOLEAN → SMALLINT (notnull, default 1)');
                    $changed = true;
                } else {
                    $output->info('teamhub_team_apps.enabled already correct type — skipping');
                }
            }
        }

        // ------------------------------------------------------------------
        // 2. teamhub_integration_registry.is_builtin
        // ------------------------------------------------------------------
        if ($schema->hasTable('teamhub_integration_registry')) {
            $table = $schema->getTable('teamhub_integration_registry');

            if ($table->hasColumn('is_builtin')) {
                $column = $table->getColumn('is_builtin');

                if ($column->getType()->getName() === 'boolean') {
                    $column->setType(\Doctrine\DBAL\Types\Type::getType('smallint'));
                    $column->setOptions(['notnull' => true, 'default' => 0]);
                    $output->info('teamhub_integration_registry.is_builtin changed BOOLEAN → SMALLINT (notnull, default 0)');
                    $changed = true;
                } else {
                    $output->info('teamhub_integration_registry.is_builtin already correct type — skipping');
                }
            }
        }

        return $changed ? $schema : null;
    }
}
