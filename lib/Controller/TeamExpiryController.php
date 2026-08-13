<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\TeamExpiryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Team-side expiration endpoints (v4.6.13).
 *
 * The Nextcloud-admin half of this feature lives on MaintenanceController
 * behind `#[AuthorizedAdminSetting]`. This controller is the other half: what
 * a team's own admins may do about their team's expiry, which is read it and
 * ask for more time.
 *
 * Its own class rather than four more methods on `TeamController` — that file
 * is already 1,300 lines and carries thirteen hand-rolled catch blocks that
 * return 500 where they mean 403 (HANDOFF.md, open issues). Adding to it would
 * mean either matching that pattern or leaving the file inconsistent with
 * itself.
 *
 * Every method is `#[NoAdminRequired]` — these are for ordinary users — and
 * every one is gated inside `TeamExpiryService` by
 * `MemberService::requireAdminLevel()`. The controller itself performs no
 * authorisation, which is deliberate: one gate, in the service, where the
 * business rule lives.
 */
class TeamExpiryController extends Controller {

    use ExceptionResponseTrait;

    public function __construct(
        string                    $appName,
        IRequest                  $request,
        private TeamExpiryService $expiryService,
        private LoggerInterface   $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/teams/{teamId}/expiry
     *
     * The team's expiry, whether its template may have one, the most recent
     * extension request with its outcome, and the instance's warning window.
     * Team-admin gated in the service.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function status(string $teamId): JSONResponse {
        try {
            return new JSONResponse($this->expiryService->getTeamStatus($teamId));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load the expiration status', ['teamId' => $teamId]);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/expiry/request
     * Body: { "proposedOn": "YYYY-MM-DD", "reason": "…" }
     *
     * Ask a Nextcloud administrator to push the expiry back. The proposed date
     * must be later than the current one; the service rejects anything else.
     */
    #[NoAdminRequired]
    public function requestExtension(string $teamId, string $proposedOn = '', string $reason = ''): JSONResponse {
        if (trim($proposedOn) === '') {
            return new JSONResponse(['error' => 'proposedOn is required'], Http::STATUS_BAD_REQUEST);
        }
        try {
            return new JSONResponse([
                'request' => $this->expiryService->requestExtension($teamId, $proposedOn, $reason),
            ]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to submit the request', ['teamId' => $teamId]);
        }
    }

    /**
     * DELETE /api/v1/teams/{teamId}/expiry/request
     * Withdraw the team's own request while it is still undecided.
     */
    #[NoAdminRequired]
    public function withdrawRequest(string $teamId): JSONResponse {
        try {
            $this->expiryService->withdrawRequest($teamId);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to withdraw the request', ['teamId' => $teamId]);
        }
    }
}
