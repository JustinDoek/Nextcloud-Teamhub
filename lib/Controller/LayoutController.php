<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\LayoutMapper;
use OCA\TeamHub\Service\BudgetService;
use OCA\TeamHub\Service\CollectivesService;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\DecisionTeamService;
use OCA\TeamHub\Service\PresenceTeamService;
use OCA\TeamHub\Service\ProjectService;
use OCA\TeamHub\Service\TeamTypeService;
use OCA\TeamHub\Service\TimeService;
use OCA\TeamHub\Service\TimelineService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
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
            'autoFit'     => true,
        ],
        [
            'i'           => 'widget-teaminfo',
            'x'           => 9, 'y' => 0,
            'w'           => 3, 'h' => 2,
            'minW'        => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 2,
            'autoFit'     => true,
        ],
        [
            'i'           => 'widget-members',
            'x'           => 9, 'y' => 6,
            'w'           => 3, 'h' => 2,
            'minW'        => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 2,
            'autoFit'     => true,
        ],
        [
            'i'           => 'widget-calendar',
            'x'           => 9, 'y' => 8,
            'w'           => 3, 'h' => 3,
            'minW'        => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 3,
            'autoFit'     => true,
        ],
        [
            'i'           => 'widget-deck',
            'x'           => 9, 'y' => 11,
            'w'           => 3, 'h' => 3,
            'minW'        => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 3,
            'autoFit'     => true,
        ],
        [
            'i'           => 'widget-activity',
            'x'           => 9, 'y' => 14,
            'w'           => 3, 'h' => 3,
            'minW'        => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 3,
            'autoFit'     => true,
        ],
        [
            'i'           => 'widget-pages',
            'x'           => 9, 'y' => 17,
            'w'           => 3, 'h' => 3,
            'minW'        => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 3,
            'autoFit'     => true,
        ],
        [
            'i'           => 'widget-files-center',
            // v4.3.7 — restored to y=20 after removing the standalone
            // widget-collectives (Wiki now renders inside widget-pages).
            'x'           => 9, 'y' => 20,
            'w'           => 3, 'h' => 4,
            'minW'        => 2, 'minH' => 2,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 4,
            'autoFit'     => true,
        ],
        [
            'i'           => 'widget-decisions',
            // v4.3.7 — restored to y=24 (see widget-files-center note).
            'x'           => 9, 'y' => 24,
            'w'           => 3, 'h' => 4,
            'minW'        => 2, 'minH' => 2,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 4,
            'autoFit'     => true,
        ],
        // v3.97.0 — Project Health widget. Only rendered for Advanced-project
        // members in Planning or Execution phase who can see both Budget and
        // Time (gated in TeamWidgetGrid). Positioned near the top of the
        // right column — this is a high-signal cockpit view an admin wants
        // above the fold. For existing users whose saved layout predates
        // this widget, mergeNewWidgets inserts it at y=2 and shifts every
        // other x=9 widget down by 4 (see the widget-project-health branch
        // there); for new users, this DEFAULT_LAYOUT ordering places it
        // naturally.
        [
            'i'           => 'widget-project-health',
            'x'           => 9, 'y' => 2,
            'w'           => 3, 'h' => 4,
            'minW'        => 2, 'minH' => 2,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 4,
            'autoFit'     => true,
        ],
    ];

    private const DEFAULT_TAB_ORDER = ['home', 'talk', 'files', 'calendar', 'deck', 'collectives', 'timeline'];

    // Maximum allowed JSON payload size in bytes (64 KB).
    private const MAX_PAYLOAD_BYTES = 65536;

    // v4.6.1 — any saved y at or above this is a leftover parking position from
    // the removed TeamView.applySnap(), which parked inactive widgets at
    // y=9999. Kept in sync with PARK_Y_THRESHOLD in TeamWidgetGrid.vue.
    private const PARK_Y_THRESHOLD = 1000;

    // IConfig preference keys for user default layout.
    private const PREF_DEFAULT_LAYOUT    = 'default_layout_json';
    private const PREF_DEFAULT_TAB_ORDER = 'default_tab_order_json';

    // Allowed widget i-values (static). Dynamic integration widget IDs follow
    // the pattern "widget-int-{registryId}" and are validated by prefix below.
    // Legacy IDs (widget-files-favorites, widget-files-recent, widget-files-shared)
    // are accepted on save (backward compat for any in-flight saves) but stripped
    // from saved layouts by mergeNewWidgets / pruneLegacyWidgets on every GET.
    private const ALLOWED_WIDGET_IDS = [
        'msgstream',
        'widget-teaminfo',
        'widget-members',
        'widget-calendar',
        'widget-deck',
        'widget-activity',
        'widget-pages',
        'widget-files-center',
        'widget-decisions',
        'widget-project-health',
        // Legacy — kept so saves from old clients are not rejected mid-migration.
        'widget-files-favorites',
        'widget-files-recent',
        'widget-files-shared',
    ];

    // Allowed built-in tab keys.
    private const ALLOWED_TAB_KEYS = ['home', 'talk', 'files', 'calendar', 'deck', 'collectives', 'presence', 'decisions', 'timeline'];

    public function __construct(
        string $appName,
        IRequest $request,
        private LayoutMapper $layoutMapper,
        private IUserSession $userSession,
        private IDBConnection $db,
        private IConfig $config,
        private LoggerInterface $logger,
        private MemberService $memberService,
        private PresenceTeamService $presenceTeamService,
        private DecisionTeamService $decisionTeamService,
        private TimelineService $timelineService,
        private ProjectService $projectService,
        private BudgetService $budgetService,
        private TimeService $timeService,
        private TeamTypeService $teamTypeService,
        private CollectivesService $collectivesService,
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

        if (!$this->isMember($teamId, $userId)) {
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
            // Merge any new system-default widgets the user's default may not have yet.
            $mergedDefault = $this->mergeNewWidgets($userDefault['layout']);
            $mergedTabOrder = $this->mergeNewTabs($userDefault['tabOrder']);
            $this->logger->debug('[TeamHub][LayoutController] getLayout — no team layout, cascading to default', [
                'teamId' => $teamId, 'userId' => $userId,
                'isSystemDefault' => $userDefault['isSystemDefault'],
                'itemsBefore' => count($userDefault['layout']),
                'itemsAfter'  => count($mergedDefault),
            ]);
        return new JSONResponse([
            'layout'                 => $mergedDefault,
            'tabOrder'               => $mergedTabOrder,
            'isDefault'              => true,
            'userDefault'            => $mergedDefault,
            'presenceConfig'         => $this->presenceTeamService->getConfig($teamId),
            'presenceModuleEnabled'  => $this->isPresenceModuleEnabled(),
            'decisionsConfig'        => $this->decisionTeamService->getConfig($teamId),
            'decisionsModuleEnabled' => $this->isDecisionsModuleEnabled(),
            'timelineConfig'         => [
                'timeline_enabled' => $this->config->getAppValue(Application::APP_ID, 'timeline_enabled_' . $teamId, '1') === '1',
                // NC 34 / Deck 1.18+ only — gates the "Deck card dependencies"
                // connector toggle on the Timeline tab (v3.78.8). Detected via
                // DbIntrospectionService; absent on older Deck installs.
                'card_dependencies_supported' => $this->timelineService->isCardDependencySupported(),
            ],
            'messagesConfig'         => [
                'messages_enabled' => $this->config->getAppValue(Application::APP_ID, 'messages_enabled_' . $teamId, '1') === '1',
            ],
            // v4.3.3 — Collectives team-app. Default OFF (teams opt in), same
            // shape as messagesConfig/timelineConfig so the frontend can gate
            // the Wiki widget + tab without a second fetch. `installed` lets
            // the Manage Team toggle show "Not installed" instead of erroring
            // when the Collectives NC app isn't present.
            'collectivesConfig'      => [
                'collectives_enabled'   => $this->collectivesService->isEnabledForTeam($teamId),
                'collectives_installed' => $this->collectivesService->isInstalled(),
            ],
            // Team-wide dashboard customization: owner/admin-hidden widgets +
            // the default tab opened on team entry. Read by every member;
            // written only by admins (TeamController::saveDashboardConfig).
            'dashboardConfig'        => $this->dashboardConfigFor($teamId),
            // v4.0.2 — team template label ('collaboration'|'project'|'department'|null).
            // Legacy teams (pre-4.0.2) have no row and land at null so the
            // Team info widget/BrowseTeamsView hide the badge.
            'teamType'               => $this->teamTypeService->getType($teamId),
            'budgetConfig'           => [
                'budget_enabled'   => $this->config->getAppValue(Application::APP_ID, 'budget_enabled_' . $teamId, '1') === '1',
                // v3.94.0 — tab visibility uses a project-level role floor
                // (project.budget_view_min_level) with an editor-override
                // exception. Precomputed here so the frontend can gate the
                // tab without a second fetch.
                'can_view_budget'  => $this->budgetService->canUserViewBudgetTab($teamId, $userId),
            ],
            'timeConfig'             => [
                'time_enabled'   => $this->config->getAppValue(Application::APP_ID, 'time_enabled_' . $teamId, '1') === '1',
                // v3.96.0 — same precomputed-can-view pattern as budgetConfig.
                // Tab visibility: level ≥ project.time_view_min_level OR user
                // is a named project participant (teamhub_project_member row).
                'can_view_time'  => $this->timeService->canUserViewTimeTab($teamId, $userId),
            ],
            'project'                => $this->projectFacts($teamId),
        ]);
        }

        $layout   = json_decode($row['layout_json'],    true) ?? self::DEFAULT_LAYOUT;
        $tabOrder = json_decode($row['tab_order_json'], true) ?? self::DEFAULT_TAB_ORDER;

        // Merge any new system-default widgets/tabs that are missing from the saved
        // layout/tabOrder. This ensures users with existing saved layouts get new
        // widgets and tabs automatically — see mergeNewWidgets()/mergeNewTabs() docblocks.
        $layout   = $this->mergeNewWidgets($layout);
        $tabOrder = $this->mergeNewTabs($tabOrder);

        $this->logger->debug('[TeamHub][LayoutController] getLayout — found team layout', [
            'teamId' => $teamId, 'userId' => $userId, 'items' => count($layout),
        ]);

        return new JSONResponse([
            'layout'                 => $layout,
            'tabOrder'               => $tabOrder,
            'isDefault'              => false,
            'userDefault'            => $userDefault['layout'],
            'presenceConfig'         => $this->presenceTeamService->getConfig($teamId),
            'presenceModuleEnabled'  => $this->isPresenceModuleEnabled(),
            'decisionsConfig'        => $this->decisionTeamService->getConfig($teamId),
            'decisionsModuleEnabled' => $this->isDecisionsModuleEnabled(),
            'timelineConfig'         => [
                'timeline_enabled' => $this->config->getAppValue(Application::APP_ID, 'timeline_enabled_' . $teamId, '1') === '1',
                // NC 34 / Deck 1.18+ only — gates the "Deck card dependencies"
                // connector toggle on the Timeline tab (v3.78.8). Detected via
                // DbIntrospectionService; absent on older Deck installs.
                'card_dependencies_supported' => $this->timelineService->isCardDependencySupported(),
            ],
            'messagesConfig'         => [
                'messages_enabled' => $this->config->getAppValue(Application::APP_ID, 'messages_enabled_' . $teamId, '1') === '1',
            ],
            // v4.3.3 — Collectives team-app. Default OFF (teams opt in), same
            // shape as messagesConfig/timelineConfig so the frontend can gate
            // the Wiki widget + tab without a second fetch. `installed` lets
            // the Manage Team toggle show "Not installed" instead of erroring
            // when the Collectives NC app isn't present.
            'collectivesConfig'      => [
                'collectives_enabled'   => $this->collectivesService->isEnabledForTeam($teamId),
                'collectives_installed' => $this->collectivesService->isInstalled(),
            ],
            // Team-wide dashboard customization: owner/admin-hidden widgets +
            // the default tab opened on team entry. Read by every member;
            // written only by admins (TeamController::saveDashboardConfig).
            'dashboardConfig'        => $this->dashboardConfigFor($teamId),
            // v4.0.2 — team template label ('collaboration'|'project'|'department'|null).
            // Legacy teams (pre-4.0.2) have no row and land at null so the
            // Team info widget/BrowseTeamsView hide the badge.
            'teamType'               => $this->teamTypeService->getType($teamId),
            'budgetConfig'           => [
                'budget_enabled'   => $this->config->getAppValue(Application::APP_ID, 'budget_enabled_' . $teamId, '1') === '1',
                // v3.94.0 — tab visibility uses a project-level role floor
                // (project.budget_view_min_level) with an editor-override
                // exception. Precomputed here so the frontend can gate the
                // tab without a second fetch.
                'can_view_budget'  => $this->budgetService->canUserViewBudgetTab($teamId, $userId),
            ],
            'timeConfig'             => [
                'time_enabled'   => $this->config->getAppValue(Application::APP_ID, 'time_enabled_' . $teamId, '1') === '1',
                // v3.96.0 — same precomputed-can-view pattern as budgetConfig.
                // Tab visibility: level ≥ project.time_view_min_level OR user
                // is a named project participant (teamhub_project_member row).
                'can_view_time'  => $this->timeService->canUserViewTimeTab($teamId, $userId),
            ],
            'project'                => $this->projectFacts($teamId),
        ]);
    }

    /**
     * Project facts for the layout bundle. Membership is already verified above,
     * so this is best-effort: any failure degrades to "not a project" rather than
     * breaking the whole layout response (mirrors the other config here).
     *
     * @return array{isProject:bool, mode:?string, phase:?string, startDate:?int, targetEnd:?int}
     */
    private function projectFacts(string $teamId): array {
        try {
            return $this->projectService->getForTeam($teamId);
        } catch (\Throwable $e) {
            return ['isProject' => false, 'mode' => null, 'phase' => null, 'startDate' => null, 'targetEnd' => null];
        }
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

        if (!$this->isMember($teamId, $userId)) {
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

        // Same merge as getLayout() — a saved personal default predates new
        // widgets/tabs just as easily as a team-specific layout does.
        $mergedLayout   = $this->mergeNewWidgets($userDefault['layout']);
        $mergedTabOrder = $this->mergeNewTabs($userDefault['tabOrder']);

        $this->logger->debug('[TeamHub][LayoutController] getDefaultLayout — fetched', [
            'userId' => $userId, 'isSystemDefault' => $userDefault['isSystemDefault'],
            'items'  => count($mergedLayout),
        ]);

        return new JSONResponse([
            'layout'          => $mergedLayout,
            'tabOrder'        => $mergedTabOrder,
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
            $cleanItem = [
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
            // v4.0.8 — only preserve autoFit when the frontend explicitly sends
            // true. Once the frontend runs its measurement pass it strips the
            // flag from the save payload, so subsequent loads no longer trigger
            // a fit. Persisting the flag is important though: if the user opens
            // and closes the team without a resize (e.g. mobile view where the
            // pass doesn't run), the flag survives for a later desktop visit.
            if (isset($item['autoFit']) && $item['autoFit'] === true) {
                $cleanItem['autoFit'] = true;
            }
            $cleanLayout[] = $cleanItem;
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

    /**
     * Team-wide dashboard config emitted in the layout bundle. Kept in sync
     * with TeamController::readDashboardConfig (same app-config keys/defaults).
     *
     * @return array{hidden_widgets: string[], default_tab: string}
     */
    private function dashboardConfigFor(string $teamId): array {
        $hidden = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'dashboard_hidden_' . $teamId, '[]'),
            true
        );
        if (!is_array($hidden)) {
            $hidden = [];
        }
        return [
            'hidden_widgets' => array_values(array_map('strval', $hidden)),
            'default_tab'    => $this->config->getAppValue(Application::APP_ID, 'dashboard_tab_' . $teamId, 'msgstream'),
        ];
    }

    /**
     * Merge any system DEFAULT_LAYOUT widgets that are missing from $layout.
     *
     * Called on every GET so users who saved their layout before a new widget
     * was introduced automatically receive it appended at the bottom of the
     * right-hand column without losing any of their existing positions.
     *
     * New widgets are stacked below the lowest existing item in the same
     * x-column as specified in DEFAULT_LAYOUT, so they do not overlap anything.
     */
    private function mergeNewWidgets(array $layout): array {
        // v4.6.1 — heal parked rows before anything else reads a y coordinate.
        // Every placement calculation below (and in healProjectHealthPosition)
        // takes the max bottom edge of a column; a single item left at y=9999
        // by the old applySnap() would put the next new widget at y≈10002.
        $layout = $this->unparkWidgets($layout);

        // Prune legacy widget IDs that have been consolidated into new ones.
        // widget-files-{favorites,recent,shared} → widget-files-center (v3.62).
        // widget-collectives → widget-pages (v4.3.7 — Wiki content now renders
        // inside the unified Pages widget instead of a standalone card).
        // Filter them out before merging so the new consolidated widget is added.
        $legacyIds = ['widget-files-favorites', 'widget-files-recent', 'widget-files-shared', 'widget-collectives'];
        $pruned = false;
        $layout = array_values(array_filter($layout, static function (array $item) use ($legacyIds, &$pruned): bool {
            if (in_array($item['i'], $legacyIds, true)) {
                $pruned = true;
                return false;
            }
            return true;
        }));
        if ($pruned) {
            $this->logger->debug('[TeamHub][LayoutController] mergeNewWidgets — pruned legacy files widgets, will add widget-files-center');
        }

        // Build a set of widget IDs already present.
        $existing = [];
        foreach ($layout as $item) {
            $existing[$item['i']] = true;
        }

        $added = false;
        foreach (self::DEFAULT_LAYOUT as $defaultItem) {
            if (isset($existing[$defaultItem['i']])) {
                continue; // Already in the layout — skip.
            }

            // v3.97.0 — widget-project-health is a top-of-column cockpit widget,
            // not a bottom-of-stack append. Insert it at its DEFAULT_LAYOUT y
            // (2, right below widget-teaminfo) and shift every other x=9
            // widget with y >= 2 down by this widget's height. One-time
            // reflow the first time an existing user's layout receives the
            // widget; matches the visual position new users get from
            // DEFAULT_LAYOUT verbatim.
            if ($defaultItem['i'] === 'widget-project-health') {
                $targetY = (int)$defaultItem['y'];
                $shift   = (int)$defaultItem['h'];
                foreach ($layout as &$item) {
                    if ((int)$item['x'] === (int)$defaultItem['x']
                        && (int)$item['y'] >= $targetY) {
                        $item['y'] = (int)$item['y'] + $shift;
                    }
                }
                unset($item);

                $layout[] = $defaultItem;
                $added    = true;

                $this->logger->debug('[TeamHub][LayoutController] mergeNewWidgets — inserted widget-project-health at top of right column', [
                    'atY' => $targetY, 'shiftedBy' => $shift,
                ]);
                continue;
            }

            // Find the lowest y + h in the same x-column so we don't overlap.
            $targetX = $defaultItem['x'];
            $maxBottom = 0;
            foreach ($layout as $item) {
                if ((int)$item['x'] === $targetX) {
                    $bottom = (int)$item['y'] + (int)$item['h'];
                    if ($bottom > $maxBottom) {
                        $maxBottom = $bottom;
                    }
                }
            }

            $newItem = $defaultItem;
            $newItem['y'] = $maxBottom;

            $this->logger->debug('[TeamHub][LayoutController] mergeNewWidgets — adding missing widget', [
                'widgetId' => $defaultItem['i'], 'atY' => $maxBottom,
            ]);

            $layout[] = $newItem;
            $added    = true;
        }

        if ($added) {
            $this->logger->debug('[TeamHub][LayoutController] mergeNewWidgets — layout updated with new widgets', [
                'totalItems' => count($layout),
            ]);
        }

        // v3.97.1 healing pass — the 3.97.0 mergeNewWidgets appended
        // widget-project-health at the max-bottom of column x=9, which
        // could easily be y=24 (below files/decisions). If we still find it
        // there (i.e. its y equals the highest y+h of any OTHER x=9 widget),
        // snap it up to y=2 and shift the rest down. Idempotent: after the
        // first fetch snaps it up, the widget's y is no longer the max in
        // its column and subsequent fetches skip the reflow. Users who
        // intentionally moved the widget somewhere non-bottom keep their
        // placement.
        $layout = $this->healProjectHealthPosition($layout);

        return $layout;
    }

    /**
     * v4.6.1 — pull parked widgets back into the grid.
     *
     * Until 4.6.0, TeamView.applySnap() parked every inactive widget at
     * y=9999 so it would not occupy space, and that value was persisted. The
     * snap is gone (grid-layout-plus compacts on its own now), but saved rows
     * still carry the sentinel, and it poisons every "bottom of this column"
     * calculation that decides where a newly-added widget goes.
     *
     * Each parked item is stacked at the bottom of its own x-column, below the
     * real content. The grid compacts it upward on render, so the exact value
     * only has to be sane, not perfect. Idempotent: once healed, no item is at
     * or above the threshold and this is a no-op.
     */
    private function unparkWidgets(array $layout): array {
        // Bottom edge per x-column, counting only unparked items.
        $columnBottom = [];
        foreach ($layout as $item) {
            $y = (int)($item['y'] ?? 0);
            if ($y >= self::PARK_Y_THRESHOLD) {
                continue;
            }
            $x      = (int)($item['x'] ?? 0);
            $bottom = $y + (int)($item['h'] ?? 3);
            if (!isset($columnBottom[$x]) || $bottom > $columnBottom[$x]) {
                $columnBottom[$x] = $bottom;
            }
        }

        $unparked = 0;
        foreach ($layout as &$item) {
            if ((int)($item['y'] ?? 0) < self::PARK_Y_THRESHOLD) {
                continue;
            }
            $x = (int)($item['x'] ?? 0);
            // Stack rather than pile: two parked widgets in one column must not
            // land on the same y.
            $item['y']        = $columnBottom[$x] ?? 0;
            $columnBottom[$x] = (int)$item['y'] + (int)($item['h'] ?? 3);
            $unparked++;
        }
        unset($item);

        if ($unparked > 0) {
            $this->logger->debug('[TeamHub][LayoutController] unparkWidgets — healed parked widgets', [
                'count' => $unparked,
            ]);
        }

        return $layout;
    }

    /**
     * One-shot heal: if widget-project-health is saved at the very bottom of
     * column x=9 (auto-appended by the pre-3.97.1 mergeNewWidgets), lift it
     * to y=2 and shift every other x=9 widget down by its height.
     *
     * Skips silently when:
     *   - widget-project-health is not in the layout at all (no-op)
     *   - widget-project-health is not at the bottom of column x=9 (user has
     *     moved it, respect their placement)
     */
    private function healProjectHealthPosition(array $layout): array {
        $targetIdx = null;
        foreach ($layout as $idx => $item) {
            if (($item['i'] ?? '') === 'widget-project-health') {
                $targetIdx = $idx;
                break;
            }
        }
        if ($targetIdx === null) {
            return $layout;
        }

        $target  = $layout[$targetIdx];
        $targetX = (int)($target['x'] ?? 0);
        $targetY = (int)($target['y'] ?? 0);
        $targetH = (int)($target['h'] ?? 0);

        if ($targetX !== 9) {
            // User moved it out of the right column entirely — respect that.
            return $layout;
        }

        // Compute the max bottom edge of every OTHER widget in column x=9.
        $maxOtherBottom = 0;
        foreach ($layout as $idx => $item) {
            if ($idx === $targetIdx) continue;
            if ((int)($item['x'] ?? -1) !== 9) continue;
            $bottom = (int)($item['y'] ?? 0) + (int)($item['h'] ?? 0);
            if ($bottom > $maxOtherBottom) {
                $maxOtherBottom = $bottom;
            }
        }

        // Only heal when the widget is sitting at (or below) that max
        // bottom — i.e. was auto-appended by mergeNewWidgets. If its top
        // edge is above that line, it's already been repositioned or the
        // user moved it — leave it alone.
        if ($targetY < $maxOtherBottom) {
            return $layout;
        }

        // Snap to y=2 and shift every other x=9 widget with y >= 2 down.
        foreach ($layout as $idx => &$item) {
            if ($idx === $targetIdx) continue;
            if ((int)($item['x'] ?? -1) !== 9) continue;
            if ((int)($item['y'] ?? 0) >= 2) {
                $item['y'] = (int)$item['y'] + $targetH;
            }
        }
        unset($item);

        $layout[$targetIdx]['y'] = 2;

        $this->logger->debug('[TeamHub][LayoutController] healProjectHealthPosition — snapped widget-project-health to y=2', [
            'wasAtY'      => $targetY,
            'shiftedBy'   => $targetH,
        ]);

        return $layout;
    }

    /**
     * Append any DEFAULT_TAB_ORDER keys that are missing from $tabOrder.
     *
     * Mirrors mergeNewWidgets() — same problem, same fix. A saved tab_order_json
     * row (per-team or per-user-default) is a frozen snapshot from whenever it
     * was last written. When a new built-in tab is introduced (e.g. 'timeline'
     * in v3.77.8), every existing saved row predates it and would never show
     * the new tab without this merge running on every GET.
     *
     * New keys are appended at the end, preserving the user's chosen order for
     * everything else. Order among newly-added keys follows DEFAULT_TAB_ORDER.
     */
    private function mergeNewTabs(array $tabOrder): array {
        $existing = array_flip($tabOrder);
        $added    = false;

        foreach (self::DEFAULT_TAB_ORDER as $key) {
            if (isset($existing[$key])) {
                continue;
            }
            $tabOrder[] = $key;
            $added      = true;
            $this->logger->debug('[TeamHub][LayoutController] mergeNewTabs — adding missing tab', [
                'tabKey' => $key,
            ]);
        }

        if ($added) {
            $this->logger->debug('[TeamHub][LayoutController] mergeNewTabs — tabOrder updated with new tabs', [
                'totalTabs' => count($tabOrder),
            ]);
        }

        return $tabOrder;
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function isPresenceModuleEnabled(): bool {
        return $this->config->getAppValue(Application::APP_ID, 'presence_module_enabled', '1') === '1';
    }

    private function isDecisionsModuleEnabled(): bool {
        return $this->config->getAppValue(Application::APP_ID, 'decisions_module_enabled', '1') === '1';
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

    /**
     * Returns true when the user has direct OR indirect membership in the team.
     * Delegates to MemberService so both circles_member (direct) and
     * circles_membership (indirect via group/sub-team) are checked.
     */
    private function isMember(string $teamId, string $userId): bool {
        $this->logger->debug('[TeamHub][LayoutController] isMember check', [
            'teamId' => $teamId, 'userId' => $userId,
        ]);
        return $this->memberService->isEffectiveMember($teamId, $userId, $this->db);
    }
}
