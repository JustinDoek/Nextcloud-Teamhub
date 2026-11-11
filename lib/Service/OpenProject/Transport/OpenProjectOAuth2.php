<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject\Transport;

use OCA\TeamHub\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IMemcache;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * The OAuth2 authorization-code grant against OpenProject (v4.13.5).
 *
 * Everything about one user's OAuth2 connection lives here: starting it,
 * completing it, keeping it alive, and ending it. It is also the `oauth2`
 * {@see IOpenProjectTokenProvider}, because "give me a token" and "refresh
 * the grant" are the same operation seen from two sides.
 *
 * ## The flow, and which hop is which
 *
 * 1. **Browser** → `GET {host}/oauth/authorize?…&state=…` — the user signs
 *    in to OpenProject and approves. Started from TeamHub by
 *    {@see authorizeUrl()}.
 * 2. **Browser** → back to `redirectUri()` on *this* Nextcloud with `code`
 *    and `state`.
 * 3. **Server** → `POST {host}/oauth/token` from Nextcloud itself, which
 *    is why the OpenProject host has to be reachable from the server and
 *    not only from the browser. {@see completeAuthorization()}.
 *
 * Hops 1 and 3 must see OpenProject under the **same origin**, because the
 * grant is bound to the `redirect_uri` presented at both. One configured
 * host is used for both, which is what makes that true by construction.
 *
 * ## `state` is the CSRF defence, and it is why the callback can waive the
 * Nextcloud one
 *
 * The callback is a redirect arriving from another site, so it cannot
 * carry Nextcloud's CSRF token — `#[NoCSRFRequired]` is unavoidable there
 * and CLAUDE.md asks for the reason in writing. OAuth2's own answer is
 * `state`: 32 random characters generated here, stored against the user
 * who started the flow, compared on return in constant time, and **deleted
 * whether it matched or not** so a value is never replayable. A callback
 * with a missing, stale or wrong `state` is refused before the code is
 * exchanged for anything.
 *
 * ## Keeping it alive: one refresh at a time, and only `invalid_grant`
 * ends a connection (v4.13.11)
 *
 * OpenProject issues two-hour access tokens and spends a refresh token
 * when it is used. Both facts cost Justin his connection roughly once a
 * day until this version, for two reasons that compounded:
 *
 *  - **Every failed refresh deleted the grant.** A timeout, a 502 from a
 *    reverse proxy, a 503 while OpenProject restarted and a genuinely dead
 *    refresh token were one `null` here, and all four ended in `forget()`.
 *    One nightly restart was enough to make a user reconnect by hand.
 *    Now only a definitive refusal — `invalid_grant`, which is what
 *    Doorkeeper answers for a revoked, expired or unknown refresh token —
 *    forgets anything. Everything else keeps the grant and fails this one
 *    request, which the next one retries.
 *  - **Refreshes ran in parallel.** A team home fires the overview widget,
 *    upcoming tasks, upcoming events, the feed and My Work as separate
 *    Nextcloud requests, so after a night idle five PHP processes each
 *    found the access token expired, each read the same refresh token and
 *    each spent it. One won; the others were told `invalid_grant` and —
 *    by the rule above — deleted the tokens the winner had just stored.
 *    OpenProject reads a second spend as a replay and revokes its side
 *    too, which is why only a full reconnect repaired it.
 *
 * So a refresh now takes a lock (`IMemcache::add()`, which is atomic on
 * Redis), and the processes that lose the race **wait for the winner's
 * token** instead of spending the refresh token again. On an instance with
 * no `memcache.locking` the factory hands back a `NullCache`, whose `add()`
 * answers true and whose `get()` answers nothing: every process then
 * believes it holds the lock, nobody waits, and the behaviour is exactly
 * what it was before this version minus the `forget()` — which is the half
 * that cost the connection. The winner hands it over through
 * the same cache, encrypted with `ICrypto` exactly as the stored copy is:
 * Nextcloud's cache is shared with every other app on the instance, and a
 * bearer token in plaintext there would be a weaker store than the
 * database one this class is careful about.
 *
 * The hand-over exists because the waiter cannot simply re-read the
 * database: `IConfig` caches a user's values for the life of a request, and
 * the waiter read them before the winner wrote.
 *
 * ## What is never logged
 *
 * No code, no token, no client secret, no `state`, at any level, including
 * inside an exception message. The host is not logged either: a
 * misconfigured URL can carry credentials in its userinfo part. What is
 * logged is which step failed, the HTTP status, and — since v4.13.11 — the
 * OAuth2 error *code* (`invalid_grant`), which is a fixed vocabulary from
 * the specification and names the one thing an administrator reading the
 * log needs. Never `error_description`, which is free text from the other
 * side.
 */
