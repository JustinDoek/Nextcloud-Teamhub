<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\BudgetLaneMapper;
use OCA\TeamHub\Db\ExpenseMapper;
use OCA\TeamHub\Db\MilestoneMapper;
use OCA\TeamHub\Db\ProjectMapper;
use OCA\TeamHub\Db\ProjectMemberMapper;
use OCA\TeamHub\Db\TimeLogMapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * ProjectReadinessService — powers the Project Compass panel
 * (v3.98.0, Track E follow-on).
 *
 * Computes a per-phase checklist of concrete setup items for Advanced
 * project teams. Each item has a done/pending state and a link target
 * telling the frontend where to jump.
 *
 * Items are intentionally kept small (~5 per phase) so the checklist
 * stays scannable. Every check reads existing tables — no new schema
 * required.
 *
 * AUTH
 * ----
 *   Member-gated read. Every team member sees the same checklist —
 *   Compass is a shared cockpit, not admin-only. Actions that would
 *   need admin (e.g. "Set project dates") still land on Manage Team
 *   which enforces its own gate; members who follow the link see the
 *   read-only view of that section.
 *
 * PHASES
 * ------
 *   - initiation: not used — Advanced projects open on Planning by
 *     design (Initiation is assumed complete before the team exists)
 *   - planning:   5 items (dates, members, milestones, budget, time)
 *   - execution:  4 items (expense, time-log, milestones on track,
 *                          budget within bounds)
 *   - closing:    empty for now — Session 7 will define
 *
 * The Compass renders items in the order returned. First incomplete
 * item feeds the "Next up" prompt.
 */
class ProjectReadinessService {

    public const NEXT_PHASE = [
        'initiation' => 'planning',
        'planning'   => 'execution',
        'execution'  => 'closing',
        'closing'    => null,
    ];

    // v3.99.1 — mark types persisted on teamhub_project for user-confirmed
    // Planning-phase items where the "done" signal is manual (there's no
    // programmatic check for "has the charter been reviewed?" or "has the
    // kickoff meeting been scheduled?").
    public const VALID_MARK_TYPES = ['charter_configured', 'kickoff_meeting', 'evaluation_meeting'];

    public function __construct(
        private ProjectMapper          $projectMapper,
        private ProjectMemberMapper    $projectMemberMapper,
        private MilestoneMapper        $milestoneMapper,
        private BudgetLaneMapper       $budgetLaneMapper,
        private ExpenseMapper          $expenseMapper,
        private TimeLogMapper          $timeLogMapper,
        private MemberService          $memberService,
        private ProjectHealthService   $healthService,
        // v3.98.2 — used to count Deck stacks for the workstreams_defined item.
        private TimelineService        $timelineService,
        private IConfig                $config,
        private IDBConnection          $db,
        private IUserSession           $userSession,
        private IFactory               $l10nFactory,
        private LoggerInterface        $logger,
    ) {}

    /**
     * Compute the readiness envelope for a team.
     *
     * Non-Advanced / non-project teams return `{ isProject: false }` — the
     * frontend hides the Compass in that case.
     *
     * @return array{
     *   isProject: bool,
     *   phase: ?string,
     *   nextPhase: ?string,
     *   readyToAdvance: bool,
     *   items: array<int, array<string, mixed>>
     * }
     */
    public function computeReadiness(string $teamId): array {
        $this->memberService->requireMemberLevel($teamId);

        $project = $this->projectMapper->findByTeam($teamId);
        if ($project === null || $project->getMode() !== 'advanced') {
            return $this->emptyEnvelope();
        }

        $phase = $project->getPhase() ?? 'planning';
        $l10n  = $this->l10nFactory->get(
            Application::APP_ID,
            $this->l10nFactory->getUserLanguage($this->userSession->getUser())
        );

        $items = match ($phase) {
            'initiation' => $this->initiationItems($teamId, $project, $l10n),
            'planning'   => $this->planningItems($teamId, $project, $l10n),
            'execution'  => $this->executionItems($teamId, $project, $l10n),
            'closing'    => $this->closingItems($teamId, $project, $l10n),
            default      => [],
        };

        $readyToAdvance = !empty($items) && !$this->hasIncomplete($items);

        return [
            'isProject'      => true,
            'phase'          => $phase,
            'nextPhase'      => self::NEXT_PHASE[$phase] ?? null,
            'readyToAdvance' => $readyToAdvance,
            'items'          => $items,
        ];
    }

