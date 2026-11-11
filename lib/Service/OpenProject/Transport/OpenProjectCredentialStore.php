<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject\Transport;

use OCA\TeamHub\AppInfo\Application;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * TeamHub's own OpenProject configuration and per-user tokens (v4.13.4).
 *
 * ## This reverses DESIGN §2.120, deliberately
 *
 * That entry says, of the whole integration: *"no token is ever read, so
 * there is nothing to encrypt, rotate, leak or log… there is no second
 * token store to secure because there is no second token store."* This
 * class **is** that second token store. The reversal is not a drift; it is
 * the price of {@see DirectTransport}, and it is paid only on instances
 * that configure one. Where the official app is doing the work,
 * {@see IntegrationAppTransport} still reads no token and this class holds
 * nothing.
 *
 * What the reversal buys, and why it was judged worth it, is in the
 * interface docblock of {@see IOpenProjectTransport}: independence from
 * another app's release train on a new Nextcloud major, and PATCH.
 *
 * ## The rules this store keeps
 *
 * - **Every secret is encrypted at rest.** The OAuth2 client secret (app
 *   config) and each user's access and refresh token (user config) go
 *   through `ICrypto`, which keys off the instance secret. A `getX()` that
 *   cannot decrypt answers as if nothing were stored rather than throwing:
 *   a rotated instance secret must read as "reconnect", not as a 500 on
 *   every widget.
 * - **Nothing secret is ever logged**, including at debug level, including
 *   in an exception message. The only thing logged here is *that* a decrypt
 *   failed, never what failed to decrypt.
 * - **The host is an administrator's and only an administrator's.** No
 *   TeamHub endpoint, setting or CSV accepts an OpenProject URL from a
 *   user. That is DESIGN §2.120's SSRF boundary and it is unchanged by any
 *   of this.
 *
 * ## What is not here yet
 *
 * **Acquisition.** This class stores, reads and forgets tokens; it does not
 * obtain them. The OAuth2 authorization-code flow and its refresh land in
 * the next step of the arc, and the OIDC event exchange in the one after.
 * Until then `isConfigured()` answers false on any real instance — nothing
 * can write an OAuth2 client secret — so the selector falls back to the
 * official app and behaviour is exactly as it was. The storage half is
 * built first and on its own precisely because it is the half that has to
 * be right.
 */
class OpenProjectCredentialStore {

    /** appconfig: TeamHub's own OpenProject instance URL. */
    public const CFG_URL = 'openproject_direct_url';
    /** appconfig: 'oauth2' (default) or 'oidc'. */
    public const CFG_AUTH_METHOD = 'openproject_direct_auth_method';
    /** appconfig: the OAuth2 client id registered in OpenProject. */
    public const CFG_CLIENT_ID = 'openproject_direct_client_id';
    /** appconfig: the OAuth2 client secret, encrypted. */
    public const CFG_CLIENT_SECRET = 'openproject_direct_client_secret';
    /** appconfig (v4.13.8, oidc): 'nextcloud_hub' or 'external'. */
    public const CFG_SSO_PROVIDER = 'openproject_direct_sso_provider';
    /** appconfig (v4.13.8, oidc): '1' when the IdP should exchange the token. */
    public const CFG_TOKEN_EXCHANGE = 'openproject_direct_token_exchange';
    /** appconfig (v4.13.8, oidc): the OIDC client id OpenProject is registered as. Not a secret. */
    public const CFG_AUDIENCE = 'openproject_direct_audience';

    /** user config: the access token, encrypted. */
    public const USER_TOKEN = 'openproject_direct_token';
    /** user config: unix seconds at which the access token expires. */
    public const USER_TOKEN_EXPIRES_AT = 'openproject_direct_token_expires_at';
    /** user config: the refresh token, encrypted. */
    public const USER_REFRESH_TOKEN = 'openproject_direct_refresh_token';
    /** user config: the OpenProject display name, for "are you connected". */
    public const USER_NAME = 'openproject_direct_user_name';

    /**
     * Treat a token as expired this many seconds early, so a request does
     * not start with four seconds of validity left and finish without any.
     * The same margin the official app uses.
     */
    public const EXPIRY_SAFETY_MARGIN = 60;

