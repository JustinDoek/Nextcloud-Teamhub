<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\BudgetLane;
use OCA\TeamHub\Db\BudgetLaneEditorMapper;
use OCA\TeamHub\Db\BudgetLaneMapper;
use OCA\TeamHub\Db\Expense;
use OCA\TeamHub\Db\ExpenseMapper;
use OCA\TeamHub\Db\ProjectMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * BudgetService — Execution-phase Budget page (v3.92.0, Track E Session 4).
 *
 * DATA MODEL
 * ----------
 *   - teamhub_project.currency + budget_total_minor  — one currency + total
 *     per project (Advanced projects only).
 *   - teamhub_budget_lane                            — one row per (team, Deck
 *     stack). Each lane has its own allocated_minor share of the project
 *     total plus two role gates: view_min_level and edit_min_level.
 *   - teamhub_expense                                — one line item under a
 *     lane, with projected_minor (always set) and real_minor (nullable —
 *     null = not yet realised).
 *
 * All money is BIGINT minor units (cents) — no floating-point drift, portable
 * across MySQL/MariaDB/Postgres.
 *
 * LANE SYNC
 * ---------
 * getProjectBudget() reconciles the team's `teamhub_budget_lane` rows with
 * the live Deck stacks fetched via TimelineService::getDeckStacks(). Missing
 * lanes are auto-inserted (view=1 member, edit=8 admin, no allocation). Lanes
 * whose stack has been deleted from Deck are hidden from the response but
 * retained in the DB so their expenses aren't lost — if the stack is
 * restored, the lane reappears with its history intact.
 *
 * AUTH MODEL (v3.94.0)
 * -------------------
 *   - Tab visibility                              — a SINGLE project-level floor
 *     `project.budget_view_min_level`. A user sees the Budget tab when their
 *     team role is at or above that floor, OR they are named as an editor on
 *     ANY of the project's lanes. Once they see the tab, all lanes are
 *     visible; there is no per-lane view filter anymore.
 *   - Project-level reads   (getProjectBudget)     — membership + tab visibility.
 *   - Project-level writes  (setProjectTotal)      — team admin (≥8).
 *   - Lane-level writes     (upsertLane)           — team admin (≥8).
 *   - Expense reads/writes                         — per-lane edit gate:
 *       canEdit = level ≥ edit_min_level  OR  user is a named editor on the lane
 *     Additional editors (teamhub_budget_lane_editor) are the individual
 *     override on top of the role floor. Every gate is enforced here
 *     regardless of what the frontend renders.
 *
 * Note: `teamhub_budget_lane.view_min_level` still exists in the schema but
 * is no longer consulted — kept for now to avoid a destructive migration on
 * live installs. New writes leave it at whatever value the row holds.
 *
 * Error contract for BudgetController:
 *   \InvalidArgumentException → 400 (bad input / bad currency / negative amount)
 *   \OCP\AppFramework\Db\DoesNotExistException-style "not found" → 404
 *   \Exception thrown from MemberService/level checks              → 403
 */
class BudgetService {

    /** Team role levels used as view_min_level / edit_min_level. */
    public const VALID_LEVELS = [1, 4, 8];

    /**
     * Curated allow-list for the currency picker. Kept small and expandable
     * — validation stays permissive to any ISO-4217-shaped code (3 letters
     * A-Z), so the frontend can add codes without a backend change.
     */
    public const KNOWN_CURRENCIES = [
        'EUR', 'USD', 'GBP', 'CHF', 'JPY',
        'DKK', 'SEK', 'NOK', 'CAD', 'AUD',
    ];

    /** Description max length matches the STRING(255) migration column. */
    public const DESCRIPTION_MAX_LEN = 255;

    public function __construct(
        private ProjectMapper          $projectMapper,
        private BudgetLaneMapper       $laneMapper,
        private BudgetLaneEditorMapper $editorMapper,
        private ExpenseMapper          $expenseMapper,
        private TimelineService        $timelineService,
        private MemberService          $memberService,
        private AuditService           $auditService,
        private LicenseService         $licenseService,
        private IDBConnection          $db,
        private IUserSession           $userSession,
        private IUserManager           $userManager,
        private LoggerInterface        $logger,
    ) {}

    // ── READ ────────────────────────────────────────────────────────────────

