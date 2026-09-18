<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.9.6 — OpenProject Phase 2: blueprint-driven provisioning.
 *
 * Four changes, each guarded so the step is safe to re-run on a machine that
 * ran an earlier draft:
 *
 *   1. **`teamhub_template.blueprint_json`** — the declarative blueprint of a
 *      template: what the row's `apps` and `modules` columns cannot say —
 *      the dashboard widgets, the OpenProject requirement, modes and approved
 *      templates, what is copied from such a template, the role mapping, the
 *      project-folder coordination, and the governance and lifecycle keys
 *      reserved for Phase 4. Applications and modules stay the row's, and are
 *      re-derived from it on every read. Nullable: a template without one is
 *      read exactly as before, so the three older templates keep working
 *      untouched.
 *
 *   2. **`teamhub_provisioning`** — one row per provisioning operation: who
 *      asked for what, which team it produced (null until the team exists),
 *      where it is, and the lease that stops two runners executing the same
 *      operation at once. `request_json` is the sanitised wizard payload
 *      (names, ids, choices — never a token, never a password; the
 *      provisioning service strips anything else before it is stored).
 *
 *   3. **`teamhub_provisioning_step`** — one row per step of an operation:
 *      status, the external resource it made or found, attempt count, times,
 *      the last error code and a sanitised message, and whether a retry is
 *      safe and a rollback possible. Unique per (operation, step) so a step
 *      cannot be recorded twice.
 *
 *   4. **`teamhub_resource_link`** — the generic ledger of what TeamHub created
 *      or linked for a team: application, resource type, stable identifier,
 *      created-or-linked mode, the operation that made it, who, when, when it
 *      was last validated and its health. It is a *ledger* beside
 *      `teamhub_team_app_resources` (which keeps deciding what a team may
 *      open) and `teamhub_openproject_link` (which keeps being the link) — the
 *      two Phase 1 tables are not changed, and nothing reads this one to
 *      decide access.
 *
 * No boolean columns (`Types::BOOLEAN` with `notnull` fails on MySQL at
 * insert and on Postgres at bind — HANDOFF): flags are SMALLINT 0/1. Every
 * key is named explicitly and stays under 20 characters (`/migrations` §1);
 * `teamhub_provisioning_step` is 25 characters, which is exactly why its
 * primary key is named. This file references no `OCA\TeamHub\` symbol
 * (`/migrations` §2, issue #98).
 */
class Version000409006Date20260913000000 extends SimpleMigrationStep {

    public function __construct(private IDBConnection $db) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        // ── 1. teamhub_template.blueprint_json ─────────────────────────────
        if ($schema->hasTable('teamhub_template')) {
            $t = $schema->getTable('teamhub_template');
            if (!$t->hasColumn('blueprint_json')) {
                $t->addColumn('blueprint_json', Types::TEXT, ['notnull' => false, 'default' => null]);
                $output->info('teamhub_template: added blueprint_json');
                $changed = true;
            }
        }

        // ── 2. teamhub_provisioning (20) ───────────────────────────────────
        if (!$schema->hasTable('teamhub_provisioning')) {
            $p = $schema->createTable('teamhub_provisioning');
            $p->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            // Null until the team exists; then the circle's unique_id.
            $p->addColumn('team_id', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $p->addColumn('template_key', Types::STRING, ['notnull' => true, 'length' => 32]);
            // 'create' — a new OpenProject project; 'link' — an existing one.
            $p->addColumn('mode', Types::STRING, ['notnull' => true, 'length' => 16]);
            // pending | running | completed | failed | attention | rolled_back | cancelled
            $p->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
            $p->addColumn('current_step', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]);
            // Client-supplied, unique per creator: the replay guard.
            $p->addColumn('idempotency_key', Types::STRING, ['notnull' => true, 'length' => 64]);
            $p->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $p->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $p->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $p->addColumn('started_at', Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);
            $p->addColumn('completed_at', Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);
            // Written by whoever runs a step; a stale heartbeat is what the
            // background job adopts.
            $p->addColumn('heartbeat_at', Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);
            // The lease: set by a conditional UPDATE, so two runners cannot
            // both hold it.
            $p->addColumn('lock_token', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $p->addColumn('locked_at', Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);
            $p->addColumn('request_json', Types::TEXT, ['notnull' => false, 'default' => null]);
            $p->addColumn('result_json', Types::TEXT, ['notnull' => false, 'default' => null]);
            $p->addColumn('error_code', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $p->addColumn('error_message', Types::STRING, ['notnull' => false, 'length' => 1024, 'default' => null]);

            $p->setPrimaryKey(['id'], 'th_prov_pk');
            $p->addUniqueIndex(['created_by', 'idempotency_key'], 'th_prov_idem_uq');
            $p->addIndex(['team_id'], 'th_prov_team_idx');
            $p->addIndex(['status', 'heartbeat_at'], 'th_prov_status_idx');
            $p->addIndex(['created_by', 'created_at'], 'th_prov_creator_idx');

            $output->info('Created teamhub_provisioning table');
            $changed = true;
        }

        // ── 3. teamhub_provisioning_step (25) ──────────────────────────────
        if (!$schema->hasTable('teamhub_provisioning_step')) {
            $s = $schema->createTable('teamhub_provisioning_step');
            $s->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $s->addColumn('operation_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $s->addColumn('step_key', Types::STRING, ['notnull' => true, 'length' => 32]);
            $s->addColumn('sort_index', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            // pending | running | completed | skipped | failed | attention | rolled_back
            $s->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
            // What the step makes or finds: openproject_project, team, folder, …
            $s->addColumn('resource_type', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]);
            // The stable id of that resource once known.
            $s->addColumn('external_id', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            // An in-flight reference — an OpenProject job id, for one — so a
            // retry polls instead of submitting again.
            $s->addColumn('external_ref', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $s->addColumn('attempts', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $s->addColumn('started_at', Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);
            $s->addColumn('completed_at', Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);
            $s->addColumn('error_code', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $s->addColumn('error_message', Types::STRING, ['notnull' => false, 'length' => 1024, 'default' => null]);
            $s->addColumn('retry_safe', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
            $s->addColumn('rollback_possible', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $s->addColumn('detail_json', Types::TEXT, ['notnull' => false, 'default' => null]);
            $s->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);

            $s->setPrimaryKey(['id'], 'th_pstep_pk');
            $s->addUniqueIndex(['operation_id', 'step_key'], 'th_pstep_op_uq');

            $output->info('Created teamhub_provisioning_step table');
            $changed = true;
        }

        // ── 4. teamhub_resource_link (21) ──────────────────────────────────
        if (!$schema->hasTable('teamhub_resource_link')) {
            $r = $schema->createTable('teamhub_resource_link');
            $r->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $r->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            // The application: openproject, files, spreed, calendar, collectives, …
            $r->addColumn('app_id', Types::STRING, ['notnull' => true, 'length' => 32]);
            // The kind of thing inside that application: project, folder,
            // conversation, calendar, collective, project_folder, …
            $r->addColumn('resource_type', Types::STRING, ['notnull' => true, 'length' => 32]);
            // The stable identifier as the application names it.
            $r->addColumn('resource_id', Types::STRING, ['notnull' => true, 'length' => 255]);
            // Only when safe and needed — never user-supplied.
            $r->addColumn('resource_url', Types::STRING, ['notnull' => false, 'length' => 2048, 'default' => null]);
            // 'created' by TeamHub, or 'linked' — an existing resource.
            $r->addColumn('mode', Types::STRING, ['notnull' => true, 'length' => 16]);
            $r->addColumn('operation_id', Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);
            $r->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $r->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $r->addColumn('last_validated_at', Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);
            // ok | unknown | missing | error
            $r->addColumn('health', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'unknown']);
            $r->addColumn('meta_json', Types::TEXT, ['notnull' => false, 'default' => null]);

            $r->setPrimaryKey(['id'], 'th_rlink_pk');
            $r->addUniqueIndex(['team_id', 'app_id', 'resource_type', 'resource_id'], 'th_rlink_uq');
            $r->addIndex(['operation_id'], 'th_rlink_op_idx');

            $output->info('Created teamhub_resource_link table');
            $changed = true;
        }

        return $changed ? $schema : null;
    }

    /**
     * The `openproject` template's shipped blueprint, as literal JSON. Only
     * written when the row exists and has no blueprint yet — an
     * administrator's edit is never overwritten. The three older templates
     * get none: null means "derived from the row", which is what they were.
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('teamhub_template')) {
            return;
        }
        $db = $this->db;

        $qb = $db->getQueryBuilder();
        $qb->select('template_key', 'blueprint_json')
            ->from('teamhub_template')
            ->where($qb->expr()->eq('template_key', $qb->createNamedParameter('openproject')))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row === false || ($row['blueprint_json'] ?? null) !== null) {
            return;
        }

        // Literal — the same shape `Blueprint::defaults('openproject')` builds
        // at the time of writing, frozen here on purpose (/migrations §2).
        $blueprint = [
            'version'    => 1,
            // Apps and modules are the template row's (Version000409004) and
            // are re-derived from it on every read; listed here so the stored
            // shape is complete.
            'apps'       => [
                'required' => ['talk', 'files', 'calendar', 'intravox'],
                'optional' => [],
            ],
            'modules'    => [
                'required' => ['decisions', 'messages'],
                'optional' => [],
            ],
            'dashboard'  => [
                'widgets' => ['widget-openproject', 'widget-files-center', 'msgstream', 'widget-calendar', 'widget-pages', 'widget-deck', 'widget-members'],
                'hidden'  => [],
            ],
            'openproject' => [
                'required'          => true,
                'modes'             => ['create', 'link'],
                'approvedTemplates' => [],
                'allowParent'       => true,
                'copy'              => [
                    'members'      => false,
                    'workPackages' => true,
                    'versions'     => true,
                    'categories'   => true,
                    'wiki'         => true,
                    'queries'      => true,
                    'boards'       => true,
                    'overview'     => true,
                    'phases'       => true,
                ],
            ],
            'roles'      => [
                'mapping' => [
                    'owner'     => 'Project admin',
                    'admin'     => 'Project admin',
                    'moderator' => 'Member',
                    'member'    => 'Member',
                    'guest'     => null,
                ],
                'required' => ['owner'],
            ],
            'folder'     => ['behavior' => 'both'],
            'talk'       => ['behavior' => 'create'],
            'calendar'   => ['behavior' => 'create'],
            'collective' => ['behavior' => 'none'],
            'governance' => [],
            'lifecycle'  => [],
        ];

        $qb = $db->getQueryBuilder();
        $qb->update('teamhub_template')
            ->set('blueprint_json', $qb->createNamedParameter(json_encode($blueprint, JSON_THROW_ON_ERROR)))
            ->where($qb->expr()->eq('template_key', $qb->createNamedParameter('openproject')));
        $qb->executeStatement();

        $output->info('Version000409006: seeded the openproject template blueprint');
    }
}
