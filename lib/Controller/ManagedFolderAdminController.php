<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\ManagedFolderAdminService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Which apps may claim Team folders as their own (v4.20.48).
 *
 *   GET /api/v1/admin/managed-folders/apps
 *   PUT /api/v1/admin/managed-folders/apps/{claimingApp}   { enabled: bool }
 *
 * `#[AuthorizedAdminSetting]` is the route gate and the service's
 * `requireNcAdmin()` the real boundary, as in `SharedResourceAdminController`.
 * `#[NoCSRFRequired]` on the GET only, because the admin panel fetches it.
 */
class ManagedFolderAdminController extends Controller {
    use ExceptionResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private ManagedFolderAdminService $adminService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function listApps(): JSONResponse {
        try {
            return new JSONResponse($this->adminService->listApps());
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to list the apps that manage folders');
        }
    }

    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function setAppEnabled(string $claimingApp, bool $enabled): JSONResponse {
        try {
            $this->adminService->setEnabled($claimingApp, $enabled);
            return new JSONResponse($this->adminService->listApps());
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to save the managed folder setting', [
                'claimingApp' => $claimingApp,
            ]);
        }
    }
}
