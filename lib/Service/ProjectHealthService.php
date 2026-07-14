<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\DecisionMapper;
use OCA\TeamHub\Db\MessageMapper;
use OCA\TeamHub\Db\MilestoneMapper;
use OCA\TeamHub\Db\Project;
use OCA\TeamHub\Db\ProjectMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * ProjectHealthService — the three-pillar summary that powers the
 * project-health widget (v3.97.0, Track E Session 6).
 *
 * PILLARS
 * -------
 *   1. Budget & Time      — "Are we going out of bounds anywhere?"
 *      Count of budget lanes where real spent > allocated,
 *      count of project members where logged > available (uncapped
 *      members excluded), plus a project-level over-budget boolean.
 *   2. Milestones         — "Can we achieve it within the interval?"
 *      Each milestone owns every Deck card whose duedate lies between
 *      the previous milestone's date and its own date (or between
 *      project.startDate and its date for the first milestone). Status
 *      per milestone:
 *        - on-track — all owned cards done
 *        - at-risk  — some open, none past their own duedate
 *        - slipping — some open AND at least one past its duedate
 *   3. Quality            — "Are unresolved calls piling up?"
 *      Open decisions (status in [open, finalized]) + unsolved
 *      question-type messages.
 *
 * AUTH / VISIBILITY
 * -----------------
 *   - Membership-gated (level ≥ 1) via MemberService.
 *   - Requires Advanced project mode.
 *   - Requires can_view_budget AND can_view_time — both use the same
 *     canUser* helpers LayoutController pre-computes for the layout
 *     bundle. A viewer below either floor gets an empty envelope; the
 *     frontend hides the widget.
 *
 * The service reuses BudgetService::getProjectBudget and
 * TimeService::getProjectTime rollups so counts stay consistent with
 * the full Budget/Time tabs (single source of truth for aggregates).
 * The extra cost is one budget + one time payload per widget fetch;
 * the widget page-caches on the frontend and refetches on focus, same
 * pattern as those tabs.
 */
class ProjectHealthService {

    /** Decision statuses that count as "still-open / needs-attention". */
    private const OPEN_DECISION_STATUSES = ['open', 'finalized'];

    /** How many upcoming milestones the widget shows. */
    public const MAX_UPCOMING_MILESTONES = 5;

    /**
     * v3.99.7 — inside this window an unresolved milestone-linked
     * decision flips the milestone to slipping. The threshold is 2 days:
     * within 2 days of the milestone the project-health widget flips
     * status if a linked decision has not been approved.
     */
    private const MILESTONE_DECISION_ALERT_DAYS = 2;

    public function __construct(
        private ProjectMapper   $projectMapper,
        private MilestoneMapper $milestoneMapper,
        private DecisionMapper  $decisionMapper,
        private MessageMapper   $messageMapper,
        private BudgetService   $budgetService,
        private TimeService     $timeService,
        private DecisionService $decisionService,
        private TimelineService $timelineService,
        private MemberService   $memberService,
        private IDBConnection   $db,
        private IUserSession    $userSession,
        private LoggerInterface $logger,
    ) {}

    /**
     * Widget payload for the given team. Never throws for visibility-denial
     * cases — returns an empty envelope with canView=false so the frontend
     * can hide the widget without a special error branch. Genuine failures
     * (mapper/DB errors) still propagate so the controller can 500.
     *
     * @return array{
     *   canView: bool,
     *   phase: ?string,
     *   budgetTime: array{lanesOverBudget:int, projectOverBudget:bool, membersOverHours:int},
     *   milestones: array{total:int, upcoming: array<int, array<string,mixed>>},
     *   quality: array{openDecisions:int, unsolvedQuestions:int, decisionsEnabled:bool}
     * }
     */
    public function getProjectHealth(string $teamId): array {
        $this->memberService->requireMemberLevel($teamId);

        $project = $this->projectMapper->findByTeam($teamId);
        if ($project === null || $project->getMode() !== 'advanced') {
            return $this->emptyEnvelope();
        }

        $currentUid = $this->userSession->getUser()?->getUID() ?? '';
        if ($currentUid === '') {
            return $this->emptyEnvelope();
        }

        // Both-tab visibility gate. Uses the same canUser* helpers the
        // layout bundle uses — one source of truth, no drift.
        if (!$this->budgetService->canUserViewBudgetTab($teamId, $currentUid)
            || !$this->timeService->canUserViewTimeTab($teamId, $currentUid)) {
            return $this->emptyEnvelope();
        }

        return [
            'canView'    => true,
            'phase'      => $project->getPhase(),
            'budgetTime' => $this->computeBudgetTimeSignal($teamId),
            'milestones' => $this->computeMilestoneHealth($teamId, $project),
            'quality'    => $this->computeQualitySignal($teamId),
        ];
    }

    // ── Pillar 1 ─────────────────────────────────────────────────────────

