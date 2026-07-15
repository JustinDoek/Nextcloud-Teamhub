<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\ActivityService;
use OCA\TeamHub\Service\DeckService;
use OCA\TeamHub\Service\FilesService;
use OCA\TeamHub\Service\IntravoxService;
use OCA\TeamHub\Service\MaintenanceService;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\MessageService;
use OCA\TeamHub\Service\MilestoneService;
use OCA\TeamHub\Service\ResourceService;
use OCA\TeamHub\Service\TaskService;
use OCA\TeamHub\Service\TeamService;
use OCA\TeamHub\Service\TimelineService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class TeamController extends Controller {
    use ExceptionResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private TeamService $teamService,
        private MemberService $memberService,
        private ResourceService $resourceService,
        private ActivityService $activityService,
        private MessageService $messageService,
        private IntravoxService $intravoxService,
        private FilesService $filesService,
        private MaintenanceService $maintenanceService,
        private TaskService $taskService,
        private TimelineService $timelineService,
        private MilestoneService $milestoneService,
        // v3.98.2 — for the createDeckStack endpoint powering the Compass
        // "Define workstreams" Planning-phase activity.
        private DeckService $deckService,
        private IConfig $config,
        private \OCA\TeamHub\Service\RoomDiscoveryService $roomDiscovery,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private \OCP\App\IAppManager $appManager,
        private \OCP\IDBConnection $db,
        // Container is required only for optional-app class lookups
        // (Deck's CardMapper, IntraVox's PageService) that we cannot
        // import as constructor types without hard-linking to those
        // apps at compile time. See the deckDiagnostic /
        // intravoxDiagnostic endpoints.
        private \Psr\Container\ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listTeams(): JSONResponse {
        try {
            $teams = $this->teamService->getUserTeams();
            return new JSONResponse($teams);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list teams', [
                'exception' => $e,
                'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to load teams'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTeam(string $teamId): JSONResponse {
        try {
            $team = $this->teamService->getTeam($teamId);
            return new JSONResponse($team);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function createTeam(string $name, string $description = ''): JSONResponse {
        try {
            $team = $this->teamService->createTeam($name);
            return new JSONResponse($team, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to create team');
        }
    }

    #[NoAdminRequired]
    public function updateTeam(string $teamId): JSONResponse {
        try {
            $body = $this->request->getParams();
            if (isset($body['description']) || isset($body['config'])) {
                // Both description and config changes require team admin/owner.
                $this->memberService->requireAdminLevel($teamId);
            }
            if (isset($body['description'])) {
                $this->teamService->updateTeamDescription($teamId, (string)$body['description']);
            }
            if (isset($body['config'])) {
                $this->teamService->updateTeamConfig($teamId, (int)$body['config']);
            }
            // Do NOT call getTeam() here — after a raw SQL config write, Circles'
            // in-process session cache is inconsistent and getCircle() will return
            // "Circle not found" for the rest of this request, causing a 400 error
            // which the frontend interprets as the team being gone.
            // Return a minimal success response instead; the frontend will re-fetch
            // the full team list on the next navigation.
            return new JSONResponse(['success' => true, 'id' => $teamId]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to update team', [
                'teamId' => $teamId,
            ]);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function searchUsers(): JSONResponse {
        try {
            $q = (string)$this->request->getParam('q', '');
            // M-5 — bound the query. Search terms are always short in
            // practice; without an upper bound a client can post arbitrarily
            // large strings to a public endpoint.
            if (strlen($q) < 2 || strlen($q) > 200) {
                return new JSONResponse([]);
            }
            $teamId = (string)($this->request->getParam('teamId', ''));
            // Reject obviously malformed teamIds cheaply — the mapper
            // bind protects against injection but not against 10 000
            // pointless SELECTs from a fuzzer.
            if ($teamId !== '' && !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $teamId)) {
                return new JSONResponse([]);
            }
            $users = $this->memberService->searchUsers($q, 10, $teamId);
            return new JSONResponse($users);
        } catch (\Throwable $e) {
            // M-4 — log unexpected failures. Return a distinctive envelope
            // so the frontend can tell "empty result" from "server error".
            $this->logger->error('[TeamHub][TeamController] searchUsers failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(
                ['error' => 'Search failed'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTeamMembers(string $teamId): JSONResponse {
        try {
            // Returns {members: [...], effective_count: int, has_more: bool}
            $data = $this->memberService->getTeamMembers($teamId);
            return new JSONResponse($data);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][TeamController] Failed to get team members', [
                'teamId' => $teamId,
                'exception' => $e,
                'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Flat deduplicated list of all users with effective access to the team.
     * Used by the members widget (Members + Tomorrow + Search tabs) and the
     * @mention autocomplete. Each row carries userId/displayName plus, when
     * available, email/phone/ncStatus for the widget rendering.
     *
     * Response envelope:
     *   { members: [ ... ], talkAvailable: bool }
     *
     * `talkAvailable` is a per-request fact (Spreed enabled for the current
     * user) — surfaced here so the widget can decide whether to show the
     * Talk contact icon at all. Legacy consumers that read only `members`
     * continue to work unchanged.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getAllEffectiveMembers(string $teamId): JSONResponse {
        try {
            $data = $this->memberService->getAllEffectiveMembers($teamId);
            $talkAvailable = $this->memberService->isTalkAvailableForCurrentUser();
            return new JSONResponse([
                'members'        => $data,
                'talkAvailable'  => $talkAvailable,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][TeamController] Failed to get all effective members', [
                'teamId' => $teamId,
                'exception' => $e,
                'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Structured member breakdown for the Manage Team → Members tab.
     * Returns {direct: [...], groups: [...], circles: [...], effective_count: int}.
     * Requires admin or owner level (enforced in service).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getMembersForManage(string $teamId): JSONResponse {
        try {
            $data = $this->memberService->getMembersForManage($teamId);
            return new JSONResponse($data);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][TeamController] Failed to get manage members', [
                'teamId' => $teamId,
                'exception' => $e,
                'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTeamResources(string $teamId): JSONResponse {
        try {
            $resources = $this->resourceService->getTeamResources($teamId);
            return new JSONResponse($resources);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get team resources', [
                'teamId' => $teamId,
                'exception' => $e,
                'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['talk' => null, 'files' => null, 'calendar' => null, 'deck' => null]);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTeamApps(string $teamId): JSONResponse {
        try {
            $apps = $this->teamService->getTeamApps($teamId);
            return new JSONResponse($apps);
        } catch (\Exception $e) {
            $this->logger->error('[TeamHub][TeamController] getTeamApps failed', [
                'teamId' => $teamId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Enable or disable a built-in app for a team.
     *
     * Payload: { apps: [{ app_id: string, enabled: bool }] }
     *
     * When enabling: creates the resource (Talk room / Deck board / etc.) and
     * grants the circle access. When disabling: fully deletes the resource
     * (Option B — hard delete, all data removed).
     *
     * Only team admins/owners may call this (enforced by MemberService level check).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function updateTeamApps(string $teamId, array $apps): JSONResponse {
        try {
            // Only team admins and owners may enable/disable apps (hard-deletes data on disable)
            $this->memberService->requireAdminLevel($teamId);

            $team = $this->teamService->getTeam($teamId);
            $teamName = $team['name'] ?? 'Team';
            $results = [];

            foreach ($apps as $app) {
                $appId   = $app['app_id'] ?? null;
                $enabled = isset($app['enabled']) ? (bool)$app['enabled'] : true;

                if (!$appId) {
                    continue;
                }

                $resourceKey = $this->appIdToResourceKey($appId);

                if ($enabled) {
                    // All apps including intravox now provision a resource on enable
                    $createResult = $this->resourceService->createTeamResources($teamId, [$resourceKey], $teamName);
                    $results[$appId] = $createResult[$resourceKey] ?? ['error' => 'unknown'];
                } else {
                    // All apps including intravox delete their resource on disable
                    $deleteResult = $this->resourceService->deleteTeamResource($teamId, $resourceKey);
                    $results[$appId] = $deleteResult;
                }

                // Persist the enabled flag regardless of resource op outcome
                $this->teamService->updateTeamApps($teamId, [[
                    'app_id'  => $appId,
                    'enabled' => $enabled,
                    'config'  => null,
                ]]);
            }

            return new JSONResponse(['success' => true, 'results' => $results]);
        } catch (\Exception $e) {
            $this->logger->error('[TeamHub][TeamController] updateTeamApps failed', [
                'teamId' => $teamId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function deleteTeamResource(string $teamId, string $app): JSONResponse {
        // Allowlist valid app values — reject anything unexpected before it reaches the service
        $allowed = ['spreed', 'files', 'calendar', 'deck', 'intravox'];
        if (!in_array($app, $allowed, true)) {
            return new JSONResponse(['error' => 'Invalid app identifier'], Http::STATUS_BAD_REQUEST);
        }

        try {
            // Only team admins and owners may hard-delete resources
            $this->memberService->requireAdminLevel($teamId);

            $resourceKey = $this->appIdToResourceKey($app);
            $result = $this->resourceService->deleteTeamResource($teamId, $resourceKey);
            return new JSONResponse(['success' => true, 'result' => $result]);
        } catch (\Exception $e) {
            $this->logger->error('[TeamHub][TeamController] deleteTeamResource failed', [
                'teamId' => $teamId,
                'app'    => $app,
                'error'  => $e->getMessage(),
                'app_id' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Map a Vue-side app_id ('spreed', 'files', 'calendar', 'deck', 'intravox')
     * to the resource key used by ResourceService ('talk', 'files', 'calendar', 'deck', 'intravox').
     */
    private function appIdToResourceKey(string $appId): string {
        return match($appId) {
            'spreed' => 'talk',
            default  => $appId,
        };
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getIntravoxSubPages(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            $team   = $this->teamService->getTeam($teamId);
            $pages  = $this->intravoxService->getSubPages($teamId, $team['name'] ?? '');
            return new JSONResponse($pages);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getIntravoxTeamPage(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            $team = $this->teamService->getTeam($teamId);
            $page = $this->intravoxService->getTeamPage($teamId, $team['name'] ?? '');
            return new JSONResponse($page);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function createIntravoxPage(string $teamId): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            $team   = $this->teamService->getTeam($teamId);
            // Project Teams (v3.88.x) — optional projectMode ('basic'|'advanced')
            // seeds the 9-element charter for Advanced projects only. Absent/other
            // values keep the existing blank-page behaviour unchanged.
            $projectMode = $this->request->getParam('projectMode');
            $result = $this->intravoxService->createPage($teamId, $team['name'] ?? '', $projectMode);
            $this->intravoxService->invalidateSubPagesCache($teamId);
            if (isset($result['error'])) {
                return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
            }
            return new JSONResponse(['success' => true, 'result' => $result]);
        } catch (\Exception $e) {
            $this->logger->error('[TeamHub][TeamController] createIntravoxPage failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function deleteIntravoxPage(string $teamId): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            $team   = $this->teamService->getTeam($teamId);
            $result = $this->intravoxService->deletePage($teamId, $team['name'] ?? '');
            $this->intravoxService->invalidateSubPagesCache($teamId);
            return new JSONResponse(['success' => true, 'result' => $result]);
        } catch (\Exception $e) {
            $this->logger->error('[TeamHub][TeamController] deleteIntravoxPage failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function invalidateIntravoxCache(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            $this->intravoxService->invalidateSubPagesCache($teamId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function leaveTeam(string $teamId): JSONResponse {
        try {
            $this->memberService->leaveTeam($teamId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            // indirect_member is a sentinel from MemberService — pass it through
            // so the frontend can show a tooltip rather than a generic error.
            $code = $e->getMessage() === 'indirect_member'
                ? Http::STATUS_FORBIDDEN
                : Http::STATUS_BAD_REQUEST;
            return new JSONResponse(['error' => $e->getMessage()], $code);
        }
    }

    /**
     * Mark that the current user has just seen this team's messages.
     * Called whenever the user navigates to a team. Clears the unread indicator.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function markTeamSeen(string $teamId): JSONResponse {
        try {
            $this->messageService->markTeamSeen($teamId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTeamActivity(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            $limit = (int)($this->request->getParam('limit', 25));
            $limit = max(1, min(100, $limit));
            $since = (int)($this->request->getParam('since', 0));
            $items = $this->activityService->getTeamActivity($teamId, $limit, $since);
            return new JSONResponse(['activities' => $items]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Team operation failed');
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTeamCalendarEvents(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            $events = $this->activityService->getTeamCalendarEvents($teamId);
            return new JSONResponse($events);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Team operation failed');
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/tasks
     *
     * Returns upcoming VTODO tasks from the team calendar.
     * Requires the Tasks app to be installed and the user to be a team member.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTeamTasks(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);

            if (!$this->taskService->isTasksAppAvailable()) {
                return new JSONResponse([]);
            }

            $tasks = $this->taskService->getTeamTasks($teamId);
            return new JSONResponse($tasks);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load team tasks', [
                'teamId' => $teamId,
            ]);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/timeline
     *
     * Returns date-anchored events from Calendar, Decisions, and Deck
     * for a given time window.
     *
     * Query params:
     *   from (int, required) — window start as Unix timestamp
     *   to   (int, required) — window end   as Unix timestamp
     *
     * Returns: { events: [...], stacks: [...] }
     *
     * `stacks` (Session 3, Planning-phase swimlane view) is the full,
     * date-independent list of the team's connected Deck stacks (workstreams),
     * ordered to match Deck's own stack order — so a stack with no cards in
     * [from, to] can still render as an empty lane. Deck-specific and
     * independently try-caught so a Deck failure never breaks `events`.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTimeline(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);

            $from = (int)$this->request->getParam('from', 0);
            $to   = (int)$this->request->getParam('to',   0);

            if ($from <= 0 || $to <= 0 || $to < $from) {
                return new JSONResponse(
                    ['error' => 'from and to must be valid Unix timestamps with to >= from'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // Cap window to 1 year to prevent runaway queries.
            $maxWindow = 366 * 24 * 60 * 60;
            if (($to - $from) > $maxWindow) {
                $to = $from + $maxWindow;
            }

            $events = $this->timelineService->getEvents($teamId, $from, $to);

            try {
                $stacks = $this->timelineService->getDeckStacks($teamId);
            } catch (\Throwable $e) {
                $stacks = [];
                $this->logger->warning('[TeamHub][TeamController] getDeckStacks failed', [
                    'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            return new JSONResponse(['events' => $events, 'stacks' => $stacks]);

        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load timeline', [
                'teamId' => $teamId,
            ]);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/timeline/config
     *
     * Returns the timeline enabled state for this team.
     *
     * Storage: NC app-config keyed `timeline_enabled_<teamId>` = "1"|"0".
     * Default is "1" (enabled) — teams that have never touched the setting
     * see the Timeline tab. App-config is used (rather than a dedicated
     * table) because the data is a single team-scoped boolean with no
     * other associated state; adding a table for that would be overkill.
     */
    #[NoAdminRequired]
    public function getTimelineConfig(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            $stored = $this->config->getAppValue(Application::APP_ID, 'timeline_enabled_' . $teamId, '1');
            return new JSONResponse([
                'timeline_enabled' => $stored === '1',
            ]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Team operation failed');
        }
    }

    /**
     * PUT /api/v1/teams/{teamId}/timeline/config
     *
     * Updates the timeline enabled state. Body: { timeline_enabled: 0|1 }.
     * Team admin required (mirrors the DecisionController saveConfig auth).
     */
    #[NoAdminRequired]
    public function saveTimelineConfig(string $teamId): JSONResponse {
        try {
            // Same admin gate as DecisionController::saveConfig — only team
            // admins can toggle a built-in integration on/off for the team.
            $this->memberService->requireAdminLevel($teamId);

            $raw = $this->request->getParam('timeline_enabled');
            // Accept bool, 0/1, "0"/"1" — coerce to a strict 0/1 string for storage.
            $enabled = (string)((int)(bool)$raw);
            $this->config->setAppValue(Application::APP_ID, 'timeline_enabled_' . $teamId, $enabled);

            return new JSONResponse([
                'timeline_enabled' => $enabled === '1',
            ]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to save timeline config', [
                'teamId' => $teamId,
            ]);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/messages/config
     *
     * Returns the Messages integration enabled state for this team.
     *
     * Storage: NC app-config keyed `messages_enabled_<teamId>` = "1"|"0".
     * Default is "1" (enabled) — teams that have never touched the setting
     * see the Message stream, PostMessageForm, and any message-related
     * surfaces. Follows the same shape as the Timeline toggle above.
     */
    #[NoAdminRequired]
    public function getMessagesConfig(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            $stored = $this->config->getAppValue(Application::APP_ID, 'messages_enabled_' . $teamId, '1');
            return new JSONResponse([
                'messages_enabled' => $stored === '1',
            ]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Team operation failed');
        }
    }

    /**
     * PUT /api/v1/teams/{teamId}/messages/config
     *
     * Updates the Messages enabled state. Body: { messages_enabled: 0|1 }.
     * Team admin required (mirrors the timeline/decisions toggle auth).
     */
    #[NoAdminRequired]
    public function saveMessagesConfig(string $teamId): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);

            $raw = $this->request->getParam('messages_enabled');
            $enabled = (string)((int)(bool)$raw);
            $this->config->setAppValue(Application::APP_ID, 'messages_enabled_' . $teamId, $enabled);

            return new JSONResponse([
                'messages_enabled' => $enabled === '1',
            ]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to save messages config', [
                'teamId' => $teamId,
            ]);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/budget/config
     *
     * Per-team on/off toggle for the Budget tab. Same NC-app-config storage
     * pattern as the Timeline toggle: `budget_enabled_<teamId>` = "1"|"0",
     * default "1" so newly-created Advanced projects show the tab out of
     * the box. Only surfaced in the UI when project.mode === 'advanced' —
     * this endpoint is Advanced-only in practice but is not gated on it here,
     * mirroring how getTimelineConfig / saveTimelineConfig don't gate on the
     * timeline being non-empty.
     */
    #[NoAdminRequired]
    public function getBudgetConfig(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            $stored = $this->config->getAppValue(Application::APP_ID, 'budget_enabled_' . $teamId, '1');
            return new JSONResponse([
                'budget_enabled' => $stored === '1',
            ]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Team operation failed');
        }
    }

    /**
     * PUT /api/v1/teams/{teamId}/budget/config
     * Body: { budget_enabled: 0|1 }.  Team admin required.
     */
    #[NoAdminRequired]
    public function saveBudgetConfig(string $teamId): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);

            $raw = $this->request->getParam('budget_enabled');
            $enabled = (string)((int)(bool)$raw);
            $this->config->setAppValue(Application::APP_ID, 'budget_enabled_' . $teamId, $enabled);

            return new JSONResponse([
                'budget_enabled' => $enabled === '1',
            ]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to save budget config', [
                'teamId' => $teamId,
            ]);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/time/config
     *
     * Per-team on/off toggle for the Time investment tab (v3.96.0). Same
     * NC-app-config storage pattern as the Budget toggle:
     * `time_enabled_<teamId>` = "1"|"0", default "1" so newly-created Advanced
     * projects show the tab out of the box. Only surfaced in the UI when
     * project.mode === 'advanced'.
     */
    #[NoAdminRequired]
    public function getTimeConfig(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            $stored = $this->config->getAppValue(Application::APP_ID, 'time_enabled_' . $teamId, '1');
            return new JSONResponse([
                'time_enabled' => $stored === '1',
            ]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Team operation failed');
        }
    }

    /**
     * PUT /api/v1/teams/{teamId}/time/config
     * Body: { time_enabled: 0|1 }.  Team admin required.
     */
    #[NoAdminRequired]
    public function saveTimeConfig(string $teamId): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);

            $raw = $this->request->getParam('time_enabled');
            $enabled = (string)((int)(bool)$raw);
            $this->config->setAppValue(Application::APP_ID, 'time_enabled_' . $teamId, $enabled);

            return new JSONResponse([
                'time_enabled' => $enabled === '1',
            ]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to save time config', [
                'teamId' => $teamId,
            ]);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/milestones
     *
     * List Timeline milestones for a team — Manage Team → Integration
     * settings → Timeline block. Admin-gated (MilestoneService enforces
     * this); regular members see milestones rendered on the Timeline tab
     * itself via getTimeline(), not through this endpoint.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getMilestones(string $teamId): JSONResponse {
        try {
            return new JSONResponse(['items' => $this->milestoneService->listForTeam($teamId)]);
        } catch (\Exception $e) {
            return $this->milestoneErrorResponse($e, 'getMilestones');
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/milestones/pick (v3.97.5)
     * Member-gated milestone list, used by the decision-compose picker.
     * Same shape as getMilestones but only requires team membership. Data
     * is already effectively visible to every member via the Timeline tab
     * and the project-health widget.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function pickMilestones(string $teamId): JSONResponse {
        try {
            return new JSONResponse(['items' => $this->milestoneService->listForTeamAsMember($teamId)]);
        } catch (\Exception $e) {
            return $this->milestoneErrorResponse($e, 'pickMilestones');
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/deck/stacks (v3.98.2)
     * Body: { title: string (required, ≤255) }
     *
     * Creates a new stack on the team's Deck board. Admin-gated.
     * Called from the ProjectSwimlanesModal — the Planning-phase
     * "Define workstreams" activity. Team-creation no longer seeds
     * default To do / In progress / Done stacks for Advanced projects;
     * this endpoint is how admins add lanes as they're known.
     */
    #[NoAdminRequired]
    public function createDeckStack(string $teamId): JSONResponse {
        try {
            // v3.98.2 — admin gate lives here, not in DeckService, to avoid
            // the MemberService → ResourceService → DeckService cycle that
            // NC's DI container walks into if DeckService takes a hard
            // MemberService dependency. See DeckService::createStackOnTeamBoard
            // docblock for the full story.
            $this->memberService->requireAdminLevel($teamId);

            $title = (string)($this->request->getParam('title', ''));
            $result = $this->deckService->createStackOnTeamBoard($teamId, $title);
            if (isset($result['error'])) {
                $status = $result['error'] === 'Deck app not installed'
                    ? Http::STATUS_NOT_FOUND
                    : Http::STATUS_BAD_REQUEST;
                return new JSONResponse(['error' => $result['error']], $status);
            }
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to create stack');
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/milestones
     * Body: { label: string (required, ≤255), date?: string 'YYYY-MM-DD' }
     */
    #[NoAdminRequired]
    public function createMilestone(string $teamId): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
            }
            $label = (string)$this->request->getParam('label', '');
            $date  = $this->request->getParam('date');

            $this->logger->debug('[TeamHub][TeamController] createMilestone', [
                'teamId' => $teamId, 'label' => $label, 'date' => $date, 'app' => Application::APP_ID,
            ]);

            $row = $this->milestoneService->create($teamId, $label, $date, $user->getUID());
            return new JSONResponse($row, Http::STATUS_CREATED);
        } catch (\OCA\TeamHub\Exception\LicenseGateException $e) {
            return new JSONResponse([
                'error' => $e->getMessage(), 'licenseGate' => true,
                'enforcementLevel' => $e->getEnforcementLevel(),
            ], Http::STATUS_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->milestoneErrorResponse($e, 'createMilestone');
        }
    }

    /**
     * PUT /api/v1/teams/{teamId}/milestones/{milestoneId}
     * Body: { label: string (required, ≤255), date?: string 'YYYY-MM-DD' }
     */
    #[NoAdminRequired]
    public function updateMilestone(string $teamId, int $milestoneId): JSONResponse {
        try {
            $label = (string)$this->request->getParam('label', '');
            $date  = $this->request->getParam('date');

            $this->logger->debug('[TeamHub][TeamController] updateMilestone', [
                'teamId' => $teamId, 'milestoneId' => $milestoneId, 'app' => Application::APP_ID,
            ]);

            $row = $this->milestoneService->update($teamId, $milestoneId, $label, $date);
            return new JSONResponse($row);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->milestoneErrorResponse($e, 'updateMilestone');
        }
    }

    /**
     * DELETE /api/v1/teams/{teamId}/milestones/{milestoneId}
     */
    #[NoAdminRequired]
    public function deleteMilestone(string $teamId, int $milestoneId): JSONResponse {
        try {
            $this->milestoneService->delete($teamId, $milestoneId);
            return new JSONResponse(['ok' => true]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->milestoneErrorResponse($e, 'deleteMilestone');
        }
    }

    private function milestoneErrorResponse(\Throwable $e, string $context): JSONResponse {
        return $this->exceptionResponse($e, ucfirst($context) . ' failed');
    }

    /**
     * POST /api/v1/teams/{teamId}/tasks
     *
     * Creates a VTODO task in the team calendar.
     * Body: { title: string, duedate?: string (ISO 8601), description?: string }
     */
    #[NoAdminRequired]
    public function createTeamTask(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);

            $title       = trim((string)$this->request->getParam('title', ''));
            $duedate     = $this->request->getParam('duedate') ?: null;
            $description = $this->request->getParam('description') ?: null;
            $calendarId  = $this->request->getParam('calendarId') ? (int)$this->request->getParam('calendarId') : null;

            if ($title === '') {
                return new JSONResponse(['error' => 'Title is required'], Http::STATUS_BAD_REQUEST);
            }

            $result = $this->taskService->createTeamTask($teamId, $title, $duedate, $description, $calendarId);
            return new JSONResponse($result, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to create team task', [
                'teamId' => $teamId,
            ]);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/files/shared
     *
     * Returns files and folders shared directly with this team circle by its members,
     * paginated (newest first). The team folder share itself is excluded.
     *
     * Query params: page (1-based, default 1), limit (default 10, max 50)
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTeamSharedFiles(string $teamId): JSONResponse {
        try {
            $this->logger->debug('[TeamHub][TeamController] getTeamSharedFiles — teamId: ' . $teamId, [
                'app' => 'teamhub',
            ]);

            // Membership check — throws if not a member.
            $this->memberService->requireMemberLevel($teamId);

            // Resolve current uid for node lookups in FilesService.
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
            }
            $uid = $user->getUID();

            // The dedicated Shared-files widget was folded into the Filecenter
            // widget as an always-on tab (its per-team enable/disable toggle
            // was removed). We still resolve $resources to find the team folder
            // ID we should exclude from the results, but no longer gate on the
            // (now-removed) shared_files flag. The frontend already hides this
            // whole widget when Files integration is off for the team, so this
            // endpoint is only reachable in the right context.
            $resources = $this->resourceService->getTeamResources($teamId);

            // Pagination params — clamp limit to 1–50.
            $page   = max(1, (int)$this->request->getParam('page', 1));
            $limit  = min(50, max(1, (int)$this->request->getParam('limit', 10)));
            $offset = ($page - 1) * $limit;

            // Team folder ID to exclude (null when no team folder configured).
            $teamFolderId = isset($resources['files']['folder_id'])
                ? (int)$resources['files']['folder_id']
                : null;

            $result = $this->filesService->getSharedWithTeam($teamId, $uid, $teamFolderId, $limit, $offset);

            $this->logger->debug('[TeamHub][TeamController] getTeamSharedFiles — returning ' . count($result['items']) . ' items', [
                'total' => $result['total'], 'page' => $page, 'app' => 'teamhub',
            ]);

            return new JSONResponse([
                'items' => $result['items'],
                'total' => $result['total'],
                'page'  => $page,
                'limit' => $limit,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('[TeamHub][TeamController] getTeamSharedFiles failed', [
                'teamId' => $teamId,
                'error'  => $e->getMessage(),
                'app'    => 'teamhub',
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/files/favorites
     *
     * Returns files in the team folder that the current user has starred.
     * Requires the user to be a team member.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTeamFavoriteFiles(string $teamId): JSONResponse {
        try {
            $this->logger->debug('[TeamHub][TeamController] getTeamFavoriteFiles — teamId: ' . $teamId, [
                'app' => Application::APP_ID,
            ]);

            $this->memberService->requireMemberLevel($teamId);

            $uid = $this->userSession->getUser()?->getUID();
            if ($uid === null) {
                return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
            }

            // Resolve the team folder ID from the share table via ResourceService.
            $resources = $this->resourceService->getTeamResources($teamId);
            if (empty($resources['files']['folder_id'])) {
                $this->logger->debug('[TeamHub][TeamController] getTeamFavoriteFiles — no files resource', [
                    'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
                return new JSONResponse([]);
            }

            $folderId = (int)$resources['files']['folder_id'];
            $files    = $this->filesService->getFavoriteFiles($folderId, $uid);

            $this->logger->debug('[TeamHub][TeamController] getTeamFavoriteFiles — returning ' . count($files) . ' files', [
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);

            return new JSONResponse($files);

        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load favorite files', [
                'teamId' => $teamId,
            ]);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/files/recent
     *
     * Returns the 5 most recently modified files in the team folder,
     * newest first.
     * Requires the user to be a team member.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTeamRecentFiles(string $teamId): JSONResponse {
        try {
            $this->logger->debug('[TeamHub][TeamController] getTeamRecentFiles — teamId: ' . $teamId, [
                'app' => Application::APP_ID,
            ]);

            $this->memberService->requireMemberLevel($teamId);

            $uid = $this->userSession->getUser()?->getUID();
            if ($uid === null) {
                return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
            }

            $resources = $this->resourceService->getTeamResources($teamId);
            if (empty($resources['files']['folder_id'])) {
                $this->logger->debug('[TeamHub][TeamController] getTeamRecentFiles — no files resource', [
                    'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
                return new JSONResponse([]);
            }

            $folderId = (int)$resources['files']['folder_id'];
            $files    = $this->filesService->getRecentFiles($folderId, $uid, 5);

            $this->logger->debug('[TeamHub][TeamController] getTeamRecentFiles — returning ' . count($files) . ' files', [
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);

            return new JSONResponse($files);

        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load recent files', [
                'teamId' => $teamId,
            ]);
        }
    }

    #[NoAdminRequired]
    public function createCalendarEvent(string $teamId): JSONResponse {
        try {
            // Gate: caller must be a team member. Without this, any logged-in
            // NC user could POST to this endpoint and write events into a
            // team's calendar (and now, after the RoomVox integration, also
            // book physical meeting rooms via that team's API token). The
            // gate was missing prior to v3.59.23; this restores parity with
            // every other team-scoped endpoint in TeamHub.
            $this->memberService->requireMemberLevel($teamId);

            $body        = $this->request->getParams();
            $title       = trim($body['title']       ?? '');
            $start       = trim($body['start']        ?? '');
            $end         = trim($body['end']          ?? '');
            $location    = trim($body['location']    ?? '');
            $description = trim($body['description'] ?? '');
            $calendarId  = isset($body['calendarId']) ? (int)$body['calendarId'] : null;
            // Optional fields surfaced for the meeting wizard. Defaults
            // preserve the prior behaviour for other callers (AddEventModal,
            // ScheduleMeetingModal) that don't send these:
            //   includeTalk: bool — when false, no Talk URL is embedded even
            //     if the team has a room. Default true (= prior behaviour).
            //   categories: string — comma-separated CATEGORIES for the ical.
            $includeTalk = !array_key_exists('includeTalk', $body)
                ? true
                : !($body['includeTalk'] === false || $body['includeTalk'] === 0 || $body['includeTalk'] === '0' || $body['includeTalk'] === '');
            $categories  = trim((string)($body['categories'] ?? ''));
            $roomEmail   = trim((string)($body['roomEmail'] ?? ''));
            $roomName    = trim((string)($body['roomName']  ?? ''));
            $roomId      = trim((string)($body['roomId']    ?? ''));

            // Attendee uids — comma-separated string from the wizard, or
            // an array if a future caller sends it that way. Empty means
            // no per-attendee invitations (event lands only in the team
            // calendar).
            $attendeeUids = [];
            if (isset($body['attendees'])) {
                $raw = $body['attendees'];
                if (is_array($raw)) {
                    foreach ($raw as $a) {
                        $a = trim((string)$a);
                        if ($a !== '') {
                            $attendeeUids[] = $a;
                        }
                    }
                } elseif (is_string($raw)) {
                    foreach (explode(',', $raw) as $a) {
                        $a = trim($a);
                        if ($a !== '') {
                            $attendeeUids[] = $a;
                        }
                    }
                }
            }

            if ($title === '' || $start === '' || $end === '') {
                return new JSONResponse(['error' => 'title, start and end are required'], Http::STATUS_BAD_REQUEST);
            }

            $uid = $this->activityService->createCalendarEvent(
                $teamId, $title, $start, $end, $location, $description,
                $calendarId ?: null, $includeTalk, $categories,
                $roomEmail, $roomName, $roomId, $attendeeUids
            );
            return new JSONResponse([
                'success'  => true,
                'eventUid' => $uid,
                'start'    => $start,
                'title'    => $title,
            ], Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }


    /**
     * GET /api/v1/teams/{teamId}/rooms
     *
     * Returns the list of bookable rooms visible to the current user, used
     * by the meeting wizard's room picker. Returns [] when RoomVox is not
     * installed (= "if roomvox is enabled" gate per session brief) or when
     * no rooms are discoverable.
     *
     * SEC: team-membership required. We do not surface rooms a user can't
     * already see in NC Calendar — IResourceManager applies its own
     * permission model.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listRooms(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            $rooms = $this->roomDiscovery->listAvailableRooms();
            return new JSONResponse(['rooms' => $rooms]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to list rooms', [
                'teamId'    => $teamId,
                'exception' => $e->getMessage(),
                'app'       => Application::APP_ID,
            ]);
            return new JSONResponse(['rooms' => []]);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/calendar/events/week
     *
     * Returns VEVENT objects whose DTSTART falls within the week identified by
     * the `weekStart` query parameter (ISO 8601 datetime string, e.g. 2026-05-11T00:00:00).
     * The week is always Mon–Sun in the server's local timezone.
     *
     * All team members may call this endpoint.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getCalendarEventsForWeek(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);

            $weekStartParam = trim((string)$this->request->getParam('weekStart', ''));
            if ($weekStartParam === '') {
                // Default to current week Monday.
                $weekStartDt = new \DateTime('monday this week');
                $weekStartDt->setTime(0, 0, 0);
            } else {
                try {
                    $weekStartDt = new \DateTime($weekStartParam);
                    $weekStartDt->setTime(0, 0, 0);
                } catch (\Exception $e) {
                    return new JSONResponse(['error' => 'Invalid weekStart parameter'], Http::STATUS_BAD_REQUEST);
                }
            }

            // Week ends Sunday 23:59:59 — use +7 days so the upper bound is exclusive.
            $weekEndDt = clone $weekStartDt;
            $weekEndDt->modify('+7 days');

            $this->logger->debug('[TeamHub][TeamController] getCalendarEventsForWeek', [
                'teamId'    => $teamId,
                'weekStart' => $weekStartDt->format('c'),
                'weekEnd'   => $weekEndDt->format('c'),
                'app'       => Application::APP_ID,
            ]);

            $events = $this->activityService->getTeamCalendarEventsForWeek(
                $teamId,
                $weekStartDt->getTimestamp(),
                $weekEndDt->getTimestamp(),
            );

            return new JSONResponse($events);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load calendar events', [
                'teamId' => $teamId,
            ]);
        }
    }

    /**
     * DELETE /api/v1/teams/{teamId}/calendar/events
     *
     * Deletes one or more calendar events by their (calendarId, uri) pairs.
     * Body: { events: [{ calendarId: int, uri: string, title: string }] }
     *
     * All team members may delete events. Each deletion is audit-logged as
     * calendar.event_deleted.
     */
    #[NoAdminRequired]
    public function deleteCalendarEvents(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);

            $body   = $this->request->getParams();
            $events = $body['events'] ?? [];

            if (!is_array($events) || count($events) === 0) {
                return new JSONResponse(['error' => 'events array is required and must not be empty'], Http::STATUS_BAD_REQUEST);
            }

            // Basic structural validation — reject obviously malformed entries.
            foreach ($events as $ev) {
                if (!isset($ev['calendarId'], $ev['uri']) || (int)$ev['calendarId'] <= 0 || trim((string)$ev['uri']) === '') {
                    return new JSONResponse(['error' => 'Each event must have calendarId (int > 0) and uri (string)'], Http::STATUS_BAD_REQUEST);
                }
            }

            $this->logger->debug('[TeamHub][TeamController] deleteCalendarEvents', [
                'teamId' => $teamId, 'count' => count($events), 'app' => Application::APP_ID,
            ]);

            $result = $this->activityService->deleteCalendarEvents($teamId, $events);
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to delete calendar events', [
                'teamId' => $teamId,
            ]);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function browseAllTeams(): JSONResponse {
        try {
            $teams = $this->teamService->browseAllTeams();
            return new JSONResponse($teams);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function requestJoinTeam(string $teamId): JSONResponse {
        try {
            $this->memberService->requestJoinTeam($teamId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    // -------------------------------------------------------------------------
    // Manage Team endpoints (admin/owner only, enforced in service layer)
    // -------------------------------------------------------------------------

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function updateTeamDescription(string $teamId, string $description): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            $this->teamService->updateTeamDescription($teamId, $description);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function removeMember(string $teamId, string $userId): JSONResponse {
        try {
            // type=user (default), group, or circle — maps to Circles user_type
            $typeStr  = (string)($this->request->getParam('type', 'user'));
            $userType = match ($typeStr) {
                'group'  => 2,
                'circle' => 16,
                default  => 1,
            };
            $this->memberService->removeMember($teamId, $userId, $userType);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function updateMemberLevel(string $teamId, string $userId): JSONResponse {
        try {
            $body = $this->request->getParams();
            if (!isset($body['level'])) {
                return new JSONResponse(['error' => 'level is required'], Http::STATUS_BAD_REQUEST);
            }
            $members = $this->memberService->updateMemberLevel($teamId, $userId, (int)$body['level']);
            return new JSONResponse($members);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function canCreateTeam(): JSONResponse {
        return new JSONResponse(['canCreate' => $this->memberService->canCurrentUserCreateTeam()]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getPendingRequests(string $teamId): JSONResponse {
        try {
            $requests = $this->memberService->getPendingRequests($teamId);
            return new JSONResponse($requests);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function approveRequest(string $teamId, string $userId): JSONResponse {
        try {
            $this->memberService->approveRequest($teamId, $userId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function rejectRequest(string $teamId, string $userId): JSONResponse {
        try {
            $this->memberService->rejectRequest($teamId, $userId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function inviteMembers(string $teamId): JSONResponse {
        try {
            $this->memberService->requireModeratorLevel($teamId);
            $body = $this->request->getParams();
            $members = isset($body['members']) && is_array($body['members']) ? $body['members'] : [];
            $results = $this->memberService->inviteMembers($teamId, $members);
            return new JSONResponse($results);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function createTeamResources(string $teamId): JSONResponse {
        try {
            // Resource creation (Talk room, Deck board, Calendar, Files) is a
            // destructive/provisioning operation — requires team admin or owner.
            $this->memberService->requireAdminLevel($teamId);

            $body     = $this->request->getParams();
            $apps     = isset($body['apps']) && is_array($body['apps']) ? $body['apps'] : [];
            $teamName = isset($body['teamName']) ? (string)$body['teamName'] : '';
            $names    = isset($body['names']) && is_array($body['names']) ? $body['names'] : [];
            // Project Teams (v3.90.x) — when 'advanced', DeckService seeds the
            // "Project management" stack + starter cards. Any other value keeps
            // today's behaviour unchanged.
            $projectMode = isset($body['projectMode']) ? (string)$body['projectMode'] : null;
            $results  = $this->resourceService->createTeamResources($teamId, $apps, $teamName, $names, $projectMode);

            // Persist the wizard's full app enabled/disabled state when provided.
            // The wizard sends appStates for ALL apps (both selected and deselected)
            // so that teamhub_team_apps has a complete picture from the moment the
            // team is created. Without this, ManageTeamView falls back to
            // defaultEnabled=true for all apps, and the pages widget stays hidden
            // because there is no intravox row in teamhub_team_apps.
            $appStates = isset($body['appStates']) && is_array($body['appStates']) ? $body['appStates'] : [];
            if (!empty($appStates)) {
                $stateRows = [];
                // Only toggle-driven apps go to teamhub_team_apps.
                // Resource-backed apps (spreed/files/calendar/deck) are now
                // registry-driven — createTeamResources() writes their rows directly.
                $toggleOnlyAppIds = ['intravox'];
                foreach ($appStates as $as) {
                    $appId = isset($as['app_id']) ? (string)$as['app_id'] : '';
                    if ($appId !== '' && in_array($appId, $toggleOnlyAppIds, true)) {
                        $stateRows[] = [
                            'app_id'  => $appId,
                            'enabled' => !empty($as['enabled']),
                            'config'  => null,
                        ];
                    }
                }
                if (!empty($stateRows)) {
                    $this->teamService->updateTeamApps($teamId, $stateRows);
                }
            }

            // Always 200 — per-app errors are in the results payload, not HTTP status
            return new JSONResponse($results);
        } catch (\Throwable $e) {
            // This should never happen since createTeamResources catches internally,
            // but log and return gracefully if it does
            $this->logger->error('Unexpected error in createTeamResources', ['exception' => $e, 'app' => Application::APP_ID]);
            return new JSONResponse(['_fatal' => $e->getMessage()], Http::STATUS_OK);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTeamConfig(string $teamId): JSONResponse {
        try {
            $config = $this->teamService->getTeamConfig($teamId);
            return new JSONResponse(['config' => $config]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function updateTeamConfig(string $teamId): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            $body = $this->request->getParams();
            $config = isset($body['config']) ? (int)$body['config'] : 0;
            $this->teamService->updateTeamConfig($teamId, $config);
            return new JSONResponse(['success' => true, 'config' => $config]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to update team config', [
                'teamId' => $teamId,
            ]);
        }
    }

    

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function deleteTeam(string $teamId): JSONResponse {
        try {
            $this->teamService->deleteTeam($teamId);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/transfer-owner
     *
     * Transfers ownership of the team to another user.
     * Only the current owner (level 9) may call this endpoint.
     * The target user must already be a member of the team — team owners
     * cannot promote outsiders (that is the admin-only flow in MaintenanceController).
     *
     * Body: application/x-www-form-urlencoded  userId=uid
     *
     * Must be form-encoded (not JSON) so NC's dispatcher can inject $userId
     * as a typed method argument. See AdminSettings.vue assignOwner for the
     * same pattern.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function transferOwner(string $teamId, string $userId = ''): JSONResponse {
        try {
            $this->memberService->requireOwnerLevel($teamId);

            $userId = trim($userId);
            if ($userId === '') {
                return new JSONResponse(['error' => 'userId is required'], Http::STATUS_BAD_REQUEST);
            }
            if (strlen($userId) > 64) {
                return new JSONResponse(['error' => 'Invalid userId'], Http::STATUS_BAD_REQUEST);
            }

            // Verify the target is already a member of this team. Team owners can only
            // transfer to existing members; promoting outsiders is an NC-admin action.
            $targetLevel = $this->memberService->getMemberLevelFromDb($this->db, $teamId, $userId);
            if ($targetLevel === 0) {
                return new JSONResponse(
                    ['error' => 'Target user is not a member of this team'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // enforceNcAdmin=false: the requireOwnerLevel() check above + the
            // membership verification is the authorisation boundary for this path.
            $this->maintenanceService->assignOwner($teamId, $userId, false);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to transfer ownership', [
                'teamId' => $teamId,
            ]);
        }
    }

    // NC admin required — no #[NoAdminRequired] attribute intentionally omitted
    #[NoCSRFRequired]
    public function intravoxDiagnostic(): JSONResponse {
        // Admin-only diagnostic: uses PHP Reflection to list all public methods
        // on IntraVox's PageService so we know exactly what to call.
        $currentUid = $this->userSession->getUser()?->getUID();
        if ($currentUid === null || !$this->groupManager->isAdmin($currentUid)) {
            return new JSONResponse(['error' => 'Admin required'], Http::STATUS_FORBIDDEN);
        }
        try {
            $info = ['installed' => $this->intravoxService->isInstalled()];
            if (!$info['installed']) {
                return new JSONResponse($info);
            }
            $pageService = $this->container->get(\OCA\IntraVox\Service\PageService::class);
            $ref = new \ReflectionClass($pageService);
            $methods = [];
            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->getDeclaringClass()->getName() === $ref->getName()) {
                    $params = [];
                    foreach ($m->getParameters() as $p) {
                        $type = $p->getType() ? $p->getType()->getName() : 'mixed';
                        $params[] = $type . ' $' . $p->getName();
                    }
                    $methods[] = $m->getName() . '(' . implode(', ', $params) . ')';
                }
            }
            $info['methods'] = $methods;
            $info['class'] = $ref->getName();
            $info['file'] = $ref->getFileName();

            // Optional content-format probe (v3.88.x) — pass ?pageId=<id> to see the
            // raw shape IntraVox returns for an existing page's content, so TeamHub
            // can learn the real field names before writing content (never guessed).
            // Read-only: does not create, modify, or delete anything.
            $pageId = $this->request->getParam('pageId');
            if ($pageId !== null && $pageId !== '') {
                $probe = ['pageId' => $pageId];
                try {
                    $probe['getPage'] = $pageService->getPage($pageId);
                } catch (\Throwable $e) {
                    $probe['getPage_error'] = $e->getMessage();
                }
                try {
                    $probe['getCurrentPageContent'] = $pageService->getCurrentPageContent($pageId);
                } catch (\Throwable $e) {
                    $probe['getCurrentPageContent_error'] = $e->getMessage();
                }
                $info['contentProbe'] = $probe;
            }

            return new JSONResponse($info);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()]);
        }
    }

    /**
     * Reflection helper shared by the diagnostic endpoints — lists an object's
     * own public method signatures (declaring-class filtered, so inherited
     * framework methods don't clutter the output).
     */
    private function reflectPublicMethods(object $obj): array {
        $ref = new \ReflectionClass($obj);
        $methods = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() === $ref->getName()) {
                $params = [];
                foreach ($m->getParameters() as $p) {
                    $type = $p->getType() ? $p->getType()->getName() : 'mixed';
                    $params[] = $type . ' $' . $p->getName();
                }
                $methods[] = $m->getName() . '(' . implode(', ', $params) . ')';
            }
        }
        return $methods;
    }

    // NC admin required — no #[NoAdminRequired] attribute intentionally omitted
    #[NoCSRFRequired]
    public function deckDiagnostic(): JSONResponse {
        // Admin-only diagnostic (v3.90.x) — Deck card/assignee creation has no
        // precedent anywhere in TeamHub (AddTaskModal.vue calls Deck's own OCS
        // API from the frontend, bypassing this backend entirely, and has no
        // assignee field at all). Lists CardMapper's real methods (expected to
        // exist alongside the already-used StackMapper/BoardMapper/AclMapper)
        // plus defensive probes for whichever class actually writes assignees,
        // so the real API is confirmed before any write code is written.
        // Read-only: does not create, modify, or delete anything.
        $currentUid = $this->userSession->getUser()?->getUID();
        if ($currentUid === null || !$this->groupManager->isAdmin($currentUid)) {
            return new JSONResponse(['error' => 'Admin required'], Http::STATUS_FORBIDDEN);
        }
        try {
            $info = ['installed' => $this->appManager->isInstalled('deck')];
            if (!$info['installed']) {
                return new JSONResponse($info);
            }

            try {
                $cardMapper = $this->container->get(\OCA\Deck\Db\CardMapper::class);
                $info['CardMapper'] = [
                    'class'   => get_class($cardMapper),
                    'methods' => $this->reflectPublicMethods($cardMapper),
                ];
            } catch (\Throwable $e) {
                $info['CardMapper_error'] = $e->getMessage();
            }

            // Candidate assignee-write classes — probed defensively, each
            // failure is reported rather than aborting the whole diagnostic.
            $assigneeCandidates = [
                'AssignedUsersMapper' => \OCA\Deck\Db\AssignedUsersMapper::class,
                'CardService'         => \OCA\Deck\Service\CardService::class,
                'AssignmentService'   => \OCA\Deck\Service\AssignmentService::class,
            ];
            foreach ($assigneeCandidates as $label => $className) {
                try {
                    $instance = $this->container->get($className);
                    $info[$label] = [
                        'class'   => get_class($instance),
                        'methods' => $this->reflectPublicMethods($instance),
                    ];
                } catch (\Throwable $e) {
                    $info[$label . '_error'] = $e->getMessage();
                }
            }

            return new JSONResponse($info);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()]);
        }
    }

    // NC admin required — no #[NoAdminRequired] attribute intentionally omitted
    #[NoCSRFRequired]
    public function getAdminSettings(): JSONResponse {
        try {
            return new JSONResponse($this->teamService->getAdminSettings());
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /** Public: returns allowed invite types for the invite modal — no admin required */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getAllowedInviteTypes(): JSONResponse {
        return new JSONResponse(['types' => $this->memberService->getAllowedInviteTypes()]);
    }

    #[NoCSRFRequired]
    public function saveAdminSettings(): JSONResponse {
        try {
            $body = $this->request->getParams();
            if (!is_array($body)) {
                $body = [];
            }
            $this->teamService->saveAdminSettings($body);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoCSRFRequired]
    public function searchAdminGroups(): JSONResponse {
        try {
            $q     = trim((string)($this->request->getParam('q') ?? ''));
            $limit = 20;

            $groups = $this->groupManager->search($q, $limit);

            $result = [];
            foreach ($groups as $group) {
                $result[] = [
                    'id'          => $group->getGID(),
                    'displayName' => $group->getDisplayName() ?: $group->getGID(),
                ];
            }

            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][TeamController] searchAdminGroups failed', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function checkApps(): JSONResponse {
        return new JSONResponse($this->resourceService->checkInstalledApps());
    }

    

    

    

    

    /**
     * DEBUG: Re-insert the current user as owner of a circle that exists in DB
     * but is invisible to the Circles API (missing/corrupt member row).
     * GET /api/v1/debug/repair-membership/{teamId}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function repairCircleMembership(string $teamId): JSONResponse {
        try {
            $result = $this->teamService->repairCircleMembership($teamId);
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
