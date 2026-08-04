<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.5.21 — create teamhub_mywork_state.
 *
 * My Work's only table. One row per (user, provider, item) once the user has
 * snoozed, followed or muted that item; absence of a row is the default state.
 * Everything else My Work shows is read live from the providers, so there is
 * no cache table, no sync table, and nothing to reconcile on upgrade.
 *
 * Identifier lengths (SKILLS.md § Database identifier length): the table name
 * is 20 characters, so Doctrine's auto-generated `teamhub_mywork_state_pkey`
 * (25) is comfortably under NC's 30-character DBAL cap. Both indexes are named
 * explicitly anyway — a composite unique index's auto-generated name includes
 * the column list and would overflow.
 *
 * Postgres compatibility: BIGINT for every timestamp, SMALLINT for the
 * follow-state enum, no reserved words among the column names, and both
 * indexes explicitly named. No defaults that rely on MySQL's zero-date
 * behaviour.
 */
class Version000405021Date20260731000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_mywork_state')) {
            return null;
        }

        $table = $schema->createTable('teamhub_mywork_state');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull'       => true,
            'length'        => 20,
        ]);
        $table->addColumn('user_id', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
        ]);
        $table->addColumn('provider_id', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
        ]);
        // Provider item ids are opaque to TeamHub: a Deck card id is numeric,
        // a file id is numeric, but a future provider may use a UUID or a
        // path-like key. 255 is wide enough for anything reasonable and keeps
        // the unique index within index-length limits on utf8mb4 MySQL
        // (64 + 64 + 255 characters at 4 bytes = 1532 bytes, under the 3072
        // byte limit for InnoDB with DYNAMIC row format).
        $table->addColumn('item_id', Types::STRING, [
            'notnull' => true,
            'length'  => 255,
        ]);
        $table->addColumn('team_id', Types::STRING, [
            'notnull' => false,
            'length'  => 64,
            'default' => '',
        ]);
        // 0 = not snoozed. Nullable would add a second "no value" state for
        // no benefit.
        $table->addColumn('snooze_until', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);
        // 0 = default, 1 = followed, 2 = muted (see MyWorkState constants).
        $table->addColumn('follow_state', Types::SMALLINT, [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('created_at', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('updated_at', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);

        $table->setPrimaryKey(['id'], 'th_mws_pk');

        // The identity of a state row. Also the index the per-item lookup uses.
        $table->addUniqueIndex(['user_id', 'provider_id', 'item_id'], 'th_mws_uq');
        // The render path reads every row for one user in a single query.
        $table->addIndex(['user_id'], 'th_mws_user_idx');
        // The daily purge scans by snooze_until.
        $table->addIndex(['snooze_until'], 'th_mws_snz_idx');

        return $schema;
    }
}
