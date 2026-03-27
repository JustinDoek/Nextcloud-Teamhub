<?php

declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Version 2.7.0 migration: adds poll closing and question solved features.
 *
 * Adds:
 * - poll_closed: boolean flag to prevent further voting on polls
 * - question_solved: boolean flag to mark questions as solved
 * - solved_comment_id: reference to the comment that solved the question
 *
 * Version format: Version{major}{minor}{patch}Date{timestamp}
 * For version 2.7.0 = Version000207000Date20260224000000
 */
class Version000207000Date20260224000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Add poll and question features to teamhub_messages table
        if ($schema->hasTable('teamhub_messages')) {
            $table = $schema->getTable('teamhub_messages');
            
            // Add poll_closed column if it doesn't exist
            // Using SMALLINT instead of BOOLEAN for better cross-database compatibility
            if (!$table->hasColumn('poll_closed')) {
                $table->addColumn('poll_closed', Types::SMALLINT, [
                    'notnull' => true,
                    'default' => 0,
                    'length' => 1,
                ]);
                $output->info('Added poll_closed column to teamhub_messages');
            }
            
            // Add question_solved column if it doesn't exist
            if (!$table->hasColumn('question_solved')) {
                $table->addColumn('question_solved', Types::SMALLINT, [
                    'notnull' => true,
                    'default' => 0,
                    'length' => 1,
                ]);
                $output->info('Added question_solved column to teamhub_messages');
            }
            
            // Add solved_comment_id column if it doesn't exist
            if (!$table->hasColumn('solved_comment_id')) {
                $table->addColumn('solved_comment_id', Types::BIGINT, [
                    'notnull' => false,
                    'length' => 8,
                ]);
                $output->info('Added solved_comment_id column to teamhub_messages');
            }
        }

        return $schema;
    }
}
