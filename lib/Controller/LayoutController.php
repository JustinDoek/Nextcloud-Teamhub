<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Db\LayoutMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * LayoutController — per-user, per-team Home-view grid layout + tab order.
 *
 * GET  /api/v1/teams/{teamId}/layout  → returns saved layout or server-side default
 * PUT  /api/v1/teams/{teamId}/layout  → saves layout; body: {layout, tabOrder}
 *
 * All endpoints require a logged-in user (NoAdminRequired).
 * No team-admin check needed — users may only save their *own* layout.
 */
class LayoutController extends Controller {

    // ----------------------------------------------------------------
    // Default grid layout — 12 columns, 80 px row height.
    //
    // Message stream: cols 0-7 (8 wide), rows 0-4 (5 rows = 400 px visible).
    // Two-column widget layout (cols 8-10 left col, 10-12 right col, each 3 wide):
    //   Team Info    : col 8, row 0,  h 2  — left
    //   Members      : col 9, row 0,  h 2  — right (offset by 3)
    //   Calendar     : col 8, row 2,  h 3  — left
    //   Deck         : col 9, row 2,  h 3  — right (offset by 3)
    //   Activity     : col 8, row 5,  h 3  — left
    //   Pages        : col 9, row 5,  h 3  — right (offset by 3)
    // All widgets are resizable. Collapsed state stored via 'collapsed'+'hSaved'.
    // ----------------------------------------------------------------
    private const DEFAULT_LAYOUT = [
        [
            'i'          => 'msgstream',
            'x'          => 0, 'y' => 0,
            'w'          => 9, 'h' => 9,
            'minW'       => 3, 'minH' => 3,
            'isResizable' => true,
            'collapsed'  => false,
            'hSaved'     => 9,
        ],
        [
            'i'          => 'widget-teaminfo',
            'x'          => 9, 'y' => 0,
            'w'          => 3, 'h' => 2,
            'minW'       => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'  => false,
            'hSaved'     => 2,
        ],
        [
            'i'          => 'widget-members',
            'x'          => 9, 'y' => 2,
            'w'          => 3, 'h' => 2,
            'minW'       => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'  => false,
            'hSaved'     => 2,
        ],
        [
            'i'          => 'widget-calendar',
            'x'          => 9, 'y' => 4,
            'w'          => 3, 'h' => 3,
            'minW'       => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'  => false,
            'hSaved'     => 3,
        ],
        [
            'i'          => 'widget-deck',
            'x'          => 9, 'y' => 7,
            'w'          => 3, 'h' => 3,
            'minW'       => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'  => false,
            'hSaved'     => 3,
        ],
        [
            'i'          => 'widget-activity',
            'x'          => 9, 'y' => 10,
            'w'          => 3, 'h' => 3,
            'minW'       => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'  => false,
            'hSaved'     => 3,
        ],
        [
            'i'          => 'widget-pages',
            'x'          => 9, 'y' => 13,
            'w'          => 3, 'h' => 3,
            'minW'       => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'  => false,
            'hSaved'     => 3,
        ],
    ];

    private const DEFAULT_TAB_ORDER = ['home', 'talk', 'files', 'calendar', 'deck'];

    // Maximum allowed JSON payload size in bytes (64 KB — well above any real layout).
    private const MAX_PAYLOAD_BYTES = 65536;

    // Allowed widget i-values (static). Dynamic integration widget IDs follow
    // the pattern "widget-int-{registryId}" and are validated by prefix below.
    private const ALLOWED_WIDGET_IDS = [
        'msgstream',
        'widget-teaminfo',
        'widget-members',
        'widget-calendar',
        'widget-deck',
        'widget-activity',
        'widget-pages',
    ];

    // Allowed built-in tab keys.
    private const ALLOWED_TAB_KEYS = ['home', 'talk', 'files', 'calendar', 'deck'];

