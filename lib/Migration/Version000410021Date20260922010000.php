<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.10.21 - WorkflowHub phase 6: licensed workflow archiving.
 *
 *   teamhub_wf_archive      - one row per completed licensed workflow: the
 *                             authoritative record. Points at the workflow
 *                             instance it archives and holds what the
 *                             running workflow never had - the reference
 *                             number people quote, a seal over the event
 *                             log, and the retention metadata.
 *   teamhub_wf_archive_view - the projections. One row per audience
 *                             (requesting team, service team) per archive:
 *                             *which* team may read *which* record as
 *                             *which* audience, plus the keys a search
 *                             filters and sorts on. It holds no workflow
 *                             content, because the record already does;
 *                             two projections, one record.
 *   teamhub_wf_attachment   - documents attached to a workflow, each with
 *                             an explicit visibility. A row is a reference
 *                             to a file in Nextcloud (an oc_filecache id),
 *                             never a copy of it.
 *
 * Schema only: nothing seeds these tables and nothing back-fills them. A
 * workflow that ended before this version has no archive and never gains
 * one - archiving is something that happens *while* a workflow ends, in
 * the same transaction, and inventing a record for a workflow whose
 * ending nobody observed would be inventing the record's own provenance.
 * A fresh install and an upgrade therefore end in the same state: three
 * empty tables. No post step, no repair step.
 *
 * Every key is named explicitly (see the migrations skill, rule 1); no
 * column is named `at`, `type`, `position`, `state`, `group` or `status`,
 * which are keywords on one database or the other or already mean
 * something else on the workflow tables; every timestamp is BIGINT Unix
 * seconds; no BOOLEAN. Audience, outcome and visibility values are
 * strings the application defines - inlined here as comments only, never
 * as constants (rule 2).
 *
 * "One archive per workflow" and "one projection per audience per
 * archive" are unique indexes: a second archive row for one instance
 * would be the second independent copy the product rule forbids, and the
 * database is a better place to say so than a comment. The reference
 * number is unique for the same reason a reference number exists.
 */
class Version000410021Date20260922010000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('teamhub_wf_archive')) {
            $t = $schema->createTable('teamhub_wf_archive');
            $t->addColumn('id',                 Types::BIGINT,  ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            // teamhub_wf_instance.id - the authoritative workflow this
            // record archives. The one link; everything else is read
            // through it.
            $t->addColumn('instance_id',        Types::BIGINT,  ['notnull' => true,  'length' => 8]);
            // The human reference, e.g. WF-2026-000042. Quoted in email,
            // in a ticket, on the phone.
            $t->addColumn('ref_number',         Types::STRING,  ['notnull' => true,  'length' => 32]);
            // Copied from the instance so the record stays bound to the
            // definition version it was created on.
            $t->addColumn('definition_key',     Types::STRING,  ['notnull' => true,  'length' => 64]);
            $t->addColumn('definition_version', Types::INTEGER, ['notnull' => true,  'default' => 1]);
            // The team that asked (circles_circle.unique_id).
            $t->addColumn('team_id',            Types::STRING,  ['notnull' => true,  'length' => 64, 'default' => '']);
            // The service team that handled it; '' when none did.
            $t->addColumn('service_team_id',    Types::STRING,  ['notnull' => true,  'length' => 64, 'default' => '']);
            $t->addColumn('subject_type',       Types::STRING,  ['notnull' => true,  'length' => 32, 'default' => '']);
            $t->addColumn('subject_id',         Types::STRING,  ['notnull' => true,  'length' => 255, 'default' => '']);
            // The terminal instance status: completed | rejected | cancelled.
            $t->addColumn('wf_status',          Types::STRING,  ['notnull' => true,  'length' => 16, 'default' => '']);
            // The outcome in the engine's words; a filter key, not the
            // rendered outcome.
            $t->addColumn('outcome',            Types::STRING,  ['notnull' => true,  'length' => 32, 'default' => '']);
            $t->addColumn('started_by',         Types::STRING,  ['notnull' => true,  'length' => 64, 'default' => '']);
            $t->addColumn('started_at',         Types::BIGINT,  ['notnull' => true,  'length' => 8, 'default' => 0]);
            $t->addColumn('completed_by',       Types::STRING,  ['notnull' => true,  'length' => 64, 'default' => '']);
            $t->addColumn('completed_at',       Types::BIGINT,  ['notnull' => true,  'length' => 8, 'default' => 0]);
            $t->addColumn('archived_at',        Types::BIGINT,  ['notnull' => true,  'length' => 8, 'default' => 0]);
            // How many events the log held when it was sealed, and the
            // hash chain over them. Together they make the immutability of
            // the history checkable rather than merely asserted.
            $t->addColumn('event_count',        Types::INTEGER, ['notnull' => true,  'default' => 0]);
            $t->addColumn('event_seal',         Types::STRING,  ['notnull' => true,  'length' => 64, 'default' => '']);
            // 0 = keep indefinitely. Nothing deletes on this yet; the
            // retention pass is deferred and the column is the metadata
            // this phase promised.
            $t->addColumn('retention_until',    Types::BIGINT,  ['notnull' => true,  'length' => 8, 'default' => 0]);
            $t->addColumn('retention_policy',   Types::STRING,  ['notnull' => true,  'length' => 32, 'default' => '']);
            // 1 suspends retention. Nothing sets it yet.
            $t->addColumn('legal_hold',         Types::SMALLINT, ['notnull' => true, 'default' => 0]);

            $t->setPrimaryKey(['id'], 'th_wfa_pk');
            // One archive per workflow - the product rule, enforced.
            $t->addUniqueIndex(['instance_id'], 'th_wfa_inst_uq');
            $t->addUniqueIndex(['ref_number'], 'th_wfa_ref_uq');
            // A team's archive, newest first.
            $t->addIndex(['team_id', 'completed_at'], 'th_wfa_team_idx');
            // A desk's archive, newest first.
            $t->addIndex(['service_team_id', 'completed_at'], 'th_wfa_svc_idx');
            // The retention pass scans by window; legal_hold narrows it.
            $t->addIndex(['retention_until', 'legal_hold'], 'th_wfa_ret_idx');
            $changed = true;
        }

