<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\Workflow\WorkflowEngine;
use OCA\TeamHub\Service\Workflow\WorkflowNoticeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * One-shot: send one notice of a service request to a few people (v4.20.62,
 * `docs/service-builder.md` § 14.5).
 *
 * Queued by `WorkflowNoticeService::queue()` (not registered in info.xml).
 * Argument: `['instance' => int, 'step' => string, 'notice' => int,
 * 'uids' => string[], 'channels' => string[]]`.
 *
 * A notice links to the request only for somebody who may open it, and the
 * engine's own read is what decides that.
 */
class SendServiceNoticeJob extends QueuedJob {

    public function __construct(
        ITimeFactory                  $time,
        private WorkflowNoticeService $notices,
        private WorkflowEngine        $engine,
        private LoggerInterface       $logger,
    ) {
        parent::__construct($time);
    }

    protected function run(mixed $argument): void {
        if (!is_array($argument) || !is_int($argument['instance'] ?? null) || !is_int($argument['notice'] ?? null)) {
            return;
        }
        $id = $argument['instance'];
        try {
            $this->notices->deliver(
                $id,
                (string)($argument['step'] ?? ''),
                $argument['notice'],
                array_values(array_filter((array)($argument['uids'] ?? []), 'is_string')),
                array_values(array_filter((array)($argument['channels'] ?? []), 'is_string')),
                function (string $uid) use ($id): bool {
                    try {
                        $this->engine->get($id, $uid);
                        return true;
                    } catch (\Throwable) {
                        return false;
                    }
                },
            );
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][SendServiceNoticeJob] a notice could not be sent', [
                'instance' => $id, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
        }
    }
}