    // ── Initiation ───────────────────────────────────────────────────────
    //
    // v3.99.1 — the phase model was reshuffled: Advanced projects now open
    // on Initiation (was Planning) and the pure-config items — dates,
    // roster, budget total, time capacity — live here. Planning is where
    // project *work* is planned (contract, kickoff, workstreams,
    // milestones). Initiation is the phase to set the initial
    // configuration that previously lived under Planning.

    private function initiationItems(string $teamId, $project, IL10N $l10n): array {
        $items = [];

        // Project dates
        $datesSet = $project->getStartDate() !== null && $project->getTargetEnd() !== null;
        $items[] = [
            'id'    => 'project_dates',
            'done'  => $datesSet,
            'label' => $l10n->t('Set project start and target end dates'),
            'hint'  => $l10n->t('Anchors the timeline so milestones and the health widget have a range to report against.'),
            'link'  => ['target' => 'manage-team', 'tab' => 'project', 'section' => 'dates'],
        ];

        // Members invited
        $memberCount = $this->safeEffectiveMemberCount($teamId);
        $items[] = [
            'id'    => 'members_invited',
            'done'  => $memberCount > 1,
            'label' => $l10n->t('Invite the project team'),
            'hint'  => $l10n->t('At least one other member so work has someone to be assigned to.'),
            'link'  => ['target' => 'invite-modal'],
        ];

        // Budget total set — only when Budget integration is on for the team
        if ($this->isBudgetEnabled($teamId)) {
            $totalSet = $project->getBudgetTotalMinor() !== null && $project->getBudgetTotalMinor() > 0;
            $items[] = [
                'id'    => 'budget_total',
                'done'  => $totalSet,
                'label' => $l10n->t('Set the project total budget'),
                'hint'  => $l10n->t('Then allocate a share to each workstream on the Budget tab.'),
                'link'  => ['target' => 'manage-team', 'tab' => 'project', 'section' => 'budget'],
            ];
        }

        // Time capacity per member — at least one member with availableMinutes > 0
        if ($this->isTimeEnabled($teamId)) {
            $anyCapped = false;
            try {
                foreach ($this->projectMemberMapper->findByTeam($teamId) as $pm) {
                    if ($pm->getAvailableMinutes() > 0) { $anyCapped = true; break; }
                }
            } catch (\Throwable) {
                // Non-fatal — treat as "not yet done" so the checklist
                // still renders. A hard failure surfaces in the widget
                // as "not done" rather than a red banner.
            }
            $items[] = [
                'id'    => 'time_capacity',
                'done'  => $anyCapped,
                'label' => $l10n->t('Set available hours per member'),
                'hint'  => $l10n->t('Uncapped members still log time but do not show a remaining figure.'),
                'link'  => ['target' => 'manage-team', 'tab' => 'project', 'section' => 'time'],
            ];
        }

        return $items;
    }

    // ── Planning ─────────────────────────────────────────────────────────
    //
    // v3.99.1 — Planning is now the "plan the actual work" phase. Items
    // that used to live here (dates, invites, budget total, time capacity)
    // moved to Initiation; charter + kickoff meeting joined milestones +
    // workstreams here.

