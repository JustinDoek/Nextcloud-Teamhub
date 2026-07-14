<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.99.1 — Planning-phase manual-mark items.
 *
 * Adds two BIGINT NULL timestamp columns to teamhub_project:
 *   - charter_configured_at — set when an admin clicks "Mark as done" on
 *     the Planning-phase "Configure the project contract" Compass item.
 *   - kickoff_meeting_at     — set when an admin clicks "Mark as done" on
 *     the Planning-phase "Schedule a project team meeting" Compass item.
 *
 * Both columns hold a manual confirmation timestamp — there's no
 * programmatic signal for "the user reviewed the charter" or "the user
 * scheduled a kickoff", so the Compass surfaces an inline "Mark as done"
 * button next to those items and the click lands here.
 */
class Version000399001Date20260708100000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_project')) {
            $table = $schema->getTable('teamhub_project');
            if (!$table->hasColumn('charter_configured_at')) {
                $table->addColumn('charter_configured_at', Types::BIGINT, [
                    'notnull' => false,
                    'default' => null,
                ]);
            }
            if (!$table->hasColumn('kickoff_meeting_at')) {
                $table->addColumn('kickoff_meeting_at', Types::BIGINT, [
                    'notnull' => false,
                    'default' => null,
                ]);
            }
        }

        return $schema;
    }
}
