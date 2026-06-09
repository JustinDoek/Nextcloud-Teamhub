<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.72.14 — Session B: evolve teamhub_dec_tasks + add action min-level.
 *
 * The table was originally created in v3.64.0 with (decision_id, deck_card_id).
 * Session B widens it to store any task path (not just Deck cards) and adds
 * team_id + label columns. This migration adds the missing columns if the
 * table already exists, or creates the full table on fresh installs.
 */
class Version000372120Date20260608200000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── 1. teamhub_dec_tasks ─────────────────────────────────────
        if ($schema->hasTable('teamhub_dec_tasks')) {
            // Table exists from v3.64.0 — add missing columns.
            $table = $schema->getTable('teamhub_dec_tasks');
            if (!$table->hasColumn('team_id')) {
                $table->addColumn('team_id', Types::STRING, [
                    'notnull' => false,
                    'length'  => 64,
                    'default' => null,
                ]);
            }
            if (!$table->hasColumn('task_path')) {
                $table->addColumn('task_path', Types::STRING, [
                    'notnull' => false,
                    'length'  => 500,
                    'default' => null,
                ]);
            }
            if (!$table->hasColumn('label')) {
                $table->addColumn('label', Types::STRING, [
                    'notnull' => false,
                    'length'  => 255,
                    'default' => null,
                ]);
            }
        } else {
            // Fresh install — create the full table.
            $table = $schema->createTable('teamhub_dec_tasks');
            $table->addColumn('id',          Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('decision_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('team_id',     Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('task_path',   Types::STRING, ['notnull' => false, 'length' => 500]);
            $table->addColumn('label',       Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('created_by',  Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('created_at',  Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['decision_id'], 'th_dectask_dec_idx');
        }

        // ── 2. decisions_action_min_level on teamhub_decision_team ──
        if ($schema->hasTable('teamhub_decision_team')) {
            $table = $schema->getTable('teamhub_decision_team');
            if (!$table->hasColumn('decisions_action_min_level')) {
                $table->addColumn('decisions_action_min_level', Types::SMALLINT, [
                    'notnull' => true,
                    'default' => 1,  // member (NC Circles level 1)
                ]);
            }
        }

        return $schema;
    }
}
