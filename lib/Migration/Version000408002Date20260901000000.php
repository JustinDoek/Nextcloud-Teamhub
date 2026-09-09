<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCA\TeamHub\Constants\TeamTemplates;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.8.2 — Track F2a: templates and policy profiles.
 *
 * Full design: `TRACK-F2-DESIGN.md` §2. Reasoning: `DESIGN.md` §2.104–§2.108.
 *
 * Six tables. Four of them are written by nothing in F2a — drift findings land
 * in F2c and reclassification requests in F2d — and they are created here
 * anyway rather than in three more migrations, because empty schema costs
 * nothing and a migration per stage costs a rollout each.
 *
 * **This migration assigns no team a profile and writes no team's settings.**
 * That is the whole inertness guarantee (`TRACK-F2-DESIGN.md` §5 constraints):
 * every existing team is unclassified, unclassified is an empty policy, and an
 * instance that never assigns anything behaves exactly as it did before.
 * Existing teams keep their settings as they are.
 *
 * **Unclassified is deliberately not a seeded row.** A team with no
 * `teamhub_team_policy` row resolves to a synthetic empty profile in
 * `PolicyService`. An `unclassified` row in `teamhub_policy_profile` could have
 * values added to it, and those values would silently begin governing every
 * unassigned team on the instance — inertness undone by one admin edit. Empty
 * by construction cannot do that.
 *
 * Identifier lengths (SKILLS.md § Database identifier length): the longest
 * table name here is `teamhub_reclass_request` at 23 characters, so every
 * auto-generated `<table>_pkey` would fit inside the 30-character cap. Every
 * key and index is named explicitly anyway — composite index names include the
 * column list and blow past 30 quickly.
 *
 * Postgres compatibility: BIGINT for ids and every timestamp, **no BOOLEAN
 * columns** (DESIGN §2.4 — `Types::BOOLEAN` with `notnull=true` fails on MySQL
 * at insert and Postgres at bind; SMALLINT with a default and PARAM_INT
 * instead), short strings rather than enums, and no reserved words among the
 * column names.
 */
