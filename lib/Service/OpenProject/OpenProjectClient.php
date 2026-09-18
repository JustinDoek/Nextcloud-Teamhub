<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\OpenProjectException;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The one place TeamHub talks to OpenProject (v4.9.3, Phase 1).
 *
 * ## What it reuses, and why
 *
 * The official `integration_openproject` app already owns the hard part: the
 * OpenProject instance URL an administrator configured, the OAuth2 client, the
 * per-user access and refresh tokens, and — since 2.x — the OIDC token
 * exchange through `user_oidc`. All of it is reachable through one public
 * method on its service:
 *
 *     OCA\OpenProject\Service\OpenProjectAPIService::request(
 *         string $userId, string $endPoint, array $params = [], string $method = 'GET'
 *     ): array
 *
 * It refreshes an expired token itself, sends the request as `$userId`, and
 * returns the decoded HAL body — or `['error' => …, 'statusCode' => …]` on
 * failure. TeamHub calls that and nothing else. It never reads a token, never
 * refreshes one, never sees an Authorization header. There is no second token
 * store to secure because there is no second token store.
 *
 * The two other things read from the official app are not secrets:
 *   - appconfig `openproject_instance_url` — the host, for deep links and for
 *     the stale-link check;
 *   - user preference `user_name` — set by the official app when a user
 *     connects, deleted when they disconnect. It answers "has this user ever
 *     connected" without an HTTP round trip. Both are read through `IConfig`,
 *     which is the supported interface for that data.
 *
 * ## The module gate (v4.9.16)
 *
 * The OpenProject module is licensed and switched on by an administrator
 * (`OpenProjectModuleService`). `isIntegrationEnabled()` and
 * `compatibilityProblem()` ask that first, so every caller of either — the
 * My Work provider, the news and meetings services, the layout bundle's
 * facts, the capability probe, and `send()` itself — follows the gate without
 * naming it, and a switched-off module cannot reach OpenProject at all.
 * `isIntegrationAppEnabled()` is the raw app check for the diagnostics that
 * need to tell the two apart.
 *
 * ## The compatibility guard
 *
 * The method's signature has been stable since the app's 1.x line, but it is
 * not a published contract, so it is checked rather than trusted: the class
 * must resolve, the method must exist, and it must take at least the two
 * arguments we pass. Anything else reports `integration_incompatible` rather
 * than a fatal `Error` on the first widget render.
 *
 * ## What never reaches the log
 *
 * A failed response's body can carry a project name, a user's name or the
 * message of an error we did not anticipate. This class logs the endpoint's
 * path (never its query string — a project search carries user input) and the
 * upstream status, and builds exception messages from those alone.
 */
class OpenProjectClient {

    public const INTEGRATION_APP_ID = 'integration_openproject';

    /** The official app's service class, resolved through the container. */
    private const API_SERVICE_CLASS = 'OCA\\OpenProject\\Service\\OpenProjectAPIService';

    /** appconfig key the official app stores its instance URL under. */
    private const CFG_INSTANCE_URL = 'openproject_instance_url';
    /** appconfig key the official app stores its auth method under. */
    private const CFG_AUTH_METHOD = 'authorization_method';
    /** user preference the official app writes on a successful connect. */
    private const PREF_USER_NAME = 'user_name';

    /** Memo per request — the environment does not change mid-request. */
    private ?object $service = null;
    private bool $serviceResolved = false;

