<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\ShiftAvailabilityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * When somebody is available to be rostered (v4.13.71, DESIGN §2.237).
 *
 * Personal settings → TeamHub. **Always the caller's own week**: no uid is
 * accepted on either verb, so neither endpoint can read or write a
 * colleague's. A team admin sees availability through the roster's staffing
 * endpoint, which is gated on being one and reports only what is needed to
 * staff a shift.
 *
 * Not gated on the Shifts licence. The setting is a statement a person makes
 * about their own week; it costs nothing to keep, and an instance that
 * licenses Shifts later finds its people have already answered.
 */
class ShiftAvailabilityController extends Controller {
    use ExceptionResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private ShiftAvailabilityService $availability,
        private IUserSession             $userSession,
        private LoggerInterface          $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /** GET /api/v1/people/shift-availability */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(): JSONResponse {
        try {
            return new JSONResponse($this->availability->get($this->uid()));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load your availability');
        }
    }

    /**
     * PUT /api/v1/people/shift-availability
     * Body: { cells: [{ dayOfWeek, halfDay, available }] }
     */
    #[NoAdminRequired]
    public function save(array $cells = []): JSONResponse {
        try {
            return new JSONResponse($this->availability->save($this->uid(), $cells));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to save your availability');
        }
    }

    private function uid(): string {
        return $this->userSession->getUser()?->getUID() ?? '';
    }
}
