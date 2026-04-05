<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\IntegrationRegistryMapper;
use OCA\TeamHub\Db\TeamIntegrationMapper;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Business logic for TeamHub's unified integration system.
 *
 * Two integration types:
 *
 *   'widget'    — appears in the right sidebar. TeamHub calls data_url server-side
 *                 (PHP → HTTP GET to external app endpoint), renders the response
 *                 natively as a list of items. Optional 3-dot action menu item
 *                 triggers a POST to action_url and shows the response in a modal.
 *
 *   'menu_item' — appears as a tab in the tab bar. Content loads in a sandboxed iframe
 *                 (iframe_url) for external apps, or is handled natively for built-ins
 *                 (Talk/Files/Calendar/Deck).
 *
 * Built-in integrations are seeded once via seedBuiltins() called from Application::boot().
 *
 * External apps register via REST:
 *   POST   /api/v1/ext/integrations/register
 *   DELETE /api/v1/ext/integrations/{appId}
 *
 * Team management (Manage Team → Integrations tab):
 *   GET    /api/v1/teams/{teamId}/integrations/registry
 *   POST   /api/v1/teams/{teamId}/integrations/{registryId}/toggle
 *   PUT    /api/v1/teams/{teamId}/integrations/reorder
 *
 * Render endpoints (called on team select):
 *   GET    /api/v1/teams/{teamId}/integrations        — all enabled (widgets + menu_items)
 *   GET    /api/v1/teams/{teamId}/integrations/widget-data/{registryId}  — fetch widget data
 *   POST   /api/v1/teams/{teamId}/integrations/action/{registryId}       — trigger action modal
 *
 */
class IntegrationService {

    // Widget data response timeout in seconds.
    private const DATA_FETCH_TIMEOUT = 5;

    // Maximum number of items returned by a widget data endpoint.
    private const MAX_WIDGET_ITEMS = 20;

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
        private IClientService            $httpClientService,
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
            $existing = $this->registryMapper->findByAppId($builtin['app_id']);
            if ($existing !== null) {
                continue;
            }

