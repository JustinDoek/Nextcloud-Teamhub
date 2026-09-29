<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\GroupMirrorSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly backstop: sync Circles' copy of every Nextcloud group attached to a
 * TeamHub team, and move any team holding a stale copy onto the current one
 * (v4.10.47).
 *
 * Covers what the event path cannot: a user backend (LDAP, OIDC, SAML) that
 * changes group membership without dispatching UserAddedEvent /
 * UserRemovedEvent, and any queued GroupMirrorSyncJob that failed. Circles'
 * own full group sync runs only daily. See GroupMirrorSyncService.
 */
class GroupMirrorReconcileJob extends TimedJob {

    public function __construct(
        ITimeFactory                            $time,
        private readonly GroupMirrorSyncService $groupMirrorSync,
        private readonly LoggerInterface        $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(3600);
        $this->setAllowParallelRuns(false);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        $totals = $this->groupMirrorSync->syncAll();
        if ($totals['added'] !== 0 || $totals['removed'] !== 0 || $totals['relinked'] !== 0) {
            $this->logger->info('[TeamHub][GroupMirrorReconcileJob] sweep complete', [
                'groups'   => $totals['groups'],
                'added'    => $totals['added'],
                'removed'  => $totals['removed'],
                'relinked' => $totals['relinked'],
                'app'     => Application::APP_ID,
            ]);
        }
    }
}
