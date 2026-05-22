<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.47.0 — Fix MySQL 1364 "Field has no default value" on day_of_week
 * and half_day in teamhub_presence_template and teamhub_presence_slots.
 *
 * BACKGROUND
 * ----------
 * The previous repair (Version000344001::changeSchema) used Doctrine DBAL's
 * ISchemaWrapper diff engine to set column defaults. This is unreliable for
 * modifying existing column defaults on MySQL/MariaDB — DBAL often does not
 * emit the ALTER COLUMN because it cannot detect the change in the diff.
 *
 * FIX
 * ---
 * postSchemaChange emits explicit ALTER TABLE … MODIFY COLUMN statements.
 * The DB table prefix is read from config (the established TeamHub pattern
 * used in Version000336200 and Version000300901).
 *
 * Only runs on MySQL/MariaDB — PostgreSQL was never affected by error 1364.
 */
class Version000346000Date20260519000000 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $db,
        private IConfig       $config,
    ) {}

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        return null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $platform     = $this->db->getDatabasePlatform();
        $platformName = get_class($platform);

        if (!str_contains($platformName, 'MySQL') && !str_contains($platformName, 'MariaDb')) {
            $output->info('Version000346000: non-MySQL platform — skipping (not needed)');
            return;
        }

        $this->fixMysql($output);
    }

    private function fixMysql(IOutput $output): void {
        // Use the same prefix-lookup pattern as Version000336200 and Version000300901.
        $prefix = $this->config->getSystemValue('dbtableprefix', 'oc_');

        $fixes = [
            ["{$prefix}teamhub_presence_template", 'day_of_week', 'SMALLINT NOT NULL DEFAULT 0'],
            ["{$prefix}teamhub_presence_template", 'half_day',    'SMALLINT NOT NULL DEFAULT 0'],
            ["{$prefix}teamhub_presence_slots",    'half_day',    'SMALLINT NOT NULL DEFAULT 0'],
        ];

        foreach ($fixes as [$table, $column, $ddl]) {
            try {
                // Verify the table exists before attempting the ALTER.
                $check = $this->db->executeQuery("SHOW TABLES LIKE '{$table}'");
                $exists = $check->rowCount() > 0;
                $check->closeCursor();

                if (!$exists) {
                    $output->warning("Version000346000: table {$table} not found — skipping");
                    continue;
                }

                $this->db->executeStatement(
                    "ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$ddl}"
                );
                $output->info("Version000346000: fixed `{$table}`.`{$column}` DEFAULT 0");
            } catch (\Throwable $e) {
                $output->warning(sprintf(
                    'Version000346000: could not fix `%s`.`%s`: %s',
                    $table, $column, $e->getMessage()
                ));
            }
        }
    }
}
