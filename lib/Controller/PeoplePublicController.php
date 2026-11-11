<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\PeopleDirectoryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class PeoplePublicController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private PeopleDirectoryService $people,
    ) { parent::__construct($appName, $request); }

    /** Inline native-profile section; never expose profile details to guests. */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function directory(string $userId): JSONResponse {
        $data = $this->people->profile($userId);
        if ($data === null) {
            return new JSONResponse(['error' => 'Not available'], Http::STATUS_FORBIDDEN);
        }
        return new JSONResponse($data);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function profile(string $userId): TemplateResponse {
        $data = $this->people->profile($userId);
        $result = new TemplateResponse('teamhub', 'people-profile', [
            'profile' => $data,
        ]);
        if ($data === null) {
            $result->setStatus(Http::STATUS_FORBIDDEN);
        }
        return $result;
    }
}
