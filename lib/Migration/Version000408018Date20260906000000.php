<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.8.18 — file review requests (FILE-REVIEW-PLAN.md §2).
 *
 * Two tables. A review is one request against one file; a reviewer row is one
 * person's obligation within it.
 *
 * ## Why `completed_at` rather than a `completed` flag
 *
 * The obvious shape is a boolean plus a timestamp, and it is the wrong one
 * twice over. First, the requester has to be shown **when** each reviewer
 * finished, so the timestamp is not optional — a boolean beside it is a second
 * copy of the same fact that can disagree with it. Second, `Types::BOOLEAN`
 * with `notnull => true` fails on MySQL at insert and on Postgres at bind
 * (HANDOFF, "Facts that are not derivable from this repo"), which is why
 * `teamhub_msg_subscription` uses SMALLINT. A nullable timestamp sidesteps
 * both: null means "still owed", and any value means "done, at this moment".
 *
 * ## Why `file_id` and `file_name` both
 *
 * `file_id` is the identity — it survives a rename and a move, which a stored
 * path would not, and a review that loses track of its file the moment somebody
 * tidies a folder would be worse than no review. `file_name` is a display
 * snapshot for the one case `file_id` cannot answer: the file has been deleted,
 * the node no longer resolves, and the row still has to render a title. It is
 * never used to find anything.
 *
 * ## Rows outlive the review
 *
 * Closing a review does not delete its reviewer rows, including the rows of
 * people who never completed. The requester must be able to see who did not
 * respond, and that is the only record of it. What closing changes is
 * visibility, and that lives in FileReviewWorkProvider — see its docblock for
 * why the un-completed reviewer's item disappears rather than moving to
 * "Completed".
 *
 * Identifier lengths: both table names are under the 24-character guidance, so
 * the auto-generated `_pkey` would fit — but every key is named explicitly
 * anyway, per SKILLS.md § Database identifier length, because composite indexes
 * are exactly where auto-naming overflows.
 */
class Version000408018Date20260906000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('teamhub_file_review')) {
            $table = $schema->createTable('teamhub_file_review');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $table->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $table->addColumn('file_name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('requested_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('message', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('due_at', Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);
            $table->addColumn('talk_token', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $table->addColumn('closed_at', Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);
            $table->addColumn('closed_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);

            $table->setPrimaryKey(['id'], 'th_frev_pk');

            // The team's open reviews, for the team listing. Status second
            // because every read of this index narrows by team first.
            $table->addIndex(['team_id', 'status'], 'th_frev_team_idx');
            // "Is a review already open on this file" — the modal's warning,
            // asked once per Request review click.
            $table->addIndex(['file_id'], 'th_frev_file_idx');
            // The requester's own rows, which is how My Work assembles the
            // "waiting for others" and "ready to close" halves.
            $table->addIndex(['requested_by', 'status'], 'th_frev_req_idx');

            $output->info('Created teamhub_file_review table');
            $changed = true;
        }

        if (!$schema->hasTable('teamhub_file_reviewer')) {
            $table = $schema->createTable('teamhub_file_reviewer');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $table->addColumn('review_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('completed_at', Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);
            $table->addColumn('remark', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);

            $table->setPrimaryKey(['id'], 'th_frevu_pk');

            // Both the uniqueness rule (one obligation per person per review)
            // and the index that loads a review's roster.
            $table->addUniqueIndex(['review_id', 'user_id'], 'th_frevu_uq');
            // The other direction: "which reviews do I owe". user_id is
            // leftmost because that is the only column the scan narrows by —
            // completed_at rides along so the common case (null) is answered
            // from the index.
            $table->addIndex(['user_id', 'completed_at'], 'th_frevu_user_idx');

            $output->info('Created teamhub_file_reviewer table');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
