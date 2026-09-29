<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.10.50 — adopting teams made outside TeamHub (DESIGN §2.149).
 *
 * One row per circle TeamHub found that it did not create: made in Contacts,
 * on Nextcloud's Teams page, by occ or by a provisioner. The row is the
 * decision about it — pending until somebody accepts or declines, and kept
 * afterwards, because a declined team is never offered again and the sweep
 * that finds candidates skips every circle that has a row.
 *
 *   team_id          circles_circle.unique_id; unique — one decision per team
 *   team_name        the circle's name when it was found, for the record
 *   owner_uid        the circle's owner when it was found
 *   status           pending | accepted | declined | withdrawn
 *   route            auto | desk | admin | grid — who was asked
 *   workflow_id      the request in a queue or My Work; NULL on routes that have none
 *   template_key     the template chosen in the grid ('' = the default)
 *   profile_key      the policy chosen in the grid ('' = the template's default)
 *   decided_by       uid; '' for an automatic acceptance
 *   decided_at       epoch seconds; NULL while pending
 *   reason           why it was declined or withdrawn
 *   provision_status none | queued | done | failed — the template's apps, after acceptance
 *   provision_note   what failed, in the words the grid shows
 *   detected_at      epoch seconds
 *
 * A new table, so `notnull` with a `''` default is fine (`/migrations` rule 1b
 * only bites a new column on an existing table). The table name is 21
 * characters and every key is named (rule 1). No application constant is
 * referenced (rule 2); the status and route values above are the literals
 * the code writes.
 */
class Version000410050Date20260926120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_team_adoption')) {
            return null;
        }

        $t = $schema->createTable('teamhub_team_adoption');
        $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
        $t->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        $t->addColumn('team_name', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
        $t->addColumn('owner_uid', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
        $t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
        $t->addColumn('route', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'grid']);
        $t->addColumn('workflow_id', Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);
        $t->addColumn('template_key', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
        $t->addColumn('profile_key', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
        $t->addColumn('decided_by', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
        $t->addColumn('decided_at', Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);
        $t->addColumn('reason', Types::STRING, ['notnull' => true, 'length' => 1000, 'default' => '']);
        $t->addColumn('provision_status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'none']);
        $t->addColumn('provision_note', Types::STRING, ['notnull' => true, 'length' => 1000, 'default' => '']);
        $t->addColumn('detected_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);

        $t->setPrimaryKey(['id'], 'th_tadopt_pk');
        $t->addUniqueIndex(['team_id'], 'th_tadopt_team_uq');
        // The grid's two reads: what is pending, what was decided recently.
        $t->addIndex(['status', 'decided_at'], 'th_tadopt_status_idx');

        return $schema;
    }
}