class Version000408002Date20260901000000 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        // ── teamhub_template (16) ─────────────────────────────────────────
        // Admin-adjustable templates. Seeded from the TeamTemplates constants
        // in postSchemaChange; that class stays the seed source and stops
        // being read at runtime once F2b moves the wizard onto this table.
        if (!$schema->hasTable('teamhub_template')) {
            $t = $schema->createTable('teamhub_template');

            $t->addColumn('template_key', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('description', Types::TEXT, ['notnull' => false]);
            // ';'-separated keys rather than a child table: both lists are
            // short, closed vocabularies (TeamTemplates::APPS / ::MODULES) and
            // nothing ever queries "which templates provision Deck".
            $t->addColumn('apps', Types::TEXT, ['notnull' => false]);
            $t->addColumn('modules', Types::TEXT, ['notnull' => false]);
            $t->addColumn('offer_expiry', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            // The inert Circles bitmask preselection. NOT policy — see
            // TeamTemplates::configBitmask() and TRACK-F2-DESIGN.md §6.3.
            // Never locked, never scanned, no PolicyField entry.
            $t->addColumn('preselect_config', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $t->addColumn('sort_index', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            // 1 for the three shipped rows. Lets the UI warn before editing a
            // shipped template without preventing it.
            $t->addColumn('is_seeded', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $t->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $t->addColumn('updated_at', Types::BIGINT, ['notnull' => false]);

            $t->setPrimaryKey(['template_key'], 'th_tmpl_pk');
            $t->addIndex(['sort_index'], 'th_tmpl_sort_idx');

            $changed = true;
        }

        // ── teamhub_policy_profile (22) ───────────────────────────────────
        if (!$schema->hasTable('teamhub_policy_profile')) {
            $p = $schema->createTable('teamhub_policy_profile');

            // Stable TeamHub-owned string key. NEVER an id issued by another
            // app — DESIGN §2.103: ids do not travel between instances, and an
            // admin renaming the thing they point at rewrites policy identity.
            // This is why profiles do not ride on tags.
            $p->addColumn('profile_key', Types::STRING, ['notnull' => true, 'length' => 32]);
            // Display name. Deliberately NOT unique and NOT identity — renaming
            // it must not change what the profile is.
            $p->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 64]);
            $p->addColumn('description', Types::TEXT, ['notnull' => false]);
            // Sensitivity order, low = least sensitive. Display order, and it
            // labels a reclassification request as loosening or tightening.
            // Deliberately NOT a permission lattice — nothing branches on it.
            $p->addColumn('sort_index', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $p->addColumn('is_seeded', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $p->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $p->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $p->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $p->addColumn('updated_at', Types::BIGINT, ['notnull' => false]);

            $p->setPrimaryKey(['profile_key'], 'th_pprof_pk');
            $p->addIndex(['sort_index'], 'th_pprof_sort_idx');

            $changed = true;
        }

        // ── teamhub_policy_value (20) ─────────────────────────────────────
        // One row per (profile, field). A field absent from a profile is
        // *ungoverned* by it — not "false". Absence is the third state and it
        // is the common one.
        if (!$schema->hasTable('teamhub_policy_value')) {
            $v = $schema->createTable('teamhub_policy_value');

            $v->addColumn('profile_key', Types::STRING, ['notnull' => true, 'length' => 32]);
            $v->addColumn('field_key', Types::STRING, ['notnull' => true, 'length' => 48]);
            // Everything serialised as text; PolicyField::cast() casts it back.
            // Lists are ';'-separated, matching teamhub_template.apps.
            $v->addColumn('field_value', Types::TEXT, ['notnull' => false]);
            // 'default' | 'locked'. This is where per-field default-vs-locked
            // lives — the thing that makes one mechanism serve freedom,
            // governed and mid-market postures.
            $v->addColumn('mode', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'default']);

            $v->setPrimaryKey(['profile_key', 'field_key'], 'th_pval_pk');
            $v->addIndex(['field_key'], 'th_pval_field_idx');

            $changed = true;
        }

        // ── teamhub_team_policy (19) ──────────────────────────────────────
        // Assignment. ROW EXISTENCE IS THE FACT — a team with no row is
        // unclassified. Same argument Version000406013 makes for
        // teamhub_team_expiry: a nullable profile_key would be ambiguous
        // between "never classified" and "deliberately declassified".
        //
        // Nothing writes this table in F2a. Assignment lands in F2b.
        if (!$schema->hasTable('teamhub_team_policy')) {
            $a = $schema->createTable('teamhub_team_policy');

            $a->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $a->addColumn('profile_key', Types::STRING, ['notnull' => true, 'length' => 32]);
            $a->addColumn('assigned_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $a->addColumn('assigned_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            // 'admin' | 'creation' | 'import' | 'reclassification' — how the
            // team got its profile. Evidence, and it costs one column.
            $a->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'admin']);

            $a->setPrimaryKey(['team_id'], 'th_tpol_pk');
            $a->addIndex(['profile_key'], 'th_tpol_prof_idx');

            $changed = true;
        }

        // ── teamhub_policy_drift (20) ─────────────────────────────────────
        // Findings. Written by the scan in F2c; created here so F2c is a
        // service-and-UI change rather than another migration.
        if (!$schema->hasTable('teamhub_policy_drift')) {
            $d = $schema->createTable('teamhub_policy_drift');

            $d->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
            $d->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $d->addColumn('field_key', Types::STRING, ['notnull' => true, 'length' => 48]);
            // The profile in force WHEN THE FINDING OPENED. A team can be
            // reclassified while a finding is open; this records what it was
            // measured against.
            $d->addColumn('profile_key', Types::STRING, ['notnull' => true, 'length' => 32]);
            $d->addColumn('expected_value', Types::TEXT, ['notnull' => false]);
            $d->addColumn('observed_value', Types::TEXT, ['notnull' => false]);
            $d->addColumn('first_seen_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            // Proves the sweep is still running.
            $d->addColumn('last_seen_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $d->addColumn('resolved_at', Types::BIGINT, ['notnull' => false]);
            // 'scan' | 'write' | 'event'. 'event' exists from day one and
            // nothing writes it: if Circles ever dispatches on a config change
            // (~10 lines upstream, DESIGN §2.103), the same row gains an actor
            // and this value, with no migration.
            $d->addColumn('detected_via', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'scan']);
            // NULL is the normal case and means UNKNOWN, not missing. Circles
            // emits no event and writes no activity row for a config change,
            // so scan-detected drift can never be attributed. DESIGN §2.106.
            $d->addColumn('actor_uid', Types::STRING, ['notnull' => false, 'length' => 64]);

            $d->setPrimaryKey(['id'], 'th_pdrf_pk');
            // NOT a unique index on (team_id, field_key, resolved_at): both
            // MySQL and Postgres permit unlimited NULLs in a unique index, so a
            // nullable resolved_at would enforce nothing on either engine while
            // reading as a guarantee. One-open-row-per-pair is enforced in the
            // service.
            $d->addIndex(['team_id', 'field_key'], 'th_pdrf_team_idx');
            $d->addIndex(['resolved_at'], 'th_pdrf_res_idx');

            $changed = true;
        }

        // ── teamhub_reclass_request (23) ──────────────────────────────────
        // The reclassification queue, shaped like teamhub_expiry_request
        // because it is the same workflow with a different payload. Written in
        // F2d. Rows are never deleted on decision — the history of who asked
        // for what is exactly what the audit trail is for.
        if (!$schema->hasTable('teamhub_reclass_request')) {
            $r = $schema->createTable('teamhub_reclass_request');

            $r->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
            $r->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $r->addColumn('requested_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $r->addColumn('requested_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $r->addColumn('requested_key', Types::STRING, ['notnull' => true, 'length' => 32]);
            $r->addColumn('reason', Types::TEXT, ['notnull' => false]);
            // 'pending' | 'approved' | 'denied' | 'withdrawn' | 'superseded'.
            $r->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
            $r->addColumn('decided_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $r->addColumn('decided_at', Types::BIGINT, ['notnull' => false]);
            // May differ from requested_key — the same reason granted_until
            // differs from proposed_until on an expiry request: an admin who
            // would grant Internal but not Public should not have to deny.
            $r->addColumn('granted_key', Types::STRING, ['notnull' => false, 'length' => 32]);
            $r->addColumn('decision_note', Types::TEXT, ['notnull' => false]);

            $r->setPrimaryKey(['id'], 'th_rcr_pk');
            $r->addIndex(['team_id', 'status'], 'th_rcr_team_idx');
            $r->addIndex(['status'], 'th_rcr_status_idx');

            $changed = true;
        }

        return $changed ? $schema : null;
    }

    /**
     * Seed the three templates and the four starter profiles.
     *
     * Idempotent: each table is seeded only when it is empty, so a repeated
     * upgrade never duplicates rows and never overwrites an admin's edits.
     *
     * Seeding profile *values* is safe and deliberate. A profile governs
     * nothing until a team is assigned to it, and assignment does not exist
     * until F2b — so the starter set demonstrates the mechanism (including the
     * per-field default-vs-locked distinction) while changing no behaviour.
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $now = time();

        if ($this->isEmpty('teamhub_template')) {
            $this->seedTemplates($output, $now);
        }
        if ($this->isEmpty('teamhub_policy_profile')) {
            $this->seedProfiles($output, $now);
        }
    }

    private function isEmpty(string $table): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($table)->setMaxResults(1);
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row === false;
    }

    private function seedTemplates(IOutput $output, int $now): void {
        // Labels and descriptions are English here and are the *fallback*. The
        // admin UI translates a seeded template's label by its key through
        // src/constants/policy.js, so the seven locale files stay the place
        // translations live and check:l10n keeps its reach. An admin who edits
        // a label is taken at their word and the translation stops applying.
        $meta = [
            'collaboration' => ['label' => 'Collaboration', 'sort' => 0, 'expiry' => 1],
            'project'       => ['label' => 'Project',       'sort' => 10, 'expiry' => 1],
            // Department teams are not eligible for an expiration date —
            // TeamExpiryService::isEligible() allows collaboration and project
            // only, so offering the input here would produce a form field the
            // backend refuses.
            'department'    => ['label' => 'Department',    'sort' => 20, 'expiry' => 0],
        ];

        foreach (TeamTemplates::TEMPLATES as $key) {
            $m       = $meta[$key];
            $profile = TeamTemplates::forTemplate($key);

            $apps = [];
            foreach ($profile['apps'] as $app => $on) {
                if ($on) {
                    $apps[] = $app;
                }
            }
            $modules = [];
            foreach ($profile['modules'] as $mod => $on) {
                if ($on) {
                    $modules[] = $mod;
                }
            }

            $qb = $this->db->getQueryBuilder();
            $qb->insert('teamhub_template')->values([
                'template_key'     => $qb->createNamedParameter($key),
                'label'            => $qb->createNamedParameter($m['label']),
                'description'      => $qb->createNamedParameter(null),
                'apps'             => $qb->createNamedParameter(implode(';', $apps)),
                'modules'          => $qb->createNamedParameter(implode(';', $modules)),
                'offer_expiry'     => $qb->createNamedParameter($m['expiry'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                'preselect_config' => $qb->createNamedParameter(TeamTemplates::configBitmask($key), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                'sort_index'       => $qb->createNamedParameter($m['sort'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                'is_seeded'        => $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                'updated_by'       => $qb->createNamedParameter(null),
                'updated_at'       => $qb->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
            ]);
            $qb->executeStatement();
        }

        $output->info('Version000408002: seeded ' . count(TeamTemplates::TEMPLATES) . ' team templates');
    }

    private function seedProfiles(IOutput $output, int $now): void {
        // One ordered sensitivity axis (DESIGN §2.105). "External members
        // allowed" is a FIELD, not a name on the axis — a customer project
        // under NDA is both external and confidential, which a two-axis name
        // list cannot express.
        //
        // Gaps of ten so an admin can insert a level between two seeded ones.
        //
        // 'public' locks nothing on purpose: a profile that only supplies
        // starting values is a legitimate one, and the starter set should show
        // both modes rather than teach that a profile means lockdown.
        //
        // **No PolicyField constants below this line, deliberately.** A
        // migration is a historical record and has to keep running against a
        // registry that has moved on. This one named PolicyField::MODE_DEFAULT
        // and ::MODE_LOCKED until v4.9.1; when v4.8.3 took per-field mode out
        // of the product those two constants went with it, and every instance
        // that had not already recorded this migration died here on an
        // `Undefined constant` fatal. That was every real install — the
        // previous release was 4.8.0, so nobody had run it — and every fresh
        // one. Only dev machines, which passed 4.8.2 while the constants still
        // existed, were spared, which is why it reached users. Issue #98.
        //
        // Field keys, the mode values and the bool serialisation are frozen as
        // literals for that reason. The modes are 'default' | 'locked' per the
        // column comment in changeSchema() above, and nothing ever reads them:
        // Version000408003 drops the column minutes later.
        //
        // scripts/check-migrations.js fails the build if a migration reaches
        // for an OCA\TeamHub constant again.
        $seed = [
            'public' => [
                'label' => 'Public', 'sort' => 0,
                'description' => 'Open to the whole instance. Nothing is locked.',
                'values' => [
                    ['cfg_visible', true, 'default'],
                    ['cfg_open', true, 'default'],
                    ['external_members', true, 'default'],
                    ['public_messages', true, 'default'],
                ],
            ],
            'internal' => [
                'label' => 'Internal', 'sort' => 10,
                'description' => 'Discoverable inside the organisation, but nobody joins on their own.',
                'values' => [
                    ['cfg_visible', true, 'default'],
                    ['cfg_open', false, 'locked'],
                    ['external_members', false, 'default'],
                    ['public_messages', false, 'default'],
                ],
            ],
            'confidential' => [
                'label' => 'Confidential', 'sort' => 20,
                'description' => 'Not discoverable, invitation only, no external members.',
                'values' => [
                    ['cfg_visible', false, 'locked'],
                    ['cfg_open', false, 'locked'],
                    ['cfg_invite', true, 'default'],
                    ['external_members', false, 'locked'],
                    ['public_messages', false, 'locked'],
                ],
            ],
            'restricted' => [
                'label' => 'Restricted', 'sort' => 30,
                'description' => 'The most protected posture. Every governed setting is locked.',
                'values' => [
                    ['cfg_visible', false, 'locked'],
                    ['cfg_open', false, 'locked'],
                    ['cfg_invite', true, 'locked'],
                    ['cfg_protected', true, 'locked'],
                    ['external_members', false, 'locked'],
                    ['public_messages', false, 'locked'],
                ],
            ],
        ];

        foreach ($seed as $key => $def) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('teamhub_policy_profile')->values([
                'profile_key' => $qb->createNamedParameter($key),
                'label'       => $qb->createNamedParameter($def['label']),
                'description' => $qb->createNamedParameter($def['description']),
                'sort_index'  => $qb->createNamedParameter($def['sort'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                'is_seeded'   => $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                'created_by'  => $qb->createNamedParameter(''),
                'created_at'  => $qb->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                'updated_by'  => $qb->createNamedParameter(null),
                'updated_at'  => $qb->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
            ]);
            $qb->executeStatement();

            foreach ($def['values'] as [$fieldKey, $value, $mode]) {
                $vb = $this->db->getQueryBuilder();
                $vb->insert('teamhub_policy_value')->values([
                    'profile_key' => $vb->createNamedParameter($key),
                    'field_key'   => $vb->createNamedParameter($fieldKey),
                    'field_value' => $vb->createNamedParameter($value ? '1' : '0'),
                    'mode'        => $vb->createNamedParameter($mode),
                ]);
                $vb->executeStatement();
            }
        }

        $output->info('Version000408002: seeded ' . count($seed) . ' policy profiles, none assigned to any team');
    }
}
