<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\IntegrationRegistryMapper;
use OCA\TeamHub\Db\TeamIntegrationMapper;
use OCA\TeamHub\Integration\ITeamHubWidget;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Business logic for TeamHub's unified integration system.
 *
 * Two integration types — an app may register one OR both:
 *
 *   'widget'    — appears in the right sidebar. TeamHub resolves the
 *                 registered php_class from NC's DI container and calls
 *                 ITeamHubWidget::getWidgetData() directly — no HTTP,
 *                 no loopback, no routing issues. The response is rendered
 *                 natively as a list of items with an optional 3-dot action
 *                 menu populated from the returned actions[] array.
 *                 Requires: php_class implementing ITeamHubWidget.
 *
 *   'menu_item' — appears as a tab in the tab bar. Content loads via
 *                 iframe_url, which may be:
 *                   - https://... (external URL)
 *                   - /apps/myapp/... (relative NC path for same-server apps)
 *                   - /index.php/apps/myapp/...
 *                 Built-in apps (Talk/Files/Calendar/Deck) are handled
 *                 natively — they do not use iframe_url.
 *                 Requires: iframe_url.
 *
 * Registering the same app_id twice with different integration_types creates
 * two independent registry rows. Each has its own registry_id, and team
 * admins can enable/disable them independently.
 *
 * Built-in integrations are seeded once via seedBuiltins() called from
 * Application::boot().
 *
 * External apps register via IntegrationService::registerIntegration()
 * called in-process from their own Application::boot() — never via HTTP.
 *
 * Team management (Manage Team → Integrations tab):
 *   GET    /api/v1/teams/{teamId}/integrations/registry
 *   POST   /api/v1/teams/{teamId}/integrations/{registryId}/toggle
 *   PUT    /api/v1/teams/{teamId}/integrations/reorder
 *
 * Render endpoints (called on team select):
 *   GET    /api/v1/teams/{teamId}/integrations        — all enabled (widgets + menu_items)
 *   GET    /api/v1/teams/{teamId}/integrations/widget-data/{registryId}  — fetch widget data
 */
class IntegrationService {

    // Maximum number of items returned by a widget.
    private const MAX_WIDGET_ITEMS = 20;

    // Maximum number of actions returned by a widget.
    private const MAX_WIDGET_ACTIONS = 10;

    /** Built-in menu_item definitions seeded once into the registry. */
    private const BUILTINS = [
        [
            'app_id'      => 'spreed',
            'title'       => 'Talk',
            'description' => 'Team chat powered by Nextcloud Talk',
            'icon'        => 'Message',
        ],
        [
            'app_id'      => 'files',
            'title'       => 'Files',
            'description' => 'Shared team files folder',
            'icon'        => 'Folder',
        ],
        [
            'app_id'      => 'calendar',
            'title'       => 'Calendar',
            'description' => 'Team calendar',
            'icon'        => 'CalendarMonth',
        ],
        [
            'app_id'      => 'deck',
            'title'       => 'Deck',
            'description' => 'Team task board',
            'icon'        => 'ViewDashboard',
        ],
    ];

    public function __construct(
        private IntegrationRegistryMapper $registryMapper,
        private TeamIntegrationMapper     $teamMapper,
        private IAppManager               $appManager,
        private IGroupManager             $groupManager,
        private IUserSession              $userSession,
        private ContainerInterface        $container,
        private LoggerInterface           $logger,
    ) {}

    // ------------------------------------------------------------------
    // Boot-time seeding
    // ------------------------------------------------------------------

    /**
     * Ensure built-in integration rows exist in the registry.
     * Called once from Application::boot(). Idempotent.
     */
    public function seedBuiltins(): void {

        foreach (self::BUILTINS as $builtin) {
            // Built-ins are always menu_item type — check the specific type slot.
            $existing = $this->registryMapper->findByAppIdAndType(
                $builtin['app_id'],
                IntegrationRegistryMapper::TYPE_MENU_ITEM
            );
            if ($existing !== null) {
                continue;
            }

            $this->registryMapper->create(
                appId:           $builtin['app_id'],
                integrationType: IntegrationRegistryMapper::TYPE_MENU_ITEM,
                title:           $builtin['title'],
                description:     $builtin['description'],
                icon:            $builtin['icon'],
                phpClass:        null,
                iframeUrl:       null,
                isBuiltin:       true,
            );
        }
    }

