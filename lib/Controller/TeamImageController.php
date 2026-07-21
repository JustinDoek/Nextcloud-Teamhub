<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\TeamImageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Handles team image upload, removal and serving.
 *
 * Routes:
 *   POST   /api/v1/teams/{teamId}/image   — upload a new image
 *   DELETE /api/v1/teams/{teamId}/image   — remove the image
 *   GET    /api/v1/teams/{teamId}/image   — serve the image (browser-cacheable)
 *
 * Auth:
 *   Upload / remove: moderator level or above (level ≥ 4), same as inviting members.
 *   Serve:           any authenticated team member (level > 0).
 */
class TeamImageController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private TeamImageService $teamImageService,
        private MemberService    $memberService,
        private LoggerInterface  $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // -------------------------------------------------------------------------
    // Upload
    // -------------------------------------------------------------------------

    /**
     * Upload a team image.
     *
     * Expects a multipart/form-data POST with a file field named "image".
     * The image is validated, resized to ≤200×200 px, and stored as JPEG.
     *
     * Returns: { "image_url": "/apps/teamhub/api/v1/teams/{teamId}/image" }
     */
    #[NoAdminRequired]
    public function upload(string $teamId): JSONResponse {

        try {
            $this->memberService->requireModeratorLevel($teamId);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        $file = $this->request->getUploadedFile('image');

        if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            return new JSONResponse(
                ['error' => 'No file uploaded or upload error: ' . $uploadError],
                Http::STATUS_BAD_REQUEST
            );
        }

        // Safety: 2 MB hard cap before attempting to decode
        $maxBytes = 2 * 1024 * 1024;
        if (($file['size'] ?? 0) > $maxBytes) {
            return new JSONResponse(
                ['error' => 'File too large. Maximum size is 2 MB.'],
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
            $this->teamImageService->storeImage($teamId, $rawData, $mimeType);
            $imageUrl = $this->teamImageService->getImageUrl($teamId);
            return new JSONResponse(['image_url' => $imageUrl]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub] TeamImageController upload error', ['exception' => $e, 'teamId' => $teamId]);
            return new JSONResponse(['error' => 'Internal error while storing image'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // Remove
    // -------------------------------------------------------------------------

    /**
     * Remove the team image.
     *
     * Returns: { "image_url": null }
     */
    #[NoAdminRequired]
    public function remove(string $teamId): JSONResponse {

        try {
            $this->memberService->requireModeratorLevel($teamId);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        try {
            $this->teamImageService->removeImage($teamId);
            return new JSONResponse(['image_url' => null]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub] TeamImageController remove error', ['exception' => $e, 'teamId' => $teamId]);
            return new JSONResponse(['error' => 'Failed to remove image'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // Serve
    // -------------------------------------------------------------------------

    /**
     * Serve the raw JPEG image.
     *
     * Browser-cacheable: sends Cache-Control and ETag headers.
     * Returns 404 if no image is stored for this team.
     * Returns 403 if the user is not a team member.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function serve(string $teamId): DataDisplayResponse|JSONResponse {

        // Membership check: any team member (including indirect members added
        // via a group or sub-team) may view the image. requireMemberLevel
        // accepts level >= 1 and indirect access, which is exactly the intended
        // scope. Without this check any authenticated user could fetch any
        // team's image by iterating teamId.
        try {
            $this->memberService->requireMemberLevel($teamId);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        $data = $this->teamImageService->getImageData($teamId);

        if ($data === null) {
            return new JSONResponse(['error' => 'No image'], Http::STATUS_NOT_FOUND);
        }

        $etag     = md5($data);
        $response = new DataDisplayResponse($data, Http::STATUS_OK, [
            'Content-Type'  => TeamImageService::MIME_TYPE,
            'Cache-Control' => 'public, max-age=86400',
            'ETag'          => '"' . $etag . '"',
        ]);

        return $response;
    }
}
