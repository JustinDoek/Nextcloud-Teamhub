<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.13.23 — the integration registry gains a package level, and the four
 * governance decisions each get their own record.
 *
 * ## What was wrong with one table
 *
 * `teamhub_integ_registry` is keyed `(app_id, integration_type)`, so a
 * registration *is* a widget or *is* a tab, and an app can own exactly one of
 * each. A second widget collides on the unique index. `teamhub_team_integrations`
 * then holds one bit per (registration, team) which means both "this team
 * participates" and "show this on screen", and a team administrator's "off"
 * deletes the row — so there is nowhere to record "part of this team, hidden".
 *
 * Five tables replace that with two levels and four separable decisions. The
 * two old tables are **not touched**: the compatibility adapter keeps writing
 * them, the old render endpoints keep reading them, and nothing is dropped in
 * this step. A later version removes them once no app registers the old way.
 *
 *   teamhub_integ_pkg    one row per `app_id + integration_key` — the external
 *                        app's integration as a thing. Permanent once created:
 *                        deregistration sets `registered = 0` and never
 *                        deletes, which is what lets every decision below hang
 *                        off a surrogate id and still survive an app being
 *                        disabled, upgraded, removed and reinstalled.
 *
 *   teamhub_integ_cap    one row per capability inside a package. Also
 *                        permanent, for the same reason: a per-team
 *                        shown/hidden preference points at `cap_id`, and a
 *                        capability row that came and went would take the
 *                        preference with it.
 *
 *   teamhub_integ_adm    the Nextcloud administrator's enablement. Its own
 *                        table rather than a column on the package, so the
 *                        decision carries its own actor and timestamp and is
 *                        legible as an administrative act — and so that
 *                        registration and enablement cannot be confused for
 *                        one fact, which is the mistake the old model made.
 *                        **No row means disabled.** A newly discovered
 *                        package is off.
 *
 *   teamhub_integ_tmpl   which templates include which packages. Independent
 *                        of enablement in both directions: enabling adds
 *                        nothing to a template, and disabling removes nothing
 *                        from one.
 *
 *   teamhub_integ_vis    per-team, per-capability presentation. **No row means
 *                        shown, at the capability's default order** — which is
 *                        what makes a newly registered widget appear by itself
 *                        for every team whose template already includes the
 *                        package, with no write anywhere.
 *
 * ## Why `hidden` and `sort_order` share a row
 *
 * They are the same `(team, capability)` grain, every render needs both, and a
 * hidden capability keeps the position it returns to when it is shown again.
 * Two tables would mean two reads per card and a row pair that must be kept
 * consistent. They stay independent *columns*: writing one never writes the
 * other.
 *
 * ## What is deliberately not stored
 *
 * Whether the owning app is installed and enabled. That is read live from
 * Nextcloud's app manager on every check. A stored copy is a fact that goes
 * stale the moment an administrator disables an app, and the whole point of
 * the availability level is that it tracks the app rather than a snapshot of
 * it.
 *
 * ## Rule compliance (`/migrations`)
 *
 * Rule 1 — every primary key and index is named explicitly and every name is
 * under 20 characters, with the longest table at 18 (`teamhub_integ_tmpl`).
 *
 * **What the fresh-database run actually showed about that** (2026-10-23,
 * Postgres 35.0.0): the explicit names take effect for the secondary indexes
 * — `th_integ_pkg_ident`, `th_integ_cap_type` and the rest are all there — but
 * **not for the primary keys**. Postgres names a primary-key constraint
 * `<table>_pkey` whatever `setPrimaryKey()` was passed, so what exists is
 * `oc_teamhub_integ_tmpl_pkey` (26) and four siblings at 25, and
 * `th_integ_pkg_pk` appears nowhere. The explicit name is still worth passing
 * — it is what MySQL and the schema model use — but the thing that keeps a
 * primary key under the 30-character cap on Postgres is the **table name**.
 * At 18 characters the longest of these leaves four to spare; a 25-character
 * table would overflow no matter what name the migration passed, which is the
 * failure rule 1 exists to prevent.
 * Rule 1b — no column is added to an existing table at all, so the
 * NOT NULL-with-empty-default trap cannot apply; the `notnull => true,
 * default => ''` columns below are all inside `createTable()`, where they are
 * allowed. Rule 2 — no `OCA\TeamHub\` constant, class or static method is
 * referenced; the capability type strings are inlined literals, which is the
 * point: this file records what the schema accepted in October 2026, whatever
 * `CapabilityType` says years from now. Rule 2a — no seed data and no
 * `postSchemaChange()`; the legacy backfill is an `IRepairStep`
 * (`MigrateLegacyIntegrations`) registered under both `<install>` and
 * `<post-migration>`, because a fresh install never runs a post step.
 */
class Version000413023Date20261023120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        // ────────────────────────────────────────────────────────────────
        // 1. teamhub_integ_pkg — the registered integration package
        // ────────────────────────────────────────────────────────────────
        if (!$schema->hasTable('teamhub_integ_pkg')) {
            $output->info('Version000413023: creating teamhub_integ_pkg');

            $table = $schema->createTable('teamhub_integ_pkg');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);

            // The stable identity, half one.
            $table->addColumn('app_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            // The stable identity, half two. Lets one app register more than
            // one genuinely separate TeamHub integration, and gives the pair a
            // name that is not a translated title, a class name or a URL.
            $table->addColumn('integration_key', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            $table->addColumn('title', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
            ]);

            $table->addColumn('description', Types::STRING, [
                'notnull' => false,
                'length'  => 500,
                'default' => null,
            ]);

            // A vue-material-design-icons name. The app ships no markup.
            $table->addColumn('icon', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
            ]);

            // The registering app's own integration version. Recorded and
            // shown to administrators; TeamHub never branches on it.
            $table->addColumn('version', Types::STRING, [
                'notnull' => true,
                'length'  => 32,
                'default' => '',
            ]);

            // The TeamHub range the package declares support for. Empty means
            // unbounded on that end. A package outside its range is stored and
            // reported incompatible rather than refused, so an administrator
            // can tell an incompatible integration from a missing one.
            $table->addColumn('min_th_version', Types::STRING, [
                'notnull' => true,
                'length'  => 32,
                'default' => '',
            ]);

            $table->addColumn('max_th_version', Types::STRING, [
                'notnull' => true,
                'length'  => 32,
                'default' => '',
            ]);

            // 1 for a package the compatibility adapter synthesised from an
            // old single registration. Shown to administrators so a legacy
            // integration is visibly legacy, and read by the backfill so it
            // never adopts a row twice.
            $table->addColumn('is_legacy', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            // Did this package register in the current process? Written at
            // boot. Durable rather than purely in-memory so the administration
            // screen can say "did not register" about an app that is installed
            // and enabled — a state that is neither "disabled" nor "gone", and
            // that the old model could not express.
            $table->addColumn('registered', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            // Whatever the package passed as metadata, as JSON. Recorded
            // verbatim and shown; never interpreted.
            $table->addColumn('metadata', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);

            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            // When the package last registered. The answer to "is this app
            // still talking to us" for an administrator looking at a row that
            // has stopped working.
            $table->addColumn('last_seen_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id'], 'th_integ_pkg_pk');
            // The stable identity. Unique, because registering the same pair
            // twice must update one row rather than make a second.
            $table->addUniqueIndex(['app_id', 'integration_key'], 'th_integ_pkg_ident');
            // "Everything this app registered" — the availability sweep when
            // an app is enabled or disabled.
            $table->addIndex(['app_id'], 'th_integ_pkg_app');

            $output->info('Version000413023: created teamhub_integ_pkg');
            $changed = true;
        }

        // ────────────────────────────────────────────────────────────────
        // 2. teamhub_integ_cap — one capability inside a package
        // ────────────────────────────────────────────────────────────────
        if (!$schema->hasTable('teamhub_integ_cap')) {
            $output->info('Version000413023: creating teamhub_integ_cap');

            $table = $schema->createTable('teamhub_integ_cap');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);

            // teamhub_integ_pkg.id. No database foreign key, consistent with
            // teamhub_team_integrations.registry_id — referential integrity is
            // enforced in the service layer throughout this app.
            $table->addColumn('pkg_id', Types::INTEGER, [
                'notnull'  => true,
                'unsigned' => true,
            ]);

            // The stable identity's third part. Per-team preferences and
            // ordering survive on this, so an app must never change it.
            $table->addColumn('capability_key', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            // 'widget' | 'menu_item' | 'my_work_provider' today. A string
            // rather than an enum column so a later version can add a type
            // without a migration; the accepted set is enforced in PHP.
            $table->addColumn('cap_type', Types::STRING, [
                'notnull' => true,
                'length'  => 32,
            ]);

            $table->addColumn('title', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
            ]);

            $table->addColumn('description', Types::STRING, [
                'notnull' => false,
                'length'  => 500,
                'default' => null,
            ]);

            $table->addColumn('icon', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
            ]);

            // The class TeamHub resolves from the DI container and calls.
            // Only ever written from an in-process registration — an HTTP
            // request can never choose which class TeamHub instantiates.
            $table->addColumn('provider_class', Types::STRING, [
                'notnull' => false,
                'length'  => 255,
                'default' => null,
            ]);

            // 2048 matches teamhub_integ_registry.iframe_url, so a legacy row
            // migrates without truncation.
            $table->addColumn('iframe_url', Types::STRING, [
                'notnull' => false,
                'length'  => 2048,
                'default' => null,
            ]);

            // Position among capabilities of the same type when a team has
            // expressed no preference.
            $table->addColumn('default_order', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);

            // Semicolon-separated TeamHub surfaces, the same encoding
            // teamhub_template.apps uses. Empty means the type's default
            // surface.
            $table->addColumn('surfaces', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
                'default' => '',
            ]);

            // Type-specific render hints as JSON (a widget's preferred size,
            // say). Optional, and never required to render.
            $table->addColumn('presentation', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);

            // Did this capability appear in the most recent registration of
            // its package? A package that drops a capability leaves the row
            // behind with registered = 0, so the preferences attached to it
            // survive the capability coming back.
            $table->addColumn('registered', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id'], 'th_integ_cap_pk');
            $table->addUniqueIndex(['pkg_id', 'capability_key'], 'th_integ_cap_key');
            // "Every My Work provider across all packages" — how the provider
            // registry discovers external sources at boot without reading
            // every capability on the instance.
            $table->addIndex(['cap_type'], 'th_integ_cap_type');

            $output->info('Version000413023: created teamhub_integ_cap');
            $changed = true;
        }

        // ────────────────────────────────────────────────────────────────
        // 3. teamhub_integ_adm — the administrator's enablement
        // ────────────────────────────────────────────────────────────────
        if (!$schema->hasTable('teamhub_integ_adm')) {
            $output->info('Version000413023: creating teamhub_integ_adm');

            $table = $schema->createTable('teamhub_integ_adm');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);

            $table->addColumn('pkg_id', Types::INTEGER, [
                'notnull'  => true,
                'unsigned' => true,
            ]);

            // 1 enabled, 0 disabled. A disable keeps the row rather than
            // deleting it, so the administration screen can show who turned it
            // off and when — and so "never decided" stays distinguishable from
            // "decided against". SMALLINT bound PARAM_INT, never PARAM_BOOL
            // (DESIGN §2.4).
            $table->addColumn('enabled', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->addColumn('changed_by', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
                'default' => '',
            ]);

            $table->addColumn('changed_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id'], 'th_integ_adm_pk');
            $table->addUniqueIndex(['pkg_id'], 'th_integ_adm_pkg');

            $output->info('Version000413023: created teamhub_integ_adm');
            $changed = true;
        }

        // ────────────────────────────────────────────────────────────────
        // 4. teamhub_integ_tmpl — template inclusion
        // ────────────────────────────────────────────────────────────────
        if (!$schema->hasTable('teamhub_integ_tmpl')) {
            $output->info('Version000413023: creating teamhub_integ_tmpl');

            $table = $schema->createTable('teamhub_integ_tmpl');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);

            // teamhub_template.template_key. 64 matches that table's column.
            $table->addColumn('template_key', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            $table->addColumn('pkg_id', Types::INTEGER, [
                'notnull'  => true,
                'unsigned' => true,
            ]);

            $table->addColumn('included_by', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
                'default' => '',
            ]);

            $table->addColumn('included_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id'], 'th_integ_tmpl_pk');
            // A template either includes a package or does not. There is no
            // third state and no policy column: `required`, `default_on`,
            // `default_off` and `prohibited` are deliberately not part of this
            // model — presence of the row is the whole decision.
            $table->addUniqueIndex(['template_key', 'pkg_id'], 'th_integ_tmpl_uq');
            // "Which templates include this package" — the impact preview the
            // administrator sees before disabling it.
            $table->addIndex(['pkg_id'], 'th_integ_tmpl_pkg');

            $output->info('Version000413023: created teamhub_integ_tmpl');
            $changed = true;
        }

        // ────────────────────────────────────────────────────────────────
        // 5. teamhub_integ_vis — per-team presentation of one capability
        // ────────────────────────────────────────────────────────────────
        if (!$schema->hasTable('teamhub_integ_vis')) {
            $output->info('Version000413023: creating teamhub_integ_vis');

            $table = $schema->createTable('teamhub_integ_vis');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]);

            // Circles unique_id, 31 characters in practice; 64 matches every
            // other team_id column in this app.
            $table->addColumn('team_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            // teamhub_integ_cap.id. Safe as a key because capability rows are
            // never deleted — see the class docblock.
            $table->addColumn('cap_id', Types::INTEGER, [
                'notnull'  => true,
                'unsigned' => true,
            ]);

            // 1 hidden, 0 shown. A row with hidden = 0 is written when a team
            // administrator reorders a capability without hiding it; the
            // absence of a row still means shown, so nothing has to be
            // backfilled for a team that has never been touched.
            $table->addColumn('hidden', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            // Position among shown capabilities of the same type. -1 means
            // "no preference, use the capability's default_order": a hide must
            // not silently assign a position, and a reorder must not silently
            // unhide.
            $table->addColumn('sort_order', Types::INTEGER, [
                'notnull' => true,
                'default' => -1,
            ]);

            $table->addColumn('updated_by', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
                'default' => '',
            ]);

            $table->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 8,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id'], 'th_integ_vis_pk');
            $table->addUniqueIndex(['team_id', 'cap_id'], 'th_integ_vis_uq');
            // The presentation lookup: one team's preferences, read once per
            // workspace render.
            $table->addIndex(['team_id'], 'th_integ_vis_team');

            $output->info('Version000413023: created teamhub_integ_vis');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
