<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\ResourceDiscoveryService;
use OCA\TeamHub\Service\TeamRegistryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly cron backstop for resource discovery reconciliation.
 *
 * Iterates all teams and reconciles their teamhub_team_app_resources rows
 * against live NC ACL/share tables. This catches drift that render-time
 * reconciliation misses (teams nobody visited since the last drift).
 *
 * Render-time reconciliation (ResourceService::getTeamResources) handles
 * the viewed team immediately. This job is the safety net for everything else.
 */
class ResourceDiscoveryJob extends TimedJob {

    public function __construct(
        ITimeFactory                          $time,
        private readonly ResourceDiscoveryService $discoveryService,
        private readonly TeamRegistryService      $teamRegistry,
        private readonly LoggerInterface          $logger,
    ) {
        parent::__construct($time);
        // Run once per hour.
        $this->setInterval(3600);
        // Do not run if another instance is already executing.
        $this->setAllowParallelRuns(false);
    }

    protected function run(mixed $argument): void {
        $this->logger->debug('[TeamHub][ResourceDiscoveryJob] starting', [
            'app' => Application::APP_ID,
        ]);

        try {
            $this->discoveryService->reconcileAllTeams();
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][ResourceDiscoveryJob] reconcileAllTeams threw', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
        }

        // v4.10.6 — the same hourly desired-state pass for the CFG_APP lock
        // that keeps the Teams page from deleting a TeamHub team. The bit is
        // shared with Collectives and has no event when it changes (DESIGN
        // §2.103 — Circles offers no config hook), so a poll is the only way
        // to notice it gone.
        try {
            $this->teamRegistry->relockAll();
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][ResourceDiscoveryJob] relockAll threw', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
        }

        $this->logger->debug('[TeamHub][ResourceDiscoveryJob] done', [
            'app' => Application::APP_ID,
        ]);
    }
}
