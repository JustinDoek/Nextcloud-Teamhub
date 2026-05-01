<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\AuditService;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * MemberService — everything related to circle membership.
 *
 * Extracted from TeamService in v2.25.0.
 * Responsibilities:
 *   - getTeamMembers()         list members via Circles API
 *   - updateMemberLevel()      promote/demote via direct DB write
 *   - requireAdminLevel()      permission guard (used by other methods)
 *   - getMemberLevelFromDb()   direct DB level lookup
 *   - memberToArray()          Circles member → API shape
 *   - inviteMembers()          add users/groups via Circles API
 *   - requestJoinTeam()        self-request + DB fallback
 *   - leaveTeam()              remove self from circle
 *   - removeMember()           admin removes another member
 *   - getPendingRequests()     list Requesting-status members
 *   - approveRequest()         approve a pending request
 *   - rejectRequest()          reject a pending request
 *   - searchUsers()            user/group/email/federated picker
 *   - getAllowedInviteTypes()   read admin setting
 *   - canCurrentUserCreateTeam() check createTeamGroup restriction
 */
class MemberService {

    public function __construct(
        private ResourceService $resourceService,
        private IUserSession $userSession,
        private IAppManager $appManager,
        private IUserManager $userManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private AuditService $auditService,
    ) {
    }

    // -------------------------------------------------------------------------
    // Circles manager helper (mirrors TeamService pattern)
    // -------------------------------------------------------------------------

    /** @var \OCA\Circles\CirclesManager|null */
    private $circlesManager = null;

    private function getCirclesManager(): \OCA\Circles\CirclesManager {
        if ($this->circlesManager === null) {
            if (!$this->appManager->isInstalled('circles')) {
                throw new \Exception('Nextcloud Teams (Circles) app is not enabled. Please enable it first.');
            }
            try {
                $this->circlesManager = $this->container->get(\OCA\Circles\CirclesManager::class);
            } catch (\Exception $e) {
                throw new \Exception('Failed to load Circles Manager: ' . $e->getMessage());
            }
        }
        return $this->circlesManager;
    }

    // -------------------------------------------------------------------------
    // Member listing and level management
    // -------------------------------------------------------------------------

