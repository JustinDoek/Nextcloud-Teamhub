<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration\RepairSteps;

use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Remove retired workflow settings: the "group that processes team
 * requests" (v4.10.23) and the quota engine switch (v4.10.29).
 *
 * Step 3 of the team request — *who creates the requested team* — used to
 * be a Nextcloud group an administrator picked on Admin -> TeamHub, default
 * `admin`. Requesting a new team is now one of the six services in the
 * Nextcloud Services bundle, and the service team that holds the bundle
 * does that step. There is one answer to the question again, and it is not
 * a setting (DESIGN.md 2.146).
 *
 * The stored value would otherwise sit in `oc_appconfig` forever, read by
 * nothing, and would quietly come back into force for anybody who
 * downgraded and upgraded again.
 *
 * Running requests are not touched: an actor is copied onto the step row
 * when the workflow is created, so a request already with the
 * administrators stays with them and finishes there.
 *
 * `workflow_engine_quota` (v4.10.17) kept the engine's quota request dark
 * while the ledger ran the live one. Since v4.10.29 the quota request is a
 * Nextcloud service on the engine and the ledger version is gone, so the
 * switch has nothing left to choose between.
 *
 * Literal strings rather than application constants, like every file under
 * `lib/Migration/` (`npm run check:migrations`).
 */
class DropRetiredWorkflowSettings implements IRepairStep {

    private const APP_ID = 'teamhub';
    private const KEYS   = [
        'workflow_team_request_group',
        'workflow_engine_quota',
    ];

    public function __construct(
        private IConfig         $config,
        private LoggerInterface $logger,
    ) {}

    public function getName(): string {
        return 'Remove retired TeamHub workflow settings';
    }

    public function run(IOutput $output): void {
        try {
            foreach (self::KEYS as $key) {
                if ($this->config->getAppValue(self::APP_ID, $key, '') === '') {
                    continue;
                }
                $this->config->deleteAppValue(self::APP_ID, $key);
                $output->info('Retired workflow setting removed: ' . $key);
            }
        } catch (\Throwable $e) {
            // Never fail an upgrade over a value nothing reads any more.
            $this->logger->warning('[TeamHub][DropRetiredWorkflowSettings] could not remove a retired setting', [
                'error' => $e->getMessage(), 'app' => 'teamhub',
            ]);
        }
    }
}
