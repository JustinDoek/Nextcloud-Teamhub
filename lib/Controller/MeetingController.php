<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\LicenseGateException;
use OCA\TeamHub\Service\LicenseService;
use OCA\TeamHub\Service\MeetingReplyService;
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
 *
 * POST /api/v1/teams/{teamId}/meetings/reply   (v4.20.10, licensed)
 *   Accept, tentatively accept or decline a meeting on the team calendar.
 *   Body: { uid, status: accepted|tentative|declined }.
 *   Auth: team member, and an attendee of that meeting (MeetingReplyService).
 */
class MeetingController extends Controller {
    use ExceptionResponseTrait;


    public function __construct(
        IRequest                $request,
        private MeetingService  $meetingService,
        private MemberService   $memberService,
        private MeetingReplyService $meetingReplies,
        private LicenseService  $licenseService,
        private IUserSession    $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/teams/{teamId}/meetings
    // -------------------------------------------------------------------------

    #[NoAdminRequired]
    public function createTeamMeeting(string $teamId): JSONResponse {
        $this->logger->debug('[TeamHub][MeetingController] createTeamMeeting — start', [
            'teamId' => $teamId, 'app' => Application::APP_ID,
        ]);

        try {
            // v4.20.12 — Add meeting is licensed. Membership first, so a
            // non-member learns nothing about the instance's licence; the
            // service still applies the team's own minimum level after this.
            $this->memberService->requireMemberLevel($teamId);
            if (!$this->licenseService->isLicensed()) {
                throw new LicenseGateException(
                    $this->licenseService->getEnforcementLevel(),
                    'Adding a meeting requires an active TeamHub licence.',
                );
            }

            $title       = trim((string)$this->request->getParam('title',       ''));
            $date        = trim((string)$this->request->getParam('date',         ''));
            $startTime   = trim((string)$this->request->getParam('startTime',    ''));
            $endTime     = trim((string)$this->request->getParam('endTime',      ''));
            $location    = trim((string)$this->request->getParam('location',     ''));
            $filename    = trim((string)$this->request->getParam('filename',     ''));
            $includeTalk = (bool)(int)$this->request->getParam('includeTalk',    1);
            $talkToken   = trim((string)$this->request->getParam('talkToken',    ''));
            $askAgenda   = (bool)(int)$this->request->getParam('askAgenda',      0);

            // Wizard fields — added in 3.81.0 when the Meeting Wizard
            // and Team meeting actions were merged into Add Meeting.
            // All optional; default to empty/false preserve safe behaviour.
            $description = trim((string)$this->request->getParam('description', ''));
            $categories  = trim((string)$this->request->getParam('categories',  ''));
            $roomEmail   = trim((string)$this->request->getParam('roomEmail',   ''));
            $roomName    = trim((string)$this->request->getParam('roomName',    ''));
            $roomId      = trim((string)$this->request->getParam('roomId',      ''));
            $includeOverdueTasks     = (bool)(int)$this->request->getParam('includeOverdueTasks',     0);
            $includeUnscheduledTasks = (bool)(int)$this->request->getParam('includeUnscheduledTasks', 0);
            $includeProposals        = (bool)(int)$this->request->getParam('includeProposals',        0);

            // proposalCategories — comma-separated string or array of category
            // names. Empty means "all categories" (no filter applied). Same
            // parser shape as the attendees field below.
            $proposalCategories = [];
            $rawProposalCategories = $this->request->getParam('proposalCategories', '');
            if (is_array($rawProposalCategories)) {
                foreach ($rawProposalCategories as $c) {
                    $c = trim((string)$c);
                    if ($c !== '') $proposalCategories[] = $c;
                }
            } elseif (is_string($rawProposalCategories) && $rawProposalCategories !== '') {
                foreach (explode(',', $rawProposalCategories) as $c) {
                    $c = trim($c);
                    if ($c !== '') $proposalCategories[] = $c;
                }
            }

            // Attendees — accept comma-separated string or array, mirroring
            // TeamController::createCalendarEvent for symmetry. Empty array
            // means "no per-attendee invites" — the event lands only in the
            // team calendar.
            $attendeeUids = [];
            $rawAttendees = $this->request->getParam('attendees', '');
            if (is_array($rawAttendees)) {
                foreach ($rawAttendees as $a) {
                    $a = trim((string)$a);
                    if ($a !== '') $attendeeUids[] = $a;
                }
            } elseif (is_string($rawAttendees) && $rawAttendees !== '') {
                foreach (explode(',', $rawAttendees) as $a) {
                    $a = trim($a);
                    if ($a !== '') $attendeeUids[] = $a;
                }
            }

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
            // Defence-in-depth bounds on free-text fields to limit payload
            // abuse — the notes file embeds these and the calendar event
            // stores them.
            if (strlen($title) > 200)        $errors[] = 'title too long';
            if (strlen($location) > 200)     $errors[] = 'location too long';
            if (strlen($description) > 4000) $errors[] = 'description too long';
            if (strlen($categories) > 500)   $errors[] = 'categories too long';
            if (count($attendeeUids) > 500)  $errors[] = 'too many attendees';
            if (count($proposalCategories) > 200) $errors[] = 'too many proposalCategories';

            if (!empty($errors)) {
                return new JSONResponse(['error' => implode('; ', $errors)], Http::STATUS_BAD_REQUEST);
            }

            $result = $this->meetingService->createTeamMeeting(
                teamId:                  $teamId,
                title:                   $title,
                date:                    $date,
                startTime:               $startTime,
                endTime:                 $endTime,
                location:                $location,
                filename:                $filename !== '' ? $filename : $title,
                includeTalk:             $includeTalk,
                talkToken:               $talkToken,
                askAgenda:               $askAgenda,
                attendeeUids:            $attendeeUids,
                description:             $description,
                categories:              $categories,
                roomEmail:               $roomEmail,
                roomName:                $roomName,
                roomId:                  $roomId,
                includeOverdueTasks:     $includeOverdueTasks,
                includeUnscheduledTasks: $includeUnscheduledTasks,
                includeProposals:        $includeProposals,
                proposalCategories:      $proposalCategories,
                // v4.20.8 — the repeat setting, raw; the service validates it
                // and refuses it on an unlicensed instance.
                recurrence:              $this->request->getParam('recurrence', null),
                // v4.20.10 — for a series: how many days before each date its
                // notes are made and the team chat is reminded (1 to 7).
                reminderDays:            max(1, min(7, (int)$this->request->getParam('reminderDays', 2))),
            );

            $this->logger->debug('[TeamHub][MeetingController] createTeamMeeting — success', [
                'teamId' => $teamId, 'calendarCreated' => $result['calendarEventCreated'],
                'app' => Application::APP_ID,
            ]);

            return new JSONResponse($result, Http::STATUS_CREATED);

        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to create meeting', [
                'teamId' => $teamId,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/teams/{teamId}/meetings/reply
    // -------------------------------------------------------------------------

    /**
     * Answer an invitation from the Upcoming events widget (v4.20.10).
     *
     * Licensed, like the attendee list it sits in. Membership first, so a
     * non-member learns nothing about the meeting; then the licence; then the
     * service, which only lets the signed-in attendee answer for themself and
     * reads the event back to confirm Nextcloud recorded it.
     */
    #[NoAdminRequired]
    public function replyToMeeting(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            if (!$this->licenseService->isLicensed()) {
                throw new LicenseGateException(
                    $this->licenseService->getEnforcementLevel(),
                    'Replying from TeamHub requires an active TeamHub licence.',
                );
            }
            $uid = $this->userSession->getUser()?->getUID() ?? '';
            $result = $this->meetingReplies->reply(
                $teamId,
                $uid,
                (string)$this->request->getParam('uid', ''),
                (string)$this->request->getParam('status', ''),
            );
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not save your reply', [
                'teamId' => $teamId,
            ]);
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
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load meeting settings', [
                'teamId' => $teamId,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // PUT /api/v1/teams/{teamId}/meetings/settings
    // -------------------------------------------------------------------------

    #[NoAdminRequired]
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
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to save meeting settings', [
                'teamId' => $teamId,
            ]);
        }
    }
}
