<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\Project;
use OCA\TeamHub\Db\ProjectMapper;
use OCA\TeamHub\Exception\LicenseGateException;
use OCP\IConfig;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Service for Project Teams (keystone, v3.88.0).
 *
 * A "project" is a team created from the Project template. Its persisted row
 * (teamhub_project) records:
 *   - mode  — 'basic' (cosmetic, no lifecycle) or 'advanced' (PMC lifecycle)
 *   - phase — only for advanced: initiation|planning|execution|closing
 *
 * Auth model (mirrors MilestoneService / DecisionTeamService):
 *   - getForTeam requires team membership (level ≥ 1) — the phase stepper is
 *     shown to every member, read-only.
 *   - upsert / setPhase require team admin level (≥ 8). Non-admins never see
 *     the write affordances (hidden in the UI) and are refused here too.
 *
 * Error contract for the controller:
 *   - \InvalidArgumentException → 400 (bad mode/phase, phase on a basic project)
 *   - MemberService throws      → 403 (not a member / not admin)
 */
class ProjectService {

    /** Valid values for teamhub_project.mode. */
    public const MODES = ['basic', 'advanced'];

    /** Ordered PMC phases for advanced projects. */
    public const PHASES = ['initiation', 'planning', 'execution', 'closing'];

    /**
     * v3.99.5 — restored to 'initiation' after the 3.99.2 rollback. The
     * root cause of the 3.99.1 breakage was never confirmed; the retry
     * bets that whatever it was is either specific to intermediate code
     * or has already been fixed by later patches (3.99.3 phase-guide
     * updates, 3.99.4 Decisions integration). If the "everything gone"
     * symptom returns, the diagnostic logger->info/error calls in upsert
     * below will surface where the flow dies.
     */
    public const DEFAULT_PHASE = 'initiation';

    public function __construct(
        private ProjectMapper           $mapper,
        private MemberService           $memberService,
        private AuditService            $auditService,
        // v3.99.4 — Advanced projects auto-enable Decisions and seed a
        // "Project management" category. Both services have narrow deps
        // (no MemberService transitively → no ResourceService → no
        // DeckService), so they're safe to inject here without the DI
        // cycle we hit in earlier passes.
        private DecisionTeamService     $decisionTeamService,
        private DecisionCategoryService $decisionCategoryService,
        private IConfig                 $config,
        private IFactory                $l10nFactory,
        // v3.100.0 — Track F licensing gate. LicenseService depends only
        // on IConfig / IDBConnection / Logger, so no DI cycle risk.
        private LicenseService          $licenseService,
        private LoggerInterface         $logger,
    ) {}

    /**
     * Project facts for a team. Membership-gated (read).
     *
     * @return array{isProject:bool, mode:?string, phase:?string, startDate:?int, targetEnd:?int}
     */
    public function getForTeam(string $teamId): array {
        $this->memberService->requireMemberLevel($teamId);

        $row = $this->mapper->findByTeam($teamId);
        if ($row === null) {
            return [
                'isProject' => false,
                'mode'      => null,
                'phase'     => null,
                'startDate' => null,
                'targetEnd' => null,
            ];
        }
        return $this->serialize($row);
    }

