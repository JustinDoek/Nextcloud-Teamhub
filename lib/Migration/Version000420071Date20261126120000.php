<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.20.71 — fields on later steps of a service request
 * (`docs/service-builder.md` § 14.6, phase 8j).
 *
 *   answers - JSON: what was filled in when the task was completed, with the
 *             labels it was asked under. NULL for a task without fields,
 *             which is every task before this version.
 *
 * Schema only. A new column on an existing table, so nullable (`/migrations`
 * rule 1b). No application constant is referenced (rule 2).
 */
class Version000420071Date20261126120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_wf_step')) {
            return null;
        }
        $t = $schema->getTable('teamhub_wf_step');
        if ($t->hasColumn('answers')) {
            return null;
        }
        $t->addColumn('answers', Types::TEXT, ['notnull' => false, 'default' => null]);
        return $schema;
    }
}
