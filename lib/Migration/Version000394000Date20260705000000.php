<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.94.0 — Project-level Budget view floor.
 *
 * Adds `budget_view_min_level` to teamhub_project. This is the single team
 * role level required to see the Budget tab on the team home. Replaces the
 * previous per-lane `view_min_level` (still in the teamhub_budget_lane
 * schema but no longer consulted — see DESIGN.md §2.44 addendum 2).
 *
 * Default 1 (every member) so existing Advanced projects continue to show
 * the tab to everyone until an admin narrows it.
 */
class Version000394000Date20260705000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_project')) {
            return null;
        }
        $table = $schema->getTable('teamhub_project');
        if (!$table->hasColumn('budget_view_min_level')) {
            $table->addColumn('budget_view_min_level', Types::SMALLINT, [
                'notnull' => true,
                'default' => 1,
            ]);
        }
        return $schema;
    }
}
