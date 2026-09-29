<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\Workflow\WorkflowShareService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily: remove the shares the paperclip made that are past their date
 * (v4.10.38, `docs/service-builder.md` § 7).
 *
 * The engine removes a request's shares when the request ends; this job is
 * for the rest — a share whose request is still open after the service's
 * duration, and one whose removal failed at the end. Nextcloud's own daily
 * `ExpireSharesJob` covers link and e-mail shares only, and drops an expired
 * team or user share only when somebody's shares are next read (gate G4).
 * The job runs with every app loaded, which is what lets it find a team
 * share by id (gate G11, 2026-09-24).
 *
 * Registered in appinfo/info.xml <background-jobs>.
 */
class ExpireWorkflowSharesJob extends TimedJob {

    public function __construct(
        ITimeFactory                  $time,
        private WorkflowShareService  $shares,
        private LoggerInterface       $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(24 * 3600);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        try {
            $this->shares->expireDue();
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][ExpireWorkflowSharesJob] expired shares could not be removed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
        }
    }
}
