<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\IntegrationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * HTTP controller for TeamHub's unified integration API.
 *
 * External-app registration:
 *   POST   /api/v1/ext/integrations/register          — register or update an integration
 *   DELETE /api/v1/ext/integrations/{appId}            — deregister (NC admin required)
 *
 * Team render endpoints (called on team select):
 *   GET    /api/v1/teams/{teamId}/integrations          — all enabled integrations split by type
 *   GET    /api/v1/teams/{teamId}/integrations/widget-data/{registryId}  — fetch widget data
 *   GET    /api/v1/teams/{teamId}/integrations/action/{registryId}       — get action modal def
 *
 * Manage Team → Integrations tab:
 *   GET    /api/v1/teams/{teamId}/integrations/registry                  — full list + enabled state
 *   POST   /api/v1/teams/{teamId}/integrations/{registryId}/toggle       — enable/disable
 *   PUT    /api/v1/teams/{teamId}/integrations/reorder                   — persist drag order
 *
 */
class IntegrationController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IntegrationService $integrationService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // ------------------------------------------------------------------
    // External-app registration
    // ------------------------------------------------------------------

    /**
     * POST /api/v1/ext/integrations/register
     *
     * Body params:
     *   app_id           string  required
     *   integration_type string  required  'widget' | 'menu_item'
     *   title            string  required
     *   description      string  optional
     *   icon             string  optional  MDI icon name
     *
     *   For widget:
     *     data_url       string  required  TeamHub calls this server-side
     *     action_url     string  optional  Opens action modal
     *     action_label   string  optional  3-dot menu label
     *
     *   For menu_item:
     *     iframe_url     string  required  https:// URL for canvas iframe
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function registerIntegration(): JSONResponse {

        try {
            $body = $this->request->getParams();

            $appId           = isset($body['app_id'])           ? trim((string)$body['app_id'])           : '';
            $integrationType = isset($body['integration_type']) ? trim((string)$body['integration_type']) : '';
            $title           = isset($body['title'])            ? trim((string)$body['title'])            : '';
            $description     = isset($body['description'])      ? trim((string)$body['description'])      : null;
            $icon            = isset($body['icon'])             ? trim((string)$body['icon'])             : null;
            $dataUrl         = isset($body['data_url'])         ? trim((string)$body['data_url'])         : null;
            $actionUrl       = isset($body['action_url'])       ? trim((string)$body['action_url'])       : null;
            $actionLabel     = isset($body['action_label'])     ? trim((string)$body['action_label'])     : null;
            $iframeUrl       = isset($body['iframe_url'])       ? trim((string)$body['iframe_url'])       : null;

            // Normalise empty optionals to null.
            $description = ($description === '') ? null : $description;
            $icon        = ($icon === '')        ? null : $icon;
            $dataUrl     = ($dataUrl === '')     ? null : $dataUrl;
            $actionUrl   = ($actionUrl === '')   ? null : $actionUrl;
            $actionLabel = ($actionLabel === '') ? null : $actionLabel;
            $iframeUrl   = ($iframeUrl === '')   ? null : $iframeUrl;

            if ($appId === '' || $integrationType === '' || $title === '') {
                return new JSONResponse(
                    ['error' => 'app_id, integration_type and title are required'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $result = $this->integrationService->registerIntegration(
                $appId, $integrationType, $title, $description, $icon,
                $dataUrl, $actionUrl, $actionLabel, $iframeUrl
            );

            $this->logger->info('IntegrationController::registerIntegration — success', [
                'app_id'      => $appId,
                'type'        => $integrationType,
                'registry_id' => $result['id'],
                'app'         => Application::APP_ID,
            ]);

            return new JSONResponse($result, Http::STATUS_OK);

        } catch (\Exception $e) {
            $this->logger->warning('IntegrationController::registerIntegration — failed', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * DELETE /api/v1/ext/integrations/{appId}
     *
     * Deregisters an integration and cascade-deletes all team opt-ins.
     * Idempotent. NC admin required.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function deregisterIntegration(string $appId): JSONResponse {

        $appId = trim($appId);
        if ($appId === '' || strlen($appId) > 64 || !preg_match('/^[a-zA-Z0-9_\-]+$/', $appId)) {
            return new JSONResponse(['error' => 'Invalid app_id'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->integrationService->deregisterIntegration($appId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('IntegrationController::deregisterIntegration — failed', [
                'app_id'    => $appId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            $status = str_contains($e->getMessage(), 'admin') ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
            return new JSONResponse(['error' => $e->getMessage()], $status);
        }
    }

    // ------------------------------------------------------------------
    // Team render endpoints
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/teams/{teamId}/integrations
     *
     * Returns enabled integrations split by type:
     *   { "widgets": [...], "menu_items": [...] }
     * Called by Vuex selectTeam action.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getEnabledIntegrations(string $teamId): JSONResponse {

        try {
            $data = $this->integrationService->getEnabledIntegrations($teamId);
            return new JSONResponse($data);
        } catch (\Throwable $e) {
            $this->logger->error('IntegrationController::getEnabledIntegrations — failed', [
                'team_id'   => $teamId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            return new JSONResponse(['widgets' => [], 'menu_items' => []], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/integrations/widget-data/{registryId}
     *
     * TeamHub fetches data from the external app's data_url server-side and
     * returns it to the Vue client. The Vue component renders it natively.
     *
     * Response: { "items": [ { "label", "value", "icon"?, "url"? } ], "error"? }
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getWidgetData(string $teamId, int $registryId): JSONResponse {

        try {
            $data = $this->integrationService->fetchWidgetData($teamId, $registryId);
            return new JSONResponse($data);
        } catch (\Exception $e) {
            $this->logger->warning('IntegrationController::getWidgetData — failed', [
                'team_id'     => $teamId,
                'registry_id' => $registryId,
                'error'       => $e->getMessage(),
                'app'         => Application::APP_ID,
            ]);
            return new JSONResponse(['items' => [], 'error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/integrations/action/{registryId}
     *
     * Returns the action modal definition from the external app's action_url.
     * Response: { "title", "fields": [ { "label", "type", "name", "value"? } ], "submit_label"? }
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getWidgetAction(string $teamId, int $registryId): JSONResponse {

        try {
            $data = $this->integrationService->triggerWidgetAction($teamId, $registryId);
            return new JSONResponse($data);
        } catch (\Exception $e) {
            $this->logger->warning('IntegrationController::getWidgetAction — failed', [
                'team_id'     => $teamId,
                'registry_id' => $registryId,
                'error'       => $e->getMessage(),
                'app'         => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    // ------------------------------------------------------------------
    // Manage Team → Integrations tab
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/teams/{teamId}/integrations/registry
     *
     * Returns all integrations annotated with their enabled state for this team.
     * Used by Manage Team → Integrations.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getIntegrationRegistry(string $teamId): JSONResponse {

        try {
            $data = $this->integrationService->getRegistryForTeam($teamId);
            return new JSONResponse($data);
        } catch (\Throwable $e) {
            $this->logger->error('IntegrationController::getIntegrationRegistry — failed', [
                'team_id'   => $teamId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/integrations/{registryId}/toggle
     *
     * Body: { "enable": true|false }
     *
     * Returns the updated full registry list for the team.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function toggleIntegration(string $teamId, int $registryId): JSONResponse {

        try {
            $body   = $this->request->getParams();
            $enable = isset($body['enable']) ? filter_var($body['enable'], FILTER_VALIDATE_BOOLEAN) : true;

            $updated = $this->integrationService->toggleIntegration($teamId, $registryId, $enable);
            return new JSONResponse($updated);
        } catch (\Exception $e) {
            $this->logger->warning('IntegrationController::toggleIntegration — failed', [
                'team_id'     => $teamId,
                'registry_id' => $registryId,
                'error'       => $e->getMessage(),
                'app'         => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * PUT /api/v1/teams/{teamId}/integrations/reorder
     *
     * Body: { "order": [3, 1, 2] }   — registry IDs in desired display order.
     *
     * Returns updated enabled integration list.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function reorderIntegrations(string $teamId): JSONResponse {

        try {
            $body  = $this->request->getParams();
            $order = isset($body['order']) && is_array($body['order']) ? $body['order'] : [];

            if (empty($order)) {
                return new JSONResponse(['error' => 'order array is required'], Http::STATUS_BAD_REQUEST);
            }

            $updated = $this->integrationService->reorderIntegrations($teamId, $order);
            return new JSONResponse($updated);
        } catch (\Exception $e) {
            $this->logger->error('IntegrationController::reorderIntegrations — failed', [
                'team_id'   => $teamId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}
