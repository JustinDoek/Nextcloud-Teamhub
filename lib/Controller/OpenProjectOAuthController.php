<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Service\OpenProject\OpenProjectMessages;
use OCA\TeamHub\Service\OpenProject\OpenProjectModuleService;
use OCA\TeamHub\Service\OpenProject\Transport\OpenProjectCredentialStore;
use OCA\TeamHub\Service\OpenProject\Transport\OpenProjectOAuth2;
use OCA\TeamHub\Service\OpenProject\Transport\OpenProjectOidc;
use OCA\TeamHub\Service\OpenProject\Transport\OpenProjectTokenProviderResolver;
use OCA\TeamHub\Service\OpenProject\Transport\OpenProjectTransportSelector;
use OCA\TeamHub\Service\OpenProject\Transport\TransportUrl;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Connecting a user's own OpenProject account to TeamHub's own transport,
 * and configuring that transport (v4.13.5).
 *
 * Three user routes — start, callback, disconnect — and two administrator
 * routes for the instance configuration. Deliberately a controller of its
 * own rather than more methods on `OpenProjectController`: everything here
 * is about *establishing* a connection, nothing reads a project, and the
 * callback has a security posture (`#[NoCSRFRequired]`) that must not be
 * sitting in the same file as the data routes where somebody could copy it
 * by habit.
 *
 * ## Why the callback waives Nextcloud's CSRF check
 *
 * It is a redirect arriving from OpenProject, so no Nextcloud CSRF token
 * can ride on it — there is no alternative, and CLAUDE.md asks for the
 * reason to be written down. OAuth2's own defence replaces it:
 * `OpenProjectOAuth2` issues a 32-character `state` bound to the user who
 * started the flow, compares it with `hash_equals()` on return, and
 * deletes it whether or not it matched. A callback with a missing, stale
 * or mismatched `state` never reaches the token exchange. The route also
 * carries `#[UserRateLimit]`, so a guessing loop is not free.
 *
 * ## What never appears in a URL, a log or a response
 *
 * The authorization code, the tokens, the client secret and the `state`.
 * The callback answers with a redirect to the personal settings page
 * carrying only a coarse outcome (`connected`, or a short reason), so
 * nothing sensitive reaches the browser's history or a Referer header.
 */
