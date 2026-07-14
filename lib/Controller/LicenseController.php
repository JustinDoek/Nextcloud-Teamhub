<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\BackgroundJob\SendTelemetryJob;
use OCA\TeamHub\Exception\TrialRequestException;
use OCA\TeamHub\Service\LicenseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Admin-only license management endpoints (v3.100.0, Track F).
 *
 * Endpoints:
 *  - GET  /api/v1/admin/license           — current license status envelope
 *  - PUT  /api/v1/admin/license           — save a new JWT (verified before persist)
 *  - POST /api/v1/admin/license/refresh   — manually trigger SendTelemetryJob
 *
 * Everything gated with #[AuthorizedAdminSetting] so delegated TeamHub
 * admins (see AdminSettings) can manage licenses without needing full
 * NC admin.
 */
class LicenseController extends Controller {

    public function __construct(
        string                  $appName,
        IRequest                $request,
        private LicenseService  $licenseService,
        private IJobList        $jobList,
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

    /**
     * POST /api/v1/admin/license/refresh
     * Schedules SendTelemetryJob to run on the next cron tick. Used by
     * the "Refresh now" button in Admin settings — lets an admin verify
     * connectivity without waiting for the daily sweep.
     *
     * Air-gapped licenses receive a 400 — telemetry doesn't apply.
     * Unlicensed instances also receive a 400 — nothing to refresh.
     */
    /**
     * POST /api/v1/admin/license/trial
     *
     * v3.100.2 — Requests a 14-day Connected trial license from the
     * licensing back-end (server-to-server, browser never sees the URL)
     * and installs the returned JWT. Returns the fresh license status
     * envelope so the frontend can refresh without a second roundtrip.
     *
     * The one-shot per-UUID gate lives on the licensing back-end. This
     * endpoint just relays and installs.
     */
    /**
     * M-2 — NC-side throttle in addition to the licensing back-end's
     * per-IP 10/hour cap. Prevents an attacker who cannot reach the
     * back-end (e.g. outbound DNS blocked in a corporate LAN) from
     * hammering the local endpoint and consuming clientService
     * connections. Five requests per admin per hour is generous for a
     * one-shot-per-UUID trial.
     */
    #[UserRateLimit(limit: 5, period: 3600)]
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function requestTrial(): JSONResponse {
        try {
            $this->licenseService->requestTrial();
        } catch (TrialRequestException $e) {
            // TrialRequestException carries the back-end's HTTP status
            // directly, so no message-string parsing is needed at the
            // controller boundary.
            return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus());
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][LicenseController] trial request unexpected error: ' . $e->getMessage(), [
                'app' => 'teamhub',
                'exception' => $e,
            ]);
            return new JSONResponse(['error' => 'Could not start trial.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        return new JSONResponse($this->licenseService->getStatus());
    }

    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function refresh(): JSONResponse {
        $status = $this->licenseService->getStatus();
        if (!$status['hasKey']) {
            return new JSONResponse(['error' => 'No license key saved.'], Http::STATUS_BAD_REQUEST);
        }
        if (($status['kind'] ?? null) === 'airgapped') {
            return new JSONResponse(
                ['error' => 'Air-gapped licenses do not use telemetry.'],
                Http::STATUS_BAD_REQUEST,
            );
        }
        // add() is idempotent for TimedJob subclasses — no duplicate rows.
        $this->jobList->add(SendTelemetryJob::class);
        return new JSONResponse(['scheduled' => true]);
    }
}
