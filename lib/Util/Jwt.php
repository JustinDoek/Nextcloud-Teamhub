<?php
declare(strict_types=1);

namespace OCA\TeamHub\Util;

/**
 * Minimal RS256 JWS/JWT verifier (v3.100.0, Track F — Licensing).
 *
 * Deliberately dependency-free: firebase/php-jwt would work but adding a
 * composer dep + shipping vendor/ to the NC app store is more moving parts
 * than a ~40-line hand-rolled verifier that uses OpenSSL primitives that
 * are always present in the PHP configurations Nextcloud supports.
 *
 * Supports:
 *  - alg = RS256 only. Rejects "none", HS256, RS384, everything else.
 *    RS256 is what our licensing back-end signs with; adding algorithm
 *    switching would open the classic "attacker submits alg=none" bug.
 *  - typ = JWT (case-insensitive).
 *  - Claim checks: exp (must be in future), nbf (must be in past),
 *    iat (must be in past, with 60s clock-skew leeway).
 *
 * Does NOT support:
 *  - JWS with detached payload
 *  - Encrypted JWTs (JWE)
 *  - Nested tokens
 *  - Key rotation / JWKS lookup — we ship exactly one public key in
 *    LicenseService::PUBLIC_KEY_PEM. Rotate = ship an app update.
 *
 * On failure, always throws \RuntimeException with a message safe to
 * surface to admins (never leaks key material or internal state).
 */
final class Jwt {

    /** Clock-skew tolerance in seconds for iat/nbf checks. */
    private const LEEWAY = 60;

    /**
     * Verify a JWT and return the decoded payload claims on success.
     *
     * @param string $jwt      The compact-form JWT (three base64url segments).
     * @param string $publicKeyPem  PEM-encoded RSA public key.
     * @param int|null $now    Override "now" for testing. Uses time() when null.
     * @return array<string,mixed>  The decoded payload claims.
     * @throws \RuntimeException on any verification failure.
     */
    public static function verifyRs256(string $jwt, string $publicKeyPem, ?int $now = null): array {
        $now ??= time();

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('License key is malformed.');
        }
        [$headerB64, $payloadB64, $sigB64] = $parts;

        $header  = self::decodeJsonSegment($headerB64,  'header');
        $payload = self::decodeJsonSegment($payloadB64, 'payload');
        $sig     = self::base64UrlDecode($sigB64);

        if (($header['alg'] ?? '') !== 'RS256') {
            throw new \RuntimeException('License key uses an unsupported signing algorithm.');
        }
        if (strtolower((string)($header['typ'] ?? 'JWT')) !== 'jwt') {
            throw new \RuntimeException('License key has an unexpected header type.');
        }

        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            // Programming error — bad PEM shipped in LicenseService.
            // Surface the OpenSSL error so the admin knows exactly what's
            // wrong (missing BEGIN/END, truncated lines, wrong newlines,
            // etc.) rather than debugging blind.
            $osslErr = '';
            while (($e = openssl_error_string()) !== false) {
                $osslErr .= ($osslErr === '' ? '' : '; ') . $e;
            }
            throw new \RuntimeException(
                'License verification is not configured on this server. '
                . 'The PUBLIC_KEY_PEM constant in LicenseService could not be parsed by OpenSSL. '
                . 'Common causes: base64 lines truncated (contain "..."), missing '
                . '"-----BEGIN PUBLIC KEY-----" or "-----END PUBLIC KEY-----" markers, '
                . 'or missing "\\n" between concatenated lines. '
                . ($osslErr !== '' ? 'OpenSSL: ' . $osslErr : '')
            );
        }
        try {
            $ok = openssl_verify(
                $headerB64 . '.' . $payloadB64,
                $sig,
                $key,
                OPENSSL_ALGO_SHA256
            );
        } finally {
            // PHP 8+ closes automatically when the OpenSSLAsymmetricKey is GC'd,
            // but making it explicit keeps the intent readable.
            unset($key);
        }
        if ($ok !== 1) {
            throw new \RuntimeException('License key signature is invalid.');
        }

        // Claim checks. Missing exp is fatal — we never issue non-expiring
        // licenses. Missing nbf/iat are OK (older license format tolerance).
        if (!isset($payload['exp']) || !is_int($payload['exp'])) {
            throw new \RuntimeException('License key is missing an expiry.');
        }
        if ($payload['exp'] < $now) {
            throw new \RuntimeException('License key has expired.');
        }
        if (isset($payload['nbf']) && is_int($payload['nbf']) && $payload['nbf'] > $now + self::LEEWAY) {
            throw new \RuntimeException('License key is not yet valid.');
        }
        if (isset($payload['iat']) && is_int($payload['iat']) && $payload['iat'] > $now + self::LEEWAY) {
            throw new \RuntimeException('License key was issued in the future.');
        }

        return $payload;
    }

    /**
     * Inspect a JWT without verifying — used for admin UX like "show me
     * what license I have" before we confirm the sig is valid. NEVER
     * trust the return value for enforcement; that's what verifyRs256
     * is for.
     *
     * @return array{header:array,payload:array}|null Null on parse failure.
     */
    public static function peek(string $jwt): ?array {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        try {
            return [
                'header'  => self::decodeJsonSegment($parts[0], 'header'),
                'payload' => self::decodeJsonSegment($parts[1], 'payload'),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function decodeJsonSegment(string $b64, string $label): array {
        $raw = self::base64UrlDecode($b64);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("License key {$label} is not valid JSON.");
        }
        return $decoded;
    }

    private static function base64UrlDecode(string $s): string {
        // JWS uses base64url without padding. Convert to standard base64,
        // then decode strictly.
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad !== 0) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($s, true);
        if ($out === false) {
            throw new \RuntimeException('License key contains invalid base64 data.');
        }
        return $out;
    }
}
