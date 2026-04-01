<?php

declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Version 2.23.0 migration: pin messages + unread indicators.
 *
 * Adds:
 * - teamhub_messages.pinned      SMALLINT — 0 = normal, 1 = pinned (at most one per team)
 * - teamhub_last_seen             new table tracking when each user last visited each team
 *
 * Version format: Version{major}{minor}{patch}Date{timestamp}
 * For version 2.23.0 = Version000208000Date20260330000000
 */
class Version000208000Date20260330000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // 1. Add pinned column to teamhub_messages
        if ($schema->hasTable('teamhub_messages')) {
            $table = $schema->getTable('teamhub_messages');
            if (!$table->hasColumn('pinned')) {
                $table->addColumn('pinned', Types::SMALLINT, [
                    'notnull' => true,
                    'default' => 0,
                    'length'  => 1,
                ]);
                $output->info('Added pinned column to teamhub_messages');
            }
        }

        // 2. Create teamhub_last_seen table
        if (!$schema->hasTable('teamhub_last_seen')) {
            $table = $schema->createTable('teamhub_last_seen');
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            $table->addColumn('team_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            $table->addColumn('last_seen_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
            ]);
            $table->setPrimaryKey(['user_id', 'team_id']);
            $output->info('Created teamhub_last_seen table');
        }

        return $schema;
    }
}