class OpenProjectOAuth2 implements IOpenProjectTokenProvider {

    /** user config: the pending `state`, deleted the moment it is used. */
    public const USER_OAUTH_STATE = 'openproject_direct_oauth_state';
    /** user config: when that state was issued, so a stale one can expire. */
    public const USER_OAUTH_STATE_AT = 'openproject_direct_oauth_state_at';

    /** An unfinished authorization is abandoned after this long. */
    public const STATE_TTL = 600;

    /** The only scope TeamHub asks for. OpenProject's API v3, nothing else. */
    public const SCOPE = 'api_v3';

    public const ROUTE_CALLBACK = 'teamhub.openProjectOAuth.callback';

    private const TIMEOUT = 20;
    private const CONNECT_TIMEOUT = 10;

    /** Distributed cache holding the refresh lock and its hand-over. */
    private const CACHE_PREFIX = 'teamhub-openproject-oauth2';

    /**
     * How long one process may hold the refresh lock. Generous on purpose:
     * it only has to outlive one `POST /oauth/token`, and a process killed
     * mid-refresh must not block the next one for longer than it takes a
     * person to reload the page.
     */
    private const LOCK_TTL = 30;

    /** How long the winner's access token stays available to the waiters. */
    private const HANDOVER_TTL = 120;

    /** How long a waiting process gives the holder, in microseconds. */
    private const WAIT_TOTAL_US = 2_000_000;
    private const WAIT_POLL_US  =   100_000;

    /**
     * The OAuth2 error codes that mean *this grant is over*. Everything
     * else — a 5xx, a 429, `invalid_client` from a mistyped secret, no
     * answer at all — is a reason to try again later and never a reason to
     * make every user of the instance reconnect.
     */
    private const FATAL_GRANT_ERRORS = ['invalid_grant'];

    public function __construct(
        private OpenProjectCredentialStore $credentials,
        private IClientService             $clientService,
        private IConfig                    $config,
        private IURLGenerator              $urlGenerator,
        private ISecureRandom              $random,
        private ICacheFactory              $cacheFactory,
        private ICrypto                    $crypto,
        private LoggerInterface            $logger,
    ) {
    }

    /**
     * The redirect URI, which an administrator registers in OpenProject
     * (Administration → Authentication → OAuth applications).
     *
     * It must match **character for character** on both sides, so it is
     * generated from one place and shown to the administrator rather than
     * described in prose for them to retype.
     */
    public function redirectUri(): string {
        return $this->urlGenerator->linkToRouteAbsolute(self::ROUTE_CALLBACK);
    }

