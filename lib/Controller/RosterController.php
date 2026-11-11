<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Db\RosterAssignment;
use OCA\TeamHub\Exception\PresenceConflictException;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\RosterCalendarService;
use OCA\TeamHub\Service\RosterService;
use OCA\TeamHub\Service\RosterStatsService;
use OCA\TeamHub\Service\RosterTemplateService;
use OCA\TeamHub\Service\ShiftsModuleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Team-facing roster endpoints (v4.13.67, DESIGN §2.233).
 *
 * Every method does three things in this order, and the order is the point:
 * validate the input, check the caller may do this, then call the service.
 *
 *   - `#[NoAdminRequired]`, so a Nextcloud administrator is not special here.
 *   - **Reading** needs team membership and Shifts available: People on
 *     instance-wide, the instance licensed, and the team switched on
 *     (`ShiftsModuleService`). A roster is an internal document.
 *   - **Writing** needs team admin (`requireAdminLevel`, Circles level ≥ 8).
 *     The one exception is `claim`, which is a member acting on themselves and
 *     is gated on membership plus the shift being published and open.
 *
 * Error mapping comes from ExceptionResponseTrait (InvalidArgumentException →
 * 400, AccessDenied → 403), with PresenceConflictException → 409 added here
 * because an unavailable person is a conflict, not bad input.
 */
class RosterController extends Controller {
    use ExceptionResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private MemberService       $memberService,
        private RosterService       $roster,
        private ShiftsModuleService $shiftsModule,
        private RosterStatsService  $stats,
        private RosterTemplateService $templates,
        private RosterCalendarService $calendar,
        private IUserSession        $userSession,
        private LoggerInterface     $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // =====================================================================
    // Reading
    // =====================================================================

