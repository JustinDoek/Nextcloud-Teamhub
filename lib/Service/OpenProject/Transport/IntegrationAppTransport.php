<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject\Transport;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\OpenProjectException;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * OpenProject through the official `integration_openproject` app (v4.9.3,
 * moved behind {@see IOpenProjectTransport} in v4.13.4).
 *
 * This is the transport TeamHub has always used, unchanged in behaviour:
 * every line here was `OpenProjectClient`'s until the seam was cut. It is
 * still the right one wherever the official app is installed, configured
 * and working, because it owns the hard part — the instance URL, the OAuth2
 * client, every user's access and refresh token, and the OIDC exchange
 * through `user_oidc`. All of it sits behind one public method:
 *
 *     OCA\OpenProject\Service\OpenProjectAPIService::request(
 *         string $userId, string $endPoint, array $params = [], string $method = 'GET'
 *     ): array
 *
 * **No token is read, refreshed, stored or logged here.** That claim, which
 * DESIGN §2.120 made for the whole integration, is now true of this
 * transport specifically; {@see DirectTransport} is where it stops being
 * true, and that is recorded separately.
 *
 * ## The compatibility guard
 *
 * The signature has been stable since the app's 1.x line but is not a
 * published contract, so it is checked rather than trusted: the class must
 * resolve, the method must exist, and it must take at least the two
 * arguments we pass. Anything else reports `integration_incompatible`
 * rather than a fatal `Error` on the first widget render.
 *
 * ## PATCH
 *
 * Not supported, and that is upstream's decision, not a gap here.
 * `rawRequest()` reads:
 *
 *     if ($method === 'GET') … elseif 'POST' … elseif 'PUT' … elseif 'DELETE' …
 *     else return ['error' => $this->l10n->t('Bad HTTP method')];
 *
 * So `supportsMethod('PATCH')` answers false and the selector, the
 * capability envelope and ultimately the UI can tell the truth about it
 * instead of offering a button that cannot work.
 */
class IntegrationAppTransport implements IOpenProjectTransport {

    public const INTEGRATION_APP_ID = 'integration_openproject';

    /** The official app's service class, resolved through the container. */
    private const API_SERVICE_CLASS = 'OCA\\OpenProject\\Service\\OpenProjectAPIService';

    /** appconfig key the official app stores its instance URL under. */
    private const CFG_INSTANCE_URL = 'openproject_instance_url';
    /** appconfig key the official app stores its auth method under. */
    private const CFG_AUTH_METHOD = 'authorization_method';
    /** user preference the official app writes on a successful connect. */
    private const PREF_USER_NAME = 'user_name';

    /** What upstream's `rawRequest()` will actually send. */
    private const SUPPORTED_METHODS = ['GET', 'POST', 'PUT', 'DELETE'];

    /** Memo per request — the environment does not change mid-request. */
    private ?object $service = null;
    private bool $serviceResolved = false;

    public function __construct(
        private IAppManager        $appManager,
        private IConfig            $config,
        private ContainerInterface $container,
        private IUserSession       $userSession,
        private LoggerInterface    $logger,
    ) {
    }