    // ------------------------------------------------------------------
    // External-app registration
    // ------------------------------------------------------------------

    /**
     * Register or update an integration for an external NC app.
     *
     * Must be called in-process from the registering app's Application::boot()
     * with calledInProcess: true. HTTP-based registration is not supported —
     * NC's loopback guard blocks same-server HTTP calls.
     *
     * Each call registers ONE integration_type for the given app_id. To register
     * both types, call this method twice from boot() — once per type. The two
     * rows are independent and do not interfere with each other.
     *
     * Security rules:
     *   When $calledInProcess = false (HTTP fallback path, NC admin only):
     *     1. Caller must be an authenticated NC admin.
     *   When $calledInProcess = true (in-process from boot()):
     *     1. Session check skipped — no web session exists during NC bootstrap.
     *        Security is provided by the fact that only server-side PHP can
     *        call this method directly.
     *   In both cases:
     *     2. app_id must match an installed and enabled NC app.
     *     3. app_id must not be a built-in.
     *     4. integration_type must be 'widget' or 'menu_item'.
     *     5. For widget: php_class required, must implement ITeamHubWidget,
     *        must be resolvable from NC's DI container.
     *     6. For menu_item: iframe_url required. Must be:
     *          - https://... (external URL), OR
     *          - /apps/...  (relative NC path for same-server apps), OR
     *          - /index.php/...
     *     7. title required, max 255 chars.
     *     8. php_class and iframe_url are mutually exclusive per type.
     *
     * @param bool $calledInProcess Set true when calling from Application::boot().
     * @throws \Exception On any validation failure.
     */
    public function registerIntegration(
        string  $appId,
        string  $integrationType,
        string  $title,
        ?string $description     = null,
        ?string $icon            = null,
        ?string $phpClass        = null,
        ?string $iframeUrl       = null,
        bool    $calledInProcess = false,
    ): array {

        // 1. Auth check.
        if (!$calledInProcess) {
            $user = $this->userSession->getUser();
            if (!$user) {
                throw new \Exception('Not authenticated');
            }
            if (!$this->groupManager->isAdmin($user->getUID())) {
                throw new \Exception('NC admin privilege required to register an integration');
            }
            $callerLabel = $user->getUID();
        } else {
            $callerLabel = 'in-process';
        }

        // 2. App must be installed and enabled.
        if (!$this->appManager->isInstalled($appId)) {
            $this->logger->warning('IntegrationService::registerIntegration — app not installed', [
                'app_id'     => $appId,
                'called_by'  => $callerLabel,
                'app'        => 'teamhub',
            ]);
            throw new \Exception("App '{$appId}' is not installed or not enabled on this instance");
        }

        // 3. Cannot register built-in app IDs.
        if (in_array($appId, IntegrationRegistryMapper::BUILTIN_APP_IDS, true)) {
            throw new \Exception("App '{$appId}' is a built-in TeamHub integration and cannot be registered externally");
        }

        // 4. Type validation.
        if (!in_array($integrationType, [IntegrationRegistryMapper::TYPE_WIDGET, IntegrationRegistryMapper::TYPE_MENU_ITEM], true)) {
            throw new \Exception("integration_type must be 'widget' or 'menu_item'");
        }

        // 5/6. Type-specific field validation.
        if ($integrationType === IntegrationRegistryMapper::TYPE_WIDGET) {

            if (empty($phpClass)) {
                throw new \Exception(
                    "php_class is required for widget integrations — provide the fully-qualified " .
                    "class name of your ITeamHubWidget implementation"
                );
            }

            $phpClass = trim($phpClass);

            if (strlen($phpClass) > 255) {
                throw new \Exception('php_class exceeds maximum length of 255 characters');
            }

            // Validate the class exists and implements ITeamHubWidget.
            // We do this at registration time so misconfigurations are caught
            // immediately rather than silently at render time.
            if (!$this->container->has($phpClass)) {
                // Try class_exists as fallback — some classes are auto-wireable
                // without explicit container binding.
                if (!class_exists($phpClass)) {
                    throw new \Exception(
                        "php_class '{$phpClass}' is not resolvable from NC's DI container. " .
                        "Ensure the class exists and is autoloaded."
                    );
                }
            }

            if (!is_a($phpClass, ITeamHubWidget::class, true)) {
                throw new \Exception(
                    "php_class '{$phpClass}' must implement OCA\\TeamHub\\Integration\\ITeamHubWidget"
                );
            }

            $iframeUrl = null; // not used for widgets

        } else {
            // menu_item
            if (empty($iframeUrl) || trim($iframeUrl) === '') {
                throw new \Exception('iframe_url is required for menu_item integrations');
            }
            $iframeUrl    = trim($iframeUrl);
            $isHttps      = str_starts_with($iframeUrl, 'https://');
            $isRelativeNc = str_starts_with($iframeUrl, '/apps/') || str_starts_with($iframeUrl, '/index.php/');
            if (!$isHttps && !$isRelativeNc) {
                throw new \Exception(
                    "iframe_url must start with 'https://', '/apps/', or '/index.php/'. " .
                    "Relative NC paths (/apps/myapp/...) are recommended for same-server integrations."
                );
            }
            if (strlen($iframeUrl) > 2048) {
                throw new \Exception('iframe_url exceeds maximum length of 2048 characters');
            }
            $phpClass = null; // not used for menu_items
        }

        // 7. Title.
        $title = trim($title);
        if ($title === '') {
            throw new \Exception('title cannot be empty');
        }
        if (strlen($title) > 255) {
            throw new \Exception('title exceeds maximum length of 255 characters');
        }

        $description = $description !== null ? trim($description) : null;
        if ($description !== null && strlen($description) > 500) {
            throw new \Exception('description exceeds maximum length of 500 characters');
        }

        $icon = $icon !== null ? trim($icon) : null;
        if ($icon !== null && strlen($icon) > 64) {
            throw new \Exception('icon name exceeds maximum length of 64 characters');
        }

        // Upsert — natural key is (app_id, integration_type).
        // An app may have one row per type; this call only touches the row
        // matching the requested type. The other type's row (if any) is untouched.
        $existing = $this->registryMapper->findByAppIdAndType($appId, $integrationType);
        if ($existing !== null) {
            return $this->registryMapper->update(
                $existing['id'],
                $title,
                $description,
                $icon,
                $phpClass,
                $iframeUrl,
            );
        }

        return $this->registryMapper->create(
            appId:           $appId,
            integrationType: $integrationType,
            title:           $title,
            description:     $description,
            icon:            $icon,
            phpClass:        $phpClass,
            iframeUrl:       $iframeUrl,
            isBuiltin:       false,
        );
    }

