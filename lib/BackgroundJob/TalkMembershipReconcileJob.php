<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\TalkService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Hourly safety net that re-syncs Talk-room membership against each team's
 * EFFECTIVE Circles membership (direct + group-inherited + sub-team-inherited).
 *
 * Background
 * ----------
 * Talk does not watch Circles for membership changes. TeamHub already wires
 * the direct-user paths (invite, leave, remove) but groups and sub-teams are
 * a documented gap:
 *   - When a group is added to a team, its existing members aren't pulled
 *     into the Talk room.
 *   - When an NC admin adds/removes a user from a group that is attached to
 *     a team, the Talk room is never updated (no TeamHub event listener for
 *     NC group changes).
 *   - When a group is removed from a team, the legacy reconcile considered
 *     only direct members and could evict users still reachable via another
 *     attached group.
 *
 * This job sweeps every team with a connected Talk room once per hour and
 * delegates to TalkService::reconcileEffectiveTalkRoomMembers, which adds
 * any missing users and evicts any orphans (preserving room owners).
 *
 * Latency: up to 1 hour. For real-time correctness on the common direct-user
 * paths the existing per-action wiring still applies; this job is the safety
 * net for everything those paths don't cover.
 */
class TalkMembershipReconcileJob extends TimedJob {

    public function __construct(
        ITimeFactory                  $time,
        private readonly TalkService      $talkService,
        private readonly IAppManager      $appManager,
        private readonly IDBConnection    $db,
        private readonly LoggerInterface  $logger,
    ) {
        parent::__construct($time);
        // Run once per hour. Same cadence as ResourceDiscoveryJob and AuditMirrorJob.
        $this->setInterval(3600);
        // Reconcile is idempotent but two concurrent runs would race on writes —
        // pick one.
        $this->setAllowParallelRuns(false);
        // Run on resume after a server restart so a long downtime is caught up
        // promptly rather than waiting a full interval.
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        if (!$this->appManager->isInstalled('spreed')) {
            // No Talk = nothing to reconcile.
            return;
        }

        $teamIds = $this->listTeamIdsWithTalkRoom();
        if (empty($teamIds)) {
            return;
        }

        $totalAdded   = 0;
        $totalRemoved = 0;
        $touchedTeams = 0;

        foreach ($teamIds as $teamId) {
            $result = $this->talkService->reconcileEffectiveTalkRoomMembers($teamId);
            if ($result['added'] > 0 || $result['removed'] > 0) {
                $touchedTeams++;
                $totalAdded   += $result['added'];
                $totalRemoved += $result['removed'];
            }
        }

        $this->logger->info('[TeamHub][TalkMembershipReconcileJob] sweep complete', [
            'teamsScanned' => count($teamIds),
            'teamsTouched' => $touchedTeams,
            'usersAdded'   => $totalAdded,
            'usersRemoved' => $totalRemoved,
            'app'          => Application::APP_ID,
        ]);
    }

    /**
     * Return every team id that has a Talk room connected (talk_attendees
     * actor_type='circles'). Returns one row per team — DISTINCT to dedupe
     * any stray duplicate circle-attendee rows.
     *
     * @return list<string>
     */
    private function listTeamIdsWithTalkRoom(): array {
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->selectDistinct('actor_id')
                ->from('talk_attendees')
                ->where($qb->expr()->eq('actor_type', $qb->createNamedParameter('circles')))
                ->executeQuery();
            $ids = [];
            while ($row = $res->fetch()) {
                $id = (string)($row['actor_id'] ?? '');
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
            $res->closeCursor();
            return $ids;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TalkMembershipReconcileJob] failed to list teams', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
            return [];
        }
    }
}
