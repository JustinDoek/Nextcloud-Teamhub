<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.10.20 - WorkflowHub phase 5: Service Teams.
 *
 *   teamhub_service_team    - one row per service team (owner, source group, defaults)
 *   teamhub_service_agent   - the agents of a service team
 *   teamhub_service_catalog - which services a service team offers, and the
 *                             workflow definition behind each
 *
 * Plus two columns on the phase-1 workflow tables:
 *
 *   teamhub_wf_step.assignee    - the agent who claimed this step, '' while
 *                                 it is in the queue. A step whose actor is
 *                                 a service team is held by every eligible
 *                                 agent; claiming narrows it to one person
 *                                 without changing who the actor is.
 *   teamhub_wf_event.visibility - 'all' or 'internal'. An internal event is
 *                                 the service team's own note about the
 *                                 request and is never shown to the
 *                                 requester (the read filters it out).
 *
 * Schema only: nothing seeds these tables. A service team exists because an
 * administrator went through the setup flow, never because an upgrade
 * created one (product rule: do not create the Service Team silently), so a
 * fresh install and an upgrade end in the same state - three empty tables
 * and two new columns. No post step, no repair step.
 *
 * Every key is named explicitly (see the migrations skill, rule 1); no
 * column is named `at`, `type`, `position`, `state` or `group`, which are
 * keywords on one database or the other; every timestamp is BIGINT Unix
 * seconds; no BOOLEAN. Status and behaviour values are strings the
 * application defines - inlined here as defaults only, never as constants.
 *
 * "One service team row per team" is a unique index; "one catalogue entry per
 * service per team" is another. Agent uniqueness is an index too - an agent
 * added twice would give a queue row two claim buttons.
 */
class Version000410020Date20260922000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('teamhub_service_team')) {
            $t = $schema->createTable('teamhub_service_team');
            $t->addColumn('id',           Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            // circles_circle.unique_id of the team that *is* the service team.
            $t->addColumn('team_id',      Types::STRING, ['notnull' => true,  'length' => 64]);
            // The one account answerable for the service. Always eligible.
            $t->addColumn('owner_uid',    Types::STRING, ['notnull' => true,  'length' => 64]);
            // Optional Nextcloud group whose members are agents as well; '' for none.
            $t->addColumn('source_group', Types::STRING, ['notnull' => true,  'length' => 64, 'default' => '']);
            // unassigned | owner - what happens to a new request's handling step.
            $t->addColumn('assign_mode',  Types::STRING, ['notnull' => true,  'length' => 16, 'default' => 'unassigned']);
            // 0 = the setup flow has not been completed; the team is not live.
            $t->addColumn('active',       Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $t->addColumn('created_at',   Types::BIGINT, ['notnull' => true,  'length' => 8, 'default' => 0]);
            $t->addColumn('created_by',   Types::STRING, ['notnull' => true,  'length' => 64, 'default' => '']);
            $t->addColumn('updated_at',   Types::BIGINT, ['notnull' => true,  'length' => 8, 'default' => 0]);

            $t->setPrimaryKey(['id'], 'th_svt_pk');
            $t->addUniqueIndex(['team_id'], 'th_svt_team_uq');
            $t->addIndex(['owner_uid'], 'th_svt_owner_idx');
            $t->addIndex(['active'], 'th_svt_active_idx');
            $changed = true;
        }

        if (!$schema->hasTable('teamhub_service_agent')) {
            $t = $schema->createTable('teamhub_service_agent');
            $t->addColumn('id',         Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $t->addColumn('team_id',    Types::STRING, ['notnull' => true,  'length' => 64]);
            $t->addColumn('agent_uid',  Types::STRING, ['notnull' => true,  'length' => 64]);
            $t->addColumn('added_at',   Types::BIGINT, ['notnull' => true,  'length' => 8, 'default' => 0]);
            $t->addColumn('added_by',   Types::STRING, ['notnull' => true,  'length' => 64, 'default' => '']);

            $t->setPrimaryKey(['id'], 'th_sva_pk');
            $t->addUniqueIndex(['team_id', 'agent_uid'], 'th_sva_member_uq');
            // "Which service teams is this person an agent of" - the nav gate.
            $t->addIndex(['agent_uid'], 'th_sva_agent_idx');
            $changed = true;
        }

        if (!$schema->hasTable('teamhub_service_catalog')) {
            $t = $schema->createTable('teamhub_service_catalog');
            $t->addColumn('id',             Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $t->addColumn('team_id',        Types::STRING, ['notnull' => true,  'length' => 64]);
            // The service key the application defines (new_team, shared_folder, ...).
            $t->addColumn('service_key',    Types::STRING, ['notnull' => true,  'length' => 64]);
            // The workflow definition a request for this service opens.
            $t->addColumn('definition_key', Types::STRING, ['notnull' => true,  'length' => 64]);
            $t->addColumn('enabled',        Types::SMALLINT, ['notnull' => true, 'default' => 1]);
            $t->addColumn('sort_order',     Types::SMALLINT, ['notnull' => true, 'default' => 1]);
            $t->addColumn('updated_at',     Types::BIGINT, ['notnull' => true,  'length' => 8, 'default' => 0]);

            $t->setPrimaryKey(['id'], 'th_svc_pk');
            $t->addUniqueIndex(['team_id', 'service_key'], 'th_svc_entry_uq');
            // "Which service team offers this definition" - resolved when a request opens.
            $t->addIndex(['definition_key', 'enabled'], 'th_svc_def_idx');
            $changed = true;
        }

        if ($schema->hasTable('teamhub_wf_step')) {
            $t = $schema->getTable('teamhub_wf_step');
            if (!$t->hasColumn('assignee')) {
                // NULLABLE, and that is not an oversight. Nextcloud refuses a
                // NOT NULL column with an empty-string default when it is
                // added to a table that already exists
                // (MigrationService::ensureOracleConstraints) - on Oracle,
                // '' and NULL are the same value, so the combination is a
                // contradiction. The check fires only for a new column on an
                // existing table, which is exactly this case; a new table's
                // columns are exempt, which is why every other column in
                // this app declares notnull with a '' default and installs
                // fine.
                //
                // The default is still '' so every existing row gets the
                // "unclaimed" value the application means, and nothing in
                // the app ever writes NULL. WorkflowStep::getAssignee()
                // normalises NULL to '' anyway, so a row that somehow held
                // one would read as unclaimed rather than as a third state.
                $t->addColumn('assignee', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => '']);
                // "What has this agent claimed" - the agent's own half of the queue.
                $t->addIndex(['assignee', 'step_status'], 'th_wfs_assign_idx');
                $changed = true;
            }
        }

        if ($schema->hasTable('teamhub_wf_event')) {
            $t = $schema->getTable('teamhub_wf_event');
            if (!$t->hasColumn('visibility')) {
                // all | internal
                $t->addColumn('visibility', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'all']);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
