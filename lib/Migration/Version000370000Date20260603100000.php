<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.70.0 — Decisions audit trail (Session J).
 *
 * One row per state transition on a decision. The set of transitions:
 *
 *   proposed   — decision row was created (open status)
 *   commented  — a comment was added on the parent message; payload carries
 *                comment_id and author so the timeline can deep-link
 *   finalized  — proposer closed discussion; payload carries selected
 *                comment_id and the wording
 *   withdrawn  — proposer (or admin override) cancelled before approval;
 *                payload carries reason
 *   approved   — approver accepted the finalized proposal
 *   denied     — approver rejected the finalized proposal; payload carries reason
 *
 * Why a dedicated table rather than oc_activity:
 *   - oc_activity is per-user (broadcast/feed semantics) and doesn't give us
 *     a clean "all events for one decision" read path
 *   - payload schema differs per transition (reason vs comment_id vs nothing)
 *   - we want this data to outlive deletion of the originating message/comment
 *
 * Table-name budget (DESIGN.md §2.5, 27-char ceiling): teamhub_dec_audit = 17 chars ✓
 */
class Version000370000Date20260603100000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_dec_audit')) {
            $table = $schema->createTable('teamhub_dec_audit');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
            ]);

            // Denormalised team_id and decision_id. team_id lets us bulk-purge
            // when a team is deleted without joining; decision_id is the
            // primary read predicate.
            $table->addColumn('team_id', Types::STRING, [
                'length'  => 64,
                'notnull' => true,
            ]);
            $table->addColumn('decision_id', Types::BIGINT, [
                'notnull' => true,
            ]);

            // 'proposed' | 'commented' | 'finalized' | 'withdrawn' | 'approved' | 'denied'
            // Stored as STRING for forward-compat with future transitions.
            $table->addColumn('transition', Types::STRING, [
                'length'  => 32,
                'notnull' => true,
            ]);

            // Acting user uid. For 'proposed' this is the proposer; for
            // 'commented' it's the comment author; for terminal transitions
            // it's the person who flipped the switch.
            $table->addColumn('actor', Types::STRING, [
                'length'  => 64,
                'notnull' => true,
            ]);

            // Event-specific JSON payload. Schema by transition:
            //   proposed   → null
            //   commented  → { comment_id: int, excerpt: string (≤200 chars) }
            //   finalized  → { comment_id: int, excerpt: string (≤200 chars) }
            //   withdrawn  → { reason: string }
            //   approved   → null
            //   denied     → { reason: string }
            $table->addColumn('payload_json', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);

            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);

            // Dominant read path — all events for one decision, oldest first.
            $table->addIndex(['decision_id', 'created_at'], 'th_dec_audit_dec_time_idx');

            // For team-bulk-purge on team deletion.
            $table->addIndex(['team_id'], 'th_dec_audit_team_idx');
        }

        return $schema;
    }
}
