<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.13.68 — shift schedules, change requests, and the per-team Shifts switch
 * (DESIGN §2.234, which revises §2.233 after Justin's walk of 4.13.67).
 *
 * ## What a schedule is, and why it is not the shift types
 *
 * `teamhub_shift_type` (4.13.67) is the vocabulary: *Early is 07:00 to 15:00*.
 * A **schedule** is a reusable weekly pattern built out of that vocabulary —
 * *3-shift continuous: two Early and two Late every weekday, one of each at
 * the weekend, one Night every day.* A Nextcloud administrator composes them
 * once; a team admin picks one and it lays out the week ready to staff.
 *
 * The two are separate tables because they change for different reasons and
 * at different rates. An organisation adds a shift type when it starts working
 * a new time of day, which is rare; it adds a schedule whenever a department
 * works a different rhythm from the rest, which is not. Folding the pattern
 * into the type would also make "two people on Early, but only one on
 * Saturday" impossible to say at all.
 *
 *   teamhub_shift_schedule   the named pattern.
 *   teamhub_shift_sched_row  one cell of it: a weekday, a shift type, and how
 *                            many people that combination needs. A weekday
 *                            with no row simply has no shift, which is how
 *                            "Office hours" says nothing runs at the weekend.
 *   teamhub_shift_request    a member asking for a shift to be taken over,
 *                            and whoever took it.
 *
 * ## `day_of_week` is 0 = Monday
 *
 * Deliberately not PHP's `w` (0 = Sunday) and not ISO-8601's `N` (1 = Monday).
 * The roster grid is Monday-first and `PresenceTemplate.day_of_week` is
 * already 0 = Monday, so a third convention in the same module would be the
 * off-by-one nobody finds until a Sunday night shift lands on a Monday.
 *
 * ## The per-team switch is a column on an existing table
 *
 * `teamhub_presence_team.shifts_enabled`, beside `presence_enabled`, because
 * Shifts is a part of the People module rather than a module of its own
 * (Justin, 2026-11-01) and the team-level answer belongs with the rest of that
 * team's People settings. **It defaults to 0**: Shifts is licensed, so a team
 * that has never been told about it must not acquire a roster on upgrade. That
 * is the same reasoning §2.210 recorded for the module itself, in the one
 * direction that is always safe.
 *
 * Rule compliance (`/migrations`): three new tables, so rule 1b does not apply
 * to them. The fourth change **is** a column on an existing table, and it is a
 * SMALLINT with a `0` default rather than NOT NULL with `''` — the shape the
 * rule forbids is the empty string, which Oracle cannot tell from NULL. The
 * longest name is 23 characters (`teamhub_shift_sched_row_pkey` = 28, under
 * the 30 cap) and every key is named explicitly anyway, per rule 1. No
 * `OCA\TeamHub\` constant or class is referenced (rule 2). No seed data, so
 * rule 2a does not apply — a schedule is somebody's decision, not a default.
 */
class Version000413068Date20261101160000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_shift_schedule')) {
            $t = $schema->createTable('teamhub_shift_schedule');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 128, 'default' => '']);
            $t->addColumn('description', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $t->addColumn('is_active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
            $t->addColumn('sort_order', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
            $t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);

            $t->setPrimaryKey(['id'], 'th_shsched_pk');
            // A team admin picks a schedule by name out of a list, so two with
            // the same name is a question nobody can answer from the screen.
            $t->addUniqueIndex(['name'], 'th_shsched_name_uq');
            $output->info('Version000413068: creating teamhub_shift_schedule');
        }

        if (!$schema->hasTable('teamhub_shift_sched_row')) {
            $t = $schema->createTable('teamhub_shift_sched_row');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $t->addColumn('schedule_id', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
            // 0 = Monday, matching teamhub_presence_template.
            $t->addColumn('day_of_week', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $t->addColumn('shift_type_id', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
            $t->addColumn('required_count', Types::SMALLINT, ['notnull' => true, 'default' => 1]);

            $t->setPrimaryKey(['id'], 'th_shsrow_pk');
            // The whole pattern is read in one go whenever it is applied.
            $t->addIndex(['schedule_id'], 'th_shsrow_sched_idx');
            // One cell per weekday per shift type: a second row for the same
            // pair would silently double the coverage target, which is the
            // failure that looks like a staffing problem rather than a bug.
            $t->addUniqueIndex(['schedule_id', 'day_of_week', 'shift_type_id'], 'th_shsrow_cell_uq');
            $output->info('Version000413068: creating teamhub_shift_sched_row');
        }

        if (!$schema->hasTable('teamhub_shift_request')) {
            $t = $schema->createTable('teamhub_shift_request');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $t->addColumn('shift_id', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
            // Carried rather than joined: the claim link is opened cold, and
            // the team is the first thing that has to be checked.
            $t->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $t->addColumn('requester_uid', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $t->addColumn('reason', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            // 'open' | 'claimed' | 'withdrawn'
            $t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
            $t->addColumn('claimed_by', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $t->addColumn('resolved_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);

            $t->setPrimaryKey(['id'], 'th_shreq_pk');
            $t->addIndex(['shift_id'], 'th_shreq_shift_idx');
            $t->addIndex(['team_id', 'status'], 'th_shreq_team_idx');
            $output->info('Version000413068: creating teamhub_shift_request');
        }

        // The per-team switch, beside presence_enabled.
        if ($schema->hasTable('teamhub_presence_team')) {
            $t = $schema->getTable('teamhub_presence_team');
            if (!$t->hasColumn('shifts_enabled')) {
                // SMALLINT with a 0 default: the shape rule 1b forbids is NOT
                // NULL with an empty-string default, which Oracle reads as
                // NULL. An integer default is accepted, and 0 is the value
                // every existing row should have.
                $t->addColumn('shifts_enabled', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
                $output->info('Version000413068: adding teamhub_presence_team.shifts_enabled');
            }
        }

        return $schema;
    }
}
