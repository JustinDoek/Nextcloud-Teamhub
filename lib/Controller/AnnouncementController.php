<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\AnnouncementService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * In-app announcements from TeamHub HQ to unlicensed instances.
 *
 * Every endpoint is #[NoAdminRequired] — announcements can target admins
 * or everyone, and the service layer filters by role. All three endpoints
 * require an authenticated user; none is public.
 *
 * CSRF-protected by default: the state-changing POST relies on NC's built-in
 * CSRF token, which the frontend axios client already forwards.
 */
class AnnouncementController extends Controller {

    public function __construct(
        string                      $appName,
        IRequest                    $request,
        private AnnouncementService $announcementService,
        private IUserSession        $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function list(): JSONResponse {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }
        return new JSONResponse([
            'announcements' => $this->announcementService->listVisibleFor($uid),
        ]);
    }

    #[NoAdminRequired]
    public function get(): JSONResponse {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $filename = trim((string) $this->request->getParam('filename', ''));
        if ($filename === '') {
            return new JSONResponse(['error' => 'filename required'], Http::STATUS_BAD_REQUEST);
        }
        $body = $this->announcementService->getBodyFor($uid, $filename);
        if ($body === null) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
        return new JSONResponse([
            'filename' => $filename,
            'body'     => $body,
        ]);
    }

    /**
     * POST /api/v1/announcements/seen
     * Body: { filename: string, event: 'shown'|'read'|'ack'|'optout' }
     *
     * v4.13.56 — the forced popup's counters. Always 200 with what was
     * recorded: a refused event (unknown filename, unknown event, this user
     * already counted) is not an error the administrator reading the message
     * should be shown, and the body says `recorded: false` for anything that
     * wants to know.
     *
     * #[NoAdminRequired] like the rest of this controller. The popup itself is
     * only drawn for instance admins, but the service answers "may this user
     * see this announcement" and that is the boundary that matters here; an
     * extra admin gate would be a second copy of the audience rule.
     */
    #[NoAdminRequired]
    public function seen(): JSONResponse {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $filename = trim((string) $this->request->getParam('filename', ''));
        $event    = trim((string) $this->request->getParam('event', ''));
        if ($filename === '' || $event === '') {
            return new JSONResponse(['error' => 'filename and event required'], Http::STATUS_BAD_REQUEST);
        }
        return new JSONResponse([
            'recorded' => $this->announcementService->recordPopupEvent($uid, $filename, $event),
        ]);
    }

    #[NoAdminRequired]
    public function dismiss(): JSONResponse {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $filename = trim((string) $this->request->getParam('filename', ''));
        if ($filename === '') {
            return new JSONResponse(['error' => 'filename required'], Http::STATUS_BAD_REQUEST);
        }
        $ok = $this->announcementService->dismiss($uid, $filename);
        if (!$ok) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
        return new JSONResponse(['dismissed' => true]);
    }
}