    /**
     * Full Budget page payload for $teamId. Membership-gated.
     *
     * Lanes the current user can't view are excluded entirely (per-lane
     * view_min_level). If the user can view no lanes, `lanes` is [] but the
     * envelope still returns — the frontend uses that to hide the tab.
     *
     * @return array{
     *   isProject: bool,
     *   currency: ?string,
     *   totalMinor: ?int,
     *   allocatedMinor: int,
     *   spentProjectedMinor: int,
     *   spentRealMinor: int,
     *   lanes: array<int, array<string,mixed>>
     * }
     */
    public function getProjectBudget(string $teamId): array {
        $this->memberService->requireMemberLevel($teamId);

        $project = $this->projectMapper->findByTeam($teamId);
        if ($project === null) {
            return $this->emptyEnvelope();
        }

        $currentUserLevel = $this->currentUserLevel($teamId);
        $currentUid       = $this->userSession->getUser()?->getUID() ?? '';

        // Reconcile lanes with the live Deck stacks. Missing rows are inserted.
        $stacks = $this->timelineService->getDeckStacks($teamId);
        $this->syncLanes($teamId, $stacks);

        // Reload lanes after sync.
        $laneRows    = $this->laneMapper->findByTeam($teamId);
        $lanesByStack = [];
        $laneIds      = [];
        foreach ($laneRows as $lane) {
            $lanesByStack[$lane->getDeckStackId()] = $lane;
            $laneIds[] = $lane->getId();
        }

        // Editor override rows, grouped by lane. One query for the whole team.
        $editorsByLane = [];
        foreach ($this->editorMapper->findByLaneIds($laneIds) as $ed) {
            $editorsByLane[$ed->getLaneId()][] = $ed->getUserId();
        }

        // Tab-visibility check — role floor is authoritative (same rule as
        // TimeService, revised in this session). Named editors still get
        // elevated edit rights on their specific lanes, but no longer bypass
        // the tab-visibility floor. If the caller is below the floor we
        // return the empty envelope, matching the behaviour when there's no
        // project row at all.
        if ($currentUserLevel < $project->getBudgetViewMinLevel()) {
            return $this->emptyEnvelope();
        }

        // All expenses in one query, grouped by lane.
        $expenseRows = $this->expenseMapper->findByTeam($teamId);
        $expensesByLane = [];
        foreach ($expenseRows as $expense) {
            $expensesByLane[$expense->getLaneId()][] = $expense;
        }

        // Roll up in Deck-stack display order (the caller of getDeckStacks
        // has already sorted by Deck's own `order` column). Every lane is
        // visible to a caller who can see the tab — the per-lane
        // `view_min_level` isn't consulted anymore.
        $lanes = [];
        $totalAllocated      = 0;
        $totalSpentProjected = 0;
        $totalSpentReal      = 0;

        foreach ($stacks as $stack) {
            $stackId = $stack['stackId'];
            $lane    = $lanesByStack[$stackId] ?? null;
            if ($lane === null) {
                // syncLanes() just created it — mapper race. Skip; shows next fetch.
                continue;
            }

            $editorUids = $editorsByLane[$lane->getId()] ?? [];
            $canEdit    = in_array($currentUid, $editorUids, true)
                          || $currentUserLevel >= $lane->getEditMinLevel();

            $laneExpenses = $expensesByLane[$lane->getId()] ?? [];
            $spentProjected = 0;
            $spentReal      = 0;
            $expensePayload = [];
            foreach ($laneExpenses as $e) {
                $spentProjected += $e->getProjectedMinor();
                $spentReal      += (int)($e->getRealMinor() ?? 0);
                $expensePayload[] = $this->serializeExpense($e);
            }

            $allocated = $lane->getAllocatedMinor();
            if ($allocated !== null) {
                $totalAllocated += $allocated;
            }
            $totalSpentProjected += $spentProjected;
            $totalSpentReal      += $spentReal;

            $lanes[] = [
                'laneId'                  => $lane->getId(),
                'deckStackId'             => $stackId,
                'stackTitle'              => $stack['stackTitle'],
                'stackOrder'              => $stack['order'],
                'boardId'                 => $stack['boardId'],
                'boardTitle'              => $stack['boardTitle'],
                'allocatedMinor'          => $allocated,
                'editMinLevel'            => $lane->getEditMinLevel(),
                'editors'                 => $this->resolveEditorDisplayNames($editorUids),
                'canView'                 => true,
                'canEdit'                 => $canEdit,
                'spentProjectedMinor'     => $spentProjected,
                'spentRealMinor'          => $spentReal,
                'remainingProjectedMinor' => $allocated !== null ? ($allocated - $spentProjected) : null,
                'remainingRealMinor'      => $allocated !== null ? ($allocated - $spentReal)      : null,
                'expenses'                => $expensePayload,
            ];
        }

        return [
            'isProject'           => true,
            'currency'            => $project->getCurrency(),
            'totalMinor'          => $project->getBudgetTotalMinor(),
            'budgetViewMinLevel'  => $project->getBudgetViewMinLevel(),
            'allocatedMinor'      => $totalAllocated,
            'spentProjectedMinor' => $totalSpentProjected,
            'spentRealMinor'      => $totalSpentReal,
            'lanes'               => $lanes,
        ];
    }

