<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.77.1 — One-time: enable Decisions module at the NC-admin level.
 *
 * Background
 * ----------
 * v3.64.0 introduced `decisions_module_enabled` in oc_appconfig with a default
 * of '0'. v3.75.4 changed the getAppValue() default to '1', but getAppValue()
 * only falls through to the default when NO row exists. Any installation that
 * went live between v3.64.0 and v3.75.3 therefore has the key stored as '0'
 * and was unaffected by the v3.75.4 default change.
 *
 * Intent
 * ------
 * Force the NC admin-level toggle to '1' (enabled) for every installation that
 * applies this patch. The team-level toggle (`decisions_enabled` in
 * teamhub_decision_team) remains off by default, so no existing team's UI
 * changes — a team admin still has to explicitly enable it. The only visible
 * effect is that the toggle now appears as ON in the NC admin settings page.
 * NC admins who want to keep the module off can turn it back off after this
 * patch runs.
 *
 * Implementation
 * --------------
 * changeSchema() returns null — no schema change needed.
 * postSchemaChange() UPDATEs the stored value to '1'. If the row does not yet
 * exist (a freshly installed instance that never ran v3.64+), the UPDATE is a
 * no-op; getAppValue()'s default of '1' already covers that case.
 *
 * PostgreSQL + MySQL/MariaDB compatibility
 * ----------------------------------------
 * Plain UPDATE with no UPSERT syntax — safe on all supported DB engines.
 * The (appid, configkey) pair is unique-indexed, so UPDATE touches 0 or 1 row.
 */
class Version000377001Date20260614000000 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $db,
    ) {}

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        // No schema changes in this migration.
        return null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        error_log('[TeamHub][Migration][v3.77.1] postSchemaChange: setting decisions_module_enabled = 1');

        try {
            $qb = $this->db->getQueryBuilder();
            $affected = $qb->update('appconfig')
                ->set('configvalue', $qb->createNamedParameter('1'))
                ->where($qb->expr()->eq('appid',     $qb->createNamedParameter('teamhub')))
                ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('decisions_module_enabled')))
                ->executeStatement();

            error_log('[TeamHub][Migration][v3.77.1] postSchemaChange: rows updated = ' . $affected);

            // Log to the migration output so NC admin can see what happened.
            if ($affected > 0) {
                $output->info('TeamHub: decisions_module_enabled set to 1 (was explicitly 0 in database).');
            } else {
                $output->info('TeamHub: decisions_module_enabled not yet in database — getAppValue default of 1 applies.');
            }

        } catch (\Throwable $e) {
            // Non-fatal — getAppValue default of '1' means the module will
            // still be on for installations where the key was never written.
            // Log the error but do not abort the migration.
            $output->warning('TeamHub: Could not update decisions_module_enabled: ' . $e->getMessage());
            error_log('[TeamHub][Migration][v3.77.1] postSchemaChange failed: ' . $e->getMessage());
        }
    }
}
