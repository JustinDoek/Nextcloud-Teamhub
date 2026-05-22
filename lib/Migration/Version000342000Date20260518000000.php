<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.42.0 — Presence module foundation (Session B1 / L).
 *
 * Creates the eight tables the presence module needs:
 *
 *   Org-wide catalogues (filled in B1 — admin CRUD live):
 *     - teamhub_presence_types       (22 chars)  status catalogue
 *     - teamhub_buildings            (18 chars)  building level
 *     - teamhub_floors               (15 chars)  floor level
 *     - teamhub_rooms                (14 chars)  room level
 *     - teamhub_holidays             (17 chars)  admin-locked dates
 *
 *   Per-user storage (created here, schema final, no rows in B1):
 *     - teamhub_presence_template    (25 chars)  user's recurring week pattern
 *     - teamhub_presence_slots       (22 chars)  materialised concrete slots
 *
 *   Per-team config (created here, schema final, no rows in B1):
 *     - teamhub_presence_team        (21 chars)  per-team privacy choice
 *
 * Every table name's non-prefix portion is verified ≤27 chars per
 * DESIGN.md §2.5 (DBAL table-name budget).
 *
 * Cross-DB rules applied throughout per DESIGN.md §2.4:
 *   - Booleans stored as SMALLINT with explicit default (never Types::BOOLEAN —
 *     PostgreSQL rejects the 't'/'f' coercion that MySQL accepts).
 *   - Dates stored as VARCHAR(10) ISO YYYY-MM-DD (never Types::DATE — cross-DB
 *     binding is fiddly with native DATE).
 *   - Timestamps stored as BIGINT unix seconds (matches existing TeamHub pattern).
 *
 * Every primary key has an explicit short name per the v3.36.2 lesson —
 * PostgreSQL auto-generates "oc_<table>_pkey" which exceeds NC's 30-char
 * constraint-name limit on these table names. Index names are similarly
 * short-prefixed (th_<table-acronym>_<purpose>).
 *
 * Idempotent: each table is wrapped in $schema->hasTable() — safe to re-run.
 */
