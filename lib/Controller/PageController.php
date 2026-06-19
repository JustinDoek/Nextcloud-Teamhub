<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\MemberService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

class PageController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private MemberService $memberService,
        private IURLGenerator $urlGenerator,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        Util::addScript('teamhub', 'teamhub');

        return new TemplateResponse('teamhub', 'main');
    }

    /**
     * GET /apps/teamhub/timeline/{teamId}
     *
     * Renders a standalone blank HTML page containing the visual timeline.
     * The page is intended to be loaded inside an <iframe> in the widget.
     * It fetches its own data from the timeline API using the existing session.
     *
     * Member check is enforced: non-members get a 403 before the page renders.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function timeline(string $teamId): TemplateResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
        } catch (\Exception $e) {
            return new TemplateResponse('teamhub', 'timeline', [
                'teamId'  => $teamId,
                'apiBase' => '',
                'error'   => 'Access denied',
            ], TemplateResponse::RENDER_AS_BLANK);
        }

        // Pass the NC web-root so the iframe JS can build API URLs that work
        // regardless of whether NC is installed in a subdirectory.
        $apiBase = rtrim($this->urlGenerator->getBaseUrl(), '/');

        return new TemplateResponse('teamhub', 'timeline', [
            'teamId'  => $teamId,
            'apiBase' => $apiBase,
            'error'   => null,
        ], TemplateResponse::RENDER_AS_BLANK);
    }
}

