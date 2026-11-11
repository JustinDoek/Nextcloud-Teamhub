<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\IntegrationAdminService;
use OCA\TeamHub\Service\IntegrationTemplateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Integration governance — Nextcloud administrator endpoints (v4.13.23).
 *
 * The two decisions only a Nextcloud administrator makes:
 *
 *   GET    /api/v1/admin/integrations                            list, with state
 *   GET    /api/v1/admin/integrations/{pkgId}/disable-preview    impact of disabling
 *   POST   /api/v1/admin/integrations/{pkgId}/enable             enable
 *   POST   /api/v1/admin/integrations/{pkgId}/disable            disable
 *   GET    /api/v1/admin/integrations/templates                  template inclusion matrix
 *   GET    /api/v1/admin/integrations/templates/{templateKey}/preview/{pkgId}
 *                                                                impact of a template change
 *   POST   /api/v1/admin/integrations/templates/{templateKey}/include/{pkgId}
 *   DELETE /api/v1/admin/integrations/templates/{templateKey}/include/{pkgId}
 *   POST   /api/v1/admin/integrations/templates/{templateKey}/reconcile/{pkgId}
 *
 * ## There is deliberately nothing here a team administrator can reach
 *
 * Every method carries `#[AuthorizedAdminSetting]` **and** the service calls
 * `requireNcAdmin()` — the attribute is the route gate and the service is the
 * real boundary, following `PolicyController` and `TeamImportController`
 * verbatim. "The frontend will not call this" is not a security boundary.
 *
 * The consequence is deliberate and the same one `PolicyController` documents:
 * a *delegated* TeamHub admin passes the attribute and is then refused by the
 * service, which wants full `IGroupManager::isAdmin`. Integration enablement
 * and template inclusion decide what every team on the instance is connected
 * to; that is not a delegated decision.
 *
 * ## No mutating route is CSRF-exempt
 *
 * `#[NoCSRFRequired]` appears on the GET methods only, because the
 * administration panel fetches them. The old external-registration route was
 * CSRF-exempt and mutating, which it got away with because external apps
 * called it from PHP hooks; nothing here has that excuse.
 */
class IntegrationAdminController extends Controller {
    use ExceptionResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private IntegrationAdminService    $adminService,
        private IntegrationTemplateService $templateService,
        private LoggerInterface            $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // ------------------------------------------------------------------
    // Enablement
    // ------------------------------------------------------------------

    /** GET /api/v1/admin/integrations */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function index(): JSONResponse {
        try {
            return new JSONResponse($this->adminService->listIntegrations());
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not list the integrations.');
        }
    }

    /** GET /api/v1/admin/integrations/{pkgId}/disable-preview */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function disablePreview(int $pkgId): JSONResponse {
        try {
            return new JSONResponse($this->adminService->previewDisable($pkgId));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not build the impact preview.', [
                'pkg_id' => $pkgId,
            ]);
        }
    }

    /** POST /api/v1/admin/integrations/{pkgId}/enable */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function enable(int $pkgId): JSONResponse {
        try {
            return new JSONResponse($this->adminService->enable($pkgId));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not enable the integration.', [
                'pkg_id' => $pkgId,
            ]);
        }
    }

    /** POST /api/v1/admin/integrations/{pkgId}/disable */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function disable(int $pkgId): JSONResponse {
        try {
            return new JSONResponse($this->adminService->disable($pkgId));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not disable the integration.', [
                'pkg_id' => $pkgId,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Template inclusion
    // ------------------------------------------------------------------

    /** GET /api/v1/admin/integrations/templates */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function templates(): JSONResponse {
        try {
            return new JSONResponse($this->templateService->listTemplateInclusions());
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not list the template inclusions.');
        }
    }

    /** GET /api/v1/admin/integrations/templates/{templateKey}/preview/{pkgId} */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function templatePreview(string $templateKey, int $pkgId): JSONResponse {
        // `include` is the change being previewed: true for adding, false for
        // removing. A preview of a change nobody has made yet cannot read it
        // off the stored state.
        $including = filter_var(
            $this->request->getParam('include', 'true'),
            FILTER_VALIDATE_BOOLEAN,
        );

        try {
            return new JSONResponse(
                $this->templateService->previewTemplateChange($templateKey, $pkgId, $including),
            );
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not build the template impact preview.', [
                'template_key' => $templateKey, 'pkg_id' => $pkgId,
            ]);
        }
    }

    /** POST /api/v1/admin/integrations/templates/{templateKey}/include/{pkgId} */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function includeInTemplate(string $templateKey, int $pkgId): JSONResponse {
        try {
            return new JSONResponse($this->templateService->includeInTemplate($templateKey, $pkgId));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not include the integration in the template.', [
                'template_key' => $templateKey, 'pkg_id' => $pkgId,
            ]);
        }
    }

    /** DELETE /api/v1/admin/integrations/templates/{templateKey}/include/{pkgId} */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function removeFromTemplate(string $templateKey, int $pkgId): JSONResponse {
        try {
            return new JSONResponse($this->templateService->removeFromTemplate($templateKey, $pkgId));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not remove the integration from the template.', [
                'template_key' => $templateKey, 'pkg_id' => $pkgId,
            ]);
        }
    }

    /**
     * POST /api/v1/admin/integrations/templates/{templateKey}/reconcile/{pkgId}
     *
     * Body: { "teamIds": ["…"] } — optional. Omitted means every team of the
     * template.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function reconcile(string $templateKey, int $pkgId): JSONResponse {
        $body    = $this->request->getParams();
        $teamIds = (isset($body['teamIds']) && is_array($body['teamIds'])) ? $body['teamIds'] : null;

        $action = isset($body['action']) ? (string)$body['action'] : 'added';
        if ($action !== 'added' && $action !== 'removed') {
            return new JSONResponse(
                ['error' => "action must be 'added' or 'removed'."],
                Http::STATUS_BAD_REQUEST,
            );
        }

        try {
            return new JSONResponse(
                $this->templateService->reconcile($templateKey, $pkgId, $action, $teamIds),
            );
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not reconcile the affected teams.', [
                'template_key' => $templateKey, 'pkg_id' => $pkgId, 'action' => $action,
            ]);
        }
    }
}
