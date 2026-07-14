<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\TimeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * TimeController — Execution-phase Time investment (v3.96.0, Track E Session 5).
 *
 * Thin — all gating and business logic live in TimeService. Error mapping
 * mirrors BudgetController: 400 for validation, 403 for auth, 401 for
 * anonymous, 500 for everything else.
 */
class TimeController extends Controller {
    use ExceptionResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private TimeService     $timeService,
        private IUserSession    $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // ── READ ─────────────────────────────────────────────────────────────

    /** GET /api/v1/teams/{teamId}/time */
    #[NoAdminRequired]
    public function get(string $teamId): JSONResponse {
        try {
            return new JSONResponse($this->timeService->getProjectTime($teamId));
        } catch (\Throwable $e) {
            return $this->mapError($e, 'get');
        }
    }

    /** GET /api/v1/teams/{teamId}/time/members/{userId}/logs */
    #[NoAdminRequired]
    public function getMemberLogs(string $teamId, string $userId): JSONResponse {
        try {
            return new JSONResponse($this->timeService->getLogsForMember($teamId, $userId));
        } catch (\Throwable $e) {
            return $this->mapError($e, 'getMemberLogs');
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/time/loggable-cards?user_id=…
     *
     * Cards the given user is currently assigned to. `user_id` defaults to
     * the caller; non-admins can only query themselves.
     */
    #[NoAdminRequired]
    public function loggableCards(string $teamId): JSONResponse {
        try {
            $callerUid = $this->requireUser();
            $userId    = $this->request->getParam('user_id') ?: $callerUid;
            return new JSONResponse(
                $this->timeService->loggableCardsForUser($teamId, (string)$userId)
            );
        } catch (\Throwable $e) {
            return $this->mapError($e, 'loggableCards');
        }
    }

    // ── WRITE: project config ────────────────────────────────────────────

    /**
     * PUT /api/v1/teams/{teamId}/time
     * Body: time_view_min_level (int — 1|4|8).
     */
    #[NoAdminRequired]
    public function setConfig(string $teamId): JSONResponse {
        try {
            $uid   = $this->requireUser();
            $body  = $this->request->getParams();
            $level = (int)($body['time_view_min_level'] ?? 1);
            return new JSONResponse(
                $this->timeService->setProjectConfig($teamId, $level, $uid)
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'setConfig');
        }
    }

    // ── WRITE: project members ───────────────────────────────────────────

    /**
     * PUT /api/v1/teams/{teamId}/time/members/{userId}
     * Body: available_minutes (int, 0 = uncapped).
     */
    #[NoAdminRequired]
    public function upsertMember(string $teamId, string $userId): JSONResponse {
        try {
            $uid  = $this->requireUser();
            $body = $this->request->getParams();
            $available = (int)($body['available_minutes'] ?? 0);
            return new JSONResponse(
                $this->timeService->upsertMember($teamId, $userId, $available, $uid)
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'upsertMember');
        }
    }

    /** DELETE /api/v1/teams/{teamId}/time/members/{userId} */
    #[NoAdminRequired]
    public function removeMember(string $teamId, string $userId): JSONResponse {
        try {
            $uid = $this->requireUser();
            return new JSONResponse(
                $this->timeService->removeMember($teamId, $userId, $uid)
            );
        } catch (\Throwable $e) {
            return $this->mapError($e, 'removeMember');
        }
    }

    // ── WRITE: time logs ─────────────────────────────────────────────────

    /**
     * POST /api/v1/teams/{teamId}/time/logs
     * Body: card_id (int), user_id (string, optional — defaults to caller),
     *       minutes (int), description (string, optional), worked_at (int UTC ts).
     */
    #[NoAdminRequired]
    public function addLog(string $teamId): JSONResponse {
        try {
            $uid  = $this->requireUser();
            $body = $this->request->getParams();

            $cardId      = (int)($body['card_id'] ?? 0);
            $forUserId   = trim((string)($body['user_id'] ?? $uid));
            if ($forUserId === '') $forUserId = $uid;
            $minutes     = (int)($body['minutes'] ?? 0);
            $description = (string)($body['description'] ?? '');
            $workedAt    = (int)($body['worked_at'] ?? 0);

            return new JSONResponse(
                $this->timeService->addLog(
                    $teamId, $cardId, $forUserId, $minutes, $description, $workedAt, $uid
                )
            );
        } catch (\OCA\TeamHub\Exception\LicenseGateException $e) {
            return new JSONResponse([
                'error' => $e->getMessage(), 'licenseGate' => true,
                'enforcementLevel' => $e->getEnforcementLevel(),
            ], Http::STATUS_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'addLog');
        }
    }

    /** PUT /api/v1/teams/{teamId}/time/logs/{logId} */
    #[NoAdminRequired]
    public function updateLog(string $teamId, int $logId): JSONResponse {
        try {
            $uid  = $this->requireUser();
            $body = $this->request->getParams();

            $minutes     = (int)($body['minutes'] ?? 0);
            $description = (string)($body['description'] ?? '');
            $workedAt    = (int)($body['worked_at'] ?? 0);

            return new JSONResponse(
                $this->timeService->updateLog(
                    $teamId, $logId, $minutes, $description, $workedAt, $uid
                )
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'updateLog');
        }
    }

    /** DELETE /api/v1/teams/{teamId}/time/logs/{logId} */
    #[NoAdminRequired]
    public function deleteLog(string $teamId, int $logId): JSONResponse {
        try {
            $uid = $this->requireUser();
            return new JSONResponse(
                $this->timeService->deleteLog($teamId, $logId, $uid)
            );
        } catch (\Throwable $e) {
            return $this->mapError($e, 'deleteLog');
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function requireUser(): string {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \RuntimeException('Not authenticated');
        }
        return $user->getUID();
    }

    private function mapError(\Throwable $e, string $context): JSONResponse {
        return $this->exceptionResponse($e, ucfirst($context) . ' failed');
    }
}
