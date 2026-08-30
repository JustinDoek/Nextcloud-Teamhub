<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\CirclesConfig;
use OCA\TeamHub\Db\PendingDeletionMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\TalkService;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\PropertyDoesNotExistException;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\UserStatus\IManager as IUserStatusManager;
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

    /**
     * How many times to re-apply the group/circle auto-confirm before giving up
     * and warning (v4.7.12, GitHub #87). Three is enough for the interleavings
     * observed — the competing write is Circles' own insertOrUpdate() inside the
     * same request, not a separate async one — and small enough that the worst
     * case adds well under a second to an invite that was previously failing
     * silently. Only the failure path ever waits.
     */
    private const CONFIRM_MAX_ATTEMPTS = 3;

    /** Pause between auto-confirm attempts, microseconds. */
    private const CONFIRM_RETRY_DELAY_US = 150000;

    public function __construct(
        private ResourceService      $resourceService,
        private TalkService          $talkService,
        private IUserSession         $userSession,
        private IAppManager          $appManager,
        private IUserManager         $userManager,
        private ContainerInterface   $container,
        private LoggerInterface      $logger,
        private AuditService         $auditService,
        private PendingDeletionMapper $pendingMapper,
        // v4.6.26 — owns the "Mail or mailto:" question for the whole app.
        private MailClientService    $mailClientService,
        // v4.7.9 — every membership change ends by asking this to bring the
        // team's connected resources back in line. See GitHub #87.
        private ResourceMembershipService $resourceMembership,
    ) {
    }

    /**
     * Throw if the team is in a pending-deletion state.
     * Call this at the start of every write operation that targets a team.
     */
    private function assertTeamNotPendingDeletion(string $teamId): void {
        if ($this->pendingMapper->isTeamPendingDeletion($teamId)) {
            throw new \Exception(
                'This team is pending deletion and does not accept changes. Contact your administrator to restore it.',
                409
            );
        }
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
    // Lazy helpers for IAccountManager / IUserStatusManager
    //
    // Fetched via the container (not via constructor injection) to keep the
    // MemberService constructor signature stable — every change to it ripples
    // through tests and any subclass. Both services have shipped since NC 25.
    // Any failure to resolve is treated as "feature unavailable" and the
    // members-widget endpoint silently degrades (no email/phone or no live
    // status), rather than the whole request failing.
    // -------------------------------------------------------------------------

    /** @var IAccountManager|null */
    private $accountManager = null;
    /** @var bool */
    private $accountManagerResolveFailed = false;

    private function getAccountManager(): ?IAccountManager {
        if ($this->accountManager !== null) {
            return $this->accountManager;
        }
        if ($this->accountManagerResolveFailed) {
            return null;
        }
        try {
            $this->accountManager = $this->container->get(IAccountManager::class);
            return $this->accountManager;
        } catch (\Throwable $e) {
            $this->accountManagerResolveFailed = true;
            return null;
        }
    }

    /** @var IUserStatusManager|null */
    private $userStatusManager = null;
    /** @var bool */
    private $userStatusResolveFailed = false;

    private function getUserStatusManager(): ?IUserStatusManager {
        if ($this->userStatusManager !== null) {
            return $this->userStatusManager;
        }
        if ($this->userStatusResolveFailed) {
            return null;
        }
        try {
            $this->userStatusManager = $this->container->get(IUserStatusManager::class);
            return $this->userStatusManager;
        } catch (\Throwable $e) {
            $this->userStatusResolveFailed = true;
            return null;
        }
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

        $this->logger->debug('[TeamHub][MemberService] getTeamMembers: loaded', [
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

        // Flag whether the current user is a DIRECT member (user_type=1 row),
        // and capture the level for admin-gated UI affordances.
        // Indirect members (added via group / sub-team) have level=0 here, which
        // is correct: they cannot perform admin actions. requireAdminLevel()
        // applies the same direct-row rule on the backend.
        $currentUserLevel = $this->getMemberLevelFromDb($db, $teamId, $user->getUID());
        $isDirectMember = $currentUserLevel > 0;

        return [
            'members'             => $members,
            'memberships'         => $memberships,
            'effective_count'     => $effectiveCount,
            'has_more'            => $effectiveCount > count($members),
            'is_direct_member'    => $isDirectMember,
            'current_user_level'  => $currentUserLevel,
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

        $list = $this->resolveEffectiveMembers($teamId);

        // Step 4: enrich each row with email, phone, and live NC status.
        //
        // Used by the members widget (Members + Tomorrow + Search tabs). The
        // @mention autocomplete consumer reads only userId/displayName and is
        // unaffected by the extra fields.
        //
        // - Email: IUser::getEMailAddress() returns the stored address, no
        //   visibility check applies. Empty/null if not set.
        // - Phone: read via IAccountManager respecting account visibility.
        //   Only returned when scope is LOCAL/FEDERATED/PUBLISHED — never
        //   for PRIVATE.
        // - ncStatus: live status from IUserStatusManager, batched in one
        //   call. Status is one of 'online' | 'away' | 'dnd' | 'busy' |
        //   'invisible' | 'offline'. Message may be null. Icon may be null.
        $list = $this->enrichMembersForWidget($list);

        // Sort by display name, case-insensitive
        usort($list, fn ($a, $b) => strcasecmp($a['displayName'], $b['displayName']));

        $this->logger->info('[TeamHub][MemberService] getAllEffectiveMembers: resolved', [
            'teamId' => $teamId, 'count' => count($list), 'app' => Application::APP_ID,
        ]);

        return $list;
    }

    /**
     * The effective member set of a team, as `[{ userId, displayName }]`.
     *
     * v4.6.26 — extracted verbatim from `getAllEffectiveMembers()` so a caller
     * that only needs *who is in the team* can have it without paying for the
     * widget enrichment pass, which does a per-user `IAccountManager` read for
     * the phone number and a live user-status fetch. Building a mailto: link
     * needs neither.
     *
     * **No authorisation happens here.** It is a private helper and every
     * caller gates first — `getAllEffectiveMembers()` and
     * `getTeamMemberEmailAddresses()` both call `requireMemberLevel()` before
     * reaching this. Any future caller owes the same.
     *
     * @return list<array{userId: string, displayName: string}>
     */
    private function resolveEffectiveMembers(string $teamId): array {

        $db = $this->container->get(\OCP\IDBConnection::class);

        $seen = [];
        $list = [];

        // ─────────────────────────────────────────────────────────────────────
        // Approach: circles_membership is Circles' own denormalized cache of
        // "every single_id reachable from this circle". For team Sugar this
        // contains every reachable user single_id for the circle, including
        // members joined directly and members joined via a nested group.
        // We resolve each user single_id to a NC user by
        // joining back to circles_member with user_type=1 (the row Circles
        // writes for each personal user circle).
        //
        // This is the proven approach — verified in production SQL that
        // `circles_membership` contains all three users' single_ids for Sugar
        // even when the user is in only via a group.
        //
        // (Previous attempts to enumerate via IGroupManager were unreliable
        // because circles_member.user_id for group rows is a human-readable
        // label, not necessarily the actual NC group GID.)
        // ─────────────────────────────────────────────────────────────────────

        // Step 1: get all user single_ids reachable from this team
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

        // Step 2: resolve each single_id → NC user via circles_member
        if (!empty($singleIds)) {
            $uQb  = $db->getQueryBuilder();
            $uRes = $uQb->select('m.user_id', 'u.displayname')
                ->from('circles_member', 'm')
                ->leftJoin('m', 'users', 'u', 'm.user_id = u.uid')
                ->where($uQb->expr()->in('m.circle_id',
                    $uQb->createNamedParameter($singleIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($uQb->expr()->eq('m.user_type',
                    $uQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeQuery();

            while ($r = $uRes->fetch()) {
                $uid = (string)$r['user_id'];
                if ($uid === '' || isset($seen[$uid])) {
                    continue;
                }
                $seen[$uid] = true;
                // Prefer IUserManager's resolved display name (handles LDAP /
                // user-backend overrides + the preferences table where NC
                // actually stores user-set display names). Fall back to the
                // users-table column (which is often NULL on real installs)
                // and finally to the bare uid. This was the source of the
                // wizard showing account names instead of display names.
                $resolvedName = $this->userManager->get($uid)?->getDisplayName();
                $displayName  = $resolvedName !== null && $resolvedName !== ''
                    ? $resolvedName
                    : (!empty($r['displayname']) ? $r['displayname'] : $uid);
                $list[] = [
                    'userId'      => $uid,
                    'displayName' => $displayName,
                ];
            }
            $uRes->closeCursor();
        }

        // Step 3 (safety net): also pull direct user members straight from
        // circles_member. In rare cases circles_membership may not contain
        // a direct member's row yet (very freshly added). This is a no-op
        // for the common case but prevents direct members ever being missed.
        $dQb  = $db->getQueryBuilder();
        $dRes = $dQb->select('m.user_id', 'u.displayname')
            ->from('circles_member', 'm')
            ->leftJoin('m', 'users', 'u', 'm.user_id = u.uid')
            ->where($dQb->expr()->eq('m.circle_id',  $dQb->createNamedParameter($teamId)))
            ->andWhere($dQb->expr()->eq('m.user_type', $dQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($dQb->expr()->eq('m.status',    $dQb->createNamedParameter('Member')))
            ->executeQuery();

        while ($r = $dRes->fetch()) {
            $uid = (string)$r['user_id'];
            if ($uid === '' || isset($seen[$uid])) {
                continue;
            }
            $seen[$uid] = true;
            // Same display-name resolution as the membership-cache branch
            // above — see comment there for why we go through IUserManager
            // before falling back to the users-table column.
            $resolvedName = $this->userManager->get($uid)?->getDisplayName();
            $displayName  = $resolvedName !== null && $resolvedName !== ''
                ? $resolvedName
                : (!empty($r['displayname']) ? $r['displayname'] : $uid);
            $list[] = [
                'userId'      => $uid,
                'displayName' => $displayName,
            ];
        }
        $dRes->closeCursor();

        return $list;
    }

    /**
     * Every email address in a team's effective member set (v4.6.26).
     *
     * Backs the sidebar's "Email all members". Members with no address on
     * their account are skipped rather than represented by an empty slot —
     * `oc_accounts_data` holds an empty string for a user who never set one,
     * and an empty recipient is worse than a missing one.
     *
     * The count of *all* effective members comes back alongside, because the
     * difference between the two numbers is the thing worth telling the user:
     * "9 of 14 members have an email address" is actionable, "9 recipients" on
     * its own hides that five people will not receive it.
     *
     * Member-gated, like every team-scoped read here: a team's roster of email
     * addresses is member-visible information, which is the same boundary the
     * members widget already applies to the same data.
     *
     * @return array{addresses: list<string>, memberCount: int}
     * @throws \Exception if the user is not authenticated or not a member
     */
    public function getTeamMemberEmailAddresses(string $teamId): array {

        $this->requireMemberLevel($teamId);

        $members   = $this->resolveEffectiveMembers($teamId);
        $addresses = [];

        foreach ($members as $row) {
            try {
                $email = $this->userManager->get($row['userId'])?->getEMailAddress();
            } catch (\Throwable $e) {
                // A backend that cannot resolve one account must not cost the
                // whole team its mail action.
                $email = null;
            }
            $email = trim((string)$email);
            if ($email !== '') {
                $addresses[] = $email;
            }
        }

        return [
            'addresses'   => $addresses,
            'memberCount' => count($members),
        ];
    }

    /**
     * Enrich a flat member list with email, phone, and live NC user status.
     *
     * Pure addition — input rows keep all their existing keys. New keys:
     *   - email     string|null
     *   - phone     string|null    (only when account-property visibility is
     *                                LOCAL / FEDERATED / PUBLISHED; never PRIVATE)
     *   - ncStatus  array|null     { status, message, icon }
     *
     * Phone is read via IAccountManager so the user's chosen visibility scope
     * is respected. Email is read directly from IUser — NC has no per-user
     * scope on the primary email address; it's the team-membership boundary
     * that controls who can see it here (only members of the same team can
     * call this endpoint, enforced by getAllEffectiveMembers's gate).
     *
     * The whole pass degrades silently — any error per user falls back to
     * null for that field, so a single broken account never blocks the list.
     */
    private function enrichMembersForWidget(array $list): array {
        if (empty($list)) {
            return $list;
        }

        $userIds = array_map(static fn ($r) => $r['userId'], $list);

        // ── Batch-fetch live NC user statuses ──
        $statusByUid = [];
        $usm = $this->getUserStatusManager();
        if ($usm !== null) {
            try {
                $statuses = $usm->getUserStatuses($userIds);
                foreach ($statuses as $uid => $st) {
                    $statusByUid[(string)$uid] = [
                        'status'  => method_exists($st, 'getStatus')        ? (string)$st->getStatus() : null,
                        'message' => method_exists($st, 'getMessage')       ? ($st->getMessage() ?: null) : null,
                        'icon'    => method_exists($st, 'getIcon')          ? ($st->getIcon() ?: null) : null,
                    ];
                }
            } catch (\Throwable $e) {
                // Non-fatal — widget shows without status text/dot.
                $statusByUid = [];
            }
        }

        $am = $this->getAccountManager();

        // ── Per-user enrichment ──
        foreach ($list as &$row) {
            $uid = $row['userId'];
            $row['email']    = null;
            $row['phone']    = null;
            $row['ncStatus'] = $statusByUid[$uid] ?? null;

            $user = $this->userManager->get($uid);
            if ($user === null) {
                continue;
            }

            // Email — respect IAccountManager visibility scope, same as
            // phone below. A user who explicitly marked their email as
            // "Private" in their NC profile should have that respected even
            // by fellow team members. When IAccountManager isn't available
            // we degrade to IUser::getEMailAddress() (which has no scope)
            // rather than fail closed, since the team-membership gate is
            // still in force.
            if ($am !== null) {
                try {
                    $accountForEmail = $am->getAccount($user);
                    $emProp = $accountForEmail->getProperty(IAccountManager::PROPERTY_EMAIL);
                    $emValue = $emProp->getValue();
                    $emScope = $emProp->getScope();
                    if (is_string($emValue) && $emValue !== ''
                        && $emScope !== IAccountManager::SCOPE_PRIVATE) {
                        $row['email'] = $emValue;
                    }
                } catch (PropertyDoesNotExistException $e) {
                    // No email property — leave null.
                } catch (\Throwable $e) {
                    // Fall back to the user-backend value if account-manager
                    // access fails for any other reason.
                    try {
                        $em = $user->getEMailAddress();
                        if (is_string($em) && $em !== '') {
                            $row['email'] = $em;
                        }
                    } catch (\Throwable $e2) {
                        // ignore
                    }
                }
            } else {
                // No account manager available at all — fall back to the
                // user-backend email. This path is hit only on very
                // unusual NC installs.
                try {
                    $em = $user->getEMailAddress();
                    if (is_string($em) && $em !== '') {
                        $row['email'] = $em;
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            // Phone — respect the user's chosen visibility scope.
            if ($am !== null) {
                try {
                    $account = $am->getAccount($user);
                    $prop = $account->getProperty(IAccountManager::PROPERTY_PHONE);
                    $value = $prop->getValue();
                    $scope = $prop->getScope();
                    if (is_string($value) && $value !== ''
                        && $scope !== IAccountManager::SCOPE_PRIVATE) {
                        $row['phone'] = $value;
                    }
                } catch (PropertyDoesNotExistException $e) {
                    // No phone property set — leave null.
                } catch (\Throwable $e) {
                    // Any other failure: degrade silently.
                }
            }
        }
        unset($row);

        return $list;
    }

    /**
     * Whether the Talk (spreed) app is enabled for the current user.
     * Used by the widget endpoint so the frontend can decide whether to
     * render the Talk contact icon at all.
     */
    public function isTalkAvailableForCurrentUser(): bool {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }
        try {
            return $this->appManager->isEnabledForUser('spreed', $user);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether the current user can compose a message in Nextcloud Mail.
     *
     * Two gates, both required:
     *   1. The `mail` app is enabled for this user.
     *   2. That user has at least one account row in `mail_accounts`.
     *
     * The second gate is not belt-and-braces. Mail's own `/mailto` route
     * checks for an account before it opens the composer (`src/views/Home.vue`
     * upstream) — sending someone there without one drops them on the setup
     * screen and the address is silently discarded. Answering false here means
     * they get the plain `mailto:` link instead, which is what a user with no
     * Mail account actually wants.
     *
     * `mail_accounts` is read directly because Mail publishes no OCP API for
     * this — same approach as the Deck and Talk reads elsewhere in the app.
     * Every failure degrades to false, i.e. to the `mailto:` link the members
     * widget shipped before this existed.
     *
     * v4.6.26 — the lookup itself moved to `MailClientService`, which is now
     * the single answer to this question for the whole app. This method stays
     * as the members widget's way in; the payload key it feeds
     * (`mailAvailable`, documented in APIendpoints.md) is unchanged.
     */
    public function isMailAvailableForCurrentUser(): bool {
        return $this->mailClientService->isAvailableForCurrentUser();
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
            // Prefer IUserManager (LDAP + prefs-table user-set names) over the
            // users-table displayname column, which is often stale or NULL.
            // Same resolution ladder as getEffectiveMembers() above.
            $resolvedName = $this->userManager->get($userId)?->getDisplayName();
            $displayName  = $resolvedName !== null && $resolvedName !== ''
                ? $resolvedName
                : (!empty($row['displayname']) ? $row['displayname'] : $userId);
            $direct[] = [
                'userId'      => $userId,
                'displayName' => $displayName,
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

            $this->logger->debug('[TeamHub][MemberService] getMembersForManage: row resolved', [
                'user_id' => $subLabel, 'single_id' => $subSingle,
                'user_type' => $subType, 'circle_source' => $subSource,
                'circle_name' => $circleName, 'display' => $displayName,
                'count' => $subCount, 'is_group' => $isGroup,
                'app' => Application::APP_ID,
            ]);
        }
        $gcRes->closeCursor();

        $this->logger->debug('[TeamHub][MemberService] getMembersForManage', [
            'teamId'          => $teamId,
            'direct'          => count($direct),
            'groups'          => count($groups),
            'circles'         => count($circles),
            'effective_count' => $effectiveCount,
            'app'             => Application::APP_ID,
        ]);

        // ── people reached only through a group or nested team ────────────────
        //
        // v4.7.14 (GitHub #87) — until now this method returned each group and
        // team as a name and a count, so "Marketing (2)" was all an admin ever
        // saw. That is the visibility gap in the issue, and it is also why a
        // promotion looked impossible: a level lives on a direct row, and there
        // was no row in the list for somebody who has no direct row to click.
        //
        // Anyone already in $direct is skipped — they have their own entry, and
        // its level is the higher of the two anyway (Circles takes the max
        // across paths), so listing them twice would show a level that is not
        // the one in force.
        $directIds = array_column($direct, 'userId');
        $inherited = [];

        $iQb  = $db->getQueryBuilder();
        $iRes = $iQb->selectDistinct(['m.user_id', 'ms.level', 'ms.inheritance_depth', 'ms.inheritance_first'])
            ->from('circles_membership', 'ms')
            ->innerJoin('ms', 'circles_member', 'm', $iQb->expr()->andX(
                $iQb->expr()->eq('m.circle_id', 'ms.single_id'),
                $iQb->expr()->eq('m.user_type', $iQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)),
            ))
            ->where($iQb->expr()->eq('ms.circle_id', $iQb->createNamedParameter($teamId)))
            // Highest level first, then the shortest route. One person can reach
            // a team several ways at once — Lieke reads three rows for publicOne
            // — and the level in force is the highest of them, so the first row
            // per user has to be that one. The depth tiebreak just makes `via`
            // name the most direct explanation.
            ->orderBy('ms.level', 'DESC')
            ->addOrderBy('ms.inheritance_depth', 'ASC')
            ->executeQuery();

        $seen = [];
        while ($row = $iRes->fetch()) {
            $userId = (string)$row['user_id'];
            if ($userId === '' || in_array($userId, $directIds, true) || isset($seen[$userId])) {
                continue;
            }
            $seen[$userId] = true;

            $level = (int)$row['level'];
            // Name the route in, so "why can this person see the team?" has an
            // answer in the list rather than in a support conversation.
            $viaId = (string)($row['inheritance_first'] ?? '');
            $via   = $viaId !== '' ? ($this->resolveCircleDisplayName($db, $viaId) ?: $viaId) : '';

            $inherited[] = [
                'userId'      => $userId,
                'displayName' => $this->userManager->get($userId)?->getDisplayName() ?: $userId,
                'role'        => match (true) {
                    $level >= 9 => 'Owner',
                    $level >= 8 => 'Admin',
                    $level >= 4 => 'Moderator',
                    default     => 'Member',
                },
                'level'  => $level,
                'via'    => $via,
                'depth'  => (int)$row['inheritance_depth'],
                'status' => 'Member',
            ];
        }
        $iRes->closeCursor();

        return [
            'direct'          => $direct,
            'groups'          => $groups,
            'circles'         => $circles,
            'inherited'       => $inherited,
            'effective_count' => $effectiveCount,
        ];
    }

    /**
     * Human-readable name for a circle, given its unique_id (v4.7.14).
     *
     * Circles stores a group's circle as `group:<gid>` and a personal circle as
     * `user:<uid>:<id>`, neither of which is a name anybody wants to read, so
     * both prefixes are unwrapped. Returns '' when the circle is unknown.
     */
    private function resolveCircleDisplayName(\OCP\IDBConnection $db, string $singleId): string {
        $qb  = $db->getQueryBuilder();
        $res = $qb->select('name', 'display_name')
            ->from('circles_circle')
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($singleId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();

        if (!$row) {
            return '';
        }

        $name = (string)($row['display_name'] ?? '');
        if ($name === '') {
            $name = (string)($row['name'] ?? '');
        }
        if (str_starts_with($name, 'group:')) {
            return substr($name, 6);
        }
        if (str_starts_with($name, 'user:')) {
            $parts = explode(':', $name);
            return $parts[1] ?? $name;
        }

        return $name;
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

        $this->assertTeamNotPendingDeletion($teamId);

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

        // v4.7.13 (GitHub #87) — effective level, not direct level.
        // getMemberLevelFromDb() reads circles_member, which holds direct rows
        // only, so anyone reaching this team through a group or nested team
        // reads 0 there. For the caller that meant an admin who inherits their
        // adminship could not administer; for the target it meant an inherited
        // member could not be promoted at all, because there was no row to find.
        $callerLevel = $this->getEffectiveMemberLevel($db, $teamId, $caller->getUID());
        if ($callerLevel < 8) {
            throw new \Exception('Insufficient permissions. Admin or owner role required.');
        }

        $targetLevel = $this->getEffectiveMemberLevel($db, $teamId, $userId);
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

        // A level lives on a direct circles_member row. Somebody who is only an
        // inherited member has none, so give them one before setting it. This is
        // not a second admission — they are already in the team — it is the
        // carrier for the level, and Circles is built for it: fillMemberships()
        // keeps the HIGHEST level across every path to a team, so the direct row
        // raises them and the group keeps reaching them.
        $hasDirectRow = $this->getMemberLevelFromDb($db, $teamId, $userId) > 0;
        if (!$hasDirectRow) {
            $this->createDirectMemberRow($db, $teamId, $userId, $caller);
        }

        // Write the new level directly to circles_member
        $qb = $db->getQueryBuilder();
        $qb->update('circles_member')
            ->set('level', $qb->createNamedParameter($newLevel, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('Member')))
            ->executeStatement();

        // A new direct row changes the effective membership, so the team's
        // resources need to hear about it like any other membership change.
        if (!$hasDirectRow) {
            $this->resourceMembership->reconcileTeamMembership($teamId, 'member_promoted_from_inherited');
        }

        // Return refreshed member list
        return $this->getTeamMembers($teamId);
    }

    /**
     * The level a user actually has in a team, by any route (v4.7.13).
     *
     * The higher of their direct circles_member row and whatever the
     * circles_membership cache credits them with through an attached group or
     * nested team — which is the same "highest wins" rule Circles' own
     * MembershipService::fillMemberships() applies when building that cache.
     *
     * Returns 0 when the user reaches the team by no route at all.
     */
    private function getEffectiveMemberLevel(\OCP\IDBConnection $db, string $teamId, string $userId): int {
        $direct = $this->getMemberLevelFromDb($db, $teamId, $userId);

        // circles_membership.single_id is the person's own principal, not a uid,
        // so it is resolved by joining circles_member on m.circle_id = single_id
        // — the same shape TalkService uses for effective Talk membership. MAX()
        // because a user can reach one team by several paths at once.
        $qb  = $db->getQueryBuilder();
        $res = $qb->selectAlias($qb->func()->max('ms.level'), 'lvl')
            ->from('circles_membership', 'ms')
            ->innerJoin('ms', 'circles_member', 'm', $qb->expr()->andX(
                $qb->expr()->eq('m.circle_id', 'ms.single_id'),
                $qb->expr()->eq('m.user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)),
                $qb->expr()->eq('m.user_id', $qb->createNamedParameter($userId)),
            ))
            ->where($qb->expr()->eq('ms.circle_id', $qb->createNamedParameter($teamId)))
            ->executeQuery();
        // MAX() over no rows is NULL, which casts to the 0 this method promises.
        $inherited = (int)$res->fetchOne();
        $res->closeCursor();

        return max($direct, $inherited);
    }

    /**
     * Give a user a direct circles_member row in a team they already reach
     * through a group or nested team (v4.7.13).
     *
     * Circles' own addMember() builds the row rather than an INSERT here, and
     * that is the whole point: the row's `single_id` must be the user's personal
     * circle unique_id, because that is what circles_membership joins on. A
     * hand-made id produces a principal nothing can resolve, and
     * MembershipService::onUpdate() then deletes the membership rows belonging
     * to it — the phantom-principal damage recorded in HANDOFF §0b.
     *
     * v4.7.16 — takes the caller, because Circles' addMember() opens with
     * `mustHaveCurrentUser()` and throws InitiatorNotFoundException('Invalid
     * initiator') when nothing has set one. Between 4.7.13 and 4.7.15 this
     * method never set it, so the addMember() branch threw every time it was
     * reached; promoting an inherited member appeared to work only for someone
     * who already had a direct row from some earlier route, where the branch is
     * skipped. inviteMembers() has always done this correctly and is the
     * pattern being matched here.
     *
     * @param \OCP\IUser $caller the acting admin, who becomes the Circles
     *   initiator for the add — already checked for level >= 8 by the caller
     */
    private function createDirectMemberRow(\OCP\IDBConnection $db, string $teamId, string $userId, \OCP\IUser $caller): void {
        // v4.7.14 — a direct row may already exist without being a membership.
        // The caller only asked whether the user has a *Member* row; an admin who
        // already tried to invite this person leaves an 'Invited' one behind, and
        // calling addMember() on top of that throws MemberAlreadyExistsException.
        // There is nothing to add in that case — only to confirm, which the
        // UPDATE below does. Skipping straight to it is also what rescues an
        // invite the user cannot accept themselves.
        $existingStatus = $this->fetchAnyMemberStatus($db, $teamId, $userId);

        if ($existingStatus === null) {
            $federatedUserService = $this->container->get(\OCA\Circles\Service\FederatedUserService::class);
            $circleMemberService  = $this->container->get(\OCA\Circles\Service\MemberService::class);

            // Circles resolves the initiator off its own FederatedUserService,
            // not from the NC session, so this has to be said explicitly —
            // addMember() calls mustHaveCurrentUser() before anything else.
            $federatedUserService->setLocalCurrentUser($caller);

            $invitee = $federatedUserService->generateFederatedUser($userId, 1);
            $circleMemberService->addMember($teamId, $invitee);
        }

        // On a team carrying CFG_INVITE the new row lands 'Invited' at level 0.
        // That gate is there so nobody is admitted without accepting — but this
        // person is already a member through their group, so there is nothing to
        // accept; the row exists only to carry a level. Confirm it, exactly as an
        // attached group is confirmed. The caller sets the real level next.
        $qb = $db->getQueryBuilder();
        $qb->update('circles_member')
            ->set('status', $qb->createNamedParameter('Member'))
            ->set('level',  $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('Invited')))
            ->executeStatement();

        $this->logger->info('[TeamHub][MemberService] updateMemberLevel: created direct membership row for an inherited member', [
            'teamId' => $teamId,
            'userId' => $userId,
            'app'    => Application::APP_ID,
        ]);
    }

    /**
     * Direct DB lookup for a member's level in a team (0 if not a member).
     * Public so TeamService can use it for permission checks.
     */
    public function getMemberLevelFromDb(\OCP\IDBConnection $db, string $teamId, string $userId): int {
        // apps.md R-1 note: kept as a direct SELECT deliberately.
        // CirclesManager exposes level only via getInitiator()->getLevel(),
        // which is scoped to the CURRENT session user — this method must
        // support arbitrary $userId lookups (target-user checks in
        // TeamController::transferOwnership, MemberService::updateMemberLevel,
        // BudgetService::currentUserLevel-for-editor). An API migration
        // that iterates getMembers() to find $userId hydrates every member
        // entity per call, which is materially more expensive than an
        // indexed lookup on (circle_id, user_id, status).
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
     * Read a team's raw Circles config bitmask.
     *
     * Returns 0 for a team that does not exist, which `CirclesConfig::joinPolicy()`
     * reads as JOIN_CLOSED — the safe answer either way, since a join against a
     * missing circle has nothing to succeed at.
     */
    private function fetchTeamConfig(\OCP\IDBConnection $db, string $teamId): int {
        $qb  = $db->getQueryBuilder();
        $res = $qb->select('config')
            ->from('circles_circle')
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();

        return $row ? (int)$row['config'] : 0;
    }

    /**
     * Read the raw status column ('Member' | 'Invited' | 'Requesting' | 'Blocked')
     * for a (teamId, userId) row of user_type=1 (individual NC user).
     *
     * Used by inviteMembers to decide whether to sync the user to the team's
     * Talk room immediately (status='Member') or defer until they accept the
     * invite (status='Invited'). See v3.100.8 regression fix.
     */
    private function fetchMemberStatus(\OCP\IDBConnection $db, string $teamId, string $userId): ?string {
        try {
            $qb = $db->getQueryBuilder();
            $result = $qb->select('status')
                ->from('circles_member')
                ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('Member')))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            return $row ? (string)$row['status'] : null;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MemberService] fetchMemberStatus failed', [
                'teamId' => $teamId, 'userId' => $userId,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
    }

    /**
     * Read the raw status column WITHOUT filtering to 'Member' rows.
     * Returns the current status ('Invited', 'Member', 'Requesting',
     * 'Blocked') or null when no row exists. Used by the resend-invite
     * flow to distinguish "still Invited" (resend) from "already Member"
     * (no-op).
     */
    private function fetchAnyMemberStatus(\OCP\IDBConnection $db, string $teamId, string $userId): ?string {
        try {
            $qb = $db->getQueryBuilder();
            $result = $qb->select('status')
                ->from('circles_member')
                ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            return $row ? (string)$row['status'] : null;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MemberService] fetchAnyMemberStatus failed', [
                'teamId' => $teamId, 'userId' => $userId,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
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
            throw new AccessDeniedException('User not authenticated');
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

        throw new AccessDeniedException('You are not a member of this team');
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
            throw new AccessDeniedException('User not authenticated');
        }

        $db    = $this->container->get(\OCP\IDBConnection::class);
        $level = $this->getMemberLevelFromDb($db, $teamId, $user->getUID());

        if ($level === 0) {
            throw new AccessDeniedException('You are not a member of this team');
        }
        if ($level < 8) {
            throw new AccessDeniedException('Insufficient permissions. Admin or owner role required.');
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
            throw new AccessDeniedException('User not authenticated');
        }

        $db    = $this->container->get(\OCP\IDBConnection::class);
        $level = $this->getMemberLevelFromDb($db, $teamId, $user->getUID());

        if ($level === 0) {
            throw new AccessDeniedException('You are not a member of this team');
        }
        if ($level < 9) {
            throw new AccessDeniedException('Insufficient permissions. Owner role required.');
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
            throw new AccessDeniedException('User not authenticated');
        }

        $db    = $this->container->get(\OCP\IDBConnection::class);
        $level = $this->getMemberLevelFromDb($db, $teamId, $user->getUID());

        if ($level === 0) {
            throw new AccessDeniedException('You are not a member of this team');
        }
        if ($level < 4) {
            throw new AccessDeniedException('Insufficient permissions. Moderator, admin, or owner role required.');
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
     * @return array{stillMember: bool} stillMember is true when the caller can
     *         still reach the team after the direct row is gone — i.e. a group
     *         or sub-team also grants them access. The caller must not report
     *         "you have left the team" in that case; the team stays visible.
     * @throws \Exception if owner tries to leave with members still in the team,
     *                    or if user is only an indirect member
     */
    public function leaveTeam(string $teamId): array {

        $this->assertTeamNotPendingDeletion($teamId);

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

            // v3.99.6 — owner cannot leave, period. Previous code only
            // blocked when other members were still in the team; a sole-
            // owner who left orphaned the team. Only ways an owner exits:
            // transfer ownership first, or delete the team.
            if ($level >= 9) {
                throw new \Exception('Team owner cannot leave. Transfer ownership or delete the team first.');
            }

            // Delete all rows for this user in this circle (covers Member + Requesting).
            $delQb = $db->getQueryBuilder();
            $delQb->delete('circles_member')
                ->where($delQb->expr()->eq('circle_id', $delQb->createNamedParameter($teamId)))
                ->andWhere($delQb->expr()->eq('user_id',   $delQb->createNamedParameter($uid)))
                ->andWhere($delQb->expr()->eq('user_type', $delQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            // v4.4.8 — rebuild the circles_membership cache. A raw DELETE on
            // circles_member does NOT touch it, and that cache is what
            // TeamService::getUserTeams() and every share picker actually read.
            // Skipping it left the departing user with a stale cache row: the
            // team stayed in their sidebar with full access while their direct
            // level dropped to 0 — which hides the Leave action, so they could
            // not even retry. Same call, same reason, as removeMember() below.
            $cacheRebuilt = true;
            try {
                $membershipService = $this->container->get(\OCA\Circles\Service\MembershipService::class);
                $membershipService->onUpdate($teamId);
            } catch (\Throwable $e) {
                $cacheRebuilt = false;
                $this->logger->warning('[TeamHub][MemberService] leaveTeam: membership cache rebuild failed', [
                    'teamId' => $teamId, 'uid' => $uid, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            $this->logger->info('[TeamHub][MemberService] leaveTeam: member removed via direct DB delete', [
                'uid'    => $uid,
                'teamId' => $teamId,
                'app'    => Application::APP_ID,
            ]);

            // Sync the departing member out of the team's connected resources.
            // Talk does not watch for Circles changes, so this has to be told.
            //
            // v4.7.9 (GitHub #87) — was an unconditional removeUserFromTeamTalkRoom.
            // That contradicted the $stillMember check immediately below: a user
            // who leaves directly but remains reachable through an attached group
            // was correctly told they still belong to the team, and just as
            // correctly thrown out of its conversation. The reconcile diffs
            // against the same effective membership $stillMember reads, so the
            // two answers can no longer disagree.
            $this->resourceMembership->reconcileTeamMembership($teamId, 'member_left');

            // Leaving drops the direct row only. A user who is ALSO in an
            // attached group (or sub-team) legitimately keeps access, and the
            // rebuild above re-creates their cache row through that path. Ask
            // the (now-current) cache rather than assuming, so the caller can
            // tell the user the truth about a team they can still open.
            $stillMember = $this->isEffectiveMember($teamId, $uid, $db);

            if ($stillMember && !$cacheRebuilt) {
                // Distinguishable in the log from the legitimate group case:
                // the user is stuck until an admin runs the membership repair.
                $this->logger->error('[TeamHub][MemberService] leaveTeam: user still has access and the cache rebuild failed', [
                    'teamId' => $teamId, 'uid' => $uid, 'app' => Application::APP_ID,
                ]);
            }

            return ['stillMember' => $stillMember];
        } catch (\Exception $e) {
            $this->logger->error('[TeamHub][MemberService] Error leaving team', [
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

        $this->assertTeamNotPendingDeletion($teamId);
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

        $this->logger->info('[TeamHub][MemberService] removeMember: removed via direct DB delete', [
            'teamId'   => $teamId,
            'targetId' => $targetId,
            'userType' => $userType,
            'app'      => Application::APP_ID,
        ]);

        // v4.7.9 (GitHub #87) — one reconcile for every member type, replacing
        // the type-1 branch that removed the attendee row unconditionally.
        //
        // That branch is the "dual membership" bug in #87: a user removed as an
        // individual while still reachable through an attached group lost the
        // conversation, because the eviction never asked whether another path
        // to the team remained. The reconcile does ask — it diffs against the
        // effective set — so such a user keeps the row untouched, which also
        // preserves their read markers where an evict-then-re-add would not.
        //
        // One deliberate difference from the old direct path: the reconcile
        // leaves room OWNER rows (participant_type=1) alone, so removing the
        // Talk room's owner from the team no longer orphans the room. That is
        // the reconciler's documented choice, not an oversight here.
        //
        // reconcileTeamMembership() rebuilds circles_membership before reading it, which
        // is what the removed MembershipService::onUpdate() call above used to
        // do at this point.
        $this->resourceMembership->reconcileTeamMembership($teamId, 'member_removed');
    }

    /**
     * Invite a list of users/groups to a team. Requires admin or owner level.
     * Each entry can be a string (userId, type=user) or array {id, type}.
     * Types: 'user'=local NC user (Circles type 1), 'group'=NC group (type 2),
     *        'federated'=federated user (type 4), 'email'=email (type 7).
     * Non-fatal per entry — returns per-id results.
     */
    public function inviteMembers(string $teamId, array $members): array {

        $this->assertTeamNotPendingDeletion($teamId);

        // Moderator (level >= 4) or above may invite — matches the controller gate.
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

        $db = $this->container->get(\OCP\IDBConnection::class);

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

                // For circle (user_type=16) additions: enforce circular-nesting safety gate
                // before calling addMember(). This mirrors the search-time exclusion but is
                // the authoritative check — search results can be stale and the API is also
                // called directly by developers.
                //
                // Block: $memberId (candidate circle) already has $teamId as a direct member.
                // That would create A→B→A nesting. Indirect cycles (A→B→C→A) are out of scope
                // and would have to be addressed in Circles itself.
                if ($memberType === 16) {
                    // Resolve the candidate circle's unique_id from the FederatedUser
                    $candidateCircleId = null;
                    try {
                        $candidateCircleId = $invitee->getSingleId();
                    } catch (\Throwable $e) {
                        // getSingleId() may not be available on all Circles versions — fall back
                        // to a direct lookup by name (the $memberId passed in is the unique_id
                        // for circles, since generateFederatedUser uses it as the single_id)
                        $candidateCircleId = $memberId;
                    }

                    if ($candidateCircleId) {
                        // Check: is $teamId already a direct member of the candidate circle?
                        $cycleQb  = $db->getQueryBuilder();
                        $cycleRes = $cycleQb->select($cycleQb->func()->count('*', 'cnt'))
                            ->from('circles_member')
                            ->where($cycleQb->expr()->eq('circle_id', $cycleQb->createNamedParameter($candidateCircleId)))
                            ->andWhere($cycleQb->expr()->eq('single_id', $cycleQb->createNamedParameter($teamId)))
                            ->andWhere($cycleQb->expr()->eq('user_type', $cycleQb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                            ->executeQuery();
                        $cycleCount = (int)$cycleRes->fetchOne();
                        $cycleRes->closeCursor();

                        if ($cycleCount > 0) {
                            $results[$memberId] = 'failed: circular nesting not allowed — this team is already a member of the target team';
                            $this->logger->warning('[TeamHub][MemberService] inviteMembers: circular nesting rejected', [
                                'teamId'            => $teamId,
                                'candidateCircleId' => $candidateCircleId,
                                'app'               => Application::APP_ID,
                            ]);
                            continue;
                        }
                    }
                }

                // v3.100.10 — resend-invite handling.
                // Circles throws FederatedItemBadRequestException with
                // "Already invited into the team" when the target already
                // has an Invited/Member row for this circle. Two real
                // scenarios that hit this exception:
                //
                //   (a) Target has already accepted (status='Member').
                //       Nothing to do — no-op success.
                //   (b) Target is still 'Invited' but never saw / deleted
                //       the NC notification about it. The admin's intent
                //       is to RE-SEND the invite; failing here is wrong
                //       UX. We remove the stale Invited row, then let
                //       Circles create a fresh one so its notifier fires
                //       a new notification for the user.
                //
                // Only user_type=1 targets get the resend flow — for
                // groups/circles the auto-confirm block below handles it.
                $alreadyInvited = false;
                $resent         = false;
                try {
                    $circleMemberService->addMember($teamId, $invitee);
                } catch (\Throwable $addMemberEx) {
                    $msg = $addMemberEx->getMessage();

                    // v4.7.13 (GitHub #87) — match the exception that actually
                    // means "already there", not its whole family.
                    //
                    // This used to accept any FederatedItemBadRequestException.
                    // MemberAlreadyExistsException extends it, but so do the
                    // other CIRCLE_JOIN refusals — 124 "circle is not open" among
                    // them — so a genuine rejection was reported to the admin as
                    // 'already_invited', i.e. as success. That is what silently
                    // dropped a member during the 2026-08-07 bulk import
                    // (HANDOFF §0) and what makes "Lieke is already added"
                    // untrustworthy as a diagnosis today.
                    //
                    // The string checks stay as a fallback for Circles versions
                    // that raise a plain bad-request with these messages. An
                    // instanceof against a class that does not exist is simply
                    // false, so no version guard is needed.
                    $isAlreadyMember = ($addMemberEx instanceof \OCA\Circles\Exceptions\MemberAlreadyExistsException)
                        || str_contains($msg, 'Already invited')
                        || str_contains($msg, 'already a member');
                    if (!$isAlreadyMember) {
                        // Rethrown, so the loop's own catch records it as
                        // 'failed: <reason>' against this id. Louder than
                        // before, and true.
                        throw $addMemberEx;
                    }

                    $alreadyInvited = true;
                    $currentStatus  = $memberType === 1
                        ? $this->fetchAnyMemberStatus($db, $teamId, $memberId)
                        : null;

                    if ($memberType === 1
                        && $currentStatus !== null
                        && strcasecmp($currentStatus, 'Invited') === 0
                    ) {
                        // Resend path: nuke the stale Invited row, then
                        // re-invite so the Circles notifier fires again.
                        $this->logger->info('[TeamHub][MemberService] inviteMembers: stale Invited row — resending invite', [
                            'id' => $memberId, 'teamId' => $teamId,
                            'app' => Application::APP_ID,
                        ]);
                        try {
                            $delQb = $db->getQueryBuilder();
                            $delQb->delete('circles_member')
                                ->where($delQb->expr()->eq('circle_id', $delQb->createNamedParameter($teamId)))
                                ->andWhere($delQb->expr()->eq('user_id',  $delQb->createNamedParameter($memberId)))
                                ->andWhere($delQb->expr()->eq('user_type', $delQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                                ->andWhere($delQb->expr()->eq('status',   $delQb->createNamedParameter('Invited')))
                                ->executeStatement();

                            // Re-invite. If this second call still throws,
                            // we propagate — something else is wrong.
                            $circleMemberService->addMember($teamId, $invitee);
                            $resent = true;
                        } catch (\Throwable $resendEx) {
                            $this->logger->warning('[TeamHub][MemberService] inviteMembers: resend attempt failed — falling back to no-op success', [
                                'id' => $memberId, 'teamId' => $teamId,
                                'error' => $resendEx->getMessage(), 'app' => Application::APP_ID,
                            ]);
                        }
                    } else {
                        $this->logger->debug('[TeamHub][MemberService] inviteMembers: already invited/member — treating as no-op', [
                            'id' => $memberId, 'type' => $memberType,
                            'teamId' => $teamId, 'status' => $currentStatus,
                            'reason' => $msg, 'app' => Application::APP_ID,
                        ]);
                    }
                }

                // Circles' addMember() creates an 'Invited' status row for non-user
                // types (groups, circles). For groups and circles added by a team
                // admin, we treat the invite as immediately confirmed — the admin
                // is explicitly choosing to include this group; no secondary
                // acceptance is needed.
                //
                // v4.6.12 — this block used to claim that "for user_type=1, it
                // goes straight to 'Member'". That is only true on a team whose
                // config lacks CFG_INVITE (32). With CFG_INVITE set, a user lands
                // 'Invited' at level 0 with no circles_membership row, exactly
                // like a group does, and stays there until they accept. The claim
                // is why a bulk-imported team admin could be promoted to level 8
                // and still have no access to anything (observed on a team with
                // config 40). Users are deliberately still NOT auto-confirmed
                // here — a pending invite is the team's privacy config doing its
                // job. The import bypasses it only for accounts named in the
                // `admin` column, in MaintenanceService::adminSetMemberLevel().
                //
                // We update the row directly: status → 'Member', level → 1.
                // Then rebuild the Circles membership cache so the group's users
                // immediately appear in share pickers and resource ACLs.
                //
                // Note: we do NOT filter by user_type = $memberType here. Circles
                // converts groups (type=2) to their backing circle representation
                // (user_type=16) internally when inserting the circles_member row,
                // so filtering on the original type would match nothing. We target
                // all Invited rows with user_type IN(2,16) for this team — safe
                // because (a) we are scoped to the current team only, (b) we only
                // touch 'Invited' rows (not already-confirmed members), and (c) we
                // run this only after addMember() succeeds.
                if ($memberType !== 1) {
                    try {
                        // v4.7.12 (GitHub #87) — write, then CHECK, then rewrite.
                        //
                        // A single UPDATE here was silently losing. Circles' own
                        // SingleMemberAdd::manage() calls memberService->insertOrUpdate()
                        // from the queued event, whose Member object still carries
                        // status 'Invited' — so depending on which side lands last,
                        // Circles either writes the row after we looked for it, or
                        // overwrites the 'Member' we just set. Either way the row
                        // stays 'Invited', at level 0, and Circles' generateMemberships()
                        // skips anything below LEVEL_MEMBER: no membership rows, no
                        // flattened users, no MembershipsCreatedEvent, and therefore no
                        // Talk attendees for anyone inside that group or team.
                        //
                        // Both orderings are inside this request — the adds observed on
                        // 2026-08-29 produced no oc_circles_event row at all — so a
                        // bounded re-apply is enough. It only costs anything on the
                        // path that was previously failing outright.
                        $confirmed = false;
                        $updated   = 0;

                        for ($attempt = 1; $attempt <= self::CONFIRM_MAX_ATTEMPTS; $attempt++) {
                            $confirmQb = $db->getQueryBuilder();
                            $updated  += $confirmQb->update('circles_member')
                                ->set('status', $confirmQb->createNamedParameter('Member'))
                                ->set('level',  $confirmQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                                ->where($confirmQb->expr()->eq('circle_id', $confirmQb->createNamedParameter($teamId)))
                                ->andWhere($confirmQb->expr()->in(
                                    'user_type',
                                    $confirmQb->createNamedParameter([2, 16], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)
                                ))
                                ->andWhere($confirmQb->expr()->eq('status', $confirmQb->createNamedParameter('Invited')))
                                ->executeStatement();

                            // Read back rather than trusting the affected-row count:
                            // 0 rows changed is the correct answer both when the row
                            // was already 'Member' and when it has not appeared yet,
                            // and those two need opposite responses.
                            $checkQb  = $db->getQueryBuilder();
                            $checkRes = $checkQb->select($checkQb->func()->count('*', 'cnt'))
                                ->from('circles_member')
                                ->where($checkQb->expr()->eq('circle_id', $checkQb->createNamedParameter($teamId)))
                                ->andWhere($checkQb->expr()->in(
                                    'user_type',
                                    $checkQb->createNamedParameter([2, 16], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)
                                ))
                                ->andWhere($checkQb->expr()->eq('status', $checkQb->createNamedParameter('Invited')))
                                ->executeQuery();
                            $stillInvited = (int)$checkRes->fetchOne();
                            $checkRes->closeCursor();

                            if ($stillInvited === 0) {
                                $confirmed = true;
                                break;
                            }

                            if ($attempt < self::CONFIRM_MAX_ATTEMPTS) {
                                usleep(self::CONFIRM_RETRY_DELAY_US);
                            }
                        }

                        if (!$confirmed) {
                            // Warning, not info: this is the state in which a group or
                            // nested team is attached but nobody inside it can reach the
                            // team's conversation, and until now it produced no log line
                            // at all. The hourly reconcile cannot rescue it either —
                            // an Invited member generates no memberships to reconcile
                            // against — so somebody has to accept the invite by hand.
                            $this->logger->warning('[TeamHub][MemberService] inviteMembers: group/circle stayed Invited after retries — members will have no resource access until it is accepted', [
                                'id'       => $memberId,
                                'type'     => $memberType,
                                'teamId'   => $teamId,
                                'attempts' => self::CONFIRM_MAX_ATTEMPTS,
                                'app'      => Application::APP_ID,
                            ]);
                        }

                        $this->logger->info('[TeamHub][MemberService] inviteMembers: auto-confirmed group/circle membership', [
                            'id'        => $memberId,
                            'type'      => $memberType,
                            'teamId'    => $teamId,
                            'updated'   => $updated,
                            'confirmed' => $confirmed,
                            'app'       => Application::APP_ID,
                        ]);

                        // Rebuild Circles membership cache so the group's users get
                        // immediate access to resources (share pickers, Files ACLs etc).
                        try {
                            $membershipService = $this->container->get(\OCA\Circles\Service\MembershipService::class);
                            $membershipService->onUpdate($teamId);
                        } catch (\Throwable $cacheEx) {
                            // Cache rebuild failure is non-fatal — access will be
                            // reconciled by the Circles background job.
                            $this->logger->warning('[TeamHub][MemberService] inviteMembers: membership cache rebuild failed (non-fatal)', [
                                'teamId' => $teamId,
                                'error'  => $cacheEx->getMessage(),
                                'app'    => Application::APP_ID,
                            ]);
                        }
                    } catch (\Throwable $confirmEx) {
                        // Confirm failure is logged but does not fail the overall invite —
                        // the group IS in the circle (addMember succeeded); it just needs
                        // a manual acceptance or the next Circles background job to fix status.
                        $this->logger->warning('[TeamHub][MemberService] inviteMembers: could not auto-confirm group/circle (addMember succeeded)', [
                            'id'    => $memberId,
                            'type'  => $memberType,
                            'error' => $confirmEx->getMessage(),
                            'app'   => Application::APP_ID,
                        ]);
                    }
                }

                if ($resent) {
                    $results[$memberId] = 'invite_resent';
                } elseif ($alreadyInvited) {
                    $results[$memberId] = 'already_invited';
                } else {
                    $results[$memberId] = 'invited';
                }
                $this->logger->info('[TeamHub][MemberService] inviteMembers: member added', [
                    'id' => $memberId, 'type' => $memberType,
                    'alreadyInvited' => $alreadyInvited,
                    'resent'         => $resent,
                    'app' => Application::APP_ID,
                ]);

                // Add direct user members to the team's Talk room immediately —
                // but ONLY when the circles_member row is already 'Member'.
                // For invite-required teams Circles creates an 'Invited' row and
                // fires its own invite-notification event; pushing the user into
                // Talk at that moment via ParticipantService::addUsers bypasses
                // Circles' invite path (Talk adds them as a full participant and
                // Circles' notifier never sees the join). Talk sync for those
                // users happens when they accept — via approveRequest /
                // MemberJoinedEvent — not here.
                //
                // Fix for v3.100.8 regression reported against W-5.
                if ($memberType === 1) {
                    $status = $this->fetchMemberStatus($db, $teamId, $memberId);
                    if ($status === 'Member') {
                        $this->logger->debug('[TeamHub][MemberService] inviteMembers: syncing new user to Talk room', [
                            'uid' => $memberId, 'teamId' => $teamId, 'app' => Application::APP_ID,
                        ]);
                        $this->talkService->syncUserToTeamTalkRoom($teamId, $memberId);
                    } else {
                        $this->logger->debug('[TeamHub][MemberService] inviteMembers: deferring Talk sync until invite accepted', [
                            'uid' => $memberId, 'teamId' => $teamId,
                            'status' => $status, 'app' => Application::APP_ID,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                $results[$memberId] = 'failed: ' . $e->getMessage();
                $this->logger->warning('[TeamHub][MemberService] Could not invite member', [
                    'id'        => $memberId,
                    'type'      => $memberType,
                    'exception' => $e,
                    'app'       => Application::APP_ID,
                ]);
            }
        }

        // v4.7.9 (GitHub #87) — one reconcile for the whole batch, after the
        // loop rather than inside it, so a ten-member invite does one pass.
        //
        // This is what carries a *group* or sub-team into Talk. The per-user
        // syncUserToTeamTalkRoom() above only fires for memberType 1, so before
        // this call a group's members joined the team and were never given a
        // talk_attendees row — they opened the chat and were told the
        // conversation does not exist. Deck, Files and Calendar needed nothing
        // then and need nothing now; they resolve membership through Circles at
        // read time.
        //
        // Runs even when every entry failed: the failure may have been a
        // duplicate invite for somebody whose Talk row is missing anyway, and a
        // reconcile that finds no drift costs two queries.
        $this->resourceMembership->reconcileTeamMembership($teamId, 'members_invited');

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

        // v4.6.17 — refuse an invite-only team before either path runs.
        //
        // Circles already refuses one (CircleJoin::manageMemberStatus throws
        // when CFG_OPEN is absent), but that refusal was being caught by the
        // try/catch below and answered with the DB fallback, which inserts a
        // Requesting row unconditionally. So an invite-only team could be
        // joined-by-request from any crafted POST, and its admins got a
        // notification asking them to approve somebody the team's own
        // configuration says may not ask.
        //
        // The sentinel is passed to the frontend verbatim, in the shape
        // leaveTeam's `indirect_member` established, so the controller can
        // answer 403 rather than a generic 400.
        $db     = $this->container->get(\OCP\IDBConnection::class);
        $policy = CirclesConfig::joinPolicy($this->fetchTeamConfig($db, $teamId));
        if ($policy === CirclesConfig::JOIN_CLOSED) {
            $this->logger->info('[TeamHub][MemberService] requestJoinTeam: refused — team is invite only', [
                'uid' => $user->getUID(), 'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
            throw new \Exception('invite_only');
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
            $this->logger->info('[TeamHub][MemberService] requestJoinTeam: circleJoin succeeded via CircleService', [
                'uid'    => $user->getUID(),
                'teamId' => $teamId,
                'app'    => Application::APP_ID,
            ]);
            return;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MemberService] requestJoinTeam via CircleService::circleJoin failed, trying direct DB insert', [
                'teamId' => $teamId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
        }

        // Fallback: insert a pending member row directly.
        // status=Requesting, level=1 (member), user_type=1 (local user)
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
        //      (config & 1 > 0) where this user is the owner (level=9). CFG_SINGLE is
        //      bit 0 (value 1), not 2048 as an earlier version of this code claimed.
        //
        // single_id is NOT a free-form ID — Circles uses it to resolve the member
        // identity. Inserting a random value corrupts the circle for all members.

        $singleId = $this->resolveUserSingleId($uid, $db);

        if ($singleId === null) {
            // Personal circle doesn't exist yet (brand new user who has never
            // interacted with Circles). Generate it via Circles' own service.
            $this->logger->info('[TeamHub][MemberService] requestJoinTeam: personal circle not found, generating it', [
                'uid' => $uid, 'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
            try {
                $federatedUserService = $this->container->get(\OCA\Circles\Service\FederatedUserService::class);
                $generated = $federatedUserService->getLocalFederatedUser($uid, true, true);
                $singleId  = $generated->getSingleId();
                $this->logger->info('[TeamHub][MemberService] requestJoinTeam: personal circle generated', [
                    'uid' => $uid, 'singleId' => $singleId, 'app' => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('[TeamHub][MemberService] requestJoinTeam: could not generate personal circle', [
                    'uid' => $uid, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                throw new \Exception('Unable to request team membership: your Nextcloud account is not fully initialised. Please contact your administrator.');
            }
        }

        if (!$singleId) {
            throw new \Exception('Unable to request team membership: could not resolve your Nextcloud identity. Please contact your administrator.');
        }

        $this->logger->info('[TeamHub][MemberService] requestJoinTeam: resolved single_id for user', [
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
            $this->logger->error('[TeamHub][MemberService] requestJoinTeam DB fallback failed', [
                'teamId' => $teamId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            throw new \Exception('Failed to request team membership: ' . $e->getMessage());
        }

        // Does this join complete now, or wait for a moderator? Decided from the
        // policy resolved at the top of the method — before the insert, and
        // before any notification, so admins are only told about a request that
        // actually needs them.
        //
        // v4.6.17 — this branch used to test CFG_OPEN alone, which is the gate
        // on *whether* you may join, not on whether approval is needed. On a
        // team configured "anyone can join, but a moderator approves" the
        // fallback therefore flipped the row straight to Member and skipped the
        // notification entirely: the moderator gate existed in the settings and
        // nowhere else. CFG_REQUEST is what distinguishes the two, and
        // joinPolicy() is where that reading lives now.
        try {
            $this->logger->info('[TeamHub][MemberService] requestJoinTeam: join policy check', [
                'teamId' => $teamId, 'policy' => $policy, 'app' => Application::APP_ID,
            ]);

            if ($policy === CirclesConfig::JOIN_OPEN) {
                // Open circle: auto-approve by flipping status straight to Member.
                // Do NOT send a notification — no admin action is required.
                //
                // v3.100.8 (apps.md W-2) — try CirclesManager first so
                // activity/notifications fire member_confirmed; fall back
                // to the raw UPDATE that was previously the only path.
                $confirmedViaApi = false;
                try {
                    $circlesMgr = $this->getCirclesManager();
                    $circle = $circlesMgr->getCircle($teamId);
                    foreach ($circle->getInheritedMembers(false) as $m) {
                        if ($m->getUserId() === $uid
                            && strcasecmp($m->getStatus(), 'Requesting') === 0
                        ) {
                            if (method_exists($circlesMgr, 'confirmMemberRequest')) {
                                $circlesMgr->confirmMemberRequest($m);
                                $confirmedViaApi = true;
                            }
                            break;
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->debug('[TeamHub][MemberService] requestJoinTeam: CirclesManager path unavailable — using DB fallback', [
                        'uid' => $uid, 'teamId' => $teamId,
                        'reason' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }
                if (!$confirmedViaApi) {
                    $approveQb = $db->getQueryBuilder();
                    $approveQb->update('circles_member')
                        ->set('status', $approveQb->createNamedParameter('Member'))
                        ->set('level',  $approveQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                        ->where($approveQb->expr()->eq('circle_id', $approveQb->createNamedParameter($teamId)))
                        ->andWhere($approveQb->expr()->eq('user_id',   $approveQb->createNamedParameter($uid)))
                        ->andWhere($approveQb->expr()->eq('status',    $approveQb->createNamedParameter('Requesting')))
                        ->executeStatement();
                }
                $this->logger->info('[TeamHub][MemberService] requestJoinTeam: open circle — auto-approved, no notification sent', [
                    'uid' => $uid, 'teamId' => $teamId, 'viaApi' => $confirmedViaApi,
                    'app' => Application::APP_ID,
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

                // v3.100.8 — add the newly joined user to the team's Talk room.
                // Mirrors what approveRequest does; needed because inviteMembers
                // now defers Talk sync for Invited users, so the only paths that
                // trigger sync are (a) direct Member on invite, (b) admin
                // approves pending request, (c) here — open-circle self-join.
                $this->talkService->syncUserToTeamTalkRoom($teamId, $uid);

                // v4.7.9 (GitHub #87) — and the same reconcile every other
                // membership change ends with, so one joiner cannot be the one
                // case where the guarantee is "the targeted call worked".
                $this->resourceMembership->reconcileTeamMembership($teamId, 'member_self_joined');
            } else {
                // JOIN_REQUEST — the row stays `Requesting` and a moderator
                // decides. (JOIN_CLOSED never reaches here: it is refused at
                // the top of the method, before any row is written.)
                $this->sendJoinRequestNotification($teamId, $uid, $db);
                $this->logger->info('[TeamHub][MemberService] requestJoinTeam: approval required — notification sent to admins', [
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
            $this->logger->warning('[TeamHub][MemberService] requestJoinTeam: join-policy handling failed, skipping notification', [
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
                    $this->logger->warning('[TeamHub][MemberService] sendJoinRequestNotification: failed for admin', [
                        'adminUid' => $adminUid, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }
            }
            $aRes->closeCursor();

            $this->logger->info('[TeamHub][MemberService] sendJoinRequestNotification: sent to team admins', [
                'teamId' => $teamId, 'requester' => $requestingUid, 'app' => Application::APP_ID,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MemberService] sendJoinRequestNotification failed', [
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
            $this->logger->debug('[TeamHub][MemberService] getEffectiveMemberCount', [
                'teamId' => $teamId, 'count' => $cnt, 'app' => Application::APP_ID,
            ]);
            return $cnt;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MemberService] getEffectiveMemberCount failed, returning 0', [
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
            $this->logger->debug('[TeamHub][MemberService] isEffectiveMember via circles_membership', [
                'teamId' => $teamId, 'uid' => $uid, 'found' => $cnt > 0, 'app' => Application::APP_ID,
            ]);
            return $cnt > 0;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MemberService] isEffectiveMember circles_membership lookup failed', [
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

    /**
     * Cheap "is caller team admin (level ≥ 8)" check that returns bool
     * instead of throwing. Used to gate optional UI sections; the actual
     * write endpoints still call requireAdminLevel() so the gate stays
     * server-authoritative.
     */
    public function isCurrentUserTeamAdmin(string $teamId): bool {
        $user = $this->userSession->getUser();
        if (!$user) {
            return false;
        }
        $db = $this->container->get(\OCP\IDBConnection::class);
        return $this->getMemberLevelFromDb($db, $teamId, $user->getUID()) >= 8;
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
                    $this->logger->info('[TeamHub][MemberService] resolveUserSingleId: found via preferences', [
                        'uid' => $uid, 'table' => $prefTable, 'app' => Application::APP_ID,
                    ]);
                    return (string)$pRow[$valCol];
                }
            } catch (\Throwable $e) {
                // Table may not exist on this NC version — try next
            }
        }

        // Strategy 2: DB join on circles_circle + circles_member.
        // CFG_SINGLE = 1 (bit 0). Personal circles always have this bit set.
        // The user is the owner (level=9) of their own personal circle.
        // (Previous code used 2048 by mistake — that bit is CFG_BACKEND, which
        // personal circles never have, so this path always returned null. The
        // user-preference path above silently saved most calls.)
        try {
            $cQb  = $db->getQueryBuilder();
            $cRes = $cQb->select('c.unique_id')
                ->from('circles_circle', 'c')
                ->join('c', 'circles_member', 'm', 'c.unique_id = m.circle_id')
                ->where($cQb->expr()->eq('m.user_id',   $cQb->createNamedParameter($uid)))
                ->andWhere($cQb->expr()->eq('m.user_type', $cQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($cQb->expr()->eq('m.level',   $cQb->createNamedParameter(9, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere(
                    // CFG_SINGLE bit is set: config & 1 > 0.
                    $cQb->expr()->gt(
                        $cQb->createFunction('(c.config & ' . CirclesConfig::CFG_SINGLE . ')'),
                        $cQb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                    )
                )
                ->setMaxResults(1)
                ->executeQuery();
            $cRow = $cRes->fetch();
            $cRes->closeCursor();
            if ($cRow && !empty($cRow['unique_id'])) {
                $this->logger->info('[TeamHub][MemberService] resolveUserSingleId: found via DB join', [
                    'uid' => $uid, 'app' => Application::APP_ID,
                ]);
                return (string)$cRow['unique_id'];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MemberService] resolveUserSingleId: DB join failed', [
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

        $this->logger->info('[TeamHub][MemberService] getPendingRequests: found via DB', [
            'teamId' => $teamId, 'count' => count($pending), 'app' => Application::APP_ID,
        ]);

        return $pending;
    }

    /**
     * Approve a pending membership request. Requires admin or owner level.
     */
    public function approveRequest(string $teamId, string $userId): void {

        $this->assertTeamNotPendingDeletion($teamId);
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

        // v3.100.8 (apps.md W-2) — API-first with fallback.
        // Try CirclesManager first so activity/notification events fire
        // (member_confirmed) and the audit picks them up. On hidden or
        // otherwise API-refusing circles, fall through to the raw UPDATE
        // that used to be the only path. The direct UPDATE is safe:
        // requireAdminLevel() and the Requesting check above have already
        // validated the operation.
        $confirmedViaApi = false;
        $singleId = (string)($mRow['single_id'] ?? '');
        try {
            $circlesMgr = $this->getCirclesManager();
            $circle = $circlesMgr->getCircle($teamId);
            foreach ($circle->getInheritedMembers(false) as $m) {
                if ($m->getUserId() === $userId
                    && strcasecmp($m->getStatus(), 'Requesting') === 0
                ) {
                    if (method_exists($circlesMgr, 'confirmMemberRequest')) {
                        $circlesMgr->confirmMemberRequest($m);
                        $confirmedViaApi = true;
                    }
                    break;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][MemberService] approveRequest: CirclesManager path unavailable — using DB fallback', [
                'teamId' => $teamId, 'userId' => $userId,
                'reason' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        try {
            if (!$confirmedViaApi) {
                $approveQb = $db->getQueryBuilder();
                $approveQb->update('circles_member')
                    ->set('status', $approveQb->createNamedParameter('Member'))
                    ->set('level',  $approveQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    ->where($approveQb->expr()->eq('circle_id', $approveQb->createNamedParameter($teamId)))
                    ->andWhere($approveQb->expr()->eq('user_id',  $approveQb->createNamedParameter($userId)))
                    ->andWhere($approveQb->expr()->eq('status',  $approveQb->createNamedParameter('Requesting')))
                    ->executeStatement();
            }

            $this->logger->info('[TeamHub][MemberService] approveRequest: member approved', [
                'teamId' => $teamId, 'userId' => $userId,
                'viaApi' => $confirmedViaApi,
                'singleId' => $singleId,
                'app' => Application::APP_ID,
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

            // Add the newly approved member to the team's Talk room.
            $this->logger->debug('[TeamHub][MemberService] approveRequest: syncing approved user to Talk room', [
                'userId' => $userId, 'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
            $this->talkService->syncUserToTeamTalkRoom($teamId, $userId);

            // v4.7.9 (GitHub #87) — see requestJoinTeam: every membership change
            // ends the same way, whatever targeted call preceded it.
            $this->resourceMembership->reconcileTeamMembership($teamId, 'join_approved');
        } catch (\Exception $e) {
            $this->logger->error('[TeamHub][MemberService] Error approving request', [
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

            $this->logger->info('[TeamHub][MemberService] rejectRequest: request rejected via direct DB delete', [
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
            $this->logger->error('[TeamHub][MemberService] Error rejecting request', [
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
    /**
     * Search users/groups/teams for the invite picker.
     *
     * @param string $query    Search term (minimum 2 chars enforced at controller level)
     * @param int    $limit    Max results per type (default 10)
     * @param string $teamId   Optional — parent team ID. When provided, 'circle' results
     *                         exclude the team itself, teams already added, and teams
     *                         that would create a direct circular nesting (A→B→A).
     */
    public function searchUsers(string $query, int $limit = 10, string $teamId = ''): array {

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

        // TeamHub teams / Circles (user_type=16)
        //
        // Prior to v3.40.1 these were excluded because nesting was believed to
        // corrupt Circles' visibility queries. That corruption was caused by the
        // CFG_SINGLE bit-encoding bug fixed in v3.39.1. With that resolved, adding
        // one team as a member of another is safe.
        //
        // Safety gates (applied here in search AND enforced again in inviteMembers):
        //   • Exclude the parent team itself (can't add a team to itself).
        //   • Exclude teams already directly added as members of $teamId.
        //   • Exclude teams where $teamId is already a direct member (A→B and now
        //     B→A would be a direct circular nesting; Circles cache rebuild would loop).
        //
        // Only circles with source=16 (user-created teams) are surfaced. Personal
        // circles (source=1) and group-backed circles (source=2) are excluded —
        // they appear via the 'user' and 'group' flows respectively.
        if (in_array('circle', $allowedTypes, true)) {
            try {
                $db = $this->container->get(\OCP\IDBConnection::class);

                // Collect unique_ids to exclude from results
                $excludeIds = [];

                // 1. Exclude the parent team itself
                if ($teamId !== '') {
                    $excludeIds[] = $teamId;
                }

                // 2. Exclude circles already a direct member of $teamId
                // (already in circles_member with user_type=16 for this parent circle)
                if ($teamId !== '') {
                    $exQb  = $db->getQueryBuilder();
                    $exRes = $exQb->select('single_id')
                        ->from('circles_member')
                        ->where($exQb->expr()->eq('circle_id', $exQb->createNamedParameter($teamId)))
                        ->andWhere($exQb->expr()->eq('user_type', $exQb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->andWhere($exQb->expr()->eq('status', $exQb->createNamedParameter('Member')))
                        ->executeQuery();
                    while ($r = $exRes->fetch()) {
                        $excludeIds[] = (string)$r['single_id'];
                    }
                    $exRes->closeCursor();
                }

                // 3. Exclude circles that already have $teamId as a direct member
                // (would create A→B→A circular nesting)
                if ($teamId !== '') {
                    $ciQb  = $db->getQueryBuilder();
                    $ciRes = $ciQb->select('circle_id')
                        ->from('circles_member')
                        ->where($ciQb->expr()->eq('single_id', $ciQb->createNamedParameter($teamId)))
                        ->andWhere($ciQb->expr()->eq('user_type', $ciQb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->andWhere($ciQb->expr()->eq('status', $ciQb->createNamedParameter('Member')))
                        ->executeQuery();
                    while ($r = $ciRes->fetch()) {
                        $excludeIds[] = (string)$r['circle_id'];
                    }
                    $ciRes->closeCursor();
                }

                $excludeIds = array_unique(array_filter($excludeIds));

                // Query circles_circle for user-created teams matching the search term
                $cQb = $db->getQueryBuilder();
                $cQb->select('c.unique_id', 'c.name')
                    ->from('circles_circle', 'c')
                    ->where(
                        $cQb->expr()->eq('c.source', $cQb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    )
                    ->andWhere(
                        // like() is case-insensitive on MySQL (default collation) and
                        // case-sensitive on PostgreSQL — acceptable for team name search.
                        // iLike() is not available on all NC/Doctrine DBAL versions.
                        $cQb->expr()->like('c.name', $cQb->createNamedParameter('%' . $db->escapeLikeParameter($query) . '%'))
                    )
                    ->andWhere(
                        // Exclude teams with CFG_ROOT (8192) set — that is the bit both
                        // Contacts and TeamHub use for "Prevent this team from being a
                        // member of another team". Showing a prevented team in search
                        // results would produce a silent failure when the invite is sent.
                        $cQb->createFunction('(c.config & ' . \OCA\TeamHub\Constants\CirclesConfig::CFG_ROOT . ')') . ' = 0'
                    )
                    ->setMaxResults($limit);

                if (!empty($excludeIds)) {
                    $cQb->andWhere(
                        $cQb->expr()->notIn('c.unique_id', $cQb->createNamedParameter($excludeIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY))
                    );
                }

                $cRes = $cQb->executeQuery();
                while ($cRow = $cRes->fetch()) {
                    $results[] = [
                        'id'          => (string)$cRow['unique_id'],
                        'displayName' => (string)$cRow['name'],
                        'type'        => 'circle',
                        'icon'        => 'circle',
                    ];
                }
                $cRes->closeCursor();

                $this->logger->debug('[TeamHub][MemberService] searchUsers: circle search', [
                    'query'      => $query,
                    'teamId'     => $teamId,
                    'excluded'   => count($excludeIds),
                    'found'      => count(array_filter($results, fn ($r) => $r['type'] === 'circle')),
                    'app'        => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][MemberService] searchUsers: circle search failed (non-fatal)', [
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
