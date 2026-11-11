<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\FederatedTeamCleanupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily sweep for synced copies of federated teams whose owner has deleted
 * the original (v4.14.9, DESIGN §2.253).
 *
 * The rules live in {@see FederatedTeamCleanupService}: an unreachable owner
 * decides nothing, and absence is only believed when the owner answered.
 * This class is the schedule and nothing else.
 *
 * **Daily, not hourly.** The condition it looks for is permanent — a copy
 * stranded by a missed `CircleDestroy` stays stranded until something removes
 * it — so there is nothing to gain by checking often, and each pass costs one
 * signed request per federated team to another server. A stale team for a few
 * hours is a cosmetic problem; a job that hammers a peer is not.
 *
 * Off by default: the service answers `skipped` unless the administrator has
 * switched federated teams on, so an instance that does not use the feature
 * never makes an outbound request because of this job.
 *
 * `setAllowParallelRuns(false)` because two passes would probe the same peers
 * and could both decide to delete the same rows.
 */
class FederatedTeamCleanupJob extends TimedJob {

    public function __construct(
        ITimeFactory                                 $time,
        private readonly FederatedTeamCleanupService $cleanupService,
        private readonly LoggerInterface             $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(86400);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
        $this->setAllowParallelRuns(false);
    }

    protected function run(mixed $argument): void {
        try {
            $summary = $this->cleanupService->run();

            if ($summary['skipped']) {
                return;
            }

            if ($summary['removed'] === 0 && $summary['unreachable'] === []) {
                $this->logger->debug('[TeamHub][FederatedTeamCleanupJob] nothing to do', [
                    'checked' => $summary['checked'],
                    'app'     => Application::APP_ID,
                ]);
                return;
            }

            // An unreachable peer is reported at info rather than warning: it
            // is the designed outcome of rule 3, not a fault, and a peer that
            // is down for a day should not fill an administrator's log with
            // warnings about a job that correctly did nothing.
            $this->logger->info('[TeamHub][FederatedTeamCleanupJob] pass complete', [
                'checked'     => $summary['checked'],
                'removed'     => $summary['removed'],
                'kept'        => $summary['kept'],
                'unreachable' => $summary['unreachable'],
                'app'         => Application::APP_ID,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][FederatedTeamCleanupJob] pass failed', [
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
        }
    }
}
