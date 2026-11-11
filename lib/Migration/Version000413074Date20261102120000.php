<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.13.74 — the roster week template, which belongs to a team.
 *
 * ## Why this is not `teamhub_shift_schedule`
 *
 * Justin's division, 2026-11-02: **a Nextcloud administrator defines the
 * shifts** (`teamhub_shift_type` — *Early is 07:00 to 15:00*) in Admin,
 * TeamHub; **a team admin defines that team's templates** in Manage team,
 * Module settings. The instance-wide schedule a 4.13.68 administrator composes
 * is a different object with a different owner, and it stays exactly as it is.
 *
 * Two things follow from the owner changing, and they are why this is a new
 * table rather than a `team_id` column on the old one:
 *
 *   - A row carries a **role**: *Receptionist on Early*, not just *one more
 *     Early*. That is the whole point of the template — a drafted week comes
 *     out already saying what each place is for, so staffing it is picking
 *     people and nothing else.
 *   - Two roles may share a weekday and a shift type. The schedule table
 *     forbids that at the database (`th_shsrow_cell_uq` on schedule, weekday,
 *     type) because for a countable pattern a second row would silently double
 *     the coverage target. Here *Receptionist on Early* and *Barista on Early*
 *     on the same Monday is the ordinary case, so that constraint is the wrong
 *     one and widening it in place would have changed the meaning of the rows
 *     an administrator already has.
 *
 *   teamhub_roster_template  one team's named week.
 *   teamhub_roster_tpl_row   one line of it: a weekday, a shift type, the role
 *                            somebody is needed for, and how many people.
 *                            A weekday with no row has no shift that day —
 *                            absence, not a zero, the same convention
 *                            `teamhub_shift_sched_row` records.
 *
 * ## `day_of_week` is 0 = Monday
 *
 * As in `teamhub_presence_template` and `teamhub_shift_sched_row`. Not PHP's
 * `w` and not ISO-8601's `N`: a third convention inside one module is the
 * off-by-one nobody finds until a Sunday night shift lands on a Monday.
 *
 * ## Uniqueness is enforced in the service, not by an index, and on purpose
 *
 * `RosterTemplateService` refuses a duplicate name within a team and a
 * duplicate weekday/type/role line, each with a sentence. Two reasons not to
 * put either in the schema:
 *
 *   - A unique index would have to span `team_id` (64) plus `name` (128), or
 *     the row's three keys plus `role` (128). At utf8mb4 that is 768 and 530
 *     bytes of key, against InnoDB's 767-byte limit on the older row formats
 *     some customers still run. An index that installs on our databases and
 *     not on theirs is worse than no index.
 *   - A constraint violation reaches the user as a driver error. The service
 *     check reaches them as a sentence, which is the pattern
 *     `ShiftScheduleService::save()` already set.
 *
 * Rule compliance (`/migrations`): two brand-new tables, so rule 1b (no NOT
 * NULL with an empty-string default on an existing table) does not apply to
 * them; nothing is added to an existing table here. Longest identifier is
 * `teamhub_roster_template` at 23 characters and every key is named
 * explicitly anyway, per rule 1. No `OCA\TeamHub\` constant or class is
 * referenced (rule 2). No seed data: a template is a team's decision, never a
 * default, so rule 2a does not apply and there is no repair step.
 */
class Version000413074Date20261102120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_roster_template')) {
            $t = $schema->createTable('teamhub_roster_template');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            // The Circles single id of the team that owns it. A template is
            // never offered to another team: it names that team's roles.
            $t->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 128, 'default' => '']);
            $t->addColumn('description', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            // A template switched off stays readable: a week may have been laid
            // out from it, and the editor has to be able to switch it back on.
            $t->addColumn('is_active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
            $t->addColumn('sort_order', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
            $t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);

            $t->setPrimaryKey(['id'], 'th_rtpl_pk');
            // Every read is "this team's templates", from both the editor and
            // the Roster tab's picker.
            $t->addIndex(['team_id'], 'th_rtpl_team_idx');
            $output->info('Version000413074: creating teamhub_roster_template');
        }

        if (!$schema->hasTable('teamhub_roster_tpl_row')) {
            $t = $schema->createTable('teamhub_roster_tpl_row');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $t->addColumn('template_id', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
            // 0 = Monday.
            $t->addColumn('day_of_week', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $t->addColumn('shift_type_id', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
            // What somebody is needed *for*. Copied into the shift's `notes`
            // when the template is applied, which is the column the roster
            // card has shown as the role since 4.13.70 (DESIGN §2.236).
            // 128 here against the shift's 255: a role is a job title, and the
            // shorter field is the one that says so on the form.
            $t->addColumn('role', Types::STRING, ['notnull' => true, 'length' => 128, 'default' => '']);
            $t->addColumn('required_count', Types::SMALLINT, ['notnull' => true, 'default' => 1]);

            $t->setPrimaryKey(['id'], 'th_rtplrow_pk');
            // The whole week is read in one go, by the editor and by apply.
            $t->addIndex(['template_id'], 'th_rtplrow_tpl_idx');
            $output->info('Version000413074: creating teamhub_roster_tpl_row');
        }

        return $schema;
    }
}
