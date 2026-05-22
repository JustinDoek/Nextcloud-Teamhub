<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.44.0 — Session B3: add presence_enabled to teamhub_presence_team.
 *
 * The teamhub_presence_team table was created in v3.42.0 with hide_reasons
 * only. B3 adds presence_enabled (SMALLINT, default 0) so each team can
 * independently opt into showing the Presence tab.
 *
 * Default 0 = off. Admins enable per-team via Manage Team → Settings.
 * This matches the design decision: presence tab is off by default.
 *
 * Idempotent: checks hasColumn before adding.
 */
class Version000344000Date20260519000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('teamhub_presence_team')) {
            $table = $schema->getTable('teamhub_presence_team');

            if (!$table->hasColumn('presence_enabled')) {
                $output->info('Version000344000: adding presence_enabled to teamhub_presence_team');
                $table->addColumn('presence_enabled', Types::SMALLINT, [
                    'notnull' => true,
                    'default' => 0,
                ]);
                $changed = true;
            } else {
                $output->info('Version000344000: presence_enabled already exists — skipping');
            }
        } else {
            $output->warning('Version000344000: teamhub_presence_team does not exist — skipping (run occ upgrade from 3.42.0 first)');
        }

        return $changed ? $schema : null;
    }
}
