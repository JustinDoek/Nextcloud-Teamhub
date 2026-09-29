<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.10.13 — WorkflowHub phase 1: the four workflow tables
 * (`docs/workflowhub-architecture.md` §6).
 *
 *   teamhub_wf_instance    (19) — one row per workflow started from a definition
 *   teamhub_wf_step        (15) — the instance's own copy of the definition's steps
 *   teamhub_wf_participant (22) — every actor connected to the instance
 *   teamhub_wf_event       (16) — append-only log of everything that happened
 *
 * Schema only: nothing seeds these tables, and nothing reads them until the
 * engine is asked to create a workflow, so a fresh install (schema-only,
 * `/migrations` §2a) and an upgrade end in the same state — four empty
 * tables. No post step.
 *
 * Every key is named explicitly (`/migrations` §1); no column is named
 * `at`, `type`, `position` or `state`, which are keywords on one database
 * or the other; every timestamp is BIGINT Unix seconds; no BOOLEAN. The
 * status values are strings the application defines — inlined here as
 * defaults only, never as constants (`/migrations` §2).
 *
 * "One open workflow per team per kind" is enforced by the engine inside
 * its transaction, not by a partial unique index: MySQL and Postgres do
 * not agree on those.
 */
class Version000410013Date20260921000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('teamhub_wf_instance')) {
            $t = $schema->createTable('teamhub_wf_instance');
            $t->addColumn('id',                 Types::BIGINT,   ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $t->addColumn('definition_key',     Types::STRING,   ['notnull' => true,  'length' => 64]);
            $t->addColumn('definition_version', Types::INTEGER,  ['notnull' => true,  'default' => 1]);
            // circles_circle.unique_id; '' for an instance-scoped workflow (none in this phase).
            $t->addColumn('team_id',            Types::STRING,   ['notnull' => true,  'length' => 64, 'default' => '']);
            $t->addColumn('subject_type',       Types::STRING,   ['notnull' => true,  'length' => 32, 'default' => '']);
            $t->addColumn('subject_id',         Types::STRING,   ['notnull' => true,  'length' => 255, 'default' => '']);
            // submitted | in_progress | waiting | blocked | cancelled | rejected | completed
            $t->addColumn('status',             Types::STRING,   ['notnull' => true,  'length' => 16, 'default' => 'submitted']);
            $t->addColumn('outcome',            Types::STRING,   ['notnull' => false, 'length' => 32, 'default' => null]);
            // key of the active step while open; null once ended
            $t->addColumn('current_step',       Types::STRING,   ['notnull' => false, 'length' => 64, 'default' => null]);
            $t->addColumn('started_by',         Types::STRING,   ['notnull' => true,  'length' => 64]);
            $t->addColumn('started_at',         Types::BIGINT,   ['notnull' => true,  'length' => 8, 'default' => 0]);
            $t->addColumn('updated_at',         Types::BIGINT,   ['notnull' => true,  'length' => 8, 'default' => 0]);
            $t->addColumn('ended_by',           Types::STRING,   ['notnull' => false, 'length' => 64, 'default' => null]);
            $t->addColumn('ended_at',           Types::BIGINT,   ['notnull' => false, 'length' => 8, 'default' => null]);
            // 0 = keep; the maintenance pass of a later phase sets it per licence tier.
            $t->addColumn('retention_until',    Types::BIGINT,   ['notnull' => true,  'length' => 8, 'default' => 0]);
            $t->addColumn('data_json',          Types::TEXT,     ['notnull' => false, 'default' => null]);

            $t->setPrimaryKey(['id'], 'th_wfi_pk');
            // A team's workflows, open ones first: the concurrency check and the team listing.
            $t->addIndex(['team_id', 'status'], 'th_wfi_team_idx');
            // Every open instance of one definition: import, maintenance, diagnostics.
            $t->addIndex(['definition_key', 'status'], 'th_wfi_def_idx');
            // "Is there a workflow about this thing?"
            $t->addIndex(['subject_type', 'subject_id'], 'th_wfi_subj_idx');
            // The prune scans by retention.
            $t->addIndex(['retention_until'], 'th_wfi_ret_idx');
            // Listings order by recency.
            $t->addIndex(['updated_at'], 'th_wfi_upd_idx');
            $changed = true;
        }

