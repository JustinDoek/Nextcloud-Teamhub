<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.0.2 — create teamhub_team_type table.
 *
 * One row per team, written by CreateTeamView after team creation. Stores the
 * template chosen in the create wizard (collaboration / project / department)
 * so the Team info widget and Browse Teams list can display it as a label.
 *
 * We deliberately do NOT extend teamhub_project.type for this. The project
 * row's existence is currently a boolean "is this a project" gate across
 * 14+ services (Budget, Time, Milestones, ClosingArtifact, DecisionService,
 * ProjectHealth, ProjectReadiness). Writing rows for collaboration and
 * department teams would flip that gate for every non-project team and
 * ripple into all of those services. A separate table keeps the concerns
 * orthogonal: project-ness stays where it is; template-type lives here.
 *
 * Legacy teams (created before this migration) have no row and render with
 * no template label — the frontend hides the label when type is null.
 */
class Version000400002Date20260716000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_team_type')) {
            return null;
        }

        $table = $schema->createTable('teamhub_team_type');

        $table->addColumn('team_id', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
        ]);
        // 'collaboration' | 'project' | 'department'. Enum discipline is
        // enforced in TeamTypeService::validateType — DB stays permissive so
        // future template names can be added without another migration.
        $table->addColumn('type', Types::STRING, [
            'notnull' => true,
            'length'  => 32,
        ]);
        $table->addColumn('created_by', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
        ]);
        $table->addColumn('created_at', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);

        // team_id is the primary key — one row per team, upsert on PUT.
        $table->setPrimaryKey(['team_id']);

        return $schema;
    }
}
