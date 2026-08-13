<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.6.13 — optional team expiration date.
 *
 * Two tables:
 *
 * **`teamhub_team_expiry`** — one row per team, and the row's *existence* is
 * the fact. A team with no expiry has no row; clearing an expiry deletes the
 * row rather than writing a sentinel. That avoids the ambiguity a nullable
 * `expires_at` would carry (is NULL "never set" or "deliberately cleared"?),
 * and it keeps `findExpiringSoon()` a straight range scan over rows that all
 * mean something. Teams predating this migration therefore start with no
 * expiry, which is the requested behaviour for existing teams.
 *
 * Not folded into `teamhub_team_type` even though that table is also one row
 * per team keyed the same way. Same argument its own migration makes for
 * staying out of `teamhub_project`: template identity and lifecycle are
 * orthogonal concerns, and a team can gain or lose an expiry many times
 * without its template ever changing. Keeping them apart also means the
 * eligibility rule (collaboration and project only, never department) reads as
 * a join between two facts rather than as a column that is sometimes
 * meaningless.
 *
 * **`teamhub_expiry_request`** — the extension queue. A team admin proposes a
 * new date with a reason; an NC admin approves (optionally granting a
 * different date than the one proposed, hence both `proposed_until` and
 * `granted_until`) or denies with a note. Rows are never deleted on decision:
 * the history of who asked for what and what was granted is exactly what the
 * audit trail is for, and the audit log stores the event while this table
 * stores the state the UI reads.
 *
 * Identifier lengths (SKILLS.md § Database identifier length): the table names
 * are 19 and 22 characters, so Doctrine's auto-generated `<table>_pkey` would
 * be 24 and 27 — inside the 30-character cap. Every key and index is named
 * explicitly anyway, because composite index names include the column list and
 * `teamhub_expiry_request_team_id_status_idx` would not have fit.
 *
 * Postgres compatibility: BIGINT for the id and every timestamp, no BOOLEAN
 * columns (DESIGN.md §2.4 — `Types::BOOLEAN` with `notnull=true` fails on
 * MySQL at insert and Postgres at bind), `status` as a short string rather
 * than an enum, and no reserved words among the column names.
 */
class Version000406013Date20260809000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        // ── teamhub_team_expiry ───────────────────────────────────────────
        if (!$schema->hasTable('teamhub_team_expiry')) {
            $expiry = $schema->createTable('teamhub_team_expiry');

            $expiry->addColumn('team_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            // Unix seconds. Stored as the instant the team expires; the UI
            // works in whole days and hands over an end-of-day timestamp, but
            // nothing here depends on that — comparisons are plain integers.
            $expiry->addColumn('expires_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $expiry->addColumn('set_by', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            $expiry->addColumn('set_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            // Null until the first extension. Kept separate from set_by/set_at
            // so "who created this expiry" survives every later extension —
            // the admin grid shows the original author and the last extender
            // as two different pieces of information.
            $expiry->addColumn('last_extended_by', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
            ]);
            $expiry->addColumn('last_extended_at', Types::BIGINT, [
                'notnull' => false,
            ]);

            // One row per team — the team id is the identity, so it is the key.
            $expiry->setPrimaryKey(['team_id'], 'th_texp_pk');
            // The warning sweep is "expires_at between now and now + N days"
            // across every team on the instance; without this it is a full scan
            // once a day forever.
            $expiry->addIndex(['expires_at'], 'th_texp_exp_idx');

            $changed = true;
        }

        // ── teamhub_expiry_request ────────────────────────────────────────
        if (!$schema->hasTable('teamhub_expiry_request')) {
            $req = $schema->createTable('teamhub_expiry_request');

            $req->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'length'        => 20,
            ]);
            $req->addColumn('team_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            $req->addColumn('requested_by', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            $req->addColumn('requested_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            // The date the requester is asking for.
            $req->addColumn('proposed_until', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            // Free text from the requester. TEXT rather than a capped STRING
            // because the admin deciding on it benefits from the whole reason,
            // and the service caps the length on the way in.
            $req->addColumn('reason', Types::TEXT, [
                'notnull' => false,
            ]);
            // 'pending' | 'approved' | 'denied' | 'superseded'.
            // 'superseded' is what happens to an open request when the expiry
            // is extended by some other route — the queue must not keep showing
            // a decision nobody needs to make any more.
            $req->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
                'default' => 'pending',
            ]);
            $req->addColumn('decided_by', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
            ]);
            $req->addColumn('decided_at', Types::BIGINT, [
                'notnull' => false,
            ]);
            // What the admin actually granted. Usually equals proposed_until;
            // differs when the admin approves for a shorter period than asked.
            // Null on deny.
            $req->addColumn('granted_until', Types::BIGINT, [
                'notnull' => false,
            ]);
            // The admin's note back to the requester — the reason a denial is
            // useful rather than just discouraging.
            $req->addColumn('decision_note', Types::TEXT, [
                'notnull' => false,
            ]);

            $req->setPrimaryKey(['id'], 'th_texr_pk');
            // Every team-side read is "the open request for this team, if any".
            $req->addIndex(['team_id', 'status'], 'th_texr_team_idx');
            // Every admin-side read is "all open requests, instance-wide".
            $req->addIndex(['status'], 'th_texr_status_idx');

            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
