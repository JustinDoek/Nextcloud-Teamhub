<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\IUserSession;
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
        $this->logger->debug('[MemberService] constructed', ['app' => Application::APP_ID]);
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
        $this->logger->debug('[MemberService] getTeamMembers', [
            'teamId' => $teamId,
            'app'    => Application::APP_ID,
        ]);

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $circlesManager = $this->getCirclesManager();
        $federatedUser  = $circlesManager->getFederatedUser($user->getUID(), 1);
        $circlesManager->startSession($federatedUser);

        try {
            $circle = $circlesManager->getCircle($teamId);
            if (!$circle) {
                throw new \Exception('Team not found or access denied');
            }

            $members = [];
            if (method_exists($circle, 'getMembers')) {
                foreach ($circle->getMembers() as $member) {
                    try {
                        $members[] = $this->memberToArray($member);
                    } catch (\Exception $e) {
                        $this->logger->warning('[MemberService] Error processing member', [
                            'exception' => $e,
                            'app'       => Application::APP_ID,
                        ]);
                    }
                }
            }

            $this->logger->debug('[MemberService] getTeamMembers result', [
                'teamId'      => $teamId,
                'memberCount' => count($members),
                'app'         => Application::APP_ID,
            ]);

            return $members;

        } finally {
            $circlesManager->stopSession();
        }
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
        $this->logger->debug('[MemberService] updateMemberLevel', [
            'teamId'   => $teamId,
            'userId'   => $userId,
            'newLevel' => $newLevel,
            'app'      => Application::APP_ID,
        ]);

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

        $this->logger->info('[MemberService] updateMemberLevel done', [
            'teamId'   => $teamId,
            'userId'   => $userId,
            'newLevel' => $newLevel,
            'app'      => Application::APP_ID,
        ]);

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

        return [
            'userId'      => $userId,
            'displayName' => $displayName,
            'role'        => $role,
            'level'       => $level,
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
        $this->logger->debug('[MemberService] leaveTeam', [
            'teamId' => $teamId,
            'app'    => Application::APP_ID,
        ]);

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $manager       = $this->getCirclesManager();
        $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
        $manager->startSession($federatedUser);

        try {
            $circle  = $manager->getCircle($teamId);
            $members = $circle->getMembers();
            $currentMember = null;

            foreach ($members as $member) {
                $userId = method_exists($member, 'getUserId') ? $member->getUserId() : null;
                if ($userId === $user->getUID()) {
                    $currentMember = $member;
                    break;
                }
            }

            if (!$currentMember) {
                throw new \Exception('You are not a member of this team');
            }

            if ($currentMember->getLevel() >= 9 && count($members) > 1) {
                throw new \Exception('Team owner cannot leave. Transfer ownership or delete the team first.');
            }

            $manager->removeMember($currentMember->getId());

        } catch (\Exception $e) {
            $this->logger->error('[MemberService] Error leaving team', [
                'teamId'    => $teamId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            throw $e;
        } finally {
            $manager->stopSession();
        }
    }

    /**
     * Remove a member from a team. Requires admin or owner level.
     *
     * @throws \Exception if target is the owner or not found
     */
    public function removeMember(string $teamId, string $userId): void {
        $this->logger->debug('[MemberService] removeMember', [
            'teamId' => $teamId,
            'userId' => $userId,
            'app'    => Application::APP_ID,
        ]);

        $this->requireAdminLevel($teamId);

        $user    = $this->userSession->getUser();
        $manager = $this->getCirclesManager();
        $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
        $manager->startSession($federatedUser);

        try {
            $circle  = $manager->getCircle($teamId);

            $targetMember = null;
            foreach ($circle->getMembers() as $member) {
                $this->logger->debug('[MemberService] removeMember scanning member', [
                    'memberId' => method_exists($member, 'getUserId') ? $member->getUserId() : 'unknown',
                    'app'      => Application::APP_ID,
                ]);
                $mId = method_exists($member, 'getUserId') ? $member->getUserId() : null;
                if ($mId === $userId) {
                    $targetMember = $member;
                    break;
                }
            }

            if (!$targetMember) {
                throw new \Exception('Member not found in this team');
            }

            if ($targetMember->getLevel() >= 9) {
                throw new \Exception('Cannot remove the team owner');
            }

            $manager->removeMember($targetMember->getId());

        } catch (\Exception $e) {
            $this->logger->error('[MemberService] Error removing member', [
                'teamId'    => $teamId,
                'userId'    => $userId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            throw $e;
        } finally {
            $manager->stopSession();
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
        $this->logger->debug('[MemberService] inviteMembers', [
            'teamId' => $teamId,
            'count'  => count($members),
            'app'    => Application::APP_ID,
        ]);

        // Moderator (level >= 4) or above may invite — matches the controller gate.
        // Previously this called requireAdminLevel() which silently rejected moderators.
        $this->requireModeratorLevel($teamId);

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $manager       = $this->getCirclesManager();
        $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
        $manager->startSession($federatedUser);

        $results = [];
        try {
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
                    $invitee = $manager->getFederatedUser($memberId, $memberType);
                    $manager->addMember($teamId, $invitee);
                    $results[$memberId] = 'invited';
                    $this->logger->debug('[MemberService] invited member', [
                        'teamId'     => $teamId,
                        'memberId'   => $memberId,
                        'memberType' => $memberType,
                        'app'        => Application::APP_ID,
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
        } finally {
            $manager->stopSession();
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
        $this->logger->debug('[MemberService] requestJoinTeam', [
            'teamId' => $teamId,
            'app'    => Application::APP_ID,
        ]);

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        // First try via Circles API
        try {
            $manager       = $this->getCirclesManager();
            $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
            $manager->startSession($federatedUser);
            try {
                $manager->addMember($teamId, $federatedUser);
                return;
            } finally {
                $manager->stopSession();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[MemberService] requestJoinTeam via API failed, trying direct DB insert', [
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

        // Generate a single_id in Circles format (21-char alphanumeric)
        $chars    = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $singleId = '';
        for ($i = 0; $i < 21; $i++) {
            $singleId .= $chars[random_int(0, strlen($chars) - 1)];
        }

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
            'joined'     => $qb->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
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
            $this->logger->info('[MemberService] requestJoinTeam DB fallback insert succeeded', [
                'teamId' => $teamId,
                'uid'    => $uid,
                'app'    => Application::APP_ID,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[MemberService] requestJoinTeam DB fallback failed', [
                'teamId' => $teamId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            throw new \Exception('Failed to request team membership: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Pending requests (admin)
    // -------------------------------------------------------------------------

    /**
     * Get pending membership requests for a team. Requires admin or owner level.
     */
    public function getPendingRequests(string $teamId): array {
        $this->logger->debug('[MemberService] getPendingRequests', [
            'teamId' => $teamId,
            'app'    => Application::APP_ID,
        ]);

        $this->requireAdminLevel($teamId);

        $user          = $this->userSession->getUser();
        $manager       = $this->getCirclesManager();
        $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
        $manager->startSession($federatedUser);

        try {
            $circle  = $manager->getCircle($teamId);
            $pending = [];

            if (method_exists($circle, 'getMembers')) {
                foreach ($circle->getMembers() as $member) {
                    // Status 4 = Requesting in Circles API
                    $status = method_exists($member, 'getStatus') ? $member->getStatus() : null;
                    if ($status === 4) {
                        $pending[] = $this->memberToArray($member);
                    }
                }
            }

            $this->logger->debug('[MemberService] getPendingRequests result', [
                'teamId'       => $teamId,
                'pendingCount' => count($pending),
                'app'          => Application::APP_ID,
            ]);

            return $pending;

        } catch (\Exception $e) {
            $this->logger->error('[MemberService] Error getting pending requests', [
                'teamId'    => $teamId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            throw new \Exception('Failed to get pending requests: ' . $e->getMessage());
        } finally {
            $manager->stopSession();
        }
    }

    /**
     * Approve a pending membership request. Requires admin or owner level.
     */
    public function approveRequest(string $teamId, string $userId): void {
        $this->logger->debug('[MemberService] approveRequest', [
            'teamId' => $teamId,
            'userId' => $userId,
            'app'    => Application::APP_ID,
        ]);

        $this->requireAdminLevel($teamId);

        $user          = $this->userSession->getUser();
        $manager       = $this->getCirclesManager();
        $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
        $manager->startSession($federatedUser);

        try {
            $circle = $manager->getCircle($teamId);

            $targetMember = null;
            foreach ($circle->getMembers() as $member) {
                $mId = method_exists($member, 'getUserId') ? $member->getUserId() : null;
                if ($mId === $userId) {
                    $targetMember = $member;
                    break;
                }
            }

            if (!$targetMember) {
                throw new \Exception('Pending request not found');
            }

            $manager->addMember($teamId, $targetMember);

        } catch (\Exception $e) {
            $this->logger->error('[MemberService] Error approving request', [
                'teamId'    => $teamId,
                'userId'    => $userId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            throw new \Exception('Failed to approve request: ' . $e->getMessage());
        } finally {
            $manager->stopSession();
        }
    }

    /**
     * Reject a pending membership request. Requires admin or owner level.
     */
    public function rejectRequest(string $teamId, string $userId): void {
        $this->logger->debug('[MemberService] rejectRequest', [
            'teamId' => $teamId,
            'userId' => $userId,
            'app'    => Application::APP_ID,
        ]);

        $this->requireAdminLevel($teamId);

        $user          = $this->userSession->getUser();
        $manager       = $this->getCirclesManager();
        $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
        $manager->startSession($federatedUser);

        try {
            $circle = $manager->getCircle($teamId);

            $targetMember = null;
            foreach ($circle->getMembers() as $member) {
                $mId = method_exists($member, 'getUserId') ? $member->getUserId() : null;
                if ($mId === $userId) {
                    $targetMember = $member;
                    break;
                }
            }

            if (!$targetMember) {
                throw new \Exception('Pending request not found');
            }

            $manager->removeMember($targetMember->getId());

        } catch (\Exception $e) {
            $this->logger->error('[MemberService] Error rejecting request', [
                'teamId'    => $teamId,
                'userId'    => $userId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            throw new \Exception('Failed to reject request: ' . $e->getMessage());
        } finally {
            $manager->stopSession();
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
        $this->logger->debug('[MemberService] searchUsers', [
            'query' => $query,
            'limit' => $limit,
            'app'   => Application::APP_ID,
        ]);

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

        $this->logger->debug('[MemberService] searchUsers result', [
            'query'       => $query,
            'resultCount' => count($results),
            'app'         => Application::APP_ID,
        ]);

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
