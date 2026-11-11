<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.13.53 — a building or a floor in the People module can come from a
 * Nextcloud resource backend instead of being typed in twice.
 *
 * Two nullable columns on each of `teamhub_buildings` and `teamhub_floors`:
 *
 *   source      the backend identifier the row was synced from
 *               ('calendar_resource_management', 'roomvox', …), or NULL for a
 *               row an administrator created here.
 *   source_ref  the key the row was matched on within that backend.
 *
 * ## Why a name and not an id
 *
 * Nextcloud publishes a room's building and floor as *metadata strings* —
 * `{http://nextcloud.com/ns}room-building-name` and `…room-building-story` —
 * and nothing else. There is no building id in the public room API, and the
 * resource app's own tables are its private schema. So `source_ref` holds the
 * name as the backend published it, and a rename over there reads here as a
 * building that went away and one that arrived. That trade was taken
 * deliberately: it buys a sync that works for *every* room backend rather than
 * one that works for one app and breaks when that app changes its tables.
 *
 * `source_ref` is therefore distinct from `name` on purpose. `name` is what an
 * administrator reads and may one day be a fallback (an address, where an
 * older backend publishes no building name); `source_ref` is the match key and
 * nothing is allowed to rewrite it.
 *
 * ## Rule compliance (`/migrations`)
 *
 * Both columns land on tables that already exist, so rule 1b applies and both
 * are `'notnull' => false` with no default — never `notnull` with `''`, which
 * is the combination that aborts `occ upgrade` for the whole instance.
 *
 * Both indexes are named explicitly per rule 1 (`th_bld_src_idx`,
 * `th_flr_src_idx`) — a generated name for a two-column index on a table
 * called `oc_teamhub_buildings` would be well past the 30-character cap.
 * Neither index is unique: two backends may each publish a building of the
 * same name, and the sync keys on the pair, not on the ref alone.
 *
 * No `OCA\TeamHub\` constant or class is referenced (rule 2); the backend
 * identifiers above appear only as prose. No seed data — an instance with no
 * resource backend has nothing to sync and NULL in both columns everywhere,
 * which is the correct starting state and needs no repair step (rule 2a).
 */
class Version000413053Date20261030200000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        // ---------------------------------------------------------------------
        // teamhub_buildings
        // ---------------------------------------------------------------------
        if ($schema->hasTable('teamhub_buildings')) {
            $table = $schema->getTable('teamhub_buildings');

            if (!$table->hasColumn('source')) {
                $output->info('Version000413053: adding teamhub_buildings.source');
                $table->addColumn('source', Types::STRING, [
                    'notnull' => false,
                    'length'  => 64,
                ]);
                $changed = true;
            }

            if (!$table->hasColumn('source_ref')) {
                $output->info('Version000413053: adding teamhub_buildings.source_ref');
                $table->addColumn('source_ref', Types::STRING, [
                    'notnull' => false,
                    'length'  => 255,
                ]);
                $changed = true;
            }

            if (!$table->hasIndex('th_bld_src_idx')) {
                $output->info('Version000413053: adding index th_bld_src_idx');
                $table->addIndex(['source', 'source_ref'], 'th_bld_src_idx');
                $changed = true;
            }
        }

        // ---------------------------------------------------------------------
        // teamhub_floors
        // ---------------------------------------------------------------------
        if ($schema->hasTable('teamhub_floors')) {
            $table = $schema->getTable('teamhub_floors');

            if (!$table->hasColumn('source')) {
                $output->info('Version000413053: adding teamhub_floors.source');
                $table->addColumn('source', Types::STRING, [
                    'notnull' => false,
                    'length'  => 64,
                ]);
                $changed = true;
            }

            if (!$table->hasColumn('source_ref')) {
                $output->info('Version000413053: adding teamhub_floors.source_ref');
                $table->addColumn('source_ref', Types::STRING, [
                    'notnull' => false,
                    'length'  => 255,
                ]);
                $changed = true;
            }

            if (!$table->hasIndex('th_flr_src_idx')) {
                $output->info('Version000413053: adding index th_flr_src_idx');
                $table->addIndex(['source', 'source_ref'], 'th_flr_src_idx');
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
