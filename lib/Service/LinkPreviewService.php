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

            // Manual redirect loop with per-hop IP re-validation. The client's
            // own redirect-following is disabled so a public URL cannot 30x us
            // to an internal address after passing the initial isAllowedUrl()
            // gate (the classic post-allowlist SSRF), and every hop's host is
            // re-resolved and re-checked before we fetch it. Mirrors the defence
            // in LinkPreviewController::proxyImage.
            $currentUrl = $url;
            $response   = null;
            $maxHops    = 5;

            for ($hop = 0; $hop <= $maxHops; $hop++) {
                // Re-validate this hop's host + resolved IP before fetching it.
                // The very first URL was already checked by isAllowedUrl() above;
                // this catches redirect targets and DNS changes between hops.
                if (!$this->isAllowedUrl($currentUrl)) {
                    return null;
                }

                $response = $client->get($currentUrl, [
                    'timeout'         => self::TIMEOUT,
                    'allow_redirects' => false,
                    'headers'         => [
                        // Present as a browser so sites don't block us outright
                        'User-Agent' => 'Mozilla/5.0 (compatible; Nextcloud TeamHub link preview)',
                        'Accept'     => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                    ],
                    // Limit response size — we only need the <head> section. The
                    // non-HTML abort is skipped for redirect responses, which are
                    // handled by the loop below.
                    'on_headers' => function (\Psr\Http\Message\ResponseInterface $r) {
                        $status = $r->getStatusCode();
                        if ($status >= 300 && $status < 400) {
                            return;
                        }
                        $ct = $r->getHeaderLine('Content-Type');
                        if (!empty($ct) && !str_contains($ct, 'text/html') && !str_contains($ct, 'application/xhtml')) {
                            // Non-HTML resource (e.g. PDF, ZIP) — abort early
                            throw new \RuntimeException('Non-HTML content type: ' . $ct);
                        }
                    },
                ]);

                $status = $response->getStatusCode();
                if ($status >= 300 && $status < 400) {
                    if ($hop === $maxHops) {
                        return null;
                    }
                    $location = $response->getHeader('Location');
                    if ($location === '') {
                        return null;
                    }
                    // Resolve relative redirects against the current URL, then
                    // loop back to re-validate the new target's resolved IP.
                    $currentUrl = $this->resolveRedirectLocation($currentUrl, $location);
                    continue;
                }

                // Non-redirect response — done.
                break;
            }

            if ($response === null) {
                return null;
            }

            $body = (string) $response->getBody();
            // Truncate to avoid parsing huge documents
            if (strlen($body) > self::MAX_BODY_BYTES) {
                $body = substr($body, 0, self::MAX_BODY_BYTES);
            }

            // Parse against the originally-requested URL so the response's
            // `url` field (which the frontend keys previews on) stays stable
            // across redirects — matches the pre-redirect-loop behaviour.
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
     *
     * This is the FIRST gate. It validates scheme + host string and, critically,
     * resolves the host to its IP addresses and rejects any that fall in a
     * private / loopback / link-local / reserved range (DNS-rebinding defence).
     *
     * Because DNS can change between this check and the actual fetch, and because
     * a server can redirect to an internal address after passing this gate, the
     * controller MUST additionally re-validate each hop's resolved IP during the
     * fetch (see LinkPreviewController::proxyImage and isResolvedIpSafe).
     */
    public function isUrlAllowed(string $url): bool {
        return $this->isAllowedUrl($url);
    }

    private function isAllowedUrl(string $url): bool {
        $parsed = parse_url($url);
        if (!isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }
        if ($parsed['scheme'] !== 'https') {
            return false;
        }

        $host = strtolower($parsed['host']);

        // Reject obvious loopback / wildcard literals up front.
        $blockedLiterals = ['localhost', '127.0.0.1', '::1', '[::1]', '0.0.0.0'];
        if (in_array($host, $blockedLiterals, true)) {
            return false;
        }

        // Strip IPv6 brackets if present (parse_url keeps them for the host).
        $bare = trim($host, '[]');

        // Reject decimal / octal / hex encoded IPv4 forms (e.g. http://2130706433,
        // http://0x7f000001, http://017700000001). These are valid to the resolver
        // but bypass naive dotted-quad string checks. We detect a "host" that is
        // entirely numeric / hex but is NOT a normal dotted-quad, and reject it.
        if ($this->isEncodedIpForm($bare)) {
            return false;
        }

        // Resolve the host to every IP it points at, and reject if ANY of them is
        // in a forbidden range. Resolving here (not just string-matching the host)
        // is what defeats a hostname that points at 127.0.0.1 / 169.254.169.254 /
        // a private LAN address. parse_url already gave us a hostname or a literal IP.
        $ips = $this->resolveHostIps($bare);
        if ($ips === []) {
            // Could not resolve — fail closed.
            return false;
        }
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a (possibly relative) redirect Location against the URL it came
     * from. Absolute URLs pass through; root-relative and path-relative targets
     * are joined onto the base origin/path so the next-hop validation in
     * resolve() sees a complete URL. A non-https result is rejected at that
     * next isAllowedUrl() check (fail-closed).
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

    /**
     * True when the literal looks like an encoded (non-dotted-quad) IPv4:
     * a single decimal integer, an 0x-hex form, or a leading-zero octal form.
     * A normal dotted IPv4 (e.g. 93.184.216.34) and any real hostname return false.
     */
    private function isEncodedIpForm(string $host): bool {
        // Pure decimal integer with no dots, e.g. "2130706433"
        if (preg_match('/^\d+$/', $host) === 1) {
            return true;
        }
        // 0x… hex (with or without dots), e.g. "0x7f000001" or "0x7f.0x0.0x0.0x1"
        if (preg_match('/^(0x[0-9a-f]+)(\.0x[0-9a-f]+)*$/i', $host) === 1) {
            return true;
        }
        // Octal dotted form with leading zeros, e.g. "0177.0.0.01"
        if (preg_match('/^0\d+(\.\d+){0,3}$/', $host) === 1) {
            return true;
        }
        return false;
    }

    /**
     * Resolve a host (or pass through a literal IP) to a list of IP strings.
     * Returns [] when nothing resolves.
     *
     * @return string[]
     */
    private function resolveHostIps(string $host): array {
        // Literal IP? Return as-is.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        // IPv4 records
        $a = @gethostbynamel($host);
        if (is_array($a)) {
            $ips = array_merge($ips, $a);
        }

        // IPv6 records (and any others) via dns_get_record
        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * True only when $ip is a routable public address.
     *
     * Rejects: private ranges, loopback, link-local (incl. the cloud metadata
     * IP 169.254.169.254), reserved ranges, IPv6 unique-local (fc00::/7),
     * IPv6 loopback (::1), the unspecified address, and IPv4-mapped IPv6
     * (::ffff:a.b.c.d) whose embedded v4 is itself non-public.
     *
     * Public so the controller can re-check each redirect hop.
     */
    public function isResolvedIpSafe(string $ip): bool {
        return $this->isPublicIp($ip);
    }

    private function isPublicIp(string $ip): bool {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // IPv4-mapped / -compatible IPv6 (e.g. ::ffff:169.254.169.254):
        // extract the trailing dotted-quad and validate THAT as IPv4.
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m) === 1
            || preg_match('/^::(\d{1,3}(?:\.\d{1,3}){3})$/', $ip, $m) === 1) {
            return $this->isPublicIp($m[1]);
        }

        // PHP's own filter rejects private + reserved ranges. For IPv4 this covers
        // 10/8, 172.16/12, 192.168/16, 127/8, 169.254/16 (link-local, incl. the
        // 169.254.169.254 metadata IP), 0/8, 240/4, etc. For IPv6 it covers ::1,
        // ::, fe80::/10, and the documentation ranges.
        $isPublicByFilter = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;

        if (!$isPublicByFilter) {
            return false;
        }

        // Belt-and-suspenders: PHP's NO_RES_RANGE does not flag IPv6 unique-local
        // (fc00::/7) on all versions. Reject it explicitly.
        if (str_contains($ip, ':')) {
            $packed = @inet_pton($ip);
            if ($packed === false) {
                return false;
            }
            // fc00::/7 — first byte is 0xFC or 0xFD.
            $firstByte = ord($packed[0]);
            if (($firstByte & 0xFE) === 0xFC) {
                return false;
            }
        }

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