    public function __construct(
        string $appName,
        IRequest $request,
        private LayoutMapper $layoutMapper,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // ----------------------------------------------------------------
    // GET /api/v1/teams/{teamId}/layout
    // ----------------------------------------------------------------

    #[NoAdminRequired]
    public function getLayout(string $teamId): JSONResponse {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // Validate teamId is non-empty alphanumeric/hyphen (Circles UUID format).
        if (!$this->isValidId($teamId)) {
            $this->logger->warning('LayoutController::getLayout — invalid teamId', [
                'teamId' => $teamId,
                'userId' => $userId,
            ]);
            return new JSONResponse(['error' => 'Invalid team ID'], Http::STATUS_BAD_REQUEST);
        }


        $row = $this->layoutMapper->find($userId, $teamId);

        if ($row === null) {
            // No saved layout — return server-side default.
            return new JSONResponse([
                'layout'   => self::DEFAULT_LAYOUT,
                'tabOrder' => self::DEFAULT_TAB_ORDER,
                'isDefault' => true,
            ]);
        }

        $layout   = json_decode($row['layout_json'],    true) ?? self::DEFAULT_LAYOUT;
        $tabOrder = json_decode($row['tab_order_json'], true) ?? self::DEFAULT_TAB_ORDER;

        return new JSONResponse([
            'layout'    => $layout,
            'tabOrder'  => $tabOrder,
            'isDefault' => false,
        ]);
    }

    // ----------------------------------------------------------------
    // PUT /api/v1/teams/{teamId}/layout
    // ----------------------------------------------------------------

    #[NoAdminRequired]
    public function saveLayout(string $teamId): JSONResponse {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if (!$this->isValidId($teamId)) {
            $this->logger->warning('LayoutController::saveLayout — invalid teamId', [
                'teamId' => $teamId, 'userId' => $userId,
            ]);
            return new JSONResponse(['error' => 'Invalid team ID'], Http::STATUS_BAD_REQUEST);
        }

        $params = $this->request->getParams();

        // ── Validate layout ──────────────────────────────────────────
        $layout = $params['layout'] ?? null;
        if (!is_array($layout)) {
            return new JSONResponse(['error' => 'layout must be an array'], Http::STATUS_BAD_REQUEST);
        }

        // Sanitise each grid item — only allow known keys and safe values.
        $cleanLayout = [];
        foreach ($layout as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string)($item['i'] ?? '');
            if (!$this->isAllowedWidgetId($id)) {
                $this->logger->warning('LayoutController::saveLayout — rejected unknown widget id', [
                    'widgetId' => $id, 'userId' => $userId,
                ]);
                continue; // Skip unknown widget IDs silently.
            }
            $cleanLayout[] = [
                'i'           => $id,
                'x'           => max(0, (int)($item['x'] ?? 0)),
                'y'           => max(0, (int)($item['y'] ?? 0)),
                'w'           => max(1, min(12, (int)($item['w'] ?? 4))),
                'h'           => max(1, min(50, (int)($item['h'] ?? 3))),
                'minW'        => (int)($item['minW'] ?? 1),
                'minH'        => (int)($item['minH'] ?? 1),
                'isResizable' => (bool)($item['isResizable'] ?? false),
                // Collapse state — persisted as part of layout_json.
                'collapsed'   => (bool)($item['collapsed'] ?? false),
                'hSaved'      => max(1, min(50, (int)($item['hSaved'] ?? (int)($item['h'] ?? 3)))),
            ];
        }

        // ── Validate tabOrder ────────────────────────────────────────
        $tabOrder = $params['tabOrder'] ?? null;
        if (!is_array($tabOrder)) {
            return new JSONResponse(['error' => 'tabOrder must be an array'], Http::STATUS_BAD_REQUEST);
        }

        $cleanTabOrder = [];
        foreach ($tabOrder as $key) {
            $key = (string)$key;
            // Allow built-in keys, external tab keys (ext-{int}), and link keys (link-{int}).
            if ($this->isAllowedTabKey($key)) {
                $cleanTabOrder[] = $key;
            } else {
                $this->logger->warning('LayoutController::saveLayout — rejected tab key', [
                    'tabKey' => $key, 'userId' => $userId,
                ]);
            }
        }

        $layoutJson    = json_encode($cleanLayout,    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $tabOrderJson  = json_encode($cleanTabOrder,  JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        // Guard against unexpectedly large payloads.
        if (strlen($layoutJson) + strlen($tabOrderJson) > self::MAX_PAYLOAD_BYTES) {
            $this->logger->warning('LayoutController::saveLayout — payload too large', [
                'userId' => $userId, 'teamId' => $teamId,
            ]);
            return new JSONResponse(['error' => 'Payload too large'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $this->layoutMapper->upsert($userId, $teamId, $layoutJson, $tabOrderJson);
        } catch (\Throwable $e) {
            $this->logger->error('LayoutController::saveLayout — DB error: ' . $e->getMessage(), [
                'userId' => $userId, 'teamId' => $teamId,
            ]);
            return new JSONResponse(['error' => 'Failed to save layout'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(['status' => 'ok']);
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function currentUserId(): ?string {
        return $this->userSession->getUser()?->getUID();
    }

    /** Circles UUIDs: hex chars and hyphens only. */
    private function isValidId(string $id): bool {
        return $id !== '' && preg_match('/^[a-zA-Z0-9\-]+$/', $id) === 1;
    }

    /**
     * Allow static widget IDs and dynamic integration widget IDs.
     * Dynamic IDs follow the pattern "widget-int-{registryId}" where
     * registryId is a positive integer.
     */
    private function isAllowedWidgetId(string $id): bool {
        if (in_array($id, self::ALLOWED_WIDGET_IDS, true)) {
            return true;
        }
        // Integration widgets: widget-int-{integer}
        if (preg_match('/^widget-int-\d+$/', $id) === 1) {
            return true;
        }
        return false;
    }

    /**
     * Allow built-in tab keys, external tab keys (ext-{integer}),
     * and web link tab keys (link-{integer}).
     */
    private function isAllowedTabKey(string $key): bool {
        if (in_array($key, self::ALLOWED_TAB_KEYS, true)) {
            return true;
        }
        if (preg_match('/^ext-\d+$/', $key) === 1) {
            return true;
        }
        if (preg_match('/^link-\d+$/', $key) === 1) {
            return true;
        }
        return false;
    }
}
