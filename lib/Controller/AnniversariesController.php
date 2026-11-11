<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\AnniversaryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class AnniversariesController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $session,
        private AnniversaryService $anniversaries,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function celebrations(): JSONResponse {
        $uid = $this->session->getUser()?->getUID();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        return new JSONResponse($this->anniversaries->celebrations($uid));
    }

    #[NoAdminRequired]
    public function acknowledge(string $teamId = '', int $years = 0): JSONResponse {
        $uid = $this->session->getUser()?->getUID();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->anniversaries->acknowledge($uid, $teamId, $years)) {
            return new JSONResponse(['error' => 'No eligible anniversary'], Http::STATUS_BAD_REQUEST);
        }
        return new JSONResponse(['ok' => true]);
    }
}
