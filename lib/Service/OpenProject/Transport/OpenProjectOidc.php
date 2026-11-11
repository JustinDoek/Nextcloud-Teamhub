<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject\Transport;

use OCA\TeamHub\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * OpenProject tokens for an OIDC / single-sign-on instance (v4.13.8).
 *
 * The `oidc` half of {@see IOpenProjectTokenProvider}. Where
 * {@see OpenProjectOAuth2} owns a grant of its own and refreshes it, this
 * asks **Nextcloud** for a token on the user's behalf and stores nothing
 * long-lived: there is no TeamHub grant, no refresh token, and nothing for
 * a user to connect or disconnect. They are connected because of how they
 * signed in.
 *
 * ## How a token is obtained
 *
 * Entirely through `user_oidc`'s **public event API** — three events it
 * publishes for exactly this purpose. TeamHub dispatches one and reads the
 * token back off it:
 *
 * | Instance shape | Event |
 * |---|---|
 * | Nextcloud itself is the identity provider (the `oidc` app) | `InternalTokenRequestedEvent($audience, $scopes, $audience)` |
 * | An external IdP, token exchange **on** | `ExchangedTokenRequestedEvent($audience, $scopes)` |
 * | An external IdP, token exchange **off** | `ExternalTokenRequestedEvent()` — the login token, as-is |
 *
 * That last one cannot request scopes, which is `user_oidc`'s limitation
 * and not something TeamHub can work around: the token is whatever the
 * login produced. If OpenProject refuses it, the answer is to turn token
 * exchange on, and the administrator diagnostic says so.
 *
 * **Nothing here is private to another app.** The three event classes and
 * the token model are `user_oidc`'s published surface. They are still
 * checked with `class_exists()` before use rather than trusted, the way
 * {@see IntegrationAppTransport} checks the one method it calls — an app
 * that is absent, disabled or older than its event API must report a
 * cause, not fatal on a widget render.
 *
 * ## What is stored, and why anything is
 *
 * The access token and its expiry, in the same place the OAuth2 provider
 * puts them, so a token is reused while it is fresh instead of dispatching
 * an event on every request. **No refresh token is ever written** — there
 * is nothing to refresh, and a stale one would make
 * {@see OpenProjectOAuth2::accessTokenFor()} try a grant that cannot work
 * if an instance were ever switched between methods.
 *
 * ## What cannot be verified here
 *
 * There is no SSO instance to test against, so this path is exercised by
 * unit tests with a faked dispatcher and has **not been seen working
 * against a real identity provider**. That is recorded in HANDOFF rather
 * than implied by its absence.
 */
class OpenProjectOidc implements IOpenProjectTokenProvider {

    public const APP_USER_OIDC = 'user_oidc';

    /** Nextcloud itself is the IdP, through the `oidc` app. */
    public const PROVIDER_NEXTCLOUD_HUB = 'nextcloud_hub';
    /** Somebody else's identity provider. */
    public const PROVIDER_EXTERNAL = 'external';

    private const EVENT_INTERNAL = 'OCA\\UserOIDC\\Event\\InternalTokenRequestedEvent';
    private const EVENT_EXTERNAL = 'OCA\\UserOIDC\\Event\\ExternalTokenRequestedEvent';
    private const EVENT_EXCHANGED = 'OCA\\UserOIDC\\Event\\ExchangedTokenRequestedEvent';
    private const USER_BACKEND = 'OCA\\UserOIDC\\User\\Backend';