        if (!$schema->hasTable('teamhub_wf_archive_view')) {
            $t = $schema->createTable('teamhub_wf_archive_view');
            $t->addColumn('id',             Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $t->addColumn('archive_id',     Types::BIGINT, ['notnull' => true,  'length' => 8]);
            // requesting_team | service_team
            $t->addColumn('audience',       Types::STRING, ['notnull' => true,  'length' => 16]);
            // The team this projection belongs to: the requester's team,
            // or the service team.
            $t->addColumn('team_id',        Types::STRING, ['notnull' => true,  'length' => 64]);
            $t->addColumn('ref_number',     Types::STRING, ['notnull' => true,  'length' => 32, 'default' => '']);
            $t->addColumn('definition_key', Types::STRING, ['notnull' => true,  'length' => 64, 'default' => '']);
            // The catalogue service for a service request; '' otherwise.
            $t->addColumn('service_key',    Types::STRING, ['notnull' => true,  'length' => 64, 'default' => '']);
            $t->addColumn('outcome',        Types::STRING, ['notnull' => true,  'length' => 32, 'default' => '']);
            $t->addColumn('completed_at',   Types::BIGINT, ['notnull' => true,  'length' => 8, 'default' => 0]);
            // A lowercased haystack of the request's own words, for the
            // text filter. Never rendered: the reader's title comes from
            // the record, in the reader's language. Holds nothing internal.
            $t->addColumn('search_text',    Types::TEXT,   ['notnull' => false, 'default' => null]);

            $t->setPrimaryKey(['id'], 'th_wfav_pk');
            // One projection per audience per archive.
            $t->addUniqueIndex(['archive_id', 'audience'], 'th_wfav_aud_uq');
            // The search: "this audience, these teams, newest first".
            $t->addIndex(['audience', 'team_id', 'completed_at'], 'th_wfav_scope_idx');
            // "Which projections does this archive have" - and the delete.
            $t->addIndex(['archive_id'], 'th_wfav_arch_idx');
            // Somebody pasting a reference into the search box.
            $t->addIndex(['ref_number'], 'th_wfav_ref_idx');
            $changed = true;
        }

        if (!$schema->hasTable('teamhub_wf_attachment')) {
            $t = $schema->createTable('teamhub_wf_attachment');
            $t->addColumn('id',          Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $t->addColumn('instance_id', Types::BIGINT, ['notnull' => true,  'length' => 8]);
            // oc_filecache.fileid. The file stays where its owner put it.
            $t->addColumn('file_id',     Types::BIGINT, ['notnull' => true,  'length' => 8]);
            // The name when it was attached, so a record can still say
            // what was handed over when the file is gone.
            $t->addColumn('file_name',   Types::STRING, ['notnull' => true,  'length' => 255, 'default' => '']);
            // requester | internal. No default on purpose: a document
            // whose classification was never stated must not be storable.
            $t->addColumn('visibility',  Types::STRING, ['notnull' => true,  'length' => 16]);
            $t->addColumn('step_key',    Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $t->addColumn('added_by',    Types::STRING, ['notnull' => true,  'length' => 64, 'default' => '']);
            $t->addColumn('added_at',    Types::BIGINT, ['notnull' => true,  'length' => 8, 'default' => 0]);

            $t->setPrimaryKey(['id'], 'th_wfat_pk');
            // The same file attached twice would be two rows that could
            // disagree about their visibility.
            $t->addUniqueIndex(['instance_id', 'file_id'], 'th_wfat_file_uq');
            // "The documents of this workflow", which every render needs.
            $t->addIndex(['instance_id', 'visibility'], 'th_wfat_inst_idx');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
