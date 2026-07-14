<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.92.0 — Execution-phase Budget page (Track E Session 4).
 *
 * Three coordinated schema changes:
 *
 *   1. teamhub_project gains `currency` (ISO-4217, nullable) and
 *      `budget_total_minor` (BIGINT minor units, nullable). One project-total
 *      per team; per-lane allocations live in teamhub_budget_lane.
 *
 *   2. teamhub_budget_lane — one row per (team_id, deck_stack_id). Records the
 *      lane's allocated_minor and the two per-lane role gates
 *      (view_min_level, edit_min_level). Auto-created when the Budget page
 *      first syncs against the team's Deck stacks — no manual seeding.
 *
 *   3. teamhub_expense — the actual line items. Every expense belongs to
 *      exactly one lane; projected_minor is always set, real_minor is nullable
 *      (null = not yet realised). incurred_at is a UTC-midnight Unix timestamp
 *      (same convention teamhub_milestones + teamhub_project use).
 *
 * All money is BIGINT minor units (cents) — no floating-point drift, portable
 * across MySQL/MariaDB/Postgres. UI parses/formats to 2 decimals.
 */
class Version000392000Date20260704000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── 1. teamhub_project — add currency + budget_total_minor ─────────
        if ($schema->hasTable('teamhub_project')) {
            $projectTable = $schema->getTable('teamhub_project');
            if (!$projectTable->hasColumn('currency')) {
                $projectTable->addColumn('currency', Types::STRING, [
                    'notnull' => false,
                    'length'  => 3,
                    'default' => null,
                ]);
            }
            if (!$projectTable->hasColumn('budget_total_minor')) {
                $projectTable->addColumn('budget_total_minor', Types::BIGINT, [
                    'notnull' => false,
                    'default' => null,
                ]);
            }
        }

        // ── 2. teamhub_budget_lane ─────────────────────────────────────────
        if (!$schema->hasTable('teamhub_budget_lane')) {
            $lane = $schema->createTable('teamhub_budget_lane');

            $lane->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);
            $lane->addColumn('team_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            // Deck stack PK (deck_stacks.id). BIGINT to match Deck's own column.
            $lane->addColumn('deck_stack_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            // Nullable — a lane may exist (auto-created on sync) before an
            // allocation is set. Minor units.
            $lane->addColumn('allocated_minor', Types::BIGINT, [
                'notnull' => false,
                'default' => null,
            ]);
            // TeamHub role levels: 1 = member, 4 = moderator, 8 = admin.
            // View defaults to member (everyone sees), edit defaults to admin.
            $lane->addColumn('view_min_level', Types::SMALLINT, [
                'notnull' => true,
                'default' => 1,
            ]);
            $lane->addColumn('edit_min_level', Types::SMALLINT, [
                'notnull' => true,
                'default' => 8,
            ]);
            $lane->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $lane->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            $lane->setPrimaryKey(['id']);
            // One row per (team, stack).
            $lane->addUniqueIndex(['team_id', 'deck_stack_id'], 'th_budget_lane_uq');
            // Fast lookup by team for the Budget page fetch.
            $lane->addIndex(['team_id'], 'th_budget_lane_team_idx');
        }

        // ── 3. teamhub_expense ─────────────────────────────────────────────
        if (!$schema->hasTable('teamhub_expense')) {
            $expense = $schema->createTable('teamhub_expense');

            $expense->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);
            $expense->addColumn('team_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            // References teamhub_budget_lane.id. No FK — matches the rest of
            // the codebase (application-level integrity), and lane rows are
            // recreated on Deck-stack rename anyway.
            $expense->addColumn('lane_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $expense->addColumn('description', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
                'default' => '',
            ]);
            $expense->addColumn('projected_minor', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            // Null = not yet realised (i.e. still projected only).
            $expense->addColumn('real_minor', Types::BIGINT, [
                'notnull' => false,
                'default' => null,
            ]);
            // UTC-midnight timestamp. Nullable — expense may not have a firm
            // date when planned.
            $expense->addColumn('incurred_at', Types::BIGINT, [
                'notnull' => false,
                'default' => null,
            ]);
            $expense->addColumn('created_by', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            $expense->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $expense->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            $expense->setPrimaryKey(['id']);
            $expense->addIndex(['team_id'], 'th_expense_team_idx');
            $expense->addIndex(['lane_id'], 'th_expense_lane_idx');
        }

        return $schema;
    }
}
