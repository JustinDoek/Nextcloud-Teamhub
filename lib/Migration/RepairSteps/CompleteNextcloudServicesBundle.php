<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration\RepairSteps;

use OCA\TeamHub\Constants\ServiceCatalogue;
use OCA\TeamHub\Db\ServiceCatalogEntry;
use OCA\TeamHub\Db\ServiceCatalogEntryMapper;
use OCA\TeamHub\Db\ServiceTeamMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Give the team that holds the Nextcloud services every service the bundle
 * now has (v4.10.29).
 *
 * The claim writes one catalogue row per service in `ServiceCatalogue::SERVICES`
 * (`ServiceTeamService::claimNextcloudServices()`), so a team that claimed
 * the bundle when it had six services has six rows. v4.10.29 added the
 * seventh — the quota request — and without this step the holder would not
 * offer it until an admin released and claimed again, which nobody would
 * know to do. The claim *means* "the whole bundle"; this makes the rows say
 * so again.
 *
 * Insert-when-absent, per team, per service: an existing row is never
 * touched, so a service an administrator disabled stays disabled, and a run
 * on an instance where nothing is missing writes nothing. **Not
 * licence-gated**, like `ServiceTeamService::remove()`: the rows are what a
 * licence will read when it returns, and a lapsed licence must not leave a
 * holder that offers six of seven services for ever.
 *
 * **v4.10.45 — and nothing it no longer has.** A row for a retired service
 * (`ServiceCatalogue::RETIRED`: *Request a team modification*) is removed,
 * so the holder offers *Request more time for a team* in its place. The
 * requests made on the retired service keep running: its definition stays
 * registered.
 *
 * Application constants on purpose — a repair step runs with the code it
 * ships with (`/migrations` §2a); it is the live bundle that must be
 * complete, not the one of the version that wrote the step. Runs before
 * `ImportLedgerQuotaRequests`, which needs the holder to offer the quota
 * request to hand open requests to its queue.
 */
class CompleteNextcloudServicesBundle implements IRepairStep {

    public function __construct(
        private ServiceTeamMapper         $teams,
        private ServiceCatalogEntryMapper $catalog,
        private ITimeFactory              $timeFactory,
        private LoggerInterface           $logger,
    ) {}

    public function getName(): string {
        return 'Offer every Nextcloud service from the TeamHub team that holds them';
    }

    public function run(IOutput $output): void {
        try {
            $added   = 0;
            $removed = 0;
            foreach ($this->teams->findAll(true) as $team) {
                $teamId = $team->getTeamId();
                $rows   = [];
                $order  = 0;
                foreach ($this->catalog->findByTeam($teamId) as $entry) {
                    if (in_array($entry->getServiceKey(), ServiceCatalogue::RETIRED, true)) {
                        // Counted as the bundle's below: a holder whose only
                        // row is a retired one still holds it.
                        $rows[$entry->getServiceKey()] = true;
                        $this->catalog->delete($entry);
                        $removed++;
                        continue;
                    }
                    $rows[$entry->getServiceKey()] = true;
                    $order = max($order, (int)$entry->getSortOrder());
                }
                // Not a holder: a service team offering none of the bundle's
                // services is none of this step's business.
                if (array_intersect(array_keys($rows), array_merge(ServiceCatalogue::SERVICES, ServiceCatalogue::RETIRED)) === []) {
                    continue;
                }
                foreach (ServiceCatalogue::SERVICES as $serviceKey) {
                    if (isset($rows[$serviceKey])) {
                        continue;
                    }
                    $entry = new ServiceCatalogEntry();
                    $entry->setTeamId($teamId);
                    $entry->setServiceKey($serviceKey);
                    $entry->setDefinitionKey((string)ServiceCatalogue::definitionFor($serviceKey));
                    $entry->setEnabled(1);
                    $entry->setSortOrder(++$order);
                    $entry->setUpdatedAt($this->timeFactory->getTime());
                    $this->catalog->insert($entry);
                    $added++;
                }
            }
            if ($added > 0) {
                $output->info(sprintf('Added %d Nextcloud service(s) to the team that holds them.', $added));
            }
            if ($removed > 0) {
                $output->info(sprintf('Removed %d retired Nextcloud service(s) from the team that holds them.', $removed));
            }
        } catch (\Throwable $e) {
            // Never fail an upgrade over this: the holder can release and
            // claim again, which writes the whole bundle.
            $this->logger->warning('[TeamHub][CompleteNextcloudServicesBundle] could not complete the bundle', [
                'error' => $e->getMessage(), 'app' => 'teamhub',
            ]);
        }
    }
}