    /**
     * Suspend an integration when its app is disabled.
     *
     * Clears php_class / iframe_url so the widget cannot be called while the
     * app is down, but preserves the registry row and all per-team opt-ins.
     * The next time the app is enabled its boot() calls registerIntegration()
     * which upserts the class/url back in. The ID never changes and team
     * admins never need to re-enable the widget after an app update.
     *
     * Built-in app IDs are silently skipped (they have no php_class to clear).
     */
    public function suspendIntegration(string $appId): void {

        if (in_array($appId, IntegrationRegistryMapper::BUILTIN_APP_IDS, true)) {
            return;
        }

        $existing = $this->registryMapper->findByAppId($appId);
        if (empty($existing)) {
            return;
        }

        $this->registryMapper->suspendByAppId($appId);

        $registryIds = array_column($existing, 'id');
    }

    /**
     * Permanently deregister an integration by app ID.
     *
     * Use this only for permanent removal — e.g. an NC admin explicitly
     * deleting a registration, or a future uninstall hook.
     * Do NOT call this on routine app:disable — use suspendIntegration() instead.
     *
     * Cascades: all per-team opt-ins are removed first.
     * Built-in integrations cannot be deregistered.
     *
     * @throws \Exception When caller is not NC admin or app is built-in.
     */
    public function deregisterIntegration(string $appId, bool $calledInProcess = false): void {

        if (!$calledInProcess) {
            $user = $this->userSession->getUser();
            if (!$user || !$this->groupManager->isAdmin($user->getUID())) {
                throw new \Exception('NC admin privilege required to deregister an integration');
            }
        }

        if (in_array($appId, IntegrationRegistryMapper::BUILTIN_APP_IDS, true)) {
            throw new \Exception("Built-in integration '{$appId}' cannot be deregistered");
        }

        $existing = $this->registryMapper->findByAppId($appId);
        if (empty($existing)) {
            return;
        }

        // Cascade-delete team opt-ins for ALL registry rows belonging to this app.
        foreach ($existing as $row) {
            $this->teamMapper->deleteByRegistryId($row['id']);
        }
        $this->registryMapper->deleteByAppId($appId);

        $registryIds = array_column($existing, 'id');
    }