    public function __construct(
        private IAppManager              $appManager,
        private IConfig                  $config,
        private ContainerInterface       $container,
        private IUserSession             $userSession,
        // v4.9.16 — the module gate. A DI leaf from here: it reads appconfig
        // and LicenseService, neither of which reaches back into OpenProject.
        private OpenProjectModuleService $module,
        private LoggerInterface          $logger,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────
    // Environment facts — cheap, no HTTP
    // ─────────────────────────────────────────────────────────────────────

    /** Is TeamHub's OpenProject module licensed and switched on (v4.9.16). */
    public function isModuleAvailable(): bool {
        return $this->module->isAvailable();
    }

    /** Is the official app present on disk at all. */
    public function isIntegrationInstalled(): bool {
        try {
            $this->appManager->getAppPath(self::INTEGRATION_APP_ID);
            return true;
        } catch (AppPathNotFoundException) {
            return false;
        }
    }

    /**
     * Is the official app enabled for the current user — the raw app check,
     * regardless of TeamHub's module (v4.9.16). Nextcloud can enable an app
     * for some groups only, so this is asked per user, not per instance. For
     * diagnostics that report the app's own state; everything that decides
     * whether to *use* the integration asks `isIntegrationEnabled()`.
     */
    public function isIntegrationAppEnabled(): bool {
        if (!$this->isIntegrationInstalled()) {
            return false;
        }
        $user = $this->userSession->getUser();
        return $this->appManager->isEnabledForUser(self::INTEGRATION_APP_ID, $user);
    }

    /**
     * Can the integration be used from TeamHub by the current user: the
     * module is available **and** the official app is enabled for them.
     *
     * The module half was added in v4.9.16 here rather than at each caller,
     * so that every surface that already asked this question — and every one
     * written later out of habit — inherits the gate.
     */
    public function isIntegrationEnabled(): bool {
        return $this->module->isAvailable() && $this->isIntegrationAppEnabled();
    }

    /**
     * The administrator-configured OpenProject instance URL, or '' when none
     * is set or the value is not an http(s) URL. Trailing slash removed.
     *
     * **This is the only host TeamHub will ever talk to or link to.** It is
     * set by a Nextcloud administrator in the official app's settings; no
     * TeamHub surface accepts one.
     */
    public function getHost(): string {
        $url = trim($this->config->getAppValue(self::INTEGRATION_APP_ID, self::CFG_INSTANCE_URL, ''));
        if ($url === '' || !self::isValidHost($url)) {
            return '';
        }
        return rtrim($url, '/');
    }

    /** The official app's configured method: 'oauth2' (default) or 'oidc'. */
    public function getAuthMethod(): string {
        $method = $this->config->getAppValue(self::INTEGRATION_APP_ID, self::CFG_AUTH_METHOD, '');
        return $method === 'oidc' ? 'oidc' : 'oauth2';
    }

    /**
     * Has this user connected their OpenProject account — as far as can be
     * told without a request. OAuth2 users connect explicitly and the official
     * app records `user_name` when they do; OIDC users are connected by their
     * login, and the official app's `isOIDCUser()` says whether this login is
     * one of those. A `true` here is a hint; `users/me` is the proof.
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

    /**
     * The compatibility verdict for the installed official app. Null when it
     * is usable; otherwise the error code that says why not.
     */
    public function compatibilityProblem(): ?string {
        // v4.9.16 — the module before the app: a licence and the switch are
        // what an administrator fixes first, and an unlicensed instance must
        // not be told to install an app it cannot use.
        $module = $this->module->unavailableCode();
        if ($module !== null) {
            return $module;
        }
        if (!$this->isIntegrationInstalled()) {
            return OpenProjectException::INTEGRATION_NOT_INSTALLED;
        }
        if (!$this->isIntegrationAppEnabled()) {
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

    // ─────────────────────────────────────────────────────────────────────
    // Requests
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET one API v3 endpoint as `$userId` and return the decoded body.
     *
     * `$endpoint` is the path under `/api/v3/` — `projects/12`,
     * `work_packages`. `$params` become the query string; array values are
     * expanded the way the official app expects. Every failure is an
     * `OpenProjectException` with a classified code; a 404 is reported as
     * `project_not_found` because in Phase 1 every path we request is under a
     * project, and the caller re-maps it when it means something else.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws OpenProjectException
     */
    public function get(string $userId, string $endpoint, array $params = []): array {
        return $this->send($userId, $endpoint, $params, 'GET');
    }

    /**
     * POST one API v3 endpoint as `$userId` with a JSON body (v4.9.6,
     * Phase 2). The official app sends `$params['body']` as the request body
     * with `Content-Type: application/json` — verified against
     * `integration_openproject` 3.2.0's `rawRequest()` — so the body is
     * encoded here and nothing else is put in `$params`.
     *
     * The same classification as `get()`, plus 422 → `validation_failed`
     * carrying OpenProject's own sentence, because a creator has to read
     * "Identifier has already been taken" to fix it.
     *
     * A 302 (OpenProject answers a project copy with one, pointing at the job
     * status) is followed by Nextcloud's HTTP client, so the decoded body is
     * that of the redirect target.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws OpenProjectException
     */
    public function post(string $userId, string $endpoint, array $body = []): array {
        // Always a body, `{}` when there is nothing to say: the official app
        // sets `Content-Type: application/json` only when `body` is present,
        // and OpenProject answers a bodiless POST with 406 Not Acceptable —
        // which is how the "may create projects" probe read as "no" for
        // everyone (found on the instance, 2026-09-13).
        $params = ['body' => $body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR)];
        return $this->send($userId, $endpoint, $params, 'POST');
    }

    /**
     * DELETE one API v3 endpoint as `$userId` (v4.9.6). A 204 comes back from
     * the official app as `['success' => true]`.
     *
     * @return array<string, mixed>
     * @throws OpenProjectException
     */
    public function delete(string $userId, string $endpoint): array {
        return $this->send($userId, $endpoint, [], 'DELETE');
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws OpenProjectException
     */
    private function send(string $userId, string $endpoint, array $params, string $method): array {
        $problem = $this->compatibilityProblem();
        if ($problem !== null) {
            throw new OpenProjectException($problem, 'OpenProject integration is not usable: ' . $problem);
        }
        if (!$this->isUserConnected($userId)) {
            throw new OpenProjectException(
                OpenProjectException::USER_NOT_CONNECTED,
                'User has not connected an OpenProject account',
            );
        }

        $service = $this->resolveService();
        $path    = $this->safePath($endpoint);

        try {
            /** @var array<string, mixed>|mixed $result */
            $result = $service->request($userId, $endpoint, $params, $method);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][OpenProjectClient] Request threw', [
                'endpoint' => $path,
                'class'    => get_class($e),
                'app'      => Application::APP_ID,
            ]);
            throw new OpenProjectException(
                OpenProjectException::TEMPORARY_FAILURE,
                'OpenProject request failed for ' . $path,
                null,
                $e,
            );
        }

        if (!is_array($result)) {
            throw new OpenProjectException(
                OpenProjectException::UNSUPPORTED_RESPONSE,
                'OpenProject returned a non-array body for ' . $path,
            );
        }

        if (isset($result['error'])) {
            throw $this->classifyError($result, $path);
        }

        return $result;
    }

