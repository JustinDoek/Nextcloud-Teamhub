<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\MeetingSeriesPrepService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly: each recurring team meeting's next date gets its notes file and a
 * reminder in the team chat, the chosen number of days before it (v4.20.10,
 * DESIGN §2.264). The work is in MeetingSeriesPrepService.
 */
class MeetingSeriesPrepJob extends TimedJob {

    public function __construct(
        ITimeFactory $time,
        private readonly MeetingSeriesPrepService $prep,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(3600);
        // prepared_until is written per date inside one run; a second runner
        // could post the same reminder twice.
        $this->setAllowParallelRuns(false);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        try {
            $result = $this->prep->sweep();
            if ($result['prepared'] > 0 || $result['errors'] > 0 || $result['forgotten'] > 0) {
                $this->logger->info('[TeamHub][MeetingSeriesPrepJob] sweep complete', array_merge($result, [
                    'app' => Application::APP_ID,
                ]));
            }
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MeetingSeriesPrepJob] sweep failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }
    }
}
