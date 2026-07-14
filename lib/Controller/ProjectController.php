<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\ClosingArtifactService;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\ProjectHealthService;
use OCA\TeamHub\Service\ProjectReadinessService;
use OCA\TeamHub\Service\ProjectService;
use OCP\IConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Project Teams API controller (keystone, v3.88.0).
 *
 * Thin — all gating and business logic live in ProjectService.
 *
 * Auth model:
 *   - get       requires team membership (level ≥ 1) — enforced in the service.
 *   - save      requires team admin (≥ 8) — wizard create + Basic→Advanced upgrade.
 *   - setPhase  requires team admin (≥ 8), advanced projects only.
 *
 * Error mapping:
 *   - \InvalidArgumentException                        → 400
 *   - MemberService 'not a member' / 'Insufficient'    → 403
 *   - not authenticated                                → 401
 *   - else                                             → 500
 */
class ProjectController extends Controller {
    use ExceptionResponseTrait;


    public function __construct(
        string $appName,
        IRequest $request,
        private ProjectService          $projectService,
        private ProjectHealthService    $projectHealthService,
        private ProjectReadinessService $projectReadinessService,
        // v3.99.0 — Closing phase artifact + archive-policy read.
        private ClosingArtifactService  $closingArtifactService,
        private MemberService           $memberService,
        private IConfig                 $config,
        private IUserSession            $userSession,
        private LoggerInterface         $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/teams/{teamId}/project
     * Returns { isProject, mode, phase, startDate, targetEnd }.
     */
    #[NoAdminRequired]
    public function get(string $teamId): JSONResponse {
        try {
            return new JSONResponse($this->projectService->getForTeam($teamId));
        } catch (\Throwable $e) {
            return $this->mapError($e, 'get');
        }
    }

    /**
     * PUT /api/v1/teams/{teamId}/project
     * Body: mode (required: basic|advanced), start_date / target_end (optional
     * Unix timestamps, or null to clear).
     */
    #[NoAdminRequired]
    public function save(string $teamId): JSONResponse {
        try {
            $uid  = $this->requireUser();
            $body = $this->request->getParams();

            if (!array_key_exists('mode', $body) || $body['mode'] === '') {
                return new JSONResponse(['error' => 'mode is required'], Http::STATUS_BAD_REQUEST);
            }

            $mode      = (string)$body['mode'];
            $startDate = $this->parseNullableTs($body, 'start_date');
            $targetEnd = $this->parseNullableTs($body, 'target_end');

            return new JSONResponse(
                $this->projectService->upsert($teamId, $mode, $startDate, $targetEnd, $uid)
            );
        } catch (\OCA\TeamHub\Exception\LicenseGateException $e) {
            // v3.100.0 — license gate on Advanced-team creation. The
            // frontend uses `enforcementLevel` to render the correct
            // banner (unlicensed / grace / soft-lock).
            return new JSONResponse([
                'error'            => $e->getMessage(),
                'licenseGate'      => true,
                'enforcementLevel' => $e->getEnforcementLevel(),
            ], Http::STATUS_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'save');
        }
    }

    /**
     * PUT /api/v1/teams/{teamId}/project/phase
     * Body: phase (required: initiation|planning|execution|closing).
     */
    #[NoAdminRequired]
    public function setPhase(string $teamId): JSONResponse {
        try {
            $uid  = $this->requireUser();
            $body = $this->request->getParams();

            if (!array_key_exists('phase', $body) || $body['phase'] === '') {
                return new JSONResponse(['error' => 'phase is required'], Http::STATUS_BAD_REQUEST);
            }

            return new JSONResponse(
                $this->projectService->setPhase($teamId, (string)$body['phase'], $uid)
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'setPhase');
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/project/health
     * Returns the project-health widget payload (v3.97.0, Track E Session 6):
     * { canView, phase, budgetTime, milestones, quality }.
     *
     * Never 403s on visibility denial — the service returns canView=false and
     * the frontend hides the widget. Only true auth failures (non-member,
     * unauthenticated) map to 401/403.
     */
    #[NoAdminRequired]
    public function getHealth(string $teamId): JSONResponse {
        try {
            return new JSONResponse($this->projectHealthService->getProjectHealth($teamId));
        } catch (\Throwable $e) {
            return $this->mapError($e, 'getHealth');
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/project/readiness (v3.98.0)
     *
     * Returns the phase-appropriate setup checklist that powers the
     * Project Compass panel. Advanced projects only; non-project /
     * Basic-mode teams get `{isProject: false}` and the Compass hides.
     */
    #[NoAdminRequired]
    public function getReadiness(string $teamId): JSONResponse {
        try {
            return new JSONResponse($this->projectReadinessService->computeReadiness($teamId));
        } catch (\Throwable $e) {
            return $this->mapError($e, 'getReadiness');
        }
    }

    /**
     * PUT /api/v1/teams/{teamId}/project/marks/{markType} (v3.99.1)
     * Body: { done: bool }
     *
     * Set or clear a user-confirmed Planning-phase mark timestamp. Used
     * for items where "done" can't be checked programmatically:
     *   - charter_configured — IntraVox charter has been filled in
     *   - kickoff_meeting    — kickoff meeting is on the calendar
     */
    #[NoAdminRequired]
    public function setMark(string $teamId, string $markType): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            $body = $this->request->getParams();
            $done = !empty($body['done']);
            return new JSONResponse($this->projectReadinessService->setMark($teamId, $markType, $done));
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'setMark');
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/project/closing/generate (v3.99.0)
     * Admin-gated. Generates the Closing artifact folder in the team's
     * Files, returns { filePath, generatedAt }.
     */
    #[NoAdminRequired]
    public function generateClosingArtifact(string $teamId): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            return new JSONResponse($this->closingArtifactService->generate($teamId));
        } catch (\Throwable $e) {
            return $this->mapError($e, 'generateClosingArtifact');
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/project/closing/status (v3.99.0)
     * Member-gated. Returns whether the artifact has been generated and
     * the file path. Never 500s — silent-degrades to generated=false.
     */
    #[NoAdminRequired]
    public function getClosingStatus(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            return new JSONResponse($this->closingArtifactService->getStatus($teamId));
        } catch (\Throwable $e) {
            return $this->mapError($e, 'getClosingStatus');
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/project/closing/archive-policy (v3.99.0)
     * Member-gated. Returns the effective admin archive settings plus a
     * dataLossWarning boolean the frontend uses to decide whether to show
     * the "all data will be lost" warning before triggering team archive.
     *
     * dataLossWarning = archiveBeforeDelete === '0' && archiveMode === 'hard'
     *   — no archive bundle AND immediate hard delete (no grace period).
     */
    #[NoAdminRequired]
    public function getArchivePolicy(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            $archiveBeforeDelete = $this->config->getAppValue('teamhub', 'archiveBeforeDelete', '1') === '1';
            $archiveMode = $this->config->getAppValue('teamhub', 'archiveMode', 'soft30');
            return new JSONResponse([
                'archiveBeforeDelete' => $archiveBeforeDelete,
                'archiveMode'         => $archiveMode,
                'dataLossWarning'     => !$archiveBeforeDelete && $archiveMode === 'hard',
            ]);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'getArchivePolicy');
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function requireUser(): string {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \RuntimeException('Not authenticated');
        }
        return $user->getUID();
    }

    /**
     * Read an optional Unix-timestamp field. Absent → null (leave/clear);
     * empty string / null → null; otherwise cast to int.
     */
    private function parseNullableTs(array $body, string $key): ?int {
        if (!array_key_exists($key, $body)) {
            return null;
        }
        $v = $body[$key];
        if ($v === '' || $v === null) {
            return null;
        }
        return (int)$v;
    }

    private function mapError(\Throwable $e, string $context): JSONResponse {
        return $this->exceptionResponse($e, ucfirst($context) . ' failed');
    }
}
