<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Workflow;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\TeamRegistryService;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Answers "does this user hold this actor, now?" (WorkflowHub phase 1).
 *
 * A workflow actor is a selector (`WorkflowActor`); this class resolves it
 * against the live state of the instance — Circles levels for the team
 * roles, `IGroupManager` for groups, `ServiceTeamService` for a service
 * team's agents (v4.10.20) — at the moment of the question. It is
 * the one place a role is turned into people, so the engine's permission
 * checks, the definitions' `canStart()` and the participant listing all
 * agree on who a *team owner* or a *moderator* is.
 *
 * Levels are Circles': owner 9, admin 8, moderator 4, member 1. The
 * moderator actor is "at least 4", as `MemberService::requireModeratorLevel`
 * defines it; the team actor is effective membership, direct or through a
 * group, as `requireMemberLevel` defines it.
 *
 * Answers per uid are memoised for the request: a listing asks the same
 * question once per team, not once per instance.
 */
class WorkflowActorResolver {

    /** @var array<string, int> "uid|teamId" → direct level */
    private array $levelMemo = [];
    /** @var array<string, bool> "uid|teamId" → effective member */
    private array $memberMemo = [];
    /** @var array<string, bool> */
    private array $adminMemo = [];
    /** @var array<string, string> */
    private array $teamNameMemo = [];
    /** @var array<string, string> */
    private array $nameMemo = [];

    public function __construct(
        private IDBConnection       $db,
        private IGroupManager       $groupManager,
        private IUserManager        $userManager,
        private MemberService       $memberService,
        private TeamRegistryService $teamRegistry,
        // v4.10.20 — the service-agent actor. Answers eligibility only; it
        // knows nothing about workflows, which is what keeps this one-way.
        private ServiceTeamService  $serviceTeams,
        private LoggerInterface     $logger,
    ) {
    }

    /** Direct Circles level of the user on the team; 0 when not a direct member. */
    public function memberLevel(string $uid, string $teamId): int {
        $k = $uid . '|' . $teamId;
        if (!isset($this->levelMemo[$k])) {
            try {
                $this->levelMemo[$k] = $this->memberService->getMemberLevelFromDb($this->db, $teamId, $uid);
            } catch (\Throwable $e) {
                $this->warn('member level lookup failed', $e);
                $this->levelMemo[$k] = 0;
            }
        }
        return $this->levelMemo[$k];
    }

    /** Direct member, or reachable through a group or another team. */
    public function isEffectiveMember(string $uid, string $teamId): bool {
        $k = $uid . '|' . $teamId;
        if (!isset($this->memberMemo[$k])) {
            try {
                $this->memberMemo[$k] = $this->memberService->isEffectiveMember($teamId, $uid, $this->db);
            } catch (\Throwable $e) {
                $this->warn('effective membership lookup failed', $e);
                $this->memberMemo[$k] = false;
            }
        }
        return $this->memberMemo[$k];
    }

    public function isInGroup(string $uid, string $gid): bool {
        try {
            return $this->groupManager->isInGroup($uid, $gid);
        } catch (\Throwable $e) {
            $this->warn('group membership lookup failed', $e);
            return false;
        }
    }

    /** Fails closed: a lookup error is "not an administrator". */
    public function isNextcloudAdmin(string $uid): bool {
        if (!isset($this->adminMemo[$uid])) {
            try {
                $this->adminMemo[$uid] = $this->groupManager->isAdmin($uid);
            } catch (\Throwable $e) {
                $this->warn('admin lookup failed', $e);
                $this->adminMemo[$uid] = false;
            }
        }
        return $this->adminMemo[$uid];
    }

    /**
     * Is this person an eligible agent of a service team (v4.10.20)?
     *
     * Its own method for the same reason `isInGroup()` is: this class is
     * the one place a role becomes people, and each lookup is replaceable
     * on its own so the *rule* in `holds()` stays the shipped one under
     * test (`FakeActorResolver`).
     */
    protected function isServiceAgent(string $uid, string $serviceTeamId): bool {
        return $this->serviceTeams->isEligibleAgent($uid, $serviceTeamId);
    }

    /**
     * Every eligible agent of a service team. Exact, unlike the
     * team-relative actors: a service team's roster is TeamHub's own table.
     *
     * @return string[] uids
     */
    protected function serviceAgentsOf(string $serviceTeamId): array {
        return $this->serviceTeams->eligibleAgents($serviceTeamId);
    }

