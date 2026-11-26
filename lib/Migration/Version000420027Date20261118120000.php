<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.20.27 — emoji reactions on team messages and their comments.
 *
 * One row per person per emoji per target. `target_type` is 'message' or
 * 'comment'; `message_id` is the message itself or the comment's parent, so a
 * message delete removes its thread's reactions in one statement, and
 * `team_id` lets the team purge reach them. The unique index is what makes a
 * double click or two open tabs harmless: the second insert is refused and
 * the service reads that as "already there".
 *
 * Rule compliance (`/migrations`): a new table of 17 characters, every
 * identifier named explicitly and under 20 characters (rule 1); new table, so
 * the `''` defaults are allowed (rule 1b); no `OCA\TeamHub\` reference (rule
 * 2); no seed (rule 2a); no Doctrine type named (rule 2b).
 */
class Version000420027Date20261118120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_reactions')) {
            $t = $schema->createTable('teamhub_reactions');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $t->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $t->addColumn('target_type', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => '']);
            $t->addColumn('target_id', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
            $t->addColumn('message_id', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
            $t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $t->addColumn('emoji', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => '']);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);

            $t->setPrimaryKey(['id'], 'th_react_pk');
            $t->addUniqueIndex(['target_type', 'target_id', 'user_id', 'emoji'], 'th_react_uq');
            $t->addIndex(['message_id'], 'th_react_msg');
            $t->addIndex(['team_id'], 'th_react_team');
            $output->info('Version000420027: creating teamhub_reactions');
        }

        return $schema;
    }
}
