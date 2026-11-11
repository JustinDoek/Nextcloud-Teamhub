<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\ShiftTypeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Shift types, for the Nextcloud administrator (v4.13.67, DESIGN §2.234).
 *
 * Admin → TeamHub → People → Shift types. Instance-wide, the same contract and
 * the same screen as presence types, locations and holidays.
 *
 * **No `#[NoAdminRequired]` anywhere in this controller**, which is the whole
 * access rule: every method is Nextcloud-administrator-only by omission, the
 * way the rest of `PresenceAdminController` is. A shift type is instance
 * configuration — the vocabulary every team rosters from — so a team admin
 * reads it through the team endpoints and never writes it.
 *
 * **This class was `ShiftScheduleController` until v4.14.4.** It also held the
 * four endpoints behind Admin → TeamHub → People → Shifts, the instance-wide
 * weekly pattern of §2.234, which v4.14.4 removed: the week belongs to the
 * team and is written in Manage team, Module settings (§2.240). Only the
 * vocabulary is the instance's, so only the vocabulary is here.
 */
class ShiftTypeController extends Controller {
    use ExceptionResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private ShiftTypeService $types,
        private LoggerInterface  $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /** GET /api/v1/admin/shift-types */
    #[NoCSRFRequired]
    public function index(): JSONResponse {
        try {
            return new JSONResponse(['types' => $this->types->listAll()]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load the shift types');
        }
    }

    /**
     * POST /api/v1/admin/shift-types
     * Body: { label, startTime, endTime, color?, icon?, isActive? }
     */
    public function create(
        string $label = '',
        string $startTime = '09:00',
        string $endTime = '17:00',
        string $color = '',
        string $icon = '',
        bool $isActive = true,
    ): JSONResponse {
        try {
            return new JSONResponse(
                $this->types->save(null, $label, $startTime, $endTime, $color, $icon, $isActive),
                Http::STATUS_CREATED,
            );
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to create the shift type');
        }
    }

    /** PUT /api/v1/admin/shift-types/{id} */
    public function update(
        int $id,
        string $label = '',
        string $startTime = '09:00',
        string $endTime = '17:00',
        string $color = '',
        string $icon = '',
        bool $isActive = true,
    ): JSONResponse {
        try {
            return new JSONResponse(
                $this->types->save($id, $label, $startTime, $endTime, $color, $icon, $isActive),
            );
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to save the shift type');
        }
    }

    /** DELETE /api/v1/admin/shift-types/{id} */
    public function destroy(int $id): JSONResponse {
        try {
            $this->types->delete($id);
            return new JSONResponse(['deleted' => true]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to delete the shift type');
        }
    }
}
