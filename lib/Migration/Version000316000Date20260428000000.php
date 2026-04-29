<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.16.0 — Audit log table for admin governance.
 *
 * Creates `teamhub_audit_log` (17 chars unprefixed — well under the 27-char limit).
 *
 * The table is treated as immutable by the application layer:
 *   - AuditLogMapper exposes only insert / read / bulk-purge operations
 *   - There is no controller endpoint for updating or deleting individual rows
 *   - The only DELETE path is the daily / hourly purge driven by retention policy
 *
 * Schema design notes:
 *   - `event_type` uses a namespaced string vocabulary (e.g. `member.joined`,
 *     `file.created`, `team.config_changed`).
 *   - `metadata` is a JSON blob for shape flexibility — diff payloads, share
 *     recipients, etc. Kept as TEXT so MySQL/MariaDB compatibility is unaffected.
 *   - `created_at` is a unix-seconds bigint (consistent with other TeamHub tables).
 *
 * Indexes:
 *   - (team_id, created_at DESC) — covers the paginated audit-tab query
 *   - (created_at)               — used by the purge job's range delete
 *   - (event_type)                — supports event-type filtering in the audit tab
 */
class Version000316000Date20260428000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('teamhub_audit_log')) {
            $output->info('Version000316000: creating teamhub_audit_log');

            $table = $schema->createTable('teamhub_audit_log');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
                'length'        => 8,
            ]);

            $table->addColumn('team_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            $table->addColumn('event_type', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            // Nullable for system-originated events with no human actor.
            $table->addColumn('actor_uid', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
            ]);

            // 'team', 'member', 'file', 'share', 'invite', 'app'
            $table->addColumn('target_type', Types::STRING, [
                'notnull' => false,
                'length'  => 32,
                'default' => null,
            ]);

            // uid, fileid, shareid, app id, etc.
            $table->addColumn('target_id', Types::STRING, [
                'notnull' => false,
                'length'  => 255,
                'default' => null,
            ]);

            // JSON blob — diffs, share recipients, group flags, etc.
            $table->addColumn('metadata', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);

            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
            ]);

            $table->setPrimaryKey(['id'], 'th_audit_log_pk');
            // Composite index supports the main paginated query in AuditController.
            $table->addIndex(['team_id', 'created_at'], 'th_audit_team_time');
            // Used by the purge job's range delete.
            $table->addIndex(['created_at'], 'th_audit_created');
            // Used by the audit-tab event-type filter.
            $table->addIndex(['event_type'], 'th_audit_event_type');

            $output->info('Version000316000: created teamhub_audit_log');
            $changed = true;
        } else {
            $output->info('Version000316000: teamhub_audit_log already exists — skipping');
        }

        return $changed ? $schema : null;
    }
}
