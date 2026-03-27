<?php

declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial schema migration — creates all base tables.
 *
 * This replaces appinfo/database.xml as the canonical schema definition.
 * Version 2.0.0 = Version000200000Date20240101000000
 */
class Version000200000Date20240101000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ------------------------------------------------------------------
        // teamhub_messages
        // ------------------------------------------------------------------
        if (!$schema->hasTable('teamhub_messages')) {
            $table = $schema->createTable('teamhub_messages');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $table->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('author_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('subject', Types::STRING, ['notnull' => true, 'length' => 512]);
            $table->addColumn('message', Types::TEXT, ['notnull' => true]);
            $table->addColumn('priority', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'normal']);
            $table->addColumn('message_type', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'normal']);
            $table->addColumn('poll_options', Types::TEXT, ['notnull' => false]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['team_id'], 'teamhub_msg_team_idx');
            $table->addIndex(['author_id'], 'teamhub_msg_author_idx');

            $output->info('Created teamhub_messages table');
        }

        // ------------------------------------------------------------------
        // teamhub_poll_votes
        // ------------------------------------------------------------------
        if (!$schema->hasTable('teamhub_poll_votes')) {
            $table = $schema->createTable('teamhub_poll_votes');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $table->addColumn('message_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('option_index', Types::INTEGER, ['notnull' => true, 'length' => 4]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['message_id'], 'teamhub_votes_msg_idx');
            $table->addUniqueIndex(['message_id', 'user_id'], 'teamhub_votes_unique');

            $output->info('Created teamhub_poll_votes table');
        }

        // ------------------------------------------------------------------
        // teamhub_comments
        // ------------------------------------------------------------------
        if (!$schema->hasTable('teamhub_comments')) {
            $table = $schema->createTable('teamhub_comments');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $table->addColumn('message_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $table->addColumn('author_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('comment', Types::TEXT, ['notnull' => true]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['message_id'], 'teamhub_comment_msg_idx');

            $output->info('Created teamhub_comments table');
        }

        // ------------------------------------------------------------------
        // teamhub_web_links
        // ------------------------------------------------------------------
        if (!$schema->hasTable('teamhub_web_links')) {
            $table = $schema->createTable('teamhub_web_links');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $table->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 256]);
            $table->addColumn('url', Types::STRING, ['notnull' => true, 'length' => 2048]);
            $table->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['team_id'], 'teamhub_links_team_idx');

            $output->info('Created teamhub_web_links table');
        }

        // ------------------------------------------------------------------
        // teamhub_team_apps
        // ------------------------------------------------------------------
        if (!$schema->hasTable('teamhub_team_apps')) {
            $table = $schema->createTable('teamhub_team_apps');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $table->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('app_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('enabled', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
            $table->addColumn('config', Types::TEXT, ['notnull' => false]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['team_id', 'app_id'], 'teamhub_apps_team_idx');

            $output->info('Created teamhub_team_apps table');
        }

        return $schema;
    }
}
