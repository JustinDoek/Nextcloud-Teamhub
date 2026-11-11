<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

use OCA\TeamHub\Exception\ValidationException;

/**
 * The one answer to "may TeamHub put this URL in a team iframe" (v4.13.23).
 *
 * The rule existed in three places before this file: `IntegrationService`
 * checked it at registration, `TeamView.vue`'s `menuItemUrl()` checked it
 * again on the way out ("never trust stored data on the way out either"), and
 * the admin list rendered whatever was stored. Three copies of a security
 * rule is two too many — the registration copy is the one that can be
 * tightened and the render copy is the one that actually protects the user,
 * so they must not be able to disagree.
 *
 * ## What is accepted, and why only this
 *
 *   /apps/…            a route on this Nextcloud. The normal case, and the
 *                      recommended one: same origin, the user's own session,
 *                      no third party involved.
 *   /index.php/…       the same thing on an instance without pretty URLs.
 *   https://…          an absolute URL. Accepted because the previous model
 *                      accepted it and apps registered against it; a team tab
 *                      pointing at an external service is a legitimate shape.
 *
 * Everything else is refused: `javascript:` and `data:` because they execute
 * in TeamHub's own document, `http://` because a team's workspace must not
 * downgrade the page, a protocol-relative `//host` because it reads as a path
 * and resolves to another origin, and anything with a control character or a
 * newline because that is header- or attribute-injection material rather than
 * a URL.
 *
 * A relative path is **not** resolved or rewritten here. TeamHub appends its
 * own verified `?teamId=` at render time, and the team id is never taken from
 * the stored URL — see {@see TeamHubContext}.
 */
final class IframeUrl {

    public const MAX_LENGTH = 2048;

    /**
     * Validate and normalise, or throw.
     *
     * @throws ValidationException
     */
    public static function require(string $url, string $what = 'iframeUrl'): string {
        // The control-character test runs on the RAW value, before any
        // trimming. `trim()`'s default character list includes "\0", so a URL
        // ending in a NUL byte would otherwise be silently cleaned up and
        // accepted — found by IframeUrlTest, which expected a refusal and got
        // a pass. Silently repairing a value that arrived with a NUL in it is
        // the wrong instinct: what was registered should be what is rendered,
        // and a URL containing a control character is not a URL whichever end
        // it is at.
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw new ValidationException("{$what} contains control characters.");
        }

        $trimmed = trim($url);

        if ($trimmed === '') {
            throw new ValidationException("{$what} cannot be empty.");
        }
        if (strlen($trimmed) > self::MAX_LENGTH) {
            throw new ValidationException("{$what} exceeds " . self::MAX_LENGTH . ' characters.');
        }
        if (!self::isAcceptable($trimmed)) {
            throw new ValidationException(
                "{$what} must start with '/apps/', '/index.php/' or 'https://'. "
                . 'A relative Nextcloud path is recommended for an app on this instance.',
            );
        }

        return $trimmed;
    }

    /**
     * Is this value one of the three accepted shapes?
     *
     * Takes the value as stored. Callers that render a stored URL use this
     * rather than trusting registration — a row can predate a tightening of
     * the rule, and a tightened rule has to apply to it.
     */
    public static function isAcceptable(string $url): bool {
        // Raw value, before trimming — see require() for why.
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        $trimmed = trim($url);

        if ($trimmed === '' || strlen($trimmed) > self::MAX_LENGTH) {
            return false;
        }
        // '//evil.example' starts with '/' and is another origin. Rejected
        // before the relative-path test, which would otherwise accept it on
        // the strength of its first character.
        if (str_starts_with($trimmed, '//')) {
            return false;
        }
        if (str_starts_with($trimmed, '/apps/') || str_starts_with($trimmed, '/index.php/')) {
            return true;
        }

        return str_starts_with($trimmed, 'https://');
    }

    /**
     * The URL with TeamHub's verified team id appended.
     *
     * The team id comes from the caller — which is TeamHub, from a context it
     * built after a membership check — never from the stored URL and never
     * from request input. A stored URL that already carries a `teamId`
     * parameter gets TeamHub's appended after it, and the last value wins in
     * every server-side parser Nextcloud uses, so a registration cannot pin a
     * tab to a team the viewer may not see.
     */
    public static function withTeamId(string $url, string $teamId): string {
        if (!self::isAcceptable($url)) {
            return '';
        }
        $trimmed   = trim($url);
        $separator = str_contains($trimmed, '?') ? '&' : '?';

        return $trimmed . $separator . 'teamId=' . rawurlencode($teamId);
    }
}