    /**
     * Whether the installed official app can be driven at all.
     *
     * Public so the capability service can report `integration_incompatible`
     * separately from "not installed"; nothing else needs the object.
     */
    public function hasUsableService(): bool {
        return $this->resolveService() !== null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // URL helpers — the only place a URL to OpenProject is ever assembled
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Resolve a link OpenProject handed us (`_links.*.href`) against the
     * configured host, and refuse anything that would leave that origin.
     * Returns null for a missing, relative-but-odd or foreign href.
     */
    public function absoluteUrl(?string $href): ?string {
        $host = $this->getHost();
        if ($host === '' || $href === null || trim($href) === '') {
            return null;
        }
        $href = trim($href);
        if (str_starts_with($href, '/')) {
            if (str_starts_with($href, '//')) {
                return null;
            }
            return $host . $href;
        }
        if (!self::isValidHost($href)) {
            return null;
        }
        return self::sameOrigin($host, $href) ? $href : null;
    }

    /**
     * Deep links into OpenProject's web UI. `$projectRef` is the validated
     * identifier, or the numeric id when the identifier is unknown —
     * OpenProject accepts either in `/projects/…`. Null when there is no host.
     */
    public function projectUrl(string $projectRef): ?string {
        return $this->deepLink('/projects/' . rawurlencode($projectRef));
    }

    public function workPackagesUrl(string $projectRef): ?string {
        return $this->deepLink('/projects/' . rawurlencode($projectRef) . '/work_packages');
    }

    public function newWorkPackageUrl(string $projectRef): ?string {
        return $this->deepLink('/projects/' . rawurlencode($projectRef) . '/work_packages/new');
    }

    public function workPackageUrl(int $workPackageId): ?string {
        return $workPackageId > 0 ? $this->deepLink('/work_packages/' . $workPackageId) : null;
    }

    /** A news item's page in OpenProject (v4.9.7). */
    public function newsUrl(int $newsId): ?string {
        return $newsId > 0 ? $this->deepLink('/news/' . $newsId) : null;
    }

    /** A meeting's page in OpenProject (v4.9.7). */
    public function meetingUrl(int $meetingId): ?string {
        return $meetingId > 0 ? $this->deepLink('/meetings/' . $meetingId) : null;
    }

    /**
     * The project's work-package list narrowed to the viewer's own open work
     * (v4.9.7). `query_props` is the web UI's own query encoding (`f` = the
     * filters, each `{n, o, v}` — the same grammar as the API's, spelled
     * short); a version that does not read it lands on the unfiltered list,
     * which is still the right page. Built from the configured host and a
     * validated project reference, like every other deep link here.
     */
    public function myWorkPackagesUrl(string $projectRef): ?string {
        $props = json_encode(['f' => [
            ['n' => 'assignee', 'o' => '=', 'v' => ['me']],
            ['n' => 'status',   'o' => 'o', 'v' => []],
        ]], JSON_THROW_ON_ERROR);
        return $this->deepLink('/projects/' . rawurlencode($projectRef) . '/work_packages?query_props=' . rawurlencode($props));
    }

    private function deepLink(string $path): ?string {
        $host = $this->getHost();
        return $host === '' ? null : $host . $path;
    }

    /** Is `$url` an absolute http(s) URL with a host part. */
    public static function isValidHost(string $url): bool {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        return in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }

    private static function sameOrigin(string $a, string $b): bool {
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

        if (!$this->isIntegrationEnabled() || !class_exists(self::API_SERVICE_CLASS)) {
            return null;
        }
        try {
            $service = $this->container->get(self::API_SERVICE_CLASS);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][OpenProjectClient] Could not resolve the integration service', [
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

    /**
     * Turn the official app's `['error' => …, 'statusCode' => …]` shape into
     * a classified exception. The body (`error`) is deliberately not carried
     * over — see the class docblock.
     *
     * The official app reports a connection failure as `statusCode 404` with
     * no `message` key, and a real 404 from OpenProject with the JSON error's
     * `message`. That is the only way to tell "unreachable" from "not found",
     * so it is what is used.
     *
     * @param array<string, mixed> $result
     */
    private function classifyError(array $result, string $path): OpenProjectException {
        $status = isset($result['statusCode']) ? (int)$result['statusCode'] : null;

        if ($status === 401) {
            $code = OpenProjectException::AUTH_FAILED;
        } elseif ($status === 403) {
            $code = OpenProjectException::PERMISSION_DENIED;
        } elseif ($status === 404 && !array_key_exists('message', $result)) {
            $code = OpenProjectException::API_UNAVAILABLE;
        } elseif ($status === 404) {
            $code = OpenProjectException::PROJECT_NOT_FOUND;
        } elseif ($status === 429) {
            $code = OpenProjectException::RATE_LIMITED;
        } elseif ($status === 422) {
            // v4.9.6 — OpenProject's validation verdict on a write. Its
            // `message` is a sentence about the input ("Identifier has
            // already been taken."), reduced to plain text and bounded; the
            // body itself is still not carried.
            $upstream = is_string($result['message'] ?? null)
                ? mb_substr(trim(strip_tags((string)$result['message'])), 0, 300)
                : null;
            $this->logger->info('[TeamHub][OpenProjectClient] OpenProject refused a write', [
                'endpoint' => $path,
                'status'   => $status,
                'app'      => Application::APP_ID,
            ]);
            return new OpenProjectException(
                OpenProjectException::VALIDATION_FAILED,
                'OpenProject answered 422 for ' . $path,
                $status,
                null,
                $upstream,
            );
        } elseif ($status !== null && $status >= 500) {
            // The official app also uses 500 for "URL is invalid".
            $code = is_string($result['error'] ?? null) && str_contains((string)$result['error'], 'URL is invalid')
                ? OpenProjectException::HOST_NOT_CONFIGURED
                : OpenProjectException::TEMPORARY_FAILURE;
        } elseif ($status !== null && $status >= 400) {
            $code = OpenProjectException::UNSUPPORTED_RESPONSE;
        } else {
            $code = OpenProjectException::API_UNAVAILABLE;
        }

        $this->logger->info('[TeamHub][OpenProjectClient] OpenProject request failed', [
            'endpoint' => $path,
            'status'   => $status,
            'code'     => $code,
            'app'      => Application::APP_ID,
        ]);

        return new OpenProjectException($code, 'OpenProject answered ' . ($status ?? 'nothing') . ' for ' . $path, $status);
    }

    /** The endpoint without its query string, for logs. */
    private function safePath(string $endpoint): string {
        $q = strpos($endpoint, '?');
        return $q === false ? $endpoint : substr($endpoint, 0, $q);
    }
}
