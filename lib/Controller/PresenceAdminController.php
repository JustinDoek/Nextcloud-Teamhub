<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Exception\PresenceConflictException;
use OCA\TeamHub\Service\PresenceHolidayService;
use OCA\TeamHub\Service\PresenceLocationService;
use OCA\TeamHub\Service\PresenceTypeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Admin-only endpoints for the TeamHub presence module (Session B1 / L).
 *
 * Authorisation: every method is gated by #[AuthorizedAdminSetting] against
 * TeamHub's AdminSettings class — matches the existing MaintenanceController
 * pattern. This allows TeamHub-delegated admin groups (per
 * IDelegatedSettings) to manage the presence catalogue without full NC
 * admin rights.
 *
 * Error mapping:
 *   DoesNotExistException        → 404
 *   InvalidArgumentException     → 400
 *   PresenceConflictException    → 409 (with affectedCount when present)
 *   anything else                → 500
 */
class PresenceAdminController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private PresenceTypeService     $types,
        private PresenceLocationService $locations,
        private PresenceHolidayService  $holidays,
        private LoggerInterface         $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // =========================================================================
    // Types — /api/v1/admin/presence/types
    // =========================================================================

    /**
     * GET /api/v1/admin/presence/types
     * Returns the full presence-type catalogue, ordered by sort_order.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function listTypes(): JSONResponse {
        try {
            return new JSONResponse($this->types->getTypes());
        } catch (\Throwable $e) {
            return $this->mapError($e, 'listTypes');
        }
    }

    /**
     * POST /api/v1/admin/presence/types
     * Body: { label, icon?, color?, requires_location?, is_busy?, selectable_by_user?, sort_order? }
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function createType(
        string $label = '',
        string $icon = '',
        string $color = '',
        int    $requires_location = 0,
        int    $is_busy = 1,
        int    $selectable_by_user = 1,
        int    $sort_order = 0,
    ): JSONResponse {
        try {
            $row = $this->types->createType([
                'label'              => $label,
                'icon'               => $icon,
                'color'              => $color,
                'requires_location'  => $requires_location,
                'is_busy'            => $is_busy,
                'selectable_by_user' => $selectable_by_user,
                'sort_order'         => $sort_order,
            ]);
            return new JSONResponse($row, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'createType');
        }
    }

    /**
     * PUT /api/v1/admin/presence/types/{id}
     * Partial update. Only fields present in the body are touched.
     *
     * Defaults are intentional sentinel values (empty strings and -1) so the
     * service knows which fields the client actually sent — NC's controller
     * framework binds missing optional params to the default rather than
     * leaving them out.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function updateType(
        int     $id,
        ?string $label = null,
        ?string $icon = null,
        ?string $color = null,
        ?int    $requires_location = null,
        ?int    $is_busy = null,
        ?int    $selectable_by_user = null,
        ?int    $sort_order = null,
    ): JSONResponse {
        try {
            $patch = [];
            if ($label              !== null) { $patch['label']              = $label; }
            if ($icon               !== null) { $patch['icon']               = $icon; }
            if ($color              !== null) { $patch['color']              = $color; }
            if ($requires_location  !== null) { $patch['requires_location']  = $requires_location; }
            if ($is_busy            !== null) { $patch['is_busy']            = $is_busy; }
            if ($selectable_by_user !== null) { $patch['selectable_by_user'] = $selectable_by_user; }
            if ($sort_order         !== null) { $patch['sort_order']         = $sort_order; }

            return new JSONResponse($this->types->updateType($id, $patch));
        } catch (\Throwable $e) {
            return $this->mapError($e, 'updateType');
        }
    }

    /**
     * DELETE /api/v1/admin/presence/types/{id}
     * Rejected for builtins (409). Rejected if any template or slot row
     * still references this type (409 with affectedCount).
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function deleteType(int $id): JSONResponse {
        try {
            $this->types->deleteType($id);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'deleteType');
        }
    }

    // =========================================================================
    // Locations — /api/v1/admin/presence/{locations,buildings,floors,rooms}
    // =========================================================================

    /**
     * GET /api/v1/admin/presence/locations
     * Returns the full nested tree: buildings → floors → rooms.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function getLocationTree(): JSONResponse {
        try {
            return new JSONResponse($this->locations->getBuildings());
        } catch (\Throwable $e) {
            return $this->mapError($e, 'getLocationTree');
        }
    }

    // --- Buildings -----------------------------------------------------------

    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function createBuilding(
        string  $name = '',
        ?string $address = null,
        int     $sort_order = 0,
    ): JSONResponse {
        try {
            $row = $this->locations->createBuilding([
                'name'       => $name,
                'address'    => $address,
                'sort_order' => $sort_order,
            ]);
            return new JSONResponse($row, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'createBuilding');
        }
    }

    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function updateBuilding(
        int     $id,
        ?string $name = null,
        ?string $address = null,
        ?int    $sort_order = null,
    ): JSONResponse {
        try {
            $patch = [];
            if ($name       !== null) { $patch['name']       = $name; }
            if ($address    !== null) { $patch['address']    = $address; }
            if ($sort_order !== null) { $patch['sort_order'] = $sort_order; }
            return new JSONResponse($this->locations->updateBuilding($id, $patch));
        } catch (\Throwable $e) {
            return $this->mapError($e, 'updateBuilding');
        }
    }

    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function deleteBuilding(int $id): JSONResponse {
        try {
            $this->locations->deleteBuilding($id);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'deleteBuilding');
        }
    }

    // --- Floors --------------------------------------------------------------

    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function createFloor(
        int    $building_id = 0,
        string $name = '',
        int    $sort_order = 0,
    ): JSONResponse {
        try {
            $row = $this->locations->createFloor([
                'building_id' => $building_id,
                'name'        => $name,
                'sort_order'  => $sort_order,
            ]);
            return new JSONResponse($row, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'createFloor');
        }
    }

    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function updateFloor(
        int     $id,
        ?string $name = null,
        ?int    $sort_order = null,
    ): JSONResponse {
        try {
            $patch = [];
            if ($name       !== null) { $patch['name']       = $name; }
            if ($sort_order !== null) { $patch['sort_order'] = $sort_order; }
            return new JSONResponse($this->locations->updateFloor($id, $patch));
        } catch (\Throwable $e) {
            return $this->mapError($e, 'updateFloor');
        }
    }

    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function deleteFloor(int $id): JSONResponse {
        try {
            $this->locations->deleteFloor($id);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'deleteFloor');
        }
    }

    // --- Rooms ---------------------------------------------------------------

    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function createRoom(
        int    $floor_id = 0,
        string $name = '',
        int    $sort_order = 0,
    ): JSONResponse {
        try {
            $row = $this->locations->createRoom([
                'floor_id'   => $floor_id,
                'name'       => $name,
                'sort_order' => $sort_order,
            ]);
            return new JSONResponse($row, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'createRoom');
        }
    }

    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function updateRoom(
        int     $id,
        ?string $name = null,
        ?int    $sort_order = null,
    ): JSONResponse {
        try {
            $patch = [];
            if ($name       !== null) { $patch['name']       = $name; }
            if ($sort_order !== null) { $patch['sort_order'] = $sort_order; }
            return new JSONResponse($this->locations->updateRoom($id, $patch));
        } catch (\Throwable $e) {
            return $this->mapError($e, 'updateRoom');
        }
    }

    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function deleteRoom(int $id): JSONResponse {
        try {
            $this->locations->deleteRoom($id);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'deleteRoom');
        }
    }

    // =========================================================================
    // Holidays — /api/v1/admin/presence/holidays
    // =========================================================================

    /**
     * GET /api/v1/admin/presence/holidays?year=YYYY
     * Returns all holidays, optionally filtered to a single year.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function listHolidays(?int $year = null): JSONResponse {
        try {
            return new JSONResponse($this->holidays->getHolidays($year));
        } catch (\Throwable $e) {
            return $this->mapError($e, 'listHolidays');
        }
    }

    /**
     * POST /api/v1/admin/presence/holidays/preview
     * Body: { date: "YYYY-MM-DD" }
     * Returns: { affectedSlots: N }
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function previewHoliday(string $date = ''): JSONResponse {
        try {
            return new JSONResponse($this->holidays->previewHoliday($date));
        } catch (\Throwable $e) {
            return $this->mapError($e, 'previewHoliday');
        }
    }

    /**
     * POST /api/v1/admin/presence/holidays
     * Body: { date: "YYYY-MM-DD", name: "..." }
     * Returns: { holiday: { ... }, affectedSlots: N }
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function addHoliday(string $date = '', string $name = ''): JSONResponse {
        try {
            $result = $this->holidays->addHoliday($date, $name);
            return new JSONResponse($result, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'addHoliday');
        }
    }

    /**
     * DELETE /api/v1/admin/presence/holidays/{id}
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function deleteHoliday(int $id): JSONResponse {
        try {
            $this->holidays->removeHoliday($id);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'deleteHoliday');
        }
    }

    // =========================================================================
    // Error mapper
    // =========================================================================

    /**
     * Map service-layer exceptions to HTTP responses with the right code.
     * Logged once at the controller boundary so we have a single audit
     * trail of admin actions and failures.
     */
    private function mapError(\Throwable $e, string $action): JSONResponse {
        if ($e instanceof DoesNotExistException) {
            $this->logger->debug(sprintf(
                '[TeamHub][PresenceAdminController] %s: not found — %s',
                $action,
                $e->getMessage()
            ));
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_NOT_FOUND
            );
        }

        if ($e instanceof PresenceConflictException) {
            $this->logger->info(sprintf(
                '[TeamHub][PresenceAdminController] %s: conflict — %s (affected=%d)',
                $action,
                $e->getMessage(),
                $e->getAffectedCount()
            ));
            $body = ['error' => $e->getMessage()];
            if ($e->getAffectedCount() > 0) {
                $body['affectedCount'] = $e->getAffectedCount();
            }
            return new JSONResponse($body, Http::STATUS_CONFLICT);
        }

        if ($e instanceof \InvalidArgumentException) {
            $this->logger->debug(sprintf(
                '[TeamHub][PresenceAdminController] %s: bad request — %s',
                $action,
                $e->getMessage()
            ));
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        }

        $this->logger->error(sprintf(
            '[TeamHub][PresenceAdminController] %s: unexpected — %s',
            $action,
            $e->getMessage()
        ), ['exception' => $e]);

        return new JSONResponse(
            ['error' => $e->getMessage()],
            Http::STATUS_INTERNAL_SERVER_ERROR
        );
    }
}
