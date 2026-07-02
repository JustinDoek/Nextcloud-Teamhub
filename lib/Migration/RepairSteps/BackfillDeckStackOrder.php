<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration\RepairSteps;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Backfill `order` on `deck_stacks` rows TeamHub created with NULL order.
 *
 * v3.85.0 and earlier created Deck stacks via a QB insert whose `order` value
 * was gated on `DbIntrospectionService::getTableColumns('deck_stacks')`
 * including the column in its returned list. When introspection fell through
 * to a degraded path that omitted the column, `order` was never written —
 * leaving the row with NULL order. The Deck UI refuses to rename a stack with
 * NULL order until the user manually reshuffles columns (which fires Deck's
 * own reorder code path and assigns proper values).
 *
 * This step is idempotent: it only touches rows where `order` IS NULL. For
 * each affected board it assigns a fresh sequence starting at
 * MAX(existing order) + 1, preserving any user-set ordering on that board.
 *
 * Skips cleanly when the Deck app's `deck_stacks` table is absent.
 */
class BackfillDeckStackOrder implements IRepairStep {

    public function __construct(
        private IDBConnection   $db,
        private LoggerInterface $logger,
    ) {}

    public function getName(): string {
        return 'Backfill missing order on Deck stacks created by TeamHub';
    }

    public function run(IOutput $output): void {
        // Deck app may not be installed — bail cleanly.
        try {
            $this->db->getQueryBuilder()
                ->select('id')
                ->from('deck_stacks')
                ->setMaxResults(1)
                ->executeQuery()
                ->closeCursor();
        } catch (\Throwable $e) {
            $output->info('BackfillDeckStackOrder: deck_stacks not available — skipping');
            return;
        }

        // Find rows with NULL order, grouped by board.
        try {
            $qb = $this->db->getQueryBuilder();
            $res = $qb->select('id', 'board_id')
                ->from('deck_stacks')
                ->where($qb->expr()->isNull('order'))
                ->orderBy('board_id', 'ASC')
                ->addOrderBy('id', 'ASC')
                ->executeQuery();

            /** @var array<int, int[]> $byBoard board_id => list of stack ids needing order */
            $byBoard = [];
            while ($row = $res->fetch()) {
                $boardId = (int)$row['board_id'];
                $byBoard[$boardId][] = (int)$row['id'];
            }
            $res->closeCursor();
        } catch (\Throwable $e) {
            $output->warning('BackfillDeckStackOrder: scan failed — ' . $e->getMessage());
            $this->logger->warning('[TeamHub][BackfillDeckStackOrder] scan failed: ' . $e->getMessage());
            return;
        }

        if (empty($byBoard)) {
            $output->info('BackfillDeckStackOrder: no rows to backfill');
            return;
        }

        $fixed = 0;
        $boards = 0;
        $now = time();

        foreach ($byBoard as $boardId => $stackIds) {
            // Find current max(order) for this board, ignoring our NULL rows.
            // Fetch all non-null orders and compute MAX in PHP to keep the
            // query trivial across MySQL/MariaDB/PostgreSQL without worrying
            // about reserved-word quoting in aggregate expressions.
            try {
                $qb = $this->db->getQueryBuilder();
                $maxRes = $qb->select('order')
                    ->from('deck_stacks')
                    ->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->isNotNull('order'))
                    ->executeQuery();
                $maxVal = null;
                while ($r = $maxRes->fetch()) {
                    $v = $r['order'] ?? null;
                    if (is_numeric($v) && ($maxVal === null || (int)$v > $maxVal)) {
                        $maxVal = (int)$v;
                    }
                }
                $maxRes->closeCursor();
                $nextOrder = $maxVal === null ? 0 : $maxVal + 1;
            } catch (\Throwable $e) {
                // Fall back to 0-based numbering if the lookup fails.
                $nextOrder = 0;
            }

            foreach ($stackIds as $stackId) {
                try {
                    $uqb = $this->db->getQueryBuilder();
                    $uqb->update('deck_stacks')
                        ->set('order', $uqb->createNamedParameter($nextOrder, IQueryBuilder::PARAM_INT))
                        ->set('last_modified', $uqb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                        ->where($uqb->expr()->eq('id', $uqb->createNamedParameter($stackId, IQueryBuilder::PARAM_INT)));
                    $uqb->executeStatement();
                    $fixed++;
                    $nextOrder++;
                } catch (\Throwable $e) {
                    $this->logger->warning('[TeamHub][BackfillDeckStackOrder] update failed', [
                        'stack_id' => $stackId,
                        'board_id' => $boardId,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }
            $boards++;
        }

        $output->info(sprintf(
            'BackfillDeckStackOrder: fixed %d stack(s) across %d board(s)',
            $fixed,
            $boards
        ));
    }
}
