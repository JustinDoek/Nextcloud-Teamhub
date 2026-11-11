<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\TeamBannerService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Handles team banner upload, removal and serving.
 *
 * Routes:
 *   POST   /api/v1/teams/{teamId}/banner — upload a new banner
 *   DELETE /api/v1/teams/{teamId}/banner — remove the banner
 *   GET    /api/v1/teams/{teamId}/banner — serve the banner (browser-cacheable)
 *
 * Auth:
 *   Upload / remove: moderator level or above (level ≥ 4) — the same boundary
 *                    the team picture uses, because it is the same act: a
 *                    change to how the team presents itself to its members.
 *   Serve:           any team member, direct or inherited.
 *
 * Deliberately a separate controller from {@see TeamImageController} and not a
 * second pair of methods on it: the two differ in every rule that matters
 * (size cap, dimensions, storage) and the only thing they would share is the
 * membership gate, which is one line. See TeamBannerService's docblock.
 */
class TeamBannerController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private TeamBannerService $teamBannerService,
        private MemberService     $memberService,
        private LoggerInterface   $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // -------------------------------------------------------------------------
    // Upload
    // -------------------------------------------------------------------------

    /**
     * Upload a team banner.
     *
     * Expects a multipart/form-data POST with a file field named "banner".
     * The image is validated, scaled to fit 1920×1080 px and stored as JPEG.
     *
     * Returns: { "banner_url": "/apps/teamhub/api/v1/teams/{teamId}/banner?v=…" }
     */
    #[NoAdminRequired]
    public function upload(string $teamId): JSONResponse {

        try {
            $this->memberService->requireModeratorLevel($teamId);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        $file = $this->request->getUploadedFile('banner');

        if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new JSONResponse(
                ['error' => 'No file uploaded or upload error: ' . ($file['error'] ?? UPLOAD_ERR_NO_FILE)],
                Http::STATUS_BAD_REQUEST
            );
        }

        if (($file['size'] ?? 0) > TeamBannerService::MAX_BYTES) {
            return new JSONResponse(
                ['error' => 'File too large. Maximum size is ' . (int)(TeamBannerService::MAX_BYTES / (1024 * 1024)) . ' MB.'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $tmpPath  = $file['tmp_name'] ?? '';
        $mimeType = $file['type'] ?? 'application/octet-stream';

        if (!$tmpPath || !is_readable($tmpPath)) {
            return new JSONResponse(['error' => 'Uploaded file is not readable'], Http::STATUS_BAD_REQUEST);
        }

        $rawData = file_get_contents($tmpPath);
        if ($rawData === false) {
            return new JSONResponse(['error' => 'Failed to read uploaded file'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        try {
            $this->teamBannerService->storeBanner($teamId, $rawData, $mimeType);
            return new JSONResponse(['banner_url' => $this->teamBannerService->getBannerUrl($teamId)]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub] TeamBannerController upload error', ['exception' => $e, 'teamId' => $teamId]);
            return new JSONResponse(['error' => 'Internal error while storing banner'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // Remove
    // -------------------------------------------------------------------------

    /**
     * Remove the team banner.
     *
     * Returns: { "banner_url": null }
     */
    #[NoAdminRequired]
    public function remove(string $teamId): JSONResponse {

        try {
            $this->memberService->requireModeratorLevel($teamId);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        try {
            $this->teamBannerService->removeBanner($teamId);
            return new JSONResponse(['banner_url' => null]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub] TeamBannerController remove error', ['exception' => $e, 'teamId' => $teamId]);
            return new JSONResponse(['error' => 'Failed to remove banner'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // Serve
    // -------------------------------------------------------------------------

    /**
     * Serve the team banner.
     *
     * Membership-gated for the same reason the team picture is: without it,
     * any authenticated user could read any team's banner by iterating
     * teamId. Browser-cacheable — the URL carries the stored file's
     * modification time, so a replaced banner is a new URL rather than a
     * stale cache entry.
     *
     * Returns 404 when the team has no banner, 403 when the caller is not a
     * member.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function serve(string $teamId): DataDisplayResponse|JSONResponse {

        try {
            $this->memberService->requireMemberLevel($teamId);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        $banner = $this->teamBannerService->getServableBanner($teamId);

        if ($banner === null) {
            return new JSONResponse(['error' => 'No banner'], Http::STATUS_NOT_FOUND);
        }

        return new DataDisplayResponse($banner['data'], Http::STATUS_OK, [
            'Content-Type'  => $banner['mime'],
            'Cache-Control' => 'public, max-age=86400',
            'ETag'          => '"' . md5($banner['data']) . '"',
        ]);
    }
}
