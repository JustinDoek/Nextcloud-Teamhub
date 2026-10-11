<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.11.30 — a review request carries the wording it is asking for, and a
 * file (Justin, 2026-10-05).
 *
 * **The gap.** A proposal has a text and an attachment, and the attachment
 * is commonly where the decision's substance actually lives — a contract, a
 * policy PDF, a signed quotation. A review request carried one field: the
 * requester's motivation. So the one person who knows what the decision
 * should now say could only describe it in prose, and the approver, who had
 * to write the new wording themselves, could not replace the attachment at
 * all. Justin: *the review requester should give the correct wording and if
 * needed attachment; the approver only approves or denies with a reason, or
 * is able to edit the final wording — but not the attachment.*
 *
 *   teamhub_dec_reviews.proposed_question   — the question the requester
 *                                             asks the decision to carry.
 *   teamhub_dec_reviews.proposed_answer     — the wording they ask it to
 *                                             carry. Both null on a review
 *                                             that only asks for a look
 *                                             (the 4.11.18 shape) and on
 *                                             every periodic review, where
 *                                             nobody asked anything.
 *   teamhub_dec_reviews.attachment_file_id  — the file the requester puts
 *                                             forward, already copied into
 *                                             `.proposals/{decisionId}/` so
 *                                             that every member of the team
 *                                             — the approvers included —
 *                                             can read it while the review
 *                                             runs. **The id of the copy**,
 *                                             not of the requester's
 *                                             original: the original may sit
 *                                             in their private files, which
 *                                             is exactly why the approver
 *                                             could not review it before.
 *   teamhub_dec_reviews.attachment_name     — the name the requester knows
 *                                             the file by. The copy carries
 *                                             a `review-{id}-` prefix so a
 *                                             regeneration of the proposal
 *                                             folder cannot overwrite it,
 *                                             and that prefix must not be
 *                                             what the panel shows.
 *
 * **Why columns and not a table.** One review asks for one wording and puts
 * forward at most one file: it is a single proposal, not a collection, and
 * the second ask is a second review row — which is what the table in 4.11.18
 * exists for. The reasoning that made the *reviews* a table ("the motivation
 * behind the third review is not a correction of the second") says these
 * belong on the row, because they are that one review's own proposal.
 *
 * **Every added column is nullable** (migrations skill, rule 1b): these go
 * onto a table that already exists on every instance that has run 4.11.18,
 * and `notnull => true` with an empty default is refused outright by
 * `MigrationService::ensureOracleConstraints()` — it aborts `occ upgrade`
 * for the whole instance and leaves maintenance mode on. `attachment_name`
 * normalises to `''` in the entity, because it pairs with an id and `''`
 * is the only thing "no name" can mean; the two wording columns stay
 * genuinely nullable, because null there means *nothing was proposed* and
 * that is not the same fact as an empty string. `hasProposedWording()` is
 * the one place that question is answered, so no reader has to test both.
 *
 * TEXT for both wording columns, matching `teamhub_decisions.question` and
 * `.selected_answer`, which is what they are proposals for; the application
 * caps both at 4000 characters.
 *
 * No index. Neither column is ever a search term — a review is always
 * reached by `decision_id` or by `id`, and both are already indexed.
 *
 * No application constant is referenced (rule 2) and nothing is seeded
 * (rule 2a): a review written before this version has no proposal in it,
 * which is the true statement about it.
 */
class Version000411030Date20261005120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('teamhub_dec_reviews')) {
            // 4.11.18 has not run yet on this instance. It creates the table
            // and this step runs after it, so there is nothing to reach —
            // and a fresh install folds both into one schema anyway.
            return null;
        }

        $table = $schema->getTable('teamhub_dec_reviews');

        if (!$table->hasColumn('proposed_question')) {
            $table->addColumn('proposed_question', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            $changed = true;
            $output->info('teamhub_dec_reviews: added proposed_question');
        }

        if (!$table->hasColumn('proposed_answer')) {
            $table->addColumn('proposed_answer', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            $changed = true;
            $output->info('teamhub_dec_reviews: added proposed_answer');
        }

        if (!$table->hasColumn('attachment_file_id')) {
            $table->addColumn('attachment_file_id', Types::BIGINT, [
                'notnull' => false,
                'length'  => 8,
                'default' => null,
            ]);
            $changed = true;
            $output->info('teamhub_dec_reviews: added attachment_file_id');
        }

        if (!$table->hasColumn('attachment_name')) {
            $table->addColumn('attachment_name', Types::STRING, [
                'length'  => 255,
                'notnull' => false,
                'default' => null,
            ]);
            $changed = true;
            $output->info('teamhub_dec_reviews: added attachment_name');
        }

        return $changed ? $schema : null;
    }
}
