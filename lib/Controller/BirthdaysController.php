<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\BirthdaysService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class BirthdaysController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $session,
        private BirthdaysService $birthdays,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function upcoming(string $teamId): JSONResponse {
        if ($this->session->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            return new JSONResponse($this->birthdays->upcoming($teamId));
        } catch (\OCA\TeamHub\Exception\AccessDeniedException $e) {
            return new JSONResponse(['error' => 'Team not found'], Http::STATUS_FORBIDDEN);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function celebration(): JSONResponse {
        $uid = $this->session->getUser()?->getUID();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        return new JSONResponse($this->birthdays->myCelebration($uid));
    }

    #[NoAdminRequired]
    public function acknowledge(): JSONResponse {
        $uid = $this->session->getUser()?->getUID();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $this->birthdays->acknowledge($uid);
        return new JSONResponse(['ok' => true]);
    }
}