    private function computeBudgetTimeSignal(string $teamId): array {
        $budget = $this->budgetService->getProjectBudget($teamId);
        $lanesOverBudget = 0;
        foreach ($budget['lanes'] as $lane) {
            if ($lane['allocatedMinor'] !== null
                && $lane['spentRealMinor'] > $lane['allocatedMinor']) {
                $lanesOverBudget++;
            }
        }
        $projectOverBudget = ($budget['totalMinor'] !== null
            && $budget['spentRealMinor'] > $budget['totalMinor']);

        $time = $this->timeService->getProjectTime($teamId);
        $membersOverHours = 0;
        foreach ($time['members'] as $m) {
            // available == 0 means uncapped — never counted as "over".
            if ($m['availableMinutes'] > 0
                && $m['loggedMinutes'] > $m['availableMinutes']) {
                $membersOverHours++;
            }
        }

        return [
            'lanesOverBudget'   => $lanesOverBudget,
            'projectOverBudget' => $projectOverBudget,
            'membersOverHours'  => $membersOverHours,
        ];
    }

    // ── Pillar 2 ─────────────────────────────────────────────────────────

    private function computeMilestoneHealth(string $teamId, Project $project): array {
        $rows = $this->milestoneMapper->findByTeam($teamId);
        // Widget only shows dated milestones — an undated milestone has no
        // interval to own cards for.
        $dated = array_filter($rows, static fn($m) => $m->getMilestoneDate() !== null);
        usort($dated, static fn($a, $b) => $a->getMilestoneDate() <=> $b->getMilestoneDate());

        if (empty($dated)) {
            return ['total' => 0, 'upcoming' => []];
        }

        // Fetch every card that could potentially be owned by any milestone
        // in a single query. Reuse TimelineService's Deck-stack resolution
        // (shared with Budget/Time) so we hit the same stacks the rest of
        // the app considers "this team's Deck".
        $stacks = $this->timelineService->getDeckStacks($teamId);
        $stackIds = array_column($stacks, 'stackId');
        $cards = $this->fetchDeckCards($stackIds);

        // Ownership per §"Between adjacent milestones": card belongs to the
        // first milestone M where prev.date < card.duedate <= M.date.
        // "prev" for the first milestone is project.startDate (nullable —
        // if unset, the first milestone owns everything up to its own date).
        $now = time();
        $prev = $project->getStartDate();
        $upcoming = [];

        // v3.99.7 — decisions linked to milestones. If an open/finalised
        // decision is attached to a milestone we're within
        // MILESTONE_DECISION_ALERT_DAYS of, that milestone flips to
        // at-risk. Fetch once, group by milestone_id.
        $openDecisionsByMilestone = [];
        try {
            foreach ($this->decisionMapper->findWithMilestoneByTeamAndStatus(
                $teamId, self::OPEN_DECISION_STATUSES
            ) as $d) {
                $mid = $d->getMilestoneId();
                if ($mid !== null) {
                    $openDecisionsByMilestone[$mid] = ($openDecisionsByMilestone[$mid] ?? 0) + 1;
                }
            }
        } catch (\Throwable) {
            // Non-fatal — if the decision-link lookup fails the widget
            // just renders without pending-decision annotations. The
            // core milestone signal is still correct.
        }
        $alertWindow = self::MILESTONE_DECISION_ALERT_DAYS * 86400;

        foreach ($dated as $milestone) {
            $mts = (int)$milestone->getMilestoneDate();
            $owned = 0;
            $doneOwned = 0;
            $slippingOwned = 0;

            foreach ($cards as $c) {
                $due = (int)($c['duedate'] ?? 0);
                if ($due <= 0) continue;
                if ($prev !== null && $due <= $prev) continue;
                if ($due > $mts) continue;

                $owned++;
                if ($this->isCardDone($c)) {
                    $doneOwned++;
                } elseif ($due < $now) {
                    $slippingOwned++;
                }
            }

            // v3.99.7 — pending-decision alert: if this milestone has
            // open/finalised decisions attached AND today is within N
            // days of the milestone date (including past-due), treat as
            // slipping. Rationale: an unresolved decision at the
            // milestone edge is the same kind of risk as an open Deck
            // card past its due date.
            $pendingDecisions = (int)($openDecisionsByMilestone[$milestone->getId()] ?? 0);
            $decisionAlert = $pendingDecisions > 0
                && ($mts - $now) <= $alertWindow;

            if ($slippingOwned > 0 || $decisionAlert) {
                $status = 'slipping';
            } elseif ($owned > $doneOwned) {
                $status = 'at-risk';
            } else {
                $status = 'on-track';
            }

            $upcoming[] = [
                'id'                => $milestone->getId(),
                'label'             => $milestone->getLabel(),
                'date'              => (new \DateTimeImmutable('@' . $mts))->format('Y-m-d'),
                'dateTs'            => $mts,
                'ownedTotal'        => $owned,
                'ownedDone'         => $doneOwned,
                'ownedSlipping'     => $slippingOwned,
                'pendingDecisions'  => $pendingDecisions,
                'status'            => $status,
                'isPast'            => $mts < $now,
            ];

            $prev = $mts;
        }

        // v3.99.7 — milestone order is always ascending by date,
        // soonest to farthest away. Prior code split future/past
        // and glued past-descending onto the end, which made "when I
        // change a milestone the order jumps" behaviour surprising.
        // Simple monotonic ascending sort by dateTs — the natural
        // chronological order — is what a checklist wants to show.
        // $upcoming already comes in ascending order from the earlier
        // usort on $dated; the sort here is defence-in-depth against
        // future changes to the ownership loop.
        $sorted = $upcoming;
        usort($sorted, static fn($a, $b) => $a['dateTs'] <=> $b['dateTs']);

        $shown = array_slice($sorted, 0, self::MAX_UPCOMING_MILESTONES);

        return [
            'total'    => count($upcoming),
            'upcoming' => $shown,
        ];
    }

