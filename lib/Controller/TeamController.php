<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\TeamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class TeamController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private TeamService $teamService,
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
            $users = $this->teamService->searchUsers($q, 10);
            return new JSONResponse($users);
        } catch (\Throwable $e) {
            return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTeamMembers(string $teamId): JSONResponse {
        try {
            $members = $this->teamService->getTeamMembers($teamId);
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
            $resources = $this->teamService->getTeamResources($teamId);
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
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function updateTeamApps(string $teamId, array $apps): JSONResponse {
        try {
            $this->teamService->updateTeamApps($teamId, $apps);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function leaveTeam(string $teamId): JSONResponse {
        try {
            $this->teamService->leaveTeam($teamId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTeamActivity(string $teamId): JSONResponse {
        try {
            $limit = (int)($this->request->getParam('limit', 25));
            $limit = max(1, min(100, $limit));
            $since = (int)($this->request->getParam('since', 0));
            $items = $this->teamService->getTeamActivity($teamId, $limit, $since);
            return new JSONResponse(['activities' => $items]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTeamCalendarEvents(string $teamId): JSONResponse {
        try {
            $events = $this->teamService->getTeamCalendarEvents($teamId);
            return new JSONResponse($events);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
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
            $this->teamService->requestJoinTeam($teamId);
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
            $this->teamService->removeMember($teamId, $userId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getPendingRequests(string $teamId): JSONResponse {
        try {
            $requests = $this->teamService->getPendingRequests($teamId);
            return new JSONResponse($requests);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function approveRequest(string $teamId, string $userId): JSONResponse {
        try {
            $this->teamService->approveRequest($teamId, $userId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function rejectRequest(string $teamId, string $userId): JSONResponse {
        try {
            $this->teamService->rejectRequest($teamId, $userId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }


    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function inviteMembers(string $teamId): JSONResponse {
        try {
            $body = $this->request->getParams();
            $members = isset($body['members']) && is_array($body['members']) ? $body['members'] : [];
            $results = $this->teamService->inviteMembers($teamId, $members);
            return new JSONResponse($results);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function createTeamResources(string $teamId): JSONResponse {
        try {
            $body = $this->request->getParams();
            $apps = isset($body['apps']) && is_array($body['apps']) ? $body['apps'] : [];
            $teamName = isset($body['teamName']) ? (string)$body['teamName'] : '';
            $results = $this->teamService->createTeamResources($teamId, $apps, $teamName);
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
            $body = $this->request->getParams();
            $config = isset($body['config']) ? (int)$body['config'] : 0;
            $this->teamService->updateTeamConfig($teamId, $config);
            return new JSONResponse(['success' => true, 'config' => $config]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }


    /** DEBUG ONLY — inspect Talk/Deck table schemas */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function debugResourceTables(): JSONResponse {
        return new JSONResponse($this->teamService->debugResourceTables());
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

    #[NoAdminRequired]
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
        return new JSONResponse(['types' => $this->teamService->getAllowedInviteTypes()]);
    }

    #[NoCSRFRequired]
    public function saveAdminSettings(): JSONResponse {
        try {
            // getParams() only reads form/query params — for JSON body use getContent()
            $raw  = $this->request->getContent();
            $body = $raw ? json_decode($raw, true) : [];
            if (!is_array($body)) {
                $body = $this->request->getParams(); // fallback for form POST
            }
            $this->teamService->saveAdminSettings($body);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }


    /** DEBUG: test a single resource creation to diagnose 500 errors */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function testResource(): JSONResponse {
        try {
            $body = $this->request->getParams();
            $teamId   = $body['teamId']   ?? '';
            $app      = $body['app']      ?? 'calendar';
            $teamName = $body['teamName'] ?? 'Test Team';
            $results  = $this->teamService->createTeamResources($teamId, [$app], $teamName);
            return new JSONResponse(['result' => $results, 'success' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse([
                'success' => false,
                'error'   => $e->getMessage(),
                'trace'   => substr($e->getTraceAsString(), 0, 2000),
            ], Http::STATUS_OK);
        }
    }


    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function checkApps(): JSONResponse {
        return new JSONResponse($this->teamService->checkInstalledApps());
    }


    /** DEBUG ONLY */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function debugCirclesMethods(): JSONResponse {
        try {
            $info = $this->teamService->debugCirclesMethods();
            return new JSONResponse($info);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /** DEBUG: inspect raw activity rows for a team to tune the activity query */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function debugActivity(string $teamId): JSONResponse {
        try {
            return new JSONResponse($this->teamService->debugActivity($teamId));
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /** DEBUG: dump all circles DB vs probeCircles to find filter pattern */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function debugAllCircles(): JSONResponse {
        try {
            return new JSONResponse($this->teamService->debugAllCircles());
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /** DEBUG: compare DB config vs probeCircles config for a specific circle */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function debugCircleConfig(string $teamId): JSONResponse {
        try {
            $info = $this->teamService->debugCircleConfig($teamId);
            return new JSONResponse($info);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
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
