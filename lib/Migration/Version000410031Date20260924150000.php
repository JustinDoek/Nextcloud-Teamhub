<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.10.31 — the service builder, phase 8a (`docs/service-builder.md`).
 *
 * A service team builds its own services out of steps. Two changes:
 *
 *   teamhub_team_service   - one service a service team built. The team
 *                            edits a **draft**; publishing copies the draft
 *                            to **published** and raises `pub_version`. Both
 *                            are JSON documents the application defines
 *                            (title, description, category, lead time,
 *                            whether the form asks for a team, the steps).
 *                            A request copies its steps onto its own rows at
 *                            start, so a later publish never touches a
 *                            request that is already running.
 *
 *   teamhub_wf_step.role_label - the role a desk step needs, as the service
 *                            builder typed it ("Functional admin"). A label,
 *                            not a permission: any member of the service
 *                            team may claim the step. Copied onto the step
 *                            row like the step's own label, so renaming the
 *                            role later changes no running request.
 *
 * Schema only: nothing seeds a service, and a fresh install ends in the same
 * state as an upgrade — an empty table and a new column.
 *
 * Every key is named explicitly (`/migrations` rule 1). The new column on
 * the existing `teamhub_wf_step` is nullable with an empty-string default
 * (rule 1b); `WorkflowStep::getRoleLabel()` answers '' for NULL. No column is
 * named `version`, `state`, `type` or `position`. No application constant
 * is referenced here (rule 2).
 */
class Version000410031Date20260924150000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('teamhub_team_service')) {
            $t = $schema->createTable('teamhub_team_service');
            $t->addColumn('id',           Types::BIGINT,   ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            // circles_circle.unique_id of the service team that built it.
            $t->addColumn('team_id',      Types::STRING,   ['notnull' => true,  'length' => 64]);
            // The document the builder edits (JSON).
            $t->addColumn('draft',        Types::TEXT,     ['notnull' => false]);
            // The document requests start from (JSON); NULL until first published.
            $t->addColumn('published',    Types::TEXT,     ['notnull' => false]);
            // 0 = never published. Raised by every publish; stamped on each request.
            $t->addColumn('pub_version',  Types::INTEGER,  ['notnull' => true,  'default' => 0]);
            // 1 = on the Services page and startable; 0 = a draft, or unpublished.
            $t->addColumn('listed',       Types::SMALLINT, ['notnull' => true,  'default' => 0]);
            $t->addColumn('sort_order',   Types::SMALLINT, ['notnull' => true,  'default' => 1]);
            $t->addColumn('created_by',   Types::STRING,   ['notnull' => true,  'length' => 64, 'default' => '']);
            $t->addColumn('created_at',   Types::BIGINT,   ['notnull' => true,  'length' => 8, 'default' => 0]);
            $t->addColumn('updated_at',   Types::BIGINT,   ['notnull' => true,  'length' => 8, 'default' => 0]);
            $t->addColumn('published_by', Types::STRING,   ['notnull' => true,  'length' => 64, 'default' => '']);
            $t->addColumn('published_at', Types::BIGINT,   ['notnull' => true,  'length' => 8, 'default' => 0]);

            $t->setPrimaryKey(['id'], 'th_tsv_pk');
            $t->addIndex(['team_id'], 'th_tsv_team_idx');
            // "Which services does the Services page list" and "which do we register".
            $t->addIndex(['listed', 'pub_version'], 'th_tsv_listed_idx');
            $changed = true;
        }

        if ($schema->hasTable('teamhub_wf_step')) {
            $t = $schema->getTable('teamhub_wf_step');
            if (!$t->hasColumn('role_label')) {
                // Nullable on purpose: a NOT NULL column with an empty-string
                // default cannot be added to an existing table (`/migrations`
                // rule 1b). The entity reads NULL as ''.
                $t->addColumn('role_label', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => '']);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
