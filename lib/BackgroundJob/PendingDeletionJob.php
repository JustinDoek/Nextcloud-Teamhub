<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\PendingDeletionMapper;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\ResourceService;
use OCA\TeamHub\Service\TeamService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Daily job that finalizes teams whose soft-delete grace period has expired.
 *
 * Scans teamhub_pending_dels for rows with:
 *   status = 'pending' AND hard_delete_at <= now()
 *
 * For each due row:
 *   1. Deletes connected NC app resources (Talk room, Files share, Calendar,
 *      Deck board) via ResourceService::deleteTeamResource(). These were
 *      suspended (not deleted) at archive time for soft modes, so the Files
 *      folder and content have been preserved until now.
 *   2. Calls TeamService::deleteTeam() to destroy the Circles circle and all
 *      TeamHub-owned DB data.
 *   3. Marks the row status='completed'.
 *   4. Writes a team.deleted audit entry.
 *
 * Failures in individual deletions are caught and logged — one broken team
 * cannot prevent other due rows from being processed.
 *
 * Note: hard-delete mode rows are handled synchronously in
 * ArchiveService::produceTeamArchive() and do not reach this job.
 */
class PendingDeletionJob extends TimedJob {

    public function __construct(
        ITimeFactory                  $time,
        private PendingDeletionMapper $pendingMapper,
        private TeamService           $teamService,
        private ResourceService       $resourceService,
        private AuditService          $auditService,
        private IDBConnection         $db,
        private LoggerInterface       $logger,
    ) {
        parent::__construct($time);
        // Run once every 24 hours.
        $this->setInterval(86400);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        $now = time();

        try {
            $due = $this->pendingMapper->findDueForHardDelete($now);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][PendingDeletionJob] Could not query due rows', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
            return;
        }

        if (empty($due)) {
            $this->logger->debug('[TeamHub][PendingDeletionJob] No teams due for deletion', [
                'app' => Application::APP_ID,
            ]);
            return;
        }

        $this->logger->info('[TeamHub][PendingDeletionJob] Processing due deletions', [
            'count' => count($due),
            'app'   => Application::APP_ID,
        ]);

        foreach ($due as $pending) {
            $teamId   = $pending->getTeamId();
            $teamName = $pending->getTeamName();

            try {
                // ── Step 1: Destroy connected NC app resources ────────────────
                // At soft-delete time these were suspended (share/ACL row
                // removed) but the underlying data was kept. Now we fully
                // destroy them so the owner's account is cleaned up.
                $enabledApps = $this->getEnabledApps($teamId);
                foreach ($enabledApps as $app) {
                    try {
                        $this->resourceService->deleteTeamResource($teamId, $app);
                        $this->logger->debug('[TeamHub][PendingDeletionJob] Resource deleted', [
                            'teamId' => $teamId, 'app_name' => $app,
                            'app' => Application::APP_ID,
                        ]);
                    } catch (\Throwable $e) {
                        // Log and continue — one failed resource must not block deletion.
                        $this->logger->warning('[TeamHub][PendingDeletionJob] Resource delete failed', [
                            'teamId' => $teamId, 'app_name' => $app,
                            'error' => $e->getMessage(), 'app' => Application::APP_ID,
                        ]);
                    }
                }

                // ── Step 2: Destroy the circle and TeamHub DB data ────────────
                $this->teamService->deleteTeam($teamId);

                $pending->setStatus('completed');
                $this->pendingMapper->update($pending);

                $this->auditService->log(
                    $teamId,
                    'team.deleted',
                    null,       // system-originated — no human actor
                    'team',
                    $teamId,
                    [
                        'name'   => $teamName,
                        'reason' => 'grace_period_expired',
                    ]
                );

                $this->logger->info('[TeamHub][PendingDeletionJob] Team hard-deleted', [
                    'teamId'   => $teamId,
                    'teamName' => $teamName,
                    'app'      => Application::APP_ID,
                ]);

            } catch (\Throwable $e) {
                // Log but continue — one failure must not block other deletions.
                $pending->setFailureReason('PendingDeletionJob: ' . $e->getMessage());
                try {
                    $this->pendingMapper->update($pending);
                } catch (\Throwable) {}

                $this->logger->error('[TeamHub][PendingDeletionJob] Hard-delete failed', [
                    'teamId'   => $teamId,
                    'teamName' => $teamName,
                    'error'    => $e->getMessage(),
                    'app'      => Application::APP_ID,
                ]);
            }
        }
    }

    /**
     * Return the list of app resource types that were enabled for this team.
     * Reads teamhub_team_apps so we only attempt to delete what was set up.
     *
     * @return string[]
     */
    private function getEnabledApps(string $teamId): array {
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('app_id')
                ->from('teamhub_team_apps')
                ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('enabled', $qb->createNamedParameter(1)))
                ->executeQuery();
            $apps = [];
            while ($row = $res->fetch()) {
                $apps[] = (string)$row['app_id'];
            }
            $res->closeCursor();
            return $apps;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][PendingDeletionJob] Could not read enabled apps', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            // Fall back to attempting deletion for all known app types.
            return ['talk', 'files', 'calendar', 'deck', 'intravox'];
        }
    }
}


