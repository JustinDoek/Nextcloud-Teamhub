<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\TeamAdoptionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Every five minutes: find teams made outside TeamHub and ask the right
 * people about them (v4.10.50, DESIGN §2.149). The whole of the logic is
 * {@see TeamAdoptionService::sweep()}; this is its clock.
 *
 * Registered in `appinfo/info.xml` `<background-jobs>`.
 */
class TeamAdoptionSweepJob extends TimedJob {

    public function __construct(
        ITimeFactory                $time,
        private TeamAdoptionService $adoption,
        private LoggerInterface     $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(300);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        try {
            $result = $this->adoption->sweep();
            if ($result['found'] > 0 || $result['withdrawn'] > 0 || $result['requeued'] > 0) {
                $this->logger->info('[TeamHub][TeamAdoptionSweepJob] teams made outside TeamHub', $result + ['app' => Application::APP_ID]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][TeamAdoptionSweepJob] sweep failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }
}