    // ------------------------------------------------------------------
    // Admin — full registry view
    // ------------------------------------------------------------------

    /**
     * Return all registry entries (built-ins first, then external).
     * Used by Admin Settings → Integrations tab.
     *
     * @return array<int, array>
     */
    public function getFullRegistry(): array {
        return $this->registryMapper->findAll();
    }

    // ------------------------------------------------------------------
    // Team management (Manage Team → Integrations tab)
    // ------------------------------------------------------------------

    /**
     * Return all integrations with their enabled state for a team.
     */
    public function getRegistryForTeam(string $teamId): array {
        return $this->teamMapper->findAllWithEnabledStateForTeam($teamId);
    }

    /**
     * Enable or disable an integration for a team.
     *
     * @return array Updated full registry list.
     * @throws \Exception When registry_id does not exist.
     */
    public function toggleIntegration(string $teamId, int $registryId, bool $enable): array {

        $this->registryMapper->findById($registryId); // throws if not found

        if ($enable) {
            $this->teamMapper->enable($registryId, $teamId);
        } else {
            $this->teamMapper->disable($registryId, $teamId);
        }

        return $this->teamMapper->findAllWithEnabledStateForTeam($teamId);
    }

    /**
     * Persist a new sort order for a team's enabled integrations.
     *
     * @param int[] $orderedRegistryIds Registry IDs in the desired display order.
     * @return array Updated enabled integration list.
     */
    public function reorderIntegrations(string $teamId, array $orderedRegistryIds): array {

        $clean = array_values(array_filter(
            array_map('intval', $orderedRegistryIds),
            fn(int $id) => $id > 0
        ));

        $this->teamMapper->reorder($teamId, $clean);
        return $this->teamMapper->findEnabledForTeam($teamId);
    }

    // ------------------------------------------------------------------
    // Render endpoints (called on team select)
    // ------------------------------------------------------------------

    /**
     * Return all enabled integrations for a team split by type.
     * Shape: { 'widgets': [...], 'menu_items': [...] }
     */
    public function getEnabledIntegrations(string $teamId): array {

        $all = $this->teamMapper->findEnabledForTeam($teamId);

        $result = ['widgets' => [], 'menu_items' => []];
        foreach ($all as $item) {
            if ($item['integration_type'] === IntegrationRegistryMapper::TYPE_WIDGET) {
                $result['widgets'][] = $item;
            } else {
                $result['menu_items'][] = $item;
            }
        }

        return $result;
    }

