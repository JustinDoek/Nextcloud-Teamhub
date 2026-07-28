<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\TelemetryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily anonymous usage report for unlicensed instances.
 *
 * Restores the transport that v4.3.20 removed. That release deleted the old
 * DailyReportJob along with the install/uninstall pings, but left
 * TelemetryService::isEnabled() — the policy — in place. The result was an
 * app that decided correctly whether it *should* report and then had no code
 * path that ever did, so free-tier reporting silently stopped at 4.3.20 while
 * still appearing implemented.
 *
 * Whether anything is actually sent is entirely TelemetryService's decision;
 * this job only provides the tick. The policy it applies:
 *
 *   No licence installed .................. reports
 *   Malformed / unreadable licence JWT .... reports (treated as unlicensed)
 *   Active licence or trial ............... never reports
 *   Expired, within the 30-day grace ...... never reports
 *   Expired beyond grace .................. reports again
 *
 * A paying or trialling customer is already known, so there is nothing to
 * learn by counting them; the report exists to size the free-tier footprint.
 *
 * The payload is aggregate-only — see TelemetryService::collectStats(). No
 * user IDs, no message content, no instance URL or hostname, and custom link
 * URLs reduced to bare hostnames.
 *
 * Registration: appinfo/info.xml <background-jobs>.
 */
class TelemetryReportJob extends TimedJob {

    public function __construct(
        ITimeFactory              $time,
        private TelemetryService  $telemetryService,
        private LoggerInterface   $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(24 * 60 * 60);
        // Nothing downstream cares whether the report lands at 03:00 or 09:00,
        // so let NC defer it away from time-sensitive work.
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        try {
            // Gates on isEnabled() internally — a licensed or trialling
            // instance returns without opening a connection.
            $this->telemetryService->sendDailyReport();
        } catch (\Throwable $e) {
            // TelemetryService::send() already swallows transport failures.
            // This is the belt-and-braces catch for anything raised while
            // collecting stats, so a bad query can never take down the whole
            // background-job runner for the instance.
            $this->logger->warning(
                '[TeamHub] TelemetryReportJob failed: ' . $e->getMessage(),
                ['app' => Application::APP_ID, 'exception' => $e],
            );
        }
    }
}
