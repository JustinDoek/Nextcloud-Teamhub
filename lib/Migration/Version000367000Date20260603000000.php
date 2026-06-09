<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.67.0 — Decisions module: per-team predefined categories with
 * per-category approver lists.
 *
 * Created tables:
 *  - teamhub_dec_categories  — one row per (team_id, name)
 *  - teamhub_dec_cat_apprs   — m:n join from category to user (approvers)
 *
 * Table-name budget check (DESIGN.md §2.5, ≤27 chars non-prefix portion):
 *  teamhub_dec_categories  → 22 chars ✓
 *  teamhub_dec_cat_apprs   → 21 chars ✓
 *
 * Approval model (per Session G spec):
 *  - Every category has an approver list. Empty list is disallowed at the
 *    service layer; the team owner is auto-added when a category is created.
 *  - Either-one approval semantics — any user in the approver list can
 *    finalize/approve decisions in that category (decided in Session G Q2).
 *
 * Notes:
 *  - Existing `teamhub_decisions.category` remains a free-text column for now.
 *    Frontend in Session G switches to a constrained NcSelect populated from
 *    this table; legacy free-text values stay untouched. Session H will add
 *    a foreign-key column if it turns out we need referential integrity.
 *  - No `Types::BOOLEAN` per DESIGN.md §2.4 — none needed here either.
 */
class Version000367000Date20260603000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // =====================================================================
        // 1. teamhub_dec_categories — predefined categories per team
        // =====================================================================
        if (!$schema->hasTable('teamhub_dec_categories')) {
            $table = $schema->createTable('teamhub_dec_categories');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
            ]);
            $table->addColumn('team_id', Types::STRING, [
                'length'  => 64,
                'notnull' => true,
            ]);
            $table->addColumn('name', Types::STRING, [
                'length'  => 128,
                'notnull' => true,
            ]);
            // The user who created the category. Display-only; not used for
            // authorisation. Authoritative permission list lives in
            // teamhub_dec_cat_apprs.
            $table->addColumn('created_by', Types::STRING, [
                'length'  => 64,
                'notnull' => true,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);

            // Unique within a team. Two teams can have categories of the
            // same name; that's intended.
            $table->addUniqueIndex(['team_id', 'name'], 'teamhub_dec_cat_team_name_uq');

            // Lookup by team is the dominant query path.
            $table->addIndex(['team_id'], 'teamhub_dec_cat_team_idx');
        }

        // =====================================================================
        // 2. teamhub_dec_cat_apprs — approvers per category (m:n)
        // =====================================================================
        if (!$schema->hasTable('teamhub_dec_cat_apprs')) {
            $table = $schema->createTable('teamhub_dec_cat_apprs');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
            ]);
            $table->addColumn('category_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'length'  => 64,
                'notnull' => true,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);

            // No duplicates per (category, user).
            $table->addUniqueIndex(['category_id', 'user_id'], 'teamhub_dec_cat_appr_uq');

            // Lookup all categories a user can approve in — used by the
            // widget's "Approve" tab gate (Session I).
            $table->addIndex(['user_id'], 'teamhub_dec_cat_appr_user_idx');

            // Lookup all approvers for a category — dominant read path.
            $table->addIndex(['category_id'], 'teamhub_dec_cat_appr_cat_idx');
        }

        return $schema;
    }
}
