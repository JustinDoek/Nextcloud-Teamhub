<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.11.18 — an approved decision can be reviewed, superseded and archived
 * (Justin, 2026-10-03).
 *
 * Three things the Decisions module could not express before:
 *
 *   1. **A review.** Circumstances change, so any team member may ask the
 *      category's approvers to look at an approved decision again. The
 *      request is its own record — the decision keeps its `approved` status
 *      and stays in force until the review is answered, and nothing about
 *      the original approval is rewritten.
 *   2. **A review period.** A category may state that its decisions need
 *      looking at every N months; 0 means never. The approvers get the task
 *      two weeks before it falls due.
 *   3. **An archive.** A decision that has been superseded or that a
 *      reviewer retires moves into the team's Archive category, keeps the
 *      name of the category it was decided under, and becomes read-only.
 *
 *   teamhub_dec_reviews                  — one row per review cycle.
 *   teamhub_decisions.archived_*         — when, by whom, and from where.
 *   teamhub_decisions.review_due_at      — when this decision next needs a
 *                                          review; null means never.
 *   teamhub_decisions.review_notified_at — the cycle the notice was written
 *                                          for, so the daily pass writes it
 *                                          once and not every morning.
 *   teamhub_dec_categories.review_period_months
 *                                        — 0 | N months.
 *   teamhub_dec_categories.is_archive    — 0 | 1. The one category per team
 *                                          that holds retired decisions.
 *
 * **Why a table rather than columns on the decision.** A decision can be
 * reviewed many times over its life, and the motivation behind the third
 * review is not a correction of the second — it is a separate fact with its
 * own author and its own answer. Columns would hold only the most recent one
 * and the earlier motivations would survive nowhere but the audit payload,
 * which is not a place a query can reach.
 *
 * **Why the archive is a category and not a status alone.** Both: `status`
 * gains `archived` and `superseded` (application-layer values, see
 * `Decision`'s docblock), and the row's `category` moves to the team's
 * Archive. The status is what makes the row read-only; the category is what
 * makes it findable, because a member looking for a retired decision looks
 * in a place, not in a filter. `archived_from_category` keeps the original
 * name so the record still answers "what did Marketing decide".
 *
 * SMALLINT for both new flags/periods, like every other flag on these
 * tables: Postgres and MariaDB disagree about comparing a boolean column
 * against 1, and a smallint compares the same on both. `notnull => true`
 * with a `0` default is safe on an existing table — what Nextcloud refuses
 * (`MigrationService::ensureOracleConstraints`) is an empty-string or null
 * default, because on Oracle those are the same value (migrations skill,
 * rule 1b). Every added column on an existing table that needs no value is
 * nullable for the same reason.
 *
 * Every key is named explicitly (rule 1) and no application constant is
 * referenced (rule 2) — the values are inlined and their meaning lives in
 * the entity docblocks.
 *
 * **Schema only, and deliberately so.** The two seeded categories (General
 * and Archive) are rows every instance must have, and a fresh install never
 * runs a migration's post step (rule 2a) — so they are seeded by the
 * `SeedDecisionCategories` repair step, registered under both `<install>`
 * and `<post-migration>`. Nothing here back-fills `review_due_at` either:
 * no category has a review period until a team admin sets one, and setting
 * one is what fills the column for that category's decisions.
 */
class Version000411018Date20261003000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        // ── teamhub_dec_reviews ──────────────────────────────────────────
        // 19 chars, so the auto-generated `_pkey` would fit; named anyway,
        // because every key in this file is.
        if (!$schema->hasTable('teamhub_dec_reviews')) {
            $t = $schema->createTable('teamhub_dec_reviews');

            $t->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'length'        => 8,
            ]);
            // teamhub_decisions.id. A soft link: deleting a decision leaves
            // a row that resolves to nothing and the reader drops it, the
            // same contract every other pointer in this app has.
            $t->addColumn('decision_id', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
            ]);
            // circles_circle.unique_id — the team whose decision this is.
            // Stored so "the open reviews in these teams", which is what My
            // Work asks for every viewer, does not have to join.
            $t->addColumn('team_id', Types::STRING, [
                'length'  => 64,
                'notnull' => true,
                'default' => '',
            ]);
            // 'requested' — a team member asked for it.
            // 'periodic'  — the category's review period fell due.
            // Two kinds rather than a boolean: the rows read differently to
            // the approver (one carries a person's motivation, the other
            // carries a date) and a reader should not have to invert a flag
            // to find out which.
            $t->addColumn('kind', Types::STRING, [
                'length'  => 16,
                'notnull' => true,
                'default' => '',
            ]);
            // Who asked. Empty for a periodic review: nobody asked, the
            // calendar did, and naming the system as a user would be a lie
            // the audit trail then repeats.
            $t->addColumn('requested_by', Types::STRING, [
                'length'  => 64,
                'notnull' => true,
                'default' => '',
            ]);
            // The member's reasons, in their own words. TEXT because this is
            // the one field of the feature a person writes prose into, and
            // the application caps it at 1000 characters.
            $t->addColumn('motivation', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            // 'open' | 'completed'.
            $t->addColumn('status', Types::STRING, [
                'length'  => 16,
                'notnull' => true,
                'default' => '',
            ]);
            // 'kept' | 'archived', set together with completed_by and
            // completed_at. Null while the review is open.
            $t->addColumn('outcome', Types::STRING, [
                'length'  => 16,
                'notnull' => false,
                'default' => null,
            ]);
            $t->addColumn('completed_by', Types::STRING, [
                'length'  => 64,
                'notnull' => false,
                'default' => null,
            ]);
            $t->addColumn('completed_at', Types::BIGINT, [
                'notnull' => false,
                'length'  => 8,
                'default' => null,
            ]);
            $t->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $t->setPrimaryKey(['id'], 'th_decrev_pk');
            // "Is there an open review on this decision" — asked before
            // every request and on every render of a decision's panel.
            $t->addIndex(['decision_id', 'status'], 'th_decrev_dec_idx');
            // "The open reviews in these teams" — My Work, per viewer.
            $t->addIndex(['team_id', 'status'], 'th_decrev_team_idx');

            $changed = true;
            $output->info('teamhub_dec_reviews: created');
        }

        // ── teamhub_decisions ────────────────────────────────────────────
        if ($schema->hasTable('teamhub_decisions')) {
            $table = $schema->getTable('teamhub_decisions');

            if (!$table->hasColumn('archived_at')) {
                $table->addColumn('archived_at', Types::BIGINT, [
                    'notnull' => false,
                    'length'  => 8,
                    'default' => null,
                ]);
                $changed = true;
                $output->info('teamhub_decisions: added archived_at');
            }

            if (!$table->hasColumn('archived_by')) {
                $table->addColumn('archived_by', Types::STRING, [
                    'length'  => 64,
                    'notnull' => false,
                    'default' => null,
                ]);
                $changed = true;
                $output->info('teamhub_decisions: added archived_by');
            }

            // The name of the category the decision was decided under, kept
            // when the row moves into Archive. A name and not an id, because
            // `category` itself is a name on this table and the two have to
            // compare.
            if (!$table->hasColumn('archived_from_category')) {
                $table->addColumn('archived_from_category', Types::STRING, [
                    'length'  => 128,
                    'notnull' => false,
                    'default' => null,
                ]);
                $changed = true;
                $output->info('teamhub_decisions: added archived_from_category');
            }

            // Null means no review is due — either the category has no
            // review period or the decision never reached `approved`. Null
            // rather than 0 so "never" cannot be read as "1970".
            if (!$table->hasColumn('review_due_at')) {
                $table->addColumn('review_due_at', Types::BIGINT, [
                    'notnull' => false,
                    'length'  => 8,
                    'default' => null,
                ]);
                $changed = true;
                $output->info('teamhub_decisions: added review_due_at');
            }

            // The `review_due_at` the notice was written for, not the moment
            // it was written. Storing the cycle rather than the timestamp is
            // what makes the daily pass idempotent across a reset: once the
            // due date moves, the stored value no longer matches and the next
            // cycle gets its own notice.
            if (!$table->hasColumn('review_notified_at')) {
                $table->addColumn('review_notified_at', Types::BIGINT, [
                    'notnull' => false,
                    'length'  => 8,
                    'default' => null,
                ]);
                $changed = true;
                $output->info('teamhub_decisions: added review_notified_at');
            }

            // The daily pass asks one question of the whole table: which
            // approved decisions fall due inside the notice window. Status
            // leads because it is the selective half — an instance's
            // decisions are mostly not approved — and because the same index
            // serves the per-team status filter the list view already runs.
            if (!$table->hasIndex('th_dec_rev_due_idx')) {
                $table->addIndex(['status', 'review_due_at'], 'th_dec_rev_due_idx');
                $changed = true;
                $output->info('teamhub_decisions: added th_dec_rev_due_idx');
            }
        }

        // ── teamhub_dec_categories ───────────────────────────────────────
        if ($schema->hasTable('teamhub_dec_categories')) {
            $table = $schema->getTable('teamhub_dec_categories');

            // Months. 0 = decisions in this category need no review, which
            // is the true statement about every category written before this
            // version — none of them asked for one.
            if (!$table->hasColumn('review_period_months')) {
                $table->addColumn('review_period_months', Types::SMALLINT, [
                    'notnull' => true,
                    'default' => 0,
                ]);
                $changed = true;
                $output->info('teamhub_dec_categories: added review_period_months');
            }

            // 0 | 1. The team's one archive category: no approvers, review
            // period fixed at 0, and neither renamed nor deleted. Existing
            // categories are all 0 — an archive is created, never found.
            if (!$table->hasColumn('is_archive')) {
                $table->addColumn('is_archive', Types::SMALLINT, [
                    'notnull' => true,
                    'default' => 0,
                ]);
                $changed = true;
                $output->info('teamhub_dec_categories: added is_archive');
            }
        }

        return $changed ? $schema : null;
    }
}
