<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.75.2 — create teamhub_dec_ext_links table.
 *
 * Table name: teamhub_dec_ext_links (21 chars, within the ≤27 budget).
 *
 * One row per external-decision link attached to a proposal/decision. The
 * use case: a decision made in this team needs to be tracked further (e.g.
 * to organisation management using a different tool), OR an earlier
 * decision in another system led to the creation of this decision and we
 * want to reference it. Either direction is just "an outbound URL with
 * an optional label" — there's no two-way relation here because the other
 * end is by definition outside TeamHub.
 */
class Version000375020Date20260611100000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_dec_ext_links')) {
            return null; // idempotent
        }

        $table = $schema->createTable('teamhub_dec_ext_links');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull'       => true,
            'unsigned'      => true,
        ]);
        $table->addColumn('team_id', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
        ]);
        $table->addColumn('decision_id', Types::BIGINT, [
            'notnull'  => true,
            'unsigned' => true,
        ]);
        // The external URL. Capped at 2048 — generous for most URLs, in
        // line with what mainstream browsers accept in an address bar.
        $table->addColumn('url', Types::STRING, [
            'notnull' => true,
            'length'  => 2048,
        ]);
        // Optional human-readable label. Default empty string (not null)
        // to keep query branching simple in the mapper.
        $table->addColumn('label', Types::STRING, [
            'notnull' => true,
            'length'  => 255,
            'default' => '',
        ]);
        $table->addColumn('created_by', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
        ]);
        $table->addColumn('created_at', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['decision_id'], 'dec_ext_decision_idx');
        $table->addIndex(['team_id'],     'dec_ext_team_idx');

        return $schema;
    }
}
