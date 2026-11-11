<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.13.2 — compliments (DESIGN §2.186).
 *
 * One row per compliment one person paid another, with the note they wrote
 * with it. The row survives being read: `seen_at` is a timestamp rather than
 * a delete, so the same compliment is shown in the party popup exactly once
 * and is still there afterwards.
 *
 *   recipient_uid  who it is for
 *   sender_uid     who sent it
 *   team_id        the team whose page it was sent from; '' when there was
 *                  none (the contacts menu also opens outside a team page)
 *   note           what they wrote, up to 500 characters; '' is allowed
 *   created_at     epoch seconds
 *   seen_at        epoch seconds when the recipient was shown it; NULL until
 *                  then, which is what "since their last visit" means
 *
 * `team_id` is recorded for provenance rather than for authorisation: the
 * shared-team rule is evaluated when the compliment is sent, against the
 * teams the two people share at that moment, and is not re-checked on read.
 * A compliment does not become unsayable because somebody later left a team.
 *
 * Rule compliance (`/migrations`): a brand-new table, so `notnull` with a
 * `''` default is correct and rule 1b does not apply. The name is 18
 * characters (`teamhub_compliment_pkey` = 23, under the 30 cap) and every key
 * is named explicitly anyway, per rule 1. No `OCA\TeamHub\` constant or class
 * is referenced (rule 2). No seed data, so rule 2a does not apply.
 */
class Version000413002Date20261016120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_compliment')) {
            return null;
        }

        $t = $schema->createTable('teamhub_compliment');
        $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
        $t->addColumn('recipient_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
        $t->addColumn('sender_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
        $t->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
        // STRING rather than TEXT deliberately: MySQL refuses a DEFAULT on a
        // TEXT column, and a compliment that needs more than 500 characters
        // is a message, which this app already has a stream for.
        $t->addColumn('note', Types::STRING, ['notnull' => true, 'length' => 500, 'default' => '']);
        $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
        $t->addColumn('seen_at', Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);

        $t->setPrimaryKey(['id'], 'th_cmp_pk');
        // The only hot query: "what has this person not been shown yet",
        // oldest first. Both columns, because every member of a busy instance
        // accumulates seen rows that this read must skip.
        $t->addIndex(['recipient_uid', 'seen_at'], 'th_cmp_rcpt_idx');
        // The per-sender daily cap counts a sender's rows for one recipient.
        $t->addIndex(['sender_uid', 'created_at'], 'th_cmp_sender_idx');

        return $schema;
    }
}
