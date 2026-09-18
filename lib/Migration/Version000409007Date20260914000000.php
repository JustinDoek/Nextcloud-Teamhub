<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.9.7 — OpenProject Phase 3: the news-mirror ledger.
 *
 * An OpenProject news item is copied once into the linked team's message
 * stream as a System post (Justin, 2026-09-14: "copy that news item as a
 * message in the team and add something so it's clear its source is
 * OpenProject"). This table is what makes "once" true: one row per (team,
 * OpenProject connection, news item), written together with the message.
 * The copy happens the first time a connected member reads the team's
 * feed or home — TeamHub holds no OpenProject token, so nothing can run
 * in the background — and two members loading at the same moment race on
 * the unique index, which the loser reads as "already mirrored".
 *
 * `connection` is a hash of the OpenProject host the link pointed at, so a
 * repointed integration does not collide with the old instance's ids.
 * `message_id` is the mirrored message's id, kept so a later version can
 * find or heal the copy; nothing reads it today.
 *
 * Identifier lengths: the table name is 22 characters; every key is still
 * named explicitly (`/migrations` §1). Self-contained — no `OCA\TeamHub\`
 * constant or class is referenced (`/migrations` §2, issue #98).
 */
class Version000409007Date20260914000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_op_news_mirror')) {
            return null;
        }

        $table = $schema->createTable('teamhub_op_news_mirror');
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
        $table->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        // md5 of the OpenProject host the link pointed at, 32 hex characters.
        $table->addColumn('connection', Types::STRING, ['notnull' => true, 'length' => 32]);
        // OpenProject's numeric news id.
        $table->addColumn('news_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
        // The mirrored teamhub_messages row.
        $table->addColumn('message_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
        // Who triggered the copy (the connected member whose read found it).
        $table->addColumn('mirrored_by', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);

        $table->setPrimaryKey(['id'], 'th_opnm_pk');
        // Once per team and news item — the rule and the lookup in one.
        $table->addUniqueIndex(['team_id', 'connection', 'news_id'], 'th_opnm_uq');

        $output->info('Created teamhub_op_news_mirror table');

        return $schema;
    }
}
