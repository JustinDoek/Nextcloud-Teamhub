<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.99.3 — Closing-phase evaluation-meeting mark.
 *
 * Adds `evaluation_meeting_at` (BIGINT NULL) to teamhub_project. Same
 * manual-mark pattern as `charter_configured_at` / `kickoff_meeting_at`
 * from v3.99.1 — set when an admin clicks "Mark as done" on the
 * Closing-phase "Organise an evaluation meeting" Compass item. Advisory:
 * the user can close the project without it.
 */
class Version000399003Date20260708200000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_project')) {
            $table = $schema->getTable('teamhub_project');
            if (!$table->hasColumn('evaluation_meeting_at')) {
                $table->addColumn('evaluation_meeting_at', Types::BIGINT, [
                    'notnull' => false,
                    'default' => null,
                ]);
            }
        }

        return $schema;
    }
}
