<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\LicenseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Admin-only license management endpoints (v3.100.0, Track F).
 *
 * Endpoints:
 *  - GET  /api/v1/admin/license   — current license status envelope
 *  - PUT  /api/v1/admin/license   — save a new JWT (verified before persist)
 *
 * Everything gated with #[AuthorizedAdminSetting] so delegated TeamHub
 * admins (see AdminSettings) can manage licenses without needing full
 * NC admin.
 *
 * There is no trial-request endpoint (v4.3.22+). Trials are issued by
 * hand from the licensing dashboard in response to an email from the
 * customer's admin, and the customer pastes the resulting JWT into the
 * License tab. See the "Request trial by email" mailto in AdminSettings.
 */
class LicenseController extends Controller {

    public function __construct(
        string                  $appName,
        IRequest                $request,
        private LicenseService  $licenseService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function getStatus(): JSONResponse {
        return new JSONResponse($this->licenseService->getStatus());
    }

    /**
     * GET /api/v1/license/entitlements
     *
     * Public — any logged-in user can call it. Returns ONLY what the
     * CreateTeamView wizard needs to decide whether to grey out the
     * Advanced-project tile. Deliberately does not expose seat counts,
     * customer name, license id, telemetry payloads, or anything else
     * from the full status envelope — those are admin-only.
     *
     * v3.100.1 — added so the wizard can block Advanced upfront rather
     * than letting the user click through three steps and failing on
     * submit with a 403.
     *
     * @return JSONResponse{
     *   canCreateAdvanced: bool,
     *   enforcementLevel: 'none'|'grace'|'soft-lock'|'unlicensed'
     * }
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function entitlements(): JSONResponse {
        $level = $this->licenseService->getEnforcementLevel();
        return new JSONResponse([
            'canCreateAdvanced' => $this->licenseService->allowsAdvancedCreation(),
            'enforcementLevel'  => $level,
        ]);
    }

    /**
     * PUT /api/v1/admin/license
     * Body: { jwt: "<full JWT string>" }
     *
     * Verifies against embedded public key + instance UUID before saving.
     * On failure, returns 400 with the human-readable reason so the admin
     * UI can render "License key is issued for a different instance." /
     * "License key has expired." etc.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function saveKey(string $jwt = ''): JSONResponse {
        $jwt = trim($jwt);
        if ($jwt === '') {
            return new JSONResponse(['error' => 'License key is required.'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $this->licenseService->saveKey($jwt);
        } catch (\RuntimeException $e) {
            // saveKey re-verifies inside the service; RuntimeException
            // messages here are already admin-facing (produced by
            // Jwt::verifyRs256 + LicenseService::verifyJwt).
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][LicenseController] unexpected saveKey error: ' . $e->getMessage(), [
                'app' => 'teamhub',
                'exception' => $e,
            ]);
            return new JSONResponse(['error' => 'Could not save license key.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        // Return the fresh status so the frontend can update its UI
        // without an extra roundtrip.
        return new JSONResponse($this->licenseService->getStatus());
    }

}
