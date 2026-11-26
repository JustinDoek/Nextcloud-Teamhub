<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.20.58 — approvals on a service request (`docs/service-builder.md`
 * § 14.4, phase 8h).
 *
 *   step_type  - 'approval' for a step somebody approves or rejects; NULL
 *                for every other step, which is every step before this
 *                version.
 *   config     - JSON: what the service said about the approval when the
 *                request started (its words, what a rejection does), or who
 *                asked for it and why. On the row rather than in the
 *                definition, because a service may be published again while
 *                the request runs.
 *
 * Schema only. Both are new columns on an existing table, so nullable
 * (`/migrations` rule 1b). No application constant is referenced (rule 2).
 */
class Version000420058Date20261125200000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_wf_step')) {
            return null;
        }
        $t       = $schema->getTable('teamhub_wf_step');
        $changed = false;
        if (!$t->hasColumn('step_type')) {
            $t->addColumn('step_type', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
            $changed = true;
        }
        if (!$t->hasColumn('config')) {
            $t->addColumn('config', Types::TEXT, ['notnull' => false, 'default' => null]);
            $changed = true;
        }
        return $changed ? $schema : null;
    }
}
