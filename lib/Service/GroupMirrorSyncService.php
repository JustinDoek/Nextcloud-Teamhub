<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\IDBConnection;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Keep every Nextcloud group attached to a TeamHub team reaching the team:
 * Circles' copy of the group in step with the group, and the team holding
 * the copy Circles actually maintains (v4.10.47).
 *
 * How a group is attached
 * ───────────────────────
 * A team does not hold a group. Circles keeps a hidden circle per group
 * (`circles_circle.name = 'group:{gid}'`, one `circles_member` row per user),
 * and the team holds *that circle* as a member (`user_type` 16, `single_id` =
 * the copy's id), fixed at the moment the group was attached. Everything
 * downstream — `circles_membership`, the team list in TeamHub, Deck, the team
 * folder, the calendar, Talk through our own reconcile — reads the copy the
 * team holds, never the group.
 *
 * Two ways that goes wrong:
 *
 *  1. **The team holds a copy Circles no longer maintains** — the one that
 *     happened. Circles finds a group's copy by name, config, source *and
 *     owner* — the owner being its own app identity, which is matched on the
 *     instance name among other things. When that identity is recreated,
 *     Circles makes a new copy of every group on the next sync and maintains
 *     only that one; teams keep the old one for ever. On the test instance the
 *     identity was recreated on 2026-08-07 and 2026-09-25, and
 *     *Marketing_profile* held the 08-07 copy: Charles West, added to
 *     *Marketing* on 2026-09-26, landed in the current copy and never reached
 *     the team — and **leavers would have kept their access**.
 *  2. **A group change never reaches the copy** — a safety net, not observed
 *     here. Circles' `GroupMemberAdded` listener queues a `SingleMemberAdd`
 *     federated event that a web request runs asynchronously, in a second
 *     request; Circles' listener swallows every exception, and a stuck event
 *     is a known failure (HANDOFF §0000). Some user backends change groups
 *     without dispatching events at all. Circles' only repair is the daily
 *     full sync in its maintenance job.
 *
 * What this does, per group
 * ─────────────────────────
 * First a diff against the copy Circles maintains now (`getGroupCircle()`):
 * users in the group but not in the copy are added by Circles' own per-group
 * sync (`SyncService::syncNextcloudGroup()`, what `occ circles:sync --groups`
 * runs); users in the copy but no longer in the group are removed one by one
 * (`SyncService::groupMemberRemoved()`, what Circles' `GroupMemberRemoved`
 * listener runs). From cron Circles executes those in-process (`OC::$CLI`),
 * so they cannot be lost the way the web request's async event was.
 *
 * Then every team holding any *other* copy of that group is re-pointed to the
 * current one — MemberService::relinkGroup(), attach-then-detach so nobody
 * drops out in between (Justin, 2026-09-26: automatic, audited). The diff runs
 * first so the copy a team moves onto is already complete.
 *
 * Circles then rebuilds `circles_membership` and dispatches the
 * `Memberships*` events, and `CircleMembershipChangedListener` brings Talk
 * along. `SyncService` and `FederatedUserService` are Circles-internal, not
 * OCP — the same kind of dependency as `MembershipService::onUpdate()` in
 * ResourceMembershipService (Justin, 2026-09-26). Both are resolved lazily and
 * guarded, so a Circles without them degrades to "no sync".
 *
 * One refusal on purpose: when the Nextcloud group reports **no users** while
 * the copy still has some, nothing is removed. An LDAP or OIDC backend that
 * is unreachable answers an empty list, and taking that at its word would
 * strip every group-based member from every team at once. An intentionally
 * emptied group still reaches the copy through Circles' synchronous removal
 * path; this only stops the backstop from finishing the job.
 */
class GroupMirrorSyncService {

    private const COPY_PREFIX = 'group:';

    public function __construct(
        private IDBConnection       $db,
        private IGroupManager       $groupManager,
        private TeamRegistryService $teamRegistry,
        private ContainerInterface  $container,
        private LoggerInterface     $logger,
    ) {
    }

    /**
     * Is this Nextcloud group attached to at least one TeamHub team? The
     * listener's cheap exit — most groups on an instance are not.
     */
    public function isTeamGroup(string $groupId): bool {
        if ($groupId === '') {
            return false;
        }
        foreach ($this->readTeamGroupBindings() as $binding) {
            if ($binding['groupId'] === $groupId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Bring one group's copy in line with the group, then move every team
     * still holding an older copy onto the current one.
     *
     * @param list<array{teamId: string, groupId: string, singleId: string}>|null $bindings
     *        the team bindings already read by syncAll(), or null to read them
     * @return array{added: int, removed: int, relinked: int}
     */
    public function syncGroup(string $groupId, ?array $bindings = null): array {
        $result = ['added' => 0, 'removed' => 0, 'relinked' => 0];
        if ($groupId === '') {
            return $result;
        }

        $groupUsers = $this->readGroupUserIds($groupId);
        if ($groupUsers === null) {
            // The group is gone. Circles' GroupDeleted listener removes its
            // copy synchronously; nothing for us to diff against or move to.
            return $result;
        }
        $currentId = $this->circlesCurrentCopyId($groupId);
        if ($currentId === null) {
            return $result;
        }

        // 1. The current copy matches the group.
        $copy    = $this->readCopyMemberIds($currentId);
        $missing = array_values(array_diff($groupUsers, $copy));
        $extra   = array_values(array_diff($copy, $groupUsers));

        if ($missing !== []) {
            // One call adds every missing user; Circles skips the ones present.
            if ($this->circlesAddMissing($groupId)) {
                $result['added'] = count($missing);
            }
        }

        if ($extra !== []) {
            if ($groupUsers === []) {
                $this->logger->warning('[TeamHub][GroupMirrorSync] group reports no users; removals skipped', [
                    'groupId'     => $groupId,
                    'copyMembers' => count($copy),
                    'app'         => Application::APP_ID,
                ]);
            } else {
                foreach ($extra as $userId) {
                    if ($this->circlesRemove($groupId, $userId)) {
                        $result['removed']++;
                    }
                }
            }
        }

        // 2. Every team holds the current copy.
        foreach ($bindings ?? $this->readTeamGroupBindings() as $binding) {
            if ($binding['groupId'] !== $groupId || $binding['singleId'] === $currentId) {
                continue;
            }
            if ($this->relink($binding['teamId'], $groupId, $binding['singleId'])) {
                $result['relinked']++;
            }
        }

        if ($result['added'] !== 0 || $result['removed'] !== 0 || $result['relinked'] !== 0) {
            $this->logger->info('[TeamHub][GroupMirrorSync] group repaired', [
                'groupId'  => $groupId,
                'added'    => $result['added'],
                'removed'  => $result['removed'],
                'relinked' => $result['relinked'],
                'app'      => Application::APP_ID,
            ]);
        }

        return $result;
    }

    /**
     * The hourly backstop: every group attached to a TeamHub team.
     *
     * @return array{groups: int, added: int, removed: int, relinked: int}
     */
    public function syncAll(): array {
        $totals   = ['groups' => 0, 'added' => 0, 'removed' => 0, 'relinked' => 0];
        $bindings = $this->readTeamGroupBindings();
        $groupIds = array_values(array_unique(array_column($bindings, 'groupId')));

        foreach ($groupIds as $groupId) {
            $totals['groups']++;
            try {
                $r = $this->syncGroup($groupId, $bindings);
                $totals['added']    += $r['added'];
                $totals['removed']  += $r['removed'];
                $totals['relinked'] += $r['relinked'];
            } catch (\Throwable $e) {
                // One broken group must not stop the sweep.
                $this->logger->warning('[TeamHub][GroupMirrorSync] group sync failed', [
                    'groupId' => $groupId,
                    'error'   => $e->getMessage(),
                    'app'     => Application::APP_ID,
                ]);
            }
        }
        return $totals;
    }

    // ------------------------------------------------------------------
    // Reads — protected so the unit test can stand in for the database
    // ------------------------------------------------------------------

    /**
     * Every (team, group, copy) a TeamHub team holds: confirmed rows whose
     * member is a group copy. The group id is read from the copy's name — a
     * group row's `user_id` is only a label (see MemberService::getTeamMembers()).
     *
     * @return list<array{teamId: string, groupId: string, singleId: string}>
     */
    protected function readTeamGroupBindings(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('m.circle_id', 'm.single_id', 'gc.name')
            ->from('circles_member', 'm')
            ->innerJoin('m', 'circles_circle', 'gc', $qb->expr()->eq('gc.unique_id', 'm.single_id'))
            ->innerJoin('m', 'circles_circle', 'c', $qb->expr()->eq('c.unique_id', 'm.circle_id'))
            ->where($qb->expr()->eq('m.status', $qb->createNamedParameter('Member')))
            ->andWhere($qb->expr()->like('gc.name', $qb->createNamedParameter(self::COPY_PREFIX . '%')));
        $this->teamRegistry->restrictToTeamHubTeams($qb, 'c');

        $bindings = [];
        $res = $qb->executeQuery();
        while ($row = $res->fetch()) {
            $groupId = substr((string)$row['name'], strlen(self::COPY_PREFIX));
            if ($groupId === '') {
                continue;
            }
            $bindings[] = [
                'teamId'   => (string)$row['circle_id'],
                'groupId'  => $groupId,
                'singleId' => (string)$row['single_id'],
            ];
        }
        $res->closeCursor();
        return $bindings;
    }

    /**
     * Local users in one copy of a group. Every user row whatever its status:
     * that is the set Circles' syncNextcloudGroup() compares against, so
     * "missing" means the same thing on both sides.
     *
     * @return list<string>
     */
    protected function readCopyMemberIds(string $copyId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id')
            ->from('circles_member')
            ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($copyId)))
            ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        $ids = [];
        $res = $qb->executeQuery();
        while ($row = $res->fetch()) {
            $uid = (string)($row['user_id'] ?? '');
            if ($uid !== '') {
                $ids[] = $uid;
            }
        }
        $res->closeCursor();
        return array_values(array_unique($ids));
    }

    /**
     * The group's users, or null when the group does not exist.
     *
     * @return list<string>|null
     */
    protected function readGroupUserIds(string $groupId): ?array {
        $group = $this->groupManager->get($groupId);
        if ($group === null) {
            return null;
        }
        $ids = [];
        foreach ($group->getUsers() as $user) {
            $ids[] = $user->getUID();
        }
        return $ids;
    }

    // ------------------------------------------------------------------
    // Circles and MemberService — every write goes through one of these
    // ------------------------------------------------------------------

    /**
     * The id of the copy Circles maintains for this group now — the one its
     * own listeners and syncs write to. Circles creates it when none matches.
     */
    protected function circlesCurrentCopyId(string $groupId): ?string {
        $class = 'OCA\Circles\Service\FederatedUserService';
        if (!class_exists($class)) {
            return null;
        }
        try {
            $id = (string)$this->container->get($class)->getGroupCircle($groupId)->getSingleId();
            return $id === '' ? null : $id;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][GroupMirrorSync] could not resolve the group copy', [
                'groupId' => $groupId,
                'error'   => $e->getMessage(),
                'app'     => Application::APP_ID,
            ]);
            return null;
        }
    }

    protected function circlesAddMissing(string $groupId): bool {
        $sync = $this->circlesSyncService();
        if ($sync === null) {
            return false;
        }
        try {
            $sync->syncNextcloudGroup($groupId);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][GroupMirrorSync] Circles group sync failed', [
                'groupId' => $groupId,
                'error'   => $e->getMessage(),
                'app'     => Application::APP_ID,
            ]);
            return false;
        }
    }

    protected function circlesRemove(string $groupId, string $userId): bool {
        $sync = $this->circlesSyncService();
        if ($sync === null) {
            return false;
        }
        try {
            $sync->groupMemberRemoved($groupId, $userId);
            return true;
        } catch (\Throwable $e) {
            // A deleted account lands here (Circles cannot resolve it); its
            // own UserDeleted listener clears those rows.
            $this->logger->warning('[TeamHub][GroupMirrorSync] Circles member removal failed', [
                'groupId' => $groupId,
                'error'   => $e->getMessage(),
                'app'     => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * MemberService is resolved here rather than injected: it is heavy, and
     * the listener that builds this service runs on every group change on
     * the instance while almost never needing it.
     */
    protected function relink(string $teamId, string $groupId, string $staleSingleId): bool {
        return $this->container->get(MemberService::class)->relinkGroup($teamId, $groupId, $staleSingleId);
    }

    /** @return \OCA\Circles\Service\SyncService|null */
    private function circlesSyncService(): ?object {
        // String class name, no leading backslash — see Application's Circles
        // wiring for why.
        $class = 'OCA\Circles\Service\SyncService';
        if (!class_exists($class)) {
            return null;
        }
        try {
            return $this->container->get($class);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][GroupMirrorSync] Circles SyncService unavailable', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
            return null;
        }
    }
}
