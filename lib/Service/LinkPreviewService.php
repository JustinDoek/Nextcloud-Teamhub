<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Fetches Open Graph / basic HTML metadata for a given URL server-side.
 *
 * This avoids CORS restrictions that would block a browser-side fetch,
 * and keeps external network calls out of the frontend entirely.
 *
 * Extensibility notes:
 *   - To support additional metadata formats (oEmbed, JSON-LD, Twitter cards),
 *     add a private parse*() method and call it from resolve() before returning.
 *   - To add per-domain custom resolvers (e.g. YouTube), inject them as an
 *     array of ILinkResolver and loop through them first.
 *   - The result array shape is intentionally flat and serialisable so it can
 *     be cached easily by a future caching layer.
 */
class LinkPreviewService {

    /** Maximum response body size to read (512 KB) — prevents memory issues */
    private const MAX_BODY_BYTES = 524288;

    /** Request timeout in seconds */
    private const TIMEOUT = 8;

    /** Image file extensions we recognise as direct image URLs */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'];

    public function __construct(
        private IClientService $clientService,
        private LoggerInterface $logger,
    ) {}

    /**
     * Resolve a URL to preview metadata.
     *
     * Returns an array with keys:
     *   url          string  — the original URL (always present)
     *   title        string|null
     *   description  string|null
     *   image        string|null  — absolute URL to preview image
     *   site_name    string|null  — e.g. "GitHub", "YouTube"
     *   is_image     bool         — true when the URL itself is an image file
     *
     * Returns null on network failure or if the URL is not fetchable.
     */
    public function resolve(string $url): ?array {

        // Validate URL before doing any network call
        if (!$this->isAllowedUrl($url)) {
            return null;
        }

        // If the URL itself points to an image, return it directly as a preview
        if ($this->isImageUrl($url)) {
            return [
                'url'         => $url,
                'title'       => $this->filenameFromUrl($url),
                'description' => null,
                'image'       => $this->proxyImageUrl($url),
                'site_name'   => null,
                'is_image'    => true,
            ];
        }

        try {
            $client = $this->clientService->newClient();
            $response = $client->get($url, [
                'timeout'         => self::TIMEOUT,
                'allow_redirects' => ['max' => 5],
                'headers'         => [
                    // Present as a browser so sites don't block us outright
                    'User-Agent' => 'Mozilla/5.0 (compatible; Nextcloud TeamHub link preview)',
                    'Accept'     => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                ],
                // Limit response size — we only need the <head> section
                'on_headers' => function (\Psr\Http\Message\ResponseInterface $r) {
                    $ct = $r->getHeaderLine('Content-Type');
                    if (!empty($ct) && !str_contains($ct, 'text/html') && !str_contains($ct, 'application/xhtml')) {
                        // Non-HTML resource (e.g. PDF, ZIP) — abort early
                        throw new \RuntimeException('Non-HTML content type: ' . $ct);
                    }
                },
            ]);

            $body = (string) $response->getBody();
            // Truncate to avoid parsing huge documents
            if (strlen($body) > self::MAX_BODY_BYTES) {
                $body = substr($body, 0, self::MAX_BODY_BYTES);
            }

            $meta = $this->parseOpenGraph($body, $url);
            return $meta;

        } catch (\Throwable $e) {
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Parse Open Graph meta tags from HTML, with <title> fallback.
     */
    private function parseOpenGraph(string $html, string $sourceUrl): array {
        // Suppress XML/HTML parse errors — many real-world pages have malformed HTML
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        // Use HTML5-safe loading: force UTF-8 so DOMDocument doesn't mangle encoding
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_use_internal_errors($prev);
        libxml_clear_errors();

        $og = [];
        $metas = $doc->getElementsByTagName('meta');
        foreach ($metas as $meta) {
            /** @var \DOMElement $meta */
            $property = $meta->getAttribute('property') ?: $meta->getAttribute('name');
            $content  = $meta->getAttribute('content');
            if (!$property || !$content) continue;

            // Open Graph
            if ($property === 'og:title')       { $og['title']       = $content; }
            if ($property === 'og:description')  { $og['description'] = $content; }
            if ($property === 'og:image')        { $og['image']       = $content; }
            if ($property === 'og:site_name')    { $og['site_name']   = $content; }
            // Twitter fallbacks
            if ($property === 'twitter:title' && empty($og['title']))       { $og['title']       = $content; }
            if ($property === 'twitter:description' && empty($og['description'])) { $og['description'] = $content; }
            if ($property === 'twitter:image' && empty($og['image']))       { $og['image']       = $content; }
        }

        // Fallback: <title> tag
        if (empty($og['title'])) {
            $titles = $doc->getElementsByTagName('title');
            if ($titles->length > 0) {
                $og['title'] = trim($titles->item(0)->textContent);
            }
        }

        // Fallback: derive site_name from hostname
        if (empty($og['site_name'])) {
            $host = parse_url($sourceUrl, PHP_URL_HOST);
            if ($host) {
                $og['site_name'] = $host;
            }
        }

        // Ensure image URL is absolute
        if (!empty($og['image']) && !str_starts_with($og['image'], 'http')) {
            $base = parse_url($sourceUrl, PHP_URL_SCHEME) . '://' . parse_url($sourceUrl, PHP_URL_HOST);
            $og['image'] = $base . '/' . ltrim($og['image'], '/');
        }

        // Rewrite image URL through the TeamHub proxy so the browser
        // never loads external images directly (avoids NC CSP violations).
        $imageUrl = isset($og['image']) ? $this->proxyImageUrl($og['image']) : null;

        return [
            'url'         => $sourceUrl,
            'title'       => $og['title'] ?? null,
            'description' => isset($og['description']) ? $this->truncate($og['description'], 200) : null,
            'image'       => $imageUrl,
            'site_name'   => $og['site_name'] ?? null,
            'is_image'    => false,
        ];
    }

    /**
     * Build a TeamHub-proxied image URL from an external image URL.
     * The browser loads /apps/teamhub/api/v1/preview/image?url=... which
     * fetches the image server-side and returns it — no CSP violation.
     */
    private function proxyImageUrl(string $externalUrl): string {
        return '/apps/teamhub/api/v1/preview/image?url=' . urlencode($externalUrl);
    }

    /**
     * URL policy: only allow https:// (no http, no javascript:, no file://, no loopback).
     * Public so LinkPreviewController can reuse it for the image proxy endpoint.
     */
    public function isUrlAllowed(string $url): bool {
        return $this->isAllowedUrl($url);
    }

    private function isAllowedUrl(string $url): bool {
        $parsed = parse_url($url);
        if (!isset($parsed['scheme'], $parsed['host'])) return false;
        if ($parsed['scheme'] !== 'https') return false;

        $host = strtolower($parsed['host']);
        // Block loopback / private addresses
        $blocked = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];
        if (in_array($host, $blocked, true)) return false;
        // Block private IP ranges (basic check)
        if (preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host)) return false;

        return true;
    }

    private function isImageUrl(string $url): bool {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, self::IMAGE_EXTENSIONS, true);
    }

    private function filenameFromUrl(string $url): string {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        return basename($path) ?: $url;
    }

    private function truncate(string $text, int $maxLen): string {
        if (mb_strlen($text) <= $maxLen) return $text;
        return mb_substr($text, 0, $maxLen - 1) . '…';
    }
}