/**
 * Daily job that finalizes teams whose soft-delete grace period has expired.
 *
 * Scans teamhub_pending_dels for rows with:
 *   status = 'pending' AND hard_delete_at <= now()
 *
 * For each due row:
 *   1. Calls TeamService::deleteTeam() to destroy the circle and all TeamHub data.
 *   2. Marks the row status='completed'.
 *   3. Writes a team.deleted audit entry.
 *
 * Failures in individual deletions are caught and logged — one broken team
 * cannot prevent other due rows from being processed.
 *
 * Note: hard-delete mode (grace_seconds=0) rows are handled synchronously in
 * ArchiveService::produceTeamArchive() immediately after archive production.
 * This job only fires for soft30 and soft60 rows after the grace period.
 */
class PendingDeletionJob extends TimedJob {

    public function __construct(
        ITimeFactory            $time,
        private PendingDeletionMapper $pendingMapper,
        private TeamService           $teamService,
        private AuditService          $auditService,
        private LoggerInterface       $logger,
    ) {
        parent::__construct($time);
        // Run once every 24 hours.
        $this->setInterval(86400);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        $now = time();

        try {
            $due = $this->pendingMapper->findDueForHardDelete($now);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][PendingDeletionJob] Could not query due rows', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
            return;
        }

        if (empty($due)) {
            $this->logger->debug('[TeamHub][PendingDeletionJob] No teams due for deletion', [
                'app' => Application::APP_ID,
            ]);
            return;
        }

        $this->logger->info('[TeamHub][PendingDeletionJob] Processing due deletions', [
            'count' => count($due),
            'app'   => Application::APP_ID,
        ]);

        foreach ($due as $pending) {
            $teamId   = $pending->getTeamId();
            $teamName = $pending->getTeamName();

            try {
                $this->teamService->deleteTeam($teamId);

                $pending->setStatus('completed');
                $this->pendingMapper->update($pending);

                $this->auditService->log(
                    $teamId,
                    'team.deleted',
                    null,       // system-originated — no human actor
                    'team',
                    $teamId,
                    [
                        'name'   => $teamName,
                        'reason' => 'grace_period_expired',
                    ]
                );

                $this->logger->info('[TeamHub][PendingDeletionJob] Team hard-deleted', [
                    'teamId'   => $teamId,
                    'teamName' => $teamName,
                    'app'      => Application::APP_ID,
                ]);

            } catch (\Throwable $e) {
                // Log but continue — one failure must not block other deletions.
                $pending->setFailureReason('PendingDeletionJob: ' . $e->getMessage());
                try {
                    $this->pendingMapper->update($pending);
                } catch (\Throwable) {}

                $this->logger->error('[TeamHub][PendingDeletionJob] Hard-delete failed', [
                    'teamId'   => $teamId,
                    'teamName' => $teamName,
                    'error'    => $e->getMessage(),
                    'app'      => Application::APP_ID,
                ]);
            }
        }
    }
}
