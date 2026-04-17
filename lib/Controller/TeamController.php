<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\ActivityService;
use OCA\TeamHub\Service\FilesService;
use OCA\TeamHub\Service\IntravoxService;
use OCA\TeamHub\Service\MaintenanceService;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\MessageService;
use OCA\TeamHub\Service\ResourceService;
use OCA\TeamHub\Service\TeamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class TeamController extends Controller {
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
        private IUserSession $userSession,
        private IGroupManager $groupManager,
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
    #[NoCSRFRequired]
    public function createTeam(string $name, string $description = ''): JSONResponse {
        try {
            $team = $this->teamService->createTeam($name);
            return new JSONResponse($team, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create team', [
                'exception' => $e,
                'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
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
            $this->logger->error('Failed to update team', [
                'teamId'    => $teamId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function searchUsers(): JSONResponse {
        try {
            $q = $this->request->getParam('q', '');
            if (strlen($q) < 2) {
                return new JSONResponse([]);
            }
            $users = $this->memberService->searchUsers($q, 10);
            return new JSONResponse($users);
        } catch (\Throwable $e) {
            return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTeamMembers(string $teamId): JSONResponse {
        try {
            $members = $this->memberService->getTeamMembers($teamId);
            return new JSONResponse($members);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get team members', [
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
            $this->logger->error('[TeamController] getTeamApps failed', [
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
            $this->logger->error('[TeamController] updateTeamApps failed', [
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
            $this->logger->error('[TeamController] deleteTeamResource failed', [
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
    public function createIntravoxPage(string $teamId): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            $team   = $this->teamService->getTeam($teamId);
            $result = $this->intravoxService->createPage($teamId, $team['name'] ?? '');
            $this->intravoxService->invalidateSubPagesCache($teamId);
            if (isset($result['error'])) {
                return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
            }
            return new JSONResponse(['success' => true, 'result' => $result]);
        } catch (\Exception $e) {
            $this->logger->error('[TeamController] createIntravoxPage failed', [
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
            $this->logger->error('[TeamController] deleteIntravoxPage failed', [
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
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
        } catch (\Exception $e) {
            $status = str_contains($e->getMessage(), 'member') || str_contains($e->getMessage(), 'permissions')
                ? Http::STATUS_FORBIDDEN : Http::STATUS_INTERNAL_SERVER_ERROR;
            return new JSONResponse(['error' => $e->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTeamCalendarEvents(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            $events = $this->activityService->getTeamCalendarEvents($teamId);
            return new JSONResponse($events);
        } catch (\Exception $e) {
            $status = str_contains($e->getMessage(), 'member') || str_contains($e->getMessage(), 'permissions')
                ? Http::STATUS_FORBIDDEN : Http::STATUS_INTERNAL_SERVER_ERROR;
            return new JSONResponse(['error' => $e->getMessage()], $status);
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

        } catch (\Exception $e) {
            $status = str_contains($e->getMessage(), 'member') || str_contains($e->getMessage(), 'Access denied')
                ? Http::STATUS_FORBIDDEN : Http::STATUS_INTERNAL_SERVER_ERROR;
            $this->logger->error('[TeamHub][TeamController] getTeamFavoriteFiles failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], $status);
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

        } catch (\Exception $e) {
            $status = str_contains($e->getMessage(), 'member') || str_contains($e->getMessage(), 'Access denied')
                ? Http::STATUS_FORBIDDEN : Http::STATUS_INTERNAL_SERVER_ERROR;
            $this->logger->error('[TeamHub][TeamController] getTeamRecentFiles failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    public function createCalendarEvent(string $teamId): JSONResponse {
        try {
            $body        = $this->request->getParams();
            $title       = trim($body['title']       ?? '');
            $start       = trim($body['start']        ?? '');
            $end         = trim($body['end']          ?? '');
            $location    = trim($body['location']    ?? '');
            $description = trim($body['description'] ?? '');

            if ($title === '' || $start === '' || $end === '') {
                return new JSONResponse(['error' => 'title, start and end are required'], Http::STATUS_BAD_REQUEST);
            }

            $this->activityService->createCalendarEvent($teamId, $title, $start, $end, $location, $description);
            return new JSONResponse(['success' => true], Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
            $this->memberService->removeMember($teamId, $userId);
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

            $body = $this->request->getParams();
            $apps = isset($body['apps']) && is_array($body['apps']) ? $body['apps'] : [];
            $teamName = isset($body['teamName']) ? (string)$body['teamName'] : '';
            $results = $this->resourceService->createTeamResources($teamId, $apps, $teamName);
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
            $status = str_contains($e->getMessage(), 'permissions') || str_contains($e->getMessage(), 'member')
                ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
            return new JSONResponse(['error' => $e->getMessage()], $status);
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
            $db          = \OC::$server->get(\OCP\IDBConnection::class);
            $targetLevel = $this->memberService->getMemberLevelFromDb($db, $teamId, $userId);
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
            $msg = $e->getMessage();
            $status = str_contains($msg, 'permissions') || str_contains($msg, 'member') || str_contains($msg, 'owner')
                ? Http::STATUS_FORBIDDEN
                : Http::STATUS_INTERNAL_SERVER_ERROR;
            return new JSONResponse(['error' => $msg], $status);
        }
    }

    // NC admin required — no #[NoAdminRequired] attribute intentionally omitted
    #[NoCSRFRequired]
    public function intravoxDiagnostic(): JSONResponse {
        // Admin-only diagnostic: uses PHP Reflection to list all public methods
        // on IntraVox's PageService so we know exactly what to call.
        if (!\OC::$server->get(\OCP\IGroupManager::class)->isAdmin(\OC::$server->get(\OCP\IUserSession::class)->getUser()?->getUID() ?? '')) {
            return new JSONResponse(['error' => 'Admin required'], Http::STATUS_FORBIDDEN);
        }
        try {
            $info = ['installed' => $this->intravoxService->isInstalled()];
            if (!$info['installed']) {
                return new JSONResponse($info);
            }
            $pageService = \OC::$server->get(\OCA\IntraVox\Service\PageService::class);
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
            $this->logger->error('[TeamController] searchAdminGroups failed', [
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
