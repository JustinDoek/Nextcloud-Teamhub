<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.72.15 — Session B: add missing columns to teamhub_dec_tasks.
 *
 * The table was created in v3.64.0 with (decision_id, deck_card_id).
 * Session B evolves it to store any task path + team_id + label.
 *
 * Also adds decisions_action_min_level to teamhub_decision_team.
 */
class Version000372150Date20260609060000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_dec_tasks')) {
            $table = $schema->getTable('teamhub_dec_tasks');
            if (!$table->hasColumn('team_id')) {
                $table->addColumn('team_id', Types::STRING, [
                    'notnull' => false, 'length' => 64, 'default' => null,
                ]);
            }
            if (!$table->hasColumn('task_path')) {
                $table->addColumn('task_path', Types::STRING, [
                    'notnull' => false, 'length' => 500, 'default' => null,
                ]);
            }
            if (!$table->hasColumn('label')) {
                $table->addColumn('label', Types::STRING, [
                    'notnull' => false, 'length' => 255, 'default' => null,
                ]);
            }
            // The original v3.64.0 migration created deck_card_id as NOT NULL
            // with no default. New inserts via Session B's mapper don't set it
            // (they use task_path instead). Make it nullable so both old
            // (deck_card_id) and new (task_path) rows can coexist.
            if ($table->hasColumn('deck_card_id')) {
                $col = $table->getColumn('deck_card_id');
                $col->setNotnull(false);
                $col->setDefault(null);
            }
        }

        if ($schema->hasTable('teamhub_decision_team')) {
            $table = $schema->getTable('teamhub_decision_team');
            if (!$table->hasColumn('decisions_action_min_level')) {
                $table->addColumn('decisions_action_min_level', Types::SMALLINT, [
                    'notnull' => true, 'default' => 1,
                ]);
            }
        }

        return $schema;
    }
}
