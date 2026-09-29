<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.10.36 — links on the steps of a built service
 * (`docs/service-builder.md` § 6.1, phase 8f).
 *
 *   teamhub_wf_step.links - the links a service team put on the step in the
 *                           service builder: a JSON list of
 *                           {label, url, kind}, every url https://. Copied
 *                           onto the step row when the request starts, like
 *                           the step's label and role, so publishing a new
 *                           version of the service changes no running
 *                           request. NULL for every step without links,
 *                           which is every step of a built-in workflow.
 *
 * Schema only. The new column on the existing `teamhub_wf_step` is nullable
 * and has no default (`/migrations` rule 1b); `WorkflowStep::getLinks()`
 * answers [] for NULL. No application constant is referenced here (rule 2).
 */
class Version000410036Date20260924180000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_wf_step')) {
            return null;
        }
        $t = $schema->getTable('teamhub_wf_step');
        if ($t->hasColumn('links')) {
            return null;
        }
        $t->addColumn('links', Types::TEXT, ['notnull' => false]);
        return $schema;
    }
}