class Version000342000Date20260518000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        // ---------------------------------------------------------------------
        // teamhub_presence_types  (22 chars) — admin-managed status catalogue
        // ---------------------------------------------------------------------
        if (!$schema->hasTable('teamhub_presence_types')) {
            $output->info('Version000342000: creating teamhub_presence_types');

            $table = $schema->createTable('teamhub_presence_types');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
                'length'        => 8,
            ]);

            // Machine name. Built-ins use stable slugs ('office', 'home', ...).
            // Used as natural key by the seed repair step (idempotent upsert).
            $table->addColumn('slug', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            // Display name. Translatable on read via t('teamhub', $label).
            $table->addColumn('label', Types::STRING, [
                'notnull' => true,
                'length'  => 128,
            ]);

            // mdi icon name, e.g. 'OfficeBuilding'.
            $table->addColumn('icon', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
                'default' => '',
            ]);

            // Hex colour, e.g. '#2196F3'.
            $table->addColumn('color', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
                'default' => '',
            ]);

            // Drives the location picker in B2 (1 = picker shown, 0 = hidden).
            $table->addColumn('requires_location', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            // Drives VEVENT TRANSP in B4 (1 = BUSY, 0 = TRANSPARENT).
            $table->addColumn('is_busy', Types::SMALLINT, [
                'notnull' => true,
                'default' => 1,
            ]);

            // 'holiday' is 0 — a user cannot self-pick a holiday slot.
            $table->addColumn('selectable_by_user', Types::SMALLINT, [
                'notnull' => true,
                'default' => 1,
            ]);

            // Built-in types cannot be deleted by admins.
            $table->addColumn('is_builtin', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->addColumn('sort_order', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id'], 'th_ptyp_pk');
            $table->addUniqueIndex(['slug'], 'th_ptyp_slug_uniq');

            $output->info('Version000342000: created teamhub_presence_types');
            $changed = true;
        } else {
            $output->info('Version000342000: teamhub_presence_types already exists — skipping');
        }

        // ---------------------------------------------------------------------
        // teamhub_buildings  (18 chars)
        // ---------------------------------------------------------------------
        if (!$schema->hasTable('teamhub_buildings')) {
            $output->info('Version000342000: creating teamhub_buildings');

            $table = $schema->createTable('teamhub_buildings');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
                'length'        => 8,
            ]);

            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
            ]);

            $table->addColumn('address', Types::STRING, [
                'notnull' => false,
                'length'  => 255,
                'default' => null,
            ]);

            $table->addColumn('sort_order', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id'], 'th_bld_pk');

            $output->info('Version000342000: created teamhub_buildings');
            $changed = true;
        } else {
            $output->info('Version000342000: teamhub_buildings already exists — skipping');
        }

        // ---------------------------------------------------------------------
        // teamhub_floors  (15 chars)
        // ---------------------------------------------------------------------
        if (!$schema->hasTable('teamhub_floors')) {
            $output->info('Version000342000: creating teamhub_floors');

            $table = $schema->createTable('teamhub_floors');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
                'length'        => 8,
            ]);

            // Semantic FK to teamhub_buildings.id. NC convention: no DB-level FK;
            // referential integrity enforced in the service layer (cascading
            // delete via PresenceLocationService::deleteBuilding).
            $table->addColumn('building_id', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
                'length'  => 8,
            ]);

            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
            ]);

            $table->addColumn('sort_order', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id'], 'th_flr_pk');
            // Tree-load support: fetch all floors for a building.
            $table->addIndex(['building_id'], 'th_flr_bld_idx');

            $output->info('Version000342000: created teamhub_floors');
            $changed = true;
        } else {
            $output->info('Version000342000: teamhub_floors already exists — skipping');
        }

        // ---------------------------------------------------------------------
        // teamhub_rooms  (14 chars)
        // ---------------------------------------------------------------------
        if (!$schema->hasTable('teamhub_rooms')) {
            $output->info('Version000342000: creating teamhub_rooms');

            $table = $schema->createTable('teamhub_rooms');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
                'length'        => 8,
            ]);

            // Semantic FK to teamhub_floors.id (same NC convention as above).
            $table->addColumn('floor_id', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
                'length'  => 8,
            ]);

            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
            ]);

            $table->addColumn('sort_order', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id'], 'th_rm_pk');
            // Tree-load support: fetch all rooms for a floor.
            $table->addIndex(['floor_id'], 'th_rm_flr_idx');

            $output->info('Version000342000: created teamhub_rooms');
            $changed = true;
        } else {
            $output->info('Version000342000: teamhub_rooms already exists — skipping');
        }

        // ---------------------------------------------------------------------
        // teamhub_holidays  (17 chars) — admin-locked dates
        // ---------------------------------------------------------------------
        if (!$schema->hasTable('teamhub_holidays')) {
            $output->info('Version000342000: creating teamhub_holidays');

            $table = $schema->createTable('teamhub_holidays');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
                'length'        => 8,
            ]);

            // ISO YYYY-MM-DD. VARCHAR(10) over Types::DATE per DESIGN.md §2.4 —
            // cross-DB binding is fiddly with native DATE on PostgreSQL.
            // Sortable lexicographically; joins cleanly against slot.slot_date.
            $table->addColumn('holiday_date', Types::STRING, [
                'notnull' => true,
                'length'  => 10,
            ]);

            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length'  => 128,
            ]);

            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id'], 'th_hol_pk');
            // One holiday per date.
            $table->addUniqueIndex(['holiday_date'], 'th_hol_date_uniq');

            $output->info('Version000342000: created teamhub_holidays');
            $changed = true;
        } else {
            $output->info('Version000342000: teamhub_holidays already exists — skipping');
        }

        // ---------------------------------------------------------------------
        // teamhub_presence_template  (25 chars) — user week template (empty in B1)
        // ---------------------------------------------------------------------
        if (!$schema->hasTable('teamhub_presence_template')) {
            $output->info('Version000342000: creating teamhub_presence_template');

            $table = $schema->createTable('teamhub_presence_template');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
                'length'        => 8,
            ]);

            // NC user id (uid). VARCHAR(64) matches NC's own user-id length.
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            // 0=Mon, 1=Tue, ..., 6=Sun (ISO 8601 day-of-week numbering).
            $table->addColumn('day_of_week', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            // 0=AM, 1=PM.
            $table->addColumn('half_day', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            // Nullable — null in template = empty slot (B2 will render as
            // "no entry" rather than picking a default).
            $table->addColumn('presence_type_id', Types::BIGINT, [
                'notnull' => false,
                'unsigned' => true,
                'length'  => 8,
                'default' => null,
            ]);

            // Nullable — only set when the chosen type has requires_location=1.
            $table->addColumn('location_room_id', Types::BIGINT, [
                'notnull' => false,
                'unsigned' => true,
                'length'  => 8,
                'default' => null,
            ]);

            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id'], 'th_ptmpl_pk');
            // Enforces one row per (user, day, half-day) — natural key for the
            // template's 14-slot grid (7 days × AM/PM).
            $table->addUniqueIndex(['user_id', 'day_of_week', 'half_day'], 'th_ptmpl_uniq');
            // User-scoped reads load the whole template in one query.
            $table->addIndex(['user_id'], 'th_ptmpl_user_idx');

            $output->info('Version000342000: created teamhub_presence_template');
            $changed = true;
        } else {
            $output->info('Version000342000: teamhub_presence_template already exists — skipping');
        }

        // ---------------------------------------------------------------------
        // teamhub_presence_slots  (22 chars) — materialised concrete slots
        //                                       (empty in B1, filled in B2)
        // ---------------------------------------------------------------------
        if (!$schema->hasTable('teamhub_presence_slots')) {
            $output->info('Version000342000: creating teamhub_presence_slots');

            $table = $schema->createTable('teamhub_presence_slots');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
                'length'        => 8,
            ]);

            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            // ISO YYYY-MM-DD per §2.4. Indexed alone for cron + holiday-sweep
            // range scans ("all slots on date X").
            $table->addColumn('slot_date', Types::STRING, [
                'notnull' => true,
                'length'  => 10,
            ]);

            $table->addColumn('half_day', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->addColumn('presence_type_id', Types::BIGINT, [
                'notnull' => false,
                'unsigned' => true,
                'length'  => 8,
                'default' => null,
            ]);

            $table->addColumn('location_room_id', Types::BIGINT, [
                'notnull' => false,
                'unsigned' => true,
                'length'  => 8,
                'default' => null,
            ]);

            // Provenance of this row's values:
            //   'template' — auto-materialised from user's week template
            //   'override' — user explicitly set this slot
            //   'holiday'  — admin-locked by a holiday on this date
            // Drives overwrite/revert logic in B2/B3.
            $table->addColumn('source', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
                'default' => 'template',
            ]);

            // UID of the corresponding VEVENT in the user's default calendar.
            // Populated by B4; preserved across holiday overwrites so B4's
            // update logic can re-point the event rather than creating a new one.
            $table->addColumn('calendar_event_uid', Types::STRING, [
                'notnull' => false,
                'length'  => 255,
                'default' => null,
            ]);

            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id'], 'th_pslot_pk');
            // Enforces one row per (user, date, half-day).
            $table->addUniqueIndex(['user_id', 'slot_date', 'half_day'], 'th_pslot_uniq');
            // Holiday-sweep: "all slots on date X" across all users.
            $table->addIndex(['slot_date'], 'th_pslot_date_idx');
            // User-scoped reads: "show me my slots in date range".
            $table->addIndex(['user_id'], 'th_pslot_user_idx');

            $output->info('Version000342000: created teamhub_presence_slots');
            $changed = true;
        } else {
            $output->info('Version000342000: teamhub_presence_slots already exists — skipping');
        }

        // ---------------------------------------------------------------------
        // teamhub_presence_team  (21 chars) — per-team privacy (empty in B1)
        // ---------------------------------------------------------------------
        if (!$schema->hasTable('teamhub_presence_team')) {
            $output->info('Version000342000: creating teamhub_presence_team');

            $table = $schema->createTable('teamhub_presence_team');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
                'length'        => 8,
            ]);

            // circles_circle.unique_id of the team.
            $table->addColumn('team_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            // 0 = full detail visible to team members
            // 1 = hide reasons (show busy/free/away without the status label)
            $table->addColumn('hide_reasons', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id'], 'th_pteam_pk');
            // One row per team.
            $table->addUniqueIndex(['team_id'], 'th_pteam_team_uniq');

            $output->info('Version000342000: created teamhub_presence_team');
            $changed = true;
        } else {
            $output->info('Version000342000: teamhub_presence_team already exists — skipping');
        }

        return $changed ? $schema : null;
    }
}
