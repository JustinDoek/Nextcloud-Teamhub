<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\MyWork\Provider\OpenProjectWorkProvider;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\OpenProject\OpenProjectActivityService;
use OCA\TeamHub\Service\OpenProject\OpenProjectCapabilityService;
use OCA\TeamHub\Service\OpenProject\OpenProjectMeetingMirrorService;
use OCA\TeamHub\Service\OpenProject\OpenProjectMeetingService;
use OCA\TeamHub\Service\OpenProject\OpenProjectMessages;
use OCA\TeamHub\Service\OpenProject\OpenProjectModuleService;
use OCA\TeamHub\Service\OpenProject\OpenProjectNewsService;
use OCA\TeamHub\Service\OpenProject\OpenProjectProjectService;
use OCA\TeamHub\Service\OpenProject\OpenProjectWorkPackageService;
use OCA\TeamHub\Service\OpenProject\TeamOpenProjectLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * OpenProject Phase 1 endpoints (v4.9.3). See OPENPROJECT.md.
 *
 * Every team route gates on membership first and admin level where it
 * costs OpenProject requests; the services check again, so the controller
 * is not the only line. **This controller has no link write.** The link is
 * made by `POST /teams` (`TeamController::createTeam`, v4.9.4) as part of
 * creating the team, and removed only by an instance administrator from
 * Maintenance (`MaintenanceController::unlinkOpenProject`) — Justin,
 * 2026-09-12: the project is configured at creation, Manage team only
 * shows it. The one member-level write is `createWorkPackage` (v4.9.15):
 * a work package in the linked project, as the viewer, OpenProject
 * deciding. Every OpenProject failure is answered with a status and a
 * stable `code` (`OpenProjectResponseTrait`) — the frontend branches on
 * the code and shows the sentence; nothing here ever returns 200 with an
 * error body.
 *
 * Nextcloud's own per-user rate limit is applied to every route that
 * causes an OpenProject request, on top of TeamHub's caching and refresh
 * cooldown — a bouncing browser tab must never become a request storm.
 *
 * v4.9.16 — every route but `capabilities` starts with the module gate
 * (`OpenProjectResponseTrait::openProjectModuleGate()`): 403 with the code
 * when the OpenProject module is unlicensed or switched off. `capabilities`
 * stays open because it is how the wizard learns the module is off — its
 * `errorCode` carries the same code, and the card is hidden on it.
 */
