<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\DecisionMapper;
use OCA\TeamHub\Db\MilestoneMapper;
use OCA\TeamHub\Db\Project;
use OCA\TeamHub\Db\ProjectMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * ClosingArtifactService — Track E Session 7 (v3.99.0).
 *
 * Generates a human-readable snapshot of an Advanced project's decisions,
 * budget, time investment, milestones, and roster into the team's Files
 * folder before archiving. The snapshot survives team archival — files
 * live in the team folder, not in TeamHub state — so historical project
 * information remains checkable after the team itself is gone from the
 * app.
 *
 * ARTIFACT LAYOUT
 * ---------------
 *   <team-files>/Project Closing/
 *     summary.md      — one-page overview
 *     decisions.md    — all decisions with status, impact, category, milestone
 *     budget.md       — project total, per-lane allocation + real spent, expenses
 *     time.md         — per-member available vs logged, per-lane roll-up
 *     milestones.md   — dated milestones with reached / open status
 *
 * Each file is written independently — a partial failure (say milestones
 * fails but budget succeeds) still preserves whatever we could produce.
 * Overall success is defined as all five files present.
 *
 * AUTH / GATING
 * -------------
 * The caller (ProjectController::generateClosing) enforces admin level
 * via MemberService::requireAdminLevel. We deliberately do NOT inject
 * MemberService here because MemberService transitively depends on
 * ResourceService which depends on this service's cousin DeckService —
 * the cycle NC's DI container walks into. Same pattern DeckService uses.
 *
 * IDEMPOTENCY
 * -----------
 * Regenerating overwrites — the artifact always reflects the last run.
 * `teamhub_project.closing_artifact_at` is set to time() on success so
 * the Compass's closing_artifact readiness item is satisfied.
 */
class ClosingArtifactService {

    private const ARTIFACT_FOLDER_NAME = 'Project Closing';

    public function __construct(
        private ProjectMapper        $projectMapper,
        private MilestoneMapper      $milestoneMapper,
        private DecisionMapper       $decisionMapper,
        private BudgetService        $budgetService,
        private TimeService          $timeService,
        private ResourceService      $resourceService,
        private ContainerInterface   $container,
        private TimezoneService      $timezoneService,
        private LoggerInterface      $logger,
    ) {}

    /**
     * Generate the closing artifact for a team. Returns the path (as
     * displayed in NC Files) on success.
     *
     * $uid is the person who pressed the button — every date in the rendered
     * documents is formatted in their timezone, so the artifact reads in the
     * dates they saw on screen rather than the server's UTC day.
     *
     * @return array{filePath: string, generatedAt: int}
     * @throws \RuntimeException on any hard failure (no Files folder,
     *                          write error, project not Advanced).
     */
    public function generate(string $teamId, string $uid = ''): array {
        $project = $this->projectMapper->findByTeam($teamId);
        if ($project === null || $project->getMode() !== 'advanced') {
            throw new \RuntimeException('Closing artifact is only available for Advanced projects.');
        }

        $folder = $this->resolveArtifactFolder($teamId);
        if ($folder === null) {
            throw new \RuntimeException('This team has no Files folder to write into. Enable the Files integration first.');
        }

        // Load everything up front so a per-file failure doesn't leave the
        // files inconsistent with one another.
        $data = [
            'project'    => $project,
            'teamId'     => $teamId,
            'uid'        => $uid,
            'decisions'  => $this->safeLoadDecisions($teamId),
            'budget'     => $this->safeLoadBudget($teamId),
            'time'       => $this->safeLoadTime($teamId),
            'milestones' => $this->safeLoadMilestones($teamId),
        ];

        $this->writeFile($folder, 'summary.md',    $this->renderSummary($data));
        $this->writeFile($folder, 'decisions.md',  $this->renderDecisions($data));
        $this->writeFile($folder, 'budget.md',     $this->renderBudget($data));
        $this->writeFile($folder, 'time.md',       $this->renderTime($data));
        $this->writeFile($folder, 'milestones.md', $this->renderMilestones($data));

        $now = time();
        $project->setClosingArtifactAt($now);
        $this->projectMapper->update($project);

        $this->logger->info('[TeamHub][ClosingArtifactService] artifact generated', [
            'teamId'      => $teamId,
            'folderPath'  => $folder->getPath(),
            'generatedAt' => $now,
            'app'         => Application::APP_ID,
        ]);

        return [
            'filePath'    => $folder->getPath(),
            'generatedAt' => $now,
        ];
    }

