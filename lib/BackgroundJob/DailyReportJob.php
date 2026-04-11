<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\Service\TelemetryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Sends a daily anonymous usage report to the TeamHub stats endpoint.
 *
 * Runs once every 24 hours via NC's background job system (cron / webcron / ajax).
 * If telemetry is disabled by an NC admin, TelemetryService::sendDailyReport()
 * returns immediately without making any network call.
 *
 * Registration: appinfo/info.xml <background-jobs> block.
 * Do NOT use $context->registerBackgroundJob() — that method was removed from
 * IRegistrationContext in NC 33 and will fatal on boot.
 */
class DailyReportJob extends TimedJob {

    public function __construct(
        ITimeFactory            $time,
        private TelemetryService $telemetryService,
        private LoggerInterface  $logger,
    ) {
        parent::__construct($time);
        // Run once every 24 hours
        $this->setInterval(24 * 60 * 60);
        // NC 26+: allow the job to run again immediately after a server restart
        // rather than waiting a full interval from last-run time.
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        try {
            $this->telemetryService->sendDailyReport();
        } catch (\Throwable $e) {
            // Never crash the background job runner
            $this->logger->error('[TeamHub] DailyReportJob failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
