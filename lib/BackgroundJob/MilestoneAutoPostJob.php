<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\MilestoneAutoPostService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly sweep that posts a "milestone reached" system message to the team
 * stream once a milestone's date has passed (v3.97.0, Track E Session 6).
 *
 * Runs every hour. Latency is up to 60 minutes between the milestone's
 * midnight timestamp and the announcement — acceptable for a
 * team-management artifact. Sweep body lives in MilestoneAutoPostService;
 * this job is the scheduler + logger only.
 */
class MilestoneAutoPostJob extends TimedJob {

    public function __construct(
        ITimeFactory                              $time,
        private readonly MilestoneAutoPostService $autoPost,
        private readonly LoggerInterface          $logger,
    ) {
        parent::__construct($time);
        // Same cadence as TalkMembershipReconcileJob / ResourceDiscoveryJob.
        $this->setInterval(3600);
        // The service stamps posted_at inside the same iteration, so a
        // parallel run would only ever double-post if it caught a milestone
        // between "read as due" and "write posted_at". Cheaper to enforce
        // single-runner than to add row-level locking.
        $this->setAllowParallelRuns(false);
        // Time-insensitive: catch up after downtime rather than wait a full hour.
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        try {
            $result = $this->autoPost->sweep();
            if ($result['scanned'] > 0 || $result['errors'] > 0) {
                $this->logger->info('[TeamHub][MilestoneAutoPostJob] sweep complete', array_merge($result, [
                    'app' => Application::APP_ID,
                ]));
            }
        } catch (\Throwable $e) {
            // sweep() catches per-milestone failures internally; a throw here
            // means something systemic (DB down, mapper wiring broken).
            $this->logger->error('[TeamHub][MilestoneAutoPostJob] sweep failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }
    }
}
