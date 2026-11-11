<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\PeopleProfileService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/** Self-service profile only. There is deliberately no endpoint for other users. */
class PeopleProfileController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $session,
        private PeopleProfileService $profiles,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(string $department = ''): JSONResponse {
        $uid = $this->session->getUser()?->getUID();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        return new JSONResponse($this->profiles->get($uid, $department));
    }

    /** PUT /api/v1/people/profile — CSRF protected by Nextcloud. */
    #[NoAdminRequired]
    public function save(array $profile = []): JSONResponse {
        $uid = $this->session->getUser()?->getUID();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            return new JSONResponse($this->profiles->save($uid, $profile));
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}
