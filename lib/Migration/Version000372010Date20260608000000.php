<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.72.1 — Decisions: Level field, level-enabled toggle, category icon + description.
 *
 * 1. teamhub_decisions — new `level` column.
 *    Values: 'operational' (default) | 'tactical' | 'strategic'.
 *    Nullable with a DB default so existing rows implicitly read as operational.
 *    Validated at the service layer; never stored outside the allowed set.
 *
 * 2. teamhub_decision_team — new `decisions_level_enabled` SMALLINT column.
 *    0 = level field hidden (default); 1 = shown in composer + filters.
 *    Follows the SMALLINT-not-BOOLEAN pattern (DESIGN.md §2.4).
 *
 * 3. teamhub_dec_categories — new `icon` (emoji, ≤8 chars) and
 *    `description` (≤500 chars) columns.
 *    Both nullable — existing rows keep null until an admin sets them.
 *    The `icon` is stored as a raw emoji string (1–2 Unicode code points);
 *    no validation is done at the DB level; the service caps length.
 *
 * Table-name budget check (DESIGN.md §2.5, ≤27 chars):
 *   teamhub_decisions         = 18 chars ✓  (altered, not created)
 *   teamhub_decision_team     = 20 chars ✓  (altered, not created)
 *   teamhub_dec_categories    = 22 chars ✓  (altered, not created)
 */
class Version000372010Date20260608000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // =====================================================================
        // 1. teamhub_decisions — add `level`
        // =====================================================================
        if ($schema->hasTable('teamhub_decisions')) {
            $table = $schema->getTable('teamhub_decisions');
            if (!$table->hasColumn('level')) {
                $table->addColumn('level', Types::STRING, [
                    'length'   => 16,
                    'notnull'  => false,
                    'default'  => 'operational',
                ]);
            }
        }

        // =====================================================================
        // 2. teamhub_decision_team — add `decisions_level_enabled`
        // =====================================================================
        if ($schema->hasTable('teamhub_decision_team')) {
            $table = $schema->getTable('teamhub_decision_team');
            if (!$table->hasColumn('decisions_level_enabled')) {
                $table->addColumn('decisions_level_enabled', Types::SMALLINT, [
                    'notnull' => true,
                    'default' => 0,
                ]);
            }
        }

        // =====================================================================
        // 3. teamhub_dec_categories — add `icon` and `description`
        // =====================================================================
        if ($schema->hasTable('teamhub_dec_categories')) {
            $table = $schema->getTable('teamhub_dec_categories');
            if (!$table->hasColumn('icon')) {
                $table->addColumn('icon', Types::STRING, [
                    'length'  => 64,
                    'notnull' => false,
                    'default' => null,
                ]);
            }
            if (!$table->hasColumn('description')) {
                $table->addColumn('description', Types::STRING, [
                    'length'  => 500,
                    'notnull' => false,
                    'default' => null,
                ]);
            }
        }

        return $schema;
    }
}