    /**
     * Begin an authorization for `$userId`: issue a `state`, remember it,
     * and return the OpenProject URL to send the browser to. Null when the
     * instance is not configured for OAuth2.
     */
    public function authorizeUrl(string $userId): ?string {
        $host     = $this->credentials->getHost();
        $clientId = $this->credentials->getClientId();
        if ($host === '' || $clientId === '' || $this->credentials->getAuthMethod() !== 'oauth2') {
            return null;
        }

        $state = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
        $this->config->setUserValue($userId, Application::APP_ID, self::USER_OAUTH_STATE, $state);
        $this->config->setUserValue($userId, Application::APP_ID, self::USER_OAUTH_STATE_AT, (string)time());

        return $host . '/oauth/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $clientId,
            'redirect_uri'  => $this->redirectUri(),
            'scope'         => self::SCOPE,
            'state'         => $state,
        ]);
    }

    /**
     * Finish an authorization. Returns null on success, or a short reason
     * code for the settings page to turn into a sentence:
     * `state_mismatch`, `not_configured`, `exchange_failed`.
     *
     * The reason is deliberately coarse. A user can act on "that took too
     * long, try again" and on nothing finer; everything else is in the log
     * for an administrator.
     */
    public function completeAuthorization(string $userId, string $code, string $state): ?string {
        $expected = $this->config->getUserValue($userId, Application::APP_ID, self::USER_OAUTH_STATE, '');
        $issuedAt = (int)$this->config->getUserValue($userId, Application::APP_ID, self::USER_OAUTH_STATE_AT, '0');

        // Consumed whatever happens: a state that survives a failed attempt
        // is a state that can be replayed.
        $this->forgetState($userId);

        if ($expected === '' || $state === '' || !hash_equals($expected, $state)) {
            $this->logger->warning('[TeamHub][OpenProjectOAuth2] Callback state did not match', [
                'app' => Application::APP_ID,
            ]);
            return 'state_mismatch';
        }
        if ($issuedAt <= 0 || time() - $issuedAt > self::STATE_TTL) {
            $this->logger->info('[TeamHub][OpenProjectOAuth2] Callback arrived after the state expired', [
                'app' => Application::APP_ID,
            ]);
            return 'state_mismatch';
        }
        if ($code === '') {
            return 'exchange_failed';
        }
        if (!$this->credentials->isConfigured()) {
            return 'not_configured';
        }

        $token = $this->requestToken([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $this->redirectUri(),
        ], 'authorization_code');

        if ($token['ok'] === false) {
            return 'exchange_failed';
        }

        $this->credentials->storeTokens(
            $userId,
            $token['access_token'],
            $token['expires_in'],
            $token['refresh_token'],
            // A name is a nicety, not a requirement: a connection that
            // works but could not be named is still a connection.
            $this->fetchUserName($token['access_token']) ?? 'OpenProject',
        );
        return null;
    }

    /**
     * A token that can be sent right now: the stored one while it is
     * fresh, otherwise a refresh, otherwise null.
     *
     * One refresh per user at a time, and a refusal only ends the
     * connection when OpenProject says the grant itself is gone — the
     * class docblock has the whole reasoning and the bug it closes.
     */
    public function accessTokenFor(string $userId): ?string {
        $fresh = $this->credentials->getFreshAccessToken($userId);
        if ($fresh !== null) {
            return $fresh;
        }
        if ($this->credentials->getRefreshToken($userId) === null || !$this->credentials->isConfigured()) {
            return null;
        }

        $cache = $this->cache();

        // Another process may have refreshed microseconds ago: this one read
        // its user config before that write landed, and `IConfig` does not
        // read a user's values twice in one request.
        $handed = $this->handedOverToken($cache, $userId);
        if ($handed !== null) {
            return $handed;
        }

        $held      = $cache !== null && $cache->add(self::lockKey($userId), '1', self::LOCK_TTL) === true;
        $contended = $cache !== null && !$held;
        if ($contended) {
            // Somebody else is spending this refresh token right now.
            // Waiting for their answer is the point: spending it twice is
            // what OpenProject reads as a replay.
            $handed = $this->awaitHandover($cache, $userId);
            if ($handed !== null) {
                return $handed;
            }
            // The holder published nothing — it died, or its own refresh
            // failed. Try once rather than refuse: the lock carries a TTL
            // precisely so a dead holder cannot block a connection.
        }

        try {
            $refresh = $this->credentials->getRefreshToken($userId);
            if ($refresh === null) {
                // Forgotten while we waited: a refusal, already handled.
                return null;
            }

            $token = $this->requestToken([
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh,
            ], 'refresh_token');

            if ($token['ok'] === false) {
                if ($token['fatal'] && $contended) {
                    // We refreshed after waiting on somebody else, so this
                    // `invalid_grant` has a second explanation: they
                    // succeeded and could not publish, and the token we
                    // spent was already theirs to spend. Dropping the
                    // connection on that reading is the bug this version
                    // closes, so it is left to a request that raced with
                    // nobody — which the next page load will be.
                    $this->logger->warning('[TeamHub][OpenProjectOAuth2] Refresh refused after waiting on another; grant kept', [
                        'app' => Application::APP_ID,
                    ]);
                } elseif ($token['fatal']) {
                    $this->logger->info('[TeamHub][OpenProjectOAuth2] Refresh refused; connection dropped', [
                        'app' => Application::APP_ID,
                    ]);
                    $this->credentials->forget($userId);
                    $this->forgetHandover($cache, $userId);
                } else {
                    $this->logger->warning('[TeamHub][OpenProjectOAuth2] Refresh could not be completed; grant kept', [
                        'app' => Application::APP_ID,
                    ]);
                }
                return null;
            }

            $this->credentials->storeTokens(
                $userId,
                $token['access_token'],
                $token['expires_in'],
                $token['refresh_token'],
            );
            $this->handOver($cache, $userId, $token['access_token']);
            return $token['access_token'];
        } finally {
            if ($held) {
                $this->releaseLock($cache, $userId);
            }
        }
    }

    /**
     * With OAuth2, connected means a grant is held — the authorization
     * was completed and not since dropped (v4.13.8).
     */
    public function isUserConnected(string $userId): bool {
        return $this->credentials->hasCredential($userId);
    }

    /** End this user's connection. Nothing is revoked in OpenProject. */
    public function disconnect(string $userId): void {
        $this->forgetState($userId);
        $this->credentials->forget($userId);
        // A hand-over outliving a disconnect would hand a live token to the
        // next request for up to two minutes.
        $this->forgetHandover($this->cache(), $userId);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * One call to `/oauth/token`. Returns the three fields that matter, or
     * a failure that says whether the grant itself is gone — never the raw
     * body, which carries the tokens.
     *
     * `fatal` is the whole point of the shape (v4.13.11): a caller has to
     * be able to tell "reconnect" from "try again", because acting on the
     * first when it was the second is what cost a connection a day.
     *
     * `$kind` names the grant for the log and is the only thing about the
     * request that is ever written down.
     *
     * @param array<string, string> $grant
     * @return array{ok: true, access_token: string, expires_in: ?int, refresh_token: ?string}
     *        |array{ok: false, fatal: bool}
     */
    private function requestToken(array $grant, string $kind): array {
        $host = $this->credentials->getHost();
        if ($host === '') {
            return ['ok' => false, 'fatal' => false];
        }

        try {
            $response = $this->clientService->newClient()->post($host . '/oauth/token', [
                // OpenProject (Doorkeeper) accepts the client credentials
                // in the body; they are form-encoded, never in the URL,
                // where they would reach access logs and the Referer header.
                'body' => array_merge($grant, [
                    'client_id'     => $this->credentials->getClientId(),
                    'client_secret' => $this->credentials->getClientSecret(),
                ]),
                'timeout'         => self::TIMEOUT,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'http_errors'     => false,
            ]);
        } catch (\Throwable $e) {
            // DNS, TLS, a timeout, a refused connection. The grant is very
            // probably fine; the network was not.
            $this->logger->warning('[TeamHub][OpenProjectOAuth2] Token endpoint unreachable', [
                'grant' => $kind,
                'class' => get_class($e),
                'app'   => Application::APP_ID,
            ]);
            return ['ok' => false, 'fatal' => false];
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            // RFC 6749 §5.2: the refusal names itself in `error`, and
            // Doorkeeper answers `invalid_grant` for a refresh token that is
            // revoked, expired or unknown. A 5xx, a 429 or a body we cannot
            // read says nothing about the grant, so it does not end one.
            $error = $this->oauthErrorCode((string)$response->getBody());
            $fatal = in_array($error, self::FATAL_GRANT_ERRORS, true);
            $this->logger->warning('[TeamHub][OpenProjectOAuth2] Token endpoint refused the grant', [
                'grant'      => $kind,
                'status'     => $status,
                'oauthError' => $error,
                'fatal'      => $fatal,
                'app'        => Application::APP_ID,
            ]);
            return ['ok' => false, 'fatal' => $fatal];
        }

        $body = json_decode((string)$response->getBody(), true);
        if (!is_array($body) || !isset($body['access_token']) || !is_string($body['access_token']) || $body['access_token'] === '') {
            $this->logger->warning('[TeamHub][OpenProjectOAuth2] Token response had no access token', [
                'grant' => $kind,
                'app'   => Application::APP_ID,
            ]);
            return ['ok' => false, 'fatal' => false];
        }

        return [
            'ok'            => true,
            'access_token'  => $body['access_token'],
            'expires_in'    => isset($body['expires_in']) && is_numeric($body['expires_in'])
                ? (int)$body['expires_in']
                : null,
            'refresh_token' => isset($body['refresh_token']) && is_string($body['refresh_token']) && $body['refresh_token'] !== ''
                ? $body['refresh_token']
                : null,
        ];
    }

    /**
     * The OpenProject display name for a freshly obtained token, so the
     * settings page can say *who* is connected. Best effort: a failure
     * here must not fail a connection that otherwise worked.
     */
    private function fetchUserName(string $accessToken): ?string {
        $host = $this->credentials->getHost();
        if ($host === '') {
            return null;
        }
        try {
            $response = $this->clientService->newClient()->get($host . '/api/v3/users/me', [
                'headers'         => ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json'],
                'timeout'         => self::TIMEOUT,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'http_errors'     => false,
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $body = json_decode((string)$response->getBody(), true);
            $name = is_array($body) && isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
            return $name === '' ? null : mb_substr($name, 0, 190);
        } catch (\Throwable) {
            return null;
        }
    }

    private function forgetState(string $userId): void {
        $this->config->deleteUserValue($userId, Application::APP_ID, self::USER_OAUTH_STATE);
        $this->config->deleteUserValue($userId, Application::APP_ID, self::USER_OAUTH_STATE_AT);
    }

    /**
     * The OAuth2 error code out of a refusal body, or '' when there is
     * none to read. The code only; `error_description` is free text from
     * the other side and is neither read nor logged.
     */
    private function oauthErrorCode(string $body): string {
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['error']) || !is_string($decoded['error'])) {
            return '';
        }
        // Bounded and stripped of anything that is not an error code, so a
        // hostile body cannot write whatever it likes into the log.
        return substr(preg_replace('/[^a-z_]/', '', strtolower(trim($decoded['error']))) ?? '', 0, 40);
    }

    // ─────────────────────────────────────────────────────────────────────
    // One refresh at a time
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The locking cache, or null when this instance has none that works.
     *
     * Null is a real answer: without a distributed cache there is nothing
     * to serialise with, and a refresh then behaves as it did before
     * v4.13.11 — minus the part that deleted the connection, which is the
     * half that actually hurt.
     */
    private function cache(): ?IMemcache {
        try {
            return $this->cacheFactory->createLocking(self::CACHE_PREFIX);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function lockKey(string $userId): string {
        return 'refresh_lock_' . md5($userId);
    }

    private static function handoverKey(string $userId): string {
        return 'refreshed_' . md5($userId);
    }

    private function releaseLock(?IMemcache $cache, string $userId): void {
        try {
            $cache?->remove(self::lockKey($userId));
        } catch (\Throwable) {
            // The TTL releases it either way.
        }
    }

    /**
     * Offer the token just obtained to the processes that waited for it.
     * Encrypted, because Nextcloud's cache is shared with every other app
     * on the instance.
     */
    private function handOver(?IMemcache $cache, string $userId, string $accessToken): void {
        if ($cache === null) {
            return;
        }
        try {
            $cache->set(self::handoverKey($userId), $this->crypto->encrypt($accessToken), self::HANDOVER_TTL);
        } catch (\Throwable) {
            // Best effort. A waiter that gets nothing refreshes itself,
            // which is the behaviour this only improves on.
        }
    }

    /** The token another process refreshed moments ago, if there is one. */
    private function handedOverToken(?IMemcache $cache, string $userId): ?string {
        if ($cache === null) {
            return null;
        }
        try {
            $stored = $cache->get(self::handoverKey($userId));
            if (!is_string($stored) || $stored === '') {
                return null;
            }
            $token = $this->crypto->decrypt($stored);
            return $token === '' ? null : $token;
        } catch (\Throwable) {
            // An instance secret rotated under us, or a value written by an
            // older version. Treat it as absent and refresh properly.
            return null;
        }
    }

    private function forgetHandover(?IMemcache $cache, string $userId): void {
        try {
            $cache?->remove(self::handoverKey($userId));
        } catch (\Throwable) {
            // Expires on its own within two minutes.
        }
    }

    /**
     * Wait for the process holding the lock to publish its token, up to
     * {@see WAIT_TOTAL_US}. Null when it never does — the caller then
     * refreshes itself rather than failing a page load.
     *
     * The wait is bounded by two things, whichever comes first: the token
     * appearing, or the lock disappearing (the holder finished and had
     * nothing to publish, so waiting longer cannot help).
     */
    private function awaitHandover(IMemcache $cache, string $userId): ?string {
        for ($waited = 0; $waited < self::WAIT_TOTAL_US; $waited += self::WAIT_POLL_US) {
            usleep(self::WAIT_POLL_US);

            $handed = $this->handedOverToken($cache, $userId);
            if ($handed !== null) {
                return $handed;
            }
            try {
                if ($cache->get(self::lockKey($userId)) === null) {
                    return null;
                }
            } catch (\Throwable) {
                return null;
            }
        }
        $this->logger->info('[TeamHub][OpenProjectOAuth2] Waited for another refresh without result', [
            'app' => Application::APP_ID,
        ]);
        return null;
    }
}
