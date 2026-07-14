<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.99.0 — Closing phase artifact (Track E Session 7).
 *
 * Adds `closing_artifact_at` to teamhub_project — the timestamp the
 * Closing-phase artifact was last generated. NULL until first generation.
 * Presence gates the Compass `closing_artifact` readiness item, which
 * blocks the "Ready to archive" state for Advanced projects until the
 * project's decisions/budget/time/milestones have been exported to the
 * team's Files folder.
 *
 * BIGINT to match the Unix-timestamp convention used elsewhere in the
 * schema (created_at, updated_at, milestone_date, worked_at, posted_at).
 */
class Version000399000Date20260708000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_project')) {
            $table = $schema->getTable('teamhub_project');
            if (!$table->hasColumn('closing_artifact_at')) {
                $table->addColumn('closing_artifact_at', Types::BIGINT, [
                    'notnull' => false,
                    'default' => null,
                ]);
            }
        }

        return $schema;
    }
}
