<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.14.8 — a member's shifts copied into their own calendar (DESIGN §2.250).
 *
 * One row per (shift, person) that TeamHub has written into that person's
 * calendar. It exists so the copy can be **taken back out**: when somebody
 * loses a shift (unassigned, handed over, the week taken back to concept, the
 * shift deleted) the event comes out of their calendar with it. §2.215 and
 * §2.246 are why that is not optional: TeamHub stopped writing into personal
 * calendars once before precisely because it had no reliable way to clean up
 * after itself, and the presence events it left behind still answer busy.
 *
 * `calendar_id` + `object_uri` is the address of the event, so removing it
 * never has to search. `transp` is what the member chose (`OPAQUE` busy,
 * `TRANSPARENT` free), kept so a later rewrite of the same event keeps it.
 *
 * Rule compliance (`/migrations`): a new table of 16 characters, every
 * identifier named explicitly and under 20 characters (rule 1); new table, so
 * the `''` defaults are allowed (rule 1b); no `OCA\TeamHub\` reference (rule
 * 2); no seed (rule 2a); no Doctrine type named (rule 2b).
 */
class Version000414008Date20261110120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_roster_cal')) {
            $t = $schema->createTable('teamhub_roster_cal');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $t->addColumn('shift_id', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
            $t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            // The team, so a deleted team's rows can be found without a join
            // to shifts that may already be gone.
            $t->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $t->addColumn('calendar_id', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
            $t->addColumn('object_uri', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $t->addColumn('transp', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'OPAQUE']);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
            $t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);

            $t->setPrimaryKey(['id'], 'th_rcal_pk');
            // One copy per person per shift: exporting the week again
            // rewrites the event rather than adding a second one.
            $t->addUniqueIndex(['shift_id', 'user_id'], 'th_rcal_uq');
            $t->addIndex(['team_id'], 'th_rcal_team_idx');
            $output->info('Version000414008: creating teamhub_roster_cal');
        }

        return $schema;
    }
}
