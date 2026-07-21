<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.2.11 — add is_public flag to teamhub_messages.
 *
 * Marks a message as viewable outside the team scope. When set, the message
 * becomes available through GET /api/v1/messages/public to any authenticated
 * user on this NC instance (see MessageController::listPublicMessages), so it
 * can surface on the personal aggregated feed alongside the user's own-team
 * messages.
 *
 * Whether members are ALLOWED to publish public messages is an admin-gated
 * per-team setting (app-config key allowPublicMessages_<teamId>, default off).
 * The compose form only shows the checkbox when the setting is on; the service
 * layer forces is_public back to 0 when it isn't, so a hand-crafted request
 * cannot bypass the gate.
 *
 * SMALLINT chosen to match the existing pinned column pattern (cross-database
 * boolean-safe on both MariaDB and Postgres; see DESIGN §2.4).
 */
class Version000402011Date20260720000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_messages')) {
            return null;
        }

        $table = $schema->getTable('teamhub_messages');
        if (!$table->hasColumn('is_public')) {
            $table->addColumn('is_public', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
                'length'  => 1,
            ]);
            $output->info('Added is_public column to teamhub_messages');
        }

        return $schema;
    }
}
