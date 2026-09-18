<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\CirclesMemberType;
use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\OpenProject\OpenProjectClient;
use OCA\TeamHub\Service\OpenProject\OpenProjectProvisioningService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * TeamHub roles ↔ OpenProject roles (v4.9.6, Phase 2): the mapping, the
 * matching of Nextcloud accounts to OpenProject users, the preview the
 * wizard shows, and the drift report afterwards.
 *
 * ## Authority (decided for Phase 2, DESIGN §2.123)
 *
 * - **At provisioning, TeamHub initiates.** The creator's member list and
 *   the blueprint's mapping decide who gets which OpenProject role; the
 *   memberships are created as the creator, so OpenProject's own
 *   `manage_members` permission and role grantability apply — TeamHub
 *   cannot hand out a role OpenProject would refuse the creator.
 * - **Afterwards, OpenProject is authoritative for OpenProject roles.**
 *   TeamHub does not watch either side. A team admin can ask for a drift
 *   report (`drift()`) and run one explicit, one-way action: add to
 *   OpenProject the team members who are missing there
 *   (`ProvisioningService::syncMembership()`). Nothing is ever removed from
 *   OpenProject by TeamHub, and a role changed in OpenProject is reported,
 *   not reverted — the official app's `request()` has no PATCH, and a
 *   silent revert would be the wrong answer even if it had.
 * - **Removed users** are reported as `extra_in_openproject` when they left
 *   the team but stayed in the project, and as `missing_in_openproject` in
 *   the other direction. Both are a person's decision.
 * - **External members** (federated accounts, e-mail invitees) map to the
 *   `guest` role key, which ships as "no OpenProject access" and can be
 *   pointed at a limited role by an administrator.
 *
 * ## Matching
 *
 * A Nextcloud account is matched to an OpenProject user, in this order:
 *   1. the official app's `user_id` preference on that account — written
 *      when they connected, so it is exact;
 *   2. an OpenProject user whose login equals the Nextcloud uid, or whose
 *      e-mail equals the account's e-mail (`findUser()`).
 * A near miss is never a match. An unmatched user is reported and the
 * wizard requires a decision for each: keep them on the TeamHub team
 * without OpenProject access (`teamhub_only`), or leave them off (`omit`).
 * A group is matched by exact name against OpenProject's groups the caller
 * can see; unmatched groups get the same choice.
 *
 * ## Escalation
 *
 * The mapping is the administrator's (blueprint) and the wizard shows it
 * read-only. Even so, a creator cannot use it to grant more than they may:
 * every membership is created as them, and OpenProject refuses roles they
 * cannot assign. A refusal is recorded per member and the step reports it.
 */
class MembershipPlanService {

    public const DECISIONS = ['teamhub_only', 'omit'];