    public function __construct(
        private IConfig         $config,
        private ICrypto         $crypto,
        private LoggerInterface $logger,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────
    // Instance configuration
    // ─────────────────────────────────────────────────────────────────────

    /**
     * TeamHub's own OpenProject URL, without a trailing slash, or '' when
     * none is set or the value is not an http(s) URL.
     */
    public function getHost(): string {
        $url = trim($this->config->getAppValue(Application::APP_ID, self::CFG_URL, ''));
        if ($url === '' || !TransportUrl::isValidHost($url)) {
            return '';
        }
        return rtrim($url, '/');
    }

    public function getAuthMethod(): string {
        $method = $this->config->getAppValue(Application::APP_ID, self::CFG_AUTH_METHOD, '');
        return $method === 'oidc' ? 'oidc' : 'oauth2';
    }

    public function getClientId(): string {
        return trim($this->config->getAppValue(Application::APP_ID, self::CFG_CLIENT_ID, ''));
    }

    /**
     * The OAuth2 client secret, or '' when unset or undecryptable.
     *
     * Trimmed on read as well as on write (v4.13.7), so a value stored
     * before the setter trimmed repairs itself at the next request
     * instead of needing an administrator to paste it again — which
     * OpenProject would not let them do, because it shows a secret once
     * and keeps only a hash.
     */
    public function getClientSecret(): string {
        return trim($this->decryptAppValue(self::CFG_CLIENT_SECRET));
    }

    /**
     * Is the direct transport set up on this instance at all.
     *
     * A host plus whatever that auth method needs: an OAuth2 client for
     * `oauth2`, nothing further for `oidc` (there the credential comes from
     * `user_oidc` at request time, not from stored configuration).
     */
    public function isConfigured(): bool {
        if ($this->getHost() === '') {
            return false;
        }
        if ($this->getAuthMethod() === 'oidc') {
            return true;
        }
        return $this->getClientId() !== '' && $this->getClientSecret() !== '';
    }

    /**
     * Set the OpenProject URL, or '' to clear it and hand everything back
     * to the official app. Validated by the caller; stored as given minus
     * a trailing slash, and read back through `getHost()`, which validates
     * again — a value that got in another way still cannot become a host.
     */
    public function setHost(string $url): void {
        $url = rtrim(trim($url), '/');
        $this->config->setAppValue(Application::APP_ID, self::CFG_URL, $url);
    }

    /** Anything that is not `oidc` is stored as `oauth2`. */
    public function setAuthMethod(string $method): void {
        $this->config->setAppValue(
            Application::APP_ID,
            self::CFG_AUTH_METHOD,
            $method === 'oidc' ? 'oidc' : 'oauth2',
        );
    }

    public function setClientId(string $clientId): void {
        $this->config->setAppValue(Application::APP_ID, self::CFG_CLIENT_ID, trim($clientId));
    }

    /** v4.13.8 — the OIDC settings. None of these is a secret. */
    public function setSsoProvider(string $provider): void {
        $this->config->setAppValue(
            Application::APP_ID,
            self::CFG_SSO_PROVIDER,
            $provider === OpenProjectOidc::PROVIDER_NEXTCLOUD_HUB
                ? OpenProjectOidc::PROVIDER_NEXTCLOUD_HUB
                : OpenProjectOidc::PROVIDER_EXTERNAL,
        );
    }

    public function setTokenExchange(bool $enabled): void {
        $this->config->setAppValue(Application::APP_ID, self::CFG_TOKEN_EXCHANGE, $enabled ? '1' : '0');
    }

    public function setAudience(string $audience): void {
        $this->config->setAppValue(Application::APP_ID, self::CFG_AUDIENCE, trim($audience));
    }

    /**
     * Store the OAuth2 client secret, encrypted. An empty string clears
     * it rather than storing an encrypted empty value, so that
     * `isConfigured()` reads false afterwards.
     *
     * **Trimmed** (v4.13.7). This setter was the only one here that did
     * not trim, and it cost an afternoon: a paste that picked up one
     * leading space stored 44 characters where OpenProject had generated
     * 43, and Doorkeeper answered the token exchange with a bare `401`.
     * Nothing in the failure named the cause — the authorize step
     * succeeded, because that only uses the client *id* — so it read as
     * "the secret is wrong" when the secret was right and its envelope
     * was not.
     *
     * A secret is formally an opaque string, so trimming could in theory
     * alter a legitimate one. It is the right trade anyway: OAuth2 secrets
     * travel in form-encoded bodies and headers where surrounding
     * whitespace does not survive reliably, Doorkeeper generates URL-safe
     * base64, and the alternative failure mode is a silent 401 that looks
     * like a configuration mystery.
     */
    public function setClientSecret(string $secret): void {
        $secret = trim($secret);
        if ($secret === '') {
            $this->config->deleteAppValue(Application::APP_ID, self::CFG_CLIENT_SECRET);
            return;
        }
        $this->config->setAppValue(Application::APP_ID, self::CFG_CLIENT_SECRET, $this->crypto->encrypt($secret));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Per-user tokens
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Has this user a stored connection — regardless of whether the access
     * token is still fresh, because a refresh token is a connection too.
     */
    public function hasCredential(string $userId): bool {
        if ($this->config->getUserValue($userId, Application::APP_ID, self::USER_NAME, '') !== '') {
            return true;
        }
        return $this->getRefreshToken($userId) !== null
            || $this->getStoredAccessToken($userId) !== null;
    }

    /**
     * The stored access token if it is present and not within the safety
     * margin of expiry; null otherwise.
     *
     * **This does not refresh.** A caller that gets null here and holds a
     * refresh token has to go and refresh — which is what the OAuth2 step
     * of this arc adds. Until then, null means the direct transport reports
     * the user as needing to reconnect.
     */
    public function getFreshAccessToken(string $userId): ?string {
        $token = $this->getStoredAccessToken($userId);
        if ($token === null) {
            return null;
        }
        return $this->isAccessTokenExpired($userId) ? null : $token;
    }

    /** The stored access token, fresh or not. */
    public function getStoredAccessToken(string $userId): ?string {
        $token = $this->decryptUserValue($userId, self::USER_TOKEN);
        return $token === '' ? null : $token;
    }

    public function getRefreshToken(string $userId): ?string {
        $token = $this->decryptUserValue($userId, self::USER_REFRESH_TOKEN);
        return $token === '' ? null : $token;
    }

    public function isAccessTokenExpired(string $userId): bool {
        $expiresAt = (int)$this->config->getUserValue(
            $userId, Application::APP_ID, self::USER_TOKEN_EXPIRES_AT, '0',
        );
        if ($expiresAt <= 0) {
            // No expiry recorded. Treat as expired rather than as eternal:
            // a token we cannot date is one we cannot trust to still work,
            // and the cost of being wrong is one reconnect, not a silent
            // wrong answer.
            return true;
        }
        return time() > ($expiresAt - self::EXPIRY_SAFETY_MARGIN);
    }

    /**
     * Store a freshly obtained set of credentials for one user.
     *
     * `$expiresIn` is seconds from now, as OAuth2 gives it. A null or
     * absent refresh token leaves any stored one alone — a refresh response
     * is allowed to omit it, and dropping it would silently turn a
     * long-lived connection into a one-hour one.
     */
    public function storeTokens(
        string $userId,
        string $accessToken,
        ?int $expiresIn = null,
        ?string $refreshToken = null,
        ?string $openProjectUserName = null,
    ): void {
        $this->setEncryptedUserValue($userId, self::USER_TOKEN, $accessToken);
        $this->config->setUserValue(
            $userId,
            Application::APP_ID,
            self::USER_TOKEN_EXPIRES_AT,
            (string)($expiresIn !== null && $expiresIn > 0 ? time() + $expiresIn : 0),
        );
        if ($refreshToken !== null && $refreshToken !== '') {
            $this->setEncryptedUserValue($userId, self::USER_REFRESH_TOKEN, $refreshToken);
        }
        if ($openProjectUserName !== null) {
            $this->config->setUserValue($userId, Application::APP_ID, self::USER_NAME, $openProjectUserName);
        }
    }

    /**
     * Drop this user's connection entirely — disconnect, or a 401 that a
     * refresh could not repair.
     */
    public function forget(string $userId): void {
        foreach ([self::USER_TOKEN, self::USER_TOKEN_EXPIRES_AT, self::USER_REFRESH_TOKEN, self::USER_NAME] as $key) {
            $this->config->deleteUserValue($userId, Application::APP_ID, $key);
        }
    }

    /** The OpenProject display name recorded at connect time, or ''. */
    public function getUserName(string $userId): string {
        return $this->config->getUserValue($userId, Application::APP_ID, self::USER_NAME, '');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Encryption
    // ─────────────────────────────────────────────────────────────────────

    private function setEncryptedUserValue(string $userId, string $key, string $value): void {
        $this->config->setUserValue($userId, Application::APP_ID, $key, $this->crypto->encrypt($value));
    }

    private function decryptUserValue(string $userId, string $key): string {
        return $this->decrypt($this->config->getUserValue($userId, Application::APP_ID, $key, ''), $key);
    }

    private function decryptAppValue(string $key): string {
        return $this->decrypt($this->config->getAppValue(Application::APP_ID, $key, ''), $key);
    }

    /**
     * Decrypt, or '' when the stored value cannot be read.
     *
     * The failure case is real and survivable: the instance secret was
     * rotated, or a value was written before encryption existed. Both mean
     * "there is no usable credential here", which is a reconnect, not an
     * error page. Only the key's *name* is logged — never the value, never
     * the exception message, which `ICrypto` may build from the input.
     */
    private function decrypt(string $stored, string $key): string {
        if ($stored === '') {
            return '';
        }
        try {
            return $this->crypto->decrypt($stored);
        } catch (\Throwable) {
            $this->logger->warning('[TeamHub][OpenProjectCredentialStore] Stored value could not be decrypted', [
                'key' => $key,
                'app' => Application::APP_ID,
            ]);
            return '';
        }
    }
}
