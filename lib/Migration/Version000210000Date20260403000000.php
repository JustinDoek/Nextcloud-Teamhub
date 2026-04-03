<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v2.32.0 — add teamhub_widget_layouts table.
 *
 * Stores per-user, per-team Home-view grid layout and tab order.
 * layout_json  : vue-grid-layout item array (x, y, w, h, i per widget)
 * tab_order_json: ordered array of tab keys e.g. ["home","talk","files","calendar","deck"]
 */
class Version000210000Date20260403000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_widget_layouts')) {
            // Idempotent — nothing to do if already present.
            return null;
        }

        $table = $schema->createTable('teamhub_widget_layouts');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull'       => true,
            'unsigned'      => true,
        ]);

        // NC user ID (max 64 chars per NC convention).
        $table->addColumn('user_id', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
        ]);

        // Circles/Teams UUID.
        $table->addColumn('team_id', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
        ]);

        // JSON blob: vue-grid-layout layout array.
        // Each entry: {"i":"msgstream","x":0,"y":0,"w":8,"h":5,"minW":3,"minH":3}
        $table->addColumn('layout_json', Types::TEXT, [
            'notnull' => true,
            'default' => '[]',
        ]);

        // JSON blob: ordered array of tab key strings.
        // e.g. ["home","talk","files","calendar","deck","link-42"]
        $table->addColumn('tab_order_json', Types::TEXT, [
            'notnull' => true,
            'default' => '[]',
        ]);

        $table->addColumn('updated_at', Types::BIGINT, [
            'notnull'  => true,
            'unsigned' => true,
            'default'  => 0,
        ]);

        $table->setPrimaryKey(['id']);

        // One row per user+team combination.
        $table->addUniqueIndex(['user_id', 'team_id'], 'teamhub_wl_user_team');

        // Fast lookup by team (e.g. bulk read for shared-layout future feature).
        $table->addIndex(['team_id'], 'teamhub_wl_team');

        return $schema;
    }
}
