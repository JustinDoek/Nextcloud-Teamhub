<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.5.42 — decision proposals gain a discussion phase.
 *
 * Until now a proposal had exactly two shapes: created from the message stream
 * and left `open` with no way to edit it, or created from the compose modal and
 * finalized in the same request. This adds the state the middle needs.
 *
 * **`share_mode`** — how the proposer chose to open the proposal:
 *   - `immediate` — finalized on creation. The pre-4.5.42 compose-modal path,
 *     and the backfill value for every existing row, because a row written
 *     before this migration had no discussion phase to record.
 *   - `selected`  — open, discussed in a Talk group conversation with a named
 *     set of people. Only those people (and the proposer) may see it while it
 *     is open — see `teamhub_decision_audience`.
 *   - `team`      — open, discussed in a thread in the team conversation.
 *     Visible to the whole team, which is what an open decision has always
 *     been, so this needs no audience rows.
 *
 * **`talk_token` / `talk_thread_id`** — where the discussion lives. Nullable
 * because `immediate` proposals have no discussion, and because Talk sharing
 * is best-effort: a proposal whose Talk post failed is still a valid proposal,
 * it just has nowhere to point at. Storing the token rather than the room id
 * matches every other Talk reference in the codebase (`TalkService` resolves
 * ids from tokens, never the reverse).
 *
 * **`teamhub_decision_audience`** — who may see a `selected` proposal while it
 * is open. Deliberately its own table rather than a JSON column on
 * `teamhub_decisions`: the feed has to filter on it per request, and a JSON
 * column cannot be indexed for that on both MySQL and Postgres. `decisions.
 * participants` is JSON precisely because nothing filters on it.
 *
 * Identifier lengths (SKILLS.md § Database identifier length): the table name
 * is 25 characters, so Doctrine's auto-generated `teamhub_decision_audience_pkey`
 * would be 30 — at the cap with no headroom. Every constraint and index is
 * therefore named explicitly.
 *
 * No data step: `ADD COLUMN … NOT NULL DEFAULT 'immediate'` fills existing
 * rows with the default on both MySQL/MariaDB and Postgres, which is exactly
 * the value those rows should carry.
 *
 * Postgres compatibility: BIGINT for ids and timestamps, no BOOLEAN columns
 * (DESIGN.md §2.4), every index and constraint explicitly named, and no
 * reserved words among the column names.
 */
class Version000405042Date20260804100000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        // ── teamhub_decisions: share mode + Talk linkage ──────────────────
        if ($schema->hasTable('teamhub_decisions')) {
            $table = $schema->getTable('teamhub_decisions');

            // Default 'immediate' rather than notnull-with-no-default: an
            // existing row genuinely had no discussion phase, so 'immediate'
            // is the true statement about it, not a placeholder.
            if (!$table->hasColumn('share_mode')) {
                $table->addColumn('share_mode', Types::STRING, [
                    'notnull' => true,
                    'length'  => 16,
                    'default' => 'immediate',
                ]);
                $changed = true;
                $output->info('teamhub_decisions: added share_mode');
            }

            // Talk tokens are 32 chars today; 64 leaves room without costing
            // anything, and matches teamhub_team_app_resources.resource_id
            // which stores the same kind of value.
            if (!$table->hasColumn('talk_token')) {
                $table->addColumn('talk_token', Types::STRING, [
                    'notnull' => false,
                    'length'  => 64,
                    'default' => null,
                ]);
                $changed = true;
                $output->info('teamhub_decisions: added talk_token');
            }

            if (!$table->hasColumn('talk_thread_id')) {
                $table->addColumn('talk_thread_id', Types::BIGINT, [
                    'notnull' => false,
                    'default' => null,
                ]);
                $changed = true;
                $output->info('teamhub_decisions: added talk_thread_id');
            }

            // The feed asks "open proposals in my teams, narrowed by mode" on
            // every What's New load. status alone is not selective enough once
            // a team accumulates records.
            if (!$table->hasIndex('th_dec_status_mode_idx')) {
                $table->addIndex(['status', 'share_mode'], 'th_dec_status_mode_idx');
                $changed = true;
                $output->info('teamhub_decisions: added th_dec_status_mode_idx');
            }
        }

        // ── teamhub_decision_audience ─────────────────────────────────────
        if (!$schema->hasTable('teamhub_decision_audience')) {
            $audience = $schema->createTable('teamhub_decision_audience');

            $audience->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'length'        => 20,
            ]);
            $audience->addColumn('decision_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            // NC user ids are capped at 64 characters.
            $audience->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            $audience->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            $audience->setPrimaryKey(['id'], 'th_dau_pk');
            // One row per person per decision. Also the index the membership
            // test uses when the detail endpoint asks "may this user see it".
            $audience->addUniqueIndex(['decision_id', 'user_id'], 'th_dau_uq');
            // The feed asks the other way round: "which decisions may this
            // user see", once per request.
            $audience->addIndex(['user_id'], 'th_dau_user_idx');

            $changed = true;
            $output->info('teamhub_decision_audience: created');
        }

        return $changed ? $schema : null;
    }
}
