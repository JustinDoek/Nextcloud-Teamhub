<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\TeamImportMapper;
use OCA\TeamHub\Service\TeamImportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Safety net for bulk team imports (v4.6.6). Runs every five minutes.
 *
 * Two duties:
 *
 * 1. **Drain abandoned runs.** An import whose status is `running` but whose
 *    `heartbeat_at` is older than five minutes has lost its pump — the admin
 *    closed the tab. This job finishes it.
 * 2. **Prune.** Runs that reached `completed` or `cancelled` more than 30 days
 *    ago, along with their rows.
 *
 * ## Why this is only a safety net
 *
 * The browser drives provisioning because it runs in a real admin session, and
 * a session is exactly what team creation needs: `TeamService::createTeam()`
 * opens a Circles session from `IUserSession::getUser()`, and
 * `ResourceService::createTeamResources()` reads the session user to decide who
 * owns the Talk room, the folder, the calendar and the Deck board. A TimedJob
 * has no session at all.
 *
 * ## How the session is supplied here
 *
 * `IUserSession::setUser()` for the duration of the drain, restored afterwards
 * in a `finally`. Before impersonating, the import's `created_by` account is
 * re-checked twice: it must still exist, and `IGroupManager::isAdmin()` must
 * still hold. An admin who has since been demoted or deleted does not get their
 * queued import finished under their name — the run is left `running` and the
 * next tick re-evaluates, so restoring the account resumes it.
 *
 * This does **not** affect the normal path. Nothing about the browser pump
 * changes; this code only ever executes for a run whose tab went away.
 *
 * Registered in `appinfo/info.xml` `<background-jobs>` — **not** via
 * `registerBackgroundJob()`, which was removed from `IRegistrationContext` in
 * NC 33 (see the note in `Application.php`).
 */
class TeamImportJob extends TimedJob {

    /** Runs adopted per tick. Bounded so one tick cannot run for ever. */
    private const MAX_IMPORTS_PER_RUN = 3;

    /** Rows provisioned per adopted run per tick. */
    private const ROWS_PER_IMPORT = 10;

    /** Terminal runs older than this are deleted, rows included. */
    private const PRUNE_AFTER_SECONDS = 30 * 24 * 60 * 60;

    public function __construct(
        ITimeFactory              $time,
        private TeamImportMapper  $mapper,
        private TeamImportService $importService,
        private IUserManager      $userManager,
        private IGroupManager     $groupManager,
        private IUserSession      $userSession,
        private LoggerInterface   $logger,
    ) {
        parent::__construct($time);
        // Five minutes — the same window that defines a stale heartbeat, so an
        // abandoned run is picked up on the first tick after it goes quiet.
        $this->setInterval(300);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        $this->drainStalledImports();
        $this->pruneOldImports();
    }

    // -------------------------------------------------------------------------
    // Drain
    // -------------------------------------------------------------------------

    private function drainStalledImports(): void {
        $staleBefore = time() - TeamImportService::STALE_AFTER_SECONDS;

        try {
            $stalled = $this->mapper->findStalledRunning($staleBefore, self::MAX_IMPORTS_PER_RUN);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][TeamImportJob] Could not query stalled imports', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);

            return;
        }

        if ($stalled === []) {
            return;
        }

        foreach ($stalled as $import) {
            $importId = (int)$import['id'];
            $actorUid = (string)$import['created_by'];

            $actor = $this->userManager->get($actorUid);
            if ($actor === null) {
                $this->logger->warning('[TeamHub][TeamImportJob] Skipping import — account no longer exists', [
                    'importId' => $importId, 'app' => Application::APP_ID,
                ]);
                continue;
            }
            if (!$this->groupManager->isAdmin($actorUid)) {
                $this->logger->warning('[TeamHub][TeamImportJob] Skipping import — account is no longer an admin', [
                    'importId' => $importId, 'app' => Application::APP_ID,
                ]);
                continue;
            }

            $previousUser = $this->userSession->getUser();
            try {
                $this->userSession->setUser($actor);

                // Rows left 'running' belong to the request that died. Return
                // them to the queue first, or the run can never finish.
                $released = $this->mapper->releaseRunningRows($importId);
                if ($released > 0) {
                    $this->logger->info('[TeamHub][TeamImportJob] Released rows from a dead request', [
                        'importId' => $importId, 'rows' => $released, 'app' => Application::APP_ID,
                    ]);
                }

                $this->importService->runChunk($importId, self::ROWS_PER_IMPORT);

                $this->logger->info('[TeamHub][TeamImportJob] Drained a chunk of an abandoned import', [
                    'importId' => $importId, 'app' => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                // One broken import must not stop the others, and must not stop
                // the prune below.
                $this->logger->error('[TeamHub][TeamImportJob] Drain failed', [
                    'importId' => $importId, 'error' => $e->getMessage(),
                    'app'      => Application::APP_ID,
                ]);
            } finally {
                // Always restore, including on the error path: cron runs many
                // jobs in one process and leaving a faked session behind would
                // hand it to whatever runs next.
                $this->userSession->setUser($previousUser);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Prune
    // -------------------------------------------------------------------------

    private function pruneOldImports(): void {
        $before = time() - self::PRUNE_AFTER_SECONDS;

        try {
            $ids = $this->mapper->findFinishedBefore($before);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][TeamImportJob] Could not query prunable imports', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);

            return;
        }

        foreach ($ids as $importId) {
            try {
                $this->mapper->deleteImport($importId);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][TeamImportJob] Prune failed', [
                    'importId' => $importId, 'error' => $e->getMessage(),
                    'app'      => Application::APP_ID,
                ]);
            }
        }

        if ($ids !== []) {
            $this->logger->info('[TeamHub][TeamImportJob] Pruned old import runs', [
                'count' => count($ids), 'app' => Application::APP_ID,
            ]);
        }
    }
}
