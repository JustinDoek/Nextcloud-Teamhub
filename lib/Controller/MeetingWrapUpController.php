<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Exception\LicenseGateException;
use OCA\TeamHub\Service\LicenseService;
use OCA\TeamHub\Service\MeetingWrapUpService;
use OCA\TeamHub\Service\MemberService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Meeting wrap-up (v4.20.19, DESIGN §2.271): the dialog a meeting's organiser
 * opens from My Work once the meeting is over.
 *
 * SEC, both endpoints: team membership first (so a non-member learns nothing
 * about the licence), then the licence (Add meeting, which makes these
 * meetings, is licensed), then in the service: the viewer organised this
 * occurrence, it has ended and it was made with Add meeting. Anybody else
 * gets 404 or 403.
 */
class MeetingWrapUpController extends Controller {
    use ExceptionResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private MeetingWrapUpService $wrapUp,
        private MemberService $memberService,
        private LicenseService $licenseService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/teams/{teamId}/meetings/wrap-up/{objectId}/{start}
     *
     * `{ meeting, notesFound, actionItems, proposals, members, tasksAvailable, wrapped }`.
     */
    #[NoAdminRequired]
    public function getWrapUp(string $teamId, int $objectId, int $start): JSONResponse {
        try {
            $this->gate($teamId);
            return new JSONResponse($this->wrapUp->prepare($teamId, $objectId, $start));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load the meeting wrap-up', ['teamId' => $teamId]);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/meetings/wrap-up/{objectId}/{start}
     *
     * Body: `tasks` (list of `{ title, assignee, due }`, assignee a member's
     * uid or '' for the whole team, due `YYYY-MM-DD` or ''), `proposalIds`.
     * Answers `{ tasks, linked, notesUpdated, failedTasks }`.
     */
    #[NoAdminRequired]
    public function completeWrapUp(string $teamId, int $objectId, int $start): JSONResponse {
        try {
            $this->gate($teamId);
            $tasks = $this->request->getParam('tasks', []);
            $proposalIds = $this->request->getParam('proposalIds', []);
            return new JSONResponse($this->wrapUp->complete(
                $teamId,
                $objectId,
                $start,
                is_array($tasks) ? array_values(array_filter($tasks, 'is_array')) : [],
                is_array($proposalIds) ? array_values($proposalIds) : [],
            ));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to wrap up the meeting', ['teamId' => $teamId]);
        }
    }

    private function gate(string $teamId): void {
        $this->memberService->requireMemberLevel($teamId);
        if (!$this->licenseService->isLicensed()) {
            throw new LicenseGateException(
                $this->licenseService->getEnforcementLevel(),
                'Wrapping up a meeting requires an active TeamHub licence.',
            );
        }
    }
}
