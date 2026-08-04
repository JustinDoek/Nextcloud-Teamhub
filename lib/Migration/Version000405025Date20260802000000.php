<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.5.25 — add `teamhub_decisions.resolved_at`.
 *
 * `decided_at` is set when a proposal is **finalized** and deliberately not
 * overwritten when it is approved or denied, so the timeline keeps both
 * moments (`DecisionService::approve`, which says so at the point of
 * temptation). That was fine while nothing needed to know *when* a decision
 * was resolved — and then My Work's Completed section did, and a decision
 * finalized three weeks ago but approved this morning fell outside the
 * seven-day retention window and never appeared. Justin found it the day the
 * section shipped.
 *
 * `resolved_by` has existed since the decisions module; this is its missing
 * timestamp rather than a new concept.
 *
 * **Nullable, and no backfill.** Every existing approved or denied row keeps
 * `resolved_at = NULL` and readers fall back to `decided_at`, which is the
 * best information those rows have. Backfilling `decided_at` into
 * `resolved_at` would invent a precision the data never had and make old rows
 * indistinguishable from new ones.
 *
 * Postgres compatibility: BIGINT (as every other timestamp in this schema),
 * nullable with no default, no index — the column is read alongside rows
 * already selected by `team_id` + `status`, never filtered on alone. No new
 * constraint or index name, so nothing here can approach NC's 30-character
 * DBAL cap (SKILLS.md § Database identifier length).
 */
class Version000405025Date20260802000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_decisions')) {
            return null;
        }

        $table = $schema->getTable('teamhub_decisions');
        if ($table->hasColumn('resolved_at')) {
            return null;
        }

        $table->addColumn('resolved_at', Types::BIGINT, [
            'notnull' => false,
            'default' => null,
        ]);

        return $schema;
    }
}
