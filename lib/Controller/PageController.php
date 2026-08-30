<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\DateContextService;
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
        private DateContextService $dateContext,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        Util::addScript('teamhub', 'teamhub');
        $this->dateContext->provideInitialState();

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
        // RENDER_AS_BLANK skips NC's page head, so initial state never reaches
        // this template — the locale and zone travel as ordinary template vars.
        $dateContext = $this->dateContext->toArray();

        try {
            $this->memberService->requireMemberLevel($teamId);
        } catch (\Exception $e) {
            return new TemplateResponse('teamhub', 'timeline', [
                'teamId'    => $teamId,
                'apiUrl'    => '',
                'webRoot'   => '',
                'urlPrefix' => '',
                'error'     => 'Access denied',
                'locale'    => $dateContext['locale'],
                'timezone'  => $dateContext['timezone'],
            ], TemplateResponse::RENDER_AS_BLANK);
        }

        // GitHub #91 — the page used to assemble this itself, as
        // getBaseUrl() . '/apps/teamhub/api/v1/…'. getBaseUrl() carries the web
        // root but never '/index.php', so on an install without pretty URLs the
        // request bypassed the front controller and never reached this app.
        // linkToRoute() asks the router, which inserts '/index.php' exactly when
        // the install needs it.
        $apiUrl = $this->urlGenerator->linkToRoute('teamhub.team.getTimeline', ['teamId' => $teamId]);

        // Event chips link into other apps through paths the API returns web-root
        // relative ('/apps/deck/board/…'), so they need the same treatment. This
        // page cannot read OC.config.modRewriteWorking the way @nextcloud/router
        // does — RENDER_AS_BLANK omits the head that defines it — so the prefix is
        // computed here and handed over. It is read back off a generated route
        // rather than from htaccess.IgnoreFrontController, so the router stays the
        // only thing deciding whether '/index.php' belongs in a URL.
        $webRoot   = $this->urlGenerator->getWebroot();
        $appIndex  = $this->urlGenerator->linkToRoute('teamhub.page.index');
        $marker    = '/apps/' . $this->appName . '/';
        $cut       = strrpos($appIndex, $marker);
        $urlPrefix = $cut === false ? $webRoot : substr($appIndex, 0, $cut);

        return new TemplateResponse('teamhub', 'timeline', [
            'teamId'    => $teamId,
            'apiUrl'    => $apiUrl,
            'webRoot'   => $webRoot,
            'urlPrefix' => $urlPrefix,
            'error'     => null,
            'locale'    => $dateContext['locale'],
            'timezone'  => $dateContext['timezone'],
        ], TemplateResponse::RENDER_AS_BLANK);
    }
}

