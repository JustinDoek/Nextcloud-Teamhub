<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\IdpGroupRequestService;
use OCA\TeamHub\Service\IdpTeamProvisionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * One-shot: decide whether one Nextcloud group should have a team (v4.11.32).
 *
 * Queued by `GroupMembershipChangedListener` (not registered in info.xml), so
 * it runs on the next cron tick rather than inside the request that changed
 * the group. Argument: `['groupId' => string]`.
 *
 * **Why not act in the listener.** The event that gets us here is dispatched
 * by core's `OC\Group\Group::addUser()` during a login or an LDAP sync, and
 * creating a team is not a few milliseconds of work: it makes a circle, a
 * team space, a Talk room, a calendar and a Deck board. Doing that inside
 * somebody's login would make the login wait for it, and a failure anywhere
 * in the chain would surface as a failed login rather than a failed
 * provisioning. Circles' own OIDC integration queues for the same reason.
 *
 * The job is deduplicated per group by `IJobList`, so a bulk LDAP sync that
 * adds forty people to one group queues one job, not forty.
 */
class IdpGroupConsiderJob extends QueuedJob {

    public function __construct(
        ITimeFactory                     $time,
        private IdpTeamProvisionService  $provisioning,
        private IdpGroupRequestService   $requests,
        private LoggerInterface          $logger,
    ) {
        parent::__construct($time);
    }

    protected function run(mixed $argument): void {
        $groupId = is_array($argument) ? ($argument['groupId'] ?? null) : null;
        if (!is_string($groupId) || $groupId === '') {
            return;
        }
        try {
            $outcome = $this->provisioning->consider($groupId);
            // v4.11.39 — a group that is now waiting gets the
            // administrators' My Work task straight away rather than on the
            // next hourly reconcile (DESIGN §2.172, step 4).
            if ($outcome === 'pending') {
                $this->requests->openForGroup($groupId);
            }
            if ($outcome !== '') {
                $this->logger->info('[TeamHub][IdpGroupConsiderJob] directory group considered', [
                    'groupId' => $groupId,
                    'outcome' => $outcome,
                    'app'     => Application::APP_ID,
                ]);
            }
        } catch (\Throwable $e) {
            // The hourly sweep reconsiders whatever this missed.
            $this->logger->error('[TeamHub][IdpGroupConsiderJob] could not consider a group', [
                'groupId' => $groupId,
                'error'   => $e->getMessage(),
                'app'     => Application::APP_ID,
            ]);
        }
    }
}
