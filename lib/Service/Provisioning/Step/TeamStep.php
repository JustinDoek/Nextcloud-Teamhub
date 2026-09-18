<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning\Step;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\ResourceLinkMapper;
use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\OpenProject\ProjectAlreadyLinkedException;
use OCA\TeamHub\Service\OpenProject\TeamOpenProjectLinkService;
use OCA\TeamHub\Service\PolicyService;
use OCA\TeamHub\Service\Provisioning\ProvisioningContext;
use OCA\TeamHub\Service\Provisioning\StepResult;
use OCA\TeamHub\Service\TeamExpiryService;
use OCA\TeamHub\Service\TeamService;
use OCA\TeamHub\Service\TeamTypeService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Step 3 — the TeamHub team, created **with its OpenProject link or not at
 * all**: the same sequence `POST /api/v1/teams` runs (v4.9.4), in the same
 * order — check the project, create the circle, assign the policy, type and
 * link (which deletes the team again if the link fails), then description,
 * privacy preselection and expiry.
 *
 * ## Idempotency
 *
 * The team id is written to the operation the instant the circle exists
 * (`ProvisioningContext::setTeamId()`), before the link. A retry that finds
 * `teamId` set skips creation. A retry that finds no id but a team already
 * linked to our project, owned by the creator, adopts it — that is the
 * window between `createCircle()` and the write closing.
 *
 * ## Rollback
 *
 * The team is TeamHub's own and can be deleted through the normal cascade
 * (`TeamService::deleteTeam()`, owner-gated), which also removes the link
 * row and, via the other steps' policy, the resources. Whether the cascade
 * may run is the rollback path's decision, not this step's.
 */
class TeamStep implements StepInterface {

    public const KEY = 'team';

    public function __construct(
        private TeamService                $teams,
        private TeamTypeService            $types,
        private PolicyService              $policies,
        private TeamOpenProjectLinkService $links,
        private TeamExpiryService          $expiry,
        private MemberService              $members,
        private ResourceLinkMapper         $registry,
        private IDBConnection              $db,
        private LoggerInterface            $logger,
    ) {
    }

    public function key(): string { return self::KEY; }
    public function resourceType(): ?string { return 'team'; }
    public function applies(ProvisioningContext $ctx): bool { return true; }
    public function rollbackPossible(): bool { return true; }

    public function run(ProvisioningContext $ctx): StepResult {
        if ($ctx->teamId !== null) {
            return $this->done($ctx, $ctx->teamId, ['adopted' => true]);
        }

        $projectId = $ctx->projectId();
        $needsLink = $ctx->blueprint->requiresOpenProject();
        if ($needsLink && $projectId === null) {
            return StepResult::failed('no_project', 'The OpenProject project is not known yet.', true);
        }

        // A team already linked to our project, owned by the creator, is
        // ours from an attempt that died before recording it.
        if ($needsLink) {
            $adopted = $this->adoptableTeam($ctx, $projectId);
            if ($adopted !== null) {
                $ctx->setTeamId($adopted);
                return $this->done($ctx, $adopted, ['adopted' => true]);
            }
        }

        try {
            if ($needsLink) {
                $this->links->assertLinkableForNewTeam(TeamOpenProjectLinkService::TEMPLATE, $projectId);
            }
            $team   = $this->teams->createTeam($ctx->teamName());
            $teamId = (string)($team['id'] ?? '');
            if ($teamId === '') {
                return StepResult::failed('team_create', 'The team was not created.', true);
            }
            $ctx->setTeamId($teamId);

            $governed = $this->policies->assignAtCreation($teamId, (string)$ctx->field('profileKey', ''), (string)$ctx->operation['templateKey']);
            if ($governed !== []) {
                $this->teams->applyPolicyConfig($teamId);
            }

            if ($needsLink) {
                // Types the team and links it, or deletes the team again and
                // rethrows — Phase 1's rule, unchanged.
                $link = $this->links->linkNewTeam($teamId, $projectId);
                $ctx->remember(self::KEY, 'link', ['projectId' => $link['projectId'] ?? $projectId]);
            } else {
                $this->types->setType($teamId, (string)$ctx->operation['templateKey']);
            }
        } catch (ProjectAlreadyLinkedException $e) {
            $ctx->teamId = null;
            return StepResult::failed('project_already_linked', $e->getMessage(), false, ['teams' => $e->getTeams()]);
        } catch (OpenProjectException $e) {
            $ctx->teamId = null;
            return StepResult::failed($e->getErrorCode(), $e->getUpstreamMessage() ?? $e->getMessage(), true);
        } catch (\Throwable $e) {
            // linkNewTeam() has deleted the team on its own failure; any
            // other failure before the link leaves one, which the retry
            // adopts (it is linked) or the rollback removes.
            $this->logger->error('[TeamHub][TeamStep] Team creation failed', ['exception' => $e, 'app' => Application::APP_ID]);
            return StepResult::failed('team_create', $e->getMessage(), true);
        }

        $this->afterCreate($ctx, $teamId);
        return $this->done($ctx, $teamId);
    }

