<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.4.17 — create teamhub_announce_read.
 *
 * Per-user dismissal ledger for in-app announcements. A row exists iff the
 * user has clicked "Got it" on that announcement's filename. Announcements
 * live as .md files in the app's announcements/ folder; the registry that
 * links filename → role + version is source-controlled JSON. Only the
 * dismissal state is per-user, so only the dismissal state is in the DB.
 *
 * Table name is 21 chars → NC's 30-char DBAL cap on the auto-generated
 * primary-key name is safe without an explicit short PK name (see
 * SKILLS.md § Database identifier length).
 */
class Version000404017Date20260728000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_announce_read')) {
            return null;
        }

        $table = $schema->createTable('teamhub_announce_read');

        $table->addColumn('user_id', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
        ]);
        $table->addColumn('filename', Types::STRING, [
            'notnull' => true,
            'length'  => 128,
        ]);
        $table->addColumn('dismissed_at', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);

        $table->setPrimaryKey(['user_id', 'filename']);

        return $schema;
    }
}
