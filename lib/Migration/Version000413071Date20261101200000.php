<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.13.71 — when somebody is available to be rostered (DESIGN §2.237).
 *
 * One row per half-day a person has said something about. **An absent row
 * means available**, which is the only default that can be right: somebody who
 * has never opened the setting must stay rosterable, or switching this feature
 * on would empty every roster in the instance.
 *
 * So the table holds the exceptions, and `available` is still a column rather
 * than the row's mere existence being the fact. Two reasons. It lets the
 * screen save an explicit yes, which is what a person who has just unticked
 * Saturday and ticked it back expects to persist. And it leaves room for a
 * third state later (*prefer not to*, which a roster could honour as a soft
 * constraint) without another migration.
 *
 * ## Why this is not `teamhub_presence_template`
 *
 * That table is the user's **workplace** pattern: on Tuesday mornings I am at
 * the office. This one is **consent to be rostered**: on Tuesday mornings I
 * cannot work a shift. A person can perfectly well be at the office on a
 * morning they are not available for a shift, and the opposite happens every
 * time somebody works a night. Folding the two would make one a lie.
 *
 * ## Instance-wide, not per team
 *
 * It lives in Personal settings, so it is one answer for the person rather
 * than one per team: *I cannot work Wednesday afternoons* is a fact about
 * their week, not about a team. A team admin sees it when staffing a shift.
 *
 * `day_of_week` is **0 = Monday**, matching `teamhub_presence_template` and
 * `teamhub_shift_sched_row`. `half_day` is 0 morning, 1 afternoon, the same
 * pair `teamhub_presence_slots` uses.
 *
 * Rule compliance (`/migrations`): a brand-new table, so rule 1b does not
 * apply. The name is 19 characters (`teamhub_shift_avail_pkey` = 24, under the
 * 30 cap) and every key is named explicitly anyway, per rule 1. No
 * `OCA\TeamHub\` constant or class is referenced (rule 2). No seed data, so
 * rule 2a does not apply — an absent row is the default and needs no row.
 */
class Version000413071Date20261101200000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_shift_avail')) {
            return null;
        }

        $t = $schema->createTable('teamhub_shift_avail');
        $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
        $t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        // 0 = Monday.
        $t->addColumn('day_of_week', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
        // 0 = morning, 1 = afternoon.
        $t->addColumn('half_day', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
        $t->addColumn('available', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
        $t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);

        $t->setPrimaryKey(['id'], 'th_shavail_pk');
        // One answer per person per half-day. A second row for the same cell
        // would make "is this person available" depend on which row was read
        // first, which is the kind of ambiguity a roster cannot carry.
        $t->addUniqueIndex(['user_id', 'day_of_week', 'half_day'], 'th_shavail_uq');
        // The staffing picker asks for a set of people at once.
        $t->addIndex(['user_id'], 'th_shavail_user_idx');

        $output->info('Version000413071: creating teamhub_shift_avail');

        return $schema;
    }
}
