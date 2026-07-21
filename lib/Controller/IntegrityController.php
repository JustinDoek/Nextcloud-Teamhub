<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\IntegrityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Admin-only code-integrity endpoint (Compliance tab).
 *
 * The lists of altered / missing / unexpected files can identify install
 * paths and framework structure, so this must stay admin-only — same trust
 * boundary as the rest of AdminSettings.
 *
 * Endpoints:
 *   GET /api/v1/admin/integrity — run the check and return the report
 */
class IntegrityController extends Controller {

    public function __construct(
        string                   $appName,
        IRequest                 $request,
        private IntegrityService $integrityService,
        private LoggerInterface  $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function check(): JSONResponse {
        try {
            return new JSONResponse($this->integrityService->check());
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][IntegrityController] check failed: ' . $e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Integrity check failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
