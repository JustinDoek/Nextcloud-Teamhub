<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.72.16 — make deck_card_id nullable on teamhub_dec_tasks.
 *
 * The v3.64.0 migration created this column as NOT NULL with no default.
 * Session B inserts rows using task_path (not deck_card_id), so MySQL
 * rejects the INSERT because deck_card_id has no value and no default.
 * Making it nullable lets both old and new row shapes coexist.
 */
class Version000372160Date20260609070000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_dec_tasks')) {
            $table = $schema->getTable('teamhub_dec_tasks');
            if ($table->hasColumn('deck_card_id')) {
                $col = $table->getColumn('deck_card_id');
                $col->setNotnull(false);
                $col->setDefault(null);
            }
        }

        return $schema;
    }
}