    /**
     * Fetch widget data by calling the registered ITeamHubWidget implementation
     * directly via NC's DI container — no HTTP, no loopback, no curl.
     *
     * The implementing class is resolved fresh from the container on each call,
     * so it benefits from full constructor injection (services, mappers, etc.).
     *
     * Response is sanitised before being returned to the frontend:
     *   - items: capped at MAX_WIDGET_ITEMS, shape enforced
     *   - actions: capped at MAX_WIDGET_ACTIONS, URL validated, label required
     *
     * @throws \Exception When the integration is not a widget, has no php_class,
     *                    or the widget is not enabled for this team.
     */
    public function fetchWidgetData(string $teamId, int $registryId): array {

        $entry = $this->registryMapper->findById($registryId);

        if ($entry['integration_type'] !== IntegrationRegistryMapper::TYPE_WIDGET) {
            throw new \Exception("Integration {$registryId} is not a widget");
        }

        if (empty($entry['php_class'])) {
            throw new \Exception(
                "Widget {$registryId} has no php_class configured — " .
                "the registering app must implement ITeamHubWidget"
            );
        }

        if (!$this->teamMapper->isEnabled($registryId, $teamId)) {
            throw new \Exception("Widget {$registryId} is not enabled for team {$teamId}");
        }

        // Resolve the ITeamHubWidget implementation from NC's DI container.
        // This gives the implementing class full access to its own injected services.
        $phpClass = $entry['php_class'];

        try {
            /** @var ITeamHubWidget $widget */
            $widget = $this->container->get($phpClass);
        } catch (\Throwable $e) {
            $this->logger->error('IntegrationService::fetchWidgetData — container resolution failed', [
                'registry_id' => $registryId,
                'php_class'   => $phpClass,
                'error'       => $e->getMessage(),
                'app'         => 'teamhub',
            ]);
            return ['items' => [], 'error' => "Widget class '{$phpClass}' could not be loaded"];
        }

        if (!($widget instanceof ITeamHubWidget)) {
            $this->logger->error('IntegrationService::fetchWidgetData — class does not implement ITeamHubWidget', [
                'registry_id' => $registryId,
                'php_class'   => $phpClass,
                'app'         => 'teamhub',
            ]);
            return ['items' => [], 'error' => "Widget class '{$phpClass}' does not implement ITeamHubWidget"];
        }

        // Get current user ID to pass to the widget — widget uses it for access control.
        $user   = $this->userSession->getUser();
        $userId = $user ? $user->getUID() : '';

        try {
            $raw = $widget->getWidgetData($teamId, $userId);
        } catch (\Throwable $e) {
            $this->logger->error('IntegrationService::fetchWidgetData — getWidgetData threw', [
                'registry_id' => $registryId,
                'php_class'   => $phpClass,
                'error'       => $e->getMessage(),
                'app'         => 'teamhub',
            ]);
            return ['items' => [], 'error' => 'Widget data could not be loaded'];
        }

        if (!is_array($raw) || !isset($raw['items']) || !is_array($raw['items'])) {
            $this->logger->warning('IntegrationService::fetchWidgetData — invalid response shape', [
                'registry_id' => $registryId,
                'php_class'   => $phpClass,
                'app'         => 'teamhub',
            ]);
            return ['items' => []];
        }

        // Sanitise items — enforce shape and cap count.
        $items = [];
        foreach (array_slice($raw['items'], 0, self::MAX_WIDGET_ITEMS) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = [
                'label' => isset($item['label']) ? (string)$item['label'] : '',
                'value' => isset($item['value']) ? (string)$item['value'] : '',
                'icon'  => isset($item['icon'])  ? (string)$item['icon']  : null,
                'url'   => isset($item['url'])   ? (string)$item['url']   : null,
            ];
        }

        // Sanitise optional dynamic actions array.
        // Actions with actionId trigger a native TeamHub modal form.
        // Actions with only url are browser-navigation links (relative NC path or https://).
        $actions = [];
        if (isset($raw['actions']) && is_array($raw['actions'])) {
            foreach (array_slice($raw['actions'], 0, self::MAX_WIDGET_ACTIONS) as $action) {
                if (!is_array($action)) {
                    continue;
                }
                $label    = isset($action['label']) ? trim((string)$action['label']) : '';
                $actionId = isset($action['actionId']) ? trim((string)$action['actionId']) : null;

                if ($label === '') {
                    continue;
                }

                // actionId-based actions use the native modal — no url validation needed.
                if ($actionId !== null && $actionId !== '') {
                    $actions[] = [
                        'label'    => $label,
                        'icon'     => isset($action['icon']) ? (string)$action['icon'] : null,
                        'actionId' => $actionId,
                        // url is optional fallback for clients that don't support actionId
                        'url'      => isset($action['url']) ? (string)$action['url'] : null,
                    ];
                    continue;
                }

                // url-only fallback action — validate the URL.
                $actionUrl  = isset($action['url']) ? trim((string)$action['url']) : '';
                $isRelative = str_starts_with($actionUrl, '/apps/') || str_starts_with($actionUrl, '/index.php/');
                $isHttps    = str_starts_with($actionUrl, 'https://');
                if ((!$isRelative && !$isHttps) || strlen($actionUrl) > 2048) {
                    continue;
                }
                $actions[] = [
                    'label' => $label,
                    'icon'  => isset($action['icon']) ? (string)$action['icon'] : null,
                    'url'   => $actionUrl,
                ];
            }
        }

        $result = ['items' => $items];
        if (!empty($actions)) {
            $result['actions'] = $actions;
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Native action modal — form fetch + submit
    // ------------------------------------------------------------------

    /**
     * Fetch the form definition for a widget action.
     *
     * Calls getActionForm() on the ITeamHubWidget implementation in-process.
     * Returns the form definition that IntegrationWidget renders as a native NC modal.
     *
     * @throws \Exception When the integration is not a widget, class not found,
     *                    or method not implemented.
     */
    public function fetchActionForm(string $teamId, int $registryId, string $actionId): array {

        $widget = $this->resolveWidgetClass($teamId, $registryId);
        $user   = $this->userSession->getUser();
        $userId = $user ? $user->getUID() : '';

        if (!method_exists($widget, 'getActionForm')) {
            return ['fields' => []];
        }

        try {
            $form = $widget->getActionForm($actionId, $teamId, $userId);
        } catch (\Throwable $e) {
            $this->logger->error('IntegrationService::fetchActionForm — getActionForm threw', [
                'registry_id' => $registryId,
                'action_id'   => $actionId,
                'error'       => $e->getMessage(),
                'app'         => 'teamhub',
            ]);
            throw new \Exception('Failed to load action form: ' . $e->getMessage());
        }

        if (!is_array($form) || !isset($form['fields']) || !is_array($form['fields'])) {
            return ['fields' => []];
        }

        // Sanitise fields
        $fields = [];
        $allowedTypes = ['text', 'textarea', 'email', 'checkbox', 'date'];
        foreach ($form['fields'] as $field) {
            if (!is_array($field) || empty($field['name']) || empty($field['label']) || empty($field['type'])) {
                continue;
            }
            $type = in_array($field['type'], $allowedTypes, true) ? $field['type'] : 'text';
            $sanitised = [
                'name'     => (string)$field['name'],
                'label'    => (string)$field['label'],
                'type'     => $type,
                'required' => !empty($field['required']),
            ];
            if (isset($field['value']))       { $sanitised['value']       = $field['value']; }
            if (isset($field['placeholder'])) { $sanitised['placeholder'] = (string)$field['placeholder']; }
            $fields[] = $sanitised;
        }

        return [
            'title'        => isset($form['title'])        ? (string)$form['title']        : null,
            'submit_label' => isset($form['submit_label']) ? (string)$form['submit_label'] : null,
            'fields'       => $fields,
        ];
    }

    /**
     * Submit a completed action form to the widget implementation.
     *
     * Calls handleAction() on the ITeamHubWidget implementation in-process.
     *
     * @throws \Exception When the integration is not a widget, class not found,
     *                    or the action fails.
     */
    public function submitAction(string $teamId, int $registryId, string $actionId, array $fields): array {

        $widget = $this->resolveWidgetClass($teamId, $registryId);
        $user   = $this->userSession->getUser();
        $userId = $user ? $user->getUID() : '';

        if (!method_exists($widget, 'handleAction')) {
            throw new \Exception('This integration does not support action submission');
        }

        // Sanitise field values — only allow scalar values
        $cleanFields = [];
        foreach ($fields as $key => $value) {
            if (is_string($key) && (is_scalar($value) || is_null($value))) {
                $cleanFields[(string)$key] = $value;
            }
        }

        try {
            $result = $widget->handleAction($actionId, $cleanFields, $teamId, $userId);
        } catch (\Throwable $e) {
            $this->logger->error('IntegrationService::submitAction — handleAction threw', [
                'registry_id' => $registryId,
                'action_id'   => $actionId,
                'error'       => $e->getMessage(),
                'app'         => 'teamhub',
            ]);
            throw new \Exception('Action failed: ' . $e->getMessage());
        }

        if (!is_array($result) || !isset($result['success'])) {
            return ['success' => false, 'message' => 'Action returned an invalid response'];
        }

        return [
            'success' => (bool)$result['success'],
            'message' => isset($result['message']) ? (string)$result['message'] : null,
            'refresh' => !empty($result['refresh']),
        ];
    }

    /**
     * Resolve and validate a widget class from the DI container.
     * Shared by fetchActionForm() and submitAction().
     *
     * @throws \Exception On misconfiguration or if integration is not enabled.
     */
    private function resolveWidgetClass(string $teamId, int $registryId): ITeamHubWidget {
        $entry = $this->registryMapper->findById($registryId);

        if ($entry['integration_type'] !== IntegrationRegistryMapper::TYPE_WIDGET) {
            throw new \Exception("Integration {$registryId} is not a widget");
        }
        if (empty($entry['php_class'])) {
            throw new \Exception("Widget {$registryId} has no php_class configured");
        }
        if (!$this->teamMapper->isEnabled($registryId, $teamId)) {
            throw new \Exception("Widget {$registryId} is not enabled for team {$teamId}");
        }

        $phpClass = $entry['php_class'];
        try {
            $widget = $this->container->get($phpClass);
        } catch (\Throwable $e) {
            throw new \Exception("Widget class '{$phpClass}' could not be loaded: " . $e->getMessage());
        }

        if (!($widget instanceof ITeamHubWidget)) {
            throw new \Exception("Widget class '{$phpClass}' does not implement ITeamHubWidget");
        }

        return $widget;
    }
}
