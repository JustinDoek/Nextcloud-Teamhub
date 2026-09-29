<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\Service\GroupMirrorSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * One-shot: sync Circles' copy of one Nextcloud group (v4.10.47).
 *
 * Queued by GroupMembershipChangedListener (not registered in info.xml).
 * Argument: ['groupId' => string]. Runs from cron, which is the point — see
 * GroupMirrorSyncService.
 */
class GroupMirrorSyncJob extends QueuedJob {

    public function __construct(
        ITimeFactory                   $time,
        private GroupMirrorSyncService $groupMirrorSync,
    ) {
        parent::__construct($time);
    }

    protected function run(mixed $argument): void {
        $groupId = is_array($argument) ? ($argument['groupId'] ?? null) : null;
        if (!is_string($groupId) || $groupId === '') {
            return;
        }
        $this->groupMirrorSync->syncGroup($groupId);
    }
}
