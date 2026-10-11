<?php
declare(strict_types=1);

namespace OCA\TeamHub\Listener;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\BackgroundJob\GroupMirrorSyncJob;
use OCA\TeamHub\BackgroundJob\IdpGroupConsiderJob;
use OCA\TeamHub\Service\GroupMirrorSyncService;
use OCA\TeamHub\Service\IdpTeamService;
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
 * ## The second duty, v4.11.32: a group that has no team yet
 *
 * These two events are also the earliest signal that a directory produced a
 * new group, which is why the IdP-teams feature hangs off them rather than
 * off a group-lifecycle event:
 *
 *   - **`user_ldap` never dispatches `GroupCreatedEvent` or
 *     `GroupDeletedEvent`.** Its `UpdateGroupsService` reports a newly
 *     discovered group by dispatching `UserAddedEvent` per member, and a
 *     vanished one by `UserRemovedEvent` per member. There is no group-level
 *     event on the LDAP path at all.
 *   - **`user_oidc` provisions groups at login** through
 *     `IGroupManager::createGroup()` and `IGroup::addUser()`, and core's
 *     `OC\Group\Group::addUser()` dispatches `UserAddedEvent` itself. So an
 *     OIDC-provisioned group reaches us in the login request.
 *
 * So the normal latency for a new directory team is one cron run, same as
 * above; `IdpGroupSweepJob` is the hourly backstop for a qualifying group
 * with no members, which fires no membership event ever.
 *
 * @template-implements IEventListener<Event>
 */
class GroupMembershipChangedListener implements IEventListener {

    public function __construct(
        private GroupMirrorSyncService $groupMirrorSync,
        private IdpTeamService         $idpTeams,
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
            if ($this->groupMirrorSync->isTeamGroup($groupId)) {
                $this->jobList->add(GroupMirrorSyncJob::class, ['groupId' => $groupId]);
                // A group already attached to a team cannot also be a
                // candidate for a new one, so there is nothing below to do.
                return;
            }

            // v4.11.32 — the group is not attached to any team. It may be one
            // a directory just produced, which is the moment to consider
            // giving it one. `isEnabled()` is a config read and answers false
            // on every instance that has not switched this on, which is the
            // default — so the common case costs nothing beyond it.
            if ($this->idpTeams->isEnabled() && $this->idpTeams->qualifies($groupId)) {
                $this->jobList->add(IdpGroupConsiderJob::class, ['groupId' => $groupId]);
            }
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
