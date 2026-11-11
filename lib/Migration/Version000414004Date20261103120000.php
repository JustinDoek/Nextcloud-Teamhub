<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.14.4 — the instance-wide weekly pattern goes away (DESIGN §2.247).
 *
 * ## What is being dropped and why it is safe
 *
 * v4.13.68 gave a Nextcloud administrator a reusable week, instance-wide:
 * Admin, TeamHub, People, Shifts. One day later v4.13.74 gave a **team admin**
 * the roster week template, in Manage team, Module settings, built from the
 * same shift types and carrying a role per line. Justin's call, 2026-11-03:
 * one of those two is the week, and it is the team's.
 *
 * The administrator's pattern was also unusable by the people it was for.
 * `GET /api/v1/admin/shift-schedules` carried no `#[NoAdminRequired]`, so for
 * an ordinary team admin that half of the picker on the Roster tab had been
 * empty since the day it shipped (§2.240 recorded it and worked around it).
 * Four days between the two releases, and no public release in between.
 *
 *   teamhub_shift_schedule   dropped: the named pattern.
 *   teamhub_shift_sched_row  dropped: one cell of it.
 *
 * Nothing else goes. `teamhub_shift_type` is the vocabulary and stays — it is
 * what a team's template is written from. `teamhub_roster_shift`,
 * `teamhub_roster_assign` and `teamhub_shift_request` are weeks that were
 * actually worked and stay untouched: a shift laid out from a pattern was
 * **copied** into its own row, never a reference, so dropping the pattern
 * takes nothing with it.
 *
 * ## The upgrade path and the install path both end in the same schema
 *
 * An upgrading instance has the two tables and this step drops them. A fresh
 * install never has them: Nextcloud folds every step's `changeSchema()` into
 * one in-memory schema and applies it once (`/migrations` §2a), so
 * `Version000413068`'s `createTable` and this `dropTable` cancel and no table
 * is ever created. `hasTable` guards both paths either way.
 *
 * `Version000413068` keeps its `createTable` calls and must: an instance that
 * has not yet run it still gets the tables there first, and then loses them
 * here. A recorded migration is a historical record, not a description of the
 * current schema.
 *
 * Rule compliance (`/migrations`): nothing is created, so rule 1 (explicit
 * identifier names) and rule 1b (no NOT NULL with an empty-string default on
 * an existing table) have nothing to bite on. No `OCA\TeamHub\` constant or
 * class is referenced (rule 2) and no `Doctrine\DBAL` type is named (rule 2b).
 * There is no seed, so rule 2a does not apply.
 */
class Version000414004Date20261103120000 extends SimpleMigrationStep {

    /** The row table goes first: it is the one that points at the other. */
    private const DROP = [
        'teamhub_shift_sched_row',
        'teamhub_shift_schedule',
    ];

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        foreach (self::DROP as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
                $output->info('Version000414004: dropping ' . $table);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