    public function id(): string {
        return self::ID_INTEGRATION_APP;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Environment facts — cheap, no HTTP
    // ─────────────────────────────────────────────────────────────────────

    /** Is the official app present on disk at all. */
    public function isInstalled(): bool {
        try {
            $this->appManager->getAppPath(self::INTEGRATION_APP_ID);
            return true;
        } catch (AppPathNotFoundException) {
            return false;
        }
    }

    /**
     * Is the official app enabled for the current user. Nextcloud can enable
     * an app for some groups only, so this is asked per user, not per
     * instance.
     */
    public function isAppEnabled(): bool {
        if (!$this->isInstalled()) {
            return false;
        }
        return $this->appManager->isEnabledForUser(self::INTEGRATION_APP_ID, $this->userSession->getUser());
    }

    public function unavailableCode(): ?string {
        if (!$this->isInstalled()) {
            return OpenProjectException::INTEGRATION_NOT_INSTALLED;
        }
        if (!$this->isAppEnabled()) {
            return OpenProjectException::INTEGRATION_DISABLED;
        }
        if ($this->resolveService() === null) {
            return OpenProjectException::INTEGRATION_INCOMPATIBLE;
        }
        if ($this->getHost() === '') {
            return OpenProjectException::HOST_NOT_CONFIGURED;
        }
        return null;
    }

    /**
     * The administrator-configured OpenProject instance URL, or '' when none
     * is set or the value is not an http(s) URL. Trailing slash removed.
     */
    public function getHost(): string {
        $url = trim($this->config->getAppValue(self::INTEGRATION_APP_ID, self::CFG_INSTANCE_URL, ''));
        if ($url === '' || !TransportUrl::isValidHost($url)) {
            return '';
        }
        return rtrim($url, '/');
    }

    public function getAuthMethod(): string {
        $method = $this->config->getAppValue(self::INTEGRATION_APP_ID, self::CFG_AUTH_METHOD, '');
        return $method === 'oidc' ? 'oidc' : 'oauth2';
    }

    /**
     * OAuth2 users connect explicitly and the official app records
     * `user_name` when they do; OIDC users are connected by their login, and
     * the official app's `isOIDCUser()` says whether this login is one of
     * those.
     */
    public function isUserConnected(string $userId): bool {
        if ($this->config->getUserValue($userId, self::INTEGRATION_APP_ID, self::PREF_USER_NAME, '') !== '') {
            return true;
        }
        if ($this->getAuthMethod() === 'oidc') {
            $service = $this->resolveService();
            if ($service !== null && method_exists($service, 'isOIDCUser')) {
                try {
                    return (bool)$service->isOIDCUser();
                } catch (\Throwable) {
                    return false;
                }
            }
        }
        return false;
    }

    public function supportsMethod(string $method): bool {
        return in_array(strtoupper($method), self::SUPPORTED_METHODS, true);
    }

    /**
     * Whether the installed official app can be driven at all. Public so the
     * capability service can report `integration_incompatible` separately
     * from "not installed"; nothing else needs the object.
     */
    public function hasUsableService(): bool {
        return $this->resolveService() !== null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Requests
    // ─────────────────────────────────────────────────────────────────────

    public function request(string $userId, string $endpoint, array $params, string $method): array {
        $service = $this->resolveService();
        if ($service === null) {
            return ['error' => 'integration service unavailable', 'statusCode' => null];
        }
        if (!$this->supportsMethod($method)) {
            // Belt and braces: the client checks `supportsMethod()` before
            // it gets here, so reaching this line means a caller bypassed
            // it. Answering with the error shape rather than upstream's
            // "Bad HTTP method" keeps the classification ours.
            return ['error' => 'method not supported by the integration app', 'statusCode' => null];
        }

        try {
            /** @var array<string, mixed>|mixed $result */
            $result = $service->request($userId, $endpoint, $params, strtoupper($method));
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][IntegrationAppTransport] Request threw', [
                'endpoint' => TransportUrl::safePath($endpoint),
                'class'    => get_class($e),
                'app'      => Application::APP_ID,
            ]);
            // The cause rides along so the client can attach it as the
            // exception's `previous`: a timeout inside the official app is
            // worth having in the log with its own stack.
            return ['error' => 'request threw', 'statusCode' => null, 'threw' => true, 'cause' => $e];
        }

        if (!is_array($result)) {
            return ['error' => 'non-array body', 'statusCode' => null, 'unsupported' => true];
        }
        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The official app's service, or null when it cannot be driven.
     * Memoised per request; a null is memoised too.
     */
    private function resolveService(): ?object {
        if ($this->serviceResolved) {
            return $this->service;
        }
        $this->serviceResolved = true;

        if (!$this->isAppEnabled() || !class_exists(self::API_SERVICE_CLASS)) {
            return null;
        }
        try {
            $service = $this->container->get(self::API_SERVICE_CLASS);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][IntegrationAppTransport] Could not resolve the integration service', [
                'class' => get_class($e),
                'app'   => Application::APP_ID,
            ]);
            return null;
        }
        if (!is_object($service) || !method_exists($service, 'request')) {
            return null;
        }
        try {
            $method = new \ReflectionMethod($service, 'request');
            if ($method->getNumberOfParameters() < 2 || $method->getNumberOfRequiredParameters() > 4) {
                return null;
            }
        } catch (\ReflectionException) {
            return null;
        }

        $this->service = $service;
        return $service;
    }
}
