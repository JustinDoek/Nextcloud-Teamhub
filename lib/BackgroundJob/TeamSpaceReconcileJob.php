<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\TeamSpaceReconcileService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily pass that makes every TeamHub team's folder its Nextcloud 35 team
 * space (v4.10.1) — see `TeamSpaceReconcileService` for the rules and for
 * why this is a job rather than a migration.
 *
 * Idempotent; the run after a full conversion does nothing but log. On
 * Nextcloud 33/34 the service answers "skipped" before touching anything.
 * `ScheduleTeamSpaceReconcile` (a post-migration repair step) resets this
 * job at every TeamHub upgrade so the first pass follows the upgrade at the
 * next cron tick instead of a day later.
 */
class TeamSpaceReconcileJob extends TimedJob {

    public function __construct(
        ITimeFactory                                $time,
        private readonly TeamSpaceReconcileService $reconcileService,
        private readonly LoggerInterface           $logger,
    ) {
        parent::__construct($time);
        // Once a day. The estate converts on the first run; after that the
        // job only notices teams that appear on shared folders (bulk
        // import), folders that were shared again, or a fresh Nextcloud 35.
        $this->setInterval(86400);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
        // Two passes over the same folder would race on the applicable
        // list and the resource rows.
        $this->setAllowParallelRuns(false);
    }

    protected function run(mixed $argument): void {
        try {
            $summary = $this->reconcileService->reconcile();
            if (!empty($summary['skipped'])) {
                $this->logger->debug('[TeamHub][TeamSpaceReconcileJob] skipped — team spaces not available on this Nextcloud', [
                    'app' => Application::APP_ID,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][TeamSpaceReconcileJob] run failed', [
                'error' => $e->getMessage(), 'exception' => $e, 'app' => Application::APP_ID,
            ]);
        }
    }
}
