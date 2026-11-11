<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.13.17 — the audit trail becomes tamper-evident, membership
 * recertification gets its record, and the integrations register gets nothing
 * new (it reads tables that already exist).
 *
 * **The file is named 000413016 and the work ships as 4.13.17.** It was written
 * while 4.13.16 was the open version; by the time it shipped, 4.13.16 had already
 * been deployed with the Teams-frontend switch alone, so the rest needed a bump to
 * make `occ upgrade` run this step at all. The name is the recorded migration id and
 * renaming it after the fact is how an instance runs a step twice, so it stays.
 *
 * Three changes, all additive:
 *
 *   teamhub_audit_log.row_hash   a keyed digest of this row's own content,
 *                                written at insert. NULL on every row written
 *                                before this version — those rows can never be
 *                                verified, and the integrity report says so
 *                                rather than implying they are sound.
 *
 *   teamhub_member_review        one append-only row per completed membership
 *                                recertification — the ISO 27001 A.5.18
 *                                evidence (DESIGN §2.193). See
 *                                `MemberReviewMapper` for why this is a table
 *                                and not the workflow ledger.
 *
 *   teamhub_audit_anchor         periodic anchors over ranges of row ids. Each
 *                                anchor hashes the row hashes in its range
 *                                together with the previous anchor's hash, so
 *                                the anchors form the chain and the rows do
 *                                not have to.
 *
 * **Why the chain is in a second table rather than a prev_hash column.** A
 * prev_hash written at insert has to read the current tip first, and two
 * concurrent inserts then read the same tip and write the same prev_hash. The
 * chain forks, and a verifier walking it reports a break that nobody caused —
 * on a busy instance, constantly. Hashing a row against itself needs no tip, so
 * inserts stay independent and unordered; the hourly anchor pass is the only
 * thing that needs to see rows in order, and it is single-threaded by virtue of
 * being a background job.
 *
 *   from_id / to_id  inclusive id range this anchor covers
 *   row_count        how many rows were actually found in it, which is what
 *                    makes a deletion visible: the range is fixed, the count
 *                    is not
 *   rows_hash        digest over the range's row hashes, in id order
 *   chain_hash       digest over the previous anchor's chain_hash + rows_hash
 *   kind             'segment' for a normal pass, 'purge' for the record the
 *                    retention job leaves when it removes a range on purpose
 *   key_fingerprint  which signing key produced this anchor, so a rotated
 *                    config.php secret reads as "cannot verify from here"
 *                    rather than as tampering
 *   created_at       epoch seconds
 *
 * Rule compliance (`/migrations`): `row_hash` is a new column on an **existing**
 * table, so rule 1b applies and it is `notnull => false` — it is genuinely
 * nullable, which is the honest shape for rows that predate it. The new table
 * is 20 characters (`teamhub_audit_anchor_pkey` = 25, under the 30 cap) and
 * every key is named explicitly anyway, per rule 1. No `OCA\TeamHub\` constant
 * or class is referenced (rule 2). No seed data (rule 2a) — the first anchor is
 * written by the hourly job, and an instance with no anchors yet reports
 * "not anchored", which is true.
 */
class Version000413016Date20261021120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('teamhub_audit_log')) {
            $table = $schema->getTable('teamhub_audit_log');
            if (!$table->hasColumn('row_hash')) {
                $output->info('Version000413016: adding teamhub_audit_log.row_hash');
                // 64 hex characters of SHA-256. Nullable: see rule 1b, and see
                // the docblock for why that is also the truthful shape here.
                $table->addColumn('row_hash', Types::STRING, [
                    'notnull' => false,
                    'length'  => 64,
                    'default' => null,
                ]);
                $changed = true;
            }
        }

        if (!$schema->hasTable('teamhub_audit_anchor')) {
            $output->info('Version000413016: creating teamhub_audit_anchor');

            $table = $schema->createTable('teamhub_audit_anchor');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
                'length'        => 8,
            ]);

            $table->addColumn('from_id', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
            ]);

            $table->addColumn('to_id', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
            ]);

            $table->addColumn('row_count', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->addColumn('rows_hash', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            $table->addColumn('chain_hash', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            // 'segment' | 'purge'
            $table->addColumn('kind', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
                'default' => 'segment',
            ]);

            $table->addColumn('key_fingerprint', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
                'default' => '',
            ]);

            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
            ]);

            $table->setPrimaryKey(['id'], 'th_audit_anchor_pk');
            // The verifier walks anchors in id order; the anchor pass asks for
            // the last one. Both are served by the primary key. This index is
            // for the range lookup the report does when it points at a break.
            $table->addIndex(['to_id'], 'th_audit_anchor_to');

            $output->info('Version000413016: created teamhub_audit_anchor');
            $changed = true;
        }

        if (!$schema->hasTable('teamhub_member_review')) {
            $output->info('Version000413016: creating teamhub_member_review');

            // One row per completed membership recertification. Append-only;
            // the next due date is stored rather than recomputed so that
            // shortening a policy profile's review period does not silently
            // rewrite when past reviews were due.
            $table = $schema->createTable('teamhub_member_review');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
                'length'        => 8,
            ]);

            $table->addColumn('team_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            // The administrator who attested. Never empty: a review with no
            // actor is not evidence of anything.
            $table->addColumn('reviewed_by', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            $table->addColumn('reviewed_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
            ]);

            // How many people the roster held at the moment it was attested,
            // and how many had been removed since the previous review. Both are
            // what makes a review readable years later without the snapshot.
            $table->addColumn('member_count', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->addColumn('removed_count', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->addColumn('next_due_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->addColumn('note', Types::STRING, [
                'notnull' => true,
                'length'  => 500,
                'default' => '',
            ]);

            // The roster as attested, as JSON. Nullable: an instance may
            // decide the counts are enough, and an old row may predate it.
            $table->addColumn('snapshot', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);

            $table->setPrimaryKey(['id'], 'th_mreview_pk');
            // The two reads: one team's latest/history, and the daily pass
            // asking many teams at once.
            $table->addIndex(['team_id', 'reviewed_at'], 'th_mreview_team_time');

            $output->info('Version000413016: created teamhub_member_review');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
