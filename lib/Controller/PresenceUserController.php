<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Exception\PresenceConflictException;
use OCA\TeamHub\Service\PresenceLocationService;
use OCA\TeamHub\Service\PresenceMaterialisationService;
use OCA\TeamHub\Service\PresenceSlotService;
use OCA\TeamHub\Service\PresenceTemplateService;
use OCA\TeamHub\Service\PresenceTypeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * User-facing presence endpoints. Every endpoint:
 *   - Requires an authenticated NC session (#[NoAdminRequired]).
 *   - Scopes all data to the current user (userId from IUserSession).
 *   - Never exposes another user's data through this controller.
 *
 * Error mapping:
 *   DoesNotExistException     → 404
 *   PresenceConflictException → 409 (holiday-locked slot)
 *   InvalidArgumentException  → 400
 *   else                      → 500
 */
class PresenceUserController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession                   $userSession,
        private PresenceTemplateService        $templateService,
        private PresenceSlotService            $slotService,
        private PresenceMaterialisationService $materialisationService,
        private PresenceTypeService            $typeService,
        private PresenceLocationService        $locationService,
        private LoggerInterface                $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // =========================================================================
    // Template — /api/v1/presence/template
    // =========================================================================

    /**
     * GET /api/v1/presence/template
     * Returns the current user's 14-cell week template (all cells, including
     * unset ones with presence_type_id=null).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTemplate(): JSONResponse {
        try {
            $userId = $this->requireUserId();
            return new JSONResponse($this->templateService->getTemplate($userId));
        } catch (\Throwable $e) {
            return $this->mapError($e, 'getTemplate');
        }
    }

    /**
     * GET /api/v1/presence/types
     * Read-only list of presence types for the personal presence picker.
     *
     * The same data is exposed by the admin endpoint, but that one is gated by
     * #[AuthorizedAdminSetting] — regular users must not call it. The personal
     * "My Presence" page needs the type vocabulary to render the picker, so this
     * non-admin read-only mirror exists. No mutation routes are exposed here;
     * creating/editing/deleting types stays admin-only.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTypes(): JSONResponse {
        try {
            $this->requireUserId();
            return new JSONResponse($this->typeService->getTypes());
        } catch (\Throwable $e) {
            return $this->mapError($e, 'getTypes');
        }
    }

    /**
     * GET /api/v1/presence/locations
     * Read-only location tree (buildings → floors → rooms) for the personal
     * presence picker. Non-admin mirror of the admin location endpoint, for the
     * same reason as getTypes(). Read-only; building/floor/room management stays
     * admin-only.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getLocations(): JSONResponse {
        try {
            $this->requireUserId();
            return new JSONResponse($this->locationService->getBuildings());
        } catch (\Throwable $e) {
            return $this->mapError($e, 'getLocations');
        }
    }

    /**
     * POST /api/v1/presence/slots/materialise
     * Trigger an immediate rematerialisation for the current user.
     * Called by the calendar view when it detects months with missing slots.
     */
    #[NoAdminRequired]
    public function materialiseNow(): JSONResponse {
        try {
            $userId = $this->requireUserId();
            $this->materialisationService->rematerialiseForUser($userId);
            return new JSONResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'materialiseNow');
        }
    }

    /**
     * PUT /api/v1/presence/template/bulk
     * Save all 14 template cells at once, then materialise once.
     * Body: { cells: [{ day_of_week, half_day, presence_type_id, location_room_id }] }
     * Returns the full template (same shape as GET /presence/template).
     */
    #[NoAdminRequired]
    public function saveTemplateBulk(array $cells = []): JSONResponse {
        try {
            $userId = $this->requireUserId();
            $result = $this->templateService->setBulk($userId, $cells);
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'saveTemplateBulk');
        }
    }

    /**
     * PUT /api/v1/presence/template/cell
     * Upsert a single cell in the user's week template, then trigger
     * immediate re-materialisation.
     *
     * Body: { day_of_week: 0–6, half_day: 0|1, presence_type_id: int|null, location_room_id: int|null }
     * Pass presence_type_id=null to clear the cell.
     */
    #[NoAdminRequired]
    public function setTemplateCell(
        int  $day_of_week,
        int  $half_day,
        ?int $presence_type_id = null,
        ?int $location_room_id = null,
    ): JSONResponse {
        try {
            $userId = $this->requireUserId();
            $result = $this->templateService->setCell($userId, [
                'day_of_week'      => $day_of_week,
                'half_day'         => $half_day,
                'presence_type_id' => $presence_type_id,
                'location_room_id' => $location_room_id,
            ]);
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'setTemplateCell');
        }
    }

    // =========================================================================
    // Slots — /api/v1/presence/slots
    // =========================================================================

    /**
     * GET /api/v1/presence/slots?from=YYYY-MM-DD&to=YYYY-MM-DD
     * Returns the current user's materialised slots in the given date range,
     * enriched with type metadata (label, icon, color, requires_location, is_locked).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getSlots(string $from = '', string $to = ''): JSONResponse {
        try {
            $userId = $this->requireUserId();
            return new JSONResponse(
                $this->slotService->getSlotsForUser($userId, $from, $to)
            );
        } catch (\Throwable $e) {
            return $this->mapError($e, 'getSlots');
        }
    }

    /**
     * PUT /api/v1/presence/slots/override
     * Override a single slot. Changes source to 'override' regardless of prior
     * value. Returns 409 if the slot is source='holiday' (admin-locked).
     *
     * Body: { slot_date: "YYYY-MM-DD", half_day: 0|1, presence_type_id: int|null, location_room_id: int|null }
     */
    #[NoAdminRequired]
    public function overrideSlot(
        string $slot_date       = '',
        int    $half_day        = 0,
        ?int   $presence_type_id = null,
        ?int   $location_room_id = null,
    ): JSONResponse {
        try {
            $userId = $this->requireUserId();
            $result = $this->slotService->overrideSlot($userId, [
                'slot_date'        => $slot_date,
                'half_day'         => $half_day,
                'presence_type_id' => $presence_type_id,
                'location_room_id' => $location_room_id,
            ]);
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'overrideSlot');
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function requireUserId(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \RuntimeException('Not authenticated');
        }
        return $user->getUID();
    }

    private function mapError(\Throwable $e, string $action): JSONResponse {
        if ($e instanceof DoesNotExistException) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
        if ($e instanceof PresenceConflictException) {
            $body = ['error' => $e->getMessage()];
            if ($e->getAffectedCount() > 0) {
                $body['affectedCount'] = $e->getAffectedCount();
            }
            return new JSONResponse($body, Http::STATUS_CONFLICT);
        }
        if ($e instanceof \InvalidArgumentException) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
        $this->logger->error(sprintf(
            '[TeamHub][PresenceUserController] %s: %s', $action, $e->getMessage()
        ), ['exception' => $e]);
        return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
    }
}
