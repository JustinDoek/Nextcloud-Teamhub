<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\PresenceTeamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Team-facing presence endpoints. Every endpoint:
 *   - Requires an authenticated NC session (#[NoAdminRequired]).
 *   - Requires the caller to be a team member (requireMemberLevel).
 *   - Config writes additionally require team admin (requireAdminLevel ≥ 8).
 *
 * Error mapping:
 *   InvalidArgumentException → 400
 *   \Exception (not member)  → 403
 *   else                     → 500
 */
class PresenceTeamController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private MemberService        $memberService,
        private PresenceTeamService  $teamPresence,
        private LoggerInterface      $logger,
        private \OCP\IConfig                              $config,
        private \OCP\IUserSession                         $userSession,
        private \OCA\TeamHub\Service\Suggestion\MeetingSuggestionService $suggestionService,
        private \OCA\TeamHub\Service\Suggestion\TimeslotSuggestionService $timeslotService,
        private \OCA\TeamHub\Service\TelemetryService     $telemetry,
    ) {
        parent::__construct($appName, $request);
    }

    // =========================================================================
    // Grid — /api/v1/teams/{teamId}/presence
    // =========================================================================

    /**
     * GET /api/v1/teams/{teamId}/presence?from=YYYY-MM-DD&to=YYYY-MM-DD
     *
     * Returns the team presence grid for the given date range.
     * Privacy filter (hide_reasons) applied server-side.
     *
     * SEC: team membership required.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTeamGrid(string $teamId, string $from = '', string $to = ''): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            return new JSONResponse(
                $this->teamPresence->getTeamGrid($teamId, $from, $to)
            );
        } catch (\Throwable $e) {
            return $this->mapError($e, 'getTeamGrid');
        }
    }

    // =========================================================================
    // Config — /api/v1/teams/{teamId}/presence/config
    // =========================================================================

    /**
     * GET /api/v1/teams/{teamId}/presence/suggest-times
     *   ?date=YYYY-MM-DD&type=online|office&attendees=uid1,uid2
     *
     * Returns up to 3 ranked suggested meeting half-days based on team
     * presence. `attendees` is optional and comma-separated; empty/absent means
     * the whole team. `date` is the organiser's starting pick; suggestions are
     * within ±3 working days of it. `type` is 'online' (default) or 'office'.
     *
     * SEC: team membership required AND the presence module must be enabled
     * both globally (admin app-config) and per-team. If either toggle is off
     * the endpoint returns 403 — the feature must not be reachable via a direct
     * API call when the module is disabled, regardless of a stale per-team flag.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function suggestTimes(
        string $teamId,
        string $date = '',
        string $type = 'online',
        string $attendees = '',
    ): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);

            // AND gate: global module toggle AND per-team toggle.
            $globalOn = $this->config->getAppValue(Application::APP_ID, 'presence_module_enabled', '1') === '1';
            $teamOn   = (bool)($this->teamPresence->getConfig($teamId)['presence_enabled'] ?? false);
            if (!$globalOn || !$teamOn) {
                return new JSONResponse(['error' => 'Presence module is not enabled'], Http::STATUS_FORBIDDEN);
            }

            // Validate the picked date.
            $date = trim($date);
            if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return new JSONResponse(['error' => 'A valid date (YYYY-MM-DD) is required'], Http::STATUS_BAD_REQUEST);
            }
            $d = \DateTime::createFromFormat('Y-m-d', $date);
            if ($d === false || $d->format('Y-m-d') !== $date) {
                return new JSONResponse(['error' => 'A valid date (YYYY-MM-DD) is required'], Http::STATUS_BAD_REQUEST);
            }

            $type = $type === 'office' ? 'office' : 'online';

            // Parse the optional attendee list.
            $attendeeIds = [];
            $attendees = trim($attendees);
            if ($attendees !== '') {
                foreach (explode(',', $attendees) as $a) {
                    $a = trim($a);
                    if ($a !== '') {
                        $attendeeIds[] = $a;
                    }
                }
            }

            $organiser = $this->userSession->getUser();
            if ($organiser === null) {
                return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
            }

            $suggestions = $this->suggestionService->suggest(
                $teamId,
                $date,
                $organiser->getUID(),
                $type,
                $attendeeIds,
                5,
            );

            $this->telemetry->incrementSuggestWizardUses();

            return new JSONResponse(['suggestions' => $suggestions]);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'suggestTimes');
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/presence/suggest-timeslots
     *   ?date=YYYY-MM-DD&half=0|1&duration=60&attendees=uid1,uid2
     *
     * Stage-two: given a half-day the user picked from suggestTimes, find
     * the best concrete time-of-day windows of `duration` minutes within
     * that half, ranked by how many invited attendees have no
     * personal-calendar conflict.
     *
     * Returns up to 3 distinct non-overlapping windows. `date` and `half`
     * identify the half-day; `duration` defaults to 60 minutes (clamped
     * server-side to a sane range).
     *
     * SEC: same gates as suggestTimes — team membership required, and the
     * presence module must be enabled both globally and per-team. Because
     * this endpoint reads other attendees' personal-calendar event times,
     * the membership gate is what entitles the caller to coordinate with
     * those people. The endpoint never returns event content (titles,
     * UIDs, descriptions, locations, attendees) — only how many attendees
     * are free in each candidate window and the window's start/end.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function suggestTimeslots(
        string $teamId,
        string $date = '',
        int $half = 0,
        int $duration = 60,
        string $attendees = '',
        string $type = 'online',
        string $buildingName = '',
    ): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);

            $globalOn = $this->config->getAppValue(Application::APP_ID, 'presence_module_enabled', '1') === '1';
            $teamOn   = (bool)($this->teamPresence->getConfig($teamId)['presence_enabled'] ?? false);
            if (!$globalOn || !$teamOn) {
                return new JSONResponse(['error' => 'Presence module is not enabled'], Http::STATUS_FORBIDDEN);
            }

            $date = trim($date);
            if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return new JSONResponse(['error' => 'A valid date (YYYY-MM-DD) is required'], Http::STATUS_BAD_REQUEST);
            }
            $d = \DateTime::createFromFormat('Y-m-d', $date);
            if ($d === false || $d->format('Y-m-d') !== $date) {
                return new JSONResponse(['error' => 'A valid date (YYYY-MM-DD) is required'], Http::STATUS_BAD_REQUEST);
            }

            if ($half !== 0 && $half !== 1) {
                return new JSONResponse(['error' => 'half must be 0 (morning) or 1 (afternoon)'], Http::STATUS_BAD_REQUEST);
            }

            // The service clamps; we also reject obviously bad values here
            // so the API contract is clear rather than silently coercing.
            if ($duration < 1 || $duration > 24 * 60) {
                return new JSONResponse(['error' => 'duration must be between 1 and 1440 minutes'], Http::STATUS_BAD_REQUEST);
            }

            $attendeeIds = [];
            $attendees = trim($attendees);
            if ($attendees !== '') {
                foreach (explode(',', $attendees) as $a) {
                    $a = trim($a);
                    if ($a !== '') {
                        $attendeeIds[] = $a;
                    }
                }
            }

            $organiser = $this->userSession->getUser();
            if ($organiser === null) {
                return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
            }

            $result = $this->timeslotService->suggest(
                $teamId,
                $date,
                $half,
                $organiser->getUID(),
                $duration,
                $attendeeIds,
                $type === 'office' ? 'office' : 'online',
                trim($buildingName),
            );

            return new JSONResponse($result);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'suggestTimeslots');
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/presence/config
     * Returns { presence_enabled: bool, hide_reasons: bool }.
     *
     * SEC: team membership required (members need to know presence_enabled to
     * decide whether to show the tab).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getConfig(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            return new JSONResponse($this->teamPresence->getConfig($teamId));
        } catch (\Throwable $e) {
            return $this->mapError($e, 'getConfig');
        }
    }

    /**
     * PUT /api/v1/teams/{teamId}/presence/config
     * Body: { presence_enabled?: bool|int, hide_reasons?: bool|int }
     * Only keys present in the body are written; missing keys are unchanged.
     *
     * SEC: team admin (level ≥ 8) required.
     */
    #[NoAdminRequired]
    public function saveConfig(
        string $teamId,
        ?int $presence_enabled = null,
        ?int $hide_reasons     = null,
    ): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);

            $data = [];
            if ($presence_enabled !== null) {
                $data['presence_enabled'] = $presence_enabled;
            }
            if ($hide_reasons !== null) {
                $data['hide_reasons'] = $hide_reasons;
            }

            if (count($data) === 0) {
                return new JSONResponse(
                    ['error' => 'No fields provided'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            return new JSONResponse($this->teamPresence->saveConfig($teamId, $data));
        } catch (\Throwable $e) {
            return $this->mapError($e, 'saveConfig');
        }
    }

    // =========================================================================
    // Error mapper
    // =========================================================================

    private function mapError(\Throwable $e, string $action): JSONResponse {
        $msg = $e->getMessage();

        if ($e instanceof \InvalidArgumentException) {
            return new JSONResponse(['error' => $msg], Http::STATUS_BAD_REQUEST);
        }

        // MemberService throws plain \Exception for auth/membership failures.
        if (str_contains($msg, 'not a member') || str_contains($msg, 'not authenticated')
            || str_contains($msg, 'not have admin') || str_contains($msg, 'Insufficient')
        ) {
            return new JSONResponse(['error' => $msg], Http::STATUS_FORBIDDEN);
        }

        $this->logger->error(sprintf(
            '[TeamHub][PresenceTeamController] %s: %s', $action, $msg
        ), ['exception' => $e]);

        return new JSONResponse(['error' => $msg], Http::STATUS_INTERNAL_SERVER_ERROR);
    }
}
