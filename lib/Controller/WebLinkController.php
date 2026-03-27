<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\WebLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class WebLinkController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private WebLinkService $webLinkService,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listLinks(string $teamId): JSONResponse {
        try {
            $links = $this->webLinkService->getTeamLinks($teamId);
            return new JSONResponse($links);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function createLink(string $teamId, string $title, string $url): JSONResponse {
        try {
            $link = $this->webLinkService->createLink($teamId, $title, $url);
            return new JSONResponse($link, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function updateLink(string $teamId, int $linkId, string $title, string $url, int $sortOrder): JSONResponse {
        try {
            $link = $this->webLinkService->updateLink($linkId, $title, $url, $sortOrder);
            return new JSONResponse($link);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function deleteLink(string $teamId, int $linkId): JSONResponse {
        try {
            $this->webLinkService->deleteLink($linkId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}
