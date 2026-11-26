<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.20.19 — meeting wrap-ups (DESIGN §2.271).
 *
 * One row per meeting occurrence its organiser wrapped up, or marked as not
 * needing one. The meeting itself stays in the team calendar; this row only
 * records that the wrap-up is done, so My Work stops asking for it, and what
 * it produced (how many tasks, how many proposals were linked to the notes).
 * `occurrence` is the start of the occurrence, which tells the dates of a
 * repeating meeting apart: each date is wrapped up on its own.
 *
 * Rule compliance (`/migrations`): a new table of 19 characters, every
 * identifier named explicitly and under 20 characters (rule 1); new table, so
 * the `''` defaults are allowed (rule 1b); no `OCA\TeamHub\` reference (rule
 * 2); no seed (rule 2a); no Doctrine type named (rule 2b).
 */
class Version000420019Date20261117120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_mtg_wrapups')) {
            $t = $schema->createTable('teamhub_mtg_wrapups');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $t->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $t->addColumn('event_uid', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $t->addColumn('occurrence', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
            $t->addColumn('organiser', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            // 'done' (wrapped up) or 'skipped' (no wrap-up needed).
            $t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => '']);
            $t->addColumn('task_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $t->addColumn('linked_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);

            $t->setPrimaryKey(['id'], 'th_mtgw_pk');
            $t->addUniqueIndex(['team_id', 'event_uid', 'occurrence'], 'th_mtgw_uq');
            $t->addIndex(['organiser', 'created_at'], 'th_mtgw_org');
            $output->info('Version000420019: creating teamhub_mtg_wrapups');
        }

        return $schema;
    }
}
