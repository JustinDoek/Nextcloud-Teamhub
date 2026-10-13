<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.12.2 — sub-teams (DESIGN §2.175).
 *
 * One row per sub-team: the link from a team to the main team that governs
 * it. A sub-team is an ordinary TeamHub team in every other respect — its own
 * circle, its own registry row, its own members, storage, modules and
 * archive, keyed on its own `team_id` in all ~80 other `teamhub_*` tables —
 * so this table holds nothing but the relationship and the one setting that
 * has no home anywhere else.
 *
 *   team_id         the sub-team's circles_circle.unique_id; unique, because
 *                   a sub-team has exactly one parent and the row IS the fact
 *                   of being a sub-team
 *   parent_team_id  the main team's circles_circle.unique_id
 *   visibility      private | listed — whether a member of the parent who is
 *                   NOT in the sub-team sees its name under the parent
 *   created_by      uid of the parent admin who created it
 *   created_at      epoch seconds
 *
 * **Why this is TeamHub's own table and not Circles' nesting.** Circles can
 * already hold one circle inside another (`circles_member.user_type = 16`),
 * and making the sub-team a member of the main team is the obvious
 * implementation. It is also exactly backwards: in Circles a *contained*
 * circle's members become effective members of the **container**, through
 * `circles_membership`, which both `MemberService::isEffectiveMember()` and
 * `requireMemberLevel()` honour. A sub-team may invite people from outside
 * the main team, so that row would hand every one of those outsiders the main
 * team's files, Talk and calendar. **No `circles_member` row ever links a
 * parent and a child.** `SubTeamService` says the same thing where the code
 * is, because the wrong implementation is the intuitive one.
 *
 * **Why `visibility` is a column here rather than a Circles config bit.**
 * "Invisible to members of the parent who are not in it" is not a Circles
 * concept — `CFG_VISIBLE` is about non-members instance-wide — and
 * `CirclesConfig` forbids inventing bit values. Three characters of state,
 * read in the same indexed query that answers "is this a sub-team".
 *
 * Rule compliance (`/migrations`): a brand-new table, so `notnull` with a
 * `''` / `'private'` default is correct and rule 1b does not apply — nothing
 * in this feature adds a column to an existing table. The name is 16
 * characters (`teamhub_sub_team_pkey` = 21, under the 30 cap) and every key
 * is still named explicitly, per rule 1. No `OCA\TeamHub\` constant or class
 * is referenced (rule 2) — `'private'` is the literal this migration writes,
 * and application code reads `SubTeamMapper::VIS_PRIVATE` for the same value.
 * No seed data, so rule 2a does not apply.
 */
class Version000412002Date20261011000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_sub_team')) {
            return null;
        }

        $t = $schema->createTable('teamhub_sub_team');
        $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
        $t->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        $t->addColumn('parent_team_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        $t->addColumn('visibility', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'private']);
        $t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
        $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);

        $t->setPrimaryKey(['id'], 'th_subt_pk');
        // One parent per sub-team. Also the gate: every reader asks "does this
        // team_id have a row", so the lookup has to be a unique index hit.
        $t->addUniqueIndex(['team_id'], 'th_subt_team_uq');
        // The sidebar, the Sub-teams tab and the delete/archive cascade all
        // ask the same question from the other end: what hangs off this team.
        $t->addIndex(['parent_team_id'], 'th_subt_parent_idx');

        return $schema;
    }
}
