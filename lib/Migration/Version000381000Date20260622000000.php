<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.81.0 — Decisions schema repair.
 *
 * Some users hit "column ... does not exist" errors on the Decisions tables
 * after upgrading to v3.80, despite Version000372010 / Version000372120
 * having been recorded as run in oc_migrations. The original migrations are
 * idempotent (hasColumn checks) and well-formed, so the most likely cause is
 * a silent DDL rollback during an earlier upgrade batch that still left the
 * oc_migrations row in place. Once a migration version is marked run, NC
 * will never re-execute it — so the columns stay missing forever and
 * db:add-missing-columns can't help either (TeamHub doesn't subscribe to
 * AddMissingColumnsEvent).
 *
 * This migration re-asserts every column that was added by the v3.72.x
 * Decisions-evolution migrations, using the same hasColumn pattern. It is a
 * no-op for users whose schema is already correct, and self-heals for users
 * who drifted.
 *
 * Mirrors:
 *   Version000372010Date20260608000000 — level, decisions_level_enabled, icon, description
 *   Version000372120Date20260608200000 — decisions_action_min_level, team_id, task_path, label
 */
class Version000381000Date20260622000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── teamhub_decisions ─────────────────────────────────────────
        if ($schema->hasTable('teamhub_decisions')) {
            $table = $schema->getTable('teamhub_decisions');
            if (!$table->hasColumn('level')) {
                $output->info('[TeamHub] repair: adding missing teamhub_decisions.level');
                $table->addColumn('level', Types::STRING, [
                    'length'  => 16,
                    'notnull' => false,
                    'default' => 'operational',
                ]);
            }
        }

        // ── teamhub_decision_team ────────────────────────────────────
        if ($schema->hasTable('teamhub_decision_team')) {
            $table = $schema->getTable('teamhub_decision_team');
            if (!$table->hasColumn('decisions_level_enabled')) {
                $output->info('[TeamHub] repair: adding missing teamhub_decision_team.decisions_level_enabled');
                $table->addColumn('decisions_level_enabled', Types::SMALLINT, [
                    'notnull' => true,
                    'default' => 0,
                ]);
            }
            if (!$table->hasColumn('decisions_action_min_level')) {
                $output->info('[TeamHub] repair: adding missing teamhub_decision_team.decisions_action_min_level');
                $table->addColumn('decisions_action_min_level', Types::SMALLINT, [
                    'notnull' => true,
                    'default' => 1,
                ]);
            }
        }

        // ── teamhub_dec_categories ───────────────────────────────────
        if ($schema->hasTable('teamhub_dec_categories')) {
            $table = $schema->getTable('teamhub_dec_categories');
            if (!$table->hasColumn('icon')) {
                $output->info('[TeamHub] repair: adding missing teamhub_dec_categories.icon');
                $table->addColumn('icon', Types::STRING, [
                    'length'  => 64,
                    'notnull' => false,
                    'default' => null,
                ]);
            }
            if (!$table->hasColumn('description')) {
                $output->info('[TeamHub] repair: adding missing teamhub_dec_categories.description');
                $table->addColumn('description', Types::STRING, [
                    'length'  => 500,
                    'notnull' => false,
                    'default' => null,
                ]);
            }
        }

        // ── teamhub_dec_tasks ────────────────────────────────────────
        if ($schema->hasTable('teamhub_dec_tasks')) {
            $table = $schema->getTable('teamhub_dec_tasks');
            if (!$table->hasColumn('team_id')) {
                $output->info('[TeamHub] repair: adding missing teamhub_dec_tasks.team_id');
                $table->addColumn('team_id', Types::STRING, [
                    'notnull' => false,
                    'length'  => 64,
                    'default' => null,
                ]);
            }
            if (!$table->hasColumn('task_path')) {
                $output->info('[TeamHub] repair: adding missing teamhub_dec_tasks.task_path');
                $table->addColumn('task_path', Types::STRING, [
                    'notnull' => false,
                    'length'  => 500,
                    'default' => null,
                ]);
            }
            if (!$table->hasColumn('label')) {
                $output->info('[TeamHub] repair: adding missing teamhub_dec_tasks.label');
                $table->addColumn('label', Types::STRING, [
                    'notnull' => false,
                    'length'  => 255,
                    'default' => null,
                ]);
            }
        }

        return $schema;
    }
}
