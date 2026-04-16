<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Db\LayoutMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * LayoutController — per-user, per-team Home-view grid layout + tab order.
 *
 * GET  /api/v1/teams/{teamId}/layout  → returns saved layout or cascaded default
 * PUT  /api/v1/teams/{teamId}/layout  → saves layout; body: {layout, tabOrder}
 * GET  /api/v1/layout/default         → returns user's personal default layout
 * PUT  /api/v1/layout/default         → saves user's personal default layout
 *
 * Layout cascade (GET team layout):
 *   1. Team-specific row in teamhub_widget_layouts
 *   2. User's personal default (stored in oc_preferences via IConfig)
 *   3. System DEFAULT_LAYOUT constant
 *
 * All endpoints require a logged-in user (NoAdminRequired).
 * User default is stored in oc_preferences — no migration needed.
 */
class LayoutController extends Controller {

    // ----------------------------------------------------------------
    // Default grid layout — 12 columns, 80 px row height.
    // ----------------------------------------------------------------
    private const DEFAULT_LAYOUT = [
        [
            'i'           => 'msgstream',
            'x'           => 0, 'y' => 0,
            'w'           => 9, 'h' => 9,
            'minW'        => 3, 'minH' => 3,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 9,
        ],
        [
            'i'           => 'widget-teaminfo',
            'x'           => 9, 'y' => 0,
            'w'           => 3, 'h' => 2,
            'minW'        => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 2,
        ],
        [
            'i'           => 'widget-members',
            'x'           => 9, 'y' => 2,
            'w'           => 3, 'h' => 2,
            'minW'        => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 2,
        ],
        [
            'i'           => 'widget-calendar',
            'x'           => 9, 'y' => 4,
            'w'           => 3, 'h' => 3,
            'minW'        => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 3,
        ],
        [
            'i'           => 'widget-deck',
            'x'           => 9, 'y' => 7,
            'w'           => 3, 'h' => 3,
            'minW'        => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 3,
        ],
        [
            'i'           => 'widget-activity',
            'x'           => 9, 'y' => 10,
            'w'           => 3, 'h' => 3,
            'minW'        => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 3,
        ],
        [
            'i'           => 'widget-pages',
            'x'           => 9, 'y' => 13,
            'w'           => 3, 'h' => 3,
            'minW'        => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 3,
        ],
    ];

    private const DEFAULT_TAB_ORDER = ['home', 'talk', 'files', 'calendar', 'deck'];

    // Maximum allowed JSON payload size in bytes (64 KB).
    private const MAX_PAYLOAD_BYTES = 65536;

    // IConfig preference keys for user default layout.
    private const PREF_DEFAULT_LAYOUT    = 'default_layout_json';
    private const PREF_DEFAULT_TAB_ORDER = 'default_tab_order_json';

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
        private IDBConnection $db,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // ----------------------------------------------------------------
    // GET /api/v1/teams/{teamId}/layout
    //
    // Cascades: team-specific → user personal default → system default.
    // Always returns userDefault so the client can show/hide the layout
    // default buttons without a second request.
    // ----------------------------------------------------------------

