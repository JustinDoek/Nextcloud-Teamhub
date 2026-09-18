<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning\Step;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Service\MaintenanceService;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\OpenProject\OpenProjectProvisioningService;
use OCA\TeamHub\Service\Provisioning\MembershipPlanService;
use OCA\TeamHub\Service\Provisioning\ProvisioningContext;
use OCA\TeamHub\Service\Provisioning\StepResult;
use Psr\Log\LoggerInterface;

/**
 * Step — membership and roles, on both sides.
 *
 * 1. **TeamHub.** The wizard's members are invited (`MemberService::
 *    inviteMembers()`, the same call the wizard makes) and given their
 *    levels (`MaintenanceService::applyCreationRoles()` without a handover —
 *    the handover is the last step of the operation, because it takes the
 *    creator's owner level away and every step after it would fail).
 *    Members who chose `omit` for a missing OpenProject account are not
 *    invited; `teamhub_only` members are.
 * 2. **OpenProject.** For every member with a mapped role and a matched
 *    principal, a membership is created *as the creator*. The project's
 *    current memberships are read first, so a retry adds only what is
 *    missing and never a second membership. A refusal by OpenProject (403,
 *    422) is recorded per member; the step then reports `attention` with
 *    the list rather than failing the workspace — the team is usable, and
 *    the memberships can be retried or made by hand.
 *
 * Authority afterwards: OpenProject's. See {@see MembershipPlanService}.
 */
class MembershipStep implements StepInterface {

    public const KEY = 'membership';

    public function __construct(
        private MemberService                  $members,
        private MaintenanceService             $maintenance,
        private MembershipPlanService          $plans,
        private OpenProjectProvisioningService $op,
        private LoggerInterface                $logger,
    ) {
    }

    public function key(): string { return self::KEY; }
    public function resourceType(): ?string { return 'membership'; }
    public function applies(ProvisioningContext $ctx): bool { return true; }
    public function rollbackPossible(): bool { return false; }

    public function run(ProvisioningContext $ctx): StepResult {
        if ($ctx->teamId === null) {
            return StepResult::failed('no_team', 'The team does not exist yet.', true);
        }
        $teamId  = $ctx->teamId;
        $members = (array)$ctx->field('members', []);
        $report  = ['invited' => [], 'omitted' => [], 'levels' => [], 'openProject' => []];

        // ── 1. TeamHub ───────────────────────────────────────────────────
        $current = $this->plans->effectiveTeamMembers($teamId);
        $toInvite = [];
        $roles    = [];
        foreach ($members as $m) {
            $id   = trim((string)($m['id'] ?? ''));
            $type = (string)($m['type'] ?? 'user');
            if ($id === '') {
                continue;
            }
            if (($m['decision'] ?? null) === 'omit') {
                $report['omitted'][] = $id;
                continue;
            }
            if ($type === 'user' && isset($current[$id])) {
                $report['invited'][$id] = 'already';
            } elseif ($type === 'user' && $id === $ctx->userId) {
                $report['invited'][$id] = 'creator';
            } else {
                $toInvite[] = ['id' => $id, 'type' => $type];
            }
            $level = (int)($m['level'] ?? 1);
            if ($level > 1) {
                $roles[] = ['id' => $id, 'type' => $type, 'level' => $level];
            }
        }
        if ($toInvite !== []) {
            try {
                foreach ($this->members->inviteMembers($teamId, $toInvite) as $id => $status) {
                    $report['invited'][(string)$id] = is_string($status) ? mb_substr($status, 0, 120) : 'ok';
                }
            } catch (\Throwable $e) {
                return StepResult::failed('invite', $e->getMessage(), true, $report);
            }
        }
        if ($roles !== []) {
            try {
                $levels = $this->maintenance->applyCreationRoles($teamId, $roles, '');
                $report['levels'] = $levels['levels'] ?? [];
            } catch (\Throwable $e) {
                $report['levelsError'] = $e->getMessage();
            }
        }

        // ── 2. OpenProject ───────────────────────────────────────────────
        $projectId = $ctx->projectId();
        if ($projectId === null || !$ctx->blueprint->requiresOpenProject()) {
            $ctx->remember(self::KEY, 'report', $report);
            return StepResult::completed(null, $report);
        }

        $refused = [];
        try {
            $liveRoles = $this->op->listRoles($ctx->userId);
            $resolved  = $this->plans->resolveMapping($ctx->blueprint, $liveRoles);
            $existing  = $this->op->listMemberships($ctx->userId, $projectId);
            $planned   = $this->plans->plan($ctx->userId, $this->membersIncludingCreator($ctx, $members), $resolved, $existing);
        } catch (OpenProjectException $e) {
            return StepResult::failed($e->getErrorCode(), $e->getUpstreamMessage() ?? $e->getMessage(), true, $report);
        }

        foreach ($planned['entries'] as $entry) {
            $id = $entry['id'];
            if ($entry['status'] === 'no_access') {
                $report['openProject'][$id] = 'no_access';
                continue;
            }
            if ($entry['status'] === 'unmatched') {
                $report['openProject'][$id] = ($entry['decision'] ?? 'teamhub_only');
                continue;
            }
            if ($entry['status'] === 'exists') {
                $report['openProject'][$id] = !empty($entry['roleDrift']) ? 'exists_role_differs' : 'exists';
                continue;
            }
            $roleId = $entry['openProjectRole']['id'] ?? null;
            if ($roleId === null) {
                $report['openProject'][$id] = 'role_missing';
                $refused[] = $id;
                continue;
            }
            try {
                $this->op->createMembership(
                    $ctx->userId, $projectId, (int)$entry['principal']['id'], [(int)$roleId],
                    $entry['principal']['type'] === 'group' ? 'groups' : 'users',
                );
                $report['openProject'][$id] = 'added';
            } catch (OpenProjectException $e) {
                $report['openProject'][$id] = 'refused:' . $e->getErrorCode();
                $refused[] = $id;
                $this->logger->info('[TeamHub][MembershipStep] OpenProject refused a membership', [
                    'code' => $e->getErrorCode(), 'app' => Application::APP_ID,
                ]);
            }
        }

        $ctx->remember(self::KEY, 'report', $report);
        if ($refused !== []) {
            return StepResult::attention(
                'memberships_partial',
                'OpenProject did not accept every membership. The team is usable; the listed members can be added in OpenProject or by retrying this step.',
                $report + ['refused' => $refused],
            );
        }
        return StepResult::completed(null, $report);
    }

    public function rollback(ProvisioningContext $ctx, array $stepRow, bool $confirm): StepResult {
        // Memberships are removed with the team on TeamHub's side. On
        // OpenProject's side nothing is removed: a project that outlives the
        // workspace keeps its members — see MembershipPlanService.
        return StepResult::skipped('openproject_memberships_kept');
    }

    /**
     * @param list<array<string,mixed>> $members
     * @return list<array<string,mixed>>
     */
    private function membersIncludingCreator(ProvisioningContext $ctx, array $members): array {
        $owner   = (string)$ctx->field('ownerUid', '');
        $hasSelf = false;
        foreach ($members as $m) {
            if (($m['type'] ?? 'user') === 'user' && ($m['id'] ?? '') === $ctx->userId) {
                $hasSelf = true;
                break;
            }
        }
        if (!$hasSelf && ($owner === '' || $owner === $ctx->userId)) {
            $members[] = ['id' => $ctx->userId, 'type' => 'user', 'level' => 9, 'decision' => 'teamhub_only'];
        }
        return array_values(array_filter($members, static fn (array $m): bool => ($m['decision'] ?? null) !== 'omit'));
    }
}
