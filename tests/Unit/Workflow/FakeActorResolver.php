<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Service\Workflow\WorkflowActorResolver;

/**
 * The actor resolver over an in-memory roster (WorkflowHub phase 1 tests).
 *
 * Only the lookups are replaced — Circles levels, group membership, the
 * admin flag, the user's teams and groups. `holds()` is inherited, so the
 * permission rule under test is the one that ships.
 */
class FakeActorResolver extends WorkflowActorResolver {

    /** @var array<string, array<string, int>> teamId → uid → direct level */
    public array $levels = [];
    /** @var array<string, string[]> teamId → uids reachable only through a group */
    public array $indirect = [];
    /** @var array<string, string[]> uid → group ids */
    public array $groups = [];
    /** @var string[] */
    public array $admins = [];
    /** @var array<string, string[]> service team id → eligible agent uids (v4.10.20) */
    public array $serviceAgents = [];

    public function __construct() {
        // No Nextcloud.
    }

    public function memberLevel(string $uid, string $teamId): int {
        return $this->levels[$teamId][$uid] ?? 0;
    }

    public function isEffectiveMember(string $uid, string $teamId): bool {
        return $this->memberLevel($uid, $teamId) > 0 || in_array($uid, $this->indirect[$teamId] ?? [], true);
    }

    public function isInGroup(string $uid, string $gid): bool {
        return in_array($gid, $this->groups[$uid] ?? [], true);
    }

    public function isNextcloudAdmin(string $uid): bool {
        return in_array($uid, $this->admins, true);
    }

    protected function isServiceAgent(string $uid, string $serviceTeamId): bool {
        return in_array($uid, $this->serviceAgents[$serviceTeamId] ?? [], true);
    }

    protected function serviceAgentsOf(string $serviceTeamId): array {
        return $this->serviceAgents[$serviceTeamId] ?? [];
    }

    public function groupsOf(string $uid): array {
        return $this->groups[$uid] ?? [];
    }

    public function displayName(string $uid): string {
        return $uid === '' ? '' : ucfirst($uid);
    }

    public function teamName(string $teamId): string {
        return $teamId === '' ? '' : 'Team ' . $teamId;
    }

    public function teamsOf(string $uid): array {
        $out = [];
        foreach ($this->levels as $teamId => $members) {
            if (isset($members[$uid])) {
                $out[$teamId] = $members[$uid];
            }
        }
        foreach ($this->indirect as $teamId => $uids) {
            if (in_array($uid, $uids, true) && !isset($out[$teamId])) {
                $out[$teamId] = 0;
            }
        }
        return $out;
    }
}
