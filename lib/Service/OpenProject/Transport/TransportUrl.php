<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject\Transport;

/**
 * The URL and logging helpers both transports need (v4.13.4).
 *
 * Extracted from `OpenProjectClient` when the transport seam was cut, so
 * that the two transports cannot drift on the two rules that matter:
 *
 *  - **What counts as a host.** An absolute http(s) URL with a host part,
 *    and nothing else. This is the SSRF boundary from DESIGN §2.120 — user
 *    input never becomes a host, anywhere — and it has to mean the same
 *    thing whichever transport read the setting.
 *  - **What reaches the log.** The endpoint's path and never its query
 *    string: a project search carries the user's search text, and an
 *    activity query carries ids.
 *
 * Static, stateless and deliberately not injectable: these are value rules,
 * not collaborators, and `OpenProjectClient::isValidHost()` has been a
 * public static since 4.9.3.
 */
final class TransportUrl {

    private function __construct() {
    }

    /** Is `$url` an absolute http(s) URL with a host part. */
    public static function isValidHost(string $url): bool {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        return in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }

    /** Same scheme, host and port — the test a returned link has to pass. */
    public static function sameOrigin(string $a, string $b): bool {
        $pa = parse_url($a);
        $pb = parse_url($b);
        if ($pa === false || $pb === false) {
            return false;
        }
        $norm = static fn (array $p): string => strtolower((string)($p['scheme'] ?? ''))
            . '://' . strtolower((string)($p['host'] ?? ''))
            . ':' . (string)($p['port'] ?? '');
        return $norm($pa) === $norm($pb);
    }

    /** The endpoint without its query string, for logs. */
    public static function safePath(string $endpoint): string {
        $q = strpos($endpoint, '?');
        return $q === false ? $endpoint : substr($endpoint, 0, $q);
    }
}
