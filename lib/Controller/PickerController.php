<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\CalendarService;
use OCA\TeamHub\Service\DeckService;
use OCA\TeamHub\Service\FilesService;
use OCA\TeamHub\Service\GroupFolderService;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\TalkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * HTTP controller for resource pickers.
 *
 * Exposes per-app GET endpoints that list resources owned by the
 * authenticated user, suitable for populating a "Connect existing" picker
 * in the Create Team wizard and Manage Team > Settings > Apps.
 *
 *   GET /api/v1/pickers/calendar   — user's owned calendars
 *   GET /api/v1/pickers/deck       — user's owned Deck boards
 *   GET /api/v1/pickers/talk       — user's owned/moderated Talk rooms
 *
 * Files uses NC's client-side file picker (getFilePickerBuilder from
 * @nextcloud/dialogs); no server endpoint is needed for that.
 *
 * Auth: requires an authenticated NC user. Listing is scoped to the
 * caller's UID so users only ever see their own resources.
 */
class PickerController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private CalendarService $calendarService,
        private DeckService $deckService,
        private TalkService $talkService,
        private FilesService $filesService,
        private GroupFolderService $groupFolderService,
        private MemberService $memberService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /** GET /api/v1/pickers/calendar — list calendars owned by the current user. */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listCalendars(): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
            }
            $resources = $this->calendarService->listOwnedCalendars($user->getUID());
            return new JSONResponse(['resources' => $resources]);
        } catch (\Throwable $e) {
            $this->logger->error('PickerController::listCalendars failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to list calendars'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /** GET /api/v1/pickers/deck — list Deck boards owned by the current user. */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listDeckBoards(): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
            }
            $resources = $this->deckService->listOwnedBoards($user->getUID());
            return new JSONResponse(['resources' => $resources]);
        } catch (\Throwable $e) {
            $this->logger->error('PickerController::listDeckBoards failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to list Deck boards'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /** GET /api/v1/pickers/talk — list Talk rooms owned/moderated by the current user. */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listTalkRooms(): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
            }
            $resources = $this->talkService->listOwnedRooms($user->getUID());
            return new JSONResponse(['resources' => $resources]);
        } catch (\Throwable $e) {
            $this->logger->error('PickerController::listTalkRooms failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to list Talk rooms'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/v1/pickers/files?teamId={teamId}
     *
     * Returns available file folders to connect to the team, in two sections:
     *   1. Group Folders where the team's circle is already a member (type=group_folder) — top
     *   2. Shared folders owned by the current user not yet connected to any team (type=shared_folder)
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listFileFolders(): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
            }
            $teamId = (string)($this->request->getParam('teamId') ?? '');

            // Security: verify the requesting user is a member of the team before
            // exposing group folder names associated with its circle.
            if ($teamId !== '' && !$this->memberService->isCurrentUserDirectMember($teamId)) {
                return new JSONResponse(['error' => 'Not a team member'], Http::STATUS_FORBIDDEN);
            }

            // v3.100.9 — pass "team admin?" flag so the picker can offer
            // the "available group folders" section (reconnect / attach
            // to a new GF) only to admins. Members still see the
            // currently-attached GFs and their own shared folders.
            $isTeamAdmin = $teamId !== ''
                && $this->memberService->isCurrentUserTeamAdmin($teamId);

            $resources = $this->filesService->listConnectableFileFolders(
                $user->getUID(),
                $teamId,
                $this->groupFolderService,
                (string)($this->request->getParam('activeFilesType', 'none')),
                $isTeamAdmin
            );
            return new JSONResponse(['resources' => $resources]);
        } catch (\Throwable $e) {
            $this->logger->error('PickerController::listFileFolders failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to list file folders'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
