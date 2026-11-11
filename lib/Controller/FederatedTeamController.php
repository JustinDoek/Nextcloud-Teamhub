<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\FederatedTeamService;
use OCA\TeamHub\Service\MemberService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * A team this server does not own (v4.14.15, DESIGN §2.256).
 *
 * One route, read-only: the team's own facts, the server that owns it, and the
 * roster Circles synced here. It is what `RemoteTeamView` draws, and it is
 * everything this instance knows about such a team, because every module keeps
 * its data in TeamHub's tables on the owning instance.
 *
 * Deliberately not on `TeamController`. Every route there answers for a team
 * this server owns and runs through the registry gate; a second meaning for
 * `{teamId}` on the same controller is how one of those routes eventually
 * starts answering for a remote team by accident. When the TeamHub-to-TeamHub
 * proxy arrives, this is the controller it grows on.
 *
 * `#[NoAdminRequired]`, and the gate is the service's: the administrator's
 * switch must be on, the circle must be a copy owned by another instance with
 * no registry row, and the caller must be in it. A team that fails any of
 * those answers 404 and says nothing about which of them it was.
 */
class FederatedTeamController extends Controller {

    use ExceptionResponseTrait;

    public function __construct(
        string                       $appName,
        IRequest                     $request,
        private FederatedTeamService $federatedTeams,
        private MemberService        $memberService,
        private IUserSession         $userSession,
        private IDBConnection        $db,
        private LoggerInterface      $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/federated-teams/{teamId}
     *
     * @return JSONResponse the team, or 404 when it is not a visible synced copy
     */
    #[NoAdminRequired]
    public function show(string $teamId): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
            }

            $uid      = $user->getUID();
            $singleId = $this->memberService->resolveUserSingleId($uid, $this->db);
            $team     = $this->federatedTeams->describe($teamId, $uid, $singleId);

            if ($team === null) {
                // The same answer a deep link to a deleted team gets. Not a
                // 403: whether the circle exists at all is not something to
                // disclose to somebody who is not in it.
                return new JSONResponse(['error' => 'Team not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse(['team' => $team]);

        } catch (\Throwable $e) {
            return $this->exceptionResponse(
                $e,
                'Failed to load the team',
                ['teamId' => $teamId, 'app' => Application::APP_ID],
            );
        }
    }
}
