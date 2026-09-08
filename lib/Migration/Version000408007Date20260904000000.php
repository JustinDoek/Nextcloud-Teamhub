<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.8.7 — per-message comment-notification subscriptions (GitHub #95).
 *
 * A row here is an **override**, not a membership record. Absence means
 * "this user has expressed no preference for this message", and the default
 * that applies then is: the message author is subscribed, everybody else is
 * not.
 *
 * That is what lets the feature hold for messages that already exist. A
 * backfill would have to write one row per historical message to make its
 * author subscribed; deriving the author's default instead makes every
 * message in the database behave correctly the moment the code lands, and
 * writes nothing. The only rows this table ever holds are deliberate acts:
 * somebody subscribed to a thread they did not start, or unsubscribed from
 * one they did.
 *
 * `subscribed` is SMALLINT rather than BOOLEAN deliberately — `Types::BOOLEAN`
 * with `notnull => true` fails on MySQL at insert and on Postgres at bind
 * (HANDOFF, "Facts that are not derivable from this repo"). It is bound with
 * PARAM_INT at every call site.
 *
 * Identifier lengths: the table name is 24 characters, so the auto-generated
 * `_pkey` would fit under NC's 30-char DBAL cap — but every key is named
 * explicitly anyway, per SKILLS.md § Database identifier length, because a
 * composite unique index is exactly the case where auto-naming overflows.
 */
class Version000408007Date20260904000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_msg_subscription')) {
            return null;
        }

        $table = $schema->createTable('teamhub_msg_subscription');
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
        $table->addColumn('message_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
        $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('subscribed', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);
        $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);

        $table->setPrimaryKey(['id'], 'th_msgsub_pk');

        // (message_id, user_id) in this order on purpose: it is both the
        // uniqueness rule and the index every read uses. Resolving one
        // thread's subscribers is a message_id range scan on its leftmost
        // column, and the per-viewer batch stamp is `message_id IN (…)`
        // narrowed by user_id. No second index earns its keep.
        $table->addUniqueIndex(['message_id', 'user_id'], 'th_msgsub_uq');

        $output->info('Created teamhub_msg_subscription table');

        return $schema;
    }
}