    /**
     * Lightweight "does this user see the Budget tab?" check for LayoutController.
     * Same rule as getProjectBudget's early exit but without loading expenses or
     * building the full payload. Never throws — returns false on any failure
     * (project doesn't exist, user isn't a member, DB error) so it stays safe
     * to call from a warm layout-fetch path.
     */
    public function canUserViewBudgetTab(string $teamId, string $userId): bool {
        try {
            $project = $this->projectMapper->findByTeam($teamId);
            if ($project === null || $project->getMode() !== 'advanced') {
                return false;
            }
            $level = $this->memberService->getMemberLevelFromDb($this->db, $teamId, $userId);
            if ($level <= 0) {
                return false;
            }
            // Role floor is authoritative — same rule as TimeService. Named
            // editors still get elevated EDIT rights on their lanes
            // (see requireLaneWithEdit), but they no longer get an
            // automatic tab-visibility bypass. Admins who want a
            // low-role user to see the Budget tab lower the floor
            // (per-project) or disable the integration for the team.
            return $level >= $project->getBudgetViewMinLevel();
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][BudgetService] canUserViewBudgetTab error: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    // ── WRITE: project total + currency ─────────────────────────────────────

    /**
     * Set the project-wide total budget, currency, and Budget-tab view floor.
     * Admin-gated. `budgetViewMinLevel` uses the same 1/4/8 scheme as
     * edit_min_level.
     */
    public function setProjectTotal(
        string  $teamId,
        ?int    $totalMinor,
        ?string $currency,
        int     $budgetViewMinLevel,
        string  $actingUserId
    ): array {
        $this->memberService->requireAdminLevel($teamId);

        $project = $this->projectMapper->findByTeam($teamId);
        if ($project === null) {
            throw new \InvalidArgumentException('This team is not a project');
        }
        if ($project->getMode() !== 'advanced') {
            throw new \InvalidArgumentException('Budget applies to advanced projects only');
        }

        $currency = $this->normalizeCurrency($currency);
        $this->validateLevel($budgetViewMinLevel, 'budgetViewMinLevel');
        if ($totalMinor !== null && $totalMinor < 0) {
            throw new \InvalidArgumentException('Budget total cannot be negative');
        }

        // If a total is being set, verify existing lane allocations still fit.
        if ($totalMinor !== null) {
            $sumAllocated = 0;
            foreach ($this->laneMapper->findByTeam($teamId) as $lane) {
                $sumAllocated += (int)($lane->getAllocatedMinor() ?? 0);
            }
            if ($sumAllocated > $totalMinor) {
                throw new \InvalidArgumentException(
                    'Sum of lane allocations (' . $sumAllocated . ') exceeds the new total (' . $totalMinor . ')'
                );
            }
        }

        $old = [
            'currency'            => $project->getCurrency(),
            'budgetTotalMinor'    => $project->getBudgetTotalMinor(),
            'budgetViewMinLevel'  => $project->getBudgetViewMinLevel(),
        ];

        $project->setCurrency($currency);
        $project->setBudgetTotalMinor($totalMinor);
        $project->setBudgetViewMinLevel($budgetViewMinLevel);
        $project->setUpdatedAt(time());
        $this->projectMapper->update($project);

        $diff = $this->auditService->buildDiff($old, [
            'currency'            => $currency,
            'budgetTotalMinor'    => $totalMinor,
            'budgetViewMinLevel'  => $budgetViewMinLevel,
        ]);
        if ($diff !== null) {
            $this->auditService->log(
                $teamId, 'project.budget_total_changed', $actingUserId,
                'project', (string)$project->getId(), $diff,
            );
        }

        return $this->getProjectBudget($teamId);
    }

    // ── WRITE: per-lane allocation + permission levels ──────────────────────

    /**
     * Update one lane's allocated_minor + view/edit min-levels. Admin-gated.
     * The lane must belong to $teamId and its stack must still exist on the
     * team's Deck board.
     */
    /**
     * @param string[] $editorUids   Additional editor UIDs — replaces the existing set.
     */
    public function upsertLane(
        string $teamId,
        int    $laneId,
        ?int   $allocatedMinor,
        int    $editMinLevel,
        array  $editorUids,
        string $actingUserId
    ): array {
        $this->memberService->requireAdminLevel($teamId);

        $lane = $this->laneMapper->findById($laneId);
        if ($lane === null || $lane->getTeamId() !== $teamId) {
            throw new \InvalidArgumentException('Lane not found in this team');
        }

        // Stack still present on Deck?
        $stackAlive = false;
        foreach ($this->timelineService->getDeckStacks($teamId) as $stack) {
            if ($stack['stackId'] === $lane->getDeckStackId()) {
                $stackAlive = true;
                break;
            }
        }
        if (!$stackAlive) {
            throw new \InvalidArgumentException(
                'The Deck stack for this lane no longer exists — restore it in Deck first'
            );
        }

        $this->validateLevel($editMinLevel, 'editMinLevel');
        if ($allocatedMinor !== null && $allocatedMinor < 0) {
            throw new \InvalidArgumentException('Lane allocation cannot be negative');
        }

        // Sum-of-allocated ≤ project total (only enforced when both exist).
        $project = $this->projectMapper->findByTeam($teamId);
        if ($project !== null && $project->getBudgetTotalMinor() !== null && $allocatedMinor !== null) {
            $sumOther = 0;
            foreach ($this->laneMapper->findByTeam($teamId) as $other) {
                if ($other->getId() === $laneId) continue;
                $sumOther += (int)($other->getAllocatedMinor() ?? 0);
            }
            if (($sumOther + $allocatedMinor) > $project->getBudgetTotalMinor()) {
                throw new \InvalidArgumentException(
                    'Sum of lane allocations would exceed the project total ('
                    . $project->getBudgetTotalMinor() . ')'
                );
            }
        }

        // Normalise, dedupe, validate the editor list. Empty strings drop out;
        // unknown UIDs are refused so a typo doesn't silently grant nothing.
        $normalizedEditors = $this->normalizeEditorUids($editorUids);

        $existingEditors = [];
        foreach ($this->editorMapper->findByLane($laneId) as $ed) {
            $existingEditors[] = $ed->getUserId();
        }
        sort($existingEditors);
        $sortedNew = $normalizedEditors;
        sort($sortedNew);

        $old = [
            'allocatedMinor' => $lane->getAllocatedMinor(),
            'editMinLevel'   => $lane->getEditMinLevel(),
            'editors'        => implode(',', $existingEditors),
        ];

        $lane->setAllocatedMinor($allocatedMinor);
        $lane->setEditMinLevel($editMinLevel);
        $lane->setUpdatedAt(time());
        $this->laneMapper->update($lane);

        $this->editorMapper->replaceForLane($laneId, $normalizedEditors);

        $diff = $this->auditService->buildDiff($old, [
            'allocatedMinor' => $allocatedMinor,
            'editMinLevel'   => $editMinLevel,
            'editors'        => implode(',', $sortedNew),
        ]);
        if ($diff !== null) {
            $this->auditService->log(
                $teamId, 'project.budget_lane_changed', $actingUserId,
                'budget_lane', (string)$lane->getId(), $diff,
            );
        }

        return $this->getProjectBudget($teamId);
    }

    // ── WRITE: expenses ─────────────────────────────────────────────────────

    public function addExpense(
        string $teamId,
        int    $laneId,
        string $description,
        int    $projectedMinor,
        ?int   $realMinor,
        ?int   $incurredAt,
        string $actingUserId
    ): array {
        // v3.100.0 — Track F soft-lock gate. No-op when the team is Basic
        // or the license is active (or in grace).
        $this->licenseService->gateAdvancedWrite($teamId);
        $lane = $this->requireLaneWithEdit($teamId, $laneId);

        $description = $this->normalizeDescription($description);
        $this->validateAmounts($projectedMinor, $realMinor);

        $expense = $this->expenseMapper->insertExpense(
            $teamId, $lane->getId(), $description, $projectedMinor, $realMinor, $incurredAt, $actingUserId
        );

        $this->auditService->log(
            $teamId, 'project.expense_added', $actingUserId,
            'expense', (string)$expense->getId(),
            ['laneId' => $lane->getId(), 'projectedMinor' => $projectedMinor, 'realMinor' => $realMinor],
        );

        return $this->getProjectBudget($teamId);
    }

    public function updateExpense(
        string $teamId,
        int    $laneId,
        int    $expenseId,
        string $description,
        int    $projectedMinor,
        ?int   $realMinor,
        ?int   $incurredAt,
        string $actingUserId
    ): array {
        $lane    = $this->requireLaneWithEdit($teamId, $laneId);
        $expense = $this->expenseMapper->findById($expenseId);
        if ($expense === null || $expense->getTeamId() !== $teamId || $expense->getLaneId() !== $lane->getId()) {
            throw new \InvalidArgumentException('Expense not found in this lane');
        }

        $description = $this->normalizeDescription($description);
        $this->validateAmounts($projectedMinor, $realMinor);

        $old = [
            'description'    => $expense->getDescription(),
            'projectedMinor' => $expense->getProjectedMinor(),
            'realMinor'      => $expense->getRealMinor(),
            'incurredAt'     => $expense->getIncurredAt(),
        ];

        $expense->setDescription($description);
        $expense->setProjectedMinor($projectedMinor);
        $expense->setRealMinor($realMinor);
        $expense->setIncurredAt($incurredAt);
        $expense->setUpdatedAt(time());
        $this->expenseMapper->update($expense);

        $diff = $this->auditService->buildDiff($old, [
            'description'    => $description,
            'projectedMinor' => $projectedMinor,
            'realMinor'      => $realMinor,
            'incurredAt'     => $incurredAt,
        ]);
        if ($diff !== null) {
            $this->auditService->log(
                $teamId, 'project.expense_updated', $actingUserId,
                'expense', (string)$expense->getId(),
                ['laneId' => $lane->getId()] + $diff,
            );
        }

        return $this->getProjectBudget($teamId);
    }

    public function deleteExpense(
        string $teamId,
        int    $laneId,
        int    $expenseId,
        string $actingUserId
    ): array {
        $lane    = $this->requireLaneWithEdit($teamId, $laneId);
        $expense = $this->expenseMapper->findById($expenseId);
        if ($expense === null || $expense->getTeamId() !== $teamId || $expense->getLaneId() !== $lane->getId()) {
            throw new \InvalidArgumentException('Expense not found in this lane');
        }

        $this->expenseMapper->delete($expense);

        $this->auditService->log(
            $teamId, 'project.expense_deleted', $actingUserId,
            'expense', (string)$expenseId,
            ['laneId' => $lane->getId()],
        );

        return $this->getProjectBudget($teamId);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * Insert a lane row for every current Deck stack that doesn't have one.
     * Skipped stacks (never present, later added) just show up on the next
     * getProjectBudget() call.
     *
     * @param array<int, array{stackId:int, boardId:int, boardTitle:string, stackTitle:string, order:int|null}> $stacks
     */
    private function syncLanes(string $teamId, array $stacks): void {
        if (empty($stacks)) {
            return;
        }
        $existing = [];
        foreach ($this->laneMapper->findByTeam($teamId) as $lane) {
            $existing[$lane->getDeckStackId()] = true;
        }
        foreach ($stacks as $stack) {
            if (!isset($existing[$stack['stackId']])) {
                try {
                    $this->laneMapper->insertLane($teamId, $stack['stackId']);
                } catch (\Throwable $e) {
                    // Unique-index collision means a parallel request beat us.
                    $this->logger->debug('[TeamHub][BudgetService] syncLanes: insert skipped: ' . $e->getMessage(), [
                        'app' => Application::APP_ID,
                    ]);
                }
            }
        }
    }

    private function requireLaneWithEdit(string $teamId, int $laneId): BudgetLane {
        $lane = $this->laneMapper->findById($laneId);
        if ($lane === null || $lane->getTeamId() !== $teamId) {
            throw new \InvalidArgumentException('Lane not found in this team');
        }
        $level = $this->currentUserLevel($teamId);
        if ($level < 1) {
            throw new AccessDeniedException('You are not a member of this team');
        }
        // Editor override — a named editor can always edit, regardless of
        // their team role. Otherwise the standard role-level floor applies.
        $uid = $this->userSession->getUser()?->getUID() ?? '';
        foreach ($this->editorMapper->findByLane($laneId) as $ed) {
            if ($ed->getUserId() === $uid) {
                return $lane;
            }
        }
        if ($level < $lane->getEditMinLevel()) {
            throw new AccessDeniedException('Insufficient permissions for this budget lane');
        }
        return $lane;
    }

    /**
     * Look each UID up in IUserManager. Unknown UIDs are refused so an admin
     * saving typos doesn't create dead-letter entries the frontend can't map
     * back to a display name.
     *
     * @param string[] $uids
     * @return string[]  — deduplicated, whitespace-trimmed, existence-verified
     */
    private function normalizeEditorUids(array $uids): array {
        $out = [];
        $seen = [];
        foreach ($uids as $raw) {
            if (!is_string($raw)) continue;
            $uid = trim($raw);
            if ($uid === '' || isset($seen[$uid])) continue;
            if ($this->userManager->get($uid) === null) {
                throw new \InvalidArgumentException('Unknown user: ' . $uid);
            }
            $seen[$uid] = true;
            $out[] = $uid;
        }
        return $out;
    }

    /**
     * @param string[] $uids
     * @return array<int, array{uid:string, displayName:string}>
     */
    private function resolveEditorDisplayNames(array $uids): array {
        $out = [];
        foreach ($uids as $uid) {
            $user = $this->userManager->get($uid);
            $out[] = [
                'uid'         => $uid,
                'displayName' => $user !== null ? $user->getDisplayName() : $uid,
            ];
        }
        return $out;
    }

    private function currentUserLevel(string $teamId): int {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new AccessDeniedException('User not authenticated');
        }
        return $this->memberService->getMemberLevelFromDb($this->db, $teamId, $user->getUID());
    }

    private function serializeExpense(Expense $e): array {
        // v3.99.7 — resolve createdBy display name so the Budget view can
        // show "By" in the expenses table. Fallback to the UID when the
        // user account was removed. One IUserManager::get per expense —
        // fine for the sizes teams generate; if this ever becomes a
        // hotspot, batch-resolve into a per-request cache like
        // TimeService::getProjectTime does.
        $uid = $e->getCreatedBy();
        $createdByName = $uid;
        try {
            $u = $this->userManager->get($uid);
            if ($u !== null) {
                $createdByName = $u->getDisplayName();
            }
        } catch (\Throwable) {
            // Fall back to the UID if the user manager can't resolve
            // the display name (deleted user, LDAP hiccup, …). The
            // frontend re-resolves display names anyway on hydration.
        }
        return [
            'id'             => $e->getId(),
            'laneId'         => $e->getLaneId(),
            'description'    => $e->getDescription(),
            'projectedMinor' => $e->getProjectedMinor(),
            'realMinor'      => $e->getRealMinor(),
            'incurredAt'     => $e->getIncurredAt(),
            'createdBy'      => $uid,
            'createdByName'  => $createdByName,
            'createdAt'      => $e->getCreatedAt(),
            'updatedAt'      => $e->getUpdatedAt(),
        ];
    }

    private function emptyEnvelope(): array {
        return [
            'isProject'           => false,
            'currency'            => null,
            'totalMinor'          => null,
            'allocatedMinor'      => 0,
            'spentProjectedMinor' => 0,
            'spentRealMinor'      => 0,
            'lanes'               => [],
        ];
    }

    private function validateLevel(int $level, string $field): void {
        if (!in_array($level, self::VALID_LEVELS, true)) {
            throw new \InvalidArgumentException(
                $field . ' must be one of: ' . implode(', ', self::VALID_LEVELS)
            );
        }
    }

    private function validateAmounts(int $projectedMinor, ?int $realMinor): void {
        if ($projectedMinor < 0) {
            throw new \InvalidArgumentException('Projected amount cannot be negative');
        }
        if ($realMinor !== null && $realMinor < 0) {
            throw new \InvalidArgumentException('Real amount cannot be negative');
        }
    }

    private function normalizeDescription(string $description): string {
        $description = trim($description);
        if ($description === '') {
            throw new \InvalidArgumentException('Description is required');
        }
        if (mb_strlen($description) > self::DESCRIPTION_MAX_LEN) {
            $description = mb_substr($description, 0, self::DESCRIPTION_MAX_LEN);
        }
        return $description;
    }

    /**
     * Accept any 3-letter ISO-4217-shaped code (case-normalised to upper).
     * The frontend picker is limited to KNOWN_CURRENCIES but the backend
     * stays permissive so orgs on exotic currencies aren't blocked.
     */
    private function normalizeCurrency(?string $currency): ?string {
        if ($currency === null || $currency === '') {
            return null;
        }
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('Currency must be a 3-letter ISO-4217 code');
        }
        return $currency;
    }
}