    private function planningItems(string $teamId, $project, IL10N $l10n): array {
        $items = [];

        // Configure project contract — user-confirmed. The IntraVox
        // charter is auto-created at team creation but has empty body
        // sections to fill in. "Done" is tracked as a manual mark
        // because there's no programmatic signal for "the user reviewed
        // and filled this in".
        $items[] = [
            'id'       => 'charter_configured',
            'done'     => $project->getCharterConfiguredAt() !== null,
            'label'    => $l10n->t('Configure the project contract'),
            'hint'     => $l10n->t('Fill in the 9-element PMC charter in the IntraVox contract page or create a file for this if IntraVox is not installed. Mark as done here once the charter reflects the actual project.'),
            'link'     => ['target' => 'set-view', 'view' => 'pages', 'requires' => 'intravox'],
            'markable' => 'charter_configured',
        ];

        // Kickoff meeting — user-confirmed. Same manual-mark model. Link
        // opens the Add Meeting wizard; the user schedules the meeting
        // and then marks the item done.
        $items[] = [
            'id'       => 'kickoff_meeting',
            'done'     => $project->getKickoffMeetingAt() !== null,
            'label'    => $l10n->t('Schedule a project team meeting'),
            'hint'     => $l10n->t('A kickoff meeting to align the team on scope, timeline, and roles. Mark as done once it\'s on the calendar.'),
            'link'     => ['target' => 'add-meeting'],
            'markable' => 'kickoff_meeting',
        ];

        // Workstreams defined (v3.98.2) — kept in Planning by design.
        $stackCount = 0;
        try {
            $stackCount = count($this->timelineService->getDeckStacks($teamId));
        } catch (\Throwable) {}
        $items[] = [
            'id'    => 'workstreams_defined',
            'done'  => $stackCount > 1,
            'label' => $l10n->t('Define your project workstreams'),
            'hint'  => $l10n->t('Add lanes to the Deck board as they become known. Each lane is a workstream that milestones and budget lanes hang off.'),
            'link'  => ['target' => 'swimlanes-modal'],
        ];

        // Milestones added — kept in Planning by design.
        $hasMilestones = false;
        try {
            foreach ($this->milestoneMapper->findByTeam($teamId) as $m) {
                if ($m->getMilestoneDate() !== null) { $hasMilestones = true; break; }
            }
        } catch (\Throwable) {}
        $items[] = [
            'id'    => 'milestones_added',
            'done'  => $hasMilestones,
            'label' => $l10n->t('Add at least one dated milestone'),
            'hint'  => $l10n->t('Milestones own the Deck cards due before them and feed the project-health widget.'),
            'link'  => ['target' => 'manage-team', 'tab' => 'project', 'section' => 'milestones'],
        ];

        return $items;
    }

    // ── Execution ────────────────────────────────────────────────────────

    private function executionItems(string $teamId, $project, IL10N $l10n): array {
        $items = [];

        // 1. First expense logged
        if ($this->isBudgetEnabled($teamId)) {
            $items[] = [
                'id'    => 'first_expense',
                'done'  => $this->expenseMapper->hasAnyForTeam($teamId),
                'label' => $l10n->t('Log the first expense'),
                'hint'  => $l10n->t('Real spent starts populating the Budget tab and the health widget.'),
                'link'  => ['target' => 'set-view', 'view' => 'budget'],
            ];
        }

        // 2. First time entry logged
        if ($this->isTimeEnabled($teamId)) {
            $items[] = [
                'id'    => 'first_timelog',
                'done'  => $this->timeLogMapper->hasAnyForTeam($teamId),
                'label' => $l10n->t('Log the first time entry'),
                'hint'  => $l10n->t('Logged hours start populating the Time tab and the health widget.'),
                'link'  => ['target' => 'set-view', 'view' => 'time'],
            ];
        }

        // v3.99.1 — Execution advisories (milestones_on_track, within_bounds)
        // removed. Rationale: keep the Compass to actionable tasks; the
        // advisory signal already lives in the project-health widget. The
        // project-health widget's
        // Milestones tile + Budget-and-Time tile already carry those
        // signals; duplicating them in the Compass added noise without
        // action.

        return $items;
    }

    // ── Closing ──────────────────────────────────────────────────────────