    #[NoAdminRequired]
    public function getLayout(string $teamId): JSONResponse {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if (!$this->isValidId($teamId)) {
            $this->logger->warning('[TeamHub][LayoutController] getLayout — invalid teamId', [
                'teamId' => $teamId, 'userId' => $userId,
            ]);
            return new JSONResponse(['error' => 'Invalid team ID'], Http::STATUS_BAD_REQUEST);
        }

        if ($this->getMemberLevel($teamId, $userId) === 0) {
            $this->logger->warning('[TeamHub][LayoutController] getLayout — non-member access attempt', [
                'teamId' => $teamId, 'userId' => $userId,
            ]);
            return new JSONResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }

        // Resolve user default once — used for cascade and for client comparison.
        $userDefault = $this->resolveUserDefault($userId);

        $row = $this->layoutMapper->find($userId, $teamId);

        if ($row === null) {
            // No team-specific row: cascade to user default (or system default).
            $this->logger->debug('[TeamHub][LayoutController] getLayout — no team layout, cascading to default', [
                'teamId' => $teamId, 'userId' => $userId,
                'isSystemDefault' => $userDefault['isSystemDefault'],
            ]);
            return new JSONResponse([
                'layout'      => $userDefault['layout'],
                'tabOrder'    => $userDefault['tabOrder'],
                'isDefault'   => true,
                'userDefault' => $userDefault['layout'],
            ]);
        }

        $layout   = json_decode($row['layout_json'],    true) ?? self::DEFAULT_LAYOUT;
        $tabOrder = json_decode($row['tab_order_json'], true) ?? self::DEFAULT_TAB_ORDER;

        $this->logger->debug('[TeamHub][LayoutController] getLayout — found team layout', [
            'teamId' => $teamId, 'userId' => $userId, 'items' => count($layout),
        ]);

        return new JSONResponse([
            'layout'      => $layout,
            'tabOrder'    => $tabOrder,
            'isDefault'   => false,
            'userDefault' => $userDefault['layout'],
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
            $this->logger->warning('[TeamHub][LayoutController] saveLayout — invalid teamId', [
                'teamId' => $teamId, 'userId' => $userId,
            ]);
            return new JSONResponse(['error' => 'Invalid team ID'], Http::STATUS_BAD_REQUEST);
        }

        if ($this->getMemberLevel($teamId, $userId) === 0) {
            $this->logger->warning('[TeamHub][LayoutController] saveLayout — non-member access attempt', [
                'teamId' => $teamId, 'userId' => $userId,
            ]);
            return new JSONResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }

        $params = $this->request->getParams();
        [$cleanLayout, $cleanTabOrder, $error] = $this->validateAndClean($params, $userId, 'saveLayout');
        if ($error !== null) {
            return $error;
        }

        $layoutJson   = json_encode($cleanLayout,   JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $tabOrderJson = json_encode($cleanTabOrder, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (strlen($layoutJson) + strlen($tabOrderJson) > self::MAX_PAYLOAD_BYTES) {
            $this->logger->warning('[TeamHub][LayoutController] saveLayout — payload too large', [
                'userId' => $userId, 'teamId' => $teamId,
            ]);
            return new JSONResponse(['error' => 'Payload too large'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $this->layoutMapper->upsert($userId, $teamId, $layoutJson, $tabOrderJson);
            $this->logger->debug('[TeamHub][LayoutController] saveLayout — saved', [
                'teamId' => $teamId, 'userId' => $userId, 'items' => count($cleanLayout),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][LayoutController] saveLayout — DB error: ' . $e->getMessage(), [
                'userId' => $userId, 'teamId' => $teamId,
            ]);
            return new JSONResponse(['error' => 'Failed to save layout'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(['status' => 'ok']);
    }

    // ----------------------------------------------------------------
    // GET /api/v1/layout/default
    // ----------------------------------------------------------------

    #[NoAdminRequired]
    public function getDefaultLayout(): JSONResponse {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $userDefault = $this->resolveUserDefault($userId);

        $this->logger->debug('[TeamHub][LayoutController] getDefaultLayout — fetched', [
            'userId' => $userId, 'isSystemDefault' => $userDefault['isSystemDefault'],
            'items'  => count($userDefault['layout']),
        ]);

        return new JSONResponse([
            'layout'          => $userDefault['layout'],
            'tabOrder'        => $userDefault['tabOrder'],
            'isSystemDefault' => $userDefault['isSystemDefault'],
        ]);
    }

    // ----------------------------------------------------------------
    // PUT /api/v1/layout/default
    //
    // Saves the current layout as the user's personal default.
    // Stored in oc_preferences via IConfig — no DB migration required.
    // ----------------------------------------------------------------

    #[NoAdminRequired]
    public function saveDefaultLayout(): JSONResponse {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $params = $this->request->getParams();
        [$cleanLayout, $cleanTabOrder, $error] = $this->validateAndClean($params, $userId, 'saveDefaultLayout');
        if ($error !== null) {
            return $error;
        }

        try {
            $layoutJson   = json_encode($cleanLayout,   JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $tabOrderJson = json_encode($cleanTabOrder, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new JSONResponse(['error' => 'Invalid layout data'], Http::STATUS_BAD_REQUEST);
        }

        if (strlen($layoutJson) + strlen($tabOrderJson) > self::MAX_PAYLOAD_BYTES) {
            $this->logger->warning('[TeamHub][LayoutController] saveDefaultLayout — payload too large', [
                'userId' => $userId,
            ]);
            return new JSONResponse(['error' => 'Payload too large'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
        }

        $this->config->setUserValue($userId, 'teamhub', self::PREF_DEFAULT_LAYOUT,    $layoutJson);
        $this->config->setUserValue($userId, 'teamhub', self::PREF_DEFAULT_TAB_ORDER, $tabOrderJson);

        $this->logger->debug('[TeamHub][LayoutController] saveDefaultLayout — saved user default', [
            'userId' => $userId, 'items' => count($cleanLayout),
        ]);

        return new JSONResponse(['status' => 'ok']);
    }

    // ----------------------------------------------------------------
    // Private helpers
    // ----------------------------------------------------------------

    /**
     * Resolve the user's personal default from IConfig.
     * Falls back to system DEFAULT_LAYOUT if nothing saved yet.
     *
     * @return array{layout: array, tabOrder: array, isSystemDefault: bool}
     */
    private function resolveUserDefault(string $userId): array {
        $layoutJson   = $this->config->getUserValue($userId, 'teamhub', self::PREF_DEFAULT_LAYOUT,    '');
        $tabOrderJson = $this->config->getUserValue($userId, 'teamhub', self::PREF_DEFAULT_TAB_ORDER, '');

        if ($layoutJson === '') {
            return [
                'layout'          => self::DEFAULT_LAYOUT,
                'tabOrder'        => self::DEFAULT_TAB_ORDER,
                'isSystemDefault' => true,
            ];
        }

        return [
            'layout'          => json_decode($layoutJson,   true) ?? self::DEFAULT_LAYOUT,
            'tabOrder'        => json_decode($tabOrderJson, true) ?? self::DEFAULT_TAB_ORDER,
            'isSystemDefault' => false,
        ];
    }

    /**
     * Shared validation and sanitisation for layout + tabOrder payloads.
     * Returns [$cleanLayout, $cleanTabOrder, $errorResponse].
     * $errorResponse is non-null on validation failure.
     */
    private function validateAndClean(array $params, string $userId, string $context): array {
        $layout = $params['layout'] ?? null;
        if (!is_array($layout)) {
            return [[], [], new JSONResponse(['error' => 'layout must be an array'], Http::STATUS_BAD_REQUEST)];
        }

        $cleanLayout = [];
        foreach ($layout as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string)($item['i'] ?? '');
            if (!$this->isAllowedWidgetId($id)) {
                $this->logger->debug('[TeamHub][LayoutController] ' . $context . ' — skipping unknown widget id', [
                    'widgetId' => $id, 'userId' => $userId,
                ]);
                continue;
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
                'collapsed'   => (bool)($item['collapsed'] ?? false),
                'hSaved'      => max(1, min(50, (int)($item['hSaved'] ?? (int)($item['h'] ?? 3)))),
            ];
        }

        $tabOrder = $params['tabOrder'] ?? null;
        if (!is_array($tabOrder)) {
            return [[], [], new JSONResponse(['error' => 'tabOrder must be an array'], Http::STATUS_BAD_REQUEST)];
        }

        $cleanTabOrder = [];
        foreach ($tabOrder as $key) {
            $key = (string)$key;
            if ($this->isAllowedTabKey($key)) {
                $cleanTabOrder[] = $key;
            } else {
                $this->logger->debug('[TeamHub][LayoutController] ' . $context . ' — rejected tab key', [
                    'tabKey' => $key, 'userId' => $userId,
                ]);
            }
        }

        return [$cleanLayout, $cleanTabOrder, null];
    }

    private function currentUserId(): ?string {
        return $this->userSession->getUser()?->getUID();
    }

    private function isValidId(string $id): bool {
        return $id !== '' && preg_match('/^[a-zA-Z0-9\-]+$/', $id) === 1;
    }

    private function isAllowedWidgetId(string $id): bool {
        if (in_array($id, self::ALLOWED_WIDGET_IDS, true)) {
            return true;
        }
        if (preg_match('/^widget-int-\d+$/', $id) === 1) {
            return true;
        }
        return false;
    }

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

    private function getMemberLevel(string $teamId, string $userId): int {
        $qb     = $this->db->getQueryBuilder();
        $result = $qb->select('level')
            ->from('circles_member')
            ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('user_id',   $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('status',    $qb->createNamedParameter('Member')))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ? (int)$row['level'] : 0;
    }
}
