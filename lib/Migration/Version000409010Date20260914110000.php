<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.9.10 — OpenProject Phase 3: the meeting-sync ledger.
 *
 * An upcoming OpenProject meeting of the linked project is copied into the
 * team calendar, one way (Justin, 2026-09-14: "The upcoming meeting shows.
 * But it's not added to the team calendar. Can we add it there. A one way
 * sync is fine."). This table remembers what was copied where: one row
 * per (team, OpenProject connection, meeting), with the calendar and
 * object URI the copy lives at and a fingerprint of what was written, so
 * a later read knows whether to update it, leave it, or take it away
 * (`removed_at` — a cancelled or deleted meeting). The copy happens the
 * first time a connected member loads the team home after the meeting
 * exists — TeamHub holds no OpenProject token, so nothing can run in the
 * background — and two members loading at the same moment race on the
 * unique index, which the loser reads as "already copied".
 *
 * A row whose calendar object is gone but whose `removed_at` is null was
 * deleted by a member in the calendar; the sync leaves that alone.
 *
 * Identifier lengths: the table name is 23 characters; every key is named
 * explicitly (`/migrations` §1). Self-contained — no `OCA\TeamHub\`
 * constant or class is referenced (`/migrations` §2, issue #98).
 */
class Version000409010Date20260914110000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_op_meeting_sync')) {
            return null;
        }

        $table = $schema->createTable('teamhub_op_meeting_sync');
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
        $table->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        // md5 of the OpenProject host the link pointed at, 32 hex characters.
        $table->addColumn('connection', Types::STRING, ['notnull' => true, 'length' => 32]);
        // OpenProject's numeric meeting id.
        $table->addColumn('meeting_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
        // The team calendar (oc_calendars.id) the copy was written to; 0 until written.
        $table->addColumn('calendar_id', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
        // The calendar object's URI inside that calendar.
        $table->addColumn('object_uri', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
        // sha1 of what was written (title, times, location, link) — the update test.
        $table->addColumn('fingerprint', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
        // Who triggered the copy (the connected member whose read found it).
        $table->addColumn('synced_by', Types::STRING, ['notnull' => true, 'length' => 64]);
        // The meeting's start, unix seconds — to tell "gone from the window" from "gone".
        $table->addColumn('starts_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);
        $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);
        // Set when the sync itself took the copy away (cancelled or deleted in OpenProject).
        $table->addColumn('removed_at', Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);

        $table->setPrimaryKey(['id'], 'th_opms_pk');
        // Once per team and meeting — the rule and the lookup in one.
        $table->addUniqueIndex(['team_id', 'connection', 'meeting_id'], 'th_opms_uq');

        return $schema;
    }
}
