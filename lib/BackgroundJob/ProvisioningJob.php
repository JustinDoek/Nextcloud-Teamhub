<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\Provisioning\ProvisioningService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Safety net for workspace provisioning (v4.9.6, OpenProject Phase 2).
 * Runs every five minutes. The same shape as {@see TeamImportJob}, for the
 * same reason: the browser drives provisioning because the steps need a
 * real session (Circles opens one from `IUserSession::getUser()`, resource
 * creation reads the session user to decide who owns what, every
 * OpenProject call runs as that user), and a cron job has none.
 *
 * Two duties:
 *
 * 1. **Resume abandoned operations.** An operation that is `running` with a
 *    heartbeat older than five minutes has lost its pump — the creator
 *    closed the wizard, the request timed out. The job impersonates the
 *    creator (`setUser()`, restored in `finally`) after re-checking that the
 *    account exists and may still create teams, and runs the operation on.
 *    The lease inside `ProvisioningService` makes this safe against a pump
 *    that comes back at the same moment.
 * 2. **Prune.** Operations that reached a terminal state more than 30 days
 *    ago, with their steps. Ledger rows are the team's and stay.
 *
 * Registered in `appinfo/info.xml` `<background-jobs>`.
 */
class ProvisioningJob extends TimedJob {

    private const MAX_PER_RUN         = 3;
    private const BUDGET_SECONDS      = 40;
    private const PRUNE_AFTER_SECONDS = 30 * 24 * 60 * 60;

    public function __construct(
        ITimeFactory                $time,
        private ProvisioningService $provisioning,
        private IUserManager        $userManager,
        private IGroupManager       $groupManager,
        private IConfig             $config,
        private MemberService       $memberService,
        private IUserSession        $userSession,
        private LoggerInterface     $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(300);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        $this->resumeStalled();
        $this->prune();
    }

    private function resumeStalled(): void {
        try {
            $stalled = $this->provisioning->findStalled(self::MAX_PER_RUN);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][ProvisioningJob] Could not query stalled operations', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return;
        }

        foreach ($stalled as $op) {
            $id      = (int)$op['id'];
            $creator = (string)$op['createdBy'];

            $account = $this->userManager->get($creator);
            if ($account === null) {
                $this->logger->warning('[TeamHub][ProvisioningJob] Skipping operation — creator no longer exists', [
                    'operation' => $id, 'app' => Application::APP_ID,
                ]);
                continue;
            }
            if (!$this->mayCreateTeams($creator)) {
                $this->logger->warning('[TeamHub][ProvisioningJob] Skipping operation — creator may no longer create teams', [
                    'operation' => $id, 'app' => Application::APP_ID,
                ]);
                continue;
            }

            $previous = $this->userSession->getUser();
            try {
                $this->userSession->setUser($account);
                $state = $this->provisioning->runAsJob($id, self::BUDGET_SECONDS);
                $this->logger->info('[TeamHub][ProvisioningJob] Resumed an abandoned operation', [
                    'operation' => $id, 'status' => $state['status'] ?? '?', 'app' => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('[TeamHub][ProvisioningJob] Resume failed', [
                    'operation' => $id, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            } finally {
                $this->userSession->setUser($previous);
            }
        }
    }

    private function prune(): void {
        $before = time() - self::PRUNE_AFTER_SECONDS;
        try {
            $ids = $this->provisioning->findPrunable($before);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][ProvisioningJob] Could not query prunable operations', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return;
        }
        foreach ($ids as $id) {
            try {
                $this->provisioning->prune($id);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][ProvisioningJob] Prune failed', [
                    'operation' => $id, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }
    }

    /**
     * `MemberService::canCurrentUserCreateTeam()` asked about a stored uid —
     * the job has no session to ask it about.
     */
    private function mayCreateTeams(string $uid): bool {
        if ($this->memberService->canUserBulkCreateTeams($uid)) {
            return true;
        }
        $rawGroup = trim($this->config->getAppValue(Application::APP_ID, 'createTeamGroup', ''));
        if ($rawGroup === '') {
            return true;
        }
        foreach (array_filter(array_map('trim', explode(',', $rawGroup))) as $gid) {
            if ($this->groupManager->isInGroup($uid, $gid)) {
                return true;
            }
        }
        return false;
    }
}