    public function rollback(ProvisioningContext $ctx, array $stepRow, bool $confirm): StepResult {
        if ($ctx->teamId === null) {
            return StepResult::skipped('nothing_to_undo');
        }
        try {
            // Owner-gated inside; the rollback path verified the caller is the
            // creator (still the owner: the handover is the last step and a
            // rolled-back operation never reached it) or impersonates them.
            $this->teams->deleteTeam($ctx->teamId);
            $this->registry->deleteByTeam($ctx->teamId);
        } catch (\Throwable $e) {
            return StepResult::failed('team_delete', $e->getMessage(), true);
        }
        return StepResult::completed($ctx->teamId, ['deleted' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────

    /** The best-effort tail of creation: description, preselected privacy bits, expiry. */
    private function afterCreate(ProvisioningContext $ctx, string $teamId): void {
        $notes = [];
        $description = trim((string)$ctx->field('description', ''));
        if ($description !== '') {
            try {
                $this->teams->updateTeamDescription($teamId, $description);
            } catch (\Throwable $e) {
                $notes[] = 'description';
            }
        }
        $preselect = (int)$ctx->field('preselectConfig', 0);
        if ($preselect > 0) {
            try {
                $this->teams->updateTeamConfig($teamId, $preselect);
            } catch (\Throwable $e) {
                $notes[] = 'privacy';
            }
        }
        $endDate = trim((string)$ctx->field('endDate', ''));
        if ($endDate !== '') {
            try {
                $this->expiry->setAtCreation($teamId, (string)$ctx->operation['templateKey'], $endDate, $ctx->userId);
            } catch (\Throwable $e) {
                $notes[] = 'expiry';
            }
        }
        if ($notes !== []) {
            $ctx->remember(self::KEY, 'bestEffortFailed', $notes);
        }
    }

    /** @param array<string,mixed> $extra */
    private function done(ProvisioningContext $ctx, string $teamId, array $extra = []): StepResult {
        $ctx->remember(self::KEY, 'teamId', $teamId);
        $projectId = $ctx->projectId();
        if ($projectId !== null) {
            $mode = (string)$ctx->fact(OpenProjectProjectStep::KEY, 'mode', 'linked');
            $this->registry->upsert(
                $teamId, 'openproject', 'project', (string)$projectId,
                $mode === 'created' ? 'created' : 'linked',
                $ctx->id(), $ctx->userId,
                is_string($ctx->fact(OpenProjectProjectStep::KEY, 'url')) ? $ctx->fact(OpenProjectProjectStep::KEY, 'url') : null,
                ['identifier' => $ctx->fact(OpenProjectProjectStep::KEY, 'identifier'), 'name' => $ctx->fact(OpenProjectProjectStep::KEY, 'name')],
            );
        }
        return StepResult::completed($teamId, $ctx->factsOf(self::KEY) + $extra);
    }

    /** A team linked to our project that the creator owns, or null. */
    private function adoptableTeam(ProvisioningContext $ctx, int $projectId): ?string {
        try {
            $linked = $this->links->linkedTeamsByProject([$projectId])[$projectId] ?? [];
            foreach ($linked as $row) {
                $teamId = (string)$row['teamId'];
                if ($this->members->getMemberLevelFromDb($this->db, $teamId, $ctx->userId) >= 9) {
                    return $teamId;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][TeamStep] adoptable-team lookup failed', ['app' => Application::APP_ID]);
        }
        return null;
    }
}
