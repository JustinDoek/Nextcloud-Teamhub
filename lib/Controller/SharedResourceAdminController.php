<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\SharedResourceProviderAdminService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The per-provider switch for the Shared with team card (v4.20.40).
 *
 *   GET /api/v1/admin/shared-resources/providers
 *   PUT /api/v1/admin/shared-resources/providers/{providerId}   { enabled: bool }
 *
 * `#[AuthorizedAdminSetting]` is the route gate and the service's
 * `requireNcAdmin()` the real boundary, as in `IntegrationAdminController`: a
 * delegated TeamHub admin passes the attribute and is refused by the service.
 * `#[NoCSRFRequired]` on the GET only, because the admin panel fetches it; the
 * PUT keeps CSRF protection.
 */
class SharedResourceAdminController extends Controller {
    use ExceptionResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private SharedResourceProviderAdminService $adminService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function listProviders(): JSONResponse {
        try {
            return new JSONResponse($this->adminService->listProviders());
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to list team resource providers');
        }
    }

    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function setProviderEnabled(string $providerId, bool $enabled): JSONResponse {
        try {
            $this->adminService->setEnabled($providerId, $enabled);
            return new JSONResponse($this->adminService->listProviders());
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to save the provider setting', [
                'providerId' => $providerId,
            ]);
        }
    }
}
