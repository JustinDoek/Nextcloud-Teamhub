<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\SharedTeamResourceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The "Shared with team" card (v4.20.39): what other apps registered for a
 * team through Nextcloud's Teams API. `SharedTeamResourceService` has the
 * rules; this endpoint is read-only and writes nothing anywhere.
 */
class TeamSharedResourceController extends Controller {
    use ExceptionResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private SharedTeamResourceService $sharedTeamResourceService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/teams/{teamId}/shared-resources
     *
     * Answers `{ available, providers: [{ id, name, icon, resources: [{ id,
     * label, url, icon }] }], failed: [{ id, name }] }`; `icon` is
     * `{ type: emoji|svg|url, value }` or null.
     *
     * SEC: team membership required, answered by Nextcloud core for the
     * logged-in user only (403 otherwise). A provider that throws is listed in
     * `failed` and does not fail the request.
     *
     * SEC (v4.20.40): the user is the session user, always. This route takes
     * no uid in its path, query or body, and none may be added: there is no
     * way to ask for another user's view of a team. The answer is per user, so
     * it is marked `no-store` explicitly rather than trusting the default.
     */
    #[NoAdminRequired]
    public function getSharedResources(string $teamId): JSONResponse {
        try {
            $response = new JSONResponse($this->sharedTeamResourceService->forTeam($teamId));
            $response->cacheFor(0);
            return $response;
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load what is shared with this team', [
                'teamId' => $teamId,
            ]);
        }
    }
}
