<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.13.67 — the roster: shift types, shifts and assignments (DESIGN §2.233).
 *
 * Three tables. The People module already answers "where is this person" from
 * a half-day workplace the person declares about themselves; the roster
 * answers the different question "who works when", which somebody else decides
 * and which has a coverage target attached to it.
 *
 *   teamhub_shift_type     the vocabulary — Early 07:00-15:00, Late, Night.
 *                          Instance-wide and administrator-managed, exactly
 *                          like teamhub_presence_types, because a shift name
 *                          means the same thing across an organisation and
 *                          because the admin surface for it already exists.
 *   teamhub_roster_shift   one shift that needs covering: a team, a date, a
 *                          shift type, optionally a room, and how many people
 *                          it takes. Draft until `published_at` is non-zero.
 *   teamhub_roster_assign  who is on it, and whether somebody put them there
 *                          or they took it themselves.
 *
 * ## Why times live on the type and not on the shift
 *
 * A roster cell is then a *name* rather than a time range, which is what makes
 * a week of it scannable, and it gives reporting a vocabulary to group by. A
 * shift whose hours differ from its type is a v2 question (`/ui-standards`'s
 * rule that a feature is not improved by a second code path nobody asked for);
 * when it comes, it is two nullable columns here and nothing else moves.
 *
 * Times are stored as `HH:MM` wall-clock strings, not timestamps. A shift type
 * is a floating pattern in the same sense a presence half-day is a floating
 * date (DESIGN §2.4): "Early is 07:00" is true in every timezone the instance
 * serves, and resolving it to an instant before anybody has asked about a
 * specific date would be the timezone bug §2.4 exists to prevent.
 *
 * ## Why `published_at` is a column from the first version
 *
 * Dutch Arbeidstijdenwet art. 4:2 requires a roster to be communicated to the
 * worker at least 28 days ahead (4 days for the start and end times where the
 * work makes a fixed pattern impossible), a CAO may set a different term, and
 * it must be recorded in writing. We do not implement the notice warning here.
 * **But a notice rule cannot be retrofitted onto rows that never recorded when
 * they were published**, so the column and its `published_by` land now, while
 * the table is empty and it costs nothing.
 *
 * Rule compliance (`/migrations`): three brand-new tables, so rule 1b does not
 * apply and `notnull` with a `''` / `0` default is correct. The longest name
 * is 21 characters (`teamhub_roster_assign_pkey` = 26, under the 30 cap) and
 * every key is still named explicitly, per rule 1. No `OCA\TeamHub\` constant
 * or class is referenced (rule 2) — `'assigned'` is the literal this migration
 * writes, and application code reads it from a mapper constant. The built-in
 * shift types are **not** seeded here (rule 2a: a fresh install never runs a
 * post step); they are in `RepairSteps\SeedShiftTypes`.
 */
class Version000413067Date20261101120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_shift_type')) {
            $t = $schema->createTable('teamhub_shift_type');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            // The stable key. Labels are renamed by administrators and
            // translated; code and reports key on the slug, same contract as
            // teamhub_presence_types.
            $t->addColumn('slug', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 128, 'default' => '']);
            // Wall clock, 'HH:MM'. end <= start means the shift ends the next
            // day; nothing stores that separately, so the two cannot disagree.
            $t->addColumn('start_time', Types::STRING, ['notnull' => true, 'length' => 5, 'default' => '09:00']);
            $t->addColumn('end_time', Types::STRING, ['notnull' => true, 'length' => 5, 'default' => '17:00']);
            $t->addColumn('color', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => '']);
            $t->addColumn('icon', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $t->addColumn('sort_order', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $t->addColumn('is_active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
            // A built-in may be renamed and recoloured but not deleted, so the
            // seed can be idempotent without undoing an administrator's edit.
            $t->addColumn('is_builtin', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
            $t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);

            $t->setPrimaryKey(['id'], 'th_shtype_pk');
            $t->addUniqueIndex(['slug'], 'th_shtype_slug_uq');
            $output->info('Version000413067: creating teamhub_shift_type');
        }

        if (!$schema->hasTable('teamhub_roster_shift')) {
            $t = $schema->createTable('teamhub_roster_shift');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $t->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            // ISO YYYY-MM-DD, a floating date like teamhub_presence_slots.
            $t->addColumn('shift_date', Types::STRING, ['notnull' => true, 'length' => 10]);
            $t->addColumn('shift_type_id', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
            // Nullable to match teamhub_presence_slots.location_room_id, whose
            // entity and serialisers already handle the absent case.
            $t->addColumn('room_id', Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);
            // The coverage target. Assignments below it are an open shift.
            $t->addColumn('required_count', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
            $t->addColumn('notes', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            // 0 is a draft, which nobody outside the team's admins can see.
            $t->addColumn('published_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
            $t->addColumn('published_by', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
            $t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);

            $t->setPrimaryKey(['id'], 'th_rshift_pk');
            // Every read is "this team, this week".
            $t->addIndex(['team_id', 'shift_date'], 'th_rshift_team_idx');
            // The holiday sweep and any date-range maintenance come the other way.
            $t->addIndex(['shift_date'], 'th_rshift_date_idx');
            $output->info('Version000413067: creating teamhub_roster_shift');
        }

        if (!$schema->hasTable('teamhub_roster_assign')) {
            $t = $schema->createTable('teamhub_roster_assign');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $t->addColumn('shift_id', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
            $t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            // 'assigned' — a team admin put them on it.
            // 'claimed'  — they took an open shift themselves.
            $t->addColumn('origin', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'assigned']);
            $t->addColumn('assigned_by', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);

            $t->setPrimaryKey(['id'], 'th_rassign_pk');
            // One person is on a shift once. The unique index is the rule, not
            // a check in the service: a double-claim is a race, and a race is
            // settled by the database or not at all.
            $t->addUniqueIndex(['shift_id', 'user_id'], 'th_rassign_uq');
            // "My shifts", and the presence write-through, read by user.
            $t->addIndex(['user_id'], 'th_rassign_user_idx');
            $output->info('Version000413067: creating teamhub_roster_assign');
        }

        return $schema;
    }
}