            $this->registryMapper->create(
                appId:           $builtin['app_id'],
                integrationType: IntegrationRegistryMapper::TYPE_MENU_ITEM,
                title:           $builtin['title'],
                description:     $builtin['description'],
                icon:            $builtin['icon'],
                dataUrl:         null,
                actionUrl:       null,
                actionLabel:     null,
                iframeUrl:       null,  // built-ins resolve URL from team resources at runtime
                isBuiltin:       true,
            );

        }
    }

    // ------------------------------------------------------------------
    // External-app registration
    // ------------------------------------------------------------------

    /**
     * Register or update an integration for an external app.
     *
     * Security rules:
     *   When called via HTTP controller ($calledInProcess = false, default):
     *     1. Caller must be an authenticated NC admin (defence-in-depth; controller has no NoAdminRequired).
     *   When called in-process from another app's boot() ($calledInProcess = true):
     *     1. Session check is skipped — no web session exists during NC bootstrap.
     *        Security is provided by the fact that only server-side PHP can call this directly.
     *   In both cases:
     *     2. app_id must match an installed and enabled NC app.
     *     3. app_id must not be a built-in (Talk/Files/Calendar/Deck).
     *     4. integration_type must be 'widget' or 'menu_item'.
     *     5. For widget: data_url required; action_url/action_label optional.
     *     6. For menu_item: iframe_url required, must be https://.
     *     7. title required, max 255 chars.
     *
     * @param bool $calledInProcess Set true when calling from Application::boot() — skips session check.
     * @throws \Exception On any validation failure.
     */
    public function registerIntegration(
        string  $appId,
        string  $integrationType,
        string  $title,
        ?string $description      = null,
        ?string $icon             = null,
        ?string $dataUrl          = null,
        ?string $actionUrl        = null,
        ?string $actionLabel      = null,
        ?string $iframeUrl        = null,
        bool    $calledInProcess  = false,
    ): array {

        // 1. Auth + admin check.
        //    When called via HTTP the NC framework already requires a session (NoCSRFRequired only,
        //    no NoAdminRequired), but we also check here as defence-in-depth.
        //    When called in-process (boot context), no web session exists — skip the session gate
        //    and rely on the fact that only server PHP can invoke this method directly.
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
            $this->logger->warning('IntegrationService::registerIntegration — app_id not installed', [
                'app_id'    => $appId,
                'called_by' => $callerLabel,
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
            $dataUrl = $this->validateUrl($dataUrl, 'data_url', required: true);
            $actionUrl = $actionUrl ? $this->validateUrl($actionUrl, 'action_url', required: false) : null;
            if ($actionLabel !== null && strlen($actionLabel) > 64) {
                throw new \Exception('action_label exceeds maximum length of 64 characters');
            }
            $iframeUrl = null; // not used for widgets
        } else {
            $iframeUrl = $this->validateUrl($iframeUrl, 'iframe_url', required: true, httpsOnly: true);
            $dataUrl   = null;
            $actionUrl = null;
            $actionLabel = null;
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

        // Upsert.
        $existing = $this->registryMapper->findByAppId($appId);
        if ($existing !== null) {
            return $this->registryMapper->update(
                $existing['id'],
                $title,
                $description,
                $icon,
                $dataUrl,
                $actionUrl,
                $actionLabel,
                $iframeUrl,
            );
        }

        return $this->registryMapper->create(
            appId:           $appId,
            integrationType: $integrationType,
            title:           $title,
            description:     $description,
            icon:            $icon,
            dataUrl:         $dataUrl,
            actionUrl:       $actionUrl,
            actionLabel:     $actionLabel,
            iframeUrl:       $iframeUrl,
            isBuiltin:       false,
        );
    }

    /**
     * Deregister an integration by app ID.
     *
     * Cascades: all team opt-ins are removed first.
     * Built-in integrations cannot be deregistered.
     * NC admin privilege required.
     *
     * @throws \Exception When caller is not NC admin or app is built-in.
     */
    public function deregisterIntegration(string $appId, bool $calledInProcess = false): void {

        // Session gate: skip when called in-process (boot/CLI) — no web session exists.
        // HTTP callers are gated at the controller level (no NoAdminRequired attribute).
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
        if ($existing === null) {
            $this->logger->info('IntegrationService::deregisterIntegration — not registered, skipping', [
                'app_id' => $appId,
            ]);
            return;
        }

        // Cascade.
        $this->teamMapper->deleteByRegistryId($existing['id']);
        $this->registryMapper->deleteByAppId($appId);

        $this->logger->info('IntegrationService::deregisterIntegration — done', [
            'app_id'      => $appId,
            'registry_id' => $existing['id'],
        ]);
    }

    // ------------------------------------------------------------------
    // Admin — full registry view
    // ------------------------------------------------------------------

    /**
     * Return all registry entries (built-ins first, then external, ordered by created_at).
     * Used by the Admin Settings → Integrations tab via GET /api/v1/ext/integrations.
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
     * Used to populate Manage Team → Integrations.
     */
    public function getRegistryForTeam(string $teamId): array {
        return $this->teamMapper->findAllWithEnabledStateForTeam($teamId);
    }

    /**
     * Enable or disable an integration for a team.
     *
     * @return array Updated full registry list (same shape as getRegistryForTeam).
     * @throws \Exception When registry_id does not exist.
     */
    public function toggleIntegration(string $teamId, int $registryId, bool $enable): array {

        // Verify the registry entry exists.
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
     * Fetch widget data from the external app's data_url endpoint.
     *
     * TeamHub calls the external app server-side (PHP HTTP GET).
     * The external app must respond with:
     *   { "items": [ { "label": "string", "value": "string", "icon"?: "MDI name", "url"?: "string" } ] }
     *
     * teamId is passed as a query parameter so the external app can scope its response.
     * The current user's NC auth token is forwarded so the external app can authenticate.
     *
     * @throws \Exception When the integration is not a widget or data_url is missing.
     */
    public function fetchWidgetData(string $teamId, int $registryId): array {

        $entry = $this->registryMapper->findById($registryId);

        if ($entry['integration_type'] !== IntegrationRegistryMapper::TYPE_WIDGET) {
            throw new \Exception("Integration {$registryId} is not a widget");
        }
        if (empty($entry['data_url'])) {
            throw new \Exception("Widget {$registryId} has no data_url configured");
        }

        // Verify the widget is actually enabled for this team.
        if (!$this->teamMapper->isEnabled($registryId, $teamId)) {
            throw new \Exception("Widget {$registryId} is not enabled for team {$teamId}");
        }

        // Build the URL — append teamId query param.
        $url       = $entry['data_url'];
        $separator = str_contains($url, '?') ? '&' : '?';
        $fetchUrl  = $url . $separator . 'teamId=' . urlencode($teamId);


        try {
            $client   = $this->httpClientService->newClient();
            $response = $client->get($fetchUrl, [
                'timeout'         => self::DATA_FETCH_TIMEOUT,
                'connect_timeout' => self::DATA_FETCH_TIMEOUT,
            ]);

            $body = $response->getBody();
            $data = json_decode($body, true);

            if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
                $this->logger->warning('IntegrationService::fetchWidgetData — invalid response shape', [
                    'registry_id' => $registryId,
                    'url'         => $fetchUrl,
                    'body'        => substr($body, 0, 200),
                ]);
                return ['items' => []];
            }

            // Sanitise items — enforce shape and cap count.
            $items = [];
            foreach (array_slice($data['items'], 0, self::MAX_WIDGET_ITEMS) as $item) {
                if (!is_array($item)) { continue; }
                $items[] = [
                    'label' => isset($item['label']) ? (string)$item['label'] : '',
                    'value' => isset($item['value']) ? (string)$item['value'] : '',
                    'icon'  => isset($item['icon'])  ? (string)$item['icon']  : null,
                    'url'   => isset($item['url'])   ? (string)$item['url']   : null,
                ];
            }

            return ['items' => $items];

        } catch (\Throwable $e) {
            $this->logger->error('IntegrationService::fetchWidgetData — HTTP call failed', [
                'registry_id' => $registryId,
                'url'         => $fetchUrl,
                'error'       => $e->getMessage(),
            ]);
            // Return empty items so the widget renders gracefully rather than crashing.
            return ['items' => [], 'error' => 'Failed to load widget data'];
        }
    }

    /**
     * Trigger a widget action by POSTing to the external app's action_url.
     *
     * The external app responds with a modal definition:
     *   { "title": "string", "fields": [ { "label", "type", "name", "value"? } ], "submit_label"?: "string" }
     *
     * The modal is rendered by TeamHub. On submit, the browser POSTs directly to action_url.
     *
     * @throws \Exception When the integration has no action_url or is not a widget.
     */
    public function triggerWidgetAction(string $teamId, int $registryId): array {

        $entry = $this->registryMapper->findById($registryId);

        if ($entry['integration_type'] !== IntegrationRegistryMapper::TYPE_WIDGET) {
            throw new \Exception("Integration {$registryId} is not a widget");
        }
        if (empty($entry['action_url'])) {
            throw new \Exception("Widget {$registryId} has no action_url configured");
        }
        if (!$this->teamMapper->isEnabled($registryId, $teamId)) {
            throw new \Exception("Widget {$registryId} is not enabled for team {$teamId}");
        }

        $url       = $entry['action_url'];
        $separator = str_contains($url, '?') ? '&' : '?';
        $fetchUrl  = $url . $separator . 'teamId=' . urlencode($teamId);


        try {
            $client   = $this->httpClientService->newClient();
            $response = $client->get($fetchUrl, [
                'timeout' => self::DATA_FETCH_TIMEOUT,
            ]);

            $body = $response->getBody();
            $data = json_decode($body, true);

            if (!is_array($data)) {
                return ['title' => $entry['action_label'] ?? 'Action', 'fields' => []];
            }

            return $data;

        } catch (\Throwable $e) {
            $this->logger->error('IntegrationService::triggerWidgetAction — HTTP call failed', [
                'registry_id' => $registryId,
                'url'         => $fetchUrl,
                'error'       => $e->getMessage(),
            ]);
            throw new \Exception('Failed to load action modal: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Validate and normalise a URL field.
     *
     * Allows:
     *   - Relative NC paths starting with /apps/ or /index.php/
     *   - Absolute https:// URLs
     *
     * @throws \Exception On validation failure.
     */
    private function validateUrl(?string $url, string $fieldName, bool $required, bool $httpsOnly = false): ?string {
        if ($url === null || trim($url) === '') {
            if ($required) {
                throw new \Exception("{$fieldName} is required");
            }
            return null;
        }

        $url = trim($url);

        if (strlen($url) > 2048) {
            throw new \Exception("{$fieldName} exceeds maximum length of 2048 characters");
        }

        if ($httpsOnly) {
            if (!str_starts_with($url, 'https://')) {
                throw new \Exception("{$fieldName} must start with https://");
            }
        } else {
            // Allow relative NC paths or absolute https.
            $isRelative = str_starts_with($url, '/apps/') || str_starts_with($url, '/index.php/');
            $isHttps    = str_starts_with($url, 'https://');
            if (!$isRelative && !$isHttps) {
                throw new \Exception("{$fieldName} must be a relative NC path (/apps/…) or an absolute https:// URL");
            }
        }

        return $url;
    }
}