class OpenProjectController extends Controller {
    use ExceptionResponseTrait;
    use OpenProjectResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private MemberService                 $memberService,
        private OpenProjectModuleService      $module,
        private OpenProjectCapabilityService  $capabilities,
        private OpenProjectProjectService     $projects,
        private OpenProjectWorkPackageService $workPackages,
        private TeamOpenProjectLinkService    $links,
        private OpenProjectMessages           $messages,
        // v4.9.7 — Phase 3: the team's attention summary (the My Work
        // provider's read plus the week's activity count), the project's
        // meetings for the Upcoming events widget, and the news mirror the
        // team home triggers.
        private OpenProjectActivityService    $activity,
        private OpenProjectMeetingService     $meetings,
        // v4.9.10 — the one-way copy of those meetings into the team calendar.
        private OpenProjectMeetingMirrorService $meetingMirror,
        private OpenProjectNewsService        $news,
        private OpenProjectWorkProvider       $workProvider,
        private IUserSession                  $userSession,
        private LoggerInterface               $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Capabilities
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/openproject/capabilities?probe=1&force=1
     *
     * Per user, not per team. `probe` asks OpenProject for `users/me`;
     * `force` bypasses the short cache of that answer.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function capabilities(bool $probe = false, bool $force = false): JSONResponse {
        try {
            return new JSONResponse($this->capabilities->getCapabilities($probe, $force));
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'Failed to read OpenProject capabilities');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Link
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/openproject/projects?q=…&limit=…
     *
     * Projects the *current user* may link — administered by them in
     * OpenProject, or public — for the team-creation wizard's picker. Not
     * team-scoped: the team does not exist yet when this is called. Gated on
     * the right to create a team, which is who will be linking.
     *
     * v4.9.4 — each project carries `linkedTeam`: null, or `{teamId, name}`
     * for the team that already links it (`name` only when the caller is a
     * member of that team). The picker shows a taken project as taken rather
     * than letting the creator fill in a wizard around it and be refused at
     * the end. Shown, not hidden: a creator whose project is absent would
     * conclude OpenProject does not list it, which is a different problem.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function searchProjects(string $q = '', int $limit = OpenProjectProjectService::SEARCH_LIMIT): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            if (!$this->memberService->canCurrentUserCreateTeam()) {
                return new JSONResponse(['error' => 'You are not allowed to create teams'], Http::STATUS_FORBIDDEN);
            }
            $userId   = $this->capabilities->requireReadable();
            $projects = $this->projects->search($userId, $q, $limit);
            $linked   = $this->links->linkedTeamsByProject(array_column($projects, 'id'));
            foreach ($projects as &$project) {
                $project['linkedTeam'] = $linked[$project['id']][0] ?? null;
            }
            unset($project);
            return new JSONResponse([
                'projects' => $projects,
                'limit'    => min(max(1, $limit), OpenProjectProjectService::SEARCH_LIMIT),
            ]);
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'Failed to search OpenProject projects');
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/openproject/link
     *
     * `{ link: {...} | null, capabilities: {...} }` — everything the settings
     * panel needs in one read. Capabilities are not probed here.
     */
    #[NoAdminRequired]
    public function getLink(string $teamId): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            $this->memberService->requireMemberLevel($teamId);
            return new JSONResponse([
                'link'         => $this->links->getLink($teamId),
                'capabilities' => $this->capabilities->getCapabilities(false),
            ]);
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'Failed to read the OpenProject link', ['teamId' => $teamId]);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/openproject/test
     *
     * The settings panel's "Test connection": a forced capability probe,
     * plus a read of the linked project when there is one. Team-admin
     * gated — it is a diagnostic, and it costs OpenProject requests.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function testConnection(string $teamId): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            $this->memberService->requireAdminLevel($teamId);
            $caps    = $this->capabilities->getCapabilities(true, true);
            $link    = $this->links->getLink($teamId);
            $project = null;
            $problem = null;

            if ($link !== null && $caps['errorCode'] === null) {
                try {
                    $row     = $this->links->requireLink($teamId);
                    $project = $this->projects->getProject($this->currentUserId(), $row->getProjectId());
                    $this->links->recordSuccessfulRead($row, $project);
                    $link    = $this->links->getLink($teamId);
                } catch (OpenProjectException $e) {
                    $problem = $this->describeOpenProjectCode($e->getErrorCode());
                }
            }

            return new JSONResponse([
                'capabilities' => $caps,
                'link'         => $link,
                'project'      => $project,
                'projectError' => $problem,
                'testedAt'     => time(),
            ]);
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'Failed to test the OpenProject connection', ['teamId' => $teamId]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Data
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/teams/{teamId}/openproject/overview?refresh=1
     *
     * The Project Overview widget's payload, as the current user.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function overview(string $teamId, bool $refresh = false): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            $this->memberService->requireMemberLevel($teamId);
            $userId  = $this->capabilities->requireReadable();
            $row     = $this->links->requireLink($teamId);
            $payload = $this->projects->overview($userId, $teamId, $row->getProjectId(), $row->getHost(), $refresh);
            if (!$payload['fromCache']) {
                $this->links->recordSuccessfulRead($row, $payload['project']);
                // v4.9.7 — the team home is the other place a connected
                // member reads the project; news written since the link was
                // made is mirrored into the stream here too, once.
                $this->news->mirrorForTeam($userId, $teamId);
            }
            return new JSONResponse($payload);
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'Failed to load the OpenProject overview', ['teamId' => $teamId]);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/openproject/work?section=upcoming&page=1&pageSize=10&refresh=1
     *
     * One page of the project's work packages, as the current user. Since
     * v4.9.5 the one section is `upcoming` — every assignee's dated, open
     * work, soonest due first — which feeds the team home's Upcoming tasks
     * widget. The viewer's own rows are in My Work (`OpenProjectWorkProvider`).
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function work(
        string $teamId,
        string $section = 'upcoming',
        int $page = 1,
        int $pageSize = OpenProjectWorkPackageService::DEFAULT_PAGE_SIZE,
        bool $refresh = false,
    ): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            $this->memberService->requireMemberLevel($teamId);
            $userId = $this->capabilities->requireReadable();
            $row    = $this->links->requireLink($teamId);
            return new JSONResponse($this->workPackages->section(
                $userId, $teamId, $row->getProjectId(), $row->getHost(), $section, $page, $pageSize, $refresh,
            ));
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'Failed to load OpenProject work packages', ['teamId' => $teamId]);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/openproject/work-packages/form
     *
     * OpenProject's create form for the linked project, as the current user
     * (v4.9.15): the types and assignees the viewer may pick in the Upcoming
     * tasks widget's "Create OpenProject work package" form. Member-gated;
     * OpenProject decides whether this viewer may add work packages at all
     * (403 → `permission_denied`), and the widget hides the action when the
     * `work` payload said so.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function workPackageForm(string $teamId): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            $this->memberService->requireMemberLevel($teamId);
            $userId = $this->capabilities->requireReadable();
            $row    = $this->links->requireLink($teamId);
            return new JSONResponse($this->workPackages->createForm($userId, $row->getProjectId()));
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'Failed to load the OpenProject form', ['teamId' => $teamId]);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/openproject/work-packages
     *
     * Create one work package in the linked project, as the current user
     * (v4.9.15) — the only OpenProject write a team member makes from the
     * team home. The body is what the form collected: subject (required),
     * typeId (required), assigneeId, dueDate (YYYY-MM-DD), description
     * (markdown). TeamHub checks shape and bounds; OpenProject checks the
     * rules and answers 422 with its sentence, returned here as 400 with
     * `validation_failed` and that sentence. 201 with the created row.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function createWorkPackage(
        string $teamId,
        string $subject = '',
        int $typeId = 0,
        ?int $assigneeId = null,
        ?string $dueDate = null,
        string $description = '',
    ): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            $this->memberService->requireMemberLevel($teamId);
            $userId = $this->capabilities->requireReadable();
            $row    = $this->links->requireLink($teamId);
            $item   = $this->workPackages->create($userId, $teamId, $row->getProjectId(), [
                'subject'     => $subject,
                'typeId'      => $typeId,
                'assigneeId'  => $assigneeId,
                'dueDate'     => $dueDate,
                'description' => $description,
            ]);
            return new JSONResponse($item, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'Failed to create the OpenProject work package', ['teamId' => $teamId]);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/openproject/meetings?limit=10&refresh=1
     *
     * The linked project's upcoming OpenProject meetings, as the current
     * user (v4.9.7) — the Upcoming events widget shows them beside the
     * team calendar's events, labelled OpenProject, opening in OpenProject.
     * Member-gated, rate-limited like the other reads.
     *
     * v4.9.10 — a fresh read also copies what it found into the team
     * calendar, one way (`OpenProjectMeetingMirrorService`); the response's
     * `sync` block says what that did, so the widget can reload the
     * calendar's own rows when something was written. A GET that writes:
     * the same shape as the news mirror on `overview` — the copy is a
     * side effect of reading as the viewer, which is the only moment
     * TeamHub can read OpenProject at all (OPENPROJECT.md §6).
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function meetings(string $teamId, int $limit = OpenProjectMeetingService::DEFAULT_LIMIT, bool $refresh = false): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            $this->memberService->requireMemberLevel($teamId);
            $userId = $this->capabilities->requireReadable();
            $row    = $this->links->requireLink($teamId);
            $read   = $this->meetings->upcoming(
                $userId, $teamId, $row->getProjectId(), $row->getHost(), $limit, $refresh,
            );
            if (!($read['fromCache'] ?? false)) {
                try {
                    $read['sync'] = $this->meetingMirror->syncForTeam(
                        $userId, $teamId, ['host' => $row->getHost(), 'projectId' => $row->getProjectId()], $read,
                    );
                } catch (\Throwable $e) {
                    // The copy is the widget's silent business; the rows stand.
                    $this->logger->debug('[TeamHub][OpenProjectController] meetings: calendar sync failed', [
                        'teamId' => $teamId, 'error' => $e->getMessage(),
                    ]);
                    $read['sync'] = ['state' => 'error', 'created' => 0, 'updated' => 0, 'removed' => 0, 'skipped' => 0];
                }
            }
            return new JSONResponse($read);
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'Failed to load OpenProject meetings', ['teamId' => $teamId]);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/openproject/attention
     *
     * The compact "your attention" block on the Project info widget
     * (v4.9.7): the viewer's overdue and due-this-week work packages, what
     * changed recently, the next milestone — the My Work provider's own
     * read over this one team, summarised, plus how much project activity
     * the last seven days held. Member-gated; every count is the viewer's.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function attention(string $teamId): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            $this->memberService->requireMemberLevel($teamId);
            $userId  = $this->capabilities->requireReadable();
            $row     = $this->links->requireLink($teamId);
            $summary = $this->workProvider->attentionSummary($userId, $teamId);

            $recent = ['count' => 0, 'state' => 'skipped'];
            try {
                $now  = time();
                $feed = $this->activity->feed($userId, [$teamId], $now - 7 * 86400, 0, 25);
                $recent = ['count' => count($feed['items']), 'state' => $feed['status']['state']];
            } catch (\Throwable $e) {
                $this->logger->debug('[TeamHub][OpenProjectController] attention: activity read failed', [
                    'teamId' => $teamId, 'error' => $e->getMessage(),
                ]);
            }
            $summary['recentActivity'] = $recent;
            $summary['projectId']      = $row->getProjectId();
            return new JSONResponse($summary);
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'Failed to load the OpenProject attention summary', ['teamId' => $teamId]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Error mapping
    // ─────────────────────────────────────────────────────────────────────

    /** The mapping itself lives in `OpenProjectResponseTrait` (v4.9.4). */
    protected function openProjectMessages(): OpenProjectMessages {
        return $this->messages;
    }

    /** For `OpenProjectResponseTrait::openProjectModuleGate()` (v4.9.16). */
    protected function openProjectModuleCode(): ?string {
        return $this->module->unavailableCode();
    }

    private function currentUserId(): string {
        return $this->userSession->getUser()?->getUID() ?? '';
    }
}
