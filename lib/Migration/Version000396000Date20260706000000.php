<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.96.0 — Execution-phase Time investment page (Track E Session 5).
 *
 * Three coordinated schema changes, following the Budget-page arc's shape:
 *
 *   1. teamhub_project gains `time_view_min_level` (SMALLINT: 1=member,
 *      4=moderator+, 8=admin-only; default 1). Mirrors budget_view_min_level.
 *      Single project-level floor that gates the Time tab; users below the
 *      floor still see the tab if they have a teamhub_project_member row
 *      (i.e. they're a named project participant — precomputed as
 *      timeConfig.can_view_time in the /layout bundle).
 *
 *   2. teamhub_project_member — one row per (team_id, user_id) recording the
 *      member's `available_minutes` (INT). 0 = uncapped: the report simply
 *      accumulates logged minutes without a "remaining" column for that user.
 *      Presence of a row = "project participant" (shows up in the per-member
 *      report grid). Admins manage this list from Manage Team → Project.
 *
 *   3. teamhub_time_log — one row per logged block of work. Attaches to a
 *      Deck card (card_id) inside a stack (stack_id — denormalised at write
 *      so lane rollups survive later card moves/deletes). `user_id` is the
 *      person the time is *for* (the card assignee), not necessarily the
 *      creator (`created_by`) — an admin can log on behalf. `minutes` is INT
 *      (same integer-first rule the Budget-arc BIGINT-minor-units treatment
 *      established: no float drift, portable across MySQL/MariaDB/Postgres).
 *      `worked_at` is a UTC-midnight Unix timestamp (matches
 *      teamhub_expense.incurred_at and teamhub_milestones.milestone_date).
 */
class Version000396000Date20260706000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── 1. teamhub_project — add time_view_min_level ───────────────────
        if ($schema->hasTable('teamhub_project')) {
            $projectTable = $schema->getTable('teamhub_project');
            if (!$projectTable->hasColumn('time_view_min_level')) {
                $projectTable->addColumn('time_view_min_level', Types::SMALLINT, [
                    'notnull' => true,
                    'default' => 1,
                ]);
            }
        }

        // ── 2. teamhub_project_member ──────────────────────────────────────
        if (!$schema->hasTable('teamhub_project_member')) {
            $member = $schema->createTable('teamhub_project_member');

            $member->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);
            $member->addColumn('team_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            $member->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            // 0 = no cap (report accumulates only). Positive integer minutes
            // otherwise. INT range (≈35,000 hours) is comfortably more than
            // any realistic project allowance.
            $member->addColumn('available_minutes', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $member->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $member->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            $member->setPrimaryKey(['id']);
            // One row per (team, user).
            $member->addUniqueIndex(['team_id', 'user_id'], 'th_project_member_uq');
            // Fast fetch of "all members of project X" for the report grid.
            $member->addIndex(['team_id'], 'th_project_member_team_idx');
        }

        // ── 3. teamhub_time_log ────────────────────────────────────────────
        if (!$schema->hasTable('teamhub_time_log')) {
            $log = $schema->createTable('teamhub_time_log');

            $log->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);
            $log->addColumn('team_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            // Deck card PK (deck_cards.id). BIGINT to match Deck's own column.
            $log->addColumn('card_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            // Deck stack PK (deck_stacks.id). Denormalised at write so the
            // lane rollup survives later card moves or Deck-side deletions.
            $log->addColumn('stack_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            // The person the time was worked BY (card assignee). May differ
            // from created_by when an admin logs on behalf.
            $log->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            $log->addColumn('minutes', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $log->addColumn('description', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
                'default' => '',
            ]);
            // UTC-midnight Unix timestamp of the day the work was performed.
            $log->addColumn('worked_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $log->addColumn('created_by', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            $log->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $log->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            $log->setPrimaryKey(['id']);
            $log->addIndex(['team_id'], 'th_time_log_team_idx');
            // "All logs for user X" — per-member report drill-down.
            $log->addIndex(['team_id', 'user_id'], 'th_time_log_team_user_idx');
            // "All logs for card X" — future per-card side panel.
            $log->addIndex(['card_id'], 'th_time_log_card_idx');
            // "All logs in lane X" — per-lane rollup.
            $log->addIndex(['team_id', 'stack_id'], 'th_time_log_team_stack_idx');
        }

        return $schema;
    }
}
