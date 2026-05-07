<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\ArchiveService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Archive endpoints.
 *
 * Owner endpoints (NoAdminRequired):
 *   POST /api/v1/teams/{teamId}/archive           — initiate archive
 *   GET  /api/v1/teams/{teamId}/archive/status    — poll status
 *
 * Admin endpoints (AuthorizedAdminSetting):
 *   GET  /api/v1/admin/archive/pending            — list pending-deletion rows
 *   POST /api/v1/admin/archive/pending/{id}/restore — restore within grace
 *   POST /api/v1/admin/archive/pending/{id}/purge   — force hard-delete
 *   GET  /api/v1/admin/archive/settings           — read settings
 *   PUT  /api/v1/admin/archive/settings           — save settings
 */
class ArchiveController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private ArchiveService  $archiveService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // =========================================================================
    // Owner endpoints
    // =========================================================================

    /**
     * POST /api/v1/teams/{teamId}/archive
     *
     * Initiates the archive-and-delete flow for the team. The caller must be
     * the team owner (level 9). Runs synchronously — the archive ZIP is
     * produced within this request.
     *
     * Returns the pending-deletion row metadata on success.
     *
     * HTTP status codes:
     *   200 — archive produced and pending-deletion row created
     *   403 — caller is not the team owner
     *   409 — team already has a pending-deletion row
     *   413 — estimated archive size exceeds configured cap
     *   500 — archive production failed (team NOT deleted; can retry)
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function archiveTeam(string $teamId): JSONResponse {
        $this->logger->debug('[TeamHub][ArchiveController] archiveTeam called', [
            'teamId' => $teamId, 'app' => Application::APP_ID,
        ]);

        try {
            $result = $this->archiveService->produceTeamArchive($teamId);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            $status = match ((int)$code) {
                409 => Http::STATUS_CONFLICT,
                413 => Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
                default => Http::STATUS_INTERNAL_SERVER_ERROR,
            };
            $this->logger->warning('[TeamHub][ArchiveController] archiveTeam failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], $status);
        } catch (\Exception $e) {
            $status = str_contains($e->getMessage(), 'owner') ? Http::STATUS_FORBIDDEN : Http::STATUS_INTERNAL_SERVER_ERROR;
            return new JSONResponse(['error' => $e->getMessage()], $status);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/archive/status
     *
     * Returns the pending-deletion row for this team, or null if none exists.
     * Accessible by any team member — used to surface failed state in the danger zone.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getArchiveStatus(string $teamId): JSONResponse {
        try {
            $row = $this->archiveService->getTeamArchiveStatus($teamId);
            return new JSONResponse(['pending' => $row]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // =========================================================================
    // Admin endpoints
    // =========================================================================

    /**
     * GET /api/v1/admin/archive/pending
     *
     * Returns a paginated list of all pending-deletion rows.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function listPendingDeletions(): JSONResponse {
        $limit  = max(1, min(200, (int)($this->request->getParam('limit', 50))));
        $offset = max(0, (int)$this->request->getParam('offset', 0));

        try {
            $result = $this->archiveService->listPendingDeletions($limit, $offset);
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][ArchiveController] listPendingDeletions failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /api/v1/admin/archive/pending/{id}/restore
     *
     * Restores a team within the grace period. Sets status='restored' so the
     * team becomes visible again. The archive ZIP is retained.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function restorePendingDeletion(int $id): JSONResponse {
        $this->logger->debug('[TeamHub][ArchiveController] restorePendingDeletion', [
            'id' => $id, 'app' => Application::APP_ID,
        ]);

        try {
            $result = $this->archiveService->restorePendingDeletion($id);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            $status = match ((int)$e->getCode()) {
                404 => Http::STATUS_NOT_FOUND,
                409 => Http::STATUS_CONFLICT,
                default => Http::STATUS_INTERNAL_SERVER_ERROR,
            };
            return new JSONResponse(['error' => $e->getMessage()], $status);
        }
    }

    /**
     * POST /api/v1/admin/archive/pending/{id}/purge
     *
     * Immediately hard-deletes a team regardless of grace period remaining.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function purgePendingDeletion(int $id): JSONResponse {
        try {
            $result = $this->archiveService->purgePendingDeletion($id);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            $status = (int)$e->getCode() === 404 ? Http::STATUS_NOT_FOUND : Http::STATUS_INTERNAL_SERVER_ERROR;
            return new JSONResponse(['error' => $e->getMessage()], $status);
        }
    }

    /**
     * DELETE /api/v1/admin/archive/pending/{id}
     *
     * Discards a failed archive row without touching the team.
     * The team becomes fully usable again. Archive ZIP (if any partial exists)
     * is not removed — admin cleans up manually if needed.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function discardFailedArchive(int $id): JSONResponse {
        try {
            $result = $this->archiveService->discardFailedArchive($id);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            $status = match ((int)$e->getCode()) {
                404 => Http::STATUS_NOT_FOUND,
                409 => Http::STATUS_CONFLICT,
                default => Http::STATUS_INTERNAL_SERVER_ERROR,
            };
            return new JSONResponse(['error' => $e->getMessage()], $status);
        }
    }

    /**
     * POST /api/v1/admin/archive/pending/{id}/retry
     *
     * Admin-initiated retry of a failed archive. Bypasses owner-level check.
     * Records the admin UID as archived_by, includes original owner in metadata.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function retryArchive(int $id): JSONResponse {
        try {
            $result = $this->archiveService->retryArchive($id);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            $status = match ((int)$e->getCode()) {
                404 => Http::STATUS_NOT_FOUND,
                409 => Http::STATUS_CONFLICT,
                413 => Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
                default => Http::STATUS_INTERNAL_SERVER_ERROR,
            };
            return new JSONResponse(['error' => $e->getMessage()], $status);
        }
    }

    /**
     * GET /api/v1/admin/archive/settings
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function getAdminArchiveSettings(): JSONResponse {
        try {
            return new JSONResponse($this->archiveService->getAdminSettings());
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * PUT /api/v1/admin/archive/settings
     *
     * Accepts JSON body with any subset of:
     *   archiveMode     — 'hard' | 'soft30' | 'soft60'
     *   archiveFolderOwner — NC uid of the user whose Files folder receives archives
     *   archivePath     — path within that user's Files (e.g. 'Team Archives')
     *   archiveMaxMb    — integer max archive size in MB
     *   anonymizeData   — boolean
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function saveAdminArchiveSettings(): JSONResponse {
        $body = $this->request->getParams();

        try {
            $this->archiveService->saveAdminSettings($body);
            return new JSONResponse(['saved' => true]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][ArchiveController] saveAdminArchiveSettings failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
