<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
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
     * Get team members with roles.
     *
     * @throws \Exception if user is not authenticated or team not found
     */
    public function getTeamMembers(string $teamId): array {

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        // Read directly from DB — getCircle() via CirclesManager fails on circles with
        // a non-zero config bitmask. Filtering to status='Member' ensures Requesting
        // rows don't appear in the member list (they appear in getPendingRequests instead).
        $db  = $this->container->get(\OCP\IDBConnection::class);
        $qb  = $db->getQueryBuilder();
        $res = $qb->select('m.user_id', 'm.level', 'm.status', 'u.displayname')
            ->from('circles_member', 'm')
            ->leftJoin('m', 'users', 'u', 'm.user_id = u.uid')
            ->where($qb->expr()->eq('m.circle_id',  $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('m.status',   $qb->createNamedParameter('Member')))
            ->andWhere($qb->expr()->eq('m.user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->orderBy('m.level', 'DESC')
            ->executeQuery();

        $members = [];
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

            $members[] = [
                'userId'      => $userId,
                'displayName' => $displayName,
                'role'        => $role,
                'level'       => $level,
                'status'      => 'Member',
            ];
        }
        $res->closeCursor();

        $this->logger->info('[MemberService] getTeamMembers: loaded via DB', [
            'teamId' => $teamId, 'count' => count($members), 'app' => Application::APP_ID,
        ]);

        return $members;
    }

    /**
     * Update a member's level in a team. Requires admin or owner level.
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

        if ($level === 0) {
            throw new \Exception('You are not a member of this team');
        }
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
     * @throws \Exception if owner tries to leave with members still in the team
     */
    public function leaveTeam(string $teamId): void {

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        // Use CircleService::circleLeave() — same path as the Circles UI.
        // This avoids the startSession()/stopSession() pattern that fails when
        // the circle config bitmask is non-zero (circle hidden from probeCircles).
        try {
            $circleService = $this->container->get(\OCA\Circles\Service\CircleService::class);
            $federatedUserService = $this->container->get(\OCA\Circles\Service\FederatedUserService::class);
            $federatedUserService->setLocalCurrentUser($user);

            // Owner guard: check via DB before attempting leave
            $db = $this->container->get(\OCP\IDBConnection::class);
            $level = $this->getMemberLevelFromDb($db, $teamId, $user->getUID());
            if ($level >= 9) {
                // Count other members to block owner leaving a non-empty team
                $cntQb = $db->getQueryBuilder();
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

            $circleService->circleLeave($teamId);
            $this->logger->info('[MemberService] leaveTeam: circleLeave succeeded', [
                'uid'    => $user->getUID(),
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
    public function removeMember(string $teamId, string $userId): void {

        $this->requireAdminLevel($teamId);

        $user = $this->userSession->getUser();

        // Look up the target member's single_id and level from DB.
        // This avoids getCircle() failing on non-zero config circles, and gives
        // us the memberId needed by Circles MemberService::removeMember().
        $db = $this->container->get(\OCP\IDBConnection::class);
        $mQb = $db->getQueryBuilder();
        $mRes = $mQb->select('single_id', 'level')
            ->from('circles_member')
            ->where($mQb->expr()->eq('circle_id', $mQb->createNamedParameter($teamId)))
            ->andWhere($mQb->expr()->eq('user_id',   $mQb->createNamedParameter($userId)))
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

        try {
            // Delete directly from circles_member. The Circles MemberService::removeMember()
            // API fails on hidden circles (non-zero config bitmask) because getMemberById()
            // cannot find the member from the initiator's perspective. A direct DB delete
            // is safe here — we have already verified admin level and target existence above.
            $delQb = $db->getQueryBuilder();
            $delQb->delete('circles_member')
                ->where($delQb->expr()->eq('circle_id', $delQb->createNamedParameter($teamId)))
                ->andWhere($delQb->expr()->eq('user_id',   $delQb->createNamedParameter($userId)))
                ->andWhere($delQb->expr()->eq('user_type', $delQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $this->logger->info('[MemberService] removeMember: member removed via direct DB delete', [
                'teamId' => $teamId, 'userId' => $userId, 'app' => Application::APP_ID,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[MemberService] Error removing member', [
                'teamId'    => $teamId,
                'userId'    => $userId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            throw $e;
        }
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
                    'group'     => 2,  // NC group
                    'federated' => 4,  // federated NC user
                    'email'     => 7,  // email (requires Circles federation)
                    default     => 1,  // local NC user
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

        // Notify all team admins and owners that a new join request has arrived.
        $this->sendJoinRequestNotification($teamId, $uid, $db);

        // If the circle is open-join (CFG_OPEN bit 1 is set in config), automatically
        // approve the request by flipping status to 'Member'. This matches Circles'
        // own behaviour for open circles where no admin approval is required.
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
                $approveQb = $db->getQueryBuilder();
                $approveQb->update('circles_member')
                    ->set('status', $approveQb->createNamedParameter('Member'))
                    ->set('level',  $approveQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    ->where($approveQb->expr()->eq('circle_id', $approveQb->createNamedParameter($teamId)))
                    ->andWhere($approveQb->expr()->eq('user_id',   $approveQb->createNamedParameter($uid)))
                    ->andWhere($approveQb->expr()->eq('status',    $approveQb->createNamedParameter('Requesting')))
                    ->executeStatement();
                $this->logger->info('[MemberService] requestJoinTeam: open circle — auto-approved membership', [
                    'uid' => $uid, 'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
            }
        } catch (\Throwable $e) {
            // Non-fatal — user is Requesting and an admin can approve manually
            $this->logger->warning('[MemberService] requestJoinTeam: auto-approve check failed', [
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

    private function resolveUserSingleId(string $uid, \OCP\IDBConnection $db): ?string {

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