        if (!$schema->hasTable('teamhub_wf_step')) {
            $t = $schema->createTable('teamhub_wf_step');
            $t->addColumn('id',           Types::BIGINT,   ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $t->addColumn('instance_id',  Types::BIGINT,   ['notnull' => true,  'length' => 8]);
            $t->addColumn('step_key',     Types::STRING,   ['notnull' => true,  'length' => 64]);
            $t->addColumn('step_order',   Types::SMALLINT, ['notnull' => true,  'default' => 1]);
            // pending | available | in_progress | waiting_for_information | completed | skipped | rejected | cancelled
            $t->addColumn('step_status',  Types::STRING,   ['notnull' => true,  'length' => 32, 'default' => 'pending']);
            // user | group | team_owner | team_moderator | team
            $t->addColumn('actor_type',   Types::STRING,   ['notnull' => true,  'length' => 16]);
            // uid or group id; '' for the team-relative actors
            $t->addColumn('actor_id',     Types::STRING,   ['notnull' => true,  'length' => 64, 'default' => '']);
            $t->addColumn('label',        Types::STRING,   ['notnull' => true,  'length' => 255, 'default' => '']);
            $t->addColumn('entered_at',   Types::BIGINT,   ['notnull' => false, 'length' => 8, 'default' => null]);
            $t->addColumn('started_at',   Types::BIGINT,   ['notnull' => false, 'length' => 8, 'default' => null]);
            $t->addColumn('completed_at', Types::BIGINT,   ['notnull' => false, 'length' => 8, 'default' => null]);
            $t->addColumn('completed_by', Types::STRING,   ['notnull' => false, 'length' => 64, 'default' => null]);
            $t->addColumn('action_taken', Types::STRING,   ['notnull' => false, 'length' => 32, 'default' => null]);
            $t->addColumn('reason',       Types::TEXT,     ['notnull' => false, 'default' => null]);

            $t->setPrimaryKey(['id'], 'th_wfs_pk');
            // One row per step key per instance; also the "steps of this instance" lookup.
            $t->addUniqueIndex(['instance_id', 'step_key'], 'th_wfs_inst_uq');
            // "Steps currently waiting on this actor."
            $t->addIndex(['actor_type', 'actor_id', 'step_status'], 'th_wfs_actor_idx');
            $changed = true;
        }

        if (!$schema->hasTable('teamhub_wf_participant')) {
            $t = $schema->createTable('teamhub_wf_participant');
            $t->addColumn('id',          Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $t->addColumn('instance_id', Types::BIGINT, ['notnull' => true,  'length' => 8]);
            $t->addColumn('actor_type',  Types::STRING, ['notnull' => true,  'length' => 16]);
            $t->addColumn('actor_id',    Types::STRING, ['notnull' => true,  'length' => 64, 'default' => '']);
            // initiator | responsible | actor | observer
            $t->addColumn('wf_role',     Types::STRING, ['notnull' => true,  'length' => 16]);
            $t->addColumn('added_at',    Types::BIGINT, ['notnull' => true,  'length' => 8, 'default' => 0]);
            $t->addColumn('added_by',    Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $t->addColumn('removed_at',  Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);

            $t->setPrimaryKey(['id'], 'th_wfp_pk');
            // The participant listing: "instances where this actor takes part".
            $t->addIndex(['actor_type', 'actor_id'], 'th_wfp_actor_idx');
            // "Who takes part in this instance."
            $t->addIndex(['instance_id'], 'th_wfp_inst_idx');
            $changed = true;
        }

        if (!$schema->hasTable('teamhub_wf_event')) {
            $t = $schema->createTable('teamhub_wf_event');
            $t->addColumn('id',           Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $t->addColumn('instance_id',  Types::BIGINT, ['notnull' => true,  'length' => 8]);
            $t->addColumn('step_id',      Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);
            $t->addColumn('step_key',     Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $t->addColumn('event_type',   Types::STRING, ['notnull' => true,  'length' => 32]);
            // null for the engine's own passes
            $t->addColumn('actor_uid',    Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $t->addColumn('occurred_at',  Types::BIGINT, ['notnull' => true,  'length' => 8, 'default' => 0]);
            $t->addColumn('payload_json', Types::TEXT,   ['notnull' => false, 'default' => null]);

            $t->setPrimaryKey(['id'], 'th_wfe_pk');
            // The history of one instance, in order.
            $t->addIndex(['instance_id', 'occurred_at'], 'th_wfe_inst_idx');
            // "Is there an open request of this kind on this instance."
            $t->addIndex(['instance_id', 'event_type'], 'th_wfe_type_idx');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
