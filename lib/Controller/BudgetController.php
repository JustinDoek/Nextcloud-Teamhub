<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\BudgetService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * BudgetController — Execution-phase Budget page (v3.92.0, Track E Session 4).
 *
 * Thin — all gating and business logic live in BudgetService.
 *
 * Auth model:
 *   - GET  /budget                            — member  (≥ 1)
 *   - PUT  /budget                            — admin   (≥ 8)
 *   - PUT  /budget/lanes/{laneId}             — admin   (≥ 8)
 *   - POST /budget/lanes/{laneId}/expenses    — per-lane editMinLevel
 *   - PUT  /budget/lanes/{laneId}/expenses/id — per-lane editMinLevel
 *   - DELETE …                                — per-lane editMinLevel
 *
 * Error mapping follows the same pattern as ProjectController: 400 for
 * validation, 403 for auth, 401 for anonymous, 500 for everything else.
 */
class BudgetController extends Controller {
    use ExceptionResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private BudgetService   $budgetService,
        private IUserSession    $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/teams/{teamId}/budget
     */
    #[NoAdminRequired]
    public function get(string $teamId): JSONResponse {
        try {
            return new JSONResponse($this->budgetService->getProjectBudget($teamId));
        } catch (\Throwable $e) {
            return $this->mapError($e, 'get');
        }
    }

    /**
     * PUT /api/v1/teams/{teamId}/budget
     * Body: total_minor (nullable int), currency (nullable 3-letter code),
     *       budget_view_min_level (int — 1|4|8, default 1).
     */
    #[NoAdminRequired]
    public function setTotal(string $teamId): JSONResponse {
        try {
            $uid  = $this->requireUser();
            $body = $this->request->getParams();

            $totalMinor         = $this->parseNullableInt($body, 'total_minor');
            $currency           = $this->parseNullableStr($body, 'currency');
            $budgetViewMinLevel = (int)($body['budget_view_min_level'] ?? 1);

            return new JSONResponse(
                $this->budgetService->setProjectTotal(
                    $teamId, $totalMinor, $currency, $budgetViewMinLevel, $uid
                )
            );
        } catch (\Throwable $e) {
            return $this->mapError($e, 'setTotal');
        }
    }

    /**
     * PUT /api/v1/teams/{teamId}/budget/lanes/{laneId}
     * Body: allocated_minor (nullable int), edit_min_level (int),
     *       editor_uids (array of user IDs — additive override for edit
     *       access, independent of role level).
     *
     * As of v3.94.0 there is no per-lane view_min_level; who sees the tab is
     * a single project-level setting (see PUT /budget). Any view_min_level in
     * the body is silently ignored for back-compat.
     */
    #[NoAdminRequired]
    public function updateLane(string $teamId, int $laneId): JSONResponse {
        try {
            $uid  = $this->requireUser();
            $body = $this->request->getParams();

            $allocatedMinor = $this->parseNullableInt($body, 'allocated_minor');
            $editMinLevel   = (int)($body['edit_min_level'] ?? 8);
            $editorUids     = $this->parseUidArray($body, 'editor_uids');

            return new JSONResponse(
                $this->budgetService->upsertLane(
                    $teamId, $laneId, $allocatedMinor, $editMinLevel, $editorUids, $uid
                )
            );
        } catch (\Throwable $e) {
            return $this->mapError($e, 'updateLane');
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/budget/lanes/{laneId}/expenses
     * Body: description (required), projected_minor (required int),
     *       real_minor (nullable int), incurred_at (nullable Unix ts).
     */
    #[NoAdminRequired]
    public function addExpense(string $teamId, int $laneId): JSONResponse {
        try {
            $uid  = $this->requireUser();
            $body = $this->request->getParams();

            $description    = (string)($body['description'] ?? '');
            $projectedMinor = (int)($body['projected_minor'] ?? 0);
            $realMinor      = $this->parseNullableInt($body, 'real_minor');
            $incurredAt     = $this->parseNullableInt($body, 'incurred_at');

            return new JSONResponse(
                $this->budgetService->addExpense(
                    $teamId, $laneId, $description, $projectedMinor, $realMinor, $incurredAt, $uid
                )
            );
        } catch (\OCA\TeamHub\Exception\LicenseGateException $e) {
            return new JSONResponse([
                'error' => $e->getMessage(), 'licenseGate' => true,
                'enforcementLevel' => $e->getEnforcementLevel(),
            ], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'addExpense');
        }
    }

    /**
     * PUT /api/v1/teams/{teamId}/budget/lanes/{laneId}/expenses/{expenseId}
     */
    #[NoAdminRequired]
    public function updateExpense(string $teamId, int $laneId, int $expenseId): JSONResponse {
        try {
            $uid  = $this->requireUser();
            $body = $this->request->getParams();

            $description    = (string)($body['description'] ?? '');
            $projectedMinor = (int)($body['projected_minor'] ?? 0);
            $realMinor      = $this->parseNullableInt($body, 'real_minor');
            $incurredAt     = $this->parseNullableInt($body, 'incurred_at');

            return new JSONResponse(
                $this->budgetService->updateExpense(
                    $teamId, $laneId, $expenseId, $description, $projectedMinor, $realMinor, $incurredAt, $uid
                )
            );
        } catch (\Throwable $e) {
            return $this->mapError($e, 'updateExpense');
        }
    }

    /**
     * DELETE /api/v1/teams/{teamId}/budget/lanes/{laneId}/expenses/{expenseId}
     */
    #[NoAdminRequired]
    public function deleteExpense(string $teamId, int $laneId, int $expenseId): JSONResponse {
        try {
            $uid = $this->requireUser();
            return new JSONResponse(
                $this->budgetService->deleteExpense($teamId, $laneId, $expenseId, $uid)
            );
        } catch (\Throwable $e) {
            return $this->mapError($e, 'deleteExpense');
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
     * Read an optional int field. Absent / empty string / null → null;
     * otherwise cast to int.
     */
    private function parseNullableInt(array $body, string $key): ?int {
        if (!array_key_exists($key, $body)) {
            return null;
        }
        $v = $body[$key];
        if ($v === '' || $v === null) {
            return null;
        }
        return (int)$v;
    }

    /**
     * Read a body field expected to be a list of user IDs. Accepts:
     *   - a real array (JSON body)
     *   - an absent field (empty list)
     * A string is refused — the frontend must send an array so a single
     * "admin,user1" typo can't sneak through as one bogus UID.
     *
     * @return string[]
     */
    private function parseUidArray(array $body, string $key): array {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return [];
        }
        $raw = $body[$key];
        if (!is_array($raw)) {
            throw new \InvalidArgumentException($key . ' must be an array');
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }

    private function parseNullableStr(array $body, string $key): ?string {
        if (!array_key_exists($key, $body)) {
            return null;
        }
        $v = $body[$key];
        if ($v === null) {
            return null;
        }
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }

    private function mapError(\Throwable $e, string $context): JSONResponse {
        return $this->exceptionResponse($e, ucfirst($context) . ' failed');
    }
}
