<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.13.34 — a default home layout can be set for the instance, for a kind of
 * team, and for one team.
 *
 * One new table, `teamhub_layout_default`, holding all three. Phases 2 and 3 of
 * issue #109; v4.13.33 (`origin`) was phase 1.
 *
 * ## Why one table and not three places
 *
 * The three defaults are the same thing at three widths: an ordered list of
 * widgets with sizes, and a tab order. Only the *scope* differs. Three storage
 * mechanisms — an app-config value, a column on `teamhub_template`, a per-team
 * app-config key — would have meant three readers, three validators and three
 * ways for a layout to be malformed, for one concept. The cascade reads all
 * three on every team open and has to treat them identically; a shape that
 * makes them identical is the one to store them in.
 *
 * It also means a fourth scope is a row, not a migration. The issue already
 * asks for the obvious next one (a default per group or per department), and
 * the sub-team work will ask for another.
 *
 * ## `scope` + `scope_id`, with `''` for the instance
 *
 *   scope = 'instance'  scope_id = ''            one row at most
 *   scope = 'template'  scope_id = template_key  one row per kind of team
 *   scope = 'team'      scope_id = team_id       one row per team, with a mode
 *
 * `scope_id` is `notnull` with a `''` default rather than nullable, because
 * NULL does not compare equal to NULL in a unique index on either engine: two
 * instance rows would both be accepted, and the cascade would then read
 * whichever the planner happened to return. `''` is a real value that the
 * unique index can refuse a duplicate of. (Rule 1b does not apply — this is a
 * `createTable`, where `notnull => true, 'default' => ''` is both allowed and
 * the right shape.)
 *
 * ## `mode` is only meaningful on a team row
 *
 * `suggest` (offer it, change nothing), `apply_once` (it is what a member sees
 * until they arrange their own) or `enforce` (it is what every member sees, and
 * the home cannot be edited). `''` on an instance or template row: those have
 * no mode, because there is nobody above a member to enforce against at that
 * width — an instance default is where the cascade *starts*, not something it
 * overrides. Stored as a string and not a smallint so a log or a `select` says
 * which mode a team is in without a lookup table.
 *
 * Rule compliance (`/migrations`): table name is 22 characters, so
 * `teamhub_layout_default_pkey` would be 27 and fit — the primary key and the
 * unique index are **still named explicitly** (`th_lyt_def_pk`,
 * `th_lyt_def_uq`), because rule 1 asks for every new index to be named and a
 * composite index's generated name is the part that overflows. No
 * `OCA\TeamHub\` constant or class is referenced (rule 2): the four scope and
 * mode values appear here only as prose. No seed data — an instance with no
 * row has no default, which is the correct starting state and needs no repair
 * step (rule 2a).
 */
class Version000413034Date20261027130000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_layout_default')) {
            return null;
        }

        $output->info('Version000413034: creating teamhub_layout_default');

        $table = $schema->createTable('teamhub_layout_default');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull'       => true,
            'unsigned'      => true,
        ]);

        // 'instance' | 'template' | 'team'.
        $table->addColumn('scope', Types::STRING, [
            'notnull' => true,
            'length'  => 16,
        ]);

        // A template key (32), a Circles team id (64), or '' for the instance.
        $table->addColumn('scope_id', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
            'default' => '',
        ]);

        // The grid-layout array, as the layout endpoints already carry it.
        $table->addColumn('layout_json', Types::TEXT, [
            'notnull' => true,
            'default' => '[]',
        ]);

        // Ordered tab keys. A default may set the tab order as well as the
        // grid: the two are what a team's home *is*, and an administrator who
        // arranges one has arranged both.
        $table->addColumn('tab_order_json', Types::TEXT, [
            'notnull' => true,
            'default' => '[]',
        ]);

        // 'suggest' | 'apply_once' | 'enforce' on a team row, '' elsewhere.
        $table->addColumn('mode', Types::STRING, [
            'notnull' => true,
            'length'  => 16,
            'default' => '',
        ]);

        // Who set it. A default changes what other people see, so the row says
        // who is answerable for it — the same reason `teamhub_policy_value`
        // carries one.
        $table->addColumn('updated_by', Types::STRING, [
            'notnull' => false,
            'length'  => 64,
        ]);

        $table->addColumn('updated_at', Types::BIGINT, [
            'notnull' => false,
        ]);

        $table->setPrimaryKey(['id'], 'th_lyt_def_pk');
        $table->addUniqueIndex(['scope', 'scope_id'], 'th_lyt_def_uq');

        return $schema;
    }
}
