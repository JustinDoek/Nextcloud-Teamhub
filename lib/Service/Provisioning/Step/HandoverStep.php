<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning\Step;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Service\MaintenanceService;
use OCA\TeamHub\Service\OpenProject\OpenProjectProvisioningService;
use OCA\TeamHub\Service\Provisioning\MembershipPlanService;
use OCA\TeamHub\Service\Provisioning\ProvisioningContext;
use OCA\TeamHub\Service\Provisioning\StepResult;
use Psr\Log\LoggerInterface;

/**
 * Step — hand the team to its appointed owner, **last of the writes**, for
 * the reason the wizard gives: `assignOwner()` demotes the creator, and
 * every step before this one needed their owner level. The same call the
 * wizard makes (`MaintenanceService::applyCreationRoles()` with a new
 * owner); the creator stays on the team only if they named themselves as a
 * member.
 *
 * ## The creator's OpenProject membership (Justin, 2026-09-13)
 *
 * OpenProject makes whoever creates a project a member with its "new
 * project" role — *Project admin* as shipped — whether or not TeamHub asked.
 * So a creator who appoints somebody else and leaves the team was left as a
 * second project administrator, which is not what "hand it over" means.
 * When the handover succeeded, the creator **left** the team, and the
 * project was **created by this operation**, the creator's membership in
 * the project is removed too, as them (they are still a project admin at
 * that moment; the appointed owner already has their mapped role from the
 * membership step). On an existing project (mode B) their membership
 * pre-existed and is never touched; a creator who stays on the team keeps
 * it, and any difference from their mapped role is drift the report shows.
 *
 * Idempotent: if the appointee already owns the team, the TeamHub half is
 * done; the removal is asked again only if the membership is still there.
 * Not rolled back — a rollback of the whole operation runs as the creator
 * and this step, once done, has made somebody else the owner; the rollback
 * path refuses at that point and says so.
 */
class HandoverStep implements StepInterface {

    public const KEY = 'handover';

    public function __construct(
        private MaintenanceService             $maintenance,
        private OpenProjectProvisioningService $op,
        private MembershipPlanService          $plans,
        private LoggerInterface                $logger,
    ) {
    }

    public function key(): string { return self::KEY; }
    public function resourceType(): ?string { return null; }
    public function rollbackPossible(): bool { return false; }

    public function applies(ProvisioningContext $ctx): bool {
        $owner = trim((string)$ctx->field('ownerUid', ''));
        return $owner !== '' && $owner !== $ctx->userId;
    }

    public function run(ProvisioningContext $ctx): StepResult {
        if ($ctx->teamId === null) {
            return StepResult::failed('no_team', 'The team does not exist yet.', true);
        }
        $owner = trim((string)$ctx->field('ownerUid', ''));
        $roles = [];
        foreach ((array)$ctx->field('members', []) as $m) {
            if (($m['decision'] ?? null) === 'omit') {
                continue;
            }
            $roles[] = ['id' => (string)($m['id'] ?? ''), 'type' => (string)($m['type'] ?? 'user'), 'level' => 1];
        }

        $creatorLeft = null;
        try {
            $result = $this->maintenance->applyCreationRoles($ctx->teamId, $roles, $owner);
            $status = (string)($result['owner']['status'] ?? 'skipped');
            $ctx->remember(self::KEY, 'owner', $result['owner'] ?? null);
            if ($status === 'failed') {
                return StepResult::attention('handover_failed', 'The team was created, but ownership could not be transferred. The creator is still the owner and can hand over from Manage team.', ['owner' => $result['owner']]);
            }
            $creatorLeft = (bool)($result['owner']['creatorLeft'] ?? false);
        } catch (\Throwable $e) {
            // The creator no longer owns the team: the handover happened on
            // an earlier attempt. That is success, not failure; whether they
            // left is what the earlier attempt recorded.
            if (!str_contains($e->getMessage(), 'Only the team owner')) {
                return StepResult::failed('handover', $e->getMessage(), true);
            }
            $creatorLeft = (bool)($ctx->fact(self::KEY, 'owner')['creatorLeft'] ?? false);
            $ctx->remember(self::KEY, 'adopted', true);
        }
        $ctx->remember(self::KEY, 'creatorLeft', $creatorLeft);

        // ── The creator's own membership in a project this operation made ──
        $projectId = $ctx->projectId();
        $created   = (string)$ctx->fact(OpenProjectProjectStep::KEY, 'mode', 'linked') === 'created';
        if ($creatorLeft && $created && $projectId !== null) {
            $removal = $this->removeCreatorFromProject($ctx, $projectId);
            $ctx->remember(self::KEY, 'creatorRemovedFromProject', $removal['removed']);
            if (!$removal['removed'] && $removal['error'] !== null) {
                return StepResult::attention(
                    'creator_still_in_project',
                    'The team was handed over, but the creator could not be removed from the OpenProject project. The new owner can remove them in OpenProject.',
                    $ctx->factsOf(self::KEY) + ['error' => $removal['error']],
                    $owner,
                );
            }
        }

        return StepResult::completed($owner, $ctx->factsOf(self::KEY));
    }

    public function rollback(ProvisioningContext $ctx, array $stepRow, bool $confirm): StepResult {
        return StepResult::skipped('not_reversible');
    }

    /**
     * Remove the creator's membership from the project, as the creator.
     * Nothing to do when they are not a member (an earlier attempt already
     * removed them, or OpenProject gave the creator no membership).
     *
     * @return array{removed: bool, error: ?string}
     */
    private function removeCreatorFromProject(ProvisioningContext $ctx, int $projectId): array {
        try {
            $me = $this->plans->matchUser($ctx->userId, $ctx->userId);
            if ($me === null) {
                return ['removed' => false, 'error' => 'creator_not_matched'];
            }
            $membershipId = null;
            foreach ($this->op->listMemberships($ctx->userId, $projectId) as $m) {
                if ($m['principalType'] === 'user' && $m['principalId'] === $me['id']) {
                    $membershipId = $m['id'];
                    break;
                }
            }
            if ($membershipId === null) {
                return ['removed' => true, 'error' => null];
            }
            $this->op->deleteMembership($ctx->userId, $membershipId);
            $this->logger->info('[TeamHub][HandoverStep] Removed the creator from the project they handed over', [
                'operation' => $ctx->id(), 'projectId' => $projectId, 'app' => Application::APP_ID,
            ]);
            return ['removed' => true, 'error' => null];
        } catch (OpenProjectException $e) {
            return ['removed' => false, 'error' => $e->getErrorCode()];
        }
    }
}
