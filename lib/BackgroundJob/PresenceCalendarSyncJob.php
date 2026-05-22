<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\Service\PresenceCalendarService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * One-shot background job: sync a single user's presence slots to their
 * default calendar after a template save or slot override.
 *
 * Queued (not registered in info.xml — added dynamically via IJobList::add()).
 * Runs once per enqueue; removed from the job table when complete.
 *
 * Argument: ['userId' => string]
 *
 * Why a QueuedJob rather than inline sync:
 *   CalDavBackend::createCalendarObject / updateCalendarObject are not
 *   negligible — each involves a DB write + potential notification. For a
 *   user whose template covers the full rolling window (~500 days × 2 slots
 *   = up to 1000 VEVENTs on first sync), doing this synchronously would hold
 *   the HTTP connection for several seconds. The user expects the UI to
 *   respond immediately after clicking a cell.
 */
class PresenceCalendarSyncJob extends QueuedJob {

    public function __construct(
        ITimeFactory                       $time,
        private PresenceCalendarService    $calendarService,
        private LoggerInterface            $logger,
    ) {
        parent::__construct($time);
    }

    protected function run(mixed $argument): void {
        $userId = $argument['userId'] ?? null;
        if (!is_string($userId) || $userId === '') {
            $this->logger->warning('[TeamHub][PresenceCalendarSyncJob] missing userId in argument');
            return;
        }

        try {
            $this->calendarService->syncAllSlotsForUser($userId);
        } catch (\Throwable $e) {
            // Non-fatal — calendar sync failures should never surface to the user.
            $this->logger->warning(sprintf(
                '[TeamHub][PresenceCalendarSyncJob] sync failed for %s: %s',
                $userId, $e->getMessage()
            ));
        }
    }
}
