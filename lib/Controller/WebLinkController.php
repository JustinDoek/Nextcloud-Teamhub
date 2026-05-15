<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\MessageService;
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
        private MemberService $memberService,
        private MessageService $messageService,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Check that the current user meets the configured linkMinLevel for this team.
     * Throws if the user does not meet the required level.
     */
    private function requireLinkLevel(string $teamId): void {
        $required = $this->messageService->getLinkMinLevelInt($teamId);
        if ($required <= 1) {
            $this->memberService->requireMemberLevel($teamId);
        } elseif ($required <= 4) {
            $this->memberService->requireModeratorLevel($teamId);
        } else {
            $this->memberService->requireAdminLevel($teamId);
        }
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
            $this->requireLinkLevel($teamId);
            $link = $this->webLinkService->createLink($teamId, $title, $url);
            return new JSONResponse($link, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            $status = str_contains($e->getMessage(), 'permissions') || str_contains($e->getMessage(), 'member')
                ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
            return new JSONResponse(['error' => $e->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    public function updateLink(string $teamId, int $linkId, string $title, string $url, int $sortOrder): JSONResponse {
        try {
            $this->requireLinkLevel($teamId);
            $link = $this->webLinkService->updateLink($linkId, $title, $url, $sortOrder);
            return new JSONResponse($link);
        } catch (\Exception $e) {
            $status = str_contains($e->getMessage(), 'permissions') || str_contains($e->getMessage(), 'member')
                ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
            return new JSONResponse(['error' => $e->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    public function deleteLink(string $teamId, int $linkId): JSONResponse {
        try {
            $this->requireLinkLevel($teamId);
            $this->webLinkService->deleteLink($linkId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            $status = str_contains($e->getMessage(), 'permissions') || str_contains($e->getMessage(), 'member')
                ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
            return new JSONResponse(['error' => $e->getMessage()], $status);
        }
    }
}
