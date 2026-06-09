<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.64.0 — Decisions module: create teamhub_decision_team,
 *            teamhub_decisions, and teamhub_dec_tasks.
 *
 * All three tables are created here so:
 *  - Fresh installs land the full schema in one shot.
 *  - Existing installs get the same tables via the same migration.
 * No data backfill is needed — the tables start empty.
 *
 * Table name budget check (DESIGN.md §2.5 — max 27 chars for non-prefix portion):
 *  teamhub_decision_team  → 21 chars ✓
 *  teamhub_decisions      → 17 chars ✓
 *  teamhub_dec_tasks      → 16 chars ✓
 *
 * Type notes:
 *  - BOOLEAN columns use Types::SMALLINT per DESIGN.md §2.4 (MySQL/MariaDB
 *    compatibility). All boolean-like columns have explicit defaults.
 *  - All timestamps are unix epoch BIGINT, matching teamhub_messages convention.
 *  - impact and status are STRING columns validated at the application layer,
 *    not DB-level enums (Doctrine portable enums are unreliable cross-DB).
 */
class Version000364000Date20260602000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // =====================================================================
        // 1. teamhub_decision_team — per-team module config
        // =====================================================================
        if (!$schema->hasTable('teamhub_decision_team')) {
            $table = $schema->createTable('teamhub_decision_team');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
            ]);
            $table->addColumn('team_id', Types::STRING, [
                'length'  => 64,
                'notnull' => true,
            ]);
            $table->addColumn('decisions_enabled', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
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
            $table->addUniqueIndex(['team_id'], 'teamhub_dec_team_unique_idx');
        }

        // =====================================================================
        // 2. teamhub_decisions — decision records
        // =====================================================================
        if (!$schema->hasTable('teamhub_decisions')) {
            $table = $schema->createTable('teamhub_decisions');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
            ]);
            $table->addColumn('team_id', Types::STRING, [
                'length'  => 64,
                'notnull' => true,
            ]);
            $table->addColumn('message_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('proposed_by', Types::STRING, [
                'length'  => 64,
                'notnull' => true,
            ]);
            $table->addColumn('answered_by', Types::STRING, [
                'length'   => 64,
                'notnull'  => false,
                'default'  => null,
            ]);
            $table->addColumn('selected_comment_id', Types::BIGINT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('category', Types::STRING, [
                'length'  => 128,
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('impact', Types::STRING, [
                'length'  => 8,  // 'low' | 'medium' | 'high'
                'notnull' => true,
            ]);
            $table->addColumn('question', Types::TEXT, [
                'notnull' => true,
            ]);
            $table->addColumn('selected_answer', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('participants', Types::TEXT, [
                // JSON array of user ID strings captured at decision time.
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('status', Types::STRING, [
                'length'  => 16,  // 'proposed' | 'decided' | 'withdrawn'
                'notnull' => true,
            ]);
            $table->addColumn('withdrawn_reason', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('resolved_by', Types::STRING, [
                // Set when a team admin marks/withdraws on the poster's behalf.
                'length'  => 64,
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('supersedes_id', Types::BIGINT, [
                // FK to teamhub_decisions.id — not DB-enforced.
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('source_type', Types::STRING, [
                // 'message' | 'document' | 'meeting_notes' | 'external'
                'length'  => 16,
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('source_ref', Types::STRING, [
                'length'  => 512,
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('decided_at', Types::BIGINT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('withdrawn_at', Types::BIGINT, [
                'notnull' => false,
                'default' => null,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['team_id'],      'teamhub_dec_team_idx');
            $table->addIndex(['message_id'],   'teamhub_dec_msg_idx');
            $table->addIndex(['status'],       'teamhub_dec_status_idx');
            $table->addIndex(['supersedes_id'], 'teamhub_dec_super_idx');
        }

        // =====================================================================
        // 3. teamhub_dec_tasks — decision ↔ Deck card link table
        // =====================================================================
        if (!$schema->hasTable('teamhub_dec_tasks')) {
            $table = $schema->createTable('teamhub_dec_tasks');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
            ]);
            $table->addColumn('decision_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('deck_card_id', Types::BIGINT, [
                // No DB-enforced FK — Deck owns oc_deck_cards.
                'notnull' => true,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('created_by', Types::STRING, [
                'length'  => 64,
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['decision_id', 'deck_card_id'], 'teamhub_dec_tasks_uniq_idx');
            $table->addIndex(['deck_card_id'], 'teamhub_dec_tasks_card_idx');
        }

        return $schema;
    }
}
