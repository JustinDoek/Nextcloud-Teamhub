<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.11.12 — a service request may point at the decisions behind it, and a
 * decision may be made public (Justin, 2026-09-30).
 *
 *   teamhub_wf_decision      — one row per decision linked to one workflow
 *                              instance. A pointer, never a copy: the
 *                              decision stays in its team's Decisions
 *                              module with the status, the approvers and
 *                              the audit trail it already has, and this
 *                              table holds only "this request is about
 *                              that decision", who said so and when.
 *   teamhub_decisions.is_public
 *                            — 0 | 1. Whether the decision is a public
 *                              record rather than a team-internal one.
 *
 * `is_public` is deliberately **not** a link capability. Justin's reasoning:
 * a public decision is the corner stone for more (a knowledge item
 * transformed out of it, a KnowledgeHub that lists it and links back), and
 * a secret-URL grant cannot be searched, listed or transformed. So the flag
 * says what the decision *is*, and every reader path derives from that.
 * What it buys today: the desk's keyword search when it links a decision to
 * a request finds the team's public decisions.
 *
 * SMALLINT rather than BOOLEAN, like every other flag on these tables:
 * Postgres and MariaDB disagree about comparing a boolean column against 1,
 * and a smallint compares the same on both. Default 0 — every decision
 * written before this version is team-internal, which is the true statement
 * about it, not a placeholder. `notnull => true` with a `0` default is safe
 * on an existing table: what Nextcloud refuses
 * (`MigrationService::ensureOracleConstraints`) is an empty-string or null
 * default, because on Oracle those are the same value.
 *
 * Every key is named explicitly (migrations skill, rule 1) and no
 * application constant is referenced (rule 2) — the values 0 and 1 are
 * inlined, and their meaning lives in `Decision`'s docblock.
 *
 * Schema only. Nothing to back-fill: no request has a decision yet, and a
 * decision that nobody has published is not public. No post step, no repair
 * step, so a fresh install and an upgrade end in the same state.
 */
class Version000411012Date20260930120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        // ── teamhub_wf_decision ───────────────────────────────────────────
        if (!$schema->hasTable('teamhub_wf_decision')) {
            $t = $schema->createTable('teamhub_wf_decision');
            $t->addColumn('id',          Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            // teamhub_wf_instance.id — the request this decision belongs to.
            $t->addColumn('instance_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            // teamhub_decisions.id. A soft link, like every other pointer on
            // the workflow tables: a deleted decision leaves a row that
            // resolves to nothing, and the reader drops it rather than the
            // database refusing the delete.
            $t->addColumn('decision_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            // The decision's team (circles_circle.unique_id) — the service
            // team handling the request, which is the only team a decision
            // may be linked from. Stored so a row can be rendered, and a
            // stale link recognised, without reading the decision back.
            $t->addColumn('team_id',     Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $t->addColumn('added_by',    Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $t->addColumn('added_at',    Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);

            $t->setPrimaryKey(['id'], 'th_wfdec_pk');
            // The same decision linked twice would be two rows saying one
            // thing. `instance_id` leads it, so "the decisions of this
            // request" — the only read the grid makes — is covered by this
            // index and needs no second one.
            $t->addUniqueIndex(['instance_id', 'decision_id'], 'th_wfdec_link_uq');
            // The other direction: "which requests point at this decision",
            // which is what unlinking a deleted decision has to ask.
            $t->addIndex(['decision_id'], 'th_wfdec_dec_idx');
            $changed = true;
            $output->info('teamhub_wf_decision: created');
        }

        // ── teamhub_decisions.is_public ───────────────────────────────────
        if ($schema->hasTable('teamhub_decisions')) {
            $table = $schema->getTable('teamhub_decisions');

            if (!$table->hasColumn('is_public')) {
                $table->addColumn('is_public', Types::SMALLINT, [
                    'notnull' => true,
                    'default' => 0,
                ]);
                $changed = true;
                $output->info('teamhub_decisions: added is_public');
            }

            // The desk's picker asks "this team's public decisions matching
            // these words" on every keystroke. team_id alone is not
            // selective enough once a team has years of records.
            if (!$table->hasIndex('th_dec_team_pub_idx')) {
                $table->addIndex(['team_id', 'is_public'], 'th_dec_team_pub_idx');
                $changed = true;
                $output->info('teamhub_decisions: added th_dec_team_pub_idx');
            }
        }

        return $changed ? $schema : null;
    }
}
