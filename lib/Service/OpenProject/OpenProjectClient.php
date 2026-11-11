<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Service\OpenProject\Transport\IOpenProjectTransport;
use OCA\TeamHub\Service\OpenProject\Transport\OpenProjectTransportSelector;
use OCA\TeamHub\Service\OpenProject\Transport\TransportUrl;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The one place TeamHub talks to OpenProject (v4.9.3; transport seam cut in
 * v4.13.4).
 *
 * ## What changed in 4.13.4, and what did not
 *
 * Until 4.13.3 this class *was* the dependency on `integration_openproject`:
 * it resolved that app's service, called its one `request()` method, and
 * read its settings. All of that moved, unchanged, into
 * {@see \OCA\TeamHub\Service\OpenProject\Transport\IntegrationAppTransport},
 * and a second transport was added beside it. What stayed here is
 * everything that is true however the request travels:
 *
 *  - the **module gate** (licence + the administrator's switch), asked once,
 *    above both transports;
 *  - **classification** — one upstream status, one `OpenProjectException`
 *    code, one sentence in `OpenProjectMessages`, for both transports;
 *  - **encoding** a body once, so the two transports cannot drift;
 *  - every **URL helper** and the origin check that keeps a link OpenProject
 *    handed back from leaving the configured host.
 *
 * Its public surface is unchanged, so the fifteen services, the controllers
 * and the My Work provider above it did not move. The one addition is
 * {@see patch()}.
 *
 * ## Why there are two transports
 *
 * The full reasoning is in {@see IOpenProjectTransport}. In short: the
 * official app's latest stable declares `max-version 34` while Nextcloud 35
 * has shipped, and its `rawRequest()` refuses PATCH outright — so the two
 * things DESIGN §2.124 and §2.126 recorded as "not built because the
 * official app has no PATCH" could never be built on it. Which transport
 * answers is {@see OpenProjectTransportSelector}'s decision, per user.
 *
 * ## The module gate (v4.9.16)
 *
 * The OpenProject module is licensed and switched on by an administrator
 * (`OpenProjectModuleService`). `isIntegrationEnabled()` and
 * `compatibilityProblem()` ask that first, so every caller of either — the
 * My Work provider, the news and meetings services, the layout bundle's
 * facts, the capability probe, and `send()` itself — follows the gate
 * without naming it, and a switched-off module cannot reach OpenProject at
 * all, by either route.
 *
 * ## What never reaches the log
 *
 * A failed response's body can carry a project name, a user's name or the
 * message of an error we did not anticipate. Nothing here logs more than
 * the endpoint's path (never its query string — a project search carries
 * user input) and the upstream status, and exception messages are built
 * from those alone. The transports keep the same rule, and the direct one
 * additionally never logs a host, a header or a token.
 */
class OpenProjectClient {

    /**
     * Kept for the callers and tests that have named it since 4.9.3. The
     * app id now lives on the transport that knows about that app.
     */
    public const INTEGRATION_APP_ID = Transport\IntegrationAppTransport::INTEGRATION_APP_ID;

