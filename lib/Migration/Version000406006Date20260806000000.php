<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.6.6 — bulk team import.
 *
 * Two tables, one run each:
 *
 * **`teamhub_team_imports`** — the run. Created by `validate()` the moment a
 * CSV parses, before the admin has confirmed anything, so a preview is a
 * durable object rather than a blob held in the browser: confirming is a state
 * flip (`validated` → `running`), not a re-upload.
 *
 * **`teamhub_team_import_rows`** — one row per CSV line, carrying the
 * normalised payload as JSON. TEXT rather than a column per field because the
 * shape is the CSV contract's, not the database's — members and apps are
 * multi-valued, and nothing queries inside them. What *is* queried lives in its
 * own columns: `status` to claim work, `team_id` and `message` to report it.
 *
 * **`heartbeat_at`** is how the sweeper tells a run that is still being pumped
 * by an open browser tab from one whose admin closed it. Every chunk touches
 * it; `TeamImportJob` adopts a `running` import whose heartbeat is older than
 * five minutes. Without it the job cannot distinguish "in progress" from
 * "abandoned" and would either double-provision or never resume.
 *
 * Identifier lengths (SKILLS.md § Database identifier length): `teamhub_team_
 * import_rows` is 24 characters, so Doctrine's auto-generated
 * `teamhub_team_import_rows_pkey` would be 29 — inside the 30-character cap
 * but with one character of headroom, and composite index names blow past it
 * outright. Every primary key and index here is therefore named explicitly.
 *
 * Postgres compatibility: BIGINT for ids and every timestamp, no BOOLEAN
 * columns (DESIGN.md §2.4), no reserved words among the column names, and
 * every index named. Counters default to 0 rather than being nullable so the
 * progress endpoint never has to decide what a NULL count means.
 */
class Version000406006Date20260806000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        // ── teamhub_team_imports ──────────────────────────────────────────
        if (!$schema->hasTable('teamhub_team_imports')) {
            $imports = $schema->createTable('teamhub_team_imports');

            $imports->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'length'        => 20,
            ]);
            // NC user ids are capped at 64 characters.
            $imports->addColumn('created_by', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            // Shown back to the admin in the recent-runs list so they can tell
            // two imports apart. Stored as uploaded, rendered escaped by Vue.
            $imports->addColumn('filename', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
                'default' => '',
            ]);
            // validated | running | completed | cancelled
            $imports->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
                'default' => 'validated',
            ]);
            $imports->addColumn('total_rows', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $imports->addColumn('created_count', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $imports->addColumn('skipped_count', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $imports->addColumn('failed_count', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $imports->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            // Null until the admin confirms — a validated run that is discarded
            // never started, and 0 would read as "started at the epoch".
            $imports->addColumn('started_at', Types::BIGINT, [
                'notnull' => false,
                'default' => null,
            ]);
            $imports->addColumn('finished_at', Types::BIGINT, [
                'notnull' => false,
                'default' => null,
            ]);
            $imports->addColumn('heartbeat_at', Types::BIGINT, [
                'notnull' => false,
                'default' => null,
            ]);

            $imports->setPrimaryKey(['id'], 'th_timp_pk');
            // The background job's only question: which runs are still running.
            // Also serves the recent-runs list, which orders by id within status.
            $imports->addIndex(['status'], 'th_timp_status_idx');

            $changed = true;
            $output->info('teamhub_team_imports: created');
        }

        // ── teamhub_team_import_rows ──────────────────────────────────────
        if (!$schema->hasTable('teamhub_team_import_rows')) {
            $rows = $schema->createTable('teamhub_team_import_rows');

            $rows->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'length'        => 20,
            ]);
            $rows->addColumn('import_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            // 1-based line number as the admin sees it in their spreadsheet,
            // counting the header as row 1 — so a reported row number can be
            // typed straight into the editor's go-to-line box.
            $rows->addColumn('row_num', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            // The normalised row as JSON: name, description, template,
            // project_mode, owner, members[], apps[], modules[], plus the
            // warnings raised at validation time.
            $rows->addColumn('payload', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            // pending | running | created | skipped | failed
            $rows->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
                'default' => 'pending',
            ]);
            // Circles unique_id of the created team. 64 matches the width every
            // other TeamHub table uses for a team id.
            $rows->addColumn('team_id', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
            ]);
            // Why it was skipped, what failed, or which best-effort steps did
            // not complete. TEXT because a row can accumulate several notes.
            $rows->addColumn('message', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);

            $rows->setPrimaryKey(['id'], 'th_timpr_pk');
            // Every read of this table is scoped to one import, and the chunk
            // claim narrows that to one status. Composite in that order so the
            // progress read (import only) uses the same index as the claim.
            $rows->addIndex(['import_id', 'status'], 'th_timpr_imp_idx');

            $changed = true;
            $output->info('teamhub_team_import_rows: created');
        }

        return $changed ? $schema : null;
    }
}
