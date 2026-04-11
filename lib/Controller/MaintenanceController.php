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
    // Maintenance — orphaned teams
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
}
