<?php

declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Upgrade migration: adds poll support to existing installations.
 *
 * Fresh installs receive these columns via Version000200000Date20240101000000.
 * All changeSchema operations are guarded with hasColumn/hasTable checks so
 * this migration is safely idempotent on both upgrade and fresh-install paths.
 *
 * Version format: Version{major}{minor}{patch}Date{timestamp}
 * For version 2.6.1 = Version000206001Date20260223000000
 */
class Version000206001Date20260223000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Add message_type and poll_options to teamhub_messages table
        if ($schema->hasTable('teamhub_messages')) {
            $table = $schema->getTable('teamhub_messages');
            
            // Add message_type column if it doesn't exist
            if (!$table->hasColumn('message_type')) {
                $table->addColumn('message_type', Types::STRING, [
                    'notnull' => true,
                    'length' => 16,
                    'default' => 'normal',
                ]);
                $output->info('Added message_type column to teamhub_messages');
            }
            
            // Add poll_options column if it doesn't exist
            if (!$table->hasColumn('poll_options')) {
                $table->addColumn('poll_options', Types::TEXT, [
                    'notnull' => false,
                ]);
                $output->info('Added poll_options column to teamhub_messages');
            }
        }

        // Create teamhub_poll_votes table if it doesn't exist
        if (!$schema->hasTable('teamhub_poll_votes')) {
            $table = $schema->createTable('teamhub_poll_votes');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 8,
            ]);
            $table->addColumn('message_id', Types::BIGINT, [
                'notnull' => true,
                'length' => 8,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('option_index', Types::INTEGER, [
                'notnull' => true,
                'length' => 4,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length' => 8,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['message_id'], 'teamhub_votes_msg_idx');
            $table->addUniqueIndex(['message_id', 'user_id'], 'teamhub_votes_unique');
            
            $output->info('Created teamhub_poll_votes table');
        }

        return $schema;
    }
}
