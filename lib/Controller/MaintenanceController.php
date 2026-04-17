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
}
