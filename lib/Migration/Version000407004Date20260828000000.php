<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.7.4 — record who last edited a message or comment, and when.
 *
 * Until now an edit was invisible: `teamhub_messages.updated_at` moves for
 * reasons that are not edits (pinning, closing a poll, marking a question
 * solved), and `teamhub_comments` has no updated column at all. Neither table
 * recorded *who* made the change, which only mattered once someone other than
 * the author could make it — which is what this version allows.
 *
 * Both columns are nullable with no default, so `edited_at IS NULL` means
 * "never edited" for every row that predates this migration. That is the
 * honest answer: those rows may well have been edited by their author, but
 * nothing recorded it, and back-filling from `updated_at` would invent a
 * fact — it would also mark every pinned message as edited.
 *
 * No index. Nothing queries by either column; they are read only as part of
 * a row already being fetched by id or by message_id.
 *
 * Column names are short and both tables are well under the 24-character
 * guidance, so no explicit constraint names are needed here (SKILLS.md
 * § Database identifier length applies to new tables and indexes).
 */
class Version000407004Date20260828000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        foreach (['teamhub_messages', 'teamhub_comments'] as $tableName) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }

            $table = $schema->getTable($tableName);

            if (!$table->hasColumn('edited_by')) {
                // Same length as the existing author_id column on both tables —
                // it holds the same kind of value, an NC uid.
                $table->addColumn('edited_by', Types::STRING, [
                    'notnull' => false,
                    'length'  => 64,
                ]);
                $output->info('Added edited_by column to ' . $tableName);
            }

            if (!$table->hasColumn('edited_at')) {
                // BIGINT epoch seconds, matching created_at / updated_at on
                // these tables rather than introducing a second time format.
                $table->addColumn('edited_at', Types::BIGINT, [
                    'notnull' => false,
                    'length'  => 8,
                ]);
                $output->info('Added edited_at column to ' . $tableName);
            }
        }

        return $schema;
    }
}