class OpenProjectOAuthController extends Controller {
    use ExceptionResponseTrait;
    use OpenProjectResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private OpenProjectCredentialStore       $credentials,
        private OpenProjectOAuth2                $oauth2,
        private OpenProjectOidc                  $oidc,
        private OpenProjectTokenProviderResolver $tokens,
        // v4.13.21 — which route answers for this viewer, so the personal
        // settings panel can send somebody on the official app's transport to
        // the official app instead of offering a Connect button that would
        // write a credential TeamHub's own transport is not using. See
        // status().
        private OpenProjectTransportSelector     $transports,
        private OpenProjectModuleService         $module,
        private OpenProjectMessages              $messages,
        private IGroupManager                    $groupManager,
        private IURLGenerator                    $urlGenerator,
        private IUserSession                     $userSession,
        private LoggerInterface                  $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // ─────────────────────────────────────────────────────────────────────
    // The user's own connection
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Where this user stands with TeamHub's own OpenProject connection, for
     * the personal settings panel.
     */
    #[NoAdminRequired]
    public function status(): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        $uid = $this->currentUid();
        if ($uid === '') {
            return new JSONResponse(['error' => 'No user session'], Http::STATUS_UNAUTHORIZED);
        }
        return new JSONResponse([
            'configured'      => $this->credentials->isConfigured(),
            'methodSupported' => $this->tokens->isMethodSupported(),
            // v4.13.21 — `direct` or `integration_app`. The panel needs it
            // because `configured` answers a narrower question than it looks:
            // it is about **TeamHub's own** OAuth client, so it is false on a
            // perfectly working instance whose OpenProject runs through the
            // official app. Without this the panel hid its OpenProject
            // section on exactly those instances, and every Connect button in
            // the app pointed at a page that had nothing on it.
            'transport'       => $this->transports->forUser($uid)->id(),
            'authMethod'      => $this->credentials->getAuthMethod(),
            'host'            => $this->credentials->getHost() !== '' ? $this->credentials->getHost() : null,
            'connected'       => $this->credentials->hasCredential($uid),
            'openProjectUser' => $this->credentials->getUserName($uid) !== ''
                ? $this->credentials->getUserName($uid)
                : null,
        ]);
    }

    /**
     * Begin the authorization: answers the OpenProject URL for the browser
     * to go to. A JSON answer rather than a redirect so the settings panel
     * can show a reason instead of navigating into a 422.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function connect(): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        $uid = $this->currentUid();
        if ($uid === '') {
            return new JSONResponse(['error' => 'No user session'], Http::STATUS_UNAUTHORIZED);
        }

        $url = $this->oauth2->authorizeUrl($uid);
        if ($url === null) {
            $code = $this->credentials->isConfigured()
                ? OpenProjectException::AUTH_METHOD_UNSUPPORTED
                : OpenProjectException::HOST_NOT_CONFIGURED;
            return new JSONResponse($this->describeOpenProjectCode($code), Http::STATUS_UNPROCESSABLE_ENTITY);
        }
        return new JSONResponse(['authorizeUrl' => $url]);
    }

    /**
     * OpenProject redirects the browser back here with `code` and `state`.
     *
     * `#[NoCSRFRequired]` is unavoidable and is answered by `state` — see
     * the class docblock. The outcome travels as a query parameter on a
     * redirect to the personal settings page; nothing sensitive is in it.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function callback(string $code = '', string $state = '', string $error = ''): RedirectResponse {
        $uid = $this->currentUid();
        if ($uid === '') {
            // No session: the user signed out mid-flow, or this is a stray
            // request. Nothing to do and nothing to say.
            return new RedirectResponse($this->urlGenerator->linkToRouteAbsolute('settings.PersonalSettings.index', ['section' => 'teamhub']));
        }

        if ($error !== '') {
            // The user declined in OpenProject, or OpenProject refused the
            // client. `error` is OpenProject's own short token
            // (`access_denied`, `invalid_scope`); it is not shown.
            $this->logger->info('[TeamHub][OpenProjectOAuth] Authorization was not granted', [
                'app' => Application::APP_ID,
            ]);
            return $this->backToSettings('declined');
        }

        $reason = $this->oauth2->completeAuthorization($uid, $code, $state);
        return $this->backToSettings($reason ?? 'connected');
    }

    /** End this user's own connection. Nothing is revoked in OpenProject. */
    #[NoAdminRequired]
    public function disconnect(): JSONResponse {
        $uid = $this->currentUid();
        if ($uid === '') {
            return new JSONResponse(['error' => 'No user session'], Http::STATUS_UNAUTHORIZED);
        }
        $this->oauth2->disconnect($uid);
        return new JSONResponse(['connected' => false]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // The instance configuration (Nextcloud administrator)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The configuration, for Admin → TeamHub. **The client secret is never
     * returned** — only whether one is stored. A settings page that could
     * read a secret back is a settings page that leaks it to anyone who
     * can reach the admin area, including through a cached response.
     */
    public function adminConfig(): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }
        return new JSONResponse([
            'url'             => $this->credentials->getHost(),
            'authMethod'      => $this->credentials->getAuthMethod(),
            'clientId'        => $this->credentials->getClientId(),
            'clientSecretSet' => $this->credentials->getClientSecret() !== '',
            'configured'      => $this->credentials->isConfigured(),
            'methodSupported' => $this->tokens->isMethodSupported(),
            // Generated, never typed: it has to match character for
            // character in OpenProject, so the administrator copies it.
            'redirectUri'     => $this->oauth2->redirectUri(),
            'scope'           => OpenProjectOAuth2::SCOPE,
            // v4.13.8 — the OIDC half. `oidcAvailable` is the honest
            // answer to "can this instance use single sign-on at all",
            // which depends on `user_oidc` being present and current, not
            // on anything an administrator can type here.
            'ssoProvider'     => $this->oidc->providerType(),
            'tokenExchange'   => $this->oidc->tokenExchangeEnabled(),
            'audience'        => $this->oidc->targetAudience(),
            'oidcAvailable'   => $this->oidc->isUsable(),
        ]);
    }

    /**
     * Save the configuration. An absent `clientSecret` leaves the stored
     * one alone, so an administrator can change the URL without retyping
     * a secret the page never showed them; an empty string clears it.
     */
    public function saveAdminConfig(
        ?string $url = null,
        ?string $authMethod = null,
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?string $ssoProvider = null,
        ?bool $tokenExchange = null,
        ?string $audience = null,
    ): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        if ($url !== null) {
            $url = trim($url);
            if ($url !== '' && !TransportUrl::isValidHost($url)) {
                return new JSONResponse(
                    ['error' => 'The OpenProject URL must be a full http:// or https:// address.'],
                    Http::STATUS_BAD_REQUEST,
                );
            }
            $this->credentials->setHost($url);
        }
        if ($authMethod !== null) {
            $this->credentials->setAuthMethod($authMethod);
        }
        if ($clientId !== null) {
            $this->credentials->setClientId(trim($clientId));
        }
        if ($clientSecret !== null) {
            $this->credentials->setClientSecret($clientSecret);
        }
        // v4.13.8 — the OIDC settings. Absent means unchanged, as above.
        if ($ssoProvider !== null) {
            $this->credentials->setSsoProvider($ssoProvider);
        }
        if ($tokenExchange !== null) {
            $this->credentials->setTokenExchange($tokenExchange);
        }
        if ($audience !== null) {
            $this->credentials->setAudience($audience);
        }

        $this->logger->info('[TeamHub][OpenProjectOAuth] Direct transport configuration changed', [
            'app' => Application::APP_ID,
        ]);
        return $this->adminConfig();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    private function backToSettings(string $outcome): RedirectResponse {
        return new RedirectResponse(
            $this->urlGenerator->linkToRouteAbsolute('settings.PersonalSettings.index', ['section' => 'teamhub'])
            . '?openprojectResult=' . rawurlencode($outcome),
        );
    }

    private function currentUid(): string {
        return $this->userSession->getUser()?->getUID() ?? '';
    }

    private function isAdmin(): bool {
        $uid = $this->currentUid();
        return $uid !== '' && $this->groupManager->isAdmin($uid);
    }

    protected function openProjectMessages(): OpenProjectMessages {
        return $this->messages;
    }

    protected function openProjectModuleCode(): ?string {
        return $this->module->unavailableCode();
    }
}
