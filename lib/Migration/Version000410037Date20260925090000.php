<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.10.37 — several tasks in one step of a built service
 * (`docs/service-builder.md` § 4, phase 8b).
 *
 * A task is a `teamhub_wf_step` row. The tasks of one step share the step's
 * `step_order`, which was never unique (the unique key is instance +
 * step_key), so claiming, assigning, releasing, the queue and the
 * statistics all keep working on rows — now one per task. Two columns say
 * what a row alone could not:
 *
 *   stage_label   - the step's own name when it holds several tasks; the
 *                   row's `label` is then the task's. '' for a step of one
 *                   task, which is every step before this version.
 *   non_blocking  - 1 when the request may move on while this task is still
 *                   open; it must be done before the request can close.
 *                   0 for every other row.
 *
 * Schema only. Both are new columns on an existing table, so nullable with
 * their default (`/migrations` rule 1b); `WorkflowStep` reads NULL as '' and
 * 0. No application constant is referenced here (rule 2).
 */
class Version000410037Date20260925090000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_wf_step')) {
            return null;
        }
        $t       = $schema->getTable('teamhub_wf_step');
        $changed = false;
        if (!$t->hasColumn('stage_label')) {
            $t->addColumn('stage_label', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => '']);
            $changed = true;
        }
        if (!$t->hasColumn('non_blocking')) {
            $t->addColumn('non_blocking', Types::SMALLINT, ['notnull' => false, 'default' => 0]);
            $changed = true;
        }
        return $changed ? $schema : null;
    }
}