    /**
     * Does the user hold the actor on this team right now? The whole
     * permission rule for acting on a step.
     */
    public function holds(string $uid, WorkflowActor $actor, string $teamId): bool {
        switch ($actor->type) {
            case WorkflowActor::TYPE_USER:
                return $actor->id === $uid;
            case WorkflowActor::TYPE_GROUP:
                return $this->isInGroup($uid, $actor->id);
            case WorkflowActor::TYPE_TEAM_OWNER:
                return $teamId !== '' && $this->memberLevel($uid, $teamId) >= 9;
            case WorkflowActor::TYPE_TEAM_MODERATOR:
                return $teamId !== '' && $this->memberLevel($uid, $teamId) >= 4;
            case WorkflowActor::TYPE_TEAM:
                return $teamId !== '' && $this->isEffectiveMember($uid, $teamId);
            case WorkflowActor::TYPE_SERVICE_AGENT:
                // The actor's id is the *service* team, not the instance's
                // team — a request belongs to the team that asked and is
                // handled by the team that serves. `$teamId` is deliberately
                // unused here.
                return $actor->id !== '' && $this->isServiceAgent($uid, $actor->id);
        }
        return false;
    }

    /**
     * Does this person still take part in a workflow, given its participant
     * rows? (v4.10.21)
     *
     * Participation is the authorisation model of the whole WorkflowHub, so
     * it is answered **here**, once. `WorkflowEngine` asks it about a live
     * workflow and `WorkflowArchiveService` asks it about an archived one;
     * before phase 6 the engine answered it privately, and a second copy of
     * a security rule in the archive would have been a second copy that
     * could drift.
     *
     * Rows marked removed do not count, and every remaining row is resolved
     * against the **live** roles: somebody who was a participant through a
     * team role or a group and no longer holds it is no longer a
     * participant, whatever the row still says. That is what makes a
     * membership that ended safe without a clean-up pass.
     *
     * A row whose actor type is not one this version understands is skipped
     * rather than fatal — an unknown selector must never grant access, and
     * must never break the read either.
     *
     * @param \OCA\TeamHub\Db\WorkflowParticipant[] $participants
     */
    public function isAmongParticipants(string $uid, array $participants, string $teamId): bool {
        if ($uid === '') {
            return false;
        }
        foreach ($participants as $p) {
            if ($p->getRemovedAt() !== null) {
                continue;
            }
            try {
                $actor = WorkflowActor::of($p->getActorType(), $p->getActorId());
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($this->holds($uid, $actor, $teamId)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The users who hold an actor right now — the recipients of a
     * notification about its step (v4.10.14).
     *
     * A user actor is that user; a group actor is the group's members; a
     * team-relative actor is the team's *direct* members at the level
     * (owner 9, moderator ≥ 4, member ≥ 1). A member reachable only through
     * a group holds the `team` actor (`holds()`) but is not on this list —
     * Circles does not enumerate indirect members cheaply, and a missed
     * notification is the lesser wrong next to a query per group per team.
     *
     * @return string[] uids, deduplicated
     */
    public function holdersOf(WorkflowActor $actor, string $teamId): array {
        switch ($actor->type) {
            case WorkflowActor::TYPE_USER:
                return $this->userManager->get($actor->id) !== null ? [$actor->id] : [];
            case WorkflowActor::TYPE_GROUP:
                try {
                    $group = $this->groupManager->get($actor->id);
                    if ($group === null) {
                        return [];
                    }
                    $out = [];
                    foreach ($group->getUsers() as $user) {
                        $out[$user->getUID()] = true;
                    }
                    return array_keys($out);
                } catch (\Throwable $e) {
                    $this->warn('group member lookup failed', $e);
                    return [];
                }
            case WorkflowActor::TYPE_TEAM_OWNER:
                return $this->teamMembersAtLeast($teamId, 9);
            case WorkflowActor::TYPE_TEAM_MODERATOR:
                return $this->teamMembersAtLeast($teamId, 4);
            case WorkflowActor::TYPE_TEAM:
                return $this->teamMembersAtLeast($teamId, 1);
            case WorkflowActor::TYPE_SERVICE_AGENT:
                // The owner, the named agents and the source group's members
                // — everybody who could pick the request up. Unlike the
                // team-relative actors this list is exact, because a service
                // team's roster is TeamHub's own table.
                return $this->serviceAgentsOf($actor->id);
        }
        return [];
    }

    /**
     * Direct user members of a team at or above a Circles level.
     *
     * @return string[] uids
     */
    public function teamMembersAtLeast(string $teamId, int $level): array {
        if ($teamId === '') {
            return [];
        }
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('user_id')
               ->from('circles_member')
               ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
               ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('Member')))
               ->andWhere($qb->expr()->gte('level', $qb->createNamedParameter($level, IQueryBuilder::PARAM_INT)));
            $out = [];
            $res = $qb->executeQuery();
            while ($row = $res->fetch()) {
                $uid = (string)$row['user_id'];
                if ($uid !== '') {
                    $out[$uid] = true;
                }
            }
            $res->closeCursor();
            return array_keys($out);
        } catch (\Throwable $e) {
            $this->warn('team member lookup failed', $e);
            return [];
        }
    }

    /**
     * The team's display name, for a view (v4.10.15). Read from the circle
     * row directly: `TeamService::getTeam()` gates on membership, and a
     * group actor's holders are usually not members of the requesting team
     * (HANDOFF, "wrong reader for estate-wide work"). Empty when unknown.
     */
    public function teamName(string $teamId): string {
        if ($teamId === '') {
            return '';
        }
        if (!isset($this->teamNameMemo[$teamId])) {
            try {
                $qb  = $this->db->getQueryBuilder();
                $res = $qb->select('name')
                    ->from('circles_circle')
                    ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
                    ->setMaxResults(1)
                    ->executeQuery();
                $row = $res->fetch();
                $res->closeCursor();
                $this->teamNameMemo[$teamId] = $row ? (string)$row['name'] : '';
            } catch (\Throwable $e) {
                $this->warn('team name lookup failed', $e);
                $this->teamNameMemo[$teamId] = '';
            }
        }
        return $this->teamNameMemo[$teamId];
    }

    /**
     * A user's display name for a view (v4.10.15); the uid when the account
     * is gone. Memoised per request.
     */
    public function displayName(string $uid): string {
        if ($uid === '') {
            return '';
        }
        if (!isset($this->nameMemo[$uid])) {
            try {
                $this->nameMemo[$uid] = $this->userManager->get($uid)?->getDisplayName() ?? $uid;
            } catch (\Throwable $e) {
                $this->warn('display name lookup failed', $e);
                $this->nameMemo[$uid] = $uid;
            }
        }
        return $this->nameMemo[$uid];
    }

    /** @return string[] group ids */
    public function groupsOf(string $uid): array {
        $user = $this->userManager->get($uid);
        if ($user === null) {
            return [];
        }
        try {
            return array_values(array_map('strval', $this->groupManager->getUserGroupIds($user)));
        } catch (\Throwable $e) {
            $this->warn('group list lookup failed', $e);
            return [];
        }
    }

    /**
     * Every TeamHub team the user is an effective member of, with the
     * user's direct level (0 for a membership that only comes through a
     * group or another team). The candidate set for a participant listing:
     * a team-relative actor can only be held on one of these.
     *
     * Same join as `TeamService::getUserTeams()`, without the session
     * dependency — this must answer for any uid, not only the caller.
     *
     * @return array<string, int> teamId → level
     */
    public function teamsOf(string $uid): array {
        try {
            $singleId = $this->memberService->resolveUserSingleId($uid, $this->db);

            $qb = $this->db->getQueryBuilder();
            $qb->select('c.unique_id', 'm.level')
               ->from('circles_circle', 'c')
               ->leftJoin('c', 'circles_member', 'm', $qb->expr()->andX(
                   $qb->expr()->eq('m.circle_id', 'c.unique_id'),
                   $qb->expr()->eq('m.user_id',   $qb->createNamedParameter($uid)),
                   $qb->expr()->eq('m.user_type', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)),
                   $qb->expr()->eq('m.status',    $qb->createNamedParameter('Member')),
               ));
            if ($singleId) {
                $qb->addSelect('ms.single_id AS ms_single_id')
                   ->leftJoin('c', 'circles_membership', 'ms', $qb->expr()->andX(
                       $qb->expr()->eq('ms.circle_id', 'c.unique_id'),
                       $qb->expr()->eq('ms.single_id', $qb->createNamedParameter($singleId)),
                   ));
                $qb->where($qb->expr()->orX(
                    $qb->expr()->isNotNull('m.user_id'),
                    $qb->expr()->isNotNull('ms.single_id'),
                ));
            } else {
                $qb->where($qb->expr()->isNotNull('m.user_id'));
            }
            $this->teamRegistry->restrictToTeamHubTeams($qb, 'c');

            $out = [];
            $res = $qb->executeQuery();
            while ($row = $res->fetch()) {
                $teamId = (string)$row['unique_id'];
                $level  = (int)($row['level'] ?? 0);
                $out[$teamId] = max($out[$teamId] ?? 0, $level);
            }
            $res->closeCursor();
            return $out;
        } catch (\Throwable $e) {
            $this->warn('team list lookup failed', $e);
            return [];
        }
    }

    private function warn(string $what, \Throwable $e): void {
        $this->logger->warning('[TeamHub][Workflow] actor resolver: ' . $what, [
            'error' => $e->getMessage(), 'app' => Application::APP_ID,
        ]);
    }
}
