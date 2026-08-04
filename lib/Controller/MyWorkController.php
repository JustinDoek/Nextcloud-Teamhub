<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\MyWork\ActionType;
use OCA\TeamHub\Service\LicenseService;
use OCA\TeamHub\Service\MyWorkConfigService;
use OCA\TeamHub\Service\MyWorkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Member-facing My Work endpoints (v4.5.21).
 *
 * Every method here is `#[NoAdminRequired]` — this is a personal view, not an
 * administrative one — and every method is licence-gated on the same ladder as
 * "What's new": enforcement level `none` or `grace` passes, everything else
 * gets a 403 carrying `licenseGate: true` so the frontend can render the
 * "License required" state rather than a generic failure.
 *
 * There is deliberately **no team id in any route**. My Work is cross-team by
 * definition; the team boundary is resolved server-side from the session user's
 * own memberships in `MyWorkService::resolveTeams()`, so there is no team
 * parameter for a caller to tamper with.
 *
 * The action endpoint is a POST with the target in the body rather than in the
 * path. Provider item ids are opaque strings — a Deck card id today, plausibly
 * a path or a UUID from a future provider — and Nextcloud's routing strips
 * trailing `.md`-style suffixes from path segments as format hints, which has
 * already bitten this codebase once (see APIendpoints.md § Announcements).
 */
