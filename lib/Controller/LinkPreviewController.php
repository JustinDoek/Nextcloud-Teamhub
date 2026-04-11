<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\LinkPreviewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Resolves a URL to Open Graph / HTML metadata server-side.
 *
 * GET /apps/teamhub/api/v1/preview?url=https://example.com
 *
 * Authentication: any logged-in Nextcloud user (NoAdminRequired).
 * CSRF: exempt because this is a GET request used by the frontend JS.
 *
 * Response shape:
 * {
 *   "url":         "https://example.com",
 *   "title":       "Example Domain",
 *   "description": "…",
 *   "image":       "https://example.com/og.png",   // or null
 *   "site_name":   "example.com",
 *   "is_image":    false
 * }
 *
 * Returns 400 if the URL is missing or invalid.
 * Returns 204 (no content) if the URL resolved but yielded no useful metadata.
 * Returns 500 on unexpected server error.
 */
class LinkPreviewController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private LinkPreviewService $linkPreviewService,
        private IClientService     $clientService,
        private LoggerInterface    $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function resolve(string $url = ''): JSONResponse {

        if ($url === '') {
            return new JSONResponse(['error' => 'Missing url parameter'], Http::STATUS_BAD_REQUEST);
        }

        // Basic format validation — full security policy is inside the service
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new JSONResponse(['error' => 'Invalid URL'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $result = $this->linkPreviewService->resolve($url);

            if ($result === null) {
                // Service could not resolve — return empty 204 so the frontend
                // can show a plain fallback card without treating this as an error
                return new JSONResponse(null, Http::STATUS_NO_CONTENT);
            }

            return new JSONResponse($result);

        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub] LinkPreviewController unexpected error: ' . $e->getMessage(), [
                'exception' => $e,
                'url'       => $url,
            ]);
            return new JSONResponse(['error' => 'Internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/v1/preview/image?url=https://example.com/og.png
     *
     * Proxies an external image through the TeamHub backend so the browser
     * never needs to load it directly. This sidesteps the NC Content Security
     * Policy which blocks img-src from external origins.
     *
     * Security constraints (enforced by LinkPreviewService::isAllowedUrl):
     *   - https:// only
     *   - no private/loopback addresses
     *   - URL must pass FILTER_VALIDATE_URL
     * Response body size is capped at 2 MB. Content-Type is echoed from the
     * upstream response. Non-image content types are rejected with 400.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function proxyImage(string $url = ''): DataDisplayResponse|JSONResponse {

        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return new JSONResponse(['error' => 'Invalid URL'], Http::STATUS_BAD_REQUEST);
        }

        // Reuse the same allowlist check as the resolve endpoint
        if (!$this->linkPreviewService->isUrlAllowed($url)) {
            return new JSONResponse(['error' => 'URL not allowed'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->get($url, [
                'timeout'         => 8,
                'allow_redirects' => ['max' => 3],
                'headers'         => ['User-Agent' => 'TeamHub/1.0 (image proxy)'],
            ]);

            $contentType = $response->getHeader('Content-Type') ?: 'image/jpeg';
            // Only proxy actual image content types
            if (!str_starts_with($contentType, 'image/')) {
                return new JSONResponse(['error' => 'Not an image'], Http::STATUS_BAD_REQUEST);
            }

            $body = (string) $response->getBody();
            // Cap at 2 MB
            if (strlen($body) > 2097152) {
                return new JSONResponse(['error' => 'Image too large'], Http::STATUS_BAD_REQUEST);
            }

            $resp = new DataDisplayResponse($body, Http::STATUS_OK, [
                'Content-Type'  => $contentType,
                'Cache-Control' => 'public, max-age=3600',
            ]);
            return $resp;

        } catch (\Throwable $e) {
            return new JSONResponse(['error' => 'Failed to fetch image'], Http::STATUS_BAD_GATEWAY);
        }
    }
}