    /**
     * Read status. Never throws — returns generated=false on any failure
     * so the Compass can render its "not yet generated" state cleanly.
     *
     * @return array{generated: bool, generatedAt: ?int, filePath: ?string}
     */
    public function getStatus(string $teamId): array {
        try {
            $project = $this->projectMapper->findByTeam($teamId);
            if ($project === null) {
                return ['generated' => false, 'generatedAt' => null, 'filePath' => null];
            }
            $ts = $project->getClosingArtifactAt();
            $folder = $this->resolveArtifactFolder($teamId, /*create*/ false);
            return [
                'generated'   => $ts !== null,
                'generatedAt' => $ts,
                'filePath'    => $folder !== null ? $folder->getPath() : null,
            ];
        } catch (\Throwable) {
            return ['generated' => false, 'generatedAt' => null, 'filePath' => null];
        }
    }

    // ── Folder resolution ────────────────────────────────────────────────

    /**
     * Resolve <team-files>/Project Closing/, creating it if $create=true.
     */
    private function resolveArtifactFolder(string $teamId, bool $create = true): ?Folder {
        $teamFolder = $this->resolveTeamFolder($teamId);
        if ($teamFolder === null) return null;

        try {
            $node = $teamFolder->get(self::ARTIFACT_FOLDER_NAME);
            if ($node instanceof Folder) {
                return $node;
            }
            // A non-folder node exists with the same name — refuse rather than clobber.
            $this->logger->warning('[TeamHub][ClosingArtifactService] a non-folder node blocks Project Closing/', [
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
            return null;
        } catch (NotFoundException) {
            if (!$create) return null;
            try {
                return $teamFolder->newFolder(self::ARTIFACT_FOLDER_NAME);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][ClosingArtifactService] newFolder failed: ' . $e->getMessage(), [
                    'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
                return null;
            }
        }
    }

    /**
     * Same pattern MeetingService::resolveTeamFolder uses — team resource
     * lookup → folder id → IRootFolder::getById. Returns null when the
     * team has no Files resource registered or the caller can't see it.
     */
    private function resolveTeamFolder(string $teamId): ?Folder {
        try {
            $resources = $this->resourceService->getTeamResources($teamId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ClosingArtifactService] resolveTeamFolder failed: ' . $e->getMessage(), [
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
            return null;
        }
        if (empty($resources['files']) || empty($resources['files']['folder_id'])) {
            return null;
        }
        $folderId = (int)$resources['files']['folder_id'];
        $root = $this->container->get(IRootFolder::class);
        $nodes = $root->getById($folderId);
        if (empty($nodes)) return null;
        $node = $nodes[0];
        return $node instanceof Folder ? $node : null;
    }

    private function writeFile(Folder $folder, string $name, string $body): void {
        try {
            $file = null;
            try {
                $file = $folder->get($name);
            } catch (NotFoundException) {
                $file = $folder->newFile($name);
            }
            if ($file instanceof File) {
                $file->putContent($body);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ClosingArtifactService] write failed: ' . $name . ' — ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
            throw new \RuntimeException('Failed to write ' . $name . ': ' . $e->getMessage());
        }
    }

    // ── Data loaders (silent-degrade) ────────────────────────────────────

    private function safeLoadDecisions(string $teamId): array {
        try {
            return $this->decisionMapper->list($teamId, [], 'recent', null, 500);
        } catch (\Throwable) { return []; }
    }
    private function safeLoadBudget(string $teamId): array {
        try { return $this->budgetService->getProjectBudget($teamId); }
        catch (\Throwable) { return []; }
    }
    private function safeLoadTime(string $teamId): array {
        try { return $this->timeService->getProjectTime($teamId); }
        catch (\Throwable) { return []; }
    }
    private function safeLoadMilestones(string $teamId): array {
        try {
            $rows = $this->milestoneMapper->findByTeam($teamId);
            usort($rows, static function ($a, $b) {
                $da = $a->getMilestoneDate();
                $db = $b->getMilestoneDate();
                if ($da === null && $db === null) return $a->getId() <=> $b->getId();
                if ($da === null) return 1;
                if ($db === null) return -1;
                return $da <=> $db;
            });
            return $rows;
        } catch (\Throwable) { return []; }
    }

    // ── Renderers ────────────────────────────────────────────────────────

    private function renderSummary(array $data): string {
        /** @var Project $p */
        $p = $data['project'];
        $uid = (string)($data['uid'] ?? '');
        $now = $this->timezoneService->today($uid);
        $start = $p->getStartDate() ? $this->timezoneService->formatTimestamp((int)$p->getStartDate(), $uid) : '—';
        $end   = $p->getTargetEnd() ? $this->timezoneService->formatTimestamp((int)$p->getTargetEnd(), $uid) : '—';

        $decisionCount   = count($data['decisions'] ?? []);
        $milestoneCount  = count($data['milestones'] ?? []);
        $budget = $data['budget'] ?? [];
        $time   = $data['time'] ?? [];

        $s  = "# Project Closing — Summary\n\n";
        $s .= "_Generated on {$now} by the TeamHub Closing artifact service._\n\n";
        $s .= "## Overview\n\n";
        $s .= "- **Mode:** " . ($p->getMode() ?? '') . "\n";
        $s .= "- **Phase at generation:** " . ($p->getPhase() ?? '—') . "\n";
        $s .= "- **Start date:** {$start}\n";
        $s .= "- **Target end date:** {$end}\n";
        $s .= "- **Currency:** " . ($p->getCurrency() ?? '—') . "\n";
        $s .= "\n## Counts\n\n";
        $s .= "- Decisions recorded: **{$decisionCount}**\n";
        $s .= "- Milestones: **{$milestoneCount}**\n";
        if (!empty($budget['lanes'])) {
            $s .= "- Budget lanes: **" . count($budget['lanes']) . "**\n";
        }
        if (!empty($time['members'])) {
            $s .= "- Project members with time capacity: **" . count($time['members']) . "**\n";
        }
        $s .= "\n## What's in this folder\n\n";
        $s .= "- `decisions.md` — every decision proposed, with status, impact, category, and milestone linkage.\n";
        $s .= "- `budget.md` — project total, per-lane allocation vs real spent, and expense line items.\n";
        $s .= "- `time.md` — per-member available vs logged hours, per-lane roll-up.\n";
        $s .= "- `milestones.md` — dated milestones in project order with reached / open status.\n";
        return $s;
    }

    private function renderDecisions(array $data): string {
        $s  = "# Decisions\n\n";
        $rows = $data['decisions'] ?? [];
        if (empty($rows)) {
            $s .= "_No decisions recorded._\n";
            return $s;
        }
        $s .= "| # | Question | Status | Impact | Category | Proposed by | Date |\n";
        $s .= "|---|---|---|---|---|---|---|\n";
        foreach ($rows as $r) {
            $id       = $r->getId();
            $question = $this->mdEscape($r->getQuestion());
            $status   = $r->getStatus();
            $impact   = $r->getImpact();
            $category = $this->mdEscape($r->getCategory() ?? '—');
            $by       = $this->mdEscape($r->getProposedBy());
            $date     = $this->timezoneService->formatTimestamp((int)$r->getCreatedAt(), (string)($data['uid'] ?? ''));
            $s .= "| {$id} | {$question} | {$status} | {$impact} | {$category} | {$by} | {$date} |\n";
        }
        return $s;
    }

    private function renderBudget(array $data): string {
        $s  = "# Budget\n\n";
        $b = $data['budget'] ?? [];
        if (empty($b) || empty($b['isProject'])) {
            $s .= "_No budget data available._\n";
            return $s;
        }
        $currency = (string)($b['currency'] ?? '');
        $total    = $this->minorToMajorStr($b['totalMinor'] ?? null);
        $alloc    = $this->minorToMajorStr($b['allocatedMinor'] ?? 0);
        $realSp   = $this->minorToMajorStr($b['spentRealMinor'] ?? 0);
        $projSp   = $this->minorToMajorStr($b['spentProjectedMinor'] ?? 0);
        $s .= "## Totals ({$currency})\n\n";
        $s .= "- Project total: **{$total}**\n";
        $s .= "- Allocated across lanes: **{$alloc}**\n";
        $s .= "- Spent (real): **{$realSp}**\n";
        $s .= "- Spent (projected): **{$projSp}**\n";

        $s .= "\n## Per lane\n\n";
        foreach ($b['lanes'] ?? [] as $lane) {
            $t   = $this->mdEscape($lane['stackTitle'] ?? '(untitled)');
            $al  = $this->minorToMajorStr($lane['allocatedMinor'] ?? null);
            $rs  = $this->minorToMajorStr($lane['spentRealMinor'] ?? 0);
            $ps  = $this->minorToMajorStr($lane['spentProjectedMinor'] ?? 0);
            $s  .= "### {$t}\n\n";
            $s  .= "- Allocated: {$al}\n";
            $s  .= "- Real spent: {$rs}\n";
            $s  .= "- Projected spent: {$ps}\n\n";
            $exps = $lane['expenses'] ?? [];
            if (!empty($exps)) {
                $s .= "| Description | Projected | Real | Incurred |\n";
                $s .= "|---|---|---|---|\n";
                foreach ($exps as $e) {
                    $desc = $this->mdEscape($e['description'] ?? '');
                    $pj = $this->minorToMajorStr($e['projectedMinor'] ?? 0);
                    $rl = $this->minorToMajorStr($e['realMinor'] ?? null);
                    $iat = !empty($e['incurredAt'])
                        ? $this->timezoneService->formatTimestamp((int)$e['incurredAt'], (string)($data['uid'] ?? ''))
                        : '—';
                    $s .= "| {$desc} | {$pj} | {$rl} | {$iat} |\n";
                }
                $s .= "\n";
            }
        }
        return $s;
    }

    private function renderTime(array $data): string {
        $s  = "# Time investment\n\n";
        $t = $data['time'] ?? [];
        if (empty($t) || empty($t['isProject'])) {
            $s .= "_No time data available._\n";
            return $s;
        }
        $availH = $this->minutesToHoursStr($t['totalAvailableMinutes'] ?? 0);
        $loggedH = $this->minutesToHoursStr($t['totalLoggedMinutes'] ?? 0);
        $s .= "## Totals\n\n";
        $s .= "- Total available (capped members only): **{$availH}**\n";
        $s .= "- Total logged: **{$loggedH}**\n";

        $s .= "\n## Per member\n\n";
        $s .= "| Member | Available | Logged | Remaining |\n";
        $s .= "|---|---|---|---|\n";
        foreach ($t['members'] ?? [] as $m) {
            $name = $this->mdEscape($m['displayName'] ?? $m['userId'] ?? '');
            $av = $m['availableMinutes'] > 0 ? $this->minutesToHoursStr($m['availableMinutes']) : '_uncapped_';
            $lg = $this->minutesToHoursStr($m['loggedMinutes'] ?? 0);
            $rm = $m['remainingMinutes'] !== null ? $this->minutesToHoursStr($m['remainingMinutes']) : '—';
            $s .= "| {$name} | {$av} | {$lg} | {$rm} |\n";
        }

        $s .= "\n## Per lane\n\n";
        $s .= "| Lane | Logged |\n";
        $s .= "|---|---|\n";
        foreach ($t['lanes'] ?? [] as $lane) {
            $name = $this->mdEscape($lane['stackTitle'] ?? '');
            $lg = $this->minutesToHoursStr($lane['loggedMinutes'] ?? 0);
            $s .= "| {$name} | {$lg} |\n";
        }
        return $s;
    }

    private function renderMilestones(array $data): string {
        $s  = "# Milestones\n\n";
        $rows = $data['milestones'] ?? [];
        if (empty($rows)) {
            $s .= "_No milestones recorded._\n";
            return $s;
        }
        $s .= "| Label | Date | Reached |\n";
        $s .= "|---|---|---|\n";
        $uid = (string)($data['uid'] ?? '');
        foreach ($rows as $r) {
            $label = $this->mdEscape($r->getLabel());
            $ts = $r->getMilestoneDate();
            $date = $ts !== null ? $this->timezoneService->formatTimestamp((int)$ts, $uid) : '_undated_';
            $posted = $r->getPostedAt();
            $reached = $posted !== null
                ? $this->timezoneService->formatTimestamp((int)$posted, $uid)
                : ($ts !== null && $ts < time() ? '_past date, not yet announced_' : '_upcoming_');
            $s .= "| {$label} | {$date} | {$reached} |\n";
        }
        return $s;
    }

    // ── Formatting helpers ───────────────────────────────────────────────

    private function mdEscape(string $s): string {
        // Enough to keep table rows from breaking. Not a full markdown
        // sanitiser — decisions/expense descriptions are already user
        // input that the app treats as text.
        return str_replace(['|', "\n", "\r"], [' \\| ', ' ', ''], $s);
    }

    private function minorToMajorStr(mixed $minor): string {
        if ($minor === null) return '—';
        $m = (int)$minor;
        return number_format($m / 100, 2, '.', '');
    }

    private function minutesToHoursStr(int $minutes): string {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($m === 0) return $h . 'h';
        return sprintf('%dh %02dm', $h, $m);
    }
}
