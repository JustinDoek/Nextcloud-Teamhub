<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\MaintenanceService;
use OCA\TeamHub\Service\TelemetryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Admin-only maintenance and telemetry endpoints.
 *
 * All routes require NC admin — enforced by #[AuthorizedAdminSetting] where
 * available, with a secondary check inside the service layer.
 */
class MaintenanceController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private MaintenanceService $maintenanceService,
        private TelemetryService   $telemetryService,
        private IUserManager       $userManager,
        private LoggerInterface    $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // -------------------------------------------------------------------------
    // Maintenance — all teams grid
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/admin/maintenance/teams
     * Returns a paginated list of all real user-created teams.
     *
     * Query params:
     *   search      string  Substring filter on team name (default: '')
     *   page        int     1-based page (default: 1)
     *   per_page    int     Rows per page: 10|20|50|100 (default: 20)
     *   orphans_only int    1 = only teams with no owner (default: 0)
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function getAllTeams(
        string $search = '',
        int    $page = 1,
        int    $per_page = 20,
        int    $orphans_only = 0,
    ): JSONResponse {

        // Validate inputs
        $search      = trim(substr($search, 0, 200));
        $page        = max(1, $page);
        $per_page    = in_array($per_page, [10, 20, 50, 100], true) ? $per_page : 20;
        $orphansOnly = $orphans_only === 1;

        try {
            $result = $this->maintenanceService->getAllTeams($search, $page, $per_page, $orphansOnly);
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // Maintenance — orphaned teams (legacy)
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/admin/maintenance/orphaned-teams
     * Returns teams that have no owner.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function getOrphanedTeams(): JSONResponse {
        try {
            return new JSONResponse($this->maintenanceService->getOrphanedTeams());
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * DELETE /api/v1/admin/maintenance/orphaned-teams/{teamId}
     * Delete an orphaned team and all its TeamHub data.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function deleteOrphanedTeam(string $teamId): JSONResponse {
        try {
            $this->maintenanceService->deleteOrphanedTeam($teamId);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /api/v1/admin/maintenance/orphaned-teams/{teamId}/assign-owner
     * Assign a new owner to an orphaned team.
     * Body: { "userId": "username" }
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function assignOwner(string $teamId, string $userId = ''): JSONResponse {
        if ($userId === '') {
            return new JSONResponse(['error' => 'userId is required'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $this->maintenanceService->assignOwner($teamId, $userId);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // Telemetry settings
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/admin/telemetry
     * Returns current telemetry settings + a preview of what would be sent.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function getTelemetry(): JSONResponse {
        try {
            return new JSONResponse([
                'enabled'     => $this->telemetryService->isEnabled(),
                'report_url'  => TelemetryService::REPORT_URL,
                'preview'     => $this->telemetryService->collectStats(),
            ]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * PUT /api/v1/admin/telemetry
     * Enable or disable telemetry.
     * Body: { "enabled": true|false }
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function saveTelemetry(bool $enabled = true): JSONResponse {
        try {
            $this->telemetryService->setEnabled($enabled);
            return new JSONResponse(['enabled' => $this->telemetryService->isEnabled()]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/v1/admin/users/search?q=term
     * User search for the owner picker — returns matching NC users.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function searchUsers(string $q = ''): JSONResponse {
        if (strlen($q) < 1) {
            return new JSONResponse([]);
        }
        $users = [];
        foreach ($this->userManager->searchDisplayName($q, 10) as $user) {
            $users[] = [
                'uid'         => $user->getUID(),
                'displayName' => $user->getDisplayName() ?: $user->getUID(),
            ];
        }
        return new JSONResponse($users);
    }

    // -------------------------------------------------------------------------
    // Membership integrity check and repair
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/admin/maintenance/membership-check
     * Scans every team and returns any whose circles_membership cache row count
     * does not match the circles_member source-of-truth count.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function checkMembershipIntegrity(): JSONResponse {
        try {
            $result = $this->maintenanceService->checkMembershipIntegrity();
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /api/v1/admin/maintenance/membership-repair/{teamId}
     * Rebuilds the circles_membership cache for a single team.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function repairMembershipCache(string $teamId): JSONResponse {
        try {
            $this->maintenanceService->repairMembershipCache($teamId);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // Ghost member cleanup
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/admin/maintenance/ghost-members
     * Return all circles_member rows (user_type=1) whose NC account is gone.
     *
     * Query params:
     *   search  string  Optional uid substring filter (default: '')
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function findGhostMembers(string $search = ''): JSONResponse {
        $search = trim(substr($search, 0, 100));
        try {
            $ghosts = $this->maintenanceService->findGhostMembers($search);
            return new JSONResponse(['ghosts' => $ghosts, 'total' => count($ghosts)]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MaintenanceController] findGhostMembers failed', [
                'error' => $e->getMessage(),
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /api/v1/admin/maintenance/fix-display-name/{teamId}
     * Fix a circle's display_name to match its sanitized_name.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function fixDisplayName(string $teamId): JSONResponse {
        try {
            $newName = $this->maintenanceService->fixDisplayName($teamId);
            return new JSONResponse(['success' => true, 'newName' => $newName]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /api/v1/admin/maintenance/assign-owner/{teamId}
     * Repair a team with no owner.
     * Promotes the highest-level existing member, or inserts the calling admin.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function repairMissingOwner(string $teamId): JSONResponse {
        try {
            $newOwner = $this->maintenanceService->repairMissingOwner($teamId);
            return new JSONResponse(['success' => true, 'newOwner' => $newOwner]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /api/v1/admin/maintenance/repair-duplicate-member/{teamId}
     * Remove duplicate circles_member rows for the same user in a circle.
     * Keeps the highest-level row (owner survives). Body: { userId: string }
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function repairDuplicateMember(string $teamId): JSONResponse {
        $body   = $this->request->getParams();
        $userId = trim((string)($body['userId'] ?? ''));
        if ($userId === '') {
            return new JSONResponse(['error' => 'userId is required'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $removed = $this->maintenanceService->repairDuplicateMember($teamId, $userId);
            return new JSONResponse(['success' => true, 'removed' => $removed]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /api/v1/admin/maintenance/clear-cfg-single/{teamId}
     * Clears the CFG_SINGLE (1024) bit from a team that was incorrectly
     * marked as a personal circle, restoring its visibility to Circles.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function clearCfgSingle(string $teamId): JSONResponse {
        try {
            $this->maintenanceService->clearCfgSingle($teamId);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MaintenanceController] clearCfgSingle failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(),
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * DELETE /api/v1/admin/maintenance/nested-team
     * Remove a team-as-member row from another team's circles_member.
     *
     * Body: { parentTeamId: string, childTeamId: string }
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function removeNestedTeam(): JSONResponse {
        $body         = $this->request->getParams();
        $parentTeamId = isset($body['parentTeamId']) ? trim((string)$body['parentTeamId']) : '';
        $childTeamId  = isset($body['childTeamId'])  ? trim((string)$body['childTeamId'])  : '';

        if ($parentTeamId === '' || $childTeamId === '') {
            return new JSONResponse(['error' => 'parentTeamId and childTeamId are required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->maintenanceService->removeNestedTeam($parentTeamId, $childTeamId);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MaintenanceController] removeNestedTeam failed', [
                'parentTeamId' => $parentTeamId, 'childTeamId' => $childTeamId,
                'error' => $e->getMessage(),
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * DELETE /api/v1/admin/maintenance/ghost-members/{userId}
     * Remove a ghost user from all teams, or from a specific team.
     *
     * Body (JSON):
     *   teamId  string|null  If given, remove from that team only; else from all
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function removeGhostMember(string $userId): JSONResponse {
        $userId = trim($userId);
        if ($userId === '') {
            return new JSONResponse(['error' => 'userId is required'], Http::STATUS_BAD_REQUEST);
        }

        $body   = $this->request->getParams();
        $teamId = isset($body['teamId']) && is_string($body['teamId']) && $body['teamId'] !== ''
            ? trim($body['teamId'])
            : null;

        try {
            $removed = $this->maintenanceService->removeGhostMember($userId, $teamId);
            return new JSONResponse(['removed' => $removed]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MaintenanceController] removeGhostMember failed', [
                'userId' => $userId, 'error' => $e->getMessage(),
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Reset a team's user-managed config bits to clean defaults.
     * Returns { oldConfig, newConfig } so the UI can confirm the change.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function resetTeamConfig(string $teamId): JSONResponse {
        try {
            $result = $this->maintenanceService->resetTeamConfig($teamId);
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MaintenanceController] resetTeamConfig failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(),
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Scan for teams with corrupted config bits (system bits set on user teams).
     * Returns an array of { id, name, config, badBits } — one entry per affected team.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function checkConfigIntegrity(): JSONResponse {
        try {
            $issues = $this->maintenanceService->checkConfigIntegrity();
            return new JSONResponse(['issues' => $issues]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MaintenanceController] checkConfigIntegrity failed', [
                'error' => $e->getMessage(),
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // Per-user team overview (Audit tab — "Find teams for a user")
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/admin/maintenance/users/{userId}/teams
     * Return every user-team the given NC user belongs to, with role, owner,
     * and the source of membership (direct / group / sub-team).
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function listTeamsForUser(string $userId): JSONResponse {
        $userId = trim($userId);
        if ($userId === '') {
            return new JSONResponse(['error' => 'userId is required'], Http::STATUS_BAD_REQUEST);
        }
        if ($this->userManager->get($userId) === null) {
            return new JSONResponse(['error' => 'User not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $rows = $this->maintenanceService->listTeamsForUser($userId);
            return new JSONResponse(['teams' => $rows]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MaintenanceController] listTeamsForUser failed', [
                'userId' => $userId, 'error' => $e->getMessage(),
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /api/v1/admin/maintenance/users/{userId}/remove-from-teams
     * Body: { teamIds: string[] }
     * Removes the user from each given team (direct memberships only,
     * non-owner only). Returns per-team outcome so the UI can show partial
     * successes and per-row failure messages.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function removeUserFromTeams(string $userId, array $teamIds = []): JSONResponse {
        $userId = trim($userId);
        if ($userId === '') {
            return new JSONResponse(['error' => 'userId is required'], Http::STATUS_BAD_REQUEST);
        }
        if ($this->userManager->get($userId) === null) {
            return new JSONResponse(['error' => 'User not found'], Http::STATUS_NOT_FOUND);
        }
        if (empty($teamIds)) {
            return new JSONResponse(['error' => 'teamIds is required'], Http::STATUS_BAD_REQUEST);
        }

        $results = [];
        foreach ($teamIds as $teamId) {
            $teamId = is_string($teamId) ? trim($teamId) : '';
            if ($teamId === '') {
                continue;
            }
            try {
                $this->maintenanceService->adminRemoveUserFromTeam($teamId, $userId);
                $results[] = ['teamId' => $teamId, 'ok' => true];
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][MaintenanceController] removeUserFromTeams: per-team failure', [
                    'teamId' => $teamId, 'userId' => $userId, 'error' => $e->getMessage(),
                ]);
                $results[] = ['teamId' => $teamId, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        return new JSONResponse(['results' => $results]);
    }
}
