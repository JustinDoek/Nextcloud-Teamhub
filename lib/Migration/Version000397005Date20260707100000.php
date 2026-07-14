<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.97.5 — Optional milestone linkage on decisions (Track E follow-up).
 *
 * Adds `milestone_id BIGINT NULL` to teamhub_decisions. NULL for every
 * existing row and for every decision on a non-project team. Advanced
 * project teams can now attach a proposal to a specific milestone at
 * propose time.
 *
 * Foreign-key-shaped (references teamhub_milestones.id) but no hard
 * constraint — matches how BudgetLane.deck_stack_id references
 * deck_stacks: soft link, defensive lookup on read. If a milestone is
 * deleted, decisions linked to it keep their milestone_id but the
 * serializer resolves the label as null and the frontend hides the chip.
 */
class Version000397005Date20260707100000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_decisions')) {
            $table = $schema->getTable('teamhub_decisions');
            if (!$table->hasColumn('milestone_id')) {
                $table->addColumn('milestone_id', Types::BIGINT, [
                    'notnull' => false,
                    'default' => null,
                ]);
            }
        }

        return $schema;
    }
}
