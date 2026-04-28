<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\MeetingService;
use OCA\TeamHub\Service\MemberService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * MeetingController — Team meeting action endpoints.
 *
 * POST /api/v1/teams/{teamId}/meetings
 *   Execute the full team meeting workflow (notes file + calendar + Talk).
 *   Auth: team member at meeting_min_level or above (enforced in MeetingService).
 *
 * GET  /api/v1/teams/{teamId}/meetings/settings
 *   Return the meeting_min_level for this team.
 *   Auth: team admin.
 *
 * PUT  /api/v1/teams/{teamId}/meetings/settings
 *   Save the meeting_min_level for this team.
 *   Auth: team admin.
 */
class MeetingController extends Controller {

    public function __construct(
        IRequest                $request,
        private MeetingService  $meetingService,
        private MemberService   $memberService,
        private IUserSession    $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/teams/{teamId}/meetings
    // -------------------------------------------------------------------------

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function createTeamMeeting(string $teamId): JSONResponse {
        $this->logger->debug('[TeamHub][MeetingController] createTeamMeeting — start', [
            'teamId' => $teamId, 'app' => Application::APP_ID,
        ]);

        try {
            $title       = trim((string)$this->request->getParam('title',       ''));
            $date        = trim((string)$this->request->getParam('date',         ''));
            $startTime   = trim((string)$this->request->getParam('startTime',    ''));
            $endTime     = trim((string)$this->request->getParam('endTime',      ''));
            $location    = trim((string)$this->request->getParam('location',     ''));
            $filename    = trim((string)$this->request->getParam('filename',     ''));
            $includeTalk = (bool)(int)$this->request->getParam('includeTalk',    1);
            $talkToken   = trim((string)$this->request->getParam('talkToken',    ''));
            $askAgenda   = (bool)(int)$this->request->getParam('askAgenda',      0);

            // Input validation
            $errors = [];
            if ($title === '') {
                $errors[] = 'title is required';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $errors[] = 'date must be YYYY-MM-DD';
            }
            if (!preg_match('/^\d{2}:\d{2}$/', $startTime)) {
                $errors[] = 'startTime must be HH:MM';
            }
            if (!preg_match('/^\d{2}:\d{2}$/', $endTime)) {
                $errors[] = 'endTime must be HH:MM';
            }
            if (!empty($errors)) {
                return new JSONResponse(['error' => implode('; ', $errors)], Http::STATUS_BAD_REQUEST);
            }

            $result = $this->meetingService->createTeamMeeting(
                teamId:      $teamId,
                title:       $title,
                date:        $date,
                startTime:   $startTime,
                endTime:     $endTime,
                location:    $location,
                filename:    $filename !== '' ? $filename : $title,
                includeTalk: $includeTalk,
                talkToken:   $talkToken,
                askAgenda:   $askAgenda,
            );

            $this->logger->debug('[TeamHub][MeetingController] createTeamMeeting — success', [
                'teamId' => $teamId, 'calendarCreated' => $result['calendarEventCreated'],
                'app' => Application::APP_ID,
            ]);

            return new JSONResponse($result, Http::STATUS_CREATED);

        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $this->logger->warning('[TeamHub][MeetingController] createTeamMeeting failed', [
                'teamId' => $teamId, 'error' => $msg, 'app' => Application::APP_ID,
            ]);

            $status = match (true) {
                str_contains($msg, 'not a member'),
                str_contains($msg, 'Insufficient permissions') => Http::STATUS_FORBIDDEN,
                str_contains($msg, 'not found'),
                str_contains($msg, 'not installed')            => Http::STATUS_UNPROCESSABLE_ENTITY,
                default                                        => Http::STATUS_INTERNAL_SERVER_ERROR,
            };

            return new JSONResponse(['error' => $msg], $status);
        }
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/teams/{teamId}/meetings/settings
    // -------------------------------------------------------------------------

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getMeetingSettings(string $teamId): JSONResponse {
        $this->logger->debug('[TeamHub][MeetingController] getMeetingSettings', [
            'teamId' => $teamId, 'app' => Application::APP_ID,
        ]);

        try {
            $this->memberService->requireAdminLevel($teamId);
            $level = $this->meetingService->getMeetingMinLevel($teamId);
            return new JSONResponse(['minLevel' => $level]);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $this->logger->warning('[TeamHub][MeetingController] getMeetingSettings failed', [
                'teamId' => $teamId, 'error' => $msg, 'app' => Application::APP_ID,
            ]);
            $status = str_contains($msg, 'member') || str_contains($msg, 'permissions')
                ? Http::STATUS_FORBIDDEN : Http::STATUS_INTERNAL_SERVER_ERROR;
            return new JSONResponse(['error' => $msg], $status);
        }
    }

    // -------------------------------------------------------------------------
    // PUT /api/v1/teams/{teamId}/meetings/settings
    // -------------------------------------------------------------------------

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function saveMeetingSettings(string $teamId): JSONResponse {
        $this->logger->debug('[TeamHub][MeetingController] saveMeetingSettings', [
            'teamId' => $teamId, 'app' => Application::APP_ID,
        ]);

        try {
            $this->memberService->requireAdminLevel($teamId);

            $level = (int)$this->request->getParam('minLevel', 1);
            if (!in_array($level, [1, 4, 8], true)) {
                return new JSONResponse(['error' => 'minLevel must be 1, 4, or 8'], Http::STATUS_BAD_REQUEST);
            }

            $this->meetingService->saveMeetingMinLevel($teamId, $level);

            return new JSONResponse(['minLevel' => $level]);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $this->logger->warning('[TeamHub][MeetingController] saveMeetingSettings failed', [
                'teamId' => $teamId, 'error' => $msg, 'app' => Application::APP_ID,
            ]);
            $status = str_contains($msg, 'member') || str_contains($msg, 'permissions')
                ? Http::STATUS_FORBIDDEN : Http::STATUS_INTERNAL_SERVER_ERROR;
            return new JSONResponse(['error' => $msg], $status);
        }
    }
}
