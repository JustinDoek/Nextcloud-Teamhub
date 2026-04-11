<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\IntegrationService;
use OCA\TeamHub\Service\MemberService;
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
 * External-app registration — NC admin required (no #[NoAdminRequired]):
 *   GET    /api/v1/ext/integrations                    — list all registered integrations (admin UI)
 *   POST   /api/v1/ext/integrations/register           — register or update an integration
 *   DELETE /api/v1/ext/integrations/{appId}            — deregister (cascade-deletes team opt-ins)
 *
 * Team render endpoints (any authenticated user):
 *   GET    /api/v1/teams/{teamId}/integrations          — all enabled integrations split by type
 *   GET    /api/v1/teams/{teamId}/integrations/widget-data/{registryId}  — fetch widget data
 *
 * Manage Team → Integrations tab (team admin required):
 *   GET    /api/v1/teams/{teamId}/integrations/registry                  — full list + enabled state
 *   POST   /api/v1/teams/{teamId}/integrations/{registryId}/toggle       — enable/disable
 *   PUT    /api/v1/teams/{teamId}/integrations/reorder                   — persist drag order
 */
class IntegrationController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IntegrationService $integrationService,
        private MemberService $memberService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // ------------------------------------------------------------------
    // External-app registration (NC admin required — no #[NoAdminRequired])
    // ------------------------------------------------------------------

    /** GET /api/v1/ext/integrations — NC admin required. */
    #[NoCSRFRequired]
    public function listRegisteredIntegrations(): JSONResponse {
        try {
            return new JSONResponse($this->integrationService->getFullRegistry());
        } catch (\Throwable $e) {
            $this->logger->error('IntegrationController::listRegisteredIntegrations — failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /** POST /api/v1/ext/integrations/register — NC admin required. */
    #[NoCSRFRequired]
    public function registerIntegration(): JSONResponse {
        try {
            $body = $this->request->getParams();

            $appId           = isset($body['app_id'])           ? trim((string)$body['app_id'])           : '';
            $integrationType = isset($body['integration_type']) ? trim((string)$body['integration_type']) : '';
            $title           = isset($body['title'])            ? trim((string)$body['title'])            : '';
            $description     = ($body['description'] ?? '') !== '' ? trim((string)$body['description']) : null;
            $icon            = ($body['icon']        ?? '') !== '' ? trim((string)$body['icon'])        : null;
            $phpClass        = ($body['php_class']   ?? '') !== '' ? trim((string)$body['php_class'])   : null;
            $iframeUrl       = ($body['iframe_url']  ?? '') !== '' ? trim((string)$body['iframe_url'])  : null;

            if ($appId === '' || $integrationType === '' || $title === '') {
                return new JSONResponse(
                    ['error' => 'app_id, integration_type and title are required'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $result = $this->integrationService->registerIntegration(
                appId:           $appId,
                integrationType: $integrationType,
                title:           $title,
                description:     $description,
                icon:            $icon,
                phpClass:        $phpClass,
                iframeUrl:       $iframeUrl,
                calledInProcess: false,
            );

            return new JSONResponse($result, Http::STATUS_OK);

        } catch (\Exception $e) {
            $this->logger->warning('IntegrationController::registerIntegration — failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /** DELETE /api/v1/ext/integrations/{appId} — NC admin required. */
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
                'app_id' => $appId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
            $status = str_contains($e->getMessage(), 'admin') ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
            return new JSONResponse(['error' => $e->getMessage()], $status);
        }
    }

    // ------------------------------------------------------------------
    // Team render endpoints (any authenticated user)
    // ------------------------------------------------------------------

    /** GET /api/v1/teams/{teamId}/integrations */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getEnabledIntegrations(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            return new JSONResponse($this->integrationService->getEnabledIntegrations($teamId));
        } catch (\Throwable $e) {
            $status = str_contains($e->getMessage(), 'member') || str_contains($e->getMessage(), 'permissions')
                ? Http::STATUS_FORBIDDEN : Http::STATUS_INTERNAL_SERVER_ERROR;
            $this->logger->error('IntegrationController::getEnabledIntegrations — failed', [
                'team_id' => $teamId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['widgets' => [], 'menu_items' => []], $status);
        }
    }

    /** GET /api/v1/teams/{teamId}/integrations/widget-data/{registryId} */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getWidgetData(string $teamId, int $registryId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            return new JSONResponse($this->integrationService->fetchWidgetData($teamId, $registryId));
        } catch (\Exception $e) {
            $status = str_contains($e->getMessage(), 'member') || str_contains($e->getMessage(), 'permissions')
                ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
            $this->logger->warning('IntegrationController::getWidgetData — failed', [
                'team_id' => $teamId, 'registry_id' => $registryId,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['items' => [], 'error' => $e->getMessage()], $status);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/integrations/action-form/{registryId}?actionId=xxx
     *
     * Returns the form definition for a named widget action.
     * IntegrationWidget renders this as a native NC modal.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getActionForm(string $teamId, int $registryId): JSONResponse {
        $actionId = trim((string)$this->request->getParam('actionId', ''));

        if ($actionId === '') {
            return new JSONResponse(['error' => 'actionId is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->memberService->requireMemberLevel($teamId);
            $form = $this->integrationService->fetchActionForm($teamId, $registryId, $actionId);
            return new JSONResponse($form);
        } catch (\Exception $e) {
            $status = str_contains($e->getMessage(), 'member') || str_contains($e->getMessage(), 'permissions')
                ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
            $this->logger->warning('IntegrationController::getActionForm — failed', [
                'team_id' => $teamId, 'registry_id' => $registryId,
                'action_id' => $actionId, 'error' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], $status);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/integrations/action-submit/{registryId}
     * Body: { "actionId": "new_item", "fields": { "title": "…", … } }
     *
     * Submits a completed action form to the widget's handleAction() method.
     */
    #[NoAdminRequired]
    public function submitAction(string $teamId, int $registryId): JSONResponse {
        $body     = $this->request->getParams();
        $actionId = trim((string)($body['actionId'] ?? ''));
        $fields   = (isset($body['fields']) && is_array($body['fields'])) ? $body['fields'] : [];

        if ($actionId === '') {
            return new JSONResponse(['error' => 'actionId is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->memberService->requireMemberLevel($teamId);
            $result = $this->integrationService->submitAction($teamId, $registryId, $actionId, $fields);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            $status = str_contains($e->getMessage(), 'member') || str_contains($e->getMessage(), 'permissions')
                ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
            $this->logger->warning('IntegrationController::submitAction — failed', [
                'team_id' => $teamId, 'registry_id' => $registryId,
                'action_id' => $actionId, 'error' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['success' => false, 'error' => $e->getMessage()], $status);
        }
    }

    // ------------------------------------------------------------------
    // Manage Team → Integrations tab (team admin required)
    // ------------------------------------------------------------------

    /** GET /api/v1/teams/{teamId}/integrations/registry */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getIntegrationRegistry(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            return new JSONResponse($this->integrationService->getRegistryForTeam($teamId));
        } catch (\Throwable $e) {
            $status = str_contains($e->getMessage(), 'member') || str_contains($e->getMessage(), 'permissions')
                ? Http::STATUS_FORBIDDEN : Http::STATUS_INTERNAL_SERVER_ERROR;
            $this->logger->error('IntegrationController::getIntegrationRegistry — failed', [
                'team_id' => $teamId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], $status);
        }
    }

    /** POST /api/v1/teams/{teamId}/integrations/{registryId}/toggle — team admin required. */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function toggleIntegration(string $teamId, int $registryId): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);

            $body   = $this->request->getParams();
            $enable = isset($body['enable']) ? filter_var($body['enable'], FILTER_VALIDATE_BOOLEAN) : true;

            return new JSONResponse($this->integrationService->toggleIntegration($teamId, $registryId, $enable));
        } catch (\Exception $e) {
            $this->logger->warning('IntegrationController::toggleIntegration — failed', [
                'team_id' => $teamId, 'registry_id' => $registryId,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            $status = str_contains($e->getMessage(), 'permissions') || str_contains($e->getMessage(), 'member')
                ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
            return new JSONResponse(['error' => $e->getMessage()], $status);
        }
    }

    /** PUT /api/v1/teams/{teamId}/integrations/reorder — team admin required. */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function reorderIntegrations(string $teamId): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);

            $body  = $this->request->getParams();
            $order = isset($body['order']) && is_array($body['order']) ? $body['order'] : [];

            if (empty($order)) {
                return new JSONResponse(['error' => 'order array is required'], Http::STATUS_BAD_REQUEST);
            }

            return new JSONResponse($this->integrationService->reorderIntegrations($teamId, $order));
        } catch (\Exception $e) {
            $this->logger->error('IntegrationController::reorderIntegrations — failed', [
                'team_id' => $teamId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
            $status = str_contains($e->getMessage(), 'permissions') || str_contains($e->getMessage(), 'member')
                ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
            return new JSONResponse(['error' => $e->getMessage()], $status);
        }
    }
}
