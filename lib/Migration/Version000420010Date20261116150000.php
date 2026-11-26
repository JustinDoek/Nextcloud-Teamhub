<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.20.10 — recurring team meetings that get notes and a chat reminder before
 * each date (DESIGN §2.264).
 *
 * One row per series created through Add meeting. `MeetingSeriesPrepJob`
 * reads the series from the team calendar itself (so a date moved or cancelled
 * in Calendar is respected) and uses this row only for what the calendar does
 * not hold: who organised it (the notes file and the chat message are written
 * as them), how many days ahead to prepare (`lead_days`, 1 to 7), whom to
 * mention, and how far it has got (`prepared_until`, the start of the last
 * occurrence it prepared, so nothing is posted twice).
 *
 * Rule compliance (`/migrations`): a new table of 18 characters, every
 * identifier named explicitly and under 20 characters (rule 1); new table, so
 * the `''` defaults are allowed (rule 1b); no `OCA\TeamHub\` reference (rule
 * 2); no seed (rule 2a); no Doctrine type named (rule 2b).
 */
class Version000420010Date20261116150000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_mtg_series')) {
            $t = $schema->createTable('teamhub_mtg_series');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $t->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $t->addColumn('event_uid', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $t->addColumn('organiser', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $t->addColumn('lead_days', Types::SMALLINT, ['notnull' => true, 'default' => 2]);
            // Comma-separated uids of the invited people, mentioned in the reminder.
            $t->addColumn('attendees', Types::TEXT, ['notnull' => false]);
            $t->addColumn('prepared_until', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);

            $t->setPrimaryKey(['id'], 'th_mtgs_pk');
            $t->addUniqueIndex(['team_id', 'event_uid'], 'th_mtgs_uq');
            $output->info('Version000420010: creating teamhub_mtg_series');
        }

        return $schema;
    }
}
