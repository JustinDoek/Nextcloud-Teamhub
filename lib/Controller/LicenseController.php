<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Exception\LicensingRequestException;
use OCA\TeamHub\Service\LicenseService;
use OCA\TeamHub\Service\QuoteRequestService;
use OCA\TeamHub\Service\TrialRequestService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Admin-only license management endpoints (v3.100.0, Track F).
 *
 * Endpoints:
 *  - GET  /api/v1/admin/license   — current license status envelope
 *  - PUT  /api/v1/admin/license   — save a new JWT (verified before persist)
 *  - POST /api/v1/admin/license/quote — send a quote request to the
 *                                       licensing service (v4.14.12)
 *  - POST /api/v1/admin/license/trial — fetch and install this instance's
 *                                       one trial key, or ask for an
 *                                       extended trial (v4.14.12)
 *
 * There is deliberately no endpoint for sending a metered licence's member
 * report. It had one briefly in v4.13.30, to back a "Send report now" button
 * on the License tab; Justin removed the button (the count is a billing matter
 * for the instance's operator, not something a team administrator should be
 * invited to manage) and the endpoint went with it rather than becoming an
 * admin-gated route with no caller. Sending on demand is
 * `occ teamhub:seat-report`.
 *
 * Everything gated with #[AuthorizedAdminSetting] so delegated TeamHub
 * admins (see AdminSettings) can manage licenses without needing full
 * NC admin.
 *
 * Trials: from v4.3.22 to v4.14.11 there was no trial endpoint and every
 * trial was issued by hand. v4.14.12 brings one back, with a different shape
 * from the 4.3-era one: the portal issues the key from the same registry the
 * partner portal uses (one trial per instance UUID, for ever) and this
 * instance installs it through saveKey(), verified like a pasted key.
 */
class LicenseController extends Controller {

    public function __construct(
        string                  $appName,
        IRequest                $request,
        private LicenseService  $licenseService,
        private QuoteRequestService $quoteRequestService,
        private TrialRequestService $trialRequestService,
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
     * POST /api/v1/admin/license/quote
     * Body: { kind: 'licence'|'support', email, country (ISO 3166 alpha-2),
     *         seats (licence only), supportPlan: 'none'|'supported'|'priority'
     *         (licence only, default 'none'), message }
     *
     * v4.14.12 — the License tab's quote form. The instance UUID and the
     * member count are added by QuoteRequestService, never taken from the
     * browser. Answers:
     *  - 201 { reference }             filed by the licensing service
     *  - 400 { error: 'invalid', field }
     *  - 429 { error: 'rate_limited' } from the service; Nextcloud's own
     *        limiter below answers 429 too, before this method runs
     *  - 502 { error: 'unreachable'|'unavailable'|'rejected' }
     *
     * The error is a code: the License tab writes the sentence in the
     * administrator's language and, for the 502s, offers the e-mail route.
     *
     * Rate-limited per user. The receiver throttles too, but a request that
     * never leaves the instance costs nobody anything.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[UserRateLimit(limit: 5, period: 3600)]
    public function requestQuote(
        string $kind = '',
        string $email = '',
        string $country = '',
        mixed  $seats = null,
        string $message = '',
        string $supportPlan = 'none',
    ): JSONResponse {
        $fields = QuoteRequestService::validate($kind, $email, $country, $seats, $message, $supportPlan);
        if (isset($fields['error'])) {
            return new JSONResponse(['error' => 'invalid', 'field' => $fields['error']], Http::STATUS_BAD_REQUEST);
        }

        try {
            $result = $this->quoteRequestService->send($fields);
        } catch (LicensingRequestException $e) {
            return $this->failure('Quote request', $e);
        }

        return new JSONResponse($result, Http::STATUS_CREATED);
    }

    /**
     * POST /api/v1/admin/license/trial
     * Body: { email, extension: bool, message (extension only) }
     *
     * v4.14.12 — *Request a trial key*. Without `extension`, asks the
     * licensing service for this instance's one trial and installs the key it
     * answers with. With `extension`, files a request for an extended trial
     * with sales instead (offered after a `trial_used`). Answers:
     *  - 201 { status: 'issued', expiresAt, license: <status envelope> }
     *  - 201 { status: 'extension_requested', reference }
     *  - 400 { error: 'invalid', field: 'email'|'message' }
     *  - 409 { error: 'trial_used' }   this UUID has had its trial
     *  - 409 { error: 'licensed' }     a paid key is in force; never replaced
     *  - 429 { error: 'rate_limited' }
     *  - 502 { error: 'unreachable'|'unavailable'|'rejected'|'invalid_key' }
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[UserRateLimit(limit: 5, period: 3600)]
    public function requestTrial(string $email = '', bool $extension = false, string $message = ''): JSONResponse {
        $fields = TrialRequestService::validate($email, $extension, $message);
        if (isset($fields['error'])) {
            return new JSONResponse(['error' => 'invalid', 'field' => $fields['error']], Http::STATUS_BAD_REQUEST);
        }

        try {
            $result = $this->trialRequestService->send($fields);
        } catch (LicensingRequestException $e) {
            return $this->failure('Trial request', $e);
        }

        if ($result['status'] === 'issued') {
            // The tab redraws from this, as it does after saveKey.
            $result['license'] = $this->licenseService->getStatus();
        }
        return new JSONResponse($result, Http::STATUS_CREATED);
    }

    /**
     * One mapping from a LicensingRequestException to a response, for both
     * requests. Expected outcomes (throttle, trial already used, paid key in
     * force) are not logged; the rest are, because each means the service or
     * the path to it needs a look.
     */
    private function failure(string $what, LicensingRequestException $e): JSONResponse {
        $reason = $e->getReason();
        $status = match ($reason) {
            'rate_limited'           => Http::STATUS_TOO_MANY_REQUESTS,
            'trial_used', 'licensed' => Http::STATUS_CONFLICT,
            default                  => Http::STATUS_BAD_GATEWAY,
        };
        if ($status === Http::STATUS_BAD_GATEWAY) {
            $this->logger->warning('[TeamHub][LicenseController] ' . $what . ' not completed (' . $reason . '): ' . $e->getMessage(), [
                'app' => 'teamhub',
            ]);
        }
        return new JSONResponse(['error' => $reason], $status);
    }

}
