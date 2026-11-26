<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\Workflow\StepDeadlineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily: remind whoever has a step of a service request that is past its
 * deadline, once (v4.20.68, `docs/service-builder.md` § 14.6).
 *
 * Registered in appinfo/info.xml <background-jobs>.
 */
class RemindOverdueStepsJob extends TimedJob {

    public function __construct(
        ITimeFactory                $time,
        private StepDeadlineService $deadlines,
        private LoggerInterface     $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(24 * 3600);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        try {
            $this->deadlines->remindOverdue();
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][RemindOverdueStepsJob] overdue steps could not be reminded about', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
        }
    }
}
