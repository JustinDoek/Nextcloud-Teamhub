<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.97.0 — Execution-phase dashboard (Track E Session 6).
 *
 * Adds `posted_at` to teamhub_milestones — the timestamp at which the hourly
 * MilestoneAutoPostJob posted a "milestone reached" system message to the
 * team stream. NULL = not yet posted (still pending). Combined with the
 * existing `milestone_date`:
 *   - milestone_date <= now AND posted_at IS NULL  → auto-post fires
 *   - milestone_date <= now AND posted_at IS NOT NULL → already posted, skip
 *   - milestone_date > now                          → future, skip
 *
 * BIGINT (not INT) to match the Unix-timestamp convention the rest of the
 * schema uses (created_at, milestone_date, worked_at, etc.).
 */
class Version000397000Date20260707000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_milestones')) {
            $table = $schema->getTable('teamhub_milestones');
            if (!$table->hasColumn('posted_at')) {
                $table->addColumn('posted_at', Types::BIGINT, [
                    'notnull' => false,
                    'default' => null,
                ]);
            }
        }

        return $schema;
    }
}
