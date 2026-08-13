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
 *   Serve:           any team member, direct or inherited — the boundary
 *                    Circles applies to its own avatar route.
 *
 * Upload and remove write TeamHub's legacy app-data image only. On NC 34+ the
 * frontend sends new pictures to Circles' OCS avatar routes instead; serve()
 * reads both and prefers the Teams avatar.
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
     * Serve the team picture.
     *
     * v4.6.25 — serves whichever storage holds it: the Nextcloud Teams
     * (Circles) avatar on NC 34+, otherwise TeamHub's own app-data image. One
     * URL for both means an ordinary `<img src>` can load the Teams avatar,
     * which the OCS route it lives behind does not allow. See DESIGN §2.95.
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
        //
        // This is deliberately the same boundary Circles enforces on its own
        // avatar route: PermissionService::userMustBeMember() accepts a direct
        // member row and falls back to inherited membership via a group or
        // parent circle. Serving the Teams avatar from here must not widen who
        // can see it.
        try {
            $this->memberService->requireMemberLevel($teamId);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        $image = $this->teamImageService->getServableImage($teamId);

        if ($image === null) {
            return new JSONResponse(['error' => 'No image'], Http::STATUS_NOT_FOUND);
        }

        $etag     = md5($image['data']);
        $response = new DataDisplayResponse($image['data'], Http::STATUS_OK, [
            'Content-Type'  => $image['mime'],
            'Cache-Control' => 'public, max-age=86400',
            'ETag'          => '"' . $etag . '"',
        ]);

        return $response;
    }
}