    /**
     * v3.99.0 — Closing-phase readiness. Two items:
     *
     *   1. closing_artifact (required) — done when
     *      project.closing_artifact_at is set. Blocks the "Ready to
     *      archive" state until the decisions/budget/time/milestones
     *      have been exported to the team's Files folder as a
     *      human-readable snapshot that survives team archival.
     *
     *   2. team_archive (advisory) — never done from inside the app.
     *      Prompts the owner to head to Manage Team → Danger (or use the
     *      Compass footer button) when the artifact is in place. Kept
     *      advisory rather than gating so a team that's already been
     *      archived doesn't leave the Compass in a permanently-red
     *      state (there's no team to render this in that case, but the
     *      semantics stay clean).
     */
    private function closingItems(string $teamId, $project, IL10N $l10n): array {
        $items = [];

        $artifactAt = $project->getClosingArtifactAt();
        $items[] = [
            'id'    => 'closing_artifact',
            'done'  => $artifactAt !== null,
            'label' => $l10n->t('Generate the Closing artifact'),
            'hint'  => $l10n->t('Exports decisions, budget, time and milestones to the team\'s Files folder as readable markdown so the project history survives team archival.'),
            'link'  => ['target' => 'closing-artifact'],
        ];

        // v3.99.3 — evaluation meeting. Advisory (project can close
        // without it) but markable so admins who did organise a retro
        // can tick it off. Same pattern as the kickoff meeting item in
        // Planning.
        $items[] = [
            'id'       => 'evaluation_meeting',
            'done'     => $project->getEvaluationMeetingAt() !== null,
            'advisory' => true,
            'label'    => $l10n->t('Organise an evaluation meeting'),
            'hint'     => $l10n->t('A retro to look back on scope, timeline, budget, and what to carry into the next project. Optional — mark as done once it\'s on the calendar, or skip if you don\'t want to run one.'),
            'link'     => ['target' => 'add-meeting'],
            'markable' => 'evaluation_meeting',
        ];

        $items[] = [
            'id'       => 'team_archive',
            'done'     => false,
            'advisory' => true,
            'label'    => $l10n->t('Archive the team'),
            'hint'     => $l10n->t('Once the Closing artifact is generated, the team owner can archive the team from here or from Manage Team → Danger.'),
            'link'     => ['target' => 'archive-team'],
        ];

        return $items;
    }

    // ── Manual marks (v3.99.1) ───────────────────────────────────────────

    /**
     * Set or clear a user-confirmed mark timestamp on the project row.
     * Admin-gated (the controller enforces requireAdminLevel).
     *
     * $markType must be one of self::VALID_MARK_TYPES. $done=true stamps
     * time(); $done=false clears the field (for corrections/mistakes).
     *
     * @return array{isProject:bool, mode:?string, phase:?string, markType:string, done:bool, at:?int}
     */
    public function setMark(string $teamId, string $markType, bool $done): array {
        if (!in_array($markType, self::VALID_MARK_TYPES, true)) {
            throw new \InvalidArgumentException('markType must be one of: ' . implode(', ', self::VALID_MARK_TYPES));
        }
        $project = $this->projectMapper->findByTeam($teamId);
        if ($project === null || $project->getMode() !== 'advanced') {
            throw new \InvalidArgumentException('Marks apply only to Advanced project teams.');
        }

        $ts = $done ? time() : null;
        match ($markType) {
            'charter_configured' => $project->setCharterConfiguredAt($ts),
            'kickoff_meeting'    => $project->setKickoffMeetingAt($ts),
            'evaluation_meeting' => $project->setEvaluationMeetingAt($ts),
        };
        $this->projectMapper->update($project);

        return [
            'isProject' => true,
            'mode'      => $project->getMode(),
            'phase'     => $project->getPhase(),
            'markType'  => $markType,
            'done'      => $done,
            'at'        => $ts,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function hasIncomplete(array $items): bool {
        foreach ($items as $it) {
            // Advisory items surface state (e.g. slipping milestones, over
            // budget) but don't gate phase advancement. See executionItems
            // for the rationale — reality gets carried into the retro.
            if (!empty($it['advisory'])) continue;
            if (empty($it['done'])) return true;
        }
        return false;
    }

    private function safeEffectiveMemberCount(string $teamId): int {
        try {
            return $this->memberService->getEffectiveMemberCount($teamId, $this->db);
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][ProjectReadinessService] member count failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
            return 0;
        }
    }

    private function isBudgetEnabled(string $teamId): bool {
        return $this->config->getAppValue(Application::APP_ID, 'budget_enabled_' . $teamId, '1') === '1';
    }

    private function isTimeEnabled(string $teamId): bool {
        return $this->config->getAppValue(Application::APP_ID, 'time_enabled_' . $teamId, '1') === '1';
    }

    private function emptyEnvelope(): array {
        return [
            'isProject'      => false,
            'phase'          => null,
            'nextPhase'      => null,
            'readyToAdvance' => false,
            'items'          => [],
        ];
    }
}
