<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.78.2 — create teamhub_milestones table (Timeline Milestones, v1).
 *
 * Table name: teamhub_milestones (19 chars, within the ≤27 budget).
 *
 * One row per milestone a team admin places on the Timeline tab. A
 * milestone is just a label with an optional date — the date is optional
 * because an admin may want to record "we will ship a beta" before a firm
 * date is picked. Milestones without a date are listed in Manage Team →
 * Integration settings but are NOT plotted on the Timeline (there is no
 * x-position to plot them at). v1 renders dated milestones as a full-height
 * red marker line with the label, independent of the four source bands
 * (Deck / Calendar / Decisions / Messages) — see TimelineService and
 * templates/timeline.php.
 *
 * No separate enable flag — milestones are gated behind the existing
 * per-team `timeline_enabled_<teamId>` app-config flag (if the Timeline
 * tab is off for a team, there is nowhere to plot milestones anyway).
 */
class Version000378002Date20260617000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_milestones')) {
            return null; // idempotent — covers both fresh installs and upgrades
        }

        $table = $schema->createTable('teamhub_milestones');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull'       => true,
            'unsigned'      => true,
        ]);
        $table->addColumn('team_id', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
        ]);
        $table->addColumn('label', Types::STRING, [
            'notnull' => true,
            'length'  => 255,
        ]);
        // Unix timestamp (UTC midnight of the chosen date). Nullable —
        // a milestone may be created before a firm date is known.
        $table->addColumn('milestone_date', Types::BIGINT, [
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

        $table->setPrimaryKey(['id']);
        $table->addIndex(['team_id'], 'th_milestone_team_idx');

        return $schema;
    }
}
