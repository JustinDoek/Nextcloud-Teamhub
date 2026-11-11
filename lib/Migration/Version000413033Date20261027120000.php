<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.13.33 — a saved home layout records who arranged it.
 *
 * One change, additive: `teamhub_widget_layouts.origin`, which says whether a
 * row holds an arrangement **a person made** or one **TeamHub wrote by itself**.
 *
 * ## Why a layout row needs a provenance at all
 *
 * The table has one row per (user, team) and, until this release, no way to
 * tell those two cases apart. That mattered because of what v4.0.8's auto-fit
 * pass did on first team open: it measured every widget, stripped its own
 * `autoFit` flag, and saved — unconditionally, before the member had touched
 * anything. From that moment `LayoutController::getLayout()` took the
 * saved-row branch forever, and the cascade behind it (personal default →
 * system default) was dead for that team. A member who later set a personal
 * default never saw it on a team they had already opened.
 *
 * With a provenance, a row TeamHub wrote is a cache that may be recomputed,
 * and a row a person arranged is theirs and is never overwritten.
 *
 * ## NULL is the third state, and it is the one every existing row is in
 *
 * `notnull => false` with no default, so every row that predates this release
 * reads NULL: *unclassified*. Nothing is guessed here. The controller answers
 * for a NULL row as it reads it, by comparing the stored layout against the
 * default it would otherwise have cascaded to — still standing where the
 * default puts it means the auto-fit pass wrote it and nobody has moved a card
 * since, so it reads as `auto`; anything else is a real arrangement and reads
 * as `user`. That comparison needs the application's own default layout, which
 * a migration may not read (rule 2 below), so it belongs there and not here —
 * and it is not written back either, because it happens on a GET.
 *
 * So this column is filled by the one event that settles the question for
 * good: the member arranging something, which is a write of their own.
 *
 * Classifying the other way round — defaulting existing rows to `auto` and
 * letting a later rollout overwrite them — would have been one line here and
 * would have silently discarded arrangements people made. A layout is cheap
 * for us to recompute and expensive for somebody to rebuild.
 *
 * Rule compliance (`/migrations`): a new column on an **existing** table, so
 * rule 1b applies — `notnull => false`, and genuinely nullable rather than
 * nullable-with-an-empty-string, because "nobody has classified this yet" is a
 * real third value and not an absent string. No index and no constraint, so
 * rule 1's 30-character cap has nothing to bite on. No `OCA\TeamHub\` constant
 * or class is referenced (rule 2) — the two values this column holds are
 * written as literals in the docblock above and nowhere else in this file. No
 * seed data, so rule 2a does not apply.
 */
class Version000413033Date20261027120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_widget_layouts')) {
            return null;
        }

        $table = $schema->getTable('teamhub_widget_layouts');
        if ($table->hasColumn('origin')) {
            return null;
        }

        $output->info('Version000413033: adding teamhub_widget_layouts.origin');

        // A short closed vocabulary — 'auto' or 'user' — so STRING(8) rather
        // than TEXT. Long enough for a third value should one ever be needed
        // and short enough that no engine stores it out of line.
        $table->addColumn('origin', Types::STRING, [
            'notnull' => false,
            'default' => null,
            'length'  => 8,
        ]);

        return $schema;
    }
}
