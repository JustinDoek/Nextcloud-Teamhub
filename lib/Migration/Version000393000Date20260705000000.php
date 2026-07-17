<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.93.0 — Additional per-lane editors (Track E Budget page follow-up).
 *
 * teamhub_budget_lane_editor — one row per (lane, user) where the user is
 * granted edit access to the lane *independent of their team role level*.
 * The base level gates (view_min_level / edit_min_level on
 * teamhub_budget_lane) remain the ordinary role-based path; this table is
 * an additive override for specific users. Any user in this list can:
 *   - edit that lane's allocation-independent expenses, and
 *   - view the lane on the Budget page (edit access implies view access —
 *     see BudgetService::canView).
 *
 * Storage is a separate table (rather than a comma-separated column on
 * teamhub_budget_lane) so the reverse lookup "which lanes can user X edit?"
 * stays a simple indexed query — needed at Budget-page fetch time to filter
 * lanes the caller can see.
 */
class Version000393000Date20260705000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_budget_lane_editor')) {
            return null;
        }

        $t = $schema->createTable('teamhub_budget_lane_editor');

        $t->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull'       => true,
            'unsigned'      => true,
        ]);
        $t->addColumn('lane_id', Types::BIGINT, [
            'notnull' => true,
        ]);
        $t->addColumn('user_id', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
        ]);
        $t->addColumn('created_at', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);

        // Explicit short PK name required — the table name is 26 chars and
        // Doctrine's auto-generated PK name would be "teamhub_budget_lane_editor_pkey"
        // (31 chars), which exceeds NC DBAL's 30-char identifier cap. Same
        // fix as th_tar_pk on teamhub_team_app_resources. Documented as a
        // hard rule in SKILLS.md § "Database identifier length".
        $t->setPrimaryKey(['id'], 'th_ble_pk');
        $t->addUniqueIndex(['lane_id', 'user_id'], 'th_bl_editor_uq');
        // Reverse lookup: "which lanes can user X edit?" — used every fetch.
        $t->addIndex(['user_id'], 'th_bl_editor_uidx');

        return $schema;
    }
}