class MyWorkController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private MyWorkService $myWorkService,
        private MyWorkConfigService $configService,
        private LicenseService $licenseService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/mywork
     *
     * The whole queue: items for the requested page, group headings, the five
     * category counts, per-provider status, and the team list for the filter.
     *
     * Query parameters (all optional):
     *   categories, providerIds, resourceTypes, priorities, statuses, teamIds
     *                    — comma-separated or repeated array params
     *   search           — free text
     *   dueFrom, dueTo   — Unix seconds
     *   includeSnoozed   — 1/0
     *   groupBy          — category | date | team | resource_type
     *   sortBy           — deadline | priority | team | recent (within a group)
     *   limit            — 1..200, default 50
     *   offset           — ≥0
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getWork(): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        $gate = $this->licenseGate();
        if ($gate !== null) {
            return $gate;
        }

        try {
            return new JSONResponse($this->myWorkService->getWork($uid, [
                'categories'     => $this->request->getParam('categories', []),
                'providerIds'    => $this->request->getParam('providerIds', []),
                'resourceTypes'  => $this->request->getParam('resourceTypes', []),
                'priorities'     => $this->request->getParam('priorities', []),
                'statuses'       => $this->request->getParam('statuses', []),
                'teamIds'        => $this->request->getParam('teamIds', []),
                'search'         => (string)$this->request->getParam('search', ''),
                'dueFrom'        => $this->intOrNull($this->request->getParam('dueFrom')),
                'dueTo'          => $this->intOrNull($this->request->getParam('dueTo')),
                'includeSnoozed' => $this->boolParam('includeSnoozed', false),
                'groupBy'        => (string)$this->request->getParam('groupBy', 'category'),
                'sortBy'         => (string)$this->request->getParam('sortBy', ''),
                'limit'          => (int)$this->request->getParam('limit', 50),
                'offset'         => (int)$this->request->getParam('offset', 0),
                // Set by the Refresh button. A user who explicitly asks for
                // fresh data must not be answered from cache.
                'nocache'        => $this->boolParam('nocache', false),
            ]));
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWorkController] getWork failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to load My Work'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }

    /**
     * GET /api/v1/mywork/counts
     *
     * Just the five numbers. Used by the periodic refresh so a background poll
     * does not pay for grouping and action resolution.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getCounts(): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        $gate = $this->licenseGate();
        if ($gate !== null) {
            return $gate;
        }

        try {
            return new JSONResponse(['counts' => $this->myWorkService->getCounts($uid)]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWorkController] getCounts failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to load counts'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/v1/mywork/providers
     *
     * Provider descriptors for the filter chips: id, translated name, icon
     * key, capabilities, and whether each is currently usable.
     *
     * Member-callable on purpose — the filter bar needs it — so it carries no
     * administrative detail beyond availability. The last-error message is
     * admin-only and lives on the admin endpoint instead.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getProviders(): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        $gate = $this->licenseGate();
        if ($gate !== null) {
            return $gate;
        }

        try {
            $providers = array_map(static function (array $p): array {
                // Strip the operational fields — a member does not need the
                // instance's last sync time or its error strings.
                unset($p['lastSyncAt'], $p['lastErrorAt'], $p['lastError'], $p['configSchema'], $p['diagnostics']);
                return $p;
            }, $this->myWorkService->describeProviders());

            return new JSONResponse(['providers' => $providers]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWorkController] getProviders failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to load providers'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /api/v1/mywork/action
     * Body: { providerId, itemId, action, params? }
     *
     * CSRF-protected (no `#[NoCSRFRequired]`) — this mutates state in a source
     * application and `@nextcloud/axios` sends the request token automatically.
     */
    #[NoAdminRequired]
    public function executeAction(): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        $gate = $this->licenseGate();
        if ($gate !== null) {
            return $gate;
        }

        $providerId = trim((string)$this->request->getParam('providerId', ''));
        $itemId     = trim((string)$this->request->getParam('itemId', ''));
        $action     = trim((string)$this->request->getParam('action', ''));
        $params     = $this->request->getParam('params', []);
        if (!is_array($params)) {
            $params = [];
        }

        // Input validation before anything touches a service. Provider ids and
        // item ids are opaque, so validate their *shape* rather than their
        // content: printable, bounded, no path or control characters.
        if ($providerId === '' || !preg_match('/^[a-z0-9_-]{1,64}$/', $providerId)) {
            return new JSONResponse(['error' => 'Invalid providerId'], Http::STATUS_BAD_REQUEST);
        }
        if ($itemId === '' || mb_strlen($itemId) > 255 || preg_match('/[\x00-\x1F\x7F]/', $itemId)) {
            return new JSONResponse(['error' => 'Invalid itemId'], Http::STATUS_BAD_REQUEST);
        }
        if (!ActionType::isValid($action)) {
            return new JSONResponse(['error' => 'Invalid action'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $result = $this->myWorkService->executeAction($uid, $providerId, $itemId, $action, $params);
            // Refused actions come back with their own HTTP status — never a
            // 200 with an error body (SKILLS.md § PHP coding standards).
            return new JSONResponse($result->jsonSerialize(), $result->httpStatus());
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWorkController] executeAction failed', [
                'provider' => $providerId, 'action' => $action,
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Action failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/v1/mywork/preferences
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getPreferences(): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        return new JSONResponse($this->configService->getUserPreferences($uid));
    }

    /**
     * PUT /api/v1/mywork/preferences
     * Body: { groupBy?, sortBy?, showSnoozed?, collapsedGroups?, compact?,
     *         pageSize?, filters? }
     *
     * `collapsedGroups` replaced `completedExpanded` in 4.5.39. An unknown key
     * is dropped by `saveUserPreferences` (it intersects against the defaults),
     * so an old client sending the old name changes nothing rather than
     * erroring.
     *
     * Presentation only. There is no preference that can remove a category or
     * change what My Work means — the specification requires the structure to
     * survive personalisation, so the server simply has no key for it.
     */
    #[NoAdminRequired]
    public function savePreferences(): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $body = $this->request->getParams();
        unset($body['_route']);

        try {
            return new JSONResponse($this->configService->saveUserPreferences($uid, $body));
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWorkController] savePreferences failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to save preferences'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ---------------------------------------------------------------------
    // Guards
    // ---------------------------------------------------------------------

    /** @return string|JSONResponse UID, or the 401 to return */
    private function requireUser(): string|JSONResponse {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null || $uid === '') {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        return $uid;
    }

    /**
     * Licence gate, identical to `MessageController::getPersonalFeed`. The
     * frontend also hides the sidebar entry, but that is presentation — this
     * is the boundary (SKILLS.md § Security standards).
     */
    private function licenseGate(): ?JSONResponse {
        $level = $this->licenseService->getEnforcementLevel();
        if ($level === 'none' || $level === 'grace') {
            return null;
        }
        return new JSONResponse([
            'error'            => 'My Work requires an active TeamHub license.',
            'licenseGate'      => true,
            'enforcementLevel' => $level,
        ], Http::STATUS_FORBIDDEN);
    }

    private function boolParam(string $name, bool $default): bool {
        $raw = $this->request->getParam($name, null);
        if ($raw === null) {
            return $default;
        }
        if (is_bool($raw)) {
            return $raw;
        }
        $s = strtolower((string)$raw);
        return $s === '1' || $s === 'true' || $s === 'yes' || $s === 'on';
    }

    private function intOrNull(mixed $raw): ?int {
        if ($raw === null || $raw === '') {
            return null;
        }
        $i = (int)$raw;
        return $i > 0 ? $i : null;
    }
}