    /**
     * Create or update the project record for a team. Admin-gated.
     *
     * Called by the create wizard (Project template) and by the "Upgrade to
     * Advanced" action in Manage Team. Phase is derived from mode:
     *   - advanced with no existing phase → DEFAULT_PHASE ('planning')
     *   - advanced with an existing phase → kept
     *   - basic                          → null
     *
     * @return array{isProject:bool, mode:?string, phase:?string, startDate:?int, targetEnd:?int}
     */
    public function upsert(
        string $teamId,
        string $mode,
        ?int   $startDate,
        ?int   $targetEnd,
        string $actingUserId
    ): array {
        // v3.99.5 diagnostic — the 3.99.1 "everything gone" symptom
        // meant PUT /project was failing silently. Log entry so the
        // next reoccurrence surfaces where the flow died.
        $this->logger->info('[TeamHub][ProjectService] upsert called', [
            'teamId' => $teamId, 'mode' => $mode, 'defaultPhase' => self::DEFAULT_PHASE,
            'app'    => Application::APP_ID,
        ]);

        try {
            $this->memberService->requireAdminLevel($teamId);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][ProjectService] upsert: requireAdminLevel failed: ' . $e->getMessage(), [
                'teamId' => $teamId, 'actingUserId' => $actingUserId,
                'app'    => Application::APP_ID,
            ]);
            throw $e;
        }
        $mode = $this->validateMode($mode);

        $existing = $this->mapper->findByTeam($teamId);

        // v3.100.0 — Track F licensing gate. Advanced-team CREATION and
        // basic → advanced UPGRADES require an active license (i.e. not
        // in grace, not soft-lock, not unlicensed). Existing Advanced
        // teams that were legit at creation-time are grandfathered — the
        // gate does not fire on advanced→advanced re-saves.
        $isNewAdvanced      = $existing === null && $mode === 'advanced';
        $isUpgradeToAdvanced = $existing !== null
            && $existing->getMode() !== 'advanced'
            && $mode === 'advanced';
        if (($isNewAdvanced || $isUpgradeToAdvanced) && !$this->licenseService->allowsAdvancedCreation()) {
            $status = $this->licenseService->getStatus();
            $this->logger->info('[TeamHub][ProjectService] upsert: license gate rejected Advanced creation', [
                'teamId'           => $teamId,
                'enforcementLevel' => $status['enforcementLevel'],
                'app'              => Application::APP_ID,
            ]);
            throw new LicenseGateException(
                $status['enforcementLevel'],
                'Advanced projects require a valid TeamHub Business license. Ask your administrator to enter one from Settings → Administration → TeamHub → License.',
            );
        }

        if ($existing === null) {
            $phase = $mode === 'advanced' ? self::DEFAULT_PHASE : null;
            $this->logger->info('[TeamHub][ProjectService] upsert: inserting new project', [
                'teamId' => $teamId, 'mode' => $mode, 'phase' => $phase,
                'app'    => Application::APP_ID,
            ]);
            try {
                $row = $this->mapper->insertProject($teamId, $mode, $phase, $startDate, $targetEnd, $actingUserId);
            } catch (\Throwable $e) {
                $this->logger->error('[TeamHub][ProjectService] upsert: insertProject failed: ' . $e->getMessage(), [
                    'teamId' => $teamId, 'mode' => $mode, 'phase' => $phase,
                    'trace'  => $e->getTraceAsString(),
                    'app'    => Application::APP_ID,
                ]);
                throw $e;
            }

            $this->auditService->log(
                $teamId, 'project.created', $actingUserId, 'project', (string)$row->getId(),
                ['mode' => $mode, 'phase' => $phase],
            );
            $this->logger->info('[TeamHub][ProjectService] created', [
                'teamId' => $teamId, 'mode' => $mode, 'phase' => $phase, 'app' => Application::APP_ID,
            ]);

            // v3.99.4 — new advanced project → enable Decisions and seed
            // "Project management" as the default category. Non-fatal:
            // any failure logs a warning but doesn't block team creation.
            if ($mode === 'advanced') {
                $this->seedAdvancedProjectDecisions($teamId, $actingUserId);
            }

            return $this->serialize($row);
        }

        // Update path — capture old values for the audit diff.
        $old = [
            'mode'      => $existing->getMode(),
            'phase'     => $existing->getPhase(),
            'startDate' => $existing->getStartDate(),
            'targetEnd' => $existing->getTargetEnd(),
        ];

        $newPhase = $existing->getPhase();
        if ($mode === 'advanced' && $newPhase === null) {
            $newPhase = self::DEFAULT_PHASE;   // basic → advanced upgrade
        } elseif ($mode === 'basic') {
            $newPhase = null;                  // advanced → basic downgrade
        }

        $existing->setMode($mode);
        $existing->setPhase($newPhase);
        $existing->setStartDate($startDate);
        $existing->setTargetEnd($targetEnd);
        $existing->setUpdatedAt(time());
        /** @var Project $existing */
        $existing = $this->mapper->update($existing);

        $diff = $this->auditService->buildDiff($old, [
            'mode'      => $newPhase === null ? 'basic' : $mode,
            'phase'     => $newPhase,
            'startDate' => $startDate,
            'targetEnd' => $targetEnd,
        ]);
        if ($diff !== null) {
            $event = $old['mode'] !== $mode ? 'project.mode_changed' : 'project.updated';
            $this->auditService->log($teamId, $event, $actingUserId, 'project', (string)$existing->getId(), $diff);
        }
        $this->logger->info('[TeamHub][ProjectService] upsert', [
            'teamId' => $teamId, 'mode' => $mode, 'phase' => $newPhase, 'app' => Application::APP_ID,
        ]);

        // v3.99.4 — basic → advanced upgrade also seeds Decisions +
        // "Project management" category. Idempotent (seed helper swallows
        // duplicate errors), so retrying an upgrade or repeatedly
        // re-saving is safe.
        if ($old['mode'] !== 'advanced' && $mode === 'advanced') {
            $this->seedAdvancedProjectDecisions($teamId, $actingUserId);
        }

        return $this->serialize($existing);
    }

    /**
     * Advance/set the lifecycle phase of an advanced project. Admin-gated.
     *
     * @return array{isProject:bool, mode:?string, phase:?string, startDate:?int, targetEnd:?int}
     */
    public function setPhase(string $teamId, string $phase, string $actingUserId): array {
        $this->memberService->requireAdminLevel($teamId);
        $phase = $this->validatePhase($phase);

        $row = $this->mapper->findByTeam($teamId);
        if ($row === null) {
            throw new \InvalidArgumentException('This team is not a project');
        }
        if ($row->getMode() !== 'advanced') {
            throw new \InvalidArgumentException('Phase applies to advanced projects only');
        }

        $oldPhase = $row->getPhase();
        if ($oldPhase === $phase) {
            return $this->serialize($row); // no-op, no audit noise
        }

        $row->setPhase($phase);
        $row->setUpdatedAt(time());
        /** @var Project $row */
        $row = $this->mapper->update($row);

        $this->auditService->log(
            $teamId, 'project.phase_changed', $actingUserId, 'project', (string)$row->getId(),
            ['phase' => ['old' => $oldPhase, 'new' => $phase]],
        );
        $this->logger->info('[TeamHub][ProjectService] setPhase', [
            'teamId' => $teamId, 'from' => $oldPhase, 'to' => $phase, 'app' => Application::APP_ID,
        ]);

        return $this->serialize($row);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @return array{isProject:bool, mode:?string, phase:?string, startDate:?int, targetEnd:?int}
     */
    private function serialize(Project $row): array {
        return [
            'isProject' => true,
            'mode'      => $row->getMode(),
            'phase'     => $row->getPhase(),
            'startDate' => $row->getStartDate(),
            'targetEnd' => $row->getTargetEnd(),
        ];
    }

    /**
     * v3.99.4 — enable Decisions for the team and seed the "Project
     * management" category. Called from the "created advanced" and
     * "basic → advanced" upsert branches. Non-fatal — any failure logs
     * and returns; the team is already an Advanced project.
     *
     * Idempotent:
     *   - DecisionTeamService::saveConfig upserts the config row.
     *   - DecisionCategoryService::createCategory throws InvalidArgument
     *     on duplicate; we swallow that.
     *
     * The Decisions module is enabled globally by default on install;
     * teams that hit this with the module globally off end up with a
     * team-level enabled flag but the module still off overall — same
     * behaviour any admin gets when they enable Decisions per-team while
     * the global toggle is off. Not worth pre-checking.
     */
    private function seedAdvancedProjectDecisions(string $teamId, string $actingUserId): void {
        try {
            $this->decisionTeamService->saveConfig($teamId, ['decisions_enabled' => true]);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ProjectService] seedAdvancedProjectDecisions: enabling Decisions failed: ' . $e->getMessage(), [
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
            return;
        }

        // Localise "Project management" per creator's NC language — same
        // pattern DeckService uses for the auto-created stack. Once stored
        // the category name stays in that language; a category rename is
        // an admin action, not something an app upgrade should do.
        try {
            $lang = $this->config->getUserValue($actingUserId, 'core', 'lang', '');
            if ($lang === '') {
                $lang = $this->config->getSystemValue('default_language', 'en');
            }
            $l = $this->l10nFactory->get(Application::APP_ID, $lang);
            $categoryName = $l->t('Project management');
        } catch (\Throwable) {
            $categoryName = 'Project management';
        }

        try {
            $this->decisionCategoryService->createCategory(
                $teamId,
                $categoryName,
                $actingUserId,
            );
            $this->logger->info('[TeamHub][ProjectService] seedAdvancedProjectDecisions: seeded category', [
                'teamId' => $teamId, 'name' => $categoryName, 'app' => Application::APP_ID,
            ]);
        } catch (\InvalidArgumentException $e) {
            // Duplicate name — either a previous seed already ran, or the
            // admin manually created a same-named category. Either way,
            // there's nothing to fix. Log at debug level.
            $this->logger->debug('[TeamHub][ProjectService] seedAdvancedProjectDecisions: category already exists', [
                'teamId' => $teamId, 'name' => $categoryName, 'app' => Application::APP_ID,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ProjectService] seedAdvancedProjectDecisions: category create failed: ' . $e->getMessage(), [
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
        }
    }

    private function validateMode(string $mode): string {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('mode must be one of: ' . implode(', ', self::MODES));
        }
        return $mode;
    }

    private function validatePhase(string $phase): string {
        $phase = strtolower(trim($phase));
        if (!in_array($phase, self::PHASES, true)) {
            throw new \InvalidArgumentException('phase must be one of: ' . implode(', ', self::PHASES));
        }
        return $phase;
    }
}