    /**
     * GET /api/v1/teams/{teamId}/roster?from=YYYY-MM-DD&to=YYYY-MM-DD
     *
     * SEC: team membership, People module on globally and for this team.
     * A member sees published shifts; a team admin also sees the draft.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(string $teamId, string $from = '', string $to = ''): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            return new JSONResponse(
                $this->roster->getTeamRoster($teamId, $from, $to, $this->isTeamAdmin($teamId)),
            );
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to load the roster', $teamId);
        }
    }

    // =====================================================================
    // Writing — team admin only
    // =====================================================================

    /**
     * POST /api/v1/teams/{teamId}/roster/shifts
     * Body: { date, shiftTypeId, roomId?, requiredCount?, role? }
     *
     * SEC: team admin.
     */
    #[NoAdminRequired]
    public function createShift(
        string $teamId,
        string $date = '',
        int $shiftTypeId = 0,
        ?int $roomId = null,
        int $requiredCount = 1,
        string $role = '',
    ): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            return new JSONResponse(
                $this->roster->createShift(
                    $teamId, $date, $shiftTypeId, $roomId, $requiredCount, $role, $this->uid(),
                ),
                Http::STATUS_CREATED,
            );
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to add the shift', $teamId);
        }
    }

    /**
     * PUT /api/v1/teams/{teamId}/roster/shifts/{shiftId}
     * Body: { requiredCount?, role?, roomId? }
     *
     * The date and the shift type are deliberately not editable — see
     * RosterService::updateShift().
     *
     * SEC: team admin.
     */
    #[NoAdminRequired]
    public function updateShift(
        string $teamId,
        int $shiftId,
        ?int $requiredCount = null,
        ?string $role = null,
        ?int $roomId = null,
    ): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            return new JSONResponse(
                $this->roster->updateShift($shiftId, $teamId, $requiredCount, $role, $roomId, $this->uid()),
            );
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to change the shift', $teamId);
        }
    }

    /**
     * DELETE /api/v1/teams/{teamId}/roster/shifts/{shiftId}
     *
     * SEC: team admin.
     */
    #[NoAdminRequired]
    public function deleteShift(string $teamId, int $shiftId): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            $this->roster->deleteShift($shiftId, $teamId, $this->uid());
            return new JSONResponse(['deleted' => true]);
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to remove the shift', $teamId);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/roster/shifts/{shiftId}/assign
     * Body: { userId }
     *
     * SEC: team admin. A member putting *themselves* on a shift uses `claim`.
     */
    #[NoAdminRequired]
    public function assign(string $teamId, int $shiftId, string $userId = ''): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            return new JSONResponse($this->roster->assign(
                $shiftId, $teamId, $userId, RosterAssignment::ORIGIN_ASSIGNED, $this->uid(),
            ));
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to assign the shift', $teamId);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/roster/shifts/{shiftId}/unassign
     * Body: { userId }
     *
     * SEC: team admin, **or** the person taking themselves off a shift they
     * claimed. An assigned shift is an instruction and is not theirs to drop:
     * that is what the swap flow will be for.
     */
    #[NoAdminRequired]
    public function unassign(string $teamId, int $shiftId, string $userId = ''): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }

            $caller = $this->uid();
            $target = $userId === '' ? $caller : $userId;

            if ($target !== $caller) {
                $this->memberService->requireAdminLevel($teamId);
            } else {
                $this->requireOwnClaim($shiftId, $caller, $teamId);
            }

            return new JSONResponse($this->roster->unassign($shiftId, $teamId, $target, $caller));
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to take the shift back', $teamId);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/roster/shifts/{shiftId}/claim
     *
     * A member taking an open shift. The service refuses a full shift, a
     * non-member and anybody who is unavailable that day.
     *
     * SEC: team membership. The shift must be published — a draft is not an
     * offer, and claiming from one would commit somebody to a plan.
     */
    #[NoAdminRequired]
    public function claim(string $teamId, int $shiftId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            return new JSONResponse($this->roster->assign(
                $shiftId, $teamId, $this->uid(), RosterAssignment::ORIGIN_CLAIMED, $this->uid(),
            ));
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to take the shift', $teamId);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/roster/publish
     * Body: { from, to }
     *
     * SEC: team admin.
     */
    #[NoAdminRequired]
    public function publish(string $teamId, string $from = '', string $to = ''): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            return new JSONResponse($this->roster->publishRange($teamId, $from, $to, $this->uid()));
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to publish the roster', $teamId);
        }
    }

    // =====================================================================
    // The team's own week templates (v4.13.74, DESIGN §2.240)
    //
    // A Nextcloud administrator defines the shift types in Admin, TeamHub; a
    // team admin defines this team's templates in Manage team, Module
    // settings. Every method here is therefore team admin, not Nextcloud
    // admin: `#[NoAdminRequired]` plus `requireAdminLevel`, the same gate the
    // rest of the writing half of this controller uses. `{templateId}` is
    // never trusted on its own — the mapper looks a template up **by id and
    // team together**, so an id belonging to another team is simply not found.
    // =====================================================================

    /**
     * GET /api/v1/teams/{teamId}/roster/templates
     *
     * SEC: team admin. A member never reads these: a template is a plan, and
     * a half-built plan is exactly what a member must not be shown (§2.234).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listTemplates(string $teamId): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            // The v4.13.75 debug line is gone: it answered its question. This
            // endpoint was returning the team's template correctly all along
            // (`templates: 1, active: 1, types: 3` on claudius), which is what
            // ruled the server out and sent the search to the picker, where
            // the control was disabled for want of a selection (§2.242).
            return new JSONResponse([
                'templates' => $this->templates->listForTeam($teamId),
                // The vocabulary travels with the templates: one request for
                // the editor, and a team admin cannot read the Nextcloud
                // administrator's own shift-type endpoint.
                'types'     => $this->templates->availableTypes(),
            ]);
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to load the roster templates', $teamId);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/roster/templates
     * Body: { name, description?, isActive?, lines: [{ dayOfWeek, shiftTypeId, role, requiredCount }] }
     *
     * SEC: team admin.
     */
    #[NoAdminRequired]
    public function createTemplate(
        string $teamId,
        string $name = '',
        string $description = '',
        bool $isActive = true,
        array $lines = [],
    ): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            return new JSONResponse(
                $this->templates->save(null, $teamId, $name, $description, $isActive, $lines, $this->uid()),
                Http::STATUS_CREATED,
            );
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to save the roster template', $teamId);
        }
    }

    /**
     * PUT /api/v1/teams/{teamId}/roster/templates/{templateId}
     * Body: as createTemplate.
     *
     * The week is replaced wholesale: the service's own contract, because a
     * diff would have to decide what an edited line means for a week already
     * laid out from it, and the answer is nothing.
     *
     * SEC: team admin.
     */
    #[NoAdminRequired]
    public function updateTemplate(
        string $teamId,
        int $templateId,
        string $name = '',
        string $description = '',
        bool $isActive = true,
        array $lines = [],
    ): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            return new JSONResponse(
                $this->templates->save($templateId, $teamId, $name, $description, $isActive, $lines, $this->uid()),
            );
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to save the roster template', $teamId);
        }
    }

    /**
     * DELETE /api/v1/teams/{teamId}/roster/templates/{templateId}
     *
     * Weeks already drafted from it are untouched — applying a template copies
     * into shifts, so nothing downstream needs this row to go on existing.
     *
     * SEC: team admin.
     */
    #[NoAdminRequired]
    public function deleteTemplate(string $teamId, int $templateId): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            $this->templates->delete($templateId, $teamId);
            return new JSONResponse(['deleted' => true]);
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to delete the roster template', $teamId);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/roster/apply-template
     * Body: { weekStart, templateId }
     *
     * Draft a week from one of this team's templates. The week comes out with
     * every place named by its role, so staffing it is assigning people.
     *
     * SEC: team admin.
     */
    #[NoAdminRequired]
    public function applyTemplate(string $teamId, string $weekStart = '', int $templateId = 0): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            // Read through the service so the team scope is applied by the
            // same lookup the editor uses: an id from another team is not
            // found, rather than found and then refused.
            $template = $this->templates->get($templateId, $teamId);
            if ($template === null) {
                return new JSONResponse(
                    ['error' => 'That template does not exist.'],
                    Http::STATUS_NOT_FOUND,
                );
            }
            return new JSONResponse(
                $this->roster->applyTemplateLines(
                    $teamId, $weekStart, $template['lines'], $this->uid(),
                ),
            );
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to draft the week', $teamId);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/roster/unpublish
     * Body: { from, to }
     *
     * Back to concept. Removes the presence rows the week wrote and withdraws
     * open hand-over requests; `published_by` is deliberately kept.
     *
     * SEC: team admin.
     */
    #[NoAdminRequired]
    public function unpublish(string $teamId, string $from = '', string $to = ''): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            return new JSONResponse($this->roster->unpublishRange($teamId, $from, $to, $this->uid()));
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to take the roster back to concept', $teamId);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/roster/notify
     * Body: { from, to }
     *
     * Announces the published week in the team's message stream.
     *
     * SEC: team admin.
     */
    #[NoAdminRequired]
    public function notify(string $teamId, string $from = '', string $to = ''): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            return new JSONResponse($this->roster->notifyTeam($teamId, $from, $to));
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to announce the roster', $teamId);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/roster/shifts/{shiftId}/handover
     * Body: { reason? }
     *
     * "Can somebody take this shift?" The row is written first and the Talk
     * post is best effort, so a team without Talk still gets a request.
     *
     * SEC: team membership, and the caller must be on the shift (service).
     */
    #[NoAdminRequired]
    public function requestHandover(string $teamId, int $shiftId, string $reason = ''): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            return new JSONResponse(
                $this->roster->requestHandover($shiftId, $teamId, $this->uid(), $reason),
                Http::STATUS_CREATED,
            );
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to ask about the shift', $teamId);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/roster/handovers/{requestId}/claim
     *
     * Taking over a shift from the link in the Talk message.
     *
     * SEC: team membership. The service re-checks the request's own team, so
     * a claim cannot be routed through a team the caller happens to be in.
     */
    #[NoAdminRequired]
    public function claimHandover(string $teamId, int $requestId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            return new JSONResponse($this->roster->claimHandover($requestId, $this->uid()));
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to take over the shift', $teamId);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/roster/handovers/{requestId}/withdraw
     *
     * SEC: team membership; the service refuses anybody but the requester.
     */
    #[NoAdminRequired]
    public function withdrawHandover(string $teamId, int $requestId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            return new JSONResponse($this->roster->withdrawHandover($requestId, $this->uid()));
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to withdraw the request', $teamId);
        }
    }

    // =====================================================================
    // Statistics and staffing
    // =====================================================================

    /**
     * GET /api/v1/teams/{teamId}/roster/stats/me?year=YYYY
     *
     * SEC: team membership. Always the caller's own figures — no uid is
     * accepted, so this endpoint cannot be used to read a colleague's year.
     *
     * v4.14.11 — the figures span **every** team the caller has shifts in,
     * not the team in the URL (DESIGN §2.253). The team still gates the
     * request, because it is the Roster tab the page is opened from.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function myStats(string $teamId, int $year = 0): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            return new JSONResponse($this->stats->forUser($this->uid(), $this->resolveYear($year)));
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to load your shift statistics', $teamId);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/roster/stats/team?year=YYYY
     *
     * SEC: **team admin**. It carries a line per member, which is a
     * comparison between colleagues and not a member's business.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function teamStats(string $teamId, int $year = 0): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            return new JSONResponse($this->stats->forTeam($teamId, $this->resolveYear($year)));
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to load the team statistics', $teamId);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/roster/shifts/{shiftId}/staffing
     *
     * Who may be put on this shift, and what stands in each person's way.
     *
     * SEC: **team admin** — it reports every member's availability, which is
     * the roster's business and not a colleague's.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function staffing(string $teamId, int $shiftId): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            return new JSONResponse($this->roster->staffingOptions($shiftId, $teamId));
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to load who is available', $teamId);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/roster/calendar?week=YYYY-MM-DD
     *
     * The *Add to my calendar* dialog: the caller's own published shifts in
     * the week that holds `week`, which are already in a calendar, the
     * calendars they own, and what they chose last time (DESIGN §2.250).
     *
     * SEC: team membership and Shifts available. Only ever the caller's own
     * shifts: the endpoint takes no user id. CSRF-protected: its only caller
     * is `@nextcloud/axios`, which sends the request token.
     */
    #[NoAdminRequired]
    public function calendarWeek(string $teamId, string $week = ''): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            return new JSONResponse($this->calendar->weekFor($teamId, $this->uid(), $week));
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to load your shifts for the calendar', $teamId);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/roster/calendar
     * Body: { week, calendarUri, transp: 'OPAQUE'|'TRANSPARENT' }
     *
     * Copy every published shift the caller has in that week into one of
     * their own calendars, as busy or free, in one action. Running it again
     * rewrites the same events.
     *
     * SEC: team membership and Shifts available; the caller's own published
     * shifts only; a calendar the caller owns only (checked in the service
     * against their own calendar home, not trusted from the body).
     */
    #[NoAdminRequired]
    public function exportToCalendar(string $teamId, string $week = '', string $calendarUri = '', string $transp = ''): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            if (($gate = $this->moduleGate($teamId)) !== null) {
                return $gate;
            }
            return new JSONResponse(
                $this->calendar->exportWeek($teamId, $this->uid(), $week, $calendarUri, $transp),
            );
        } catch (\Throwable $e) {
            return $this->fail($e, 'Failed to add your shifts to the calendar', $teamId);
        }
    }

    /** This year unless a sane one was asked for. */
    private function resolveYear(int $year): int {
        $now = (int)date('Y');
        return ($year >= 2000 && $year <= $now + 5) ? $year : $now;
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * The module gate, both halves of it.
     *
     * The instance switch AND the team switch, the same AND the suggestion
     * endpoint uses: a feature must not be reachable by a direct API call
     * because one of the two toggles is stale.
     */
    private function moduleGate(string $teamId): ?JSONResponse {
        try {
            $this->shiftsModule->requireAvailable($teamId);
            return null;
        } catch (\Throwable $e) {
            // People off, no licence, or the team has not switched Shifts on.
            // The message names which, because "switch it on" is an errand for
            // somebody whose instance is unlicensed.
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }
    }

    private function isTeamAdmin(string $teamId): bool {
        try {
            $this->memberService->requireAdminLevel($teamId);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * A member may only drop a shift they took themselves.
     *
     * @throws \InvalidArgumentException when it was assigned to them
     */
    private function requireOwnClaim(int $shiftId, string $uid, string $teamId): void {
        $origin = $this->roster->assignmentOrigin($shiftId, $teamId, $uid);
        if ($origin !== null && $origin !== RosterAssignment::ORIGIN_CLAIMED) {
            throw new \InvalidArgumentException(
                'This shift was assigned to you. Ask a team admin to change it.',
            );
        }
    }

    private function uid(): string {
        return $this->userSession->getUser()?->getUID() ?? '';
    }

    /** PresenceConflictException is a 409; everything else goes to the trait. */
    private function fail(\Throwable $e, string $message, string $teamId): JSONResponse {
        if ($e instanceof PresenceConflictException) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        }
        return $this->exceptionResponse($e, $message, ['teamId' => $teamId]);
    }
}
