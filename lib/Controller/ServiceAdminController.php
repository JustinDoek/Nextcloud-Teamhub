<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Constants\ServiceIcons;
use OCA\TeamHub\Service\ServiceTeam\ServiceCategoryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Settings → TeamHub → Services (v4.10.45): the service catalog's
 * categories and the two links under it. Nextcloud administrators only.
 *
 *   GET /api/v1/admin/services/settings             — categories with usage, links, icons
 *   PUT /api/v1/admin/services/settings/categories  — replace the category list
 *   PUT /api/v1/admin/services/settings/links       — set the two links
 *
 * Not licence-gated: an administrator prepares the catalog before a licence
 * or a service team exists, and the settings do nothing until one does.
 */
class ServiceAdminController extends Controller {
    use ExceptionResponseTrait;

    public function __construct(
        string                         $appName,
        IRequest                       $request,
        private ServiceCategoryService $categories,
        private LoggerInterface        $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function settings(): JSONResponse {
        try {
            return new JSONResponse($this->describe());
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load the service catalog settings');
        }
    }

    /** Body: `{ categories: [{ key?, label, icon }] }`, in display order. */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function saveCategories(mixed $categories = []): JSONResponse {
        try {
            $this->categories->save(is_array($categories) ? array_values($categories) : []);
            return new JSONResponse($this->describe());
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to save the categories');
        }
    }

    /** Body: `{ serviceDesk?, knowledgePortal? }`; `''` removes one. */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function saveLinks(): JSONResponse {
        try {
            $body  = $this->request->getParams();
            $links = [];
            foreach (['serviceDesk', 'knowledgePortal'] as $field) {
                if (array_key_exists($field, $body)) {
                    $links[$field] = (string)$body[$field];
                }
            }
            $this->categories->saveLinks($links);
            return new JSONResponse($this->describe());
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to save the links');
        }
    }

    /** @return array<string, mixed> */
    private function describe(): array {
        $usage = $this->categories->usage();
        $rows  = [];
        foreach ($this->categories->list() as $category) {
            $rows[] = $category + ['usage' => $usage[$category['key']] ?? 0];
        }
        return [
            'categories' => $rows,
            'links'      => $this->categories->links(),
            'icons'      => ServiceIcons::ALLOWED,
        ];
    }
}
