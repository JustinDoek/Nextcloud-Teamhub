<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCA\TeamHub\AppInfo\Application;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;

/**
 * v4.5.40 — remove My Work's follow/mute state.
 *
 * Follow shipped in v4.5.21 as a tri-state on `teamhub_mywork_state`:
 * 0 default, 1 followed, 2 muted. It is removed entirely — see ActionType and
 * MyWorkState for the reasoning. The short version: the only affordance that
 * could reach state 2 was labelled as the opposite of Follow, and nothing in
 * the UI could ever bring an item back out of it.
 *
 * Two steps, in this order, and the order is the point:
 *
 *  1. **preSchemaChange** deletes the rows the column was the only reason for.
 *     A row whose `snooze_until` is 0 exists *because* someone followed or
 *     muted that item; with the column gone it would say nothing at all, and
 *     `purgeExpired()` (which now deletes anything not currently snoozed)
 *     would collect it on the next maintenance run anyway. Doing it here
 *     rather than after means the count reported to the operator is real —
 *     once the column is dropped there is no way to tell those rows apart.
 *     Rows with a live snooze keep their snooze; only the follow half is lost.
 *  2. **changeSchema** drops `follow_state`.
 *
 * **Items hidden by a mute come back on their own.** Nothing restores them,
 * because nothing has to: `MyWorkService` no longer reads the column, so as
 * soon as the new code is in place a muted item is just an item again. There
 * is no data to recover and therefore no recovery query.
 *
 * The drop is guarded by `hasColumn`, so a re-run is a no-op, and an install
 * that never got 4.5.21 is unaffected.
 *
 * Postgres compatibility: `DROP COLUMN` is plain DDL on both engines and the
 * column carries no index, constraint or default that would need dropping
 * first — `th_mws_snz_idx` is on `snooze_until`, the unique index is on
 * (user_id, provider_id, item_id). Nothing references `follow_state`.
 */
class Version000405040Date20260804000000 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection   $db,
        private LoggerInterface $logger,
    ) {}

    /**
     * Delete rows that only ever meant "followed" or "muted".
     *
     * Runs before the schema change so the operator gets a true count. A
     * failure here must not block the upgrade: the rows are harmless dead
     * weight, and `purgeExpired()` sweeps them on the next run.
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_mywork_state')) {
            return;
        }

        try {
            $qb      = $this->db->getQueryBuilder();
            $deleted = $qb->delete('teamhub_mywork_state')
                ->where($qb->expr()->eq(
                    'snooze_until',
                    $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                ))
                ->executeStatement();

            $output->info(sprintf(
                'teamhub_mywork_state: removed %d follow/mute-only row(s). '
                . 'Any item they were hiding is visible again in My Work.',
                $deleted,
            ));
        } catch (\Throwable $e) {
            // Not fatal. The column is going away either way, and the rows
            // that survive are inert until the daily purge collects them.
            $output->warning(
                'teamhub_mywork_state: could not clear follow/mute rows ('
                . $e->getMessage() . '). They will be purged by the daily job.',
            );
            $this->logger->warning('[TeamHub][Migration 4.5.40] Follow-row cleanup failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_mywork_state')) {
            return null;
        }

        $table = $schema->getTable('teamhub_mywork_state');
        if (!$table->hasColumn('follow_state')) {
            return null;
        }

        $table->dropColumn('follow_state');
        $output->info('teamhub_mywork_state: dropped follow_state column');

        return $schema;
    }
}
