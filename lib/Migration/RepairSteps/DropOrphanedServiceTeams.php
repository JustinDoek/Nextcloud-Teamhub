<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration\RepairSteps;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Remove service-team rows whose team no longer exists (v4.10.23).
 *
 * Phase 5 never cleaned up after a deleted team: `deleteTeam()` removed the
 * apps, the OpenProject link and the provisioning ledger, but nothing
 * touched `teamhub_service_team` or `teamhub_service_catalog`. That was
 * survivable while a stale row only meant a catalogue entry nothing could
 * reach.
 *
 * It stopped being survivable in v4.10.23, when the Nextcloud services
 * became **one instance-wide claim**: a row left behind by a deleted team
 * goes on holding them, so every other team's checkbox is greyed out — and
 * named after a team that no longer exists, which renders as an empty name
 * in the middle of the sentence. Found on the test instance the day the
 * feature shipped.
 *
 * v4.10.23 also fixed the cause (`TeamService::deleteTeam()` now removes
 * the rows, unlicensed, because housekeeping must not depend on a licence).
 * This step is the repair of what earlier versions already left behind —
 * which is what a repair step is for, and why it is not in the migration:
 * on a fresh install there is nothing to repair.
 *
 * Literal strings rather than application constants, like every file under
 * `lib/Migration/`.
 */
class DropOrphanedServiceTeams implements IRepairStep {

    public function __construct(
        private IDBConnection   $db,
        private LoggerInterface $logger,
    ) {}

    public function getName(): string {
        return 'Remove TeamHub service-team rows whose team no longer exists';
    }

    public function run(IOutput $output): void {
        try {
            $orphans = $this->orphanedTeamIds();
            if ($orphans === []) {
                return;
            }

            foreach (['teamhub_service_catalog', 'teamhub_service_team'] as $table) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete($table)
                    ->where($qb->expr()->in(
                        'team_id',
                        $qb->createNamedParameter($orphans, IQueryBuilder::PARAM_STR_ARRAY),
                    ));
                $qb->executeStatement();
            }

            $output->info(sprintf(
                'Removed %d service-team row(s) for deleted teams; the Nextcloud services are claimable again.',
                count($orphans),
            ));
        } catch (\Throwable $e) {
            // Never fail an upgrade over housekeeping.
            $this->logger->warning('[TeamHub][DropOrphanedServiceTeams] could not remove orphaned rows', [
                'error' => $e->getMessage(), 'app' => 'teamhub',
            ]);
        }
    }

    /**
     * Service teams whose `team_id` is not a circle any more.
     *
     * Read in two queries rather than one NOT IN join: the circle table is
     * another app's, the service-team table has a handful of rows, and a
     * join across the two is not worth the coupling.
     *
     * @return string[]
     */
    private function orphanedTeamIds(): array {
        if (!$this->tableExists('teamhub_service_team')) {
            return [];
        }

        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('team_id')->from('teamhub_service_team')->executeQuery();
        $teamIds = [];
        while ($row = $res->fetch()) {
            $id = (string)$row['team_id'];
            if ($id !== '') {
                $teamIds[$id] = true;
            }
        }
        $res->closeCursor();
        if ($teamIds === []) {
            return [];
        }

        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('unique_id')
            ->from('circles_circle')
            ->where($qb->expr()->in(
                'unique_id',
                $qb->createNamedParameter(array_keys($teamIds), IQueryBuilder::PARAM_STR_ARRAY),
            ))
            ->executeQuery();
        while ($row = $res->fetch()) {
            unset($teamIds[(string)$row['unique_id']]);
        }
        $res->closeCursor();

        return array_keys($teamIds);
    }

    private function tableExists(string $table): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('team_id')->from($table)->setMaxResults(1)->executeQuery()->closeCursor();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
