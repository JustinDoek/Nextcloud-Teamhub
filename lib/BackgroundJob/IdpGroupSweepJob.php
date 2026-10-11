<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\IdpGroupRequestService;
use OCA\TeamHub\Service\IdpTeamProvisionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly: every directory group that should have a team but has no row yet,
 * and every provisioned team whose group has left the directory (v4.11.32).
 *
 * **The backstop, not the normal path.** `GroupMembershipChangedListener`
 * carries the usual case: a group newly discovered by `user_ldap`, or newly
 * provisioned at an OIDC login, dispatches `UserAddedEvent` for each of its
 * members, so a team is considered on the very next cron tick. This job
 * catches only what an event cannot signal —
 *
 *   - a qualifying group with **no members at all** (no membership event ever
 *     fires for it)
 *   - an event dispatched while TeamHub was disabled
 *   - a settings change that makes groups qualify which previously did not
 *   - and, on the other side, a group that has **gone**: `user_ldap` reports a
 *     vanished group by dispatching `UserRemovedEvent` per member, which says
 *     nothing about the group itself, and nothing at all is dispatched for a
 *     group that was already empty
 *
 * The same division of labour, and the same reasoning, as
 * `TeamAdoptionService`'s "a sweep, not a listener".
 *
 * Does nothing at all while `idpTeamsMode` is `off`, which is the default.
 */
class IdpGroupSweepJob extends TimedJob {

    public function __construct(
        ITimeFactory                              $time,
        private readonly IdpTeamProvisionService  $provisioning,
        private readonly IdpGroupRequestService   $requests,
        private readonly LoggerInterface          $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(3600);
        $this->setAllowParallelRuns(false);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        try {
            $swept = $this->provisioning->sweep();
            if ($swept['considered'] !== 0) {
                $this->logger->info('[TeamHub][IdpGroupSweepJob] directory groups considered', $swept + [
                    'app' => Application::APP_ID,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][IdpGroupSweepJob] sweep failed', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
        }

        // Independent of the sweep: a failure finding new groups must not stop
        // the instance noticing that a team's group has gone.
        try {
            $orphans = $this->provisioning->reconcileOrphans();
            if ($orphans['orphaned'] !== 0 || $orphans['deleted'] !== 0 || $orphans['skipped']) {
                $this->logger->info('[TeamHub][IdpGroupSweepJob] orphan pass complete', $orphans + [
                    'app' => Application::APP_ID,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][IdpGroupSweepJob] orphan pass failed', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
        }

        // v4.11.39 — the administrators' My Work task per waiting group
        // (DESIGN §2.172, step 4). Last of the three, and independent: a
        // group must be found and recorded before it can be asked about, and
        // a failure here must leave the first two passes' work standing.
        //
        // A reconcile rather than a hook on detection, so it also opens a
        // task for the rows that went pending before this version — and
        // withdraws the task of any group decided in the grid or forgotten
        // because its group went.
        try {
            $requests = $this->requests->reconcile();
            if ($requests['opened'] !== 0 || $requests['closed'] !== 0) {
                $this->logger->info('[TeamHub][IdpGroupSweepJob] group requests reconciled', $requests + [
                    'app' => Application::APP_ID,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][IdpGroupSweepJob] request pass failed', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
        }
    }
}
