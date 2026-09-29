<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.8.1 — Fix BOOLEAN NOT NULL columns that cannot store false.
 *
 * Two columns had Types::BOOLEAN + notnull => true, which causes Doctrine
 * to reject PHP false on MySQL/MariaDB with the error:
 *   "Column X is type Bool and also NotNull, so it can not store false."
 *
 * Affected columns fixed here (existing installs):
 *   teamhub_team_apps.enabled               → SMALLINT, default 1
 *   teamhub_integ_registry.is_builtin → SMALLINT, default 0
 *
 * Fresh installs are covered by the corrected base migrations
 * (Version000200000 and Version000209000). This migration no-ops gracefully
 * if the columns are already the correct type.
 *
 * v4.10.3 — two things learnt from the first run of the whole chain on an
 * empty Nextcloud 35 / PostgreSQL 18 (2026-09-20):
 *   1. Nextcloud 35 hides Doctrine behind OCP\DB\Schema\IColumn: getColumn()
 *      returns OC\DB\Schema\Column (setType(string|ColumnType), no
 *      setOptions()); on ≤ 34 it is still the Doctrine Column (setType(Type)).
 *      retypeToSmallint() branches on the object it is handed, so the step
 *      runs on every supported version; getType()->getName() works on both.
 *   2. The schema-wrapper path never worked on PostgreSQL: the generated
 *      `ALTER … TYPE SMALLINT` carries no USING clause and Postgres refuses
 *      to cast boolean → smallint implicitly (SQLSTATE 42804). MySQL/MariaDB
 *      cast implicitly, which is why it went unnoticed. On Postgres the
 *      conversion is therefore issued in postSchemaChange() with an explicit
 *      `USING (col::int)`, the same raw-DDL shape as Version000300901.
 */
class Version000300801Date20260421000000 extends SimpleMigrationStep {

    /** table (unprefixed) => [column, default] */
    private const COLUMNS = [
        'teamhub_team_apps'      => ['enabled', 1],
        'teamhub_integ_registry' => ['is_builtin', 0],
    ];

    public function __construct(private IDBConnection $db, private IConfig $config) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema     = $schemaClosure();
        $changed    = false;
        $isPostgres = $this->db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES;

        foreach (self::COLUMNS as $tableName => [$columnName, $default]) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }
            $table = $schema->getTable($tableName);
            if (!$table->hasColumn($columnName)) {
                continue;
            }
            $column = $table->getColumn($columnName);

            if ($column->getType()->getName() !== 'boolean') {
                $output->info("{$tableName}.{$columnName} already correct type — skipping");
                continue;
            }

            if ($isPostgres) {
                $output->info("{$tableName}.{$columnName} is BOOLEAN on PostgreSQL — converted in postSchemaChange");
                continue;
            }

            $this->retypeToSmallint($column, $default);
            $output->info("{$tableName}.{$columnName} changed BOOLEAN → SMALLINT (notnull, default {$default})");
            $changed = true;
        }

        return $changed ? $schema : null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_POSTGRES) {
            return;
        }

        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $prefix = $this->config->getSystemValue('dbtableprefix', 'oc_');

        foreach (self::COLUMNS as $tableName => [$columnName, $default]) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }
            $table = $schema->getTable($tableName);
            if (!$table->hasColumn($columnName)) {
                continue;
            }
            if ($table->getColumn($columnName)->getType()->getName() !== 'boolean') {
                continue;
            }

            $fullTable = $prefix . $tableName;
            $this->db->executeStatement(
                "ALTER TABLE \"{$fullTable}\""
                . " ALTER COLUMN \"{$columnName}\" DROP DEFAULT,"
                . " ALTER COLUMN \"{$columnName}\" TYPE SMALLINT USING (\"{$columnName}\"::int),"
                . " ALTER COLUMN \"{$columnName}\" SET DEFAULT {$default},"
                . " ALTER COLUMN \"{$columnName}\" SET NOT NULL"
            );
            $output->info("{$tableName}.{$columnName} changed BOOLEAN → SMALLINT (notnull, default {$default}) via USING cast");
        }
    }

    /**
     * SMALLINT NOT NULL DEFAULT $default, on whichever column object the
     * schema wrapper hands out. setNotnull() and setDefault() exist on both;
     * only setType() differs (Doctrine wants a Type instance, OCP a string).
     *
     * @param object $column \Doctrine\DBAL\Schema\Column (NC ≤ 34) or \OCP\DB\Schema\IColumn (NC ≥ 35)
     */
    private function retypeToSmallint(object $column, int $default): void {
        if ($column instanceof \Doctrine\DBAL\Schema\Column) {
            $column->setType(\Doctrine\DBAL\Types\Type::getType(Types::SMALLINT));
        } else {
            $column->setType(Types::SMALLINT);
        }
        $column->setNotnull(true);
        $column->setDefault($default);
    }
}
