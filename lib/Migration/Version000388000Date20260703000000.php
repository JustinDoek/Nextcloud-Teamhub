<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.88.0 — create teamhub_project table (Project Teams keystone, v1).
 *
 * Table name: teamhub_project (15 chars, within the ≤27 budget).
 *
 * One row per team created from the "Project" template. Its existence is the
 * project flag — a team without a row is not a project. `mode` discriminates
 * the two flavours the create wizard offers:
 *   - basic    — the historical cosmetic behaviour (no lifecycle UI), but the
 *                team is now recorded as a project (enables a later upgrade).
 *   - advanced — the PMC lifecycle: `phase` walks Initiation → Planning →
 *                Execution → Closing. `phase` is NULL for basic projects.
 *
 * start_date / target_end are Unix timestamps at UTC midnight (same convention
 * as teamhub_milestones.milestone_date), both nullable — a project may exist
 * before firm dates are chosen. Budget, time-tracking and dashboard data live
 * in their own tables introduced in later sessions.
 */
class Version000388000Date20260703000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_project')) {
            return null; // idempotent — covers both fresh installs and upgrades
        }

        $table = $schema->createTable('teamhub_project');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull'       => true,
            'unsigned'      => true,
        ]);
        $table->addColumn('team_id', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
        ]);
        // Reserved for a future Collaboration/Department distinction; today
        // every row is 'project'.
        $table->addColumn('type', Types::STRING, [
            'notnull' => true,
            'length'  => 32,
            'default' => 'project',
        ]);
        // Lifecycle discriminator: 'basic' | 'advanced'.
        $table->addColumn('mode', Types::STRING, [
            'notnull' => true,
            'length'  => 16,
            'default' => 'basic',
        ]);
        // PMC phase for advanced projects: initiation|planning|execution|closing.
        // NULL for basic projects (no lifecycle).
        $table->addColumn('phase', Types::STRING, [
            'notnull' => false,
            'length'  => 32,
            'default' => null,
        ]);
        // Unix timestamps (UTC midnight). Nullable — dates may be unset.
        $table->addColumn('start_date', Types::BIGINT, [
            'notnull' => false,
            'default' => null,
        ]);
        $table->addColumn('target_end', Types::BIGINT, [
            'notnull' => false,
            'default' => null,
        ]);
        $table->addColumn('created_by', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
        ]);
        $table->addColumn('created_at', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('updated_at', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);

        $table->setPrimaryKey(['id']);
        // One project row per team — unique so getForTeam is unambiguous.
        $table->addUniqueIndex(['team_id'], 'th_project_team_idx');

        return $schema;
    }
}