    public function __construct(
        private OpenProjectCredentialStore $credentials,
        private IAppManager                $appManager,
        private IEventDispatcher           $dispatcher,
        private IConfig                    $config,
        private IUserManager               $userManager,
        private LoggerInterface            $logger,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────
    // Availability
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Can this provider run at all: `user_oidc` present, enabled, and
     * publishing the three events we dispatch.
     *
     * Checked rather than assumed, and checked per event class: an older
     * `user_oidc` has some of them and not others, and the one that is
     * missing is the one an instance needs.
     */
    public function isUsable(): bool {
        if (!$this->appManager->isInstalled(self::APP_USER_OIDC)) {
            return false;
        }
        foreach ([self::EVENT_INTERNAL, self::EVENT_EXTERNAL, self::EVENT_EXCHANGED] as $class) {
            if (!class_exists($class)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Is this user signed in in a way that can produce an OpenProject
     * token.
     *
     * With Nextcloud as the identity provider every account qualifies, so
     * the answer is yes. With an external one, only accounts that actually
     * came through `user_oidc`'s backend do — a local admin account on an
     * SSO instance cannot be given a token for OpenProject, and saying
     * "connect your account" to them would be a dead end.
     */
    public function isUserConnected(string $userId): bool {
        if (!$this->isUsable()) {
            return false;
        }
        if ($this->providerType() === self::PROVIDER_NEXTCLOUD_HUB) {
            return true;
        }
        if (!class_exists(self::USER_BACKEND)) {
            return false;
        }
        $user = $this->userManager->get($userId);
        return $user !== null && is_a($user->getBackend(), self::USER_BACKEND);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tokens
    // ─────────────────────────────────────────────────────────────────────

    public function accessTokenFor(string $userId): ?string {
        // A token already in hand and still fresh costs nothing.
        $fresh = $this->credentials->getFreshAccessToken($userId);
        if ($fresh !== null) {
            return $fresh;
        }
        if (!$this->isUsable()) {
            return null;
        }

        $event = $this->buildEvent();
        if ($event === null) {
            return null;
        }

        try {
            $this->dispatcher->dispatchTyped($event);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][OpenProjectOidc] Token event threw', [
                'class' => get_class($e),
                'app'   => Application::APP_ID,
            ]);
            return null;
        }

        $token = method_exists($event, 'getToken') ? $event->getToken() : null;
        if ($token === null) {
            // Dispatched, but nothing answered: `user_oidc` is present and
            // declined, which is what an unconfigured provider looks like.
            $this->logger->warning('[TeamHub][OpenProjectOidc] No token came back from user_oidc', [
                'provider' => $this->providerType(),
                'app'      => Application::APP_ID,
            ]);
            return null;
        }

        $accessToken = (string)$token->getAccessToken();
        if ($accessToken === '') {
            return null;
        }

        // `getExpiresInFromNow()` is seconds from now, which is exactly
        // what the store wants; `getExpiresIn()` is relative to issue and
        // would over-state the life of a token minted a while ago.
        $expiresIn = method_exists($token, 'getExpiresInFromNow')
            ? (int)$token->getExpiresInFromNow()
            : null;

        // Deliberately no refresh token: there is nothing to refresh, and
        // storing one would leave a grant behind that only the OAuth2
        // provider knows how to use.
        $this->credentials->storeTokens($userId, $accessToken, $expiresIn, null, null);
        return $accessToken;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Configuration
    // ─────────────────────────────────────────────────────────────────────

    public function providerType(): string {
        $value = $this->config->getAppValue(
            Application::APP_ID,
            OpenProjectCredentialStore::CFG_SSO_PROVIDER,
            '',
        );
        return $value === self::PROVIDER_NEXTCLOUD_HUB ? self::PROVIDER_NEXTCLOUD_HUB : self::PROVIDER_EXTERNAL;
    }

    public function tokenExchangeEnabled(): bool {
        return $this->config->getAppValue(
            Application::APP_ID,
            OpenProjectCredentialStore::CFG_TOKEN_EXCHANGE,
            '0',
        ) === '1';
    }

    /** The OIDC client id OpenProject is registered as. */
    public function targetAudience(): string {
        return trim($this->config->getAppValue(
            Application::APP_ID,
            OpenProjectCredentialStore::CFG_AUDIENCE,
            '',
        ));
    }

    /**
     * The event for this instance's shape, or null when the configuration
     * cannot produce one.
     *
     * The two audience-bearing shapes need an audience; the third does not
     * and must not be refused for lacking one.
     */
    private function buildEvent(): ?object {
        $audience = $this->targetAudience();
        $scopes   = [OpenProjectOAuth2::SCOPE];

        if ($this->providerType() === self::PROVIDER_NEXTCLOUD_HUB) {
            if ($audience === '') {
                $this->logger->warning('[TeamHub][OpenProjectOidc] No target audience configured', [
                    'app' => Application::APP_ID,
                ]);
                return null;
            }
            $class = self::EVENT_INTERNAL;
            return new $class($audience, $scopes, $audience);
        }

        if (!$this->tokenExchangeEnabled()) {
            // The login token as-is. Scopes cannot be requested here —
            // `user_oidc`'s limitation, recorded in the class docblock.
            $class = self::EVENT_EXTERNAL;
            return new $class();
        }

        if ($audience === '') {
            $this->logger->warning('[TeamHub][OpenProjectOidc] Token exchange is on but no audience is configured', [
                'app' => Application::APP_ID,
            ]);
            return null;
        }
        $class = self::EVENT_EXCHANGED;
        return new $class($audience, $scopes);
    }
}
