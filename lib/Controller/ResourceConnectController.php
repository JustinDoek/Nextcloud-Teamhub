<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\ResourceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * HTTP controller for connecting existing app resources to a team.
 *
 *   POST /api/v1/teams/{teamId}/resources/{app}/connect
 *     Body: { resourceId: <int> }
 *     Inserts the share/ACL row granting the team's circle access to the
 *     specified resource and registers it in teamhub_team_app_resources.
 *
 * Allowed apps: 'talk', 'files', 'calendar', 'deck'. Anything else returns 400.
 *
 * Auth: requires team-admin level (enforced by MemberService::requireAdminLevel).
 * Each sub-service additionally re-verifies the user owns the specified
 * resource ID, preventing forged resource ID attacks.
 *
 * NOTE: teamhub_team_apps.enabled is no longer written for resource-backed apps.
 * Presence is derived from active rows in teamhub_team_app_resources.
 */
class ResourceConnectController extends Controller {

    private const ALLOWED_APPS = ['talk', 'files', 'calendar', 'deck'];

    public function __construct(
        string $appName,
        IRequest $request,
        private MemberService $memberService,
        private ResourceService $resourceService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * POST /api/v1/teams/{teamId}/resources/{app}/connect — team admin required.
     *
     * Body must include `resourceId` (int). Returns the connect-result from
     * the relevant sub-service.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function connect(string $teamId, string $app): JSONResponse {
        try {
            // 1. Validate app
            if (!in_array($app, self::ALLOWED_APPS, true)) {
                return new JSONResponse(
                    ['error' => 'Unknown app: ' . $app, 'allowed' => self::ALLOWED_APPS],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // 2. Validate input
            $body       = $this->request->getParams();
            $resourceId = isset($body['resourceId']) ? (int)$body['resourceId'] : 0;
            if ($resourceId <= 0) {
                return new JSONResponse(
                    ['error' => 'resourceId is required and must be a positive integer'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // 3. Authorisation — team-admin level required
            $this->memberService->requireAdminLevel($teamId);

            // 4. Perform the connect.
            //    ResourceService::connectExistingResource() handles both the
            //    NC-side share/ACL write and the teamhub_team_app_resources row.
            $result = $this->resourceService->connectExistingResource($teamId, $app, $resourceId);

            if (empty($result['success'])) {
                return new JSONResponse($result, Http::STATUS_BAD_REQUEST);
            }

            return new JSONResponse($result);

        } catch (\Exception $e) {
            $this->logger->warning('[TeamHub][ResourceConnectController] connect failed', [
                'teamId' => $teamId, 'app' => $app,
                'error' => $e->getMessage(), 'app_id' => Application::APP_ID,
            ]);
            $status = (str_contains($e->getMessage(), 'permissions') || str_contains($e->getMessage(), 'member'))
                ? Http::STATUS_FORBIDDEN
                : Http::STATUS_BAD_REQUEST;
            return new JSONResponse(['error' => $e->getMessage()], $status);
        }
    }
}
