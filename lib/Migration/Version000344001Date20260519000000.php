<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.45.0 bugfix migration (shipped as 3.44.1).
 *
 * Fixes two issues reported after v3.44.0 testing:
 *
 * FIX 1 — Built-in presence type is_busy flags were wrong:
 *   - 'office':          is_busy was 1 (busy), should be 0 (free — reachable)
 *   - 'non_working_day': is_busy was 0 (free), should be 1 (busy — unavailable)
 *
 *   The repair step (SeedPresenceTypes) is idempotent on slug presence but
 *   does not update structural flags on existing rows. This migration does a
 *   direct UPDATE by slug for both affected types, matching only rows whose
 *   current is_busy value is the wrong one (safe to re-run — no-op if already
 *   correct).
 *
 * FIX 2 — half_day column had no DEFAULT on teamhub_presence_template and
 *   teamhub_presence_slots, causing MySQL error 1364 ("Field 'half_day'
 *   doesn't have a default value") when QBMapper's INSERT omitted the column
 *   (which can happen when the entity property equals its PHP-level zero
 *   default and the ORM skips unchanged fields).
 *
 *   Add DEFAULT 0 to both columns. Idempotent — we check whether the column
 *   already has a default before modifying.
 */
class Version000344001Date20260519000000 extends SimpleMigrationStep {

    public function __construct(private IDBConnection $db) {}

    /**
     * Schema change: add DEFAULT 0 to half_day on both presence tables.
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        foreach (['teamhub_presence_template', 'teamhub_presence_slots'] as $tableName) {
            if (!$schema->hasTable($tableName)) {
                $output->warning("Version000344001: {$tableName} not found — skipping");
                continue;
            }

            $table = $schema->getTable($tableName);

            // Fix half_day — no DEFAULT caused MySQL 1364 on morning saves.
            if ($table->hasColumn('half_day')) {
                $col = $table->getColumn('half_day');
                if ($col->getDefault() === null) {
                    $output->info("Version000344001: setting DEFAULT 0 on {$tableName}.half_day");
                    $col->setDefault(0);
                    $changed = true;
                }
            }

            // Fix day_of_week — same root cause as half_day (Monday = value 0).
            // Only exists on teamhub_presence_template.
            if ($table->hasColumn('day_of_week')) {
                $col = $table->getColumn('day_of_week');
                if ($col->getDefault() === null) {
                    $output->info("Version000344001: setting DEFAULT 0 on {$tableName}.day_of_week");
                    $col->setDefault(0);
                    $changed = true;
                }
            }
        }

        return $changed ? $schema : null;
    }

    /**
     * Post-schema: fix the is_busy flags for the two built-in types.
     *
     * The slug-keyed repair step only INSERTs missing rows; it never updates
     * structural flags on rows that already exist. This postSchemaChange step
     * does the targeted UPDATE so existing installs get the corrected values
     * without waiting for a full re-seed.
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $fixes = [
            // office was is_busy=1 (busy), correct value is 0 (free — reachable)
            ['slug' => 'office',          'correct_is_busy' => 0, 'wrong_is_busy' => 1],
            // non_working_day was is_busy=0 (free), correct value is 1 (busy — unavailable)
            ['slug' => 'non_working_day', 'correct_is_busy' => 1, 'wrong_is_busy' => 0],
        ];

        foreach ($fixes as $fix) {
            try {
                $qb = $this->db->getQueryBuilder();
                $affected = $qb->update('teamhub_presence_types')
                    ->set('is_busy', $qb->createNamedParameter($fix['correct_is_busy'], IQueryBuilder::PARAM_INT))
                    ->where($qb->expr()->eq('slug', $qb->createNamedParameter($fix['slug'])))
                    ->andWhere($qb->expr()->eq(
                        'is_busy',
                        $qb->createNamedParameter($fix['wrong_is_busy'], IQueryBuilder::PARAM_INT)
                    ))
                    ->executeStatement();

                if ($affected > 0) {
                    $output->info(sprintf(
                        'Version000344001: fixed is_busy for "%s" (%d → %d)',
                        $fix['slug'],
                        $fix['wrong_is_busy'],
                        $fix['correct_is_busy']
                    ));
                } else {
                    $output->info(sprintf(
                        'Version000344001: "%s" already correct or not found — skipping',
                        $fix['slug']
                    ));
                }
            } catch (\Throwable $e) {
                // Table might not exist (pre-3.42.0 install). Non-fatal.
                $output->warning(sprintf(
                    'Version000344001: could not fix "%s": %s',
                    $fix['slug'],
                    $e->getMessage()
                ));
            }
        }
    }
}
