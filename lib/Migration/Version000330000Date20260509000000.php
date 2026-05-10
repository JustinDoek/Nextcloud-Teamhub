<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds owner_uid column to teamhub_team_app_resources.
 *
 * owner_uid stores the NC uid of the user who owns the underlying NC resource
 * (the file sharer, Talk room creator, calendar owner, or Deck board owner).
 * Used by the UserStatusListener and UserDeletedListener to find affected rows
 * when a user is disabled or deleted.
 *
 * Nullable: rows grandfathered by the Session A migration have no owner
 * information — they will be populated on the next reconciliation cycle.
 */
class Version000330000Date20260509000000 extends SimpleMigrationStep {

    public function __construct(private readonly IDBConnection $db) {}

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $table  = $schema->getTable('teamhub_team_app_resources');

        if ($table->hasColumn('owner_uid')) {
            return null; // idempotent
        }

        $table->addColumn('owner_uid', Types::STRING, [
            'notnull' => false,
            'length'  => 64,
            'default' => null,
            'comment' => 'NC uid of the resource owner (file sharer, room creator, calendar owner, board owner)',
        ]);

        return $schema;
    }
}
