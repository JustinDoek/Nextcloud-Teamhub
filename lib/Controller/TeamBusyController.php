<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\TeamBusyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Busy times on the team calendar (v4.20.15, DESIGN §2.267).
 *
 * One read: the members whose busy times the team calendar may show, each
 * with the address Nextcloud's free/busy looks them up by. The busy times
 * themselves never pass through TeamHub: the browser asks Nextcloud for them
 * from the viewer's own CalDAV outbox. `TeamBusyService` has the rules.
 */
class TeamBusyController extends Controller {
    use ExceptionResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private TeamBusyService $teamBusyService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/teams/{teamId}/calendar/busy-people
     *
     * Answers `{ organizer, maxPerRequest, people: [{ userId, displayName,
     * email, self }], notShown }`.
     *
     * SEC: team membership required (`getEffectiveMemberIds()` gates it). A
     * member who switched busy times off, or made their email Private, is not
     * in `people`; `notShown` counts them without naming anybody.
     */
    #[NoAdminRequired]
    public function getBusyPeople(string $teamId): JSONResponse {
        try {
            return new JSONResponse($this->teamBusyService->peopleFor($teamId));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load the team members for busy times', [
                'teamId' => $teamId,
            ]);
        }
    }
}