    public function __construct(
        private OpenProjectProvisioningService $op,
        private IConfig        $config,
        private IUserManager   $userManager,
        private IGroupManager  $groupManager,
        private IDBConnection  $db,
        private LoggerInterface $logger,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────
    // Roles
    // ─────────────────────────────────────────────────────────────────────

    /** The TeamHub role key of a wizard member entry. */
    public static function roleKey(int $level, string $type = 'user'): string {
        if ($type !== 'user' && $type !== 'group') {
            return 'guest';
        }
        return match (true) {
            $level >= 9 => 'owner',
            $level >= 8 => 'admin',
            $level >= 4 => 'moderator',
            default     => 'member',
        };
    }

    /**
     * The blueprint's mapping resolved against OpenProject's live roles:
     * per role key the role name, its id when it exists, or null for "no
     * access". A name that does not exist in this OpenProject is `missing`.
     *
     * @param list<array{id:int, name:string}> $liveRoles
     * @return array<string, array{name: ?string, id: ?int, missing: bool}>
     */
    public function resolveMapping(Blueprint $bp, array $liveRoles): array {
        $byName = [];
        foreach ($liveRoles as $role) {
            $byName[mb_strtolower(trim($role['name']))] = (int)$role['id'];
        }
        $out     = [];
        $mapping = $bp->roleMapping();
        foreach (Blueprint::ROLE_KEYS as $key) {
            $name = $mapping[$key] ?? null;
            if ($name === null || $name === '') {
                $out[$key] = ['name' => null, 'id' => null, 'missing' => false];
                continue;
            }
            $id = $byName[mb_strtolower(trim($name))] ?? null;
            $out[$key] = ['name' => $name, 'id' => $id, 'missing' => $id === null];
        }
        return $out;
    }

    /**
     * Refuse a mapping that names roles this OpenProject does not have for
     * a role key the members actually use.
     *
     * @param array<string, array{name: ?string, id: ?int, missing: bool}> $resolved
     * @param list<string> $usedKeys
     * @throws ValidationException
     */
    public function assertMappingUsable(array $resolved, array $usedKeys): void {
        $missing = [];
        foreach ($usedKeys as $key) {
            if (!empty($resolved[$key]['missing'])) {
                $missing[] = (string)$resolved[$key]['name'];
            }
        }
        if ($missing !== []) {
            throw new ValidationException(
                'The role mapping names OpenProject roles that do not exist here: ' . implode(', ', array_unique($missing)),
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Matching and the preview
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The plan for one member list: who is matched to what, with which
     * roles, and who needs a decision.
     *
     * `$members` are the wizard's entries: `{id, type?, level?, displayName?,
     * decision?}`. `$existing` are the project's current memberships (mode
     * B, or a retry), so a member already in the project is `exists`.
     *
     * @param list<array<string,mixed>> $members
     * @param array<string, array{name: ?string, id: ?int, missing: bool}> $resolvedMapping
     * @param list<array{id:int, principalId:?int, principalType:string, principalName:string, roles:list<array{id:int, name:string}>}> $existing
     * @return array{
     *   entries: list<array<string,mixed>>,
     *   unmatched: list<string>,
     *   needsDecision: list<string>,
     *   usedRoleKeys: list<string>
     * }
     */
    public function plan(string $callerUid, array $members, array $resolvedMapping, array $existing = []): array {
        $existingByPrincipal = [];
        foreach ($existing as $m) {
            if ($m['principalId'] !== null) {
                $existingByPrincipal[$m['principalType'] . ':' . $m['principalId']] = $m;
            }
        }

        $entries = [];
        $unmatched = [];
        $needsDecision = [];
        $used = [];

        foreach ($members as $member) {
            $id   = trim((string)($member['id'] ?? ''));
            $type = (string)($member['type'] ?? 'user');
            if ($id === '' || !in_array($type, ['user', 'group', 'federated', 'email'], true)) {
                continue;
            }
            $level    = (int)($member['level'] ?? 1);
            $roleKey  = self::roleKey($level, $type);
            $decision = (string)($member['decision'] ?? '');
            if ($decision !== '' && !in_array($decision, self::DECISIONS, true)) {
                throw new ValidationException('Unknown decision for ' . $id);
            }
            $mapped = $resolvedMapping[$roleKey] ?? ['name' => null, 'id' => null, 'missing' => false];
            $used[$roleKey] = true;

            $entry = [
                'id'              => $id,
                'type'            => $type,
                'displayName'     => (string)($member['displayName'] ?? $id),
                'level'           => $level,
                'teamRole'        => $roleKey,
                'openProjectRole' => $mapped['name'] !== null ? ['id' => $mapped['id'], 'name' => $mapped['name']] : null,
                'principal'       => null,
                'matchedBy'       => null,
                'status'          => 'add',
                'decision'        => $decision !== '' ? $decision : null,
                'reason'          => null,
            ];

            // No OpenProject access for this role — nothing to match.
            if ($mapped['name'] === null) {
                $entry['status'] = 'no_access';
                $entries[] = $entry;
                continue;
            }

            $match = null;
            try {
                $match = $type === 'group'
                    ? $this->matchGroup($callerUid, $id)
                    : ($type === 'user' ? $this->matchUser($callerUid, $id) : null);
            } catch (OpenProjectException $e) {
                // A failure to *ask* is not "no such user"; the step will ask
                // again. Reported so the preview says why.
                $entry['reason'] = $e->getErrorCode();
                $this->logger->info('[TeamHub][MembershipPlanService] Could not match a member', [
                    'code' => $e->getErrorCode(), 'app' => Application::APP_ID,
                ]);
            }

            if ($match === null) {
                $entry['status'] = 'unmatched';
                $unmatched[] = $id;
                if ($decision === '') {
                    $needsDecision[] = $id;
                }
                $entries[] = $entry;
                continue;
            }

            $entry['principal'] = ['id' => $match['id'], 'name' => $match['name'], 'type' => $match['type']];
            $entry['matchedBy'] = $match['matchedBy'];

            $key = $match['type'] . ':' . $match['id'];
            if (isset($existingByPrincipal[$key])) {
                $existingRoles = array_map(static fn (array $r): int => $r['id'], $existingByPrincipal[$key]['roles']);
                $entry['status']       = 'exists';
                $entry['membershipId'] = $existingByPrincipal[$key]['id'];
                $entry['roleDrift']    = $mapped['id'] !== null && !in_array($mapped['id'], $existingRoles, true);
            }
            $entries[] = $entry;
        }

        return [
            'entries'       => $entries,
            'unmatched'     => $unmatched,
            'needsDecision' => $needsDecision,
            'usedRoleKeys'  => array_keys($used),
        ];
    }

    /**
     * @return ?array{id:int, name:string, type:string, matchedBy:string}
     * @throws OpenProjectException
     */
    public function matchUser(string $callerUid, string $uid): ?array {
        // 1. Their own connection: exact and free.
        $connected = $this->config->getUserValue($uid, OpenProjectClient::INTEGRATION_APP_ID, 'user_id', '');
        if ($connected !== '' && ctype_digit($connected)) {
            $name = $this->config->getUserValue($uid, OpenProjectClient::INTEGRATION_APP_ID, 'user_name', '');
            return ['id' => (int)$connected, 'name' => $name !== '' ? $name : $uid, 'type' => 'user', 'matchedBy' => 'connected'];
        }
        // 2. Login / e-mail, as the caller.
        $user  = $this->userManager->get($uid);
        $email = $user?->getEMailAddress() ?? '';
        $hit   = $this->op->findUser($callerUid, $uid, $email);
        if ($hit === null) {
            return null;
        }
        $by = strcasecmp($hit['login'], $uid) === 0 ? 'login' : 'email';
        return ['id' => $hit['id'], 'name' => $hit['name'], 'type' => 'user', 'matchedBy' => $by];
    }

    /**
     * @return ?array{id:int, name:string, type:string, matchedBy:string}
     * @throws OpenProjectException
     */
    public function matchGroup(string $callerUid, string $gid): ?array {
        $group = $this->groupManager->get($gid);
        $name  = $group?->getDisplayName() ?? $gid;
        $hit   = $this->op->findGroup($callerUid, $name, $gid);
        if ($hit === null) {
            return null;
        }
        return ['id' => $hit['id'], 'name' => $hit['name'], 'type' => 'group', 'matchedBy' => 'group'];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Drift
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The team's effective local members and their levels: direct rows
     * carry their level, members reached through a group are level 1.
     *
     * @return array<string,int> uid → level
     */
    public function effectiveTeamMembers(string $teamId): array {
        $out = [];

        $eQb  = $this->db->getQueryBuilder();
        $eRes = $eQb->select('m.user_id', 'm.instance')
            ->from('circles_membership', 'ms')
            ->innerJoin('ms', 'circles_member', 'm', $eQb->expr()->andX(
                $eQb->expr()->eq('m.circle_id', 'ms.single_id'),
                $eQb->expr()->eq('m.user_type', $eQb->createNamedParameter(CirclesMemberType::TYPE_USER, IQueryBuilder::PARAM_INT)),
            ))
            ->where($eQb->expr()->eq('ms.circle_id', $eQb->createNamedParameter($teamId)))
            ->executeQuery();
        while ($row = $eRes->fetch()) {
            $uid = (string)($row['user_id'] ?? '');
            if ($uid !== '' && CirclesMemberType::isLocalInstance((string)($row['instance'] ?? ''))) {
                $out[$uid] = 1;
            }
        }
        $eRes->closeCursor();

        $dQb  = $this->db->getQueryBuilder();
        $dRes = $dQb->select('user_id', 'instance', 'level')
            ->from('circles_member')
            ->where($dQb->expr()->eq('circle_id', $dQb->createNamedParameter($teamId)))
            ->andWhere($dQb->expr()->eq('user_type', $dQb->createNamedParameter(CirclesMemberType::TYPE_USER, IQueryBuilder::PARAM_INT)))
            ->andWhere($dQb->expr()->eq('status', $dQb->createNamedParameter('Member')))
            ->executeQuery();
        while ($row = $dRes->fetch()) {
            $uid = (string)($row['user_id'] ?? '');
            if ($uid !== '' && CirclesMemberType::isLocalInstance((string)($row['instance'] ?? ''))) {
                $out[$uid] = max((int)($row['level'] ?? 1), 1);
            }
        }
        $dRes->closeCursor();

        return $out;
    }

    /**
     * Compare the team with the project, as the caller sees the project.
     *
     * @param array<string, array{name: ?string, id: ?int, missing: bool}> $resolvedMapping
     * @return array{
     *   missingInOpenProject: list<array<string,mixed>>,
     *   extraInOpenProject: list<array<string,mixed>>,
     *   roleDrift: list<array<string,mixed>>,
     *   unmatched: list<array<string,mixed>>,
     *   inSync: int, checkedAt: int
     * }
     * @throws OpenProjectException
     */
    public function drift(string $callerUid, string $teamId, int $projectId, array $resolvedMapping): array {
        $members     = $this->effectiveTeamMembers($teamId);
        $memberships = $this->op->listMemberships($callerUid, $projectId);

        $byPrincipal = [];
        foreach ($memberships as $m) {
            if ($m['principalType'] === 'user' && $m['principalId'] !== null) {
                $byPrincipal[$m['principalId']] = $m;
            }
        }

        $missing = $extra = $drift = $unmatched = [];
        $inSync  = 0;
        $seenPrincipals = [];

        foreach ($members as $uid => $level) {
            $roleKey = self::roleKey($level);
            $mapped  = $resolvedMapping[$roleKey] ?? ['name' => null, 'id' => null, 'missing' => false];
            $display = $this->userManager->get($uid)?->getDisplayName() ?? $uid;
            if ($mapped['name'] === null) {
                continue; // this role has no OpenProject access by design
            }
            $match = $this->matchUser($callerUid, $uid);
            if ($match === null) {
                $unmatched[] = ['id' => $uid, 'displayName' => $display, 'teamRole' => $roleKey];
                continue;
            }
            $seenPrincipals[$match['id']] = true;
            $current = $byPrincipal[$match['id']] ?? null;
            if ($current === null) {
                $missing[] = [
                    'id' => $uid, 'displayName' => $display, 'teamRole' => $roleKey,
                    'openProjectRole' => ['id' => $mapped['id'], 'name' => $mapped['name']],
                    'principal' => ['id' => $match['id'], 'name' => $match['name'], 'type' => 'user'],
                ];
                continue;
            }
            $currentRoleIds = array_map(static fn (array $r): int => $r['id'], $current['roles']);
            if ($mapped['id'] !== null && !in_array($mapped['id'], $currentRoleIds, true)) {
                $drift[] = [
                    'id' => $uid, 'displayName' => $display, 'teamRole' => $roleKey,
                    'expectedRole' => ['id' => $mapped['id'], 'name' => $mapped['name']],
                    'currentRoles' => $current['roles'],
                ];
                continue;
            }
            $inSync++;
        }

        foreach ($memberships as $m) {
            if ($m['principalType'] !== 'user' || $m['principalId'] === null) {
                continue;
            }
            if (!isset($seenPrincipals[$m['principalId']])) {
                $extra[] = [
                    'membershipId' => $m['id'],
                    'principal'    => ['id' => $m['principalId'], 'name' => $m['principalName'], 'type' => 'user'],
                    'roles'        => $m['roles'],
                ];
            }
        }

        return [
            'missingInOpenProject' => $missing,
            'extraInOpenProject'   => $extra,
            'roleDrift'            => $drift,
            'unmatched'            => $unmatched,
            'inSync'               => $inSync,
            'checkedAt'            => time(),
        ];
    }
}