    /**
     * Get team members for the MembersWidget and store.
     *
     * Returns a wrapped shape:
     *   {
     *     members:        array  — up to 30 direct users sorted by last_login DESC,
     *     effective_count: int   — total users with access (from circles_membership),
     *     has_more:        bool  — true when effective_count > count(members)
     *   }
     *
     * Only direct user rows (user_type=1, status=Member) are included in the
     * members list. Users added via groups or other teams are counted in
     * effective_count but do NOT appear in the list (use getMembersForManage()
     * for the full structured breakdown).
     *
     * @throws \Exception if user is not authenticated
     */
    public function getTeamMembers(string $teamId): array {

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        // Membership gate — only team members can enumerate the member list.
        // requireMemberLevel accepts both direct rows and indirect access
        // (via group or sub-team), which matches the intent for read-only
        // widgets shown on the team home page.
        $this->requireMemberLevel($teamId);

        $db = $this->container->get(\OCP\IDBConnection::class);

        // ── Effective count from circles_membership (source of truth) ─────────
        $effectiveCount = $this->getEffectiveMemberCount($teamId, $db);

        // ── Direct user members sorted by role level, limit 30 ────────────────
        // user_type=1 → local NC user
        // Sorted by level DESC puts owners/admins first, then members alphabetically.
        $qb  = $db->getQueryBuilder();
        $res = $qb->select('m.user_id', 'm.level', 'm.status', 'u.displayname')
            ->from('circles_member', 'm')
            ->leftJoin('m', 'users', 'u', 'm.user_id = u.uid')
            ->where($qb->expr()->eq('m.circle_id',  $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('m.status',   $qb->createNamedParameter('Member')))
            ->andWhere($qb->expr()->eq('m.user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->orderBy('m.level', 'DESC')
            ->addOrderBy('u.displayname', 'ASC')
            ->executeQuery();

        // First pass: collect rows (we need last-login sort below, applied in PHP
        // rather than SQL because lastLogin is stored in oc_preferences, not oc_users)
        $allDirect = [];
        while ($row = $res->fetch()) {
            $userId      = (string)$row['user_id'];
            $displayName = !empty($row['displayname']) ? $row['displayname'] : $userId;
            $level       = (int)$row['level'];

            $role = match (true) {
                $level >= 9 => 'Owner',
                $level >= 8 => 'Admin',
                $level >= 4 => 'Moderator',
                default     => 'Member',
            };

            $allDirect[] = [
                'userId'      => $userId,
                'displayName' => $displayName,
                'role'        => $role,
                'level'       => $level,
                'status'      => 'Member',
                'lastLogin'   => 0, // filled in below
            ];
        }
        $res->closeCursor();

        // Second pass: look up last-login timestamps from oc_preferences in a single query.
        // NC stores the last-login value under app='login', configkey='lastLogin' (ms since epoch).
        // Table name is 'preferences' on <= NC29, 'user_preferences' on NC30+.
        if (!empty($allDirect)) {
            $uids = array_column($allDirect, 'userId');
            $loginByUid = [];
            foreach (['user_preferences', 'preferences'] as $prefTable) {
                try {
                    $pQb = $db->getQueryBuilder();
                    // Column names differ: 'appid'/'app' and 'configkey'/'key' between versions.
                    // Try the NC30+ shape first; on failure fall through to the older one.
                    $pRes = $pQb->select('userid', 'configvalue')
                        ->from($prefTable)
                        ->where($pQb->expr()->eq('appid',     $pQb->createNamedParameter('login')))
                        ->andWhere($pQb->expr()->eq('configkey', $pQb->createNamedParameter('lastLogin')))
                        ->andWhere($pQb->expr()->in('userid', $pQb->createNamedParameter($uids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
                        ->executeQuery();
                    while ($pRow = $pRes->fetch()) {
                        $loginByUid[(string)$pRow['userid']] = (int)$pRow['configvalue'];
                    }
                    $pRes->closeCursor();
                    break;
                } catch (\Throwable $e) {
                    // Try the next table name
                    continue;
                }
            }
            foreach ($allDirect as &$d) {
                $d['lastLogin'] = $loginByUid[$d['userId']] ?? 0;
            }
            unset($d);
        }

        // Sort: owner first, then admins, then moderators, then by lastLogin DESC
        // (most-recently-active users surface in the widget's top-16 avatar stack)
        usort($allDirect, function ($a, $b) {
            if ($a['level'] !== $b['level']) {
                return $b['level'] <=> $a['level'];
            }
            return $b['lastLogin'] <=> $a['lastLogin'];
        });

        // Top 16 go to the widget avatar stack.
        // Strip the internal lastLogin key so it is not exposed in the API response —
        // only used above for sort order.
        $members = array_map(
            fn ($m) => [
                'userId'      => $m['userId'],
                'displayName' => $m['displayName'],
                'role'        => $m['role'],
                'level'       => $m['level'],
                'status'      => $m['status'],
            ],
            array_slice($allDirect, 0, 16)
        );

        $this->logger->debug('[MemberService] getTeamMembers: loaded', [
            'teamId'          => $teamId,
            'direct_count'    => count($members),
            'effective_count' => $effectiveCount,
            'app'             => Application::APP_ID,
        ]);

        // Fetch group/circle member entries so the widget can show them in a
        // flat list with a "Group" or "Team" pill on each row.
        //
        // CRITICAL: for user_type=2 (group) and user_type=16 (circle), the row's
        //   user_id     = human-readable label (group GID or circle name)
        //   single_id   = the unique_id of the corresponding circles_circle row
        // So we must JOIN on m.single_id = cc.unique_id, NOT user_id.
        //
        // circles_circle.source values for NC32:
        //   1  = user circle (personal)
        //   2  = group circle (wraps an NC group)
        //   16 = user-created team (TeamHub / NC Teams app)
        $memberships = [];
        $gcQb  = $db->getQueryBuilder();
        $gcRes = $gcQb->select('m.user_id', 'm.single_id', 'm.user_type',
                               'cc.name AS circle_name', 'cc.source AS circle_source')
            ->from('circles_member', 'm')
            ->leftJoin('m', 'circles_circle', 'cc', 'm.single_id = cc.unique_id')
            ->where($gcQb->expr()->eq('m.circle_id', $gcQb->createNamedParameter($teamId)))
            ->andWhere($gcQb->expr()->eq('m.status',  $gcQb->createNamedParameter('Member')))
            ->andWhere($gcQb->expr()->in(
                'm.user_type',
                $gcQb->createNamedParameter([2, 16], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)
            ))
            ->executeQuery();

        while ($gcRow = $gcRes->fetch()) {
            $subLabel   = (string)$gcRow['user_id'];
            $subSingle  = (string)$gcRow['single_id'];
            $subType    = (int)$gcRow['user_type'];
            $subSource  = (int)($gcRow['circle_source'] ?? 0);
            $circleName = (string)($gcRow['circle_name'] ?? '');

            $subCount = $this->getEffectiveMemberCount($subSingle, $db);

            // Classify: NC group OR group-backed circle → 'group'; else → 'circle'
            $isGroup = ($subType === 2) || ($subSource === 2);

            // Resolve display name
            $displayName = $subLabel;
            if ($circleName !== '') {
                if (str_starts_with($circleName, 'group:')) {
                    $displayName = substr($circleName, 6);
                } elseif (!str_starts_with($circleName, 'user:')) {
                    $displayName = $circleName;
                }
            }

            if ($isGroup) {
                try {
                    $groupManager = $this->container->get(\OCP\IGroupManager::class);
                    $group = $groupManager->get($displayName);
                    if ($group) {
                        $displayName = $group->getDisplayName() ?: $displayName;
                    }
                } catch (\Throwable $e) {
                    // Keep derived name
                }
            }

            $memberships[] = [
                'type'        => $isGroup ? 'group' : 'circle',
                'displayName' => $displayName,
                'memberCount' => $subCount,
            ];
        }
        $gcRes->closeCursor();

        // Flag whether the current user is a DIRECT member (user_type=1 row).
        $isDirectMember = $this->getMemberLevelFromDb($db, $teamId, $user->getUID()) > 0;

        return [
            'members'          => $members,
            'memberships'      => $memberships,
            'effective_count'  => $effectiveCount,
            'has_more'         => $effectiveCount > count($members),
            'is_direct_member' => $isDirectMember,
        ];
    }

    /**
     * Get the full flat, deduplicated list of all effective members of a team
     * for the "Show all" modal. Includes users added directly AND users added
     * via groups or sub-teams.
     *
     * Reads from circles_membership (Circles' denormalised cache). That cache
     * already deduplicates — if a user is both directly added and in a group
     * that was added, they appear only once.
     *
     * Returns a flat array of { userId, displayName } sorted by displayName.
     *
     * @throws \Exception if user is not authenticated or not a member
     */
    public function getAllEffectiveMembers(string $teamId): array {

        $this->requireMemberLevel($teamId);

        $db = $this->container->get(\OCP\IDBConnection::class);

        // Step 1: pull all single_ids with non-zero level from circles_membership.
        // Only keep rows whose single_id points to a user circle (source=1),
        // since circles_membership also contains sub-circle entries — those rows
        // represent "this sub-circle is reachable from this parent", not a user.
        $qb  = $db->getQueryBuilder();
        $res = $qb->select('ms.single_id')
            ->from('circles_membership', 'ms')
            ->innerJoin('ms', 'circles_circle', 'cc', 'ms.single_id = cc.unique_id')
            ->where($qb->expr()->eq('ms.circle_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('cc.source',
                $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->executeQuery();

        $singleIds = [];
        while ($r = $res->fetch()) {
            $singleIds[] = (string)$r['single_id'];
        }
        $res->closeCursor();

        if (empty($singleIds)) {
            return [];
        }

        // Step 2: resolve each single_id to a NC user. Circles stores a user-circle
        // row in circles_member for each personal circle: circle_id = the user's
        // personal-circle unique_id (= single_id), user_type=1, user_id=NC uid.
        $uQb  = $db->getQueryBuilder();
        $uRes = $uQb->select('m.user_id', 'u.displayname')
            ->from('circles_member', 'm')
            ->leftJoin('m', 'users', 'u', 'm.user_id = u.uid')
            ->where($uQb->expr()->in('m.circle_id',
                $uQb->createNamedParameter($singleIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($uQb->expr()->eq('m.user_type',
                $uQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->executeQuery();

        $seen = [];
        $list = [];
        while ($r = $uRes->fetch()) {
            $uid = (string)$r['user_id'];
            if ($uid === '' || isset($seen[$uid])) {
                continue;
            }
            $seen[$uid] = true;
            $list[] = [
                'userId'      => $uid,
                'displayName' => !empty($r['displayname']) ? $r['displayname'] : $uid,
            ];
        }
        $uRes->closeCursor();

        // Sort by display name, case-insensitive
        usort($list, fn ($a, $b) => strcasecmp($a['displayName'], $b['displayName']));

        return $list;
    }

    /**
     * Get the full structured member breakdown for the Manage Team → Members tab.
     * Requires admin or owner level.
     *
     * Returns three buckets:
     *   direct   — local users (user_type=1) added individually
     *   groups   — NC groups (user_type=2) — each row has groupId, displayName, memberCount
     *   circles  — other teams/circles (user_type=16) — each row has circleId, displayName, memberCount
     *
     * Plus effective_count (from circles_membership) representing ALL users with
     * access including those expanded from groups/circles.
     */
    public function getMembersForManage(string $teamId): array {

        $this->requireAdminLevel($teamId);

        $db = $this->container->get(\OCP\IDBConnection::class);

        // ── effective count ────────────────────────────────────────────────────
        $effectiveCount = $this->getEffectiveMemberCount($teamId, $db);

        // ── direct users (user_type=1) ─────────────────────────────────────────
        $dQb  = $db->getQueryBuilder();
        $dRes = $dQb->select('m.user_id', 'm.level', 'm.status', 'u.displayname')
            ->from('circles_member', 'm')
            ->leftJoin('m', 'users', 'u', 'm.user_id = u.uid')
            ->where($dQb->expr()->eq('m.circle_id',  $dQb->createNamedParameter($teamId)))
            ->andWhere($dQb->expr()->eq('m.status',   $dQb->createNamedParameter('Member')))
            ->andWhere($dQb->expr()->eq('m.user_type', $dQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->orderBy('m.level', 'DESC')
            ->executeQuery();

        $direct = [];
        while ($row = $dRes->fetch()) {
            $userId = (string)$row['user_id'];
            $level  = (int)$row['level'];
            $direct[] = [
                'userId'      => $userId,
                'displayName' => !empty($row['displayname']) ? $row['displayname'] : $userId,
                'role'        => match (true) {
                    $level >= 9 => 'Owner',
                    $level >= 8 => 'Admin',
                    $level >= 4 => 'Moderator',
                    default     => 'Member',
                },
                'level'  => $level,
                'status' => 'Member',
            ];
        }
        $dRes->closeCursor();

        // ── groups (user_type=2) and circles (user_type=16) ────────────────────
        //
        // Single unified query — both share the same join pattern.
        //
        // CRITICAL: for user_type=2 (group) and user_type=16 (circle):
        //   m.user_id   = human-readable label (group GID or circle display name)
        //   m.single_id = unique_id of the corresponding circles_circle row
        //
        // To resolve the name and classify the row, JOIN on m.single_id = cc.unique_id.
        // To count effective members, use circles_membership WHERE circle_id = single_id.
        //
        // Classification:
        //   - user_type=2 OR cc.source=2 → Groups section (NC group)
        //   - user_type=16 AND cc.source=16 → Teams section (user-created team)
        //
        // The remove handle is always m.single_id + the original user_type, because
        // circles_member deletes require (circle_id, single_id, user_type) to target
        // the exact row uniquely.
        $groups  = [];
        $circles = [];

        $gcQb  = $db->getQueryBuilder();
        $gcRes = $gcQb->select('m.user_id', 'm.single_id', 'm.user_type',
                               'cc.name AS circle_name', 'cc.source AS circle_source')
            ->from('circles_member', 'm')
            ->leftJoin('m', 'circles_circle', 'cc', 'm.single_id = cc.unique_id')
            ->where($gcQb->expr()->eq('m.circle_id',  $gcQb->createNamedParameter($teamId)))
            ->andWhere($gcQb->expr()->eq('m.status',   $gcQb->createNamedParameter('Member')))
            ->andWhere($gcQb->expr()->in(
                'm.user_type',
                $gcQb->createNamedParameter([2, 16], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)
            ))
            ->executeQuery();

        while ($row = $gcRes->fetch()) {
            $subLabel   = (string)$row['user_id'];      // human label
            $subSingle  = (string)$row['single_id'];    // → circles_circle.unique_id
            $subType    = (int)$row['user_type'];
            $subSource  = (int)($row['circle_source'] ?? 0);
            $circleName = (string)($row['circle_name'] ?? '');

            // Effective count from circles_membership using single_id as the key
            $subCount = $this->getEffectiveMemberCount($subSingle, $db);

            // Resolve display name
            $displayName = $subLabel;
            if ($circleName !== '') {
                if (str_starts_with($circleName, 'group:')) {
                    $displayName = substr($circleName, 6);
                } elseif (!str_starts_with($circleName, 'user:')) {
                    $displayName = $circleName;
                }
            }

            // Classify as group when row is user_type=2 OR circle source=2
            $isGroup = ($subType === 2) || ($subSource === 2);

            if ($isGroup) {
                // Resolve the friendly group display name via IGroupManager
                try {
                    $groupManager = $this->container->get(\OCP\IGroupManager::class);
                    $g = $groupManager->get($displayName);
                    if ($g) {
                        $displayName = $g->getDisplayName() ?: $displayName;
                    }
                } catch (\Throwable $e) {
                    // Keep derived name
                }
                $groups[] = [
                    // singleId is the DELETE key for this row in circles_member.
                    // userType tells the frontend which type to send in the remove call.
                    'groupId'     => $subSingle,
                    'singleId'    => $subSingle,
                    'userType'    => $subType,
                    'displayName' => $displayName,
                    'memberCount' => $subCount,
                ];
            } else {
                $circles[] = [
                    'circleId'    => $subSingle,
                    'singleId'    => $subSingle,
                    'userType'    => $subType,
                    'displayName' => $displayName,
                    'memberCount' => $subCount,
                ];
            }

            $this->logger->debug('[MemberService] getMembersForManage: row resolved', [
                'user_id' => $subLabel, 'single_id' => $subSingle,
                'user_type' => $subType, 'circle_source' => $subSource,
                'circle_name' => $circleName, 'display' => $displayName,
                'count' => $subCount, 'is_group' => $isGroup,
                'app' => Application::APP_ID,
            ]);
        }
        $gcRes->closeCursor();

        $this->logger->debug('[MemberService] getMembersForManage', [
            'teamId'          => $teamId,
            'direct'          => count($direct),
            'groups'          => count($groups),
            'circles'         => count($circles),
            'effective_count' => $effectiveCount,
            'app'             => Application::APP_ID,
        ]);

        return [
            'direct'          => $direct,
            'groups'          => $groups,
            'circles'         => $circles,
            'effective_count' => $effectiveCount,
        ];
    }

    /**
     * Valid target levels: 1 (Member), 4 (Moderator), 8 (Admin).
     * Owner (9) cannot be demoted through this endpoint.
     * The caller cannot change their own level.
     *
     * Uses direct DB writes — no Circles session overhead for permission checks.
     *
     * @throws \Exception on invalid state or insufficient permissions
     */
    public function updateMemberLevel(string $teamId, string $userId, int $newLevel): array {

        $caller = $this->userSession->getUser();
        if (!$caller) {
            throw new \Exception('User not authenticated');
        }

        if ($caller->getUID() === $userId) {
            throw new \Exception('You cannot change your own level');
        }

        if (!in_array($newLevel, [1, 4, 8], true)) {
            throw new \Exception('Invalid level. Must be 1 (Member), 4 (Moderator), or 8 (Admin)');
        }

        $db = $this->container->get(\OCP\IDBConnection::class);

        // Look up caller's own level directly — no Circles session needed
        $callerLevel = $this->getMemberLevelFromDb($db, $teamId, $caller->getUID());
        if ($callerLevel < 8) {
            throw new \Exception('Insufficient permissions. Admin or owner role required.');
        }

        // Look up target member's current level
        $targetLevel = $this->getMemberLevelFromDb($db, $teamId, $userId);
        if ($targetLevel === 0) {
            throw new \Exception('Member not found in this team');
        }
        if ($targetLevel >= 9) {
            throw new \Exception('Cannot change the level of the team owner');
        }

        // Only the owner can promote to admin
        if ($newLevel >= 8 && $callerLevel < 9) {
            throw new \Exception('Only the team owner can promote members to Admin');
        }

        // Write the new level directly to circles_member
        $qb = $db->getQueryBuilder();
        $qb->update('circles_member')
            ->set('level', $qb->createNamedParameter($newLevel, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('Member')))
            ->executeStatement();

        // Return refreshed member list
        return $this->getTeamMembers($teamId);
    }

    /**
     * Direct DB lookup for a member's level in a team (0 if not a member).
     * Public so TeamService can use it for permission checks.
     */
    public function getMemberLevelFromDb(\OCP\IDBConnection $db, string $teamId, string $userId): int {
        $qb = $db->getQueryBuilder();
        $result = $qb->select('level')
            ->from('circles_member')
            ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('Member')))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ? (int)$row['level'] : 0;
    }

    /**
     * Asserts the current user is at least a basic member (level >= 1) of the team.
     * Use this to gate read-only endpoints that should be invisible to non-members.
     * Uses a direct indexed DB query — avoids the full Circles API member-list fetch.
     *
     * @throws \Exception if user is not authenticated or not a member
     */
    public function requireMemberLevel(string $teamId): void {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $db    = $this->container->get(\OCP\IDBConnection::class);
        $level = $this->getMemberLevelFromDb($db, $teamId, $user->getUID());

        // Direct member — fast path
        if ($level > 0) {
            return;
        }

        // Indirect member (added via a group or another team) — also allowed
        // for read-only operations. Admin/moderator gates use requireAdminLevel()
        // / requireModeratorLevel() which require a direct row with the correct level.
        if ($this->isEffectiveMember($teamId, $user->getUID(), $db)) {
            return;
        }

        throw new \Exception('You are not a member of this team');
    }

    /**
     * Asserts the current user has admin (level >= 8) or owner (level 9) in the team.
     * Uses a direct indexed DB query — avoids the full Circles API member-list fetch.
     *
     * @throws \Exception if user is not authenticated, not a member, or insufficient level
     */
    public function requireAdminLevel(string $teamId): void {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $db    = $this->container->get(\OCP\IDBConnection::class);
        $level = $this->getMemberLevelFromDb($db, $teamId, $user->getUID());

        if ($level === 0) {
            throw new \Exception('You are not a member of this team');
        }
        if ($level < 8) {
            throw new \Exception('Insufficient permissions. Admin or owner role required.');
        }
    }

    /**
     * Asserts the current user is the team owner (level 9).
     * Used for actions that only an owner may perform (e.g. transfer ownership).
     *
     * @throws \Exception if user is not authenticated, not a member, or not owner
     */
    public function requireOwnerLevel(string $teamId): void {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $db    = $this->container->get(\OCP\IDBConnection::class);
        $level = $this->getMemberLevelFromDb($db, $teamId, $user->getUID());

        if ($level === 0) {
            throw new \Exception('You are not a member of this team');
        }
        if ($level < 9) {
            throw new \Exception('Insufficient permissions. Owner role required.');
        }
    }

    /**
     * Asserts the current user has moderator (level >= 4), admin, or owner in the team.
     * Uses a direct indexed DB query — avoids the full Circles API member-list fetch.
     *
     * @throws \Exception if user is not authenticated, not a member, or insufficient level
     */
    public function requireModeratorLevel(string $teamId): void {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $db    = $this->container->get(\OCP\IDBConnection::class);
        $level = $this->getMemberLevelFromDb($db, $teamId, $user->getUID());

        if ($level === 0) {
            throw new \Exception('You are not a member of this team');
        }
        if ($level < 4) {
            throw new \Exception('Insufficient permissions. Moderator, admin, or owner role required.');
        }
    }

    /** Convert a Circle Member to the array shape used by the API. */
    public function memberToArray(mixed $member): array {
        $userId = method_exists($member, 'getUserId')
            ? $member->getUserId()
            : (method_exists($member, 'getId') ? $member->getId() : null);

        // Skip non-user members (circles-as-members, external, etc.) that have no userId
        $userId = ($userId && $userId !== '') ? $userId : null;

        $displayName = $userId
            ? (method_exists($member, 'getDisplayName') ? $member->getDisplayName() : $userId)
            : (method_exists($member, 'getDisplayName') ? $member->getDisplayName() : 'Unknown');
        $level = method_exists($member, 'getLevel') ? $member->getLevel() : 1;

        $role = match (true) {
            $level >= 9 => 'Owner',
            $level >= 8 => 'Admin',
            $level >= 4 => 'Moderator',
            default     => 'Member',
        };

        $status = method_exists($member, 'getStatus') ? $member->getStatus() : null;
        // Circles status: 1=Unknown, 2=Invited, 4=Requesting, 8=Member, 9=Blocked
        // Map numeric status to string for the frontend
        $statusLabel = match((int)$status) {
            4       => 'Requesting',
            2       => 'Invited',
            9       => 'Blocked',
            default => 'Member',
        };

        return [
            'userId'      => $userId,
            'displayName' => $displayName,
            'role'        => $role,
            'level'       => $level,
            'status'      => $statusLabel,
        ];
    }

    // -------------------------------------------------------------------------
    // Join, leave, invite
    // -------------------------------------------------------------------------

    /**
     * Leave a team (remove current user from circle).
     *
     * Throws a specific exception when the user is only an INDIRECT member
     * (added via a group or another team) so the frontend can show a tooltip
     * instead of a generic error.
     *
     * @throws \Exception if owner tries to leave with members still in the team,
     *                    or if user is only an indirect member
     */
    public function leaveTeam(string $teamId): void {

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        // CircleService::circleLeave() throws CircleNotFoundException on circles with a
        // non-zero config bitmask (same root cause as removeMember). Use a direct DB
        // delete instead — safe because we verify membership and owner status first.
        try {
            $db  = $this->container->get(\OCP\IDBConnection::class);
            $uid = $user->getUID();

            $level = $this->getMemberLevelFromDb($db, $teamId, $uid);

            if ($level === 0) {
                // Check if the user has indirect access (via group/team) before
                // returning a confusing "not a member" error.
                if ($this->isEffectiveMember($teamId, $uid, $db)) {
                    throw new \Exception('indirect_member');
                }
                throw new \Exception('You are not a member of this team.');
            }

            if ($level >= 9) {
                $cntQb  = $db->getQueryBuilder();
                $cntRes = $cntQb->select($cntQb->func()->count('*', 'cnt'))
                    ->from('circles_member')
                    ->where($cntQb->expr()->eq('circle_id', $cntQb->createNamedParameter($teamId)))
                    ->andWhere($cntQb->expr()->eq('status', $cntQb->createNamedParameter('Member')))
                    ->executeQuery();
                $cnt = (int)($cntRes->fetch()['cnt'] ?? 0);
                $cntRes->closeCursor();
                if ($cnt > 1) {
                    throw new \Exception('Team owner cannot leave. Transfer ownership or delete the team first.');
                }
            }

            // Delete all rows for this user in this circle (covers Member + Requesting).
            $delQb = $db->getQueryBuilder();
            $delQb->delete('circles_member')
                ->where($delQb->expr()->eq('circle_id', $delQb->createNamedParameter($teamId)))
                ->andWhere($delQb->expr()->eq('user_id',   $delQb->createNamedParameter($uid)))
                ->andWhere($delQb->expr()->eq('user_type', $delQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $this->logger->info('[MemberService] leaveTeam: member removed via direct DB delete', [
                'uid'    => $uid,
                'teamId' => $teamId,
                'app'    => Application::APP_ID,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[MemberService] Error leaving team', [
                'teamId'    => $teamId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            throw $e;
        }
    }

    /**
     * Remove a member from a team. Requires admin or owner level.
     *
     * @throws \Exception if target is the owner or not found
     */
    /**
     * Remove a member, group, or team from a team. Requires admin or owner level.
     *
     * @param string $teamId    Circle unique_id of the parent team
     * @param string $targetId  For type=1: NC user ID (stored in circles_member.user_id)
     *                          For type=2 or 16: single_id of the row
     *                          (i.e. the sub-circle's unique_id)
     * @param int    $userType  Circles member type: 1=user, 2=group, 16=circle
     * @throws \Exception on invalid state or insufficient permissions
     */
    public function removeMember(string $teamId, string $targetId, int $userType = 1): void {

        $this->requireAdminLevel($teamId);

        $db = $this->container->get(\OCP\IDBConnection::class);

        if ($userType === 1) {
            // User rows: lookup by user_id (NC uid), verify not the owner
            $mQb  = $db->getQueryBuilder();
            $mRes = $mQb->select('level')
                ->from('circles_member')
                ->where($mQb->expr()->eq('circle_id', $mQb->createNamedParameter($teamId)))
                ->andWhere($mQb->expr()->eq('user_id',  $mQb->createNamedParameter($targetId)))
                ->andWhere($mQb->expr()->eq('user_type', $mQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery();
            $mRow = $mRes->fetch();
            $mRes->closeCursor();

            if (!$mRow) {
                throw new \Exception('Member not found in this team');
            }
            if ((int)$mRow['level'] >= 9) {
                throw new \Exception('Cannot remove the team owner');
            }

            // Delete by user_id
            $delQb = $db->getQueryBuilder();
            $delQb->delete('circles_member')
                ->where($delQb->expr()->eq('circle_id', $delQb->createNamedParameter($teamId)))
                ->andWhere($delQb->expr()->eq('user_id',  $delQb->createNamedParameter($targetId)))
                ->andWhere($delQb->expr()->eq('user_type', $delQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();
        } else {
            // Group (type=2) or circle (type=16) rows:
            // user_id stores a display label, NOT a lookup key.
            // The delete key is single_id (which = the sub-circle's unique_id).
            $mQb  = $db->getQueryBuilder();
            $mRes = $mQb->select($mQb->func()->count('*', 'cnt'))
                ->from('circles_member')
                ->where($mQb->expr()->eq('circle_id', $mQb->createNamedParameter($teamId)))
                ->andWhere($mQb->expr()->eq('single_id', $mQb->createNamedParameter($targetId)))
                ->andWhere($mQb->expr()->eq('user_type', $mQb->createNamedParameter($userType, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeQuery();
            $cnt = (int)$mRes->fetchOne();
            $mRes->closeCursor();
            if ($cnt === 0) {
                $typeName = $userType === 2 ? 'Group' : 'Team';
                throw new \Exception($typeName . ' not found in this team');
            }

            // Delete by single_id
            $delQb = $db->getQueryBuilder();
            $delQb->delete('circles_member')
                ->where($delQb->expr()->eq('circle_id', $delQb->createNamedParameter($teamId)))
                ->andWhere($delQb->expr()->eq('single_id', $delQb->createNamedParameter($targetId)))
                ->andWhere($delQb->expr()->eq('user_type', $delQb->createNamedParameter($userType, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();
        }

        // Rebuild the circles_membership cache for this team so users added via
        // the removed group/circle disappear from share pickers. Non-fatal —
        // the admin maintenance UI can also rebuild manually.
        try {
            $membershipService = $this->container->get(\OCA\Circles\Service\MembershipService::class);
            $membershipService->onUpdate($teamId);
        } catch (\Throwable $e) {
            $this->logger->warning('[MemberService] removeMember: cache rebuild failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        $this->logger->info('[MemberService] removeMember: removed via direct DB delete', [
            'teamId'   => $teamId,
            'targetId' => $targetId,
            'userType' => $userType,
            'app'      => Application::APP_ID,
        ]);
    }

    /**
     * Invite a list of users/groups to a team. Requires admin or owner level.
     * Each entry can be a string (userId, type=user) or array {id, type}.
     * Types: 'user'=local NC user (Circles type 1), 'group'=NC group (type 2),
     *        'federated'=federated user (type 4), 'email'=email (type 7).
     * Non-fatal per entry — returns per-id results.
     */
    public function inviteMembers(string $teamId, array $members): array {

        // Moderator (level >= 4) or above may invite — matches the controller gate.
        // Previously this called requireAdminLevel() which silently rejected moderators.
        $this->requireModeratorLevel($teamId);

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        // Use Circles' own MemberService::addMember() + FederatedUserService —
        // same services that LocalController::memberAdd() uses.
        // No manual startSession()/stopSession() needed.
        $circleMemberService  = $this->container->get(\OCA\Circles\Service\MemberService::class);
        $federatedUserService = $this->container->get(\OCA\Circles\Service\FederatedUserService::class);
        $federatedUserService->setLocalCurrentUser($user);

        $results = [];
        foreach ($members as $entry) {
            // Support both plain string (legacy) and {id, type} object
            if (is_string($entry)) {
                $memberId   = $entry;
                $memberType = 1; // user
            } else {
                $memberId   = $entry['id'] ?? '';
                $typeStr    = $entry['type'] ?? 'user';
                $memberType = match($typeStr) {
                    'group'     => 2,   // NC group
                    'federated' => 4,   // federated NC user
                    'email'     => 7,   // email (requires Circles federation)
                    'circle'    => 16,  // another NC circle/team
                    default     => 1,   // local NC user
                };
            }

            if (!$memberId || ($memberType === 1 && $memberId === $user->getUID())) {
                continue;
            }

            try {
                $invitee = $federatedUserService->generateFederatedUser($memberId, $memberType);
                $circleMemberService->addMember($teamId, $invitee);
                $results[$memberId] = 'invited';
                $this->logger->info('[MemberService] inviteMembers: member added', [
                    'id' => $memberId, 'type' => $memberType, 'app' => Application::APP_ID,
                ]);
            } catch (\Exception $e) {
                $results[$memberId] = 'failed: ' . $e->getMessage();
                $this->logger->warning('[MemberService] Could not invite member', [
                    'id'        => $memberId,
                    'type'      => $memberType,
                    'exception' => $e,
                    'app'       => Application::APP_ID,
                ]);
            }
        }

        return $results;
    }

    /**
     * Request to join a team.
     * Uses CirclesManager::addMember() which creates a pending request.
     * Falls back to a direct DB insert if Circles can't find the circle
     * (happens when config != 0, which causes probeCircles to hide it).
     */
    public function requestJoinTeam(string $teamId): void {

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        // First try via Circles CircleService::circleJoin() — this is the same
        // path the Circles UI uses (LocalController::circleJoin). It calls
        // setCurrentFederatedUser() internally and does not require a manual
        // startSession()/stopSession() dance, which is what caused "Circle not
        // found" errors when the circle config bitmask is non-zero.
        try {
            $circleService = $this->container->get(\OCA\Circles\Service\CircleService::class);
            $federatedUserService = $this->container->get(\OCA\Circles\Service\FederatedUserService::class);
            $federatedUserService->setLocalCurrentUser($user);
            $circleService->circleJoin($teamId);
            $this->logger->info('[MemberService] requestJoinTeam: circleJoin succeeded via CircleService', [
                'uid'    => $user->getUID(),
                'teamId' => $teamId,
                'app'    => Application::APP_ID,
            ]);
            return;
        } catch (\Throwable $e) {
            $this->logger->warning('[MemberService] requestJoinTeam via CircleService::circleJoin failed, trying direct DB insert', [
                'teamId' => $teamId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
        }

        // Fallback: insert a pending member row directly.
        // status=Requesting, level=1 (member), user_type=1 (local user)
        $db  = $this->container->get(\OCP\IDBConnection::class);
        $uid = $user->getUID();

        // Check not already a member or requesting
        $checkQb  = $db->getQueryBuilder();
        $checkRes = $checkQb->select('single_id')
            ->from('circles_member')
            ->where($checkQb->expr()->eq('circle_id', $checkQb->createNamedParameter($teamId)))
            ->andWhere($checkQb->expr()->eq('user_id',   $checkQb->createNamedParameter($uid)))
            ->andWhere($checkQb->expr()->eq('user_type', $checkQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $existing = $checkRes->fetch();
        $checkRes->closeCursor();
        if ($existing) {
            throw new \Exception('You are already a member or have a pending request for this team');
        }

        // Look up the user's real single_id from their personal circle.
        //
        // In NC32 Circles, personal circles are named 'user:{uid}:{randomId}' — NOT
        // 'user:{uid}'. We cannot use a name lookup reliably. Instead we use two
        // authoritative sources:
        //   1. NC preferences: Circles writes the single_id to oc_preferences / oc_user_preferences
        //      under app='circles', key='userSingleId' after creating the personal circle.
        //   2. DB join on circles_circle + circles_member: find the CFG_SINGLE circle
        //      (config & 2048 > 0) where this user is the owner (level=9).
        //
        // single_id is NOT a free-form ID — Circles uses it to resolve the member
        // identity. Inserting a random value corrupts the circle for all members.

        $singleId = $this->resolveUserSingleId($uid, $db);

        if ($singleId === null) {
            // Personal circle doesn't exist yet (brand new user who has never
            // interacted with Circles). Generate it via Circles' own service.
            $this->logger->info('[MemberService] requestJoinTeam: personal circle not found, generating it', [
                'uid' => $uid, 'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
            try {
                $federatedUserService = $this->container->get(\OCA\Circles\Service\FederatedUserService::class);
                $generated = $federatedUserService->getLocalFederatedUser($uid, true, true);
                $singleId  = $generated->getSingleId();
                $this->logger->info('[MemberService] requestJoinTeam: personal circle generated', [
                    'uid' => $uid, 'singleId' => $singleId, 'app' => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('[MemberService] requestJoinTeam: could not generate personal circle', [
                    'uid' => $uid, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                throw new \Exception('Unable to request team membership: your Nextcloud account is not fully initialised. Please contact your administrator.');
            }
        }

        if (!$singleId) {
            throw new \Exception('Unable to request team membership: could not resolve your Nextcloud identity. Please contact your administrator.');
        }

        $this->logger->info('[MemberService] requestJoinTeam: resolved single_id for user', [
            'uid' => $uid, 'singleId' => $singleId, 'app' => Application::APP_ID,
        ]);

        // Build insert with only columns that exist in this NC version's circles_member table
        // getTableColumns() lives on ResourceService — injected via constructor
        $qb           = $db->getQueryBuilder();
        $existingCols = array_flip($this->resourceService->getTableColumns('circles_member'));
        $values = [
            'circle_id'  => $qb->createNamedParameter($teamId),
            'single_id'  => $qb->createNamedParameter($singleId),
            'user_id'    => $qb->createNamedParameter($uid),
            'user_type'  => $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
            'member_id'  => $qb->createNamedParameter($uid),
            'instance'   => $qb->createNamedParameter(''),
            'level'      => $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
            'status'     => $qb->createNamedParameter('Requesting'),
            // circles_member.joined is a DATETIME column (not INT) — must be formatted as a date string.
            // Passing time() as an integer causes SQLSTATE[22007] on MySQL/MariaDB.
            'joined'     => $qb->createNamedParameter(date('Y-m-d H:i:s', time())),
        ];
        $optional = [
            'display_name' => $qb->createNamedParameter($user->getDisplayName() ?: $uid),
            'cached_name'  => $qb->createNamedParameter($user->getDisplayName() ?: $uid),
            'note'         => $qb->createNamedParameter(''),
            'contact_id'   => $qb->createNamedParameter(''),
            'contact_meta' => $qb->createNamedParameter(''),
        ];
        foreach ($optional as $col => $val) {
            if (isset($existingCols[$col])) {
                $values[$col] = $val;
            }
        }

        try {
            $qb->insert('circles_member')->values($values)->executeStatement();
        } catch (\Throwable $e) {
            $this->logger->error('[MemberService] requestJoinTeam DB fallback failed', [
                'teamId' => $teamId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            throw new \Exception('Failed to request team membership: ' . $e->getMessage());
        }

        // Check if the circle is open-join (CFG_OPEN bit 1 set = no approval needed).
        // This must happen BEFORE sending a notification so admins are only notified
        // when the join actually requires their approval.
        try {
            $cfgQb  = $db->getQueryBuilder();
            $cfgRes = $cfgQb->select('config')
                ->from('circles_circle')
                ->where($cfgQb->expr()->eq('unique_id', $cfgQb->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $cfgRow = $cfgRes->fetch();
            $cfgRes->closeCursor();

            $circleConfig = $cfgRow ? (int)$cfgRow['config'] : 0;
            $isOpen = ($circleConfig & 1) > 0; // CFG_OPEN = bit 1


            $this->logger->info('[MemberService] requestJoinTeam: circle config check', [
                'teamId' => $teamId, 'config' => $circleConfig, 'isOpen' => $isOpen,
                'app'    => Application::APP_ID,
            ]);

            if ($isOpen) {
                // Open circle: auto-approve by flipping status straight to Member.
                // Do NOT send a notification — no admin action is required.
                $approveQb = $db->getQueryBuilder();
                $approveQb->update('circles_member')
                    ->set('status', $approveQb->createNamedParameter('Member'))
                    ->set('level',  $approveQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    ->where($approveQb->expr()->eq('circle_id', $approveQb->createNamedParameter($teamId)))
                    ->andWhere($approveQb->expr()->eq('user_id',   $approveQb->createNamedParameter($uid)))
                    ->andWhere($approveQb->expr()->eq('status',    $approveQb->createNamedParameter('Requesting')))
                    ->executeStatement();
                $this->logger->info('[MemberService] requestJoinTeam: open circle — auto-approved, no notification sent', [
                    'uid' => $uid, 'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);

                // Audit: this is a direct join, not a pending request. Circles will
                // also write a `member_added` activity row that the mirror job will
                // pick up — this direct entry will be deduplicated against it.
                $this->auditService->log(
                    $teamId,
                    'member.joined',
                    $uid,
                    'member',
                    $uid,
                    ['via' => 'open_circle_self_join'],
                );
            } else {
                // Closed circle: notify admins that approval is needed.
                $this->sendJoinRequestNotification($teamId, $uid, $db);
                $this->logger->info('[MemberService] requestJoinTeam: closed circle — notification sent to admins', [
                    'uid' => $uid, 'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);

                // Audit: pending join request awaiting admin approval.
                $this->auditService->log(
                    $teamId,
                    'join.requested',
                    $uid,
                    'member',
                    $uid,
                    null,
                );
            }
        } catch (\Throwable $e) {
            // Non-fatal — user is Requesting and an admin can approve manually
            $this->logger->warning('[MemberService] requestJoinTeam: open/closed check failed, skipping notification', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the Circles single_id for a local NC user.
     *
     * In NC32, Circles personal circles are named 'user:{uid}:{randomId}' — a name
     * lookup is therefore unreliable. We use two strategies in order of preference:
     *
     * 1. NC preferences table: Circles writes 'userSingleId' under app='circles'
     *    after creating or resolving the personal circle. This is the fastest path
     *    and is available immediately after the user has interacted with Circles once.
     *
     * 2. DB join: find the circles_circle row with CFG_SINGLE config bit set where
     *    this user is the owner member (level=9). This works even if the preference
     *    was not written (e.g. fresh installs or preference table truncation).
     *
     * Returns null when no personal circle exists yet (new user).
     */
    /**
     * Send a join_request notification to all admins and owners of a team.
     * Called after a Requesting row is inserted. Non-fatal — logged but never throws.
     */
    private function sendJoinRequestNotification(string $teamId, string $requestingUid, \OCP\IDBConnection $db): void {
        try {
            $notificationManager = $this->container->get(INotificationManager::class);
            $urlGenerator        = $this->container->get(\OCP\IURLGenerator::class);

            // Resolve the requesting user's display name
            $requestingUser = $this->userManager->get($requestingUid);
            $requesterName  = $requestingUser ? ($requestingUser->getDisplayName() ?: $requestingUid) : $requestingUid;

            // Resolve the team name from circles_circle
            $cQb  = $db->getQueryBuilder();
            $cRes = $cQb->select('name')
                ->from('circles_circle')
                ->where($cQb->expr()->eq('unique_id', $cQb->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $cRow     = $cRes->fetch();
            $cRes->closeCursor();
            $teamName = $cRow ? (string)$cRow['name'] : $teamId;

            // Find all admins (level >= 8) and owners (level >= 9) via DB
            $aQb  = $db->getQueryBuilder();
            $aRes = $aQb->select('user_id')
                ->from('circles_member')
                ->where($aQb->expr()->eq('circle_id',  $aQb->createNamedParameter($teamId)))
                ->andWhere($aQb->expr()->eq('status',   $aQb->createNamedParameter('Member')))
                ->andWhere($aQb->expr()->eq('user_type', $aQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($aQb->expr()->gte('level',   $aQb->createNamedParameter(8, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeQuery();

            $link = $urlGenerator->linkToRouteAbsolute('teamhub.page.index') . '?team=' . urlencode($teamId);

            while ($aRow = $aRes->fetch()) {
                $adminUid = (string)$aRow['user_id'];
                if ($adminUid === $requestingUid) continue;

                try {
                    $notification = $notificationManager->createNotification();
                    $notification->setApp('teamhub')
                        ->setUser($adminUid)
                        ->setDateTime(new \DateTime())
                        ->setObject('join_request', $teamId)
                        ->setSubject('join_request', [
                            'requestingUid'  => $requestingUid,
                            'requesterName'  => $requesterName,
                            'teamId'         => $teamId,
                            'teamName'       => $teamName,
                        ])
                        ->setLink($link);
                    $notificationManager->notify($notification);
                } catch (\Throwable $e) {
                    $this->logger->warning('[MemberService] sendJoinRequestNotification: failed for admin', [
                        'adminUid' => $adminUid, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }
            }
            $aRes->closeCursor();

            $this->logger->info('[MemberService] sendJoinRequestNotification: sent to team admins', [
                'teamId' => $teamId, 'requester' => $requestingUid, 'app' => Application::APP_ID,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[MemberService] sendJoinRequestNotification failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Effective-membership helpers (circles_membership as source of truth)
    // -------------------------------------------------------------------------

    /**
     * Return the total number of users who effectively have access to a team
     * (or sub-circle), including those added via groups or nested teams.
     *
     * Uses circles_membership — Nextcloud Circles' own denormalised cache.
     * This is the same table that share pickers and the NC frontend use.
     * It is populated by Circles itself when members are managed via its API,
     * and can be rebuilt with `occ circles:memberships`.
     *
     * Confirmed working: admin settings grid uses this query and shows correct
     * counts (e.g. 34 = 2 direct + 32 via group) for teams with group members.
     *
     * @param string        $teamId Circle unique_id (parent OR sub-circle)
     * @param IDBConnection $db     Open DB connection
     * @return int
     */
    public function getEffectiveMemberCount(string $teamId, \OCP\IDBConnection $db): int {
        try {
            // COUNT(*) on circles_membership is wrong: that table contains one row
            // per ENTITY with access — including group-proxy circles and sub-team
            // circles themselves, not just individual users. For a team with an owner
            // (direct) and a group of 1 user, COUNT(*) gives 3:
            //   - owner personal circle
            //   - group proxy circle    ← should NOT be counted as a person
            //   - group-user personal circle
            //
            // Correct approach: join circles_member to identify which single_id values
            // are personal user circles (they have exactly one user_type=1, level=9
            // owner row). Group and sub-team proxy circles have no such row, so they
            // fall out of the INNER JOIN. COUNT(DISTINCT pm.user_id) then deduplicates
            // users who appear via multiple paths (e.g. direct member AND in a group).
            $qb  = $db->getQueryBuilder();
            $res = $qb->select($qb->createFunction('COUNT(DISTINCT pm.user_id)'))
                ->from('circles_membership', 'ms')
                ->join(
                    'ms',
                    'circles_member',
                    'pm',
                    $qb->expr()->andX(
                        $qb->expr()->eq('pm.circle_id',  'ms.single_id'),
                        $qb->expr()->eq('pm.user_type',  $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)),
                        $qb->expr()->eq('pm.level',      $qb->createNamedParameter(9, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    )
                )
                ->where($qb->expr()->eq('ms.circle_id', $qb->createNamedParameter($teamId)))
                ->executeQuery();
            $cnt = (int)$res->fetchOne();
            $res->closeCursor();
            $this->logger->debug('[MemberService] getEffectiveMemberCount', [
                'teamId' => $teamId, 'count' => $cnt, 'app' => Application::APP_ID,
            ]);
            return $cnt;
        } catch (\Throwable $e) {
            $this->logger->warning('[MemberService] getEffectiveMemberCount failed, returning 0', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return 0;
        }
    }

    /**
     * Check whether a user has effective access to a team — directly
     * (user_type=1 row in circles_member) or indirectly (via a group or
     * another team that is a member of this team).
     *
     * Falls back gracefully when circles_membership is unavailable.
     */
    public function isEffectiveMember(string $teamId, string $uid, \OCP\IDBConnection $db): bool {
        // Fast path: direct user row
        if ($this->getMemberLevelFromDb($db, $teamId, $uid) > 0) {
            return true;
        }
        // Slow path: check circles_membership via the user's personal-circle single_id
        try {
            $singleId = $this->resolveUserSingleId($uid, $db);
            if (!$singleId) {
                return false;
            }
            $qb  = $db->getQueryBuilder();
            $res = $qb->select($qb->func()->count('*', 'cnt'))
                ->from('circles_membership')
                ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('single_id', $qb->createNamedParameter($singleId)))
                ->executeQuery();
            $cnt = (int)$res->fetchOne();
            $res->closeCursor();
            $this->logger->debug('[MemberService] isEffectiveMember via circles_membership', [
                'teamId' => $teamId, 'uid' => $uid, 'found' => $cnt > 0, 'app' => Application::APP_ID,
            ]);
            return $cnt > 0;
        } catch (\Throwable $e) {
            $this->logger->warning('[MemberService] isEffectiveMember circles_membership lookup failed', [
                'teamId' => $teamId, 'uid' => $uid, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * Check whether the current user is a DIRECT member (user_type=1 row in
     * circles_member). Used by the browse endpoint to return isDirectMember
     * so the frontend can show/disable the Leave button appropriately.
     */
    public function isCurrentUserDirectMember(string $teamId): bool {
        $user = $this->userSession->getUser();
        if (!$user) {
            return false;
        }
        $db = $this->container->get(\OCP\IDBConnection::class);
        return $this->getMemberLevelFromDb($db, $teamId, $user->getUID()) > 0;
    }

    // -------------------------------------------------------------------------
    // resolveUserSingleId (public — also called by TeamService for browse query)
    // -------------------------------------------------------------------------

    public function resolveUserSingleId(string $uid, \OCP\IDBConnection $db): ?string {

        // Strategy 1: NC preferences — 'circles' app, 'userSingleId' key.
        // NC QueryBuilder adds the oc_ prefix automatically, so we pass bare names.
        // NC 30+ uses 'user_preferences'; older versions use 'preferences'.
        foreach (['user_preferences', 'preferences'] as $prefTable) {
            try {
                $pQb  = $db->getQueryBuilder();

                // Column names differ between tables
                $userCol = ($prefTable === 'user_preferences') ? 'userid' : 'userid';
                $appCol  = ($prefTable === 'user_preferences') ? 'appid'  : 'appid';
                $keyCol  = ($prefTable === 'user_preferences') ? 'configkey' : 'configkey';
                $valCol  = ($prefTable === 'user_preferences') ? 'configvalue' : 'configvalue';

                $pRes = $pQb->select($valCol)
                    ->from($prefTable)
                    ->where($pQb->expr()->eq($userCol, $pQb->createNamedParameter($uid)))
                    ->andWhere($pQb->expr()->eq($appCol, $pQb->createNamedParameter('circles')))
                    ->andWhere($pQb->expr()->eq($keyCol, $pQb->createNamedParameter('userSingleId')))
                    ->setMaxResults(1)
                    ->executeQuery();
                $pRow = $pRes->fetch();
                $pRes->closeCursor();
                if ($pRow && !empty($pRow[$valCol])) {
                    $this->logger->info('[MemberService] resolveUserSingleId: found via preferences', [
                        'uid' => $uid, 'table' => $prefTable, 'app' => Application::APP_ID,
                    ]);
                    return (string)$pRow[$valCol];
                }
            } catch (\Throwable $e) {
                // Table may not exist on this NC version — try next
            }
        }

        // Strategy 2: DB join on circles_circle + circles_member.
        // CFG_SINGLE = 2048 (bit 11). Personal circles always have this bit set.
        // The user is the owner (level=9) of their own personal circle.
        try {
            $cQb  = $db->getQueryBuilder();
            $cRes = $cQb->select('c.unique_id')
                ->from('circles_circle', 'c')
                ->join('c', 'circles_member', 'm', 'c.unique_id = m.circle_id')
                ->where($cQb->expr()->eq('m.user_id',   $cQb->createNamedParameter($uid)))
                ->andWhere($cQb->expr()->eq('m.user_type', $cQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($cQb->expr()->eq('m.level',   $cQb->createNamedParameter(9, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere(
                    // CFG_SINGLE bit (2048) is set: config & 2048 > 0
                    // QB does not support bitwise AND natively — use raw expression
                    $cQb->expr()->gt(
                        $cQb->createFunction('(c.`config` & 2048)'),
                        $cQb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                    )
                )
                ->setMaxResults(1)
                ->executeQuery();
            $cRow = $cRes->fetch();
            $cRes->closeCursor();
            if ($cRow && !empty($cRow['unique_id'])) {
                $this->logger->info('[MemberService] resolveUserSingleId: found via DB join', [
                    'uid' => $uid, 'app' => Application::APP_ID,
                ]);
                return (string)$cRow['unique_id'];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[MemberService] resolveUserSingleId: DB join failed', [
                'uid' => $uid, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Pending requests (admin)
    // -------------------------------------------------------------------------

    /**
     * Get pending membership requests for a team. Requires admin or owner level.
     */
    public function getPendingRequests(string $teamId): array {

        $this->requireAdminLevel($teamId);

        // Read directly from DB — getCircle() via CirclesManager fails on circles
        // with a non-zero config bitmask (hidden from probeCircles), causing this
        // method to return empty even when pending requests exist.
        $db  = $this->container->get(\OCP\IDBConnection::class);
        $qb  = $db->getQueryBuilder();
        $res = $qb->select('m.user_id', 'm.level', 'm.status', 'u.displayname')
            ->from('circles_member', 'm')
            ->leftJoin('m', 'users', 'u', 'm.user_id = u.uid')
            ->where($qb->expr()->eq('m.circle_id',  $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('m.status',   $qb->createNamedParameter('Requesting')))
            ->andWhere($qb->expr()->eq('m.user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->executeQuery();

        $pending = [];
        while ($row = $res->fetch()) {
            $userId      = (string)$row['user_id'];
            $displayName = !empty($row['displayname']) ? $row['displayname'] : $userId;
            $pending[] = [
                'userId'      => $userId,
                'displayName' => $displayName,
                'role'        => 'Member',
                'level'       => 1,
                'status'      => 'Requesting',
            ];
        }
        $res->closeCursor();

        $this->logger->info('[MemberService] getPendingRequests: found via DB', [
            'teamId' => $teamId, 'count' => count($pending), 'app' => Application::APP_ID,
        ]);

        return $pending;
    }

    /**
     * Approve a pending membership request. Requires admin or owner level.
     */
    public function approveRequest(string $teamId, string $userId): void {

        $this->requireAdminLevel($teamId);

        $user = $this->userSession->getUser();

        // Look up the pending member's single_id from DB to get their memberId,
        // then use Circles MemberService::addMember() — same as memberConfirm OCS route.
        $db = $this->container->get(\OCP\IDBConnection::class);
        $mQb = $db->getQueryBuilder();
        $mRes = $mQb->select('single_id')
            ->from('circles_member')
            ->where($mQb->expr()->eq('circle_id', $mQb->createNamedParameter($teamId)))
            ->andWhere($mQb->expr()->eq('user_id', $mQb->createNamedParameter($userId)))
            ->andWhere($mQb->expr()->eq('status', $mQb->createNamedParameter('Requesting')))
            ->setMaxResults(1)
            ->executeQuery();
        $mRow = $mRes->fetch();
        $mRes->closeCursor();

        if (!$mRow) {
            throw new \Exception('Pending request not found');
        }

        try {
            // Approve by flipping status to 'Member' directly in DB.
            // The Circles API getMemberById()/addMember() fails on hidden circles
            // (non-zero config bitmask) — same root cause as all other Circles API
            // failures this session. A direct UPDATE is safe: requireAdminLevel()
            // and the Requesting check above have already validated the operation.
            $approveQb = $db->getQueryBuilder();
            $approveQb->update('circles_member')
                ->set('status', $approveQb->createNamedParameter('Member'))
                ->set('level',  $approveQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->where($approveQb->expr()->eq('circle_id', $approveQb->createNamedParameter($teamId)))
                ->andWhere($approveQb->expr()->eq('user_id',  $approveQb->createNamedParameter($userId)))
                ->andWhere($approveQb->expr()->eq('status',  $approveQb->createNamedParameter('Requesting')))
                ->executeStatement();

            $this->logger->info('[MemberService] approveRequest: member approved via direct DB update', [
                'teamId' => $teamId, 'userId' => $userId, 'app' => Application::APP_ID,
            ]);

            // Audit: admin approved a pending join request. Logged AFTER the
            // status flip so a failed approval does not produce a phantom row.
            // The mirror job will also see Circles' `member_added` activity row
            // and dedupe against this entry.
            $this->auditService->log(
                $teamId,
                'join.approved',
                $user ? $user->getUID() : null,
                'member',
                $userId,
                null,
            );
        } catch (\Exception $e) {
            $this->logger->error('[MemberService] Error approving request', [
                'teamId'    => $teamId,
                'userId'    => $userId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            throw new \Exception('Failed to approve request: ' . $e->getMessage());
        }
    }

    /**
     * Reject a pending membership request. Requires admin or owner level.
     */
    public function rejectRequest(string $teamId, string $userId): void {

        $this->requireAdminLevel($teamId);

        $user = $this->userSession->getUser();

        // Look up the pending member's single_id from DB, then use
        // Circles MemberService::removeMember() — same as memberRemove OCS route.
        $db = $this->container->get(\OCP\IDBConnection::class);
        $mQb = $db->getQueryBuilder();
        $mRes = $mQb->select('single_id')
            ->from('circles_member')
            ->where($mQb->expr()->eq('circle_id', $mQb->createNamedParameter($teamId)))
            ->andWhere($mQb->expr()->eq('user_id', $mQb->createNamedParameter($userId)))
            ->andWhere($mQb->expr()->eq('status', $mQb->createNamedParameter('Requesting')))
            ->setMaxResults(1)
            ->executeQuery();
        $mRow = $mRes->fetch();
        $mRes->closeCursor();

        if (!$mRow) {
            throw new \Exception('Pending request not found');
        }

        try {
            // Reject by deleting the Requesting row directly — same rationale as
            // removeMember() and approveRequest(): Circles API fails on hidden circles.
            $rejectQb = $db->getQueryBuilder();
            $rejectQb->delete('circles_member')
                ->where($rejectQb->expr()->eq('circle_id', $rejectQb->createNamedParameter($teamId)))
                ->andWhere($rejectQb->expr()->eq('user_id',  $rejectQb->createNamedParameter($userId)))
                ->andWhere($rejectQb->expr()->eq('status',  $rejectQb->createNamedParameter('Requesting')))
                ->executeStatement();

            $this->logger->info('[MemberService] rejectRequest: request rejected via direct DB delete', [
                'teamId' => $teamId, 'userId' => $userId, 'app' => Application::APP_ID,
            ]);

            // Audit: admin rejected a pending join request. There is NO
            // corresponding Circles activity for this — the row is silently
            // deleted and no `member_*` event fires — so this is the only
            // place a `join.rejected` event is ever recorded.
            $this->auditService->log(
                $teamId,
                'join.rejected',
                $user ? $user->getUID() : null,
                'member',
                $userId,
                null,
            );
        } catch (\Exception $e) {
            $this->logger->error('[MemberService] Error rejecting request', [
                'teamId'    => $teamId,
                'userId'    => $userId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            throw new \Exception('Failed to reject request: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // User search and invite-type settings
    // -------------------------------------------------------------------------

    /**
     * Search users by display name or user ID (for member picker).
     * Respects the admin 'inviteTypes' setting.
     */
    public function searchUsers(string $query, int $limit = 10): array {

        $currentUser  = $this->userSession->getUser();
        $currentUid   = $currentUser ? $currentUser->getUID() : '';
        $allowedTypes = $this->getAllowedInviteTypes();
        $results      = [];

        // Local NC users (Circles member type 1).
        // Use searchDisplayName() + search() — both are DB-backed with LIKE queries
        // and respect the $limit parameter. Avoids iterating ALL users in PHP.
        if (in_array('user', $allowedTypes, true)) {
            $seen = [];

            // Search by display name first (most user-friendly matches).
            $byDisplay = $this->userManager->searchDisplayName($query, $limit + 1);
            foreach ($byDisplay as $user) {
                if (count($results) >= $limit) {
                    break;
                }
                if ($user->getUID() === $currentUid) {
                    continue;
                }
                $seen[$user->getUID()] = true;
                $results[] = [
                    'id'          => $user->getUID(),
                    'displayName' => $user->getDisplayName() ?: $user->getUID(),
                    'type'        => 'user',
                    'icon'        => 'user',
                ];
            }

            // Also search by user ID to catch UIDs that don't match the display name.
            if (count($results) < $limit) {
                $byUid = $this->userManager->search($query, $limit + 1);
                foreach ($byUid as $user) {
                    if (count($results) >= $limit) {
                        break;
                    }
                    if ($user->getUID() === $currentUid) {
                        continue;
                    }
                    if (isset($seen[$user->getUID()])) {
                        continue; // already added from display name search
                    }
                    $results[] = [
                        'id'          => $user->getUID(),
                        'displayName' => $user->getDisplayName() ?: $user->getUID(),
                        'type'        => 'user',
                        'icon'        => 'user',
                    ];
                }
            }
        }

        // NC Groups (Circles member type 2)
        if (in_array('group', $allowedTypes, true)) {
            try {
                $groupManager = $this->container->get(\OCP\IGroupManager::class);
                $groups = $groupManager->search($query, $limit);
                foreach ($groups as $group) {
                    if (count($results) >= $limit * 2) {
                        break;
                    }
                    $results[] = [
                        'id'          => $group->getGID(),
                        'displayName' => $group->getDisplayName() ?: $group->getGID(),
                        'type'        => 'group',
                        'icon'        => 'group',
                    ];
                }
            } catch (\Throwable $e) {
                // GroupManager not available
            }
        }

        // Email — if query looks like an email address and email type is allowed
        if (in_array('email', $allowedTypes, true) && filter_var($query, FILTER_VALIDATE_EMAIL)) {
            $results[] = [
                'id'          => $query,
                'displayName' => $query,
                'type'        => 'email',
                'icon'        => 'email',
            ];
        }

        // Federated user — if query contains '@' with domain and federated type is allowed
        // Format: user@remote.example.com
        if (in_array('federated', $allowedTypes, true)
            && preg_match('/^[^@]+@[^@]+\.[^@]+$/', $query)
            && !filter_var($query, FILTER_VALIDATE_EMAIL)
        ) {
            $results[] = [
                'id'          => $query,
                'displayName' => $query,
                'type'        => 'federated',
                'icon'        => 'federation',
            ];
        }

        // Circles / Teams — search user-created circles by name (source=16).
        // Always shown when circles app is available; no separate admin toggle needed
        // since they are just other teams within the same NC instance.
        // Excludes personal circles (name starts with 'user:') and
        // group-backed circles (name starts with 'group:').
        if ($this->appManager->isInstalled('circles')) {
            try {
                $db   = $this->container->get(\OCP\IDBConnection::class);
                $cQb  = $db->getQueryBuilder();
                $cRes = $cQb->select('unique_id', 'name')
                    ->from('circles_circle')
                    ->where(
                        $cQb->expr()->like(
                            'name',
                            $cQb->createNamedParameter('%' . $db->escapeLikeParameter($query) . '%')
                        )
                    )
                    ->andWhere($cQb->expr()->eq('source', $cQb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->setMaxResults($limit)
                    ->executeQuery();

                while ($cRow = $cRes->fetch()) {
                    $name = (string)$cRow['name'];
                    // Skip personal and group-backed circles
                    if (str_starts_with($name, 'user:') || str_starts_with($name, 'group:')) {
                        continue;
                    }
                    if (count($results) >= $limit * 3) {
                        break;
                    }
                    $results[] = [
                        'id'          => (string)$cRow['unique_id'],
                        'displayName' => $name,
                        'type'        => 'circle',
                        'icon'        => 'circle',
                    ];
                }
                $cRes->closeCursor();
            } catch (\Throwable $e) {
                $this->logger->warning('[MemberService] searchUsers: circle search failed', [
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        return $results;
    }

    /**
     * Return the currently allowed invite types as an array.
     * Used by searchUsers() to filter results.
     */
    public function getAllowedInviteTypes(): array {
        $config = $this->container->get(\OCP\IConfig::class);
        $raw    = $config->getAppValue(Application::APP_ID, 'inviteTypes', 'user,group');
        return array_filter(array_map('trim', explode(',', $raw)));
    }

    /**
     * Check whether the current user is allowed to create teams, based on
     * the admin setting 'createTeamGroup'. If the setting is empty, everyone
     * can create. Otherwise the user must be a member of at least one of the
     * configured NC groups (stored as a comma-separated list).
     */
    public function canCurrentUserCreateTeam(): bool {
        $user = $this->userSession->getUser();
        if (!$user) {
            return false;
        }

        $config   = $this->container->get(\OCP\IConfig::class);
        $rawGroup = trim($config->getAppValue(Application::APP_ID, 'createTeamGroup', ''));

        if ($rawGroup === '') {
            return true; // no restriction set
        }

        $groupManager = $this->container->get(\OCP\IGroupManager::class);
        $gids         = array_filter(array_map('trim', explode(',', $rawGroup)));

        foreach ($gids as $gid) {
            if ($groupManager->isInGroup($user->getUID(), $gid)) {
                return true;
            }
        }

        return false;
    }
}
