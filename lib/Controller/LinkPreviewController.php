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
            $client = $this->clientService->newClient();

            // Manual redirect loop. We disable the client's own redirect-following
            // so we can re-validate the resolved IP of EVERY hop (the first URL was
            // already checked by isUrlAllowed, but a server can 30x us to an internal
            // address afterwards — the classic post-allowlist SSRF). Cap at 3 hops.
            $currentUrl = $url;
            $response   = null;
            $maxHops    = 3;

            for ($hop = 0; $hop <= $maxHops; $hop++) {

                // Re-validate this hop's host + resolved IP before fetching it.
                // isUrlAllowed re-resolves DNS and rejects private/loopback/
                // link-local/encoded forms — so a redirect to an internal target
                // is caught here, not followed.
                if (!$this->linkPreviewService->isUrlAllowed($currentUrl)) {
                    return new JSONResponse(['error' => 'URL not allowed'], Http::STATUS_BAD_REQUEST);
                }

                $response = $client->get($currentUrl, [
                    'timeout'         => 8,
                    'allow_redirects' => false,
                    'headers'         => ['User-Agent' => 'TeamHub/1.0 (image proxy)'],
                ]);

                $status = $response->getStatusCode();
                if ($status >= 300 && $status < 400) {
                    if ($hop === $maxHops) {
                        return new JSONResponse(['error' => 'Too many redirects'], Http::STATUS_BAD_GATEWAY);
                    }
                    $location = $response->getHeader('Location');
                    if ($location === '') {
                        return new JSONResponse(['error' => 'Bad redirect'], Http::STATUS_BAD_GATEWAY);
                    }
                    // Resolve relative redirects against the current URL, then loop
                    // back to re-validate the new target's IP.
                    $currentUrl = $this->resolveRedirectLocation($currentUrl, $location);
                    continue;
                }

                // Non-redirect response — done.
                break;
            }

            if ($response === null) {
                return new JSONResponse(['error' => 'Failed to fetch image'], Http::STATUS_BAD_GATEWAY);
            }

            $body = (string) $response->getBody();
            // Cap at 2 MB
            if (strlen($body) > 2097152) {
                return new JSONResponse(['error' => 'Image too large'], Http::STATUS_BAD_REQUEST);
            }

            $upstreamType = $response->getHeader('Content-Type') ?: '';
            // Strip any "; charset=..." suffix.
            if (($semi = strpos($upstreamType, ';')) !== false) {
                $upstreamType = substr($upstreamType, 0, $semi);
            }
            $upstreamType = trim($upstreamType);

            // Determine the content type to serve.
            // Prefer upstream when it starts with image/. Otherwise sniff the
            // bytes — many CDNs serve images as application/octet-stream or
            // omit the header. Reject if the bytes are not actually an image.
            $contentType = null;
            if (str_starts_with($upstreamType, 'image/')) {
                $contentType = $upstreamType;
            } else {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $sniffed = $finfo->buffer($body);
                if (is_string($sniffed) && str_starts_with($sniffed, 'image/')) {
                    $contentType = $sniffed;
                }
            }

            if ($contentType === null) {
                return new JSONResponse(['error' => 'Not an image'], Http::STATUS_BAD_REQUEST);
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

    /**
     * Resolve a (possibly relative) redirect Location against the URL it came from.
     * Absolute URLs pass through; root-relative and relative paths are joined onto
     * the base origin / path so the next-hop validation sees a complete URL.
     */
    private function resolveRedirectLocation(string $baseUrl, string $location): string {
        // Already absolute.
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $base = parse_url($baseUrl);
        if (!isset($base['scheme'], $base['host'])) {
            return $location;
        }
        $origin = $base['scheme'] . '://' . $base['host']
            . (isset($base['port']) ? ':' . $base['port'] : '');

        // Root-relative.
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        // Path-relative — strip the last path segment of the base.
        $path = $base['path'] ?? '/';
        $dir  = substr($path, 0, strrpos($path, '/') + 1);
        return $origin . $dir . $location;
    }
}
