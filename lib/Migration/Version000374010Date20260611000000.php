<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.74.10 — create teamhub_dec_meetings table for approver-meeting
 * relations on a proposal.
 *
 * Table name: teamhub_dec_meetings (20 chars, within the ≤27 budget).
 *
 * One row per scheduled approver meeting. A decision may have several rows
 * if approvers scheduled multiple follow-up meetings to discuss the same
 * proposal. The event_uid links back to the iCalendar VEVENT created via
 * the suggest-meeting wizard.
 */
class Version000374010Date20260611000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_dec_meetings')) {
            return null; // idempotent
        }

        $table = $schema->createTable('teamhub_dec_meetings');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull'       => true,
            'unsigned'      => true,
        ]);
        $table->addColumn('team_id', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
        ]);
        $table->addColumn('decision_id', Types::BIGINT, [
            'notnull'  => true,
            'unsigned' => true,
        ]);
        // iCalendar VEVENT UID. Set when the meeting is scheduled via the
        // wizard; stays empty (length 0) is not permitted by the schema so
        // we use length 255 and require a value at insert time.
        $table->addColumn('event_uid', Types::STRING, [
            'notnull' => true,
            'length'  => 255,
        ]);
        // Title and start time captured at creation so the Scheduled Meetings
        // section on the proposal can render without a CalDAV lookup per row.
        // Title is denormalised — the source of truth remains the calendar event.
        $table->addColumn('meeting_title', Types::STRING, [
            'notnull' => true,
            'length'  => 255,
            'default' => '',
        ]);
        $table->addColumn('meeting_start', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('scheduled_by', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
        ]);
        $table->addColumn('created_at', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['decision_id'], 'dec_meet_decision_idx');
        $table->addIndex(['team_id'],     'dec_meet_team_idx');
        $table->addIndex(['event_uid'],   'dec_meet_event_idx');

        return $schema;
    }
}