    public function __construct(
        private OpenProjectTransportSelector $transports,
        private OpenProjectModuleService     $module,
        private IUserSession                 $userSession,
        private LoggerInterface              $logger,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────
    // Environment facts — cheap, no HTTP
    // ─────────────────────────────────────────────────────────────────────

    /** Is TeamHub's OpenProject module licensed and switched on (v4.9.16). */
    public function isModuleAvailable(): bool {
        return $this->module->isAvailable();
    }

    /**
     * Which transport answers for the current user, as an
     * `IOpenProjectTransport::ID_*` value. For diagnostics and the
     * capability envelope.
     */
    public function transportId(): string {
        return $this->transportForCurrentUser()->id();
    }

    /** Can the active transport send this HTTP method (v4.13.4). */
    public function supportsMethod(string $method): bool {
        return $this->transportForCurrentUser()->supportsMethod($method);
    }

    /**
     * Is the **official app** present on disk at all. Unchanged in meaning
     * since 4.9.3: it is a fact about that app, not about whether TeamHub
     * can reach OpenProject, and the diagnostics need to keep telling the
     * two apart.
     */
    public function isIntegrationInstalled(): bool {
        return $this->transports->integrationApp()->isInstalled();
    }

    /** Is the official app enabled for the current user — the raw app check. */
    public function isIntegrationAppEnabled(): bool {
        return $this->transports->integrationApp()->isAppEnabled();
    }

    /**
     * Can the integration be used from TeamHub by the current user: the
     * module is available **and** some transport is usable.
     *
     * Widened in 4.13.4 — before the seam this could only mean the official
     * app. An instance with the direct transport configured and no official
     * app installed now answers true, which is the point of the exercise.
     */
    public function isIntegrationEnabled(): bool {
        return $this->module->isAvailable()
            && $this->transportForCurrentUser()->unavailableCode() === null;
    }

    /**
     * The OpenProject instance URL in force, without a trailing slash, or ''.
     *
     * **This is the only host TeamHub will ever talk to or link to.** It is
     * set by a Nextcloud administrator — in the official app's settings or
     * in TeamHub's own, depending on the transport. No TeamHub surface
     * accepts one from a user.
     */
    public function getHost(): string {
        return $this->transportForCurrentUser()->getHost();
    }

    /** The configured method: 'oauth2' or 'oidc'. */
    public function getAuthMethod(): string {
        return $this->transportForCurrentUser()->getAuthMethod();
    }

    /**
     * Has this user connected an OpenProject account, as far as can be told
     * without a request. A `true` here is a hint; `users/me` is the proof.
     */
    public function isUserConnected(string $userId): bool {
        return $this->transports->forUser($userId)->isUserConnected($userId);
    }

    /**
     * The compatibility verdict. Null when OpenProject is usable; otherwise
     * the error code that says why not, in the order an administrator would
     * fix it: module licence → module switch → the transport's own state.
     */
    public function compatibilityProblem(): ?string {
        return $this->problemFor($this->currentUid());
    }

    /**
     * The same verdict for a named user (v4.13.4).
     *
     * `send()` asks this rather than `compatibilityProblem()` so that the
     * transport whose state is checked is the same one the request is
     * about to go out on. The two can only differ where the session user
     * is not the user being read for — which §2.120 forbids, since every
     * OpenProject request runs as the person looking — but a gate that is
     * right only because something else is right is one refactor away from
     * being wrong.
     */
    private function problemFor(string $userId): ?string {
        $module = $this->module->unavailableCode();
        if ($module !== null) {
            return $module;
        }
        return $this->transports->forUser($userId)->unavailableCode();
    }

    /**
     * Whether the installed official app can be driven at all. Public so
     * the capability service can report `integration_incompatible`
     * separately from "not installed".
     *
     * Still gated on the module, as it was before the seam: a switched-off
     * module never resolves that app's service.
     */
    public function hasUsableService(): bool {
        return $this->module->isAvailable() && $this->transports->integrationApp()->hasUsableService();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Requests
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET one API v3 endpoint as `$userId` and return the decoded body.
     *
     * `$endpoint` is the path under `/api/v3/` — `projects/12`,
     * `work_packages`. `$params` become the query string; array values are
     * expanded as repeated `key[]=`. Every failure is an
     * `OpenProjectException` with a classified code; a 404 is reported as
     * `project_not_found` because in Phase 1 every path we request is under
     * a project, and the caller re-maps it when it means something else.
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
     * Phase 2).
     *
     * The same classification as `get()`, plus 422 → `validation_failed`
     * carrying OpenProject's own sentence, because a creator has to read
     * "Identifier has already been taken" to fix it.
     *
     * A 302 (OpenProject answers a project copy with one, pointing at the
     * job status) is followed by the HTTP client, so the decoded body is
     * that of the redirect target.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws OpenProjectException
     */
    public function post(string $userId, string $endpoint, array $body = []): array {
        return $this->send($userId, $endpoint, $this->jsonBody($body), 'POST');
    }

    /**
     * PATCH one API v3 endpoint as `$userId` with a JSON body (v4.13.4).
     *
     * **The method OpenProject updates a work package with, and the one the
     * official app refuses to send.** A caller that reaches this through
     * the integration-app transport gets `method_unsupported` rather than
     * upstream's untranslated "Bad HTTP method" — a code with both a user
     * sentence and an administrator sentence, so the surface above can hide
     * the action and the diagnostic can say why.
     *
     * Callers that offer an edit must ask {@see supportsMethod()} first and
     * hide the action when it answers false: CLAUDE.md's rule is that what
     * a person may not do is hidden, not disabled, and a transport that
     * cannot PATCH is the same kind of fact.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws OpenProjectException
     */
    public function patch(string $userId, string $endpoint, array $body = []): array {
        return $this->send($userId, $endpoint, $this->jsonBody($body), 'PATCH');
    }

    /**
     * DELETE one API v3 endpoint as `$userId` (v4.9.6). A 204 comes back as
     * `['success' => true]`.
     *
     * @return array<string, mixed>
     * @throws OpenProjectException
     */
    public function delete(string $userId, string $endpoint): array {
        return $this->send($userId, $endpoint, [], 'DELETE');
    }

    /**
     * Always a body, `{}` when there is nothing to say: the official app
     * sets `Content-Type: application/json` only when `body` is present,
     * and OpenProject answers a bodiless POST with 406 Not Acceptable —
     * which is how the "may create projects" probe read as "no" for
     * everyone (found on the instance, 2026-09-13).
     *
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    private function jsonBody(array $body): array {
        return ['body' => $body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR)];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws OpenProjectException
     */
    private function send(string $userId, string $endpoint, array $params, string $method): array {
        $problem = $this->problemFor($userId);
        if ($problem !== null) {
            throw new OpenProjectException($problem, 'OpenProject integration is not usable: ' . $problem);
        }

        $transport = $this->transports->forUser($userId);
        $path      = TransportUrl::safePath($endpoint);

        if (!$transport->supportsMethod($method)) {
            $this->logger->info('[TeamHub][OpenProjectClient] Active transport cannot send this method', [
                'endpoint'  => $path,
                'method'    => strtoupper($method),
                'transport' => $transport->id(),
                'app'       => Application::APP_ID,
            ]);
            throw new OpenProjectException(
                OpenProjectException::METHOD_UNSUPPORTED,
                strtoupper($method) . ' is not available through the ' . $transport->id() . ' transport',
            );
        }

        if (!$transport->isUserConnected($userId)) {
            throw new OpenProjectException(
                OpenProjectException::USER_NOT_CONNECTED,
                'User has not connected an OpenProject account',
            );
        }

        $result = $transport->request($userId, $endpoint, $params, $method);
        $cause  = ($result['cause'] ?? null) instanceof \Throwable ? $result['cause'] : null;

        if (isset($result['threw'])) {
            throw new OpenProjectException(
                OpenProjectException::TEMPORARY_FAILURE,
                'OpenProject request failed for ' . $path,
                null,
                $cause,
            );
        }
        if (isset($result['unsupported'])) {
            throw new OpenProjectException(
                OpenProjectException::UNSUPPORTED_RESPONSE,
                'OpenProject returned a non-array body for ' . $path,
            );
        }
        if (isset($result['error'])) {
            throw $this->classifyError($result, $path, $cause);
        }

        return $result;
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
        return TransportUrl::sameOrigin($host, $href) ? $href : null;
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

    /** A version's own page in OpenProject (v4.13.13). */
    public function versionUrl(int $versionId): ?string {
        return $versionId > 0 ? $this->deepLink('/versions/' . $versionId) : null;
    }

    /**
     * The project's wiki table of contents in OpenProject (v4.13.13).
     *
     * `/wiki/index`, not `/wiki`: OpenProject 17 declares the wiki as
     * `resources :wiki, except: %i[index create]` and adds the index back
     * explicitly as `get "/index" => "wiki#index"`, so the bare path has no
     * route. The table of contents is also the right target — it lists the
     * pages, which is what the Pages widget is about.
     *
     * A link-out is all TeamHub can offer here: API v3 mounts wiki pages as
     * `GET /api/v3/wiki_pages/{id}` only, with no index and no
     * project-scoped collection, so a project's wiki pages cannot be
     * enumerated (verified against OpenProject 17, 2026-10-20).
     */
    public function wikiUrl(string $projectRef): ?string {
        return $this->deepLink('/projects/' . rawurlencode($projectRef) . '/wiki/index');
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

    /**
     * Is `$url` an absolute http(s) URL with a host part. Public static
     * since 4.9.3; the rule itself lives in `TransportUrl` so both
     * transports share it.
     */
    public static function isValidHost(string $url): bool {
        return TransportUrl::isValidHost($url);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The transport for the session user. '' when nobody is signed in,
     * which the selector reads as "no per-user preference" and answers from
     * the instance configuration alone.
     */
    private function transportForCurrentUser(): IOpenProjectTransport {
        return $this->transports->forUser($this->currentUid());
    }

    private function currentUid(): string {
        return $this->userSession->getUser()?->getUID() ?? '';
    }

    /**
     * Turn a transport's `['error' => …, 'statusCode' => …]` shape into a
     * classified exception. The body (`error`) is deliberately not carried
     * over — see the class docblock.
     *
     * The official app reports a connection failure as `statusCode 404`
     * with no `message` key, and a real 404 from OpenProject with the JSON
     * error's `message`. That is the only way to tell "unreachable" from
     * "not found" through that transport, so it is what is used. The direct
     * transport does not reproduce the quirk — it answers `statusCode null`
     * for a transport failure and always carries a `message` key on a real
     * response — and lands on the same codes by the ordinary route.
     *
     * @param array<string, mixed> $result
     */
    private function classifyError(array $result, string $path, ?\Throwable $cause = null): OpenProjectException {
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
        } elseif ($status === 409) {
            // v4.13.9 — a stale `lockVersion` on a PATCH: somebody else
            // changed the work package first. Carries OpenProject's own
            // sentence for the same reason 422 does, because "which field"
            // is the useful part.
            $upstream = is_string($result['message'] ?? null)
                ? mb_substr(trim(strip_tags((string)$result['message'])), 0, 300)
                : null;
            $this->logger->info('[TeamHub][OpenProjectClient] OpenProject refused a write as out of date', [
                'endpoint' => $path,
                'status'   => $status,
                'app'      => Application::APP_ID,
            ]);
            return new OpenProjectException(
                OpenProjectException::EDIT_CONFLICT,
                'OpenProject answered 409 for ' . $path,
                $status,
                $cause,
                $upstream !== null && $upstream !== '' ? $upstream : null,
            );
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
                $upstream !== null && $upstream !== '' ? $upstream : null,
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

        return new OpenProjectException(
            $code,
            'OpenProject answered ' . ($status ?? 'nothing') . ' for ' . $path,
            $status,
            $cause,
        );
    }
}
