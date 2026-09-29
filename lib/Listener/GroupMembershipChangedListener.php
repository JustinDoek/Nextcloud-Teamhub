<?php
declare(strict_types=1);

namespace OCA\TeamHub\Listener;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\BackgroundJob\GroupMirrorSyncJob;
use OCA\TeamHub\Service\GroupMirrorSyncService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use Psr\Log\LoggerInterface;

/**
 * A user joined or left a Nextcloud group: if the group is attached to a
 * TeamHub team, queue a sync of Circles' copy of it (v4.10.47).
 *
 * Two jobs for one queue entry: a team still holding a stale copy of this
 * group is moved onto the current one within a cron run rather than an hour,
 * and if Circles' own async add for this change is lost, the diff repairs it.
 *
 * Why queue rather than sync here: Circles' own listener on the same event
 * has, in this very request, queued the federated event that should do the
 * work. Running Circles' sync from a web request would queue another async
 * event just like it. From cron Circles runs it in-process. See
 * GroupMirrorSyncService for the whole story; the job is deduplicated per
 * group, so a bulk import into one group queues one job, not one per user.
 *
 * Latency is one cron run (five minutes on AIO). The hourly
 * GroupMirrorReconcileJob catches anything a user backend changes without
 * dispatching these events.
 *
 * @template-implements IEventListener<Event>
 */
class GroupMembershipChangedListener implements IEventListener {

    public function __construct(
        private GroupMirrorSyncService $groupMirrorSync,
        private IJobList               $jobList,
        private LoggerInterface        $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof UserAddedEvent) && !($event instanceof UserRemovedEvent)) {
            return;
        }

        $groupId = $event->getGroup()->getGID();
        try {
            if (!$this->groupMirrorSync->isTeamGroup($groupId)) {
                return;
            }
            $this->jobList->add(GroupMirrorSyncJob::class, ['groupId' => $groupId]);
        } catch (\Throwable $e) {
            // Somebody else's group write is in progress; never abort it. The
            // hourly job repairs whatever this misses.
            $this->logger->warning('[TeamHub][GroupMembershipChanged] could not queue group sync', [
                'groupId' => $groupId,
                'error'   => $e->getMessage(),
                'app'     => Application::APP_ID,
            ]);
        }
    }
}
