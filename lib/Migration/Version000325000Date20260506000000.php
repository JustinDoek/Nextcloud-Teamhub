<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.25.0 — Pending-deletion tracking for team archiving.
 *
 * Creates `teamhub_pending_dels` (20 chars unprefixed — well under the 27-char DBAL limit).
 *
 * A row is inserted when an owner clicks "Archive Team". It acts as both a
 * soft-delete marker (hides the team from all list endpoints during the grace
 * period) and a provenance record (where the archive lives, who requested it).
 *
 * Status lifecycle: pending → completed | restored | failed
 *   pending   — team is hidden; archive produced; awaiting hard-delete or restore
 *   completed — hard-delete has run; row kept for admin audit trail
 *   restored  — admin reversed the deletion within the grace period
 *   failed    — archive production failed; team visible again; retry possible
 *
 * Indexes:
 *   UNIQUE (team_id)               — one pending row per team at a time
 *   (hard_delete_at, status)       — daily cron scan for due rows
 *   (status)                       — admin panel filter by status
 */
class Version000325000Date20260506000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('teamhub_pending_dels')) {
            $output->info('Version000325000: creating teamhub_pending_dels');

            $table = $schema->createTable('teamhub_pending_dels');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
                'length'        => 8,
            ]);

            // circles_circle.unique_id of the team being archived.
            $table->addColumn('team_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            // Captured at archive time before circleService->destroy() runs.
            $table->addColumn('team_name', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
            ]);

            // Unix timestamp when the archive was initiated.
            $table->addColumn('archived_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
            ]);

            // Unix timestamp when the hard-delete should fire.
            // For mode=hard this equals archived_at (immediate).
            // For mode=soft30/soft60 this is archived_at + grace_seconds.
            $table->addColumn('hard_delete_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
            ]);

            // Absolute NC-Files path to the produced ZIP. NULL until archive completes.
            $table->addColumn('archive_path', Types::STRING, [
                'notnull' => false,
                'length'  => 2048,
                'default' => null,
            ]);

            // UID of the team owner who initiated the archive.
            $table->addColumn('archived_by', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            // 'pending' | 'completed' | 'restored' | 'failed'
            $table->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
                'default' => 'pending',
            ]);

            // Human-readable failure reason stored when status='failed'.
            $table->addColumn('failure_reason', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);

            // Size of the produced ZIP in bytes. Stored for admin overview display.
            $table->addColumn('archive_bytes', Types::BIGINT, [
                'notnull' => false,
                'length'  => 8,
                'default' => null,
            ]);

            $table->setPrimaryKey(['id'], 'th_pd_pk');
            // Enforces one pending row per team.
            $table->addUniqueIndex(['team_id'], 'th_pd_team_uniq');
            // Daily cron range scan for due rows.
            $table->addIndex(['hard_delete_at', 'status'], 'th_pd_due');
            // Admin status filter.
            $table->addIndex(['status'], 'th_pd_status');

            $output->info('Version000325000: created teamhub_pending_dels');
            $changed = true;
        } else {
            $output->info('Version000325000: teamhub_pending_dels already exists — skipping');
        }

        return $changed ? $schema : null;
    }
}
