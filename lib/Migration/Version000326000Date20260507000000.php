<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.26.0 — Add suspended_resources column to teamhub_pending_dels.
 *
 * When a team is soft-deleted, TeamHub removes the team circle from each
 * connected NC app resource (Talk room attendee, Files circle share, Calendar
 * dav_shares row, Deck ACL row) so members lose access during the grace period.
 * The information needed to re-add those rows on restore is stored here as JSON.
 *
 * Schema:
 *   suspended_resources TEXT NULL — JSON blob, shape:
 *   {
 *     "talk":     { "room_id": int },
 *     "files":    { "share_id": int, "uid_initiator": str,
 *                   "file_source": int, "permissions": int },
 *     "calendar": { "calendar_id": int, "principal_uri": str },
 *     "deck":     { "board_id": int, "acl_table": str, "type": int }
 *   }
 *   Only the apps that were active for the team are present as keys.
 */
class Version000326000Date20260507000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('teamhub_pending_dels')) {
            $table = $schema->getTable('teamhub_pending_dels');

            if (!$table->hasColumn('suspended_resources')) {
                $table->addColumn('suspended_resources', Types::TEXT, [
                    'notnull' => false,
                    'default' => null,
                ]);
                $output->info('Version000326000: added suspended_resources to teamhub_pending_dels');
                $changed = true;
            } else {
                $output->info('Version000326000: suspended_resources already exists — skipping');
            }
        } else {
            $output->info('Version000326000: teamhub_pending_dels not found — skipping');
        }

        return $changed ? $schema : null;
    }
}
