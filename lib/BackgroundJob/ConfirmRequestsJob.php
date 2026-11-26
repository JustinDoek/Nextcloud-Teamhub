<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\Workflow\WorkflowAutoConfirmService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily: confirm the service requests whose requester did not answer within
 * the number of days their service set (v4.20.54,
 * `docs/service-builder.md` § 14.3).
 *
 * Registered in appinfo/info.xml <background-jobs>.
 */
class ConfirmRequestsJob extends TimedJob {

    public function __construct(
        ITimeFactory                       $time,
        private WorkflowAutoConfirmService $confirmations,
        private LoggerInterface            $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(24 * 3600);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        try {
            $this->confirmations->confirmDue();
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][ConfirmRequestsJob] requests could not be confirmed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
        }
    }
}
