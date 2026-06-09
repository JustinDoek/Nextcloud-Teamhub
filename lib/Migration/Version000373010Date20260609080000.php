<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.73.1 — create teamhub_dec_links table for bidirectional decision-decision links.
 *
 * Table name: teamhub_dec_links (17 chars, within the ≤27 budget).
 *
 * One row per link. Both directions (a→b and b→a) are served from this
 * single row by querying WHERE decision_id_a = ? OR decision_id_b = ?.
 * A unique index on (decision_id_a, decision_id_b) prevents duplicates
 * (the service enforces canonical ordering a < b before insert so the
 * index catches swapped pairs too).
 */
class Version000373010Date20260609080000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_dec_links')) {
            return null; // idempotent
        }

        $table = $schema->createTable('teamhub_dec_links');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull'       => true,
            'unsigned'      => true,
        ]);
        $table->addColumn('team_id', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
        ]);
        // Canonical ordering: decision_id_a < decision_id_b always.
        $table->addColumn('decision_id_a', Types::BIGINT, [
            'notnull'  => true,
            'unsigned' => true,
        ]);
        $table->addColumn('decision_id_b', Types::BIGINT, [
            'notnull'  => true,
            'unsigned' => true,
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
        $table->addUniqueIndex(['decision_id_a', 'decision_id_b'], 'dec_links_pair_unique');
        $table->addIndex(['team_id'],        'dec_links_team_idx');
        $table->addIndex(['decision_id_a'],  'dec_links_a_idx');
        $table->addIndex(['decision_id_b'],  'dec_links_b_idx');

        return $schema;
    }
}
