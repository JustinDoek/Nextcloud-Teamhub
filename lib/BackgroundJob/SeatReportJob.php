<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\SeatReportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Twice-monthly effective-member report for metered licences (v4.13.30).
 *
 * This job only provides the tick. Whether anything is sent is entirely
 * SeatReportService's decision — a banded licence returns on the first line
 * of its gate, before any config read or network code.
 *
 * **The interval is twelve hours, not fifteen days, and that is the point.**
 * The service reports at most once per *period* (the 1st–15th and the 16th
 * onward, see SeatReportService::periodFor()), so a frequent tick costs one
 * appconfig comparison and buys catch-up: an instance whose cron was down for
 * a week reports as soon as it runs again. A fifteen-day interval would drift
 * by a day a month — background jobs run when NC gets to them, not on the
 * dot — and within a couple of months would be firing in the wrong half of
 * the month from the one it was meant to cover.
 *
 * TIME_INSENSITIVE because nothing downstream cares whether the report lands
 * at 03:00 or 09:00; only which period it lands in.
 *
 * Registration: appinfo/info.xml <background-jobs>.
 */
class SeatReportJob extends TimedJob {

    public function __construct(
        ITimeFactory               $time,
        private SeatReportService  $seatReportService,
        private LoggerInterface    $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(12 * 60 * 60);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        try {
            $this->seatReportService->reportIfDue();
        } catch (\Throwable $e) {
            // SeatReportService::send() already swallows transport failures and
            // records them for the admin panel. This is the belt-and-braces
            // catch for anything raised while counting members, so a bad query
            // can never take down the whole background-job runner for the
            // instance — the same posture TelemetryReportJob takes.
            $this->logger->warning(
                '[TeamHub] SeatReportJob failed: ' . $e->getMessage(),
                ['app' => Application::APP_ID, 'exception' => $e],
            );
        }
    }
}
