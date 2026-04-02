<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\WidgetService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * HTTP controller for TeamHub's external widget API.
 *
 * External-app endpoints (prefix /api/v1/ext/widgets):
 *   POST   /api/v1/ext/widgets/register      — register or update a widget
 *   DELETE /api/v1/ext/widgets/{appId}        — deregister a widget
 *
 * Team sidebar endpoint:
 *   GET    /api/v1/teams/{teamId}/widgets     — enabled widgets for a team (sidebar)
 *
 * Manage Team → Widgets tab endpoints:
 *   GET    /api/v1/teams/{teamId}/widget-registry                      — full list + enabled state
 *   POST   /api/v1/teams/{teamId}/widget-registry/{registryId}/toggle  — enable/disable
 *   PUT    /api/v1/teams/{teamId}/widget-registry/reorder              — persist drag order
 */
class WidgetController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private WidgetService $widgetService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // ------------------------------------------------------------------
    // External-app registration
    // ------------------------------------------------------------------

    /**
     * POST /api/v1/ext/widgets/register
     *
     * Body (JSON or form-encoded):
     *   app_id      string  required  NC app ID of the registering app
     *   title       string  required  Widget label shown in TeamHub sidebar
     *   iframe_url  string  required  https:// URL loaded in the widget iframe
     *   description string  optional  Short description for the Manage Team tab
     *   icon        string  optional  MDI icon name (e.g. 'Widgets')
     *
     * Returns 200 (upsert on existing) or 201 (new registration).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function registerWidget(): JSONResponse {
        try {
            $body = $this->request->getParams();

            $appId      = isset($body['app_id'])     ? trim((string)$body['app_id'])     : '';
            $title      = isset($body['title'])      ? trim((string)$body['title'])      : '';
            $iframeUrl  = isset($body['iframe_url']) ? trim((string)$body['iframe_url']) : '';
            $description = isset($body['description']) ? trim((string)$body['description']) : null;
            $icon        = isset($body['icon'])       ? trim((string)$body['icon'])       : null;

            // Normalise empty optionals to null so the mapper stores NULL.
            if ($description === '') { $description = null; }
            if ($icon === '')        { $icon = null; }

            if ($appId === '' || $title === '' || $iframeUrl === '') {
                return new JSONResponse(
                    ['error' => 'app_id, title and iframe_url are required'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $result = $this->widgetService->registerWidget($appId, $title, $iframeUrl, $description, $icon);

            $this->logger->info('WidgetController::registerWidget — success', [
                'app_id'      => $appId,
                'registry_id' => $result['id'],
                'app'         => Application::APP_ID,
            ]);

            return new JSONResponse($result, Http::STATUS_OK);
        } catch (\Exception $e) {
            $this->logger->warning('WidgetController::registerWidget — failed', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * DELETE /api/v1/ext/widgets/{appId}
     *
     * Deregisters a widget and removes all team opt-ins for it.
     * Idempotent — returns 200 even when no registration existed.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function deregisterWidget(string $appId): JSONResponse {
        // Sanitise: app IDs are alphanumeric + underscore/hyphen, max 64 chars.
        $appId = trim($appId);
        if ($appId === '' || strlen($appId) > 64 || !preg_match('/^[a-zA-Z0-9_\-]+$/', $appId)) {
            return new JSONResponse(['error' => 'Invalid app_id'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->widgetService->deregisterWidget($appId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('WidgetController::deregisterWidget — failed', [
                'app_id'    => $appId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ------------------------------------------------------------------
    // Sidebar
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/teams/{teamId}/widgets
     *
     * Returns the list of enabled widgets for a team, ordered by sort_order.
     * Called by the Vuex store's selectTeam action (alongside resources, messages, etc.).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getEnabledWidgets(string $teamId): JSONResponse {
        try {
            $widgets = $this->widgetService->getEnabledWidgets($teamId);
            return new JSONResponse($widgets);
        } catch (\Throwable $e) {
            $this->logger->error('WidgetController::getEnabledWidgets — failed', [
                'team_id'   => $teamId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ------------------------------------------------------------------
    // Manage Team → Widgets tab
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/teams/{teamId}/widget-registry
     *
     * Returns all registered widgets annotated with their enabled state for
     * this team. Used to populate the Widgets tab in ManageTeamView.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getWidgetRegistry(string $teamId): JSONResponse {
        try {
            $widgets = $this->widgetService->getWidgetRegistryForTeam($teamId);
            return new JSONResponse($widgets);
        } catch (\Throwable $e) {
            $this->logger->error('WidgetController::getWidgetRegistry — failed', [
                'team_id'   => $teamId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/widget-registry/{registryId}/toggle
     *
     * Body: { "enable": true|false }
     *
     * Enables or disables a widget for a team. Returns the updated full
     * registry list for the team (same shape as getWidgetRegistry).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function toggleWidget(string $teamId, int $registryId): JSONResponse {
        try {
            $body   = $this->request->getParams();
            $enable = isset($body['enable']) ? (bool)$body['enable'] : true;

            $updated = $this->widgetService->toggleWidget($teamId, $registryId, $enable);
            return new JSONResponse($updated);
        } catch (\Exception $e) {
            $this->logger->warning('WidgetController::toggleWidget — failed', [
                'team_id'     => $teamId,
                'registry_id' => $registryId,
                'error'       => $e->getMessage(),
                'app'         => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * PUT /api/v1/teams/{teamId}/widget-registry/reorder
     *
     * Body: { "order": [3, 1, 2] }   — registry IDs in desired display order.
     *
     * Persists drag-and-drop sort_order for a team's enabled widgets.
     * Returns the updated enabled widget list.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function reorderWidgets(string $teamId): JSONResponse {
        try {
            $body  = $this->request->getParams();
            $order = isset($body['order']) && is_array($body['order']) ? $body['order'] : [];

            if (empty($order)) {
                return new JSONResponse(['error' => 'order array is required'], Http::STATUS_BAD_REQUEST);
            }

            $updated = $this->widgetService->reorderWidgets($teamId, $order);
            return new JSONResponse($updated);
        } catch (\Exception $e) {
            $this->logger->error('WidgetController::reorderWidgets — failed', [
                'team_id'   => $teamId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}