    // ── Pillar 3 ─────────────────────────────────────────────────────────

    private function computeQualitySignal(string $teamId): array {
        $decisionsEnabled = $this->decisionService->isModuleActiveForTeam($teamId);
        $openDecisions = 0;
        if ($decisionsEnabled) {
            $openDecisions = $this->decisionMapper->countByTeamAndStatus(
                $teamId, self::OPEN_DECISION_STATUSES
            );
        }
        $unsolvedQuestions = $this->messageMapper->countUnsolvedQuestions($teamId);

        return [
            'openDecisions'     => $openDecisions,
            'unsolvedQuestions' => $unsolvedQuestions,
            'decisionsEnabled'  => $decisionsEnabled,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Fetch Deck cards with duedate + done from the given stack ids. Empty
     * stack list → empty result (no query fires). Only the columns we
     * strictly need are selected — id, stack_id, duedate, done — mirroring
     * TimelineService::fetchDeckEvents' hardened pattern (3.86.1) that
     * avoids DbIntrospectionService for column drift.
     *
     * deck_cards column notes:
     *   - `duedate` is one word (not `due_date`) — HANDOFF key fact.
     *   - `done` is a datetime/Unix timestamp; 0/NULL = not completed.
     *   - `deleted_at` is nullable — we filter it out when present.
     *
     * @param int[] $stackIds
     * @return array<int, array{id:int, stack_id:int, duedate:?int, done:mixed, deleted_at:mixed}>
     */
    private function fetchDeckCards(array $stackIds): array {
        if (empty($stackIds)) {
            return [];
        }
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'stack_id', 'duedate', 'done', 'deleted_at')
                ->from('deck_cards')
                ->where($qb->expr()->in(
                    'stack_id',
                    $qb->createNamedParameter($stackIds, IQueryBuilder::PARAM_INT_ARRAY)
                ));
            $r = $qb->executeQuery();
            $out = [];
            while ($row = $r->fetch()) {
                // Skip soft-deleted cards.
                if (!empty($row['deleted_at']) && $row['deleted_at'] !== 0 && $row['deleted_at'] !== '0') {
                    continue;
                }
                $out[] = $row;
            }
            $r->closeCursor();
            return $out;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ProjectHealthService] deck_cards fetch failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
            // Try again without deleted_at — older Deck installs may not have it.
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->select('id', 'stack_id', 'duedate', 'done')
                    ->from('deck_cards')
                    ->where($qb->expr()->in(
                        'stack_id',
                        $qb->createNamedParameter($stackIds, IQueryBuilder::PARAM_INT_ARRAY)
                    ));
                $r = $qb->executeQuery();
                $out = [];
                while ($row = $r->fetch()) {
                    $row['deleted_at'] = null;
                    $out[] = $row;
                }
                $r->closeCursor();
                return $out;
            } catch (\Throwable $inner) {
                $this->logger->warning('[TeamHub][ProjectHealthService] deck_cards fallback also failed: ' . $inner->getMessage(), [
                    'app' => Application::APP_ID,
                ]);
                return [];
            }
        }
    }

    private function isCardDone(array $card): bool {
        $done = $card['done'] ?? null;
        return $done !== null && $done !== 0 && $done !== '0' && $done !== '';
    }

    private function emptyEnvelope(): array {
        return [
            'canView'    => false,
            'phase'      => null,
            'budgetTime' => ['lanesOverBudget' => 0, 'projectOverBudget' => false, 'membersOverHours' => 0],
            'milestones' => ['total' => 0, 'upcoming' => []],
            'quality'    => ['openDecisions' => 0, 'unsolvedQuestions' => 0, 'decisionsEnabled' => false],
        ];
    }
}
