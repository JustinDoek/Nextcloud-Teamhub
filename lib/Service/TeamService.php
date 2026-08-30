<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\CirclesConfig;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\TeamImageService;
use OCA\TeamHub\Db\PendingDeletionMapper;
use OCA\TeamHub\Db\TeamAppMapper;
use OCA\TeamHub\Db\TeamTypeMapper;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * TeamService — team CRUD, config, admin settings, and debug utilities.
 *
 * Refactored in v2.25.0: member management, resource lookup/provisioning,
 * and activity/calendar operations have been extracted to dedicated services:
 *   @see MemberService
 *   @see ResourceService
 *   @see ActivityService
 *
 * This service now owns:
 *   - getUserTeams()          list teams the current user belongs to
 *   - getTeam()               single-team lookup
 *   - createTeam()            create a new circle
 *   - updateTeamDescription() write description to circles_circle
 *   - updateTeamConfig()      bitmask write with unmanaged-bit preservation
 *   - getTeamConfig()         raw bitmask read
 *   - deleteTeam()            owner-only destroy + cleanup
 *   - browseAllTeams()        discover visible/joined teams
 *   - getTeamApps()           TeamHub app config per team
 *   - updateTeamApps()        upsert app rows
 *   - getAdminSettings()      read IConfig app values
 *   - saveAdminSettings()     write IConfig app values
 *   - debug*()                emergency debug endpoints (no routes in prod)
 */
class TeamService {

    /** @var \OCA\Circles\CirclesManager|null */
    private $circlesManager = null;

    /** Memoised result of the Circles-avatar capability probe (per request). */
    private ?bool $teamsAvatarSupported = null;

    public function __construct(
        private MemberService        $memberService,
        private ResourceService      $resourceService,
        private ActivityService      $activityService,
        private CollectivesService   $collectivesService,
        private TeamAppMapper        $teamAppMapper,
        private IUserSession         $userSession,
        private IAppManager          $appManager,
        private ContainerInterface   $container,
        private LoggerInterface      $logger,
        private IUserManager         $userManager,
        private TeamImageService     $teamImageService,
        private AuditService         $auditService,
        private PendingDeletionMapper $pendingMapper,
        private GroupFolderService   $groupFolderService,
        private TeamTypeMapper       $teamTypeMapper,
    ) {
    }

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

    /**
     * Whether this instance's Circles app exposes the per-team avatar OCS API
     * (GET/POST/DELETE /ocs/v2.php/apps/circles/circles/{id}/avatar), which
     * first shipped in Circles/Nextcloud 34.
     *
     * On Circles < 34 (Nextcloud 32/33) there is no NC-side team picture, so
     * TeamHub keeps serving its own app-data image via {@see TeamImageService}
     * and this returns false. The value is instance-global; it is surfaced
     * per team as `nc_avatar_supported` in the team payload purely so the
     * frontend can decide whether to try the Teams-native avatar (falling back
     * to the legacy `image_url`) without a separate mount-time round-trip.
     *
     * Fails closed: any error determining the version returns false, so we
     * never point the frontend at an endpoint that may not exist.
     */
    private function isTeamsAvatarSupported(): bool {
        if ($this->teamsAvatarSupported !== null) {
            return $this->teamsAvatarSupported;
        }
        $supported = false;
        try {
            if ($this->appManager->isInstalled('circles')) {
                $version = $this->appManager->getAppVersion('circles');
                $major = (int)explode('.', $version)[0];
                $supported = $major >= 34;
            }
        } catch (\Throwable $e) {
            $supported = false;
        }
        return $this->teamsAvatarSupported = $supported;
    }

    // =========================================================================
    // Team listing
    // =========================================================================

    /**
     * Get all teams the current user is a member of.
     *
     * Covers both direct members (user_type=1 row in circles_member) AND
     * indirect members (added via a group or another team that is a member).
     * Indirect membership is tracked in circles_membership — Circles' own
     * denormalised cache — keyed on the user's personal-circle single_id.
     *
     * Reads directly from DB — probeCircles() on this instance filters out all
     * circles with non-zero config, so it cannot be used as the team list source.
     */
    public function getUserTeams(): array {

        $user = $this->userSession->getUser();
        if (!$user) {
            $this->logger->warning('[TeamHub][TeamService] getUserTeams called without authenticated user', ['app' => Application::APP_ID]);
            return [];
        }

        if (!$this->appManager->isInstalled('circles')) {
            $this->logger->warning('[TeamHub][TeamService] Circles app is not enabled', ['app' => Application::APP_ID]);
            return [];
        }

        try {
            $db  = $this->container->get(\OCP\IDBConnection::class);
            $uid = $user->getUID();

            // Resolve the user's personal-circle single_id so we can check
            // circles_membership for indirect (group/team) membership.
            $userSingleId = $this->memberService->resolveUserSingleId($uid, $db);
            $this->logger->debug('[TeamHub][TeamService] getUserTeams: resolved single_id', [
                'uid' => $uid, 'singleId' => $userSingleId, 'app' => Application::APP_ID,
            ]);

            // ── Step 1: fetch teams the user belongs to (direct OR indirect) ─
            //
            // Strategy: LEFT JOIN on direct membership (user_type=1) AND on the
            // circles_membership cache (indirect via group/team).
            // Include the circle if:
            //   (a) the user has a direct member row (m.user_id IS NOT NULL), OR
            //   (b) the user appears in circles_membership (ms.single_id IS NOT NULL)
            //
            // This mirrors the same pattern used by browseAllTeams().
            $qb = $db->getQueryBuilder();
            $qb->select('c.unique_id', 'c.name', 'c.description', 'c.config',
                        'm.level', 'm.user_id AS direct_uid')
               ->from('circles_circle', 'c')
               ->leftJoin(
                   'c',
                   'circles_member',
                   'm',
                   $qb->expr()->andX(
                       $qb->expr()->eq('m.circle_id',  'c.unique_id'),
                       $qb->expr()->eq('m.user_id',    $qb->createNamedParameter($uid)),
                       $qb->expr()->eq('m.user_type',  $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)),
                       $qb->expr()->eq('m.status',     $qb->createNamedParameter('Member'))
                   )
               );

            // Add LEFT JOIN on circles_membership for indirect access detection
            if ($userSingleId) {
                $qb->addSelect('ms.single_id AS ms_single_id')
                   ->leftJoin(
                       'c',
                       'circles_membership',
                       'ms',
                       $qb->expr()->andX(
                           $qb->expr()->eq('ms.circle_id', 'c.unique_id'),
                           $qb->expr()->eq('ms.single_id', $qb->createNamedParameter($userSingleId))
                       )
                   );
            } else {
                $qb->addSelect($qb->createFunction('NULL AS ms_single_id'));
            }

            // Only include circles where the user has direct OR indirect membership
            $qb->where(
                   $qb->expr()->orX(
                       $qb->expr()->isNotNull('m.user_id'),         // direct member row
                       $qb->expr()->isNotNull('ms.single_id')       // indirect via group/team
                   )
               )
               // v4.6.17 — LOWER(), not the raw column. Postgres sorts by byte
               // value, so every capital letter sorts before every lowercase
               // one and the sidebar read `DEsign, DJ tool, DS now, Design
               // Guild, Dit is nieuws` — three names interleaved by the case of
               // their *second* character, with `publicOne` stranded after
               // `Website Redesign`. MySQL's default collation is already
               // case-insensitive, which is why this survived: the bug is
               // invisible on half the databases we support.
               //
               // `LOWER(col)` + the portability note in HANDOFF's Circles
               // section is the same tool used for case-insensitive search;
               // there is no `iLower`, and a functional ORDER BY costs nothing
               // on a list this size.
               ->orderBy($qb->createFunction('LOWER(c.name)'), 'ASC');

            $result = $qb->executeQuery();
            $rows   = [];
            $ids    = [];

            while ($row = $result->fetch()) {
                $name = $row['name'] ?? '';
                if (str_starts_with($name, 'user:') || str_starts_with($name, 'group:')) {
                    continue;
                }
                // Deduplicate: a user could match both the direct AND indirect JOIN
                // (e.g. directly added AND member of an added group). unique_id is
                // the dedup key; skip if already collected.
                if (isset($ids[$row['unique_id']])) {
                    continue;
                }
                $ids[$row['unique_id']] = true;
                $rows[] = $row;
            }
            $result->closeCursor();

            $ids = array_keys($ids);

            // ── Filter: exclude teams pending deletion ────────────────────────
            // A pending-deletion team is hidden from all member list queries.
            // Only the admin pending-deletions endpoint surfaces these teams.
            if (!empty($ids)) {
                $pdQb  = $db->getQueryBuilder();
                $pdRes = $pdQb->select('team_id')
                    ->from('teamhub_pending_dels')
                    ->where($pdQb->expr()->in(
                        'team_id',
                        $pdQb->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)
                    ))
                    ->andWhere($pdQb->expr()->eq('status', $pdQb->createNamedParameter('pending')))
                    ->executeQuery();
                $pendingIds = [];
                while ($pdRow = $pdRes->fetch()) {
                    $pendingIds[$pdRow['team_id']] = true;
                }
                $pdRes->closeCursor();
                if (!empty($pendingIds)) {
                    $ids  = array_values(array_filter($ids, fn($id) => !isset($pendingIds[$id])));
                    $rows = array_values(array_filter($rows,  fn($r) => !isset($pendingIds[$r['unique_id']])));
                }
            }

