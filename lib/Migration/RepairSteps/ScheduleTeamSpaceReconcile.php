<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration\RepairSteps;

use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Ask for a team-space reconcile pass at the next cron tick (v4.10.1).
 *
 * The conversion of existing team folders to Nextcloud 35 team spaces is a
 * daily job, not a migration — see `TeamSpaceReconcileService` for why. This
 * step only shortens the wait after an upgrade: it makes sure the job is in
 * the job list (post-migration repair steps run *before* Nextcloud registers
 * an app's background jobs) and resets its last run, so cron picks it up on
 * its next pass rather than a day later.
 *
 * Does no conversion work itself, on purpose: during `occ upgrade` the Team
 * folders provider may not be registered at all, and a repair step that
 * throws leaves the instance in maintenance mode. Names the job by its
 * class string so this file, like every migration, depends on nothing that
 * a later TeamHub is free to move (`npm run check:migrations`).
 */
class ScheduleTeamSpaceReconcile implements IRepairStep {

    private const JOB_CLASS = 'OCA\\TeamHub\\BackgroundJob\\TeamSpaceReconcileJob';

    public function __construct(
        private IJobList        $jobList,
        private LoggerInterface $logger,
    ) {}

    public function getName(): string {
        return 'Schedule the TeamHub team-space reconcile pass';
    }

    public function run(IOutput $output): void {
        try {
            if (!$this->jobList->has(self::JOB_CLASS, null)) {
                $this->jobList->add(self::JOB_CLASS);
                $output->info('Team-space reconcile job registered; it runs at the next cron pass.');
                return;
            }
            foreach ($this->jobList->getJobsIterator(self::JOB_CLASS, 1, 0) as $job) {
                $this->jobList->resetBackgroundJob($job);
                $output->info('Team-space reconcile job reset; it runs at the next cron pass.');
                return;
            }
        } catch (\Throwable $e) {
            // Never fail the upgrade over scheduling: the daily interval
            // brings the pass within 24 hours anyway.
            $this->logger->warning('[TeamHub][ScheduleTeamSpaceReconcile] could not schedule the reconcile pass', [
                'error' => $e->getMessage(), 'app' => 'teamhub',
            ]);
            $output->warning('Team-space reconcile job could not be scheduled: ' . $e->getMessage());
        }
    }
}
