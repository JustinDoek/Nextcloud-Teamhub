<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Constants\LayoutSchema;
use OCA\TeamHub\Service\LayoutDefaultService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * LayoutDefaultController — the default home layout for one kind of team
 * (v4.13.34, issue #109 phase 2).
 *
 *   GET    /api/v1/admin/policy/templates/{templateKey}/layout     read it
 *   PUT    /api/v1/admin/policy/templates/{templateKey}/layout     set it
 *   DELETE /api/v1/admin/policy/templates/{templateKey}/layout     clear it
 *
 * v4.13.36 — there was an instance-wide default too, behind these. It is gone:
 * Justin, 2026-10-28, *"this should be set per template and no default for all
 * templates"*. It was a third width to understand, to document and to keep
 * right, between the two anybody asked for, and nothing had been set in it.
 * A team whose template has no layout falls through to the one TeamHub ships.
 *
 * A team's **own** default is not here: it is a Manage team setting written by
 * a team admin, and it rides the dashboard-config endpoint it sits beside
 * (`TeamController::saveDashboardConfig`).
 *
 * ## The gate, and why it is only the attribute
 *
 * `#[AuthorizedAdminSetting]` and nothing else. Every other instance-governance
 * control in this app pairs that attribute with `requireNcAdmin()` in the
 * service, which refuses a *delegated* TeamHub admin — `PolicyController`'s
 * docblock explains that choice and calls widening it "a permission-model
 * decision, not a default".
 *
 * That decision has now been taken. Justin, 2026-10-27: *"If we have delegated
 * admins set for TeamHub they should be able to change all settings in
 * TeamHub."* So these routes are gated by the attribute alone, which is exactly
 * what admits an account delegated TeamHub's settings section.
 *
 * **This is the first surface built to that rule and the only one so far.**
 * Policy profiles, templates, the Teams-frontend switch and the rest still
 * refuse a delegated admin in their services. Bringing them across is a
 * session of its own — it touches every admin route rather than these six —
 * and it is logged in HANDOFF. A reader who finds the two gates disagreeing
 * has found that, not a mistake here.
 *
 * `#[NoCSRFRequired]` is on the read-only GETs only, as in `PolicyController`.
 */
class LayoutDefaultController extends Controller {

    use ExceptionResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private LayoutDefaultService $defaults,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // -------------------------------------------------------------------------
    // One kind of team's default
    // -------------------------------------------------------------------------

    /** GET /api/v1/admin/policy/templates/{templateKey}/layout */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function getTemplateDefault(string $templateKey): JSONResponse {
        try {
            // `shipped` rides along, and it is what makes the editor
            // possible: an administrator setting a default has no team's home
            // in front of them, so the editor has to be seeded from somewhere,
            // and the honest seed is the layout TeamHub ships. It is also what
            // the editor's Reset restores.
            return new JSONResponse([
                'default' => $this->defaults->forTemplate($templateKey),
                'shipped' => LayoutSchema::DEFAULT_LAYOUT,
            ]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not load the template layout', ['templateKey' => $templateKey]);
        }
    }

    /**
     * PUT /api/v1/admin/policy/templates/{templateKey}/layout
     * Body: { layout: [...], tabOrder: [...] }
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function saveTemplateDefault(string $templateKey): JSONResponse {
        try {
            $this->defaults->saveTemplate(
                $templateKey,
                $this->request->getParam('layout'),
                $this->request->getParam('tabOrder'),
            );
            return new JSONResponse(['default' => $this->defaults->forTemplate($templateKey)]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not save the template layout', ['templateKey' => $templateKey]);
        }
    }

    /** DELETE /api/v1/admin/policy/templates/{templateKey}/layout */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function clearTemplateDefault(string $templateKey): JSONResponse {
        try {
            $this->defaults->clearTemplate($templateKey);
            return new JSONResponse(['default' => null]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not clear the template layout', ['templateKey' => $templateKey]);
        }
    }
}
