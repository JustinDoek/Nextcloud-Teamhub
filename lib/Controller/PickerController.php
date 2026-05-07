<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\CalendarService;
use OCA\TeamHub\Service\DeckService;
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
}