            $this->logger->debug('[TeamHub][TeamService] getUserTeams: found teams', [
                'uid' => $uid, 'count' => count($ids), 'singleId' => $userSingleId, 'app' => Application::APP_ID,
            ]);

            if (empty($ids)) {
                return [];
            }

            // ── Step 2: member counts for all teams (1 query) ────────────────
            $cqb = $db->getQueryBuilder();
            $cqb->select('circle_id', $cqb->func()->count('*', 'cnt'))
                ->from('circles_member')
                ->where($cqb->expr()->in('circle_id', $cqb->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($cqb->expr()->eq('status', $cqb->createNamedParameter('Member')))
                ->groupBy('circle_id');
            $cRes = $cqb->executeQuery();
            $memberCounts = [];
            while ($cRow = $cRes->fetch()) {
                $memberCounts[$cRow['circle_id']] = (int)$cRow['cnt'];
            }
            $cRes->closeCursor();

            // ── Step 3: unread counts — one JOIN query across all teams ──────
            // LEFT JOIN teamhub_last_seen to get the per-team last-seen timestamp
            // in a single query. Count messages by others newer than that threshold.
            // NULL last_seen_at (user never visited) → COALESCE to 0 → all messages
            // are newer → they're all unread.
            $unreadCounts = [];
            if (!empty($ids)) {
                $urQb  = $db->getQueryBuilder();
                $urRes = $urQb->select('m.team_id')
                    ->addSelect($urQb->createFunction('COUNT(*) AS unread_count'))
                    ->from('teamhub_messages', 'm')
                    ->leftJoin(
                        'm',
                        'teamhub_last_seen',
                        'ls',
                        $urQb->expr()->andX(
                            $urQb->expr()->eq('ls.team_id', 'm.team_id'),
                            $urQb->expr()->eq('ls.user_id', $urQb->createNamedParameter($uid))
                        )
                    )
                    ->where($urQb->expr()->in('m.team_id', $urQb->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
                    ->andWhere($urQb->expr()->neq('m.author_id', $urQb->createNamedParameter($uid)))
                    ->andWhere(
                        $urQb->expr()->gt(
                            'm.created_at',
                            $urQb->createFunction('COALESCE(ls.last_seen_at, 0)')
                        )
                    )
                    ->groupBy('m.team_id')
                    ->executeQuery();
                while ($urRow = $urRes->fetch()) {
                    $unreadCounts[$urRow['team_id']] = (int)$urRow['unread_count'];
                }
                $urRes->closeCursor();
            }

            $this->logger->info('[TeamHub][TeamService] getTeams: unread counts computed', [
                'uid'    => $uid,
                'teams'  => count($ids),
                'unread' => array_filter($unreadCounts),
                'app'    => Application::APP_ID,
            ]);

            // ── Assemble result ───────────────────────────────────────────────
            $teams = [];
            foreach ($rows as $row) {
                $id     = $row['unique_id'];
                $unread = $unreadCounts[$id] ?? 0;

                // Current user's role level in THIS team. The SQL LEFT JOIN
                // returns m.level for a direct member row; NULL for indirect
                // access (via a group or sub-team). Indirect members have no
                // moderation rights, so 0 is the correct representation for
                // the frontend's role-gated sidebar actions.
                $level = $row['level'] !== null ? (int)$row['level'] : 0;

                // URL and storage together — one app-data walk per team rather
                // than two. Every team in this list is one the viewer belongs
                // to, so the image needs no further membership gate here.
                $image = $this->teamImageService->describeImage($id);

                $teams[] = [
                    'id'          => $id,
                    'name'        => $row['name'],
                    'description' => $row['description'] ?? '',
                    'members'     => $memberCounts[$id] ?? 0,
                    'unread'      => $unread,
                    'image_url'   => $image['url'],
                    // v4.6.25 — 'nc' | 'legacy' | null. The frontend reads this
                    // to find the teams that still carry a TeamHub-era picture
                    // and move them into Teams (DESIGN §2.68's one-way
                    // migration). It exists so that migration can be driven off
                    // a fact the server already knows instead of probing every
                    // team over the network to discover it has nothing.
                    'image_source' => $image['source'],
                    // NC 34+ only: signals the frontend to prefer the Teams-native
                    // avatar (with image_url as fallback). See isTeamsAvatarSupported().
                    'nc_avatar_supported' => $this->isTeamsAvatarSupported(),
                    // Circles config bitmask — exposed so the frontend can render
                    // human-readable "team type" labels (open/invite/public/etc).
                    'config'      => (int)($row['config'] ?? 0),
                    // Current user's role in this team (0 = indirect member,
                    // 1 = member, 4 = moderator, 8 = admin, 9 = owner). Used by
                    // the sidebar 3-dot action menu to gate Manage team (>=8),
                    // Invite (>=4), Copy link (>=1) and Leave team (1..8).
                    'level'       => $level,
                ];
            }


            return $teams;

        } catch (\Exception $e) {
            $this->logger->error('[TeamHub][TeamService] Error in getUserTeams', ['exception' => $e, 'app' => Application::APP_ID]);
            return [];
        }
    }

    /**
     * Get a specific team by ID.
     */
    public function getTeam(string $teamId): array {

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        // Access check: verify the user is a member via direct DB query OR indirect
        // via circles_membership (group/sub-team membership).
        $db  = $this->container->get(\OCP\IDBConnection::class);
        $uid = $user->getUID();
        $accessLevel = $this->memberService->getMemberLevelFromDb($db, $teamId, $uid);
        if ($accessLevel < 1) {
            // Not a direct member — check indirect membership via circles_membership
            if (!$this->memberService->isEffectiveMember($teamId, $uid, $db)) {
                $this->logger->debug('[TeamHub][TeamService] getTeam: access denied (not direct or indirect member)', [
                    'uid' => $uid, 'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
                throw new \Exception('Team not found or access denied');
            }
            $this->logger->debug('[TeamHub][TeamService] getTeam: access granted via indirect membership', [
                'uid' => $uid, 'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
        }

        // v3.100.8 (apps.md R-1) — resolve team via CirclesManager where
        // available; the caller has already been proven a member above so
        // the API's caller-scoped visibility fits. Fall back to raw SELECT
        // if Circles is unavailable or the API throws.
        $name = null;
        $description = '';
        $config = 0;
        $viaApi = false;
        try {
            $circlesMgr = $this->getCirclesManager();
            $circle = $circlesMgr->getCircle($teamId);
            $name = $circle->getName();
            $description = (string)$circle->getDescription();
            $config = (int)$circle->getConfig();
            $viaApi = true;
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][TeamService] getTeam: CirclesManager path unavailable — using DB fallback', [
                'teamId' => $teamId, 'reason' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
        }

        if (!$viaApi) {
            $qb  = $db->getQueryBuilder();
            $res = $qb->select('c.unique_id', 'c.name', 'c.description', 'c.config')
                ->from('circles_circle', 'c')
                ->where($qb->expr()->eq('c.unique_id', $qb->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if (!$row) {
                throw new \Exception('Team not found');
            }
            $name = $row['name'];
            $description = $row['description'] ?? '';
            $config = (int)($row['config'] ?? 0);
        }

        // Member count — direct SELECT retained: no OCP scalar for this
        // and computing it via CirclesManager::getCircle()->getMembers()
        // hydrates every member entity (expensive on large teams).
        $countQb  = $db->getQueryBuilder();
        $countRes = $countQb->select($countQb->func()->count('*', 'cnt'))
            ->from('circles_member')
            ->where($countQb->expr()->eq('circle_id', $countQb->createNamedParameter($teamId)))
            ->andWhere($countQb->expr()->eq('status', $countQb->createNamedParameter('Member')))
            ->executeQuery();
        $countRow    = $countRes->fetch();
        $memberCount = $countRow ? (int)$countRow['cnt'] : 0;
        $countRes->closeCursor();


        return [
            'id'          => $teamId,
            'name'        => $name,
            'description' => $description,
            'members'     => $memberCount,
            'image_url'   => $this->teamImageService->getImageUrl($teamId),
            'nc_avatar_supported' => $this->isTeamsAvatarSupported(),
            // Circles config bitmask — exposed so the frontend can render
            // human-readable "team type" labels (open/invite/public/etc).
            'config'      => $config,
        ];
    }

    /**
     * The little a non-member is allowed to know about a team they hold a link to.
     *
     * `getTeam()` above refuses anyone who is not already a member, which is
     * correct for every surface that shows a team's contents — and is exactly
     * why a copied team link used to lead nowhere. This is the deliberate
     * exception: enough to decide whether to join, and nothing else.
     *
     * **What it discloses, and to whom.** Any authenticated user who supplies a
     * team id learns that team's name and how to get in. That is the feature —
     * a link is worth nothing if the recipient cannot see what they are being
     * offered. It is not an enumeration surface: a Circles `unique_id` is a
     * 31-character random token, so possession of one is possession of the
     * link. Malformed ids are rejected before any query runs, and Circles'
     * internal `user:` / `group:` auto-circles are filtered out so a personal
     * circle can never be resolved to its owner's name through here.
     *
     * **A closed team discloses its name only.** Description and image are
     * withheld: on an invite-only team they are content, and the only thing the
     * holder of the link needs is to learn that the link is not for them.
     *
     * @return array{id: string, name: string, description: string,
     *               image_url: ?string, joinPolicy: string, membership: string}
     * @throws \Exception when the id is malformed or no such team exists.
     */
    public function getTeamPreview(string $teamId): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        // Same cheap shape guard the user search uses: bind protects against
        // injection, but not against a fuzzer spending our SELECTs for us.
        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $teamId)) {
            throw new \Exception('Team not found');
        }

        $db  = $this->container->get(\OCP\IDBConnection::class);
        $uid = $user->getUID();

        // Read the circle directly rather than through CirclesManager: the
        // caller is by definition not a member, so the API's caller-scoped
        // visibility would hide the very row we are here to describe.
        //
        // `source = 16` is the gate, not a name-prefix test. A Circles install
        // also holds personal circles (source 1, named `user:…`), group circles
        // (2, `group:…`) and app-owned circles (10001, `app:…`), none of which
        // is a team and none of which may be described to anybody through here.
        // TeamSearchProvider and MaintenanceService identify a team the same
        // way; browseAllTeams() filters the two `user:`/`group:` name prefixes
        // instead, which would let an `app:` circle through — verified present
        // on the test instance — so this does not copy that test.
        $qb  = $db->getQueryBuilder();
        $res = $qb->select('c.unique_id', 'c.name', 'c.description', 'c.config')
            ->from('circles_circle', 'c')
            ->where($qb->expr()->eq('c.unique_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('c.source', $qb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();

        if (!$row) {
            throw new \Exception('Team not found');
        }

        $name       = (string)($row['name'] ?? '');
        $config     = (int)($row['config'] ?? 0);
        $joinPolicy = CirclesConfig::joinPolicy($config);
        $membership = $this->resolvePreviewMembership($db, $teamId, $uid);
        $isClosed   = $joinPolicy === CirclesConfig::JOIN_CLOSED;

        return [
            'id'          => $teamId,
            'name'        => $name,
            'description' => $isClosed ? '' : (string)($row['description'] ?? ''),
            // v4.6.25 — members only, the same boundary Circles puts on its own
            // avatar route. This is narrower than the description rule directly
            // above it, deliberately: a description is TeamHub's own text and a
            // non-closed team volunteers it, whereas the picture is served by a
            // route that refuses non-members. Returning a URL a link-holder
            // cannot load only produced a broken image and a failed request in
            // the console. See DESIGN §2.95.
            'image_url'   => $membership === 'member'
                ? $this->teamImageService->getImageUrl($teamId)
                : null,
            'joinPolicy'  => $joinPolicy,
            'membership'  => $membership,
        ];
    }

    /**
     * Where the current user already stands with a team they are previewing:
     * `member` (direct or via a group), `requesting` (waiting on a moderator),
     * or `none`.
     *
     * `requesting` is the state that has no other way of being seen — a pending
     * request keeps the user out of `getUserTeams()`, which filters on
     * `status = 'Member'`, so without this they would be shown the join button
     * they already pressed.
     */
    private function resolvePreviewMembership(\OCP\IDBConnection $db, string $teamId, string $uid): string {
        if ($this->memberService->getMemberLevelFromDb($db, $teamId, $uid) >= 1
            || $this->memberService->isEffectiveMember($teamId, $uid, $db)
        ) {
            return 'member';
        }

        $qb  = $db->getQueryBuilder();
        $res = $qb->select('status')
            ->from('circles_member')
            ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();

        // 'Requesting' is Circles' pending-join status; 'Invited' means the team
        // has already reached out, so the join controls are equally beside the
        // point. Both are reported as a request already in flight.
        $status = (string)($row['status'] ?? '');
        if (strcasecmp($status, 'Requesting') === 0 || strcasecmp($status, 'Invited') === 0) {
            return 'requesting';
        }

        return 'none';
    }

    // =========================================================================
    // Team CRUD
    // =========================================================================

    /**
     * Characters a new team name may not contain (v4.6.18).
     *
     * A blocklist, not an allowlist — see `assertValidTeamName()` for why the
     * ASCII allowlist this replaced was the wrong shape. Each range earns its
     * place by breaking something concrete:
     *
     *   - `/` and `\` — NC's `Folder::newFolder()` resolves them as path
     *     separators, so "A/B" silently becomes folder "A" containing "B".
     *     This is the v4.3.19 bug the original rule was written for and it is
     *     the one entry here that is genuinely about correctness rather than
     *     abuse.
     *   - `\x00-\x1F`, `\x7F` — control characters. A newline in a team name
     *     splits a log line and lets a name forge a second entry; NUL
     *     truncates on any C-string boundary it reaches.
     *   - `U+200B-U+200F`, `U+202A-U+202E`, `U+2066-U+2069`, `U+FEFF` —
     *     zero-width and bidirectional-override codepoints. These are the
     *     homograph-spoofing set: `U+202E` (RTL override) renders
     *     "Marketing<RLO>gnp.exe" as "Marketing exe.png", and the zero-width
     *     ones make two visually identical names that compare as different —
     *     which would defeat the uniqueness check below.
     *
     * Everything else is legal, including accented Latin letters, non-Latin
     * scripts, emoji, and ordinary punctuation. See the note on injection in
     * `assertValidTeamName()`.
     */
    private const NAME_FORBIDDEN_PATTERN =
        '/[\/\\\\\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    /**
     * Validate a team name for NEW team creation.
     *
     * Applies only to `createTeam()` and the CSV importer — existing teams
     * (created before this guard landed) may contain any character and are
     * treated as opaque strings by the rest of the app.
     *
     * Rules:
     *   - Non-empty after trim, and valid UTF-8.
     *   - ≤ 120 chars.
     *   - No character from `NAME_FORBIDDEN_PATTERN`.
     *   - Not composed entirely of dots.
     *
     * v4.6.19 — **the length cap dropped from 255 to 120, because 255 could not
     * be stored.** `circles_circle.name` is `varchar(127)`; the 255-character
     * column on that table is `display_name`, and the old cap had been written
     * against the wrong one. Circles' `Circle::setName()` does not truncate, so
     * a name of 128–255 characters passed validation here and then failed at
     * the insert — Postgres raising `value too long for type character
     * varying(127)`, MySQL raising in strict mode and silently truncating
     * outside it. The wizard surfaced that as a raw "Failed to create team: …"
     * and the CSV importer as a failed row, in both cases naming a database
     * error for what is a length the user could have been told about up front.
     *
     * 120 rather than 127: `mb_strlen()` counts codepoints and both databases
     * count characters in a `varchar`, so 127 would fit exactly — but leaving
     * the cap flush against the column means any future column-width change,
     * or any caller that appends a suffix before storing, overflows silently
     * again. Seven characters of headroom costs nothing at a length nobody
     * reaches deliberately.
     *
     * v4.6.18 — **the ASCII allowlist became a harm-based blocklist.** The old
     * rule was `/^[A-Za-z0-9 _-]+$/`, which rejected every accented character
     * in the six languages this app ships in: "Café Crew", "Zürich Ø" and
     * "Ingénierie" were all illegal team names. That produced a steady stream
     * of requests to re-admit one character at a time — `_` in v4.6.9 was the
     * last of them — and each one was argued on its own merits because the
     * rule had no principle to appeal to. It now has one: a character is
     * forbidden when it breaks something, and the list of things it can break
     * is finite and written down above.
     *
     * The docblock this replaces justified the allowlist with "non-ASCII /
     * punctuation cause downstream issues in Deck board titles, Talk room
     * names, Group Folder mount paths, and Collectives / IntraVox page slugs".
     * That was over-broad. Deck titles and Talk room names are free-form
     * strings; Collectives slugifies with its own `NodeHelper::sanitiseFilename`
     * and we read `getSlug()` back rather than deriving one; and the filesystem
     * paths are defended independently by
     * `FilesService::sanitiseForFolderName()`, which strips path separators and
     * control characters from whatever it is handed. The `/` case was real —
     * it is item one of the blocklist and is now the rule's actual subject.
     *
     * **On injection**, which is the reason usually given for a strict rule
     * here: it is not what makes a name safe in this codebase. Every query goes
     * through `QueryBuilder` with bound parameters, so an apostrophe in a team
     * name is inert at the database — a name rule is the wrong layer to defend
     * that, and one that let `'` through would still be safe. Rendering is the
     * same story: Vue escapes interpolated text, no `v-html` site is ever
     * handed a team name, and `templates/timeline.php` escapes with
     * `htmlspecialchars()` and is passed only a team id. If either of those
     * facts ever stops holding, the fix belongs at that site and not here,
     * because this rule protects new teams only and every pre-existing team
     * would walk straight past it.
     *
     * @throws \InvalidArgumentException with a message safe to surface to the user.
     */
    public function assertValidTeamName(string $name): void {
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Team name cannot be empty.');
        }
        // Checked before the pattern below, not after: `preg_match()` with the
        // `/u` modifier returns **false** on malformed UTF-8, and `false === 1`
        // is false — so an invalid-encoding name would read as "no forbidden
        // character found" and be admitted by the very check meant to stop it.
        if (!mb_check_encoding($trimmed, 'UTF-8')) {
            throw new \InvalidArgumentException('Team name is not valid text (it must be valid UTF-8).');
        }
        if (mb_strlen($trimmed) > 120) {
            throw new \InvalidArgumentException('Team name is too long (max 120 characters).');
        }
        if (preg_match(self::NAME_FORBIDDEN_PATTERN, $trimmed) === 1) {
            throw new \InvalidArgumentException('Team name may not contain slashes, control characters, or invisible formatting characters.');
        }
        // "." and ".." are the filesystem's own names for a directory and its
        // parent; a longer run of dots is the same trick with less to gain but
        // no more legitimacy. `sanitiseForFolderName()` already substitutes
        // "team" for the first two, so this is about rejecting the name at
        // input rather than silently creating a team whose folder is called
        // something else.
        if (preg_match('/^\.+$/', $trimmed) === 1) {
            throw new \InvalidArgumentException('Team name cannot consist only of dots.');
        }
    }

    /**
     * Circles rows that are not teams, and must not reserve a team name
     * (v4.6.18).
     *
     * `circles_circle` holds more than teams, and on a live instance most of
     * its rows are not one. Each excluded bit is a class of row that carries a
     * `display_name` a person might reasonably want to call a team:
     *
     *   - `CFG_SINGLE` / `CFG_PERSONAL` — the per-user personal circle. Its
     *     `display_name` is the account's first name, so leaving these in would
     *     stop anyone creating a team called "Angela".
     *   - `CFG_SYSTEM` — group-backed circles, one per NC group. **This is the
     *     one that would have bitten**: the test instance carries a circle
     *     `group:Marketing` whose `display_name` is exactly "Marketing", which
     *     is also a real team. A check that did not exclude these would report
     *     every group name as taken, and would have called the existing
     *     Marketing team a duplicate of the group it has nothing to do with.
     *   - `CFG_ROOT` — the `app:circles` row.
     *
     * Verified against the instance: with these four excluded the table yields
     * exactly the 12 real teams and nothing else.
     */
    private const NAME_UNIQUENESS_EXCLUDED_BITS =
        CirclesConfig::CFG_SINGLE
        | CirclesConfig::CFG_PERSONAL
        | CirclesConfig::CFG_SYSTEM
        | CirclesConfig::CFG_ROOT;

    /**
     * Assert that no existing team already uses this name (v4.6.18).
     *
     * Separate from `assertValidTeamName()` on purpose, and the reason is
     * `TeamExportService`: it validates the names of **existing** teams to warn
     * that a name could not be re-created today. Folding uniqueness into the
     * character rule would make every team on the instance report itself as a
     * duplicate of itself. Callers that are creating a team call both; callers
     * that are inspecting one call only the first.
     *
     * **Scope is the whole instance, not the caller's visible teams.** A check
     * limited to teams the creator can see leaves the hole open in the case
     * that matters — two people who cannot see each other's teams producing the
     * pair — and the shared folder still lands as "Name (1)". The cost is that
     * the refusal tells the creator a team by that name exists somewhere they
     * cannot see, so the message deliberately says nothing else: not the team's
     * id, not its owner, not whether they could join it.
     *
     * **Case-insensitivity is done by the database.** `LOWER()` runs under the
     * DB's own collation, which for non-Latin scripts and a few Latin edge
     * cases (dotted/dotless I) can disagree with PHP's `mb_strtolower()`, and
     * on a C-collation database does not fold non-ASCII at all. The
     * disagreement degrades to *admitting* a pair that differs only by the case
     * of an exotic character — i.e. back to the behaviour before this check
     * existed — rather than rejecting a name wrongly. Worth knowing, not worth
     * fetching every team's name into PHP to fold it there.
     *
     * Both `name` and `display_name` are tested. They are identical for every
     * real team on the instance (verified: zero rows diverge), but
     * `display_name` is nullable with a `''` default while `name` is `NOT NULL`,
     * so neither one alone is safe to trust as *the* name.
     *
     * @throws \InvalidArgumentException with a message safe to surface to the user.
     */
    public function assertTeamNameAvailable(string $name): void {
        $trimmed = trim($name);
        if ($trimmed === '') {
            // Emptiness belongs to assertValidTeamName(), which every caller of
            // this method runs first. Returning rather than throwing keeps the
            // two rules from reporting the same problem twice.
            return;
        }

        $db    = $this->container->get(\OCP\IDBConnection::class);
        $qb    = $db->getQueryBuilder();
        $lower = mb_strtolower($trimmed);

        // The bit test follows the established pattern in this file (see
        // browseAllTeams) — createFunction with a class constant interpolated.
        // The value is a compile-time int, never user input.
        $excluded = (int)self::NAME_UNIQUENESS_EXCLUDED_BITS;

        $qb->select('unique_id')
           ->from('circles_circle')
           ->where(
               $qb->expr()->orX(
                   $qb->expr()->eq(
                       $qb->createFunction('LOWER(name)'),
                       $qb->createNamedParameter($lower)
                   ),
                   $qb->expr()->eq(
                       $qb->createFunction('LOWER(display_name)'),
                       $qb->createNamedParameter($lower)
                   )
               )
           )
           ->andWhere(
               $qb->expr()->eq(
                   $qb->createFunction('(config & ' . $excluded . ')'),
                   $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
               )
           )
           ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row !== false) {
            throw new \InvalidArgumentException('A team with this name already exists. Team names must be unique.');
        }
    }

    /**
     * Create a new team.
     * Description is always set via updateTeamDescription() separately.
     */
    public function createTeam(string $name): array {

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        // v4.3.19 — reject path-shaped team names at the top of the flow.
        // Bug: a user typed "A/B" as a team name; downstream
        // FilesService::createSharedFolder passed the name straight to
        // NC's Folder::newFolder(), which resolves slashes as path
        // separators and quietly created "A/" with subfolder "B/"
        // instead of a single folder called "A/B". Guard the string at
        // input rather than sanitising in every downstream folder call.
        $this->assertValidTeamName($name);

        // v4.6.18 — and reject a name another team already holds. Circles does
        // not enforce this (a circle is identified by `unique_id`, so two
        // circles may share a name), and the duplicate did not stay cosmetic:
        // `FilesService::createSharedFolder()` finds the folder name taken and
        // walks a counter until it is free, so the second "Marketing" gets a
        // shared folder called "Marketing (1)" while the team itself is still
        // called "Marketing". Every other provisioned resource that derives a
        // name from the team does something similar, which is how one duplicate
        // becomes a whole team's worth of subtly mismatched resources.
        //
        // Checked here rather than inside assertValidTeamName() — see that
        // method's note on TeamExportService.
        $this->assertTeamNameAvailable($name);

        $circlesManager = $this->getCirclesManager();
        $federatedUser  = $circlesManager->getFederatedUser($user->getUID(), 1);
        $circlesManager->startSession($federatedUser);

        try {
            $circle = $circlesManager->createCircle($name);
            $result = $this->circleToArray($circle);

            // Audit log — team creation. Use the circle's unique_id (== team_id) as target.
            $teamId = (string)($result['id'] ?? $result['unique_id'] ?? '');
            if ($teamId !== '') {
                $this->auditService->log(
                    $teamId,
                    'team.created',
                    $user->getUID(),
                    'team',
                    $teamId,
                    ['name' => $name],
                );
            }


            return $result;
        } catch (\Exception $e) {
            $this->logger->error('[TeamHub][TeamService] Error creating team', ['exception' => $e, 'app' => Application::APP_ID]);
            throw new \Exception('Failed to create team: ' . $e->getMessage());
        } finally {
            $circlesManager->stopSession();
        }
    }

    /**
     * Delete a team (circle). Only the owner can do this.
     * Also cleans up TeamHub app data for this circle.
     */
    public function deleteTeam(string $teamId): void {

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        // Owner check via DB — avoids getCircle() failing on non-zero config circles.
        $db    = $this->container->get(\OCP\IDBConnection::class);
        $level = $this->memberService->getMemberLevelFromDb($db, $teamId, $user->getUID());
        if ($level < 9) {
            throw new \Exception('Only the team owner can delete a team.');
        }

        // ── Step 1: Capture metadata BEFORE any destructive operation ─────────
        // Both team name (for audit) and enabled app list (for resource cleanup)
        // must be read now — after destroy() and after the teamhub_team_apps
        // delete, neither is recoverable.
        $teamName = null;
        try {
            $qb = $db->getQueryBuilder();
            $r = $qb->select('name')
                ->from('circles_circle')
                ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $r->fetch();
            $r->closeCursor();
            if ($row !== false) {
                $teamName = (string)$row['name'];
            }
        } catch (\Throwable $e) { /* non-fatal — name is just metadata */ }

        // ── Step 2: Delete every connected NC app resource for this team ────
        //
        // v4.0.3 — Prior versions built the cleanup list from teamhub_team_apps
        // (the app-level enabled/disabled table). That table is empty for teams
        // whose admin never toggled an app, so a team with connected Talk /
        // Files / Deck / Calendar resources would keep them alive after the
        // circle was destroyed — leaving orphaned resources across Nextcloud.
        //
        // The correct source of truth for "what does this team actually have
        // connected" is teamhub_team_app_resources: one row per connected
        // resource, one team can have multiple resources of the same app.
        // ResourceService::deleteSpecificResource handles per-app ID-based
        // destruction (works whether the resource was created by TeamHub or
        // pre-existed), marks the registry row deleted, and does not depend
        // on the circle still being an ACL member.
        //
        // Iteration BEFORE circle destruction is still important — some
        // per-app deletes want the circle to exist for cross-referencing
        // (e.g. calendar principal URI), and the audit log wants to know the
        // resource was still connected at the moment of deletion.
        $delegatedApps = [];
        try {
            $qb = $db->getQueryBuilder();
            $res = $qb->select('app_id', 'resource_id')
                ->from('teamhub_team_app_resources')
                ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')))
                ->executeQuery();
            while ($row = $res->fetch()) {
                $app = (string)($row['app_id'] ?? '');
                $rid = (string)($row['resource_id'] ?? '');
                if ($app === '' || $rid === '') continue;
                $delegatedApps[$app] = true;
                try {
                    // v4.0.4 — safety check: only destroy the underlying NC
                    // resource when this team is the last team connected to it.
                    // Multi-team shares fall back to removing this team's ACL
                    // access so surviving teams keep working. Individual user
                    // shares don't preserve the resource — see docblock on
                    // ResourceService::destroyOrDetachOnTeamDelete.
                    $result = $this->resourceService->destroyOrDetachOnTeamDelete($teamId, $app, $rid);
                    $this->logger->debug('[TeamHub][TeamService] deleteTeam: resource processed', [
                        'teamId' => $teamId, 'app' => $app, 'resourceId' => $rid,
                        'result' => $result, 'class' => Application::APP_ID,
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->warning('[TeamHub][TeamService] deleteTeam: resource destruction failed', [
                        'teamId' => $teamId, 'app' => $app, 'resourceId' => $rid,
                        'error' => $e->getMessage(), 'class' => Application::APP_ID,
                    ]);
                }
            }
            $res->closeCursor();
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamService] deleteTeam: could not read teamhub_team_app_resources', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // Intravox has no per-resource row in teamhub_team_app_resources —
        // the page-to-team association lives in teamhub_team_apps (enabled=1
        // implies a page exists). Handle it via the per-app delete path.
        try {
            $qb = $db->getQueryBuilder();
            $res = $qb->select('app_id')
                ->from('teamhub_team_apps')
                ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
                ->executeQuery();
            while ($row = $res->fetch()) {
                $app = (string)($row['app_id'] ?? '');
                if ($app === '' || isset($delegatedApps[$app])) continue;
                try {
                    $result = $this->resourceService->deleteTeamResource($teamId, $app);
                    $this->logger->debug('[TeamHub][TeamService] deleteTeam: legacy per-app cleanup', [
                        'teamId' => $teamId, 'app' => $app, 'result' => $result,
                        'class' => Application::APP_ID,
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->warning('[TeamHub][TeamService] deleteTeam: legacy per-app cleanup failed', [
                        'teamId' => $teamId, 'app' => $app, 'error' => $e->getMessage(),
                        'class' => Application::APP_ID,
                    ]);
                }
            }
            $res->closeCursor();
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamService] deleteTeam: could not read teamhub_team_apps', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // ── Step 2b: Cascade delete the team's Collective (v4.3.3) ───────────
        // Collectives lives in appconfig-driven per-team toggle keys, not in
        // teamhub_team_app_resources or teamhub_team_apps, so it doesn't get
        // picked up by the resource-iteration loops above. Delete BEFORE the
        // circle destroy in Step 3 — deleteCollective(id, uid, deleteCircle:
        // false) needs the circle to still exist for the ACL check that
        // proves $uid is a LEVEL_ADMIN member. deleteCircle=false because
        // Step 3 handles the circle itself; passing true here would race
        // with circleService->destroy on the same row.
        // Best-effort — see CollectivesService::deleteForTeamCascade.
        $this->collectivesService->deleteForTeamCascade($teamId, $user->getUID());

        // ── Step 3: Destroy the circle ────────────────────────────────────────
        $circleService        = $this->container->get(\OCA\Circles\Service\CircleService::class);
        $federatedUserService = $this->container->get(\OCA\Circles\Service\FederatedUserService::class);

        $federatedUserService->setLocalCurrentUser($user);
        $circleService->destroy($teamId);

        // ── Step 4: Remove TeamHub metadata rows ──────────────────────────────
        try {
            $delQb = $db->getQueryBuilder();
            $delQb->delete('teamhub_team_apps')
                ->where($delQb->expr()->eq('team_id', $delQb->createNamedParameter($teamId)))
                ->executeStatement();
        } catch (\Throwable $e) { /* Not fatal */ }

        // ── Step 5: Audit log ─────────────────────────────────────────────────
        // Logged AFTER successful destroy so a failed delete doesn't produce a
        // misleading "team.deleted" row.
        $this->auditService->log(
            $teamId,
            'team.deleted',
            $user->getUID(),
            'team',
            $teamId,
            $teamName !== null ? ['name' => $teamName] : null,
        );

    }

    // =========================================================================
    // Team description and config
    // =========================================================================

    /**
     * Update the description of a team.
     * Verifies the caller has access via getCircle(), then writes directly to DB
     * since some Circles versions do not expose updateCircle().
     */
    public function updateTeamDescription(string $teamId, string $description): void {

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        // Access check via DB — avoids getCircle() failing on non-zero config circles.
        $db = $this->container->get(\OCP\IDBConnection::class);
        $accessLevel = $this->memberService->getMemberLevelFromDb($db, $teamId, $user->getUID());
        if ($accessLevel < 4) { // moderator or above may update description
            throw new \Exception('Access denied');
        }

        // Capture old description for the audit diff. Best-effort — falls back to ''.
        $oldDescription = '';
        try {
            $qbRead = $db->getQueryBuilder();
            $r = $qbRead->select('description', 'long_desc')
                ->from('circles_circle')
                ->where($qbRead->expr()->eq('unique_id', $qbRead->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $r->fetch();
            $r->closeCursor();
            if ($row !== false) {
                $oldDescription = (string)($row['description'] ?? $row['long_desc'] ?? '');
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        try {
            $db = $this->container->get(\OCP\IDBConnection::class);

            // Try updating the 'description' column (NC32 Circles schema).
            $qb = $db->getQueryBuilder();
            $affected = $qb->update('circles_circle')
                ->set('description', $qb->createNamedParameter($description))
                ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
                ->executeStatement();

            // Fall back to 'long_desc' for older Circles schema variants.
            if ($affected === 0) {
                $qb2 = $db->getQueryBuilder();
                $qb2->update('circles_circle')
                    ->set('long_desc', $qb2->createNamedParameter($description))
                    ->where($qb2->expr()->eq('unique_id', $qb2->createNamedParameter($teamId)))
                    ->executeStatement();
            }
        } catch (\Exception $e) {
            $this->logger->error('[TeamHub][TeamService] Error updating team description', [
                'teamId'    => $teamId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            throw new \Exception('Failed to update description: ' . $e->getMessage());
        }

        // Audit log — only if the value actually changed (buildDiff returns null otherwise).
        $diff = $this->auditService->buildDiff(
            ['description' => $oldDescription],
            ['description' => $description],
        );
        if ($diff !== null) {
            $this->auditService->log(
                $teamId,
                'team.config_changed',
                $user->getUID(),
                'team',
                $teamId,
                $diff,
            );
        }
    }

    /**
     * Update user-facing config flags on a circle.
     *
     * Writes via raw SQL (preserving unmanaged bits), then forces Circles to reload
     * the circle from DB to flush its in-process object cache.
     *
     * Bits managed by TeamHub (`CirclesConfig::MANAGED_BITS`):
     *   8=CFG_VISIBLE, 16=CFG_OPEN, 32=CFG_INVITE, 64=CFG_REQUEST,
     *   256=CFG_PROTECTED, 8192=CFG_ROOT
     * All other bits (e.g. 2=CFG_PERSONAL, 32768=CFG_FEDERATED) are preserved.
     *
     * v4.5.35 — the list above previously read "1=CFG_OPEN, 2=CFG_INVITE,
     * 4=CFG_REQUEST, 16=CFG_PROTECTED, 512=CFG_VISIBLE, 1024=CFG_SINGLE",
     * which are the **pre-3.39.1 legacy numbers** this method was fixed to
     * stop writing (see `CirclesConfig::LEGACY_MANAGED_BITS_PRE_3_39_1` and
     * `Version000339001`). The code was already correct — it reads
     * `CirclesConfig::MANAGED_BITS` — but the comment described the bug.
     *
     * Note on the raw-SQL write below: it is why a Circle carrying a
     * core-filter bit (CFG_SINGLE 1 / CFG_PERSONAL 2 / CFG_SYSTEM 4) still
     * accepts TeamHub's own config changes while rejecting Collectives'. See
     * `CirclesConfig::SYSTEM_BITS_FORBIDDEN_ON_USER_TEAMS`.
     */
    public function updateTeamConfig(string $teamId, int $config): void {

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        // Use the canonical MANAGED_BITS from CirclesConfig — the single source of truth
        // for which bits TeamHub exposes as user-facing toggles. Using hardcoded values
        // here caused the same class of bug as the CFG_SINGLE corruption in <= 3.39.0.
        $MANAGED_BITS = CirclesConfig::MANAGED_BITS;

        $db     = $this->container->get(\OCP\IDBConnection::class);
        $qb     = $db->getQueryBuilder();
        $result = $qb->select('config')
            ->from('circles_circle')
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            throw new \Exception('Circle not found in database: ' . $teamId);
        }

        $currentConfig = (int)$row['config'];
        $newConfig     = ($currentConfig & ~$MANAGED_BITS) | ($config & $MANAGED_BITS);

        $updQb = $db->getQueryBuilder();
        $updQb->update('circles_circle')
            ->set('config', $updQb->createNamedParameter($newConfig, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->where($updQb->expr()->eq('unique_id', $updQb->createNamedParameter($teamId)))
            ->executeStatement();

        // Bust APCu cache to flush Circles' in-process object cache.
        // We deliberately do NOT call $manager->getCircle() here — doing so
        // triggers Circles' internal sync logic which may re-apply config bits
        // (including CFG_SINGLE=1024) that we just cleared, corrupting the value.
        if (function_exists('apcu_delete') && class_exists('APCUIterator')) {
            try {
                foreach (new \APCUIterator('/^(circles|NC__circles)/') as $item) {
                    apcu_delete($item['key']);
                }
            } catch (\Throwable $e) { /* non-fatal */ }
        }

        // Audit log — only the managed bits, since unmanaged bits are preserved
        // verbatim and a "change" there is not user-driven.
        $oldManaged = $currentConfig & $MANAGED_BITS;
        $newManaged = $newConfig     & $MANAGED_BITS;
        if ($oldManaged !== $newManaged) {
            $this->auditService->log(
                $teamId,
                'team.config_changed',
                $user->getUID(),
                'team',
                $teamId,
                ['changed' => ['config_bits' => ['old' => $oldManaged, 'new' => $newManaged]]],
            );
        }
    }

    /**
     * Get the raw config bitmask for a circle.
     * Reads directly from DB — never from the Circles API object cache.
     */
    public function getTeamConfig(string $teamId): int {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $db     = $this->container->get(\OCP\IDBConnection::class);
        $qb     = $db->getQueryBuilder();
        $result = $qb->select('config')
            ->from('circles_circle')
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ? (int)$row['config'] : 0;
    }

    // =========================================================================
    // Browse
    // =========================================================================

    /**
     * Discover visible/joinable teams using direct DB queries.
     * Only returns circles with CFG_VISIBLE (bit 512) set, or where the
     * current user is already a member.
     */
    public function browseAllTeams(): array {

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        try {
            $db  = $this->container->get(\OCP\IDBConnection::class);
            $uid = $user->getUID();

            // Resolve the current user's personal-circle single_id once, so we can
            // check circles_membership for indirect (group/team) membership below.
            $userSingleId = $this->memberService->resolveUserSingleId($uid, $db);

            // CFG_VISIBLE (bit 9 = 512): circles marked discoverable by
            // non-members. CFG_OPEN (bit 4 = 16): circles that auto-accept
            // any join request. We push the filter into SQL so we never
            // load all circles into PHP — critical for scalability to
            // 1,000+ groups.
            //
            // Strategy: LEFT JOIN on direct membership (user_type=1) AND on the
            // circles_membership cache (indirect via group/team).
            // Include the circle if:
            //   (a) the user has a direct member row (m.user_id IS NOT NULL), OR
            //   (b) the user appears in circles_membership (ms.single_id IS NOT NULL), OR
            //   (c) the circle has CFG_VISIBLE set (config & 512 != 0), OR
            //   (d) v3.100.8 — the circle has CFG_OPEN set (config & 16 != 0).
            //       Rationale: an open circle allows anyone to join, so
            //       hiding it from browse serves no security purpose;
            //       showing it lets users actually reach the auto-approve
            //       flow this app supports (apps.md W-2 test path).
            $CFG_VISIBLE = 512;
            $CFG_OPEN    = 16;

            $qb = $db->getQueryBuilder();
            $qb->select('c.unique_id', 'c.name', 'c.description', 'c.config',
                        'm.user_id AS member_uid')
               ->from('circles_circle', 'c')
               ->leftJoin(
                   'c',
                   'circles_member',
                   'm',
                   $qb->expr()->andX(
                       $qb->expr()->eq('m.circle_id',  'c.unique_id'),
                       $qb->expr()->eq('m.user_id',    $qb->createNamedParameter($uid)),
                       $qb->expr()->eq('m.user_type',  $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)),
                       $qb->expr()->eq('m.status',     $qb->createNamedParameter('Member'))
                   )
               );

            // Add LEFT JOIN on circles_membership for indirect access detection
            if ($userSingleId) {
                $qb->addSelect('ms.single_id AS ms_single_id')
                   ->leftJoin(
                       'c',
                       'circles_membership',
                       'ms',
                       $qb->expr()->andX(
                           $qb->expr()->eq('ms.circle_id', 'c.unique_id'),
                           $qb->expr()->eq('ms.single_id', $qb->createNamedParameter($userSingleId))
                       )
                   );
            } else {
                $qb->addSelect($qb->createFunction("NULL AS ms_single_id"));
            }

            $qb->where(
                   $qb->expr()->orX(
                       // User is a direct member
                       $qb->expr()->isNotNull('m.user_id'),
                       // User appears in circles_membership (indirect via group/team)
                       $qb->expr()->isNotNull('ms.single_id'),
                       // Circle is publicly visible (CFG_VISIBLE bit set)
                       $qb->expr()->neq(
                           $qb->createFunction('(c.config & ' . $CFG_VISIBLE . ')'),
                           $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                       ),
                       // Circle is open (CFG_OPEN bit set) — v3.100.8
                       $qb->expr()->neq(
                           $qb->createFunction('(c.config & ' . $CFG_OPEN . ')'),
                           $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                       )
                   )
               )
               // Case-insensitive, for the reason spelled out on getUserTeams()'
               // own orderBy above. Browse Teams had the same byte-order
               // sorting; the sidebar is just where it was noticed.
               ->orderBy($qb->createFunction('LOWER(c.name)'), 'ASC');

            $result = $qb->executeQuery();
            $teams  = [];

            while ($row = $result->fetch()) {
                $name = $row['name'] ?? '';
                // Filter out internal Circles auto-circles (personal/group circles)
                if (str_starts_with($name, 'user:') || str_starts_with($name, 'group:')) {
                    continue;
                }

                $config           = (int)($row['config'] ?? 0);
                // CFG_OPEN = bit 4 = 16, not bit 0. The previous check was
                // reading CFG_SINGLE by accident, so every circle showed
                // requiresApproval=false regardless of its actual config
                // — masked because the migration path used to normalise the
                // bitmask elsewhere. Fixed in v3.100.8.
                //
                // v4.6.17 — and the bit it settled on was still only half the
                // rule. `requiresApproval = !isOpen` conflated "a moderator
                // must approve you" with "you cannot join at all", so an
                // invite-only team offered a Request Access button that
                // Circles refuses, while an open team that genuinely wants
                // moderator approval offered a plain Join. CirclesConfig::
                // joinPolicy() is now the only reading of these two bits.
                $joinPolicy       = CirclesConfig::joinPolicy($config);
                $isDirectMember   = $row['member_uid'] !== null;
                $isIndirectMember = !$isDirectMember && ($row['ms_single_id'] ?? null) !== null;

                $teams[] = [
                    'id'               => $row['unique_id'],
                    'name'             => $name,
                    'description'      => $row['description'] ?? '',
                    'isMember'         => $isDirectMember || $isIndirectMember,
                    'isDirectMember'   => $isDirectMember,
                    'joinPolicy'       => $joinPolicy,
                    // Retained for any caller still reading the old field; it
                    // now means what its name says and nothing more.
                    'requiresApproval' => $joinPolicy === CirclesConfig::JOIN_REQUEST,
                    // v4.6.25 — the picture follows Circles' own rule: members
                    // only, direct or inherited. Browse lists teams the viewer
                    // is not in, and the serve route answers those 403. Sending
                    // a URL anyway would render an <img> that fails on every
                    // card, which is exactly the console noise this release
                    // removes — so a non-member gets null and the placeholder
                    // icon. See DESIGN §2.95.
                    'image_url'        => ($isDirectMember || $isIndirectMember)
                        ? $this->teamImageService->getImageUrl($row['unique_id'])
                        : null,
                    'nc_avatar_supported' => $this->isTeamsAvatarSupported(),
                    // v4.0.2 — filled in below via a single batch lookup so
                    // BrowseTeamsView can render the template badge. Absent
                    // for legacy teams => frontend shows no template label.
                    'type'             => null,
                ];
            }
            $result->closeCursor();

            // Batch-load template types for every team in one query rather
            // than N+1 lookups. Legacy teams stay null.
            if ($teams !== []) {
                $typesByTeam = $this->teamTypeMapper->findTypesByTeams(
                    array_map(static fn($t) => (string)$t['id'], $teams)
                );
                foreach ($teams as &$t) {
                    $t['type'] = $typesByTeam[$t['id']] ?? null;
                }
                unset($t);
            }

            // v4.8.0 — Nextcloud tags, batch-loaded like the types above.
            //
            // Members only, the same boundary `image_url` draws two fields
            // up. Browse lists teams the viewer is not in, and a tag is a
            // classification of the team — "Confidential" on a team you
            // cannot open tells you something about it that the team never
            // chose to publish. A non-member gets an empty list, not null,
            // so the card renders no chip row rather than a broken one.
            //
            // Resolved through the container rather than the constructor,
            // the same way this method already reaches IDBConnection: it
            // keeps TeamTagService -> MemberService out of TeamService's
            // constructor graph.
            if ($teams !== []) {
                $visibleIds = array_values(array_map(
                    static fn ($t) => (string)$t['id'],
                    array_filter($teams, static fn ($t) => $t['isMember'] === true),
                ));

                $tagsByTeam = $visibleIds === []
                    ? []
                    : $this->container->get(TeamTagService::class)->getTagsForTeams($visibleIds);

                foreach ($teams as &$t) {
                    $t['tags'] = $tagsByTeam[$t['id']] ?? [];
                }
                unset($t);
            }

            return $teams;

        } catch (\Exception $e) {
            $this->logger->error('[TeamHub][TeamService] Error browsing teams', ['exception' => $e, 'app' => Application::APP_ID]);
            return [];
        }
    }

    // =========================================================================
    // Team apps
    // =========================================================================

    public function getTeamApps(string $teamId): array {
        return $this->teamAppMapper->findByTeamId($teamId);
    }

    public function updateTeamApps(string $teamId, array $apps): void {

        // Capture previous state so we can emit accurate enabled/disabled events.
        // We compare per-app rather than emitting one bulk event because each
        // toggle is a discrete admin decision worth surfacing in the audit log.
        $previous = [];
        try {
            foreach ($this->teamAppMapper->findByTeamId($teamId) as $row) {
                $appId = (string)($row['app_id'] ?? '');
                if ($appId !== '') {
                    $previous[$appId] = (bool)($row['enabled'] ?? false);
                }
            }
        } catch (\Throwable $e) { /* non-fatal — diff just won't have prior state */ }

        $user      = $this->userSession->getUser();
        $actorUid  = $user ? $user->getUID() : null;

        foreach ($apps as $app) {
            $this->teamAppMapper->upsert($teamId, $app['app_id'], $app['enabled'], $app['config'] ?? null);

            $appId    = (string)($app['app_id'] ?? '');
            $newState = (bool)($app['enabled'] ?? false);
            $oldState = $previous[$appId] ?? null;

            // Only emit when state actually transitioned.
            if ($appId !== '' && $oldState !== $newState) {
                $this->auditService->log(
                    $teamId,
                    $newState ? 'team.app_enabled' : 'team.app_disabled',
                    $actorUid,
                    'app',
                    $appId,
                    null,
                );
            }
        }
    }

    // =========================================================================
    // Admin settings
    // =========================================================================

    /**
     * Get admin-configured settings.
     * createTeamGroups is returned as an array of { id, displayName } objects
     * so the Vue picker can render chips immediately without a separate lookup.
     * The raw stored value (createTeamGroup) is still a comma-separated string
     * for backward-compat with canCreateTeam() checks.
     */
    public function getAdminSettings(): array {
        $config       = $this->container->get(\OCP\IConfig::class);
        $groupManager = $this->container->get(\OCP\IGroupManager::class);

        $rawGroups = $config->getAppValue(Application::APP_ID, 'createTeamGroup', '');

        // Resolve each stored group ID to { id, displayName }
        $createTeamGroups = [];
        if ($rawGroups !== '') {
            foreach (array_filter(array_map('trim', explode(',', $rawGroups))) as $gid) {
                $group = $groupManager->get($gid);
                $createTeamGroups[] = [
                    'id'          => $gid,
                    'displayName' => $group ? ($group->getDisplayName() ?: $gid) : $gid,
                ];
            }
        }

        return [
            'wizardDescription'      => $config->getAppValue(Application::APP_ID, 'wizardDescription', ''),
            'inviteTypes'            => $config->getAppValue(Application::APP_ID, 'inviteTypes', 'user,group'),
            'pinMinLevel'            => $config->getAppValue(Application::APP_ID, 'pinMinLevel', 'moderator'),
            'intravoxParentPath'     => $config->getAppValue(Application::APP_ID, 'intravoxParentPath', 'en/teamhub'),
            'createTeamGroup'        => $rawGroups,         // legacy flat string — keep for canCreateTeam()
            'createTeamGroups'       => $createTeamGroups, // structured array for the picker
            'groupFoldersDelegation' => $this->safeGetDelegationStatus(),
            // Presence module — default ON (v3.75.4). NC admin can toggle off.
            // Defaults to enabled to give the module visibility on fresh
            // installs; existing installs that have ever explicitly set this
            // key keep their stored value (getAppValue only uses the default
            // when the key is unset).
            'presenceModuleEnabled'  => $config->getAppValue(Application::APP_ID, 'presence_module_enabled', '1') === '1',
            // Decisions module — default ON (v3.75.4). Same rationale as above.
            'decisionsModuleEnabled' => $config->getAppValue(Application::APP_ID, 'decisions_module_enabled', '1') === '1',
            // RoomVox API token: never return the token value itself, only a
            // boolean indicating whether one is configured. The admin can
            // overwrite it (write field) but can't read it back (read field).
            // This matches NC's pattern for SMTP passwords and other secrets.
            'roomvoxTokenConfigured' => $config->getAppValue(Application::APP_ID, 'roomvox_api_token', '') !== '',
            // v4.4.4 — whether an admin has dismissed the first-run setup
            // checklist on the Team creation tab. Instance-level, not
            // per-user: "this instance has been reviewed" is a fact about
            // the instance, so one admin settling it settles it for all.
            // Default '0' — a fresh install shows the checklist.
            'onboardingChecklistDismissed' => $config->getAppValue(Application::APP_ID, 'onboarding_checklist_dismissed', '0') === '1',
            // v4.6.13 — how many days before a team's expiration date the
            // warning surfaces in My Work, for both Nextcloud admins and the
            // team's own admins. The key name and bounds come from
            // TeamExpiryService so this stays one definition rather than a
            // mirror; only the storage call lives here, next to every other
            // admin setting, so the Team creation tab's autosave carries it.
            'expiryWarningDays'      => TeamExpiryService::clampWarningDays((int)$config->getAppValue(
                Application::APP_ID,
                TeamExpiryService::CONFIG_WARNING_DAYS,
                (string)TeamExpiryService::DEFAULT_WARNING_DAYS,
            )),
            'expiryWarningDaysMin'   => TeamExpiryService::MIN_WARNING_DAYS,
            'expiryWarningDaysMax'   => TeamExpiryService::MAX_WARNING_DAYS,
            // The date the create-team wizard's picker opens on when a user
            // first clicks it. Computed server-side so the wizard, the CSV
            // importer's documentation and this panel cannot drift apart.
            'expiryDefaultDate'      => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('+' . TeamExpiryService::DEFAULT_PICKER_MONTHS . ' months')
                ->format('Y-m-d'),
        ];
    }

    /**
     * Isolates getDelegationStatus() from getAdminSettings() so that any failure
     * (e.g. group_folders_applicable table absent on a fresh GF install) cannot
     * break the settings load response and wipe the team-creator group from the UI.
     */
    private function safeGetDelegationStatus(): array {
        try {
            return $this->groupFolderService->getDelegationStatus();
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamService] getDelegationStatus failed — returning safe defaults', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [
                'groupFoldersInstalled'        => false,
                'teamCreatorGroupsConfigured'  => false,
            ];
        }
    }

    /**
     * Save admin settings. Requires server admin.
     * createTeamGroup accepts either a comma-separated string or a JSON array of group IDs.
     */
    public function saveAdminSettings(array $settings): void {

        // Defence-in-depth: verify NC admin even though the controller attribute
        // already blocks non-admins at the framework level.
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('Not authenticated');
        }
        $groupManager = $this->container->get(\OCP\IGroupManager::class);
        if (!$groupManager->isAdmin($user->getUID())) {
            $this->logger->warning('[TeamHub][TeamService] saveAdminSettings — non-admin attempt', [
                'userId' => $user->getUID(),
                'app'    => Application::APP_ID,
            ]);
            throw new \Exception('NC admin privilege required');
        }

        $config = $this->container->get(\OCP\IConfig::class);

        if (isset($settings['wizardDescription'])) {
            $config->setAppValue(Application::APP_ID, 'wizardDescription', (string)$settings['wizardDescription']);
        }
        if (isset($settings['inviteTypes'])) {
            $allowed = ['user', 'group', 'email', 'federated', 'circle'];
            $types   = array_filter(
                array_map('trim', explode(',', (string)$settings['inviteTypes'])),
                fn($t) => in_array($t, $allowed, true)
            );
            if (empty($types)) {
                $types = ['user'];
            }
            $config->setAppValue(Application::APP_ID, 'inviteTypes', implode(',', $types));
        }
        if (isset($settings['pinMinLevel'])) {
            $validLevels = ['member', 'moderator', 'admin'];
            $level = in_array($settings['pinMinLevel'], $validLevels, true)
                ? $settings['pinMinLevel']
                : 'moderator';
            $config->setAppValue(Application::APP_ID, 'pinMinLevel', $level);
        }
        if (isset($settings['intravoxParentPath'])) {
            // Validate: only alphanumeric, hyphens, underscores, and forward slashes.
            // Strip leading/trailing slashes for consistency.
            $raw  = trim((string)$settings['intravoxParentPath'], '/');
            $path = preg_match('/^[a-zA-Z0-9_\-\/]+$/', $raw) ? $raw : 'en/teamhub';
            $config->setAppValue(Application::APP_ID, 'intravoxParentPath', $path);
        }
        if (isset($settings['expiryWarningDays'])) {
            // Clamped rather than rejected, matching pinMinLevel and
            // intravoxParentPath above: this tab autosaves as the admin types,
            // so a half-typed number is a keystroke and not an error. The
            // response re-renders the field with what was actually stored.
            $config->setAppValue(
                Application::APP_ID,
                TeamExpiryService::CONFIG_WARNING_DAYS,
                (string)TeamExpiryService::clampWarningDays((int)$settings['expiryWarningDays']),
            );
        }
        if (array_key_exists('createTeamGroup', $settings)) {
            $raw = $settings['createTeamGroup'];

            // Accept a JSON array from the multi-picker (e.g. '["admins","Team Leads"]')
            // or a legacy comma-separated string. The decoded array is kept as
            // an array rather than immediately imploded, so the filter below
            // sees each group ID whole.
            if (is_string($raw) && str_starts_with(trim($raw), '[')) {
                $decoded = json_decode($raw, true);
                $gids    = is_array($decoded) ? $decoded : [];
            } else {
                $gids = explode(',', (string)$raw);
            }

            // Sanitise: drop empty IDs, and drop any ID containing a comma —
            // the stored format is comma-separated, so an embedded comma would
            // split one group into two on read.
            //
            // v4.6.2 — whitespace is NO LONGER rejected. The previous filter was
            // preg_match('/^[^\s,]+$/', $g), which also dropped every group ID
            // containing a space, and NC group IDs may legally contain one (a
            // group created as "Team Leads" has exactly that GID). The failure
            // was silent and looked like a broken picker: the admin selected a
            // group, the POST succeeded, the server stored an empty list, and
            // the reload that follows every save wiped the chip that had just
            // appeared. Both readers of this value — getAdminSettings() and
            // MemberService::canCurrentUserCreateTeam() — already explode on
            // ',' and trim, so they handle spaces correctly; only the write
            // path was losing them.
            $gids = array_filter(
                array_map(
                    static fn($g) => is_scalar($g) ? trim((string)$g) : '',
                    $gids
                ),
                static fn($g) => $g !== '' && !str_contains($g, ',')
            );

            $config->setAppValue(Application::APP_ID, 'createTeamGroup', implode(',', $gids));
        }
        if (isset($settings['presenceModuleEnabled'])) {
            $config->setAppValue(
                Application::APP_ID,
                'presence_module_enabled',
                $settings['presenceModuleEnabled'] ? '1' : '0'
            );
        }
        if (isset($settings['decisionsModuleEnabled'])) {
            $config->setAppValue(
                Application::APP_ID,
                'decisions_module_enabled',
                $settings['decisionsModuleEnabled'] ? '1' : '0'
            );
        }
        if (isset($settings['onboardingChecklistDismissed'])) {
            $config->setAppValue(
                Application::APP_ID,
                'onboarding_checklist_dismissed',
                $settings['onboardingChecklistDismissed'] ? '1' : '0'
            );
        }
        if (array_key_exists('roomvoxApiToken', $settings)) {
            // The admin UI sends:
            //   '' (empty)       → leave value unchanged (token field was not touched)
            //   '__CLEAR__'      → clear the stored token
            //   anything else    → store as new token
            // We never echo the token back to the UI (see getAdminSettings),
            // so the empty-means-no-change semantic prevents the UI from
            // accidentally wiping a configured token by re-saving the form
            // without retyping the secret.
            $raw = (string)$settings['roomvoxApiToken'];
            if ($raw === '__CLEAR__') {
                $config->deleteAppValue(Application::APP_ID, 'roomvox_api_token');
            } elseif ($raw !== '') {
                // Light validation: RoomVox tokens are documented as
                // 'rvx_' + 40 alnum characters. Reject anything that
                // doesn't match the prefix to catch typos / paste errors;
                // not a security boundary, just a sanity check.
                if (!preg_match('/^rvx_[A-Za-z0-9]{20,}$/', $raw)) {
                    throw new \Exception('RoomVox token format looks wrong (expected rvx_… )');
                }
                $config->setAppValue(Application::APP_ID, 'roomvox_api_token', $raw);
            }
        }
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function circleToArray(mixed $circle): array {
        $memberCount = 0;
        if (method_exists($circle, 'getMembers')) {
            $members     = $circle->getMembers();
            $memberCount = is_array($members) ? count($members) : 0;
        }
        return [
            'id'          => method_exists($circle, 'getSingleId') ? $circle->getSingleId() : $circle->getId(),
            'name'        => method_exists($circle, 'getDisplayName') ? $circle->getDisplayName() : $circle->getName(),
            'description' => method_exists($circle, 'getDescription') ? ($circle->getDescription() ?? '') : '',
            'members'     => $memberCount,
            'image_url'   => $this->teamImageService->getImageUrl(method_exists($circle, 'getSingleId') ? $circle->getSingleId() : $circle->getId()),
            'nc_avatar_supported' => $this->isTeamsAvatarSupported(),
        ];
    }

}
