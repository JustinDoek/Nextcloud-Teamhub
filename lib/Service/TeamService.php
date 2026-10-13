<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\CirclesConfig;
use OCA\TeamHub\Constants\TeamApps;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\TeamImageService;
use OCA\TeamHub\Db\PendingDeletionMapper;
use OCA\TeamHub\Db\ResourceLinkMapper;
use OCA\TeamHub\Db\SubTeamMapper;
use OCA\TeamHub\Db\TeamAppMapper;
use OCA\TeamHub\Db\TeamAppPresenceMapper;
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
        // v4.8.27 — the derived "which apps does this team have" reading behind
        // getTeamAppPresence(). A DI leaf; it holds only IDBConnection.
        private TeamAppPresenceMapper $appPresenceMapper,
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
        // v4.8.4 — Track F2b. Read-only from here: PolicyService decides what a
        // team's profile governs, this service owns the writes that apply it.
        // Acyclic on purpose — PolicyService knows nothing about TeamService.
        private PolicyService        $policyService,
        // v4.9.6 — the provisioning ledger, removed with the team. A DI leaf
        // (IDBConnection only).
        private ResourceLinkMapper   $resourceLinks,
        // v4.10.6 — which circles are TeamHub's, and the CFG_APP lock that keeps
        // the Teams page from deleting them. A DI leaf (mapper + IDBConnection).
        private TeamRegistryService  $teamRegistry,
        // v4.12.2 — the parent link behind sub-teams. A DI leaf
        // (IDBConnection only). The *mapper* and never SubTeamService, which
        // depends on this class: what is needed here is the link, not the
        // behaviour that owns it.
        private SubTeamMapper        $subTeams,
        // v4.12.3 - whether sub-teams exist on this instance at all: the
        // licence and the administrator's switch. A DI leaf (IConfig +
        // LicenseService) for exactly this reason - the predicate is needed
        // both here and in SubTeamService, which depends on this class.
        private SubTeamModuleService $subTeamModule,
        // v4.12.5 - which name columns this Circles schema actually has, for
        // renameTeam(). A DI leaf (container + logger) with a per-request
        // cache, which is why probing it per rename costs nothing.
        private DbIntrospectionService $dbIntrospection,
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

            // v4.10.6 — TeamHub teams only. A circle made in Contacts,
            // Collectives, occ or another app has no registry row and is not
            // shown, however the user is a member of it (DESIGN §2.136).
            $this->teamRegistry->restrictToTeamHubTeams($qb, 'c');

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

            // v4.12.2 — which of these teams are sub-teams, and of whom. One
            // indexed query for the whole list rather than one per row; the
            // parent id is only disclosed below, where it is known that the
            // viewer can see the parent too.
            $subTeamLinks = $this->subTeams->linksFor($ids);

            // v4.12.3 - one read, outside the loop. Two appconfig reads at
            // most (the licence memoises its status per request), on a call
            // that runs on every page load.
            $subTeamsAvailable = $this->subTeamModule->isAvailable();
            // v4.12.5 - and *why* not, when it is not. Same read, no extra
            // query: unavailableReason() asks the two halves isAvailable()
            // already asked, and the licence memoises per request.
            $subTeamsReason = $subTeamsAvailable ? null : $this->subTeamModule->unavailableReason();

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
                    // ── v4.12.2, sub-teams (DESIGN §2.175) ────────────────
                    // This list stays flat. Callers other than the sidebar are
                    // authorisation boundaries — MyWorkService::resolveTeams(),
                    // FileActionController, FileReviewController — and nesting
                    // children into their parents here would quietly drop every
                    // sub-team out of their scope. getSidebarTeams() does the
                    // nesting, on a copy, for the one caller that wants it.
                    'is_sub_team' => isset($subTeamLinks[$id]),
                    // The parent's id, **only when the viewer also has the
                    // parent in this same list**. A sub-team may hold people
                    // from outside the main team, and an outsider must learn
                    // nothing about the parent — not its id, not that it
                    // exists. Resolved after the loop, where the full set of
                    // visible ids is known.
                    'parent_team_id' => null,
                    'sub_team_visibility' => isset($subTeamLinks[$id])
                        ? $subTeamLinks[$id]['visibility']
                        : null,
                    // Depth is 1: a sub-team cannot have sub-teams. The
                    // frontend reads this to *hide* the Sub-teams tab and the
                    // "Request a sub-team" action rather than show them
                    // failing (CLAUDE.md § Permissions).
                    //
                    // v4.12.3 - and false for every team when the module is
                    // unavailable, which is what hides the tab instance-wide
                    // without the frontend learning a second fact. The three
                    // *structural* fields beside it stay truthful: this list
                    // is My Work's authorisation boundary, not a surface, and
                    // getSidebarTeams() needs `is_sub_team` to know which rows
                    // to drop.
                    'can_have_sub_teams' => $subTeamsAvailable && !isset($subTeamLinks[$id]),
                    // v4.12.5 - and why the tab is not usable, so Manage team
                    // can grey it and say which of the two it is rather than
                    // leaving a team admin with an absence (Justin's call,
                    // 2026-10-12). Null on a sub-team's own row as well as on
                    // an available instance: the depth rule is not something
                    // to advertise, and `is_sub_team` beside it is how the
                    // frontend tells those two apart.
                    'sub_teams_unavailable_reason' => isset($subTeamLinks[$id]) ? null : $subTeamsReason,
                ];
            }

            // v4.12.2 — disclose a parent id only to a viewer who can see the
            // parent anyway. Deliberately a second pass: whether the parent is
            // visible is a property of the finished list, not of a single row,
            // and computing it per row would mean asking the membership
            // question again for a team already answered above.
            $visibleIds = [];
            foreach ($teams as $team) {
                $visibleIds[$team['id']] = true;
            }
            foreach ($teams as &$team) {
                $link = $subTeamLinks[$team['id']] ?? null;
                if ($link !== null && isset($visibleIds[$link['parent_team_id']])) {
                    $team['parent_team_id'] = $link['parent_team_id'];
                }
            }
            unset($team);

            return $teams;

        } catch (\Exception $e) {
            $this->logger->error('[TeamHub][TeamService] Error in getUserTeams', ['exception' => $e, 'app' => Application::APP_ID]);
            return [];
        }
    }

    /**
     * The sidebar's view of {@see getUserTeams()} — the same teams, with each
     * sub-team nested inside its parent (v4.12.2, DESIGN §2.175).
     *
     * Only `TeamController::listTeams()` calls this. Everything else keeps the
     * flat list, and that is not a convenience: `MyWorkService::resolveTeams()`
     * documents itself as the single authorisation boundary for My Work, and
     * `FileActionController` / `FileReviewController` scope the Files-app
     * actions off the same call. Nesting there would silently remove every
     * sub-team from all three.
     *
     * Three cases, and only one of them needed any code:
     *
     *  - **A sub-team whose parent the viewer can also see** is moved into that
     *    parent's `sub_teams[]` and drops off the top level.
     *  - **A sub-team whose parent the viewer cannot see** — an outsider
     *    invited into it and nothing else — stays exactly where the flat query
     *    put it, at the top level, with `parent_team_id` already null. The same
     *    branch, taken the other way; correct by construction rather than by a
     *    filter somebody has to remember.
     *  - **A private sub-team the viewer is not in** never arrives at all,
     *    because {@see getUserTeams()}'s own query is membership-gated. That is
     *    why `private` is the default: it is the zero-work state.
     *
     * `listed` is the only visibility that *adds* rows, and they are added
     * here and nowhere else — so they carry no My Work scope, no Files-action
     * scope and no file-review scope, and every team-scoped endpoint still
     * refuses them through `requireMemberLevel()`. "Name visible, not
     * openable" therefore needs no new gate.
     *
     * ## When the module is unavailable (v4.12.3)
     *
     * Every sub-team row leaves the sidebar — the nested ones and the `listed`
     * siblings alike. Justin's call, 2026-10-12: the Service-teams shape, with
     * the surfaces hidden rather than only creation refused
     * ({@see SubTeamModuleService} for what that does and does not cover, and
     * DESIGN §2.177 for the accepted consequence — an outsider invited into a
     * private sub-team and into nothing else sees an empty sidebar until the
     * switch comes back).
     *
     * **The filter is here and not in `getUserTeams()`, deliberately.** That
     * call is this app's authorisation boundary for My Work and for the
     * Files-app actions; dropping a sub-team there would not hide a surface,
     * it would strand a member's open work items and file reviews. A switch an
     * administrator can flip back must not lose anything.
     */
    public function getSidebarTeams(): array {
        $teams = $this->getUserTeams();

        // v4.12.3 — no module, no sub-teams in the sidebar. Before the nesting
        // rather than after it: nesting and then unpicking would leave the
        // rows' order depending on a switch.
        if (!$this->subTeamModule->isAvailable()) {
            return self::dropSubTeams($teams);
        }

        // Which teams could carry a `listed` child the viewer is not in? Only
        // ones that can be a parent at all, and only the rows that are still
        // going to be at the top level.
        $parentIds = [];
        foreach ($teams as $team) {
            if (($team['can_have_sub_teams'] ?? false) && ($team['parent_team_id'] ?? null) === null) {
                $parentIds[] = $team['id'];
            }
        }

        $listed = [];
        if ($parentIds !== []) {
            $children = $this->subTeams->childrenOfMany($parentIds);

            // Resolve every candidate's name in one query before the nesting,
            // rather than one per row inside it.
            $candidateIds = [];
            foreach ($children as $rows) {
                foreach ($rows as $row) {
                    if ($row['visibility'] === SubTeamMapper::VIS_LISTED) {
                        $candidateIds[] = $row['team_id'];
                    }
                }
            }
            $names = $this->resolveTeamNames($candidateIds);

            foreach ($children as $parentId => $rows) {
                foreach ($rows as $row) {
                    if ($row['visibility'] !== SubTeamMapper::VIS_LISTED) {
                        continue;
                    }
                    // A circle that has gone leaves nothing to render.
                    if (!isset($names[$row['team_id']])) {
                        continue;
                    }
                    $listed[] = [
                        'id'        => $row['team_id'],
                        'name'      => $names[$row['team_id']],
                        'parent_id' => $parentId,
                    ];
                }
            }
        }

        return self::nestSubTeams($teams, $listed);
    }

    /**
     * The sidebar with every sub-team row removed (v4.12.3) — what
     * {@see getSidebarTeams()} returns when the module is unavailable.
     *
     * Static and pure for the same reason {@see nestSubTeams()} is: it is the
     * rule, the queries stay outside it, and a rule that decides what a person
     * sees is worth a test that does not need a database.
     *
     * Keyed on `is_sub_team` and not on `parent_team_id`, which is null for an
     * invited outsider who cannot see the parent — the one row that most needs
     * to go, since for them the sub-team is the whole sidebar.
     *
     * @param list<array<string, mixed>> $teams the flat payload from getUserTeams()
     * @return list<array<string, mixed>>
     */
    public static function dropSubTeams(array $teams): array {
        $top = [];
        foreach ($teams as $team) {
            if (!($team['is_sub_team'] ?? false)) {
                $top[] = $team;
            }
        }
        return $top;
    }

    /**
     * The nesting rules, with the queries left outside so they can be tested
     * (v4.12.2). Pure: same inputs, same output, no I/O.
     *
     * @param list<array<string, mixed>> $teams  the flat payload from getUserTeams()
     * @param list<array{id: string, name: string, parent_id: string}> $listed
     *        `listed` sub-teams the viewer is NOT a member of
     * @return list<array<string, mixed>>
     */
    public static function nestSubTeams(array $teams, array $listed = []): array {
        $byId = [];
        foreach ($teams as $i => $team) {
            $byId[$team['id']] = $i;
        }

        // Pass 1 — the sub-teams the viewer is a member of. A row whose
        // parent is in this same list moves inside it; a row whose parent is
        // *not* — an outsider invited into the sub-team and nothing else —
        // falls through and stays at the top level. The same branch, taken the
        // other way: correct by construction rather than by a filter somebody
        // has to remember.
        $nested = [];
        foreach ($teams as $team) {
            $parentId = $team['parent_team_id'] ?? null;
            if ($parentId !== null && isset($byId[$parentId])) {
                $teams[$byId[$parentId]]['sub_teams'][] = $team;
                $nested[$team['id']] = true;
            }
        }

        // Pass 2 — the `listed` siblings. A name and nothing else: no level,
        // no counters, and `listed_only` so the frontend renders a row with no
        // click handler and no actions menu.
        foreach ($listed as $row) {
            if (!isset($byId[$row['parent_id']])) {
                continue;
            }
            // Already a member of it — pass 1 nested the real row, and a
            // second one would render the sub-team twice.
            if (isset($byId[$row['id']])) {
                continue;
            }
            $teams[$byId[$row['parent_id']]]['sub_teams'][] = [
                'id'          => $row['id'],
                'name'        => $row['name'],
                'listed_only' => true,
                'level'       => 0,
                'unread'      => 0,
                'members'     => 0,
                'is_sub_team' => true,
                'parent_team_id'      => $row['parent_id'],
                'sub_team_visibility' => SubTeamMapper::VIS_LISTED,
                'can_have_sub_teams'  => false,
                // v4.12.5 — null, as on every sub-team row: the depth rule is
                // not advertised. These rows only ever reach the sidebar, but
                // the shape stays the same as the others on purpose.
                'sub_teams_unavailable_reason' => null,
            ];
        }

        // Drop the nested rows from the top level, preserving order.
        $top = [];
        foreach ($teams as $team) {
            if (!isset($nested[$team['id']])) {
                $top[] = $team;
            }
        }
        return $top;
    }

    /**
     * Team names for a set of ids, keyed by id. Ids with no circle are absent
     * from the result rather than mapped to '' — a caller needs to tell
     * "deleted" from "unnamed".
     *
     * @param string[] $teamIds
     * @return array<string, string>
     */
    private function resolveTeamNames(array $teamIds): array {
        $ids = array_values(array_unique(array_filter($teamIds, static fn ($id) => $id !== '')));
        if ($ids === []) {
            return [];
        }
        $db  = $this->container->get(\OCP\IDBConnection::class);
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $qb  = $db->getQueryBuilder();
            $res = $qb->select('unique_id', 'name', 'display_name')
                ->from('circles_circle')
                ->where($qb->expr()->in(
                    'unique_id',
                    $qb->createNamedParameter($chunk, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY),
                ))
                ->executeQuery();
            while ($row = $res->fetch()) {
                // `display_name` is nullable with a '' default while `name` is
                // NOT NULL, so neither alone is safe to trust as *the* name —
                // the same reasoning assertTeamNameAvailable() records.
                $name = trim((string)($row['display_name'] ?? ''));
                if ($name === '') {
                    $name = (string)$row['name'];
                }
                $out[(string)$row['unique_id']] = $name;
            }
            $res->closeCursor();
        }
        return $out;
    }

    /**
     * Get a specific team by ID.
     */
    public function getTeam(string $teamId): array {

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        // v4.10.6 — a circle TeamHub did not create is not a team here, member
        // or not: the same answer a deep link to a deleted team gets, before
        // the membership check so the caller learns nothing about the circle.
        if (!$this->teamRegistry->isTeamHubTeam($teamId)) {
            throw new \Exception('Team not found or access denied');
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

        // v4.12.2 — the parent link, and whether this viewer may be told about
        // it. "May be told" is membership of the parent, direct or inherited:
        // an outsider invited into a sub-team is a member of the sub-team and
        // of nothing else, and must not learn the parent's id from here.
        $subTeamLink   = $this->subTeams->find($teamId);
        $parentVisible = false;
        if ($subTeamLink !== null) {
            try {
                $parentVisible = $this->memberService->isEffectiveMember(
                    $subTeamLink['parent_team_id'],
                    $user->getUID(),
                    $db,
                ) || $this->memberService->getMemberLevelFromDb(
                    $db,
                    $subTeamLink['parent_team_id'],
                    $user->getUID(),
                ) > 0;
            } catch (\Throwable $e) {
                // Failing closed: an unreadable parent is a parent the caller
                // is not told about.
                $parentVisible = false;
            }
        }

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
            // v4.12.2 — the same three facts the list payload carries, so a
            // view that fetched one team does not have to go back to the list
            // to find out whether it is a sub-team. `parent_team_id` obeys the
            // same disclosure rule: a viewer who cannot see the parent is not
            // told it exists.
            'is_sub_team'         => $subTeamLink !== null,
            'parent_team_id'      => $parentVisible ? $subTeamLink['parent_team_id'] : null,
            'sub_team_visibility' => $subTeamLink['visibility'] ?? null,
            // v4.12.3 — and false on every team while the module is
            // unavailable, which is the single field the Sub-teams tab is
            // hidden on. The structural three above stay truthful: a sub-team
            // whose module is off is still a sub-team, and its parent's admins
            // are still its only administrators.
            'can_have_sub_teams'  => $subTeamLink === null && $this->subTeamModule->isAvailable(),
            // v4.12.5 — the same field the list payload carries, same rule:
            // null on a sub-team's own row, and null when the module is fine.
            'sub_teams_unavailable_reason' => $subTeamLink === null
                ? $this->subTeamModule->unavailableReason()
                : null,
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
            ->setMaxResults(1);
        // v4.10.6 — and a TeamHub team; a link to a circle made elsewhere is a
        // link to nothing.
        $this->teamRegistry->restrictToTeamHubTeams($qb, 'c');
        $res = $qb->executeQuery();
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
     * v4.12.2 — a sub-team's name must be free among its *siblings*, not
     * across the instance (DESIGN §2.175).
     *
     * Two teams under different parents may both be called "Design", which is
     * what a customer expects and what instance-wide uniqueness would refuse.
     * The folder collision that {@see assertTeamNameAvailable()} exists to
     * prevent is handled the other way for a sub-team: its storage is named
     * "{parent} – {child}", so two "Design" sub-teams get two distinct
     * folders rather than "Design" and "Design (1)".
     *
     * @throws \InvalidArgumentException with a message safe to surface to the user.
     */
    public function assertSubTeamNameAvailable(string $parentTeamId, string $name, string $exceptTeamId = ''): void {
        $trimmed = trim($name);
        if ($trimmed === '') {
            // Emptiness belongs to assertValidTeamName(), as above.
            return;
        }
        $siblingIds = $this->subTeams->siblingIds($parentTeamId, $exceptTeamId);
        if ($siblingIds === []) {
            return;
        }
        $lower = mb_strtolower($trimmed);
        foreach ($this->resolveTeamNames($siblingIds) as $siblingName) {
            if (mb_strtolower($siblingName) === $lower) {
                throw new \InvalidArgumentException('This team already has a sub-team with that name.');
            }
        }
    }

    /**
     * Create a new team.
     * Description is always set via updateTeamDescription() separately.
     *
     * `$parentTeamId` makes it a sub-team of that team (v4.12.2, DESIGN
     * §2.175) and changes exactly one thing here: the name has to be free
     * among its siblings instead of across the instance. Everything else a
     * sub-team needs — the parent link row itself, the policy, the type, the
     * cleared join bits, the members, the storage — belongs to
     * {@see \OCA\TeamHub\Service\SubTeamService::createSubTeam()}, which is
     * the only caller that passes it. It goes through this method rather than
     * beside it because this is where the registry row and the CFG_APP lock
     * are written, and a second creation path is precisely what the registry
     * was built to prevent.
     */
    public function createTeam(string $name, ?string $parentTeamId = null): array {

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
        //
        // v4.12.2 — a sub-team is scoped to its siblings instead; see
        // assertSubTeamNameAvailable() for why the folder-collision argument
        // above does not carry over.
        if ($parentTeamId !== null) {
            $this->assertSubTeamNameAvailable($parentTeamId, $name);
        } else {
            $this->assertTeamNameAvailable($name);
        }

        $circlesManager = $this->getCirclesManager();
        $federatedUser  = $circlesManager->getFederatedUser($user->getUID(), 1);
        $circlesManager->startSession($federatedUser);

        try {
            // v4.10.1 — the fifth argument is Circles 35's `$createTeamFolder`.
            // Left at its default, Nextcloud 35 would give every new circle a
            // team space before TeamHub has decided whether this team gets
            // Files at all; ResourceService creates the space itself when the
            // template says so (DESIGN.md §2.133). Circles 33/34 take four
            // parameters and PHP ignores the extra one, so this is the same
            // call on every supported version.
            $circle = $circlesManager->createCircle($name, null, false, false, false);
            $result = $this->circleToArray($circle);

            // Audit log — team creation. Use the circle's unique_id (== team_id) as target.
            $teamId = (string)($result['id'] ?? $result['unique_id'] ?? '');
            if ($teamId !== '') {
                // v4.10.6 — this circle is TeamHub's: the registry row that
                // makes it visible in TeamHub at all, and the CFG_APP lock
                // that stops the Teams page from deleting it. Here and nowhere
                // else — every creation path runs through this method.
                $this->teamRegistry->registerCreated($teamId, $user->getUID());

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
     * Delete a team (circle), and every sub-team it governs.
     * Also cleans up TeamHub app data for this circle.
     *
     * The owner may do this. **So may an admin of the team that governs this
     * one** (v4.12.5) — DESIGN §2.175 recorded that widening for 4.12.2 and it
     * was never written: `SubTeamService::isParentAdmin()` ended up with no
     * caller outside its own class, so until now only the admin who happened
     * to *create* a sub-team could delete it, and a second admin of the same
     * parent could not. `MemberService::requireOwnerLevel()` is still
     * deliberately not touched — it also guards transferring ownership, which
     * a sub-team refuses outright.
     */
    public function deleteTeam(string $teamId): void {

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        // v4.10.6 — not a TeamHub team, not TeamHub's to delete (or unlock).
        if (!$this->teamRegistry->isTeamHubTeam($teamId)) {
            throw new \Exception('Team not found or access denied');
        }

        // Owner check via DB — avoids getCircle() failing on non-zero config circles.
        $db    = $this->container->get(\OCP\IDBConnection::class);
        $level = $this->memberService->getMemberLevelFromDb($db, $teamId, $user->getUID());
        if ($level < 9 && !$this->isAdminOfGoverningTeam($db, $teamId, $user->getUID())) {
            throw new \Exception('Only the team owner can delete a team.');
        }

        // ── Step 0: the sub-teams this team governs (v4.12.5) ────────────────
        //
        // Children first, and **abort the whole delete when one of them
        // fails**. Carrying on and destroying the parent anyway is the worse
        // outcome by a distance: every gate on a sub-team resolves authority
        // through its parent, so a child that outlives its parent has no
        // administrator at all, nothing in the UI can reach it, and its link
        // row points at a circle that is gone. A parent still standing is a
        // state an administrator can look at and retry.
        //
        // {@see destroyTeamInternal()} rather than a recursive `deleteTeam()`:
        // the authority for a child is the parent's, proven immediately above,
        // and re-checking it per child would refuse exactly the case this
        // exists for — an admin of the parent who is not the child's owner.
        // Depth is one, so there is no recursion to guard against.
        foreach ($this->subTeams->childrenOf($teamId) as $child) {
            $childId = $child['team_id'];
            try {
                $this->destroyTeamInternal($childId, $user, $this->destroyActorFor($db, $childId, $user), $db);
                $this->subTeams->delete($childId);
            } catch (\Throwable $e) {
                $this->logger->error('[TeamHub][TeamService] deleteTeam: a sub-team could not be destroyed; the team was left in place', [
                    'teamId'    => $teamId,
                    'subTeamId' => $childId,
                    'exception' => $e,
                    'app'       => Application::APP_ID,
                ]);
                throw new \Exception(
                    'A sub-team of this team could not be deleted, so the team itself was left in place. '
                    . 'Delete the sub-team from Manage team → Sub-teams first.',
                );
            }
        }

        // The caller when they are the owner — which is every delete that is
        // not a parent admin reaching into a sub-team, and keeps that path
        // exactly as it was. Otherwise the team's own owner, for the reason
        // destroyActorFor() gives: a parent admin may hold no row on the
        // sub-team they are entitled to delete, and Circles authorises the
        // destroy against the initiator's rights on that circle.
        $this->destroyTeamInternal(
            $teamId,
            $user,
            $level >= 9 ? $user : $this->destroyActorFor($db, $teamId, $user),
            $db,
        );

        // The link rows, after the circle is gone: this team's own (it may be
        // a sub-team itself) and any child row a failed unlink left behind.
        // Non-fatal — the team is already destroyed, and a stray row is read
        // only through a circle that no longer exists.
        try {
            $this->subTeams->delete($teamId);
            $this->subTeams->deleteByParent($teamId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamService] deleteTeam: sub-team link rows not cleaned up', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * Is `$userId` an admin of the team that governs `$teamId`?
     *
     * The same fact {@see \OCA\TeamHub\Service\SubTeamService::isParentAdmin()}
     * answers, read here through the mapper instead: this class is a
     * dependency *of* `SubTeamService`, so it cannot call it. One hop only —
     * a sub-team cannot have sub-teams, so there is no chain to walk.
     */
    private function isAdminOfGoverningTeam(\OCP\IDBConnection $db, string $teamId, string $userId): bool {
        $parentId = $this->subTeams->parentOf($teamId);
        if ($parentId === null || $userId === '') {
            return false;
        }
        return $this->memberService->getMemberLevelFromDb($db, $parentId, $userId) >= 8;
    }

    /**
     * Who to act as when destroying `$teamId`'s circle.
     *
     * Circles' `CircleService::destroy()` authorises against the initiator's
     * rights **on that circle**, which is why `MaintenanceService`'s
     * force-delete manufactures an owner row for the acting administrator
     * before it calls the same method. A parent admin cascading a delete may
     * have no row on the child at all, so the cascade acts as the child's own
     * owner — the technique `MemberService::relinkGroup()` already uses.
     *
     * Falls back to the caller when the circle has no owner: an ownerless team
     * is Maintenance's business, and the destroy failing there is the correct
     * outcome rather than something to paper over.
     */
    private function destroyActorFor(\OCP\IDBConnection $db, string $teamId, \OCP\IUser $fallback): \OCP\IUser {
        $qb  = $db->getQueryBuilder();
        $res = $qb->select('user_id')
            ->from('circles_member')
            ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('level', $qb->createNamedParameter(9, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('Member')))
            ->setMaxResults(1)
            ->executeQuery();
        $uid = $res->fetchOne();
        $res->closeCursor();
        if ($uid === false || $uid === null || (string)$uid === '') {
            return $fallback;
        }
        return $this->userManager->get((string)$uid) ?? $fallback;
    }

    /**
     * Destroy one team's circle and everything TeamHub holds for it.
     *
     * **No authorisation of its own.** Extracted from `deleteTeam()` in
     * v4.12.5 so the sub-team cascade can reach it: a child is destroyed on
     * the authority already proven for its parent, and running the owner check
     * again per child would refuse the one case the cascade exists for.
     * `deleteTeam()` is the only caller and it is where the gate lives — keep
     * it that way, and in particular do not give this method a route.
     *
     * @param \OCP\IUser $actor     who is accountable: the audit line, the
     *                              Collectives cascade and the service-team
     *                              removal are all recorded as them.
     * @param \OCP\IUser $destroyAs who Circles acts as for the destroy itself;
     *                              {@see destroyActorFor()} for why the two
     *                              can differ.
     */
    private function destroyTeamInternal(
        string $teamId,
        \OCP\IUser $actor,
        \OCP\IUser $destroyAs,
        \OCP\IDBConnection $db,
    ): void {
        $user = $actor;

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

        // v4.10.6 — release TeamHub's own lock first: CircleDestroy::verify()
        // refuses a circle carrying CFG_APP (status 120, "managed from an
        // other app"), which is the whole point of the bit everywhere but
        // here. Collectives' claim on the same bit went with the collective
        // in step 2b. Immediately before the destroy, so the window in which
        // the Teams page could delete the team is this one call.
        $this->teamRegistry->unlock($teamId);

        // $destroyAs, not $actor: a cascading parent admin may hold no row on
        // this circle at all, and Circles authorises the destroy against the
        // initiator's rights on it. See destroyActorFor().
        $federatedUserService->setLocalCurrentUser($destroyAs);
        $circleService->destroy($teamId);

        // ── Step 4: Remove TeamHub metadata rows ──────────────────────────────
        // v4.10.6 — the registry row first: the circle is gone, so a reader
        // between here and the end of the method must not find a team.
        try {
            $this->teamRegistry->unregister($teamId);
        } catch (\Throwable $e) { /* Not fatal */ }

        try {
            $delQb = $db->getQueryBuilder();
            $delQb->delete('teamhub_team_apps')
                ->where($delQb->expr()->eq('team_id', $delQb->createNamedParameter($teamId)))
                ->executeStatement();
        } catch (\Throwable $e) { /* Not fatal */ }

        // v4.9.3 — the OpenProject link row and its cache generation. Resolved
        // through the container rather than injected: the link service depends
        // on MemberService and AuditService, and adding that edge to this
        // constructor is exactly how the v4.8.7 DI cycle happened. Nothing in
        // OpenProject is touched — the link is TeamHub's row alone.
        try {
            $this->container->get(\OCA\TeamHub\Service\OpenProject\TeamOpenProjectLinkService::class)
                ->deleteForTeamCascade($teamId);
        } catch (\Throwable $e) { /* Not fatal */ }

        // v4.9.6 — the provisioning ledger rows (what was created or linked
        // for this team). Bookkeeping only; every resource they name was
        // handled above.
        try {
            $this->resourceLinks->deleteByTeam($teamId);
        } catch (\Throwable $e) { /* Not fatal */ }

        // v4.10.23 — the service-team row and its catalogue. Phase 5 shipped
        // `remove()` with no caller here, which was survivable while a stale
        // row only meant a dark catalogue entry. It is not survivable now:
        // the Nextcloud services are one instance-wide claim, so a deleted
        // team went on holding them for everybody, greying the checkbox out
        // with an empty team name. Found on the test instance, 2026-09-23.
        //
        // Through the container for the same reason as the OpenProject link
        // above: ServiceTeamService depends on MemberService, and adding
        // that edge to this constructor is how the v4.8.7 DI cycle happened.
        try {
            $this->container->get(\OCA\TeamHub\Service\ServiceTeam\ServiceTeamService::class)
                ->remove($teamId, $user->getUID());
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
     * Rename a team (v4.12.5).
     *
     * **There is no route for this, and that is deliberate.** TeamHub has
     * never let a team be renamed, and the absence was not an oversight: a
     * team's name is carried by its Files folder, its Talk room, its Deck
     * board and its collective, and TeamHub can rename none of them today —
     * a general rename would quietly split a team's name from everything
     * named after it. {@see \OCA\TeamHub\Controller\SubTeamController::update()}
     * is the only caller, because a sub-team's storage is named
     * `{parent} – {child}` from the start (DESIGN §2.175): for a sub-team the
     * difference between the team's name and its folder's was accepted at
     * creation rather than introduced here. The dialog says so beside the
     * field. **Do not add a route for an ordinary team without solving the
     * resources first.**
     *
     * Writes the three name columns together. `name` is what the sidebar and
     * Browse teams read, `display_name` what `SubTeamService::teamName()`
     * prefers, and `sanitized_name` what Maintenance compares `display_name`
     * against when it hunts for circles Circles has misnamed — leaving any one
     * of them behind would report every renamed team as needing repair.
     * `sanitized_name` is absent on older Circles schemas, hence the probe.
     *
     * The caller is responsible for the uniqueness rule that applies to this
     * team: {@see assertTeamNameAvailable()} instance-wide, or
     * {@see assertSubTeamNameAvailable()} among siblings. Shape is checked
     * here, because no name may ever be written without it.
     */
    public function renameTeam(string $teamId, string $name): void {

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }
        $this->memberService->requireAdminLevel($teamId);
        $this->assertValidTeamName($name);

        $trimmed = trim($name);
        $db      = $this->container->get(\OCP\IDBConnection::class);

        $readQb = $db->getQueryBuilder();
        $r = $readQb->select('name')
            ->from('circles_circle')
            ->where($readQb->expr()->eq('unique_id', $readQb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $r->fetch();
        $r->closeCursor();
        if ($row === false) {
            throw new \Exception('Team not found');
        }
        $oldName = (string)$row['name'];

        if ($oldName === $trimmed) {
            return;
        }

        $columns = $this->dbIntrospection->getTableColumns('circles_circle');

        $qb = $db->getQueryBuilder();
        $qb->update('circles_circle')
            ->set('name', $qb->createNamedParameter($trimmed))
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)));
        if (in_array('display_name', $columns, true)) {
            $qb->set('display_name', $qb->createNamedParameter($trimmed));
        }
        if (in_array('sanitized_name', $columns, true)) {
            $qb->set('sanitized_name', $qb->createNamedParameter($trimmed));
        }
        $qb->executeStatement();

        // Same cache bust updateTeamConfig() does, and for the same reason:
        // Circles memoises circle objects in APCu, and a reader in the next
        // request would otherwise be told the old name. Deliberately not
        // `getCircle()` — that re-applies config bits (see updateTeamConfig).
        if (function_exists('apcu_delete') && class_exists('APCUIterator')) {
            try {
                foreach (new \APCUIterator('/^(circles|NC__circles)/') as $item) {
                    apcu_delete($item['key']);
                }
            } catch (\Throwable $e) { /* non-fatal */ }
        }

        $this->auditService->log(
            $teamId,
            'team.renamed',
            $user->getUID(),
            'team',
            $teamId,
            ['changed' => ['name' => ['old' => $oldName, 'new' => $trimmed]]],
        );
    }

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

        // v4.12.8 — through the shared gate rather than a raw level read.
        // Identical for an ordinary team (moderator, level >= 4) and it adds
        // the one thing the raw read could not have: `requireModeratorLevel()`
        // accepts an admin of the **governing** team (v4.12.2), so every admin
        // of a main team can edit its sub-teams' descriptions, not only the one
        // who happens to hold a row in the sub-team.
        //
        // That was the last setting on a sub-team still answering to a direct
        // row — DESIGN §2.182. It mattered because a sub-team's description is
        // set at creation and a parent admin who did not create it has no row
        // at all, so the description was frozen for everyone but its author.
        $this->memberService->requireModeratorLevel($teamId);

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
    /**
     * Write the team's profile into its Circles config (v4.8.4, Track F2b).
     *
     * Reads the team's current config and hands it straight back to
     * `updateTeamConfig()`, whose overlay does the actual work. That looks
     * redundant and is not: it means the governed bits are applied by exactly
     * the same code path that enforces them on every later write, so there is
     * one place that can be wrong instead of two.
     *
     * A no-op for an unclassified team — the overlay's mask is zero and the
     * config is rewritten to what it already was.
     */
    public function applyPolicyConfig(string $teamId): void {
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
            return;
        }

        $this->updateTeamConfig($teamId, (int)$row['config'] & CirclesConfig::MANAGED_BITS);
    }

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

        // v4.8.4 — the team's profile wins, over every caller.
        //
        // This is the single choke point for the six MANAGED_BITS, which is why
        // the overlay lives here rather than in each caller: the wizard, the
        // Manage Team screen and the CSV importer all arrive through this
        // method, and a governed bit has to stick for all three. Hiding the
        // control in one of those UIs would produce no control at all —
        // DESIGN §2.103 is the whole reason that sentence is in the product.
        //
        // Idempotent for the apply path itself: it passes the profile's own
        // values, so overlaying them changes nothing and needs no bypass flag.
        //
        // Unclassified teams get a zero mask and are untouched, which is every
        // team until an administrator assigns a profile.
        $overlay = $this->policyService->configOverlayForTeam($teamId);
        if ($overlay['mask'] !== 0) {
            $newConfig = ($newConfig & ~$overlay['mask']) | ($overlay['value'] & $overlay['mask']);
        }

        // v4.9.2 — federation, the one managed bit with a level above the
        // profile. Precedence is instance → profile → team: the "Allowed invite
        // types" admin setting can forbid federated members instance-wide, and
        // neither a profile nor a team owner may grant what it has withheld.
        //
        // The profile level needs nothing here — CFG_FEDERATED is in
        // PolicyField::configBits(), so a governing profile already arrives in
        // the overlay above. This clamp is only the instance level, applied
        // after the overlay precisely because it outranks it.
        if ($this->memberService->resolveFederationSetting($teamId)['lockedBy'] === 'instance') {
            $newConfig &= ~CirclesConfig::CFG_FEDERATED;
        }

        // v4.12.7 — a sub-team is never browsable and never joinable, and that
        // rule outranks a policy profile that says otherwise (Justin,
        // 2026-10-12; DESIGN §2.181). Applied here, after the overlay, because
        // this is the single writer of the managed bits: `SubTeamService`
        // clears the same two bits at creation and after a classification
        // change, but the overlay above would put back whatever a governing
        // profile asserts, so the clear could not hold on its own.
        //
        // Precedence for these two bits is therefore: instance → **sub-team**
        // → profile → team. One level above the profile, which outranks
        // everything else, because "a sub-team is not a front door" is
        // structural: a sub-team is hand-picked, excluded from
        // `browseAllTeams()`, and may hold people from outside the main team.
        // A profile that opened one would be opening a room whose whole point
        // is that you are put in it.
        //
        // The bit test runs before the lookup deliberately: this method is on
        // the path of every team settings save on the instance, and all but a
        // handful of those teams are not sub-teams and do not set either bit.
        if (($newConfig & (CirclesConfig::CFG_VISIBLE | CirclesConfig::CFG_OPEN)) !== 0
            && $this->subTeams->isSubTeam($teamId)) {
            $newConfig &= ~(CirclesConfig::CFG_VISIBLE | CirclesConfig::CFG_OPEN);
        }

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
     * Only returns circles with CFG_VISIBLE (bit 8) or CFG_OPEN (bit 16) set,
     * or where the current user is already a member.
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

            // CFG_VISIBLE (8): circles marked discoverable by non-members.
            // CFG_OPEN (16): circles that auto-accept any join request. We
            // push the filter into SQL so we never load all circles into
            // PHP — critical for scalability to 1,000+ groups.
            //
            // Both come from CirclesConfig. They are deliberately *not*
            // re-declared as locals here: this method used to carry its own
            // `$CFG_VISIBLE = 512`, which is CFG_NO_OWNER, and the toggle it
            // was meant to honour did nothing for four months. See the
            // v4.12.3 note in DESIGN.md §2.176.
            //
            // Strategy: LEFT JOIN on direct membership (user_type=1) AND on the
            // circles_membership cache (indirect via group/team).
            // Include the circle if:
            //   (a) the user has a direct member row (m.user_id IS NOT NULL), OR
            //   (b) the user appears in circles_membership (ms.single_id IS NOT NULL), OR
            //   (c) the circle has CFG_VISIBLE set (config & 8 != 0), OR
            //   (d) v3.100.8 — the circle has CFG_OPEN set (config & 16 != 0).
            //       Rationale: an open circle allows anyone to join, so
            //       hiding it from browse serves no security purpose;
            //       showing it lets users actually reach the auto-approve
            //       flow this app supports (apps.md W-2 test path).

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
                       // Circle is publicly visible (CFG_VISIBLE bit set).
                       // createFunction with a class constant interpolated,
                       // per the established pattern in this file — the value
                       // is a compile-time int, never user input.
                       $qb->expr()->neq(
                           $qb->createFunction('(c.config & ' . (int)CirclesConfig::CFG_VISIBLE . ')'),
                           $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                       ),
                       // Circle is open (CFG_OPEN bit set) — v3.100.8
                       $qb->expr()->neq(
                           $qb->createFunction('(c.config & ' . (int)CirclesConfig::CFG_OPEN . ')'),
                           $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                       )
                   )
               )
               // Case-insensitive, for the reason spelled out on getUserTeams()'
               // own orderBy above. Browse Teams had the same byte-order
               // sorting; the sidebar is just where it was noticed.
               ->orderBy($qb->createFunction('LOWER(c.name)'), 'ASC');

            // v4.10.6 — TeamHub teams only, as getUserTeams(). A visible or
            // open circle made in another app is still not browsable here.
            $this->teamRegistry->restrictToTeamHubTeams($qb, 'c');

            // v4.12.2 — and never a sub-team. Its membership is hand-picked by
            // the parent's admin (DESIGN §2.175), so appearing in a list
            // anyone can join from would defeat the whole arrangement — which
            // a template whose `preselect_config` carries CFG_VISIBLE or
            // CFG_OPEN would otherwise cause. Creation clears both bits; this
            // is the backstop that does not depend on it having worked.
            $this->subTeams->excludeSubTeams($qb, 'c');

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

            return $teams;

        } catch (\Exception $e) {
            $this->logger->error('[TeamHub][TeamService] Error browsing teams', ['exception' => $e, 'app' => Application::APP_ID]);
            return [];
        }
    }

    // =========================================================================
    // Team apps
    // =========================================================================

    /**
     * The raw `teamhub_team_apps` rows — stored toggles only.
     *
     * **Not the answer to "which apps does this team have."** Resource-backed
     * apps stopped being written here when resources became registry-driven, so
     * on most teams this returns nothing at all. Use `getTeamAppPresence()` for
     * anything a person will read, and this only when you need the stored row
     * itself (its `config` blob, or to preserve it across a write).
     */
    public function getTeamApps(string $teamId): array {
        return $this->teamAppMapper->findByTeamId($teamId);
    }

    /**
     * Which apps the team actually has, one row per canonical app (v4.8.27).
     *
     * Same row shape as `getTeamApps()` so the Manage Team screen keeps
     * working, but **every** canonical app is present with a real `enabled`
     * rather than only the ones carrying a stored toggle. That difference is
     * the bug this fixes: the screen read the stored rows, found none, and fell
     * back to `enabled = true` — so it showed every installed app as switched
     * on for every team, whatever the team actually had.
     *
     * @return list<array{app_id: string, enabled: bool, config: mixed}>
     */
    public function getTeamAppPresence(string $teamId): array {
        $present = $this->appPresenceMapper->presenceForTeams([$teamId])[$teamId] ?? [];

        $configs = [];
        foreach ($this->teamAppMapper->findByTeamId($teamId) as $row) {
            $configs[TeamApps::canonical((string)$row['app_id'])] = $row['config'] ?? null;
        }

        $out = [];
        foreach (TeamApps::CANONICAL as $appId) {
            $out[] = [
                'app_id'  => $appId,
                'enabled' => in_array($appId, $present, true),
                'config'  => $configs[$appId] ?? null,
            ];
        }

        return $out;
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
            // v4.10.7 — the profile fields shown under a person's name in every
            // picker, in order (PersonSublineService). Resolved through the
            // service so the default and the validation live in one place.
            'personSublineFields'    => implode(',', $this->container->get(PersonSublineService::class)->configuredFields()),
            'personSublineFieldsAvailable' => PersonSublineService::FIELDS,
            'pinMinLevel'            => $config->getAppValue(Application::APP_ID, 'pinMinLevel', 'moderator'),
            'intravoxParentPath'     => $config->getAppValue(Application::APP_ID, 'intravoxParentPath', 'en/teamhub'),
            'createTeamGroup'        => $rawGroups,         // legacy flat string — keep for canCreateTeam()
            'createTeamGroups'       => $createTeamGroups, // structured array for the picker
            'groupFoldersDelegation' => $this->safeGetDelegationStatus(),
            // v4.11.32 — IdP teams: a team per Nextcloud group that came from
            // a directory. Read through the two services so the defaults, the
            // validation and the "can this filter even run" question each live
            // in one place. `idpTeamsLdapDnAvailable` is false when user_ldap
            // is not installed, and the panel must then not offer the sub-OU
            // field at all: a filter that cannot be evaluated would silently
            // stop filtering.
            'idpTeams'               => $this->idpTeamSettings(),
            // v4.11.34 — what happens to a team made outside TeamHub:
            // `accept`, `review` (the default) or `decline`. The key and the
            // clamp come from TeamAdoptionService, the read happens here:
            // that service's graph reaches the workflow engine, and nothing
            // in the admin panel should be able to fail over a one-word
            // setting. The panel renders it beside the adoption grid on
            // Import/Export, which is where the consequence is visible.
            'teamAdoptionMode'       => TeamAdoptionService::normaliseMode($config->getAppValue(
                Application::APP_ID,
                TeamAdoptionService::SETTING_MODE,
                TeamAdoptionService::MODE_REVIEW,
            )),
            // v4.11.35 — the pair an automatically accepted team gets.
            // Raw here, unlike the mode: resolving them needs the template
            // list and the profile table, which is the adoption service's
            // job, and the panel renders its own closed lists anyway. An
            // empty value means "the default", which the panel shows.
            'teamAdoptionTemplate'   => trim($config->getAppValue(
                Application::APP_ID,
                TeamAdoptionService::SETTING_TEMPLATE,
                TeamAdoptionDecisionService::DEFAULT_TEMPLATE,
            )),
            'teamAdoptionProfile'    => trim($config->getAppValue(
                Application::APP_ID,
                TeamAdoptionService::SETTING_PROFILE,
                TeamAdoptionDecisionService::DEFAULT_PROFILE,
            )),
            // Presence module — default ON (v3.75.4). NC admin can toggle off.
            // Defaults to enabled to give the module visibility on fresh
            // installs; existing installs that have ever explicitly set this
            // key keep their stored value (getAppValue only uses the default
            // when the key is unset).
            'presenceModuleEnabled'  => $config->getAppValue(Application::APP_ID, 'presence_module_enabled', '1') === '1',
            // Decisions module — default ON (v3.75.4). Same rationale as above.
            'decisionsModuleEnabled' => $config->getAppValue(Application::APP_ID, 'decisions_module_enabled', '1') === '1',
            // OpenProject module — default OFF (v4.9.16): useless without an
            // OpenProject to talk to, so an administrator opts in. The switch
            // alone; whether the module is *available* also needs the licence,
            // which the admin panel reads from the License tab it already has.
            // Key and default live in OpenProjectModuleService.
            'openProjectModuleEnabled' => $config->getAppValue(
                Application::APP_ID,
                \OCA\TeamHub\Service\OpenProject\OpenProjectModuleService::CONFIG_ENABLED,
                '0',
            ) === '1',
            // Service teams module — default OFF (v4.10.46, Justin
            // 2026-09-25): service teams change how an organisation asks each
            // other for things, so a client switches them on when its people
            // are ready rather than because it upgraded. Licensed too, like
            // OpenProject above, and for the same reason only the switch is
            // stored here. Key and default live in ServiceTeamService.
            'serviceTeamsModuleEnabled' => $config->getAppValue(
                Application::APP_ID,
                \OCA\TeamHub\Service\ServiceTeam\ServiceTeamService::CONFIG_ENABLED,
                '0',
            ) === '1',
            // Sub-teams module — licensed, and **default ON** (v4.12.3,
            // Justin 2026-10-12). The other two licensed modules default off
            // because each needs something else first: OpenProject needs an
            // OpenProject, service teams need somebody to run a desk. A
            // sub-team needs nothing but a team, so a licensed instance has
            // them from the moment it upgrades. Key and default live in
            // SubTeamModuleService; only the switch is stored here, the
            // licence half comes from the License tab the panel already has.
            'subTeamsModuleEnabled' => $config->getAppValue(
                Application::APP_ID,
                SubTeamModuleService::CONFIG_ENABLED,
                '1',
            ) === '1',
            // File reviews are no longer an administrator switch (v4.8.31).
            // Whether they exist follows the licence, because a review's whole
            // working life happens in My Work and My Work is licensed — a
            // switched-on unlicensed instance could create reviews nobody could
            // ever see. `FileReviewService::isEnabledGlobally()` is the one
            // answer; there is deliberately no stored value to report here.
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
            // v4.10.23 — `workflowTeamRequestGroup` was here. Step 3 of a
            // team request is the service team that holds the Nextcloud
            // services, so there is no group left to pick (DESIGN §2.146).
            // The date the create-team wizard's picker opens on when a user
            // first clicks it. Computed server-side so the wizard, the CSV
            // importer's documentation and this panel cannot drift apart.
            'expiryDefaultDate'      => TeamExpiryService::defaultPickerDateFor(),
        ];
    }

    /**
     * Isolates getDelegationStatus() from getAdminSettings() so that any failure
     * (e.g. group_folders_applicable table absent on a fresh GF install) cannot
     * break the settings load response and wipe the team-creator group from the UI.
     */
    /**
     * The IdP-teams block of the admin panel (v4.11.32).
     *
     * Resolved lazily: this panel is read on every admin page load and the
     * provisioning service pulls in the rest of the creation stack.
     *
     * `serviceAccountProblem` is carried rather than a bare bool so the panel
     * can say *why* provisioning cannot run — no account, a deleted one, a
     * disabled one, or one that may not create teams — which is the whole
     * difference between an administrator fixing it and filing a bug.
     *
     * @return array<string,mixed>
     */
    private function idpTeamSettings(): array {
        try {
            $rule = $this->container->get(IdpTeamService::class);

            return [
                'mode'               => $rule->mode(),
                'backends'           => $rule->backends(),
                'ldapOu'             => $rule->ldapOu(),
                'ldapDnAvailable'    => $rule->ldapDnAvailable(),
                'pattern'            => $rule->pattern(),
                'template'           => $rule->template(),
                'profile'            => $rule->profile(),
                'serviceAccount'     => $rule->serviceAccount(),
                'onGroupGone'        => $rule->onGroupGone(),
                // Reading this needs the provisioning service, which is the
                // heavier of the two; a failure here must not cost the panel
                // its other eight values.
                'serviceAccountProblem' => $this->safeServiceAccountProblem(),
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamService] IdP team settings unavailable', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
            return ['mode' => IdpTeamService::MODE_OFF, 'unavailable' => true];
        }
    }

    private function safeServiceAccountProblem(): string {
        try {
            return $this->container->get(IdpTeamProvisionService::class)->serviceAccountProblem();
        } catch (\Throwable $e) {
            return 'unavailable';
        }
    }

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
        if (isset($settings['personSublineFields'])) {
            // v4.10.7 — an empty list is a valid choice (no subline);
            // storableValue() encodes it so it is not read as "the default".
            $config->setAppValue(
                Application::APP_ID,
                PersonSublineService::CONFIG_KEY,
                PersonSublineService::storableValue(explode(',', (string)$settings['personSublineFields'])),
            );
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

        // ── IdP teams (v4.11.32) ─────────────────────────────────────────
        //
        // Clamped rather than rejected throughout, like the three settings
        // above, because this tab autosaves as the administrator types. Every
        // value is validated against a closed vocabulary where one exists —
        // an unrecognised mode must not read as "on".
        // ── Teams made outside TeamHub (v4.11.34) ──────────────────
        //
        // Clamped to `review` like every closed vocabulary above: an
        // unrecognised value must read as "ask somebody", never as
        // "accept everything" or "refuse everything".
        if (isset($settings['teamAdoptionMode'])) {
            $adoptionMode = (string)$settings['teamAdoptionMode'];
            $config->setAppValue(
                Application::APP_ID,
                TeamAdoptionService::SETTING_MODE,
                TeamAdoptionService::normaliseMode($adoptionMode),
            );
        }
        // Length-capped only, like the IdP pair below: what is a valid
        // template or profile is the policy tables' answer, and it is given
        // at the point of use (`defaultTemplate()` / `defaultProfile()`),
        // where a key that has since been deleted falls back rather than
        // leaving the setting unreadable.
        if (isset($settings['teamAdoptionTemplate'])) {
            $config->setAppValue(
                Application::APP_ID,
                TeamAdoptionService::SETTING_TEMPLATE,
                mb_substr(trim((string)$settings['teamAdoptionTemplate']), 0, 64),
            );
        }
        if (isset($settings['teamAdoptionProfile'])) {
            $config->setAppValue(
                Application::APP_ID,
                TeamAdoptionService::SETTING_PROFILE,
                mb_substr(trim((string)$settings['teamAdoptionProfile']), 0, 64),
            );
        }

        if (isset($settings['idpTeamsMode'])) {
            $mode = (string)$settings['idpTeamsMode'];
            $config->setAppValue(
                Application::APP_ID,
                IdpTeamService::SETTING_MODE,
                in_array($mode, IdpTeamService::MODES, true) ? $mode : IdpTeamService::MODE_OFF,
            );
        }
        if (isset($settings['idpTeamsBackends'])) {
            // Free text by necessity: a backend name comes from whatever user
            // backend app is installed, so there is no closed list to check
            // against. Length-capped and comma-normalised; an unknown name
            // simply matches no group.
            $names = array_values(array_unique(array_filter(
                array_map(
                    static fn (string $n): string => mb_substr(trim($n), 0, 64),
                    explode(',', (string)$settings['idpTeamsBackends']),
                ),
                static fn (string $n): bool => $n !== '',
            )));
            $config->setAppValue(Application::APP_ID, IdpTeamService::SETTING_BACKENDS, implode(',', $names));
        }
        if (isset($settings['idpTeamsLdapOu'])) {
            $config->setAppValue(
                Application::APP_ID,
                IdpTeamService::SETTING_LDAP_OU,
                mb_substr(trim((string)$settings['idpTeamsLdapOu']), 0, 1000),
            );
        }
        if (isset($settings['idpTeamsPattern'])) {
            $config->setAppValue(
                Application::APP_ID,
                IdpTeamService::SETTING_PATTERN,
                mb_substr(trim((string)$settings['idpTeamsPattern']), 0, 512),
            );
        }
        if (isset($settings['idpTeamsTemplate'])) {
            $config->setAppValue(
                Application::APP_ID,
                IdpTeamService::SETTING_TEMPLATE,
                mb_substr(trim((string)$settings['idpTeamsTemplate']), 0, 64),
            );
        }
        if (isset($settings['idpTeamsProfile'])) {
            $config->setAppValue(
                Application::APP_ID,
                IdpTeamService::SETTING_PROFILE,
                mb_substr(trim((string)$settings['idpTeamsProfile']), 0, 64),
            );
        }
        if (isset($settings['idpTeamsServiceAccount'])) {
            // Stored even when the account cannot create teams: the panel
            // reports the problem from `serviceAccountProblem()` so the
            // administrator can see what they picked and why it will not work,
            // rather than having their choice silently discarded.
            $config->setAppValue(
                Application::APP_ID,
                IdpTeamService::SETTING_SERVICE_ACCOUNT,
                mb_substr(trim((string)$settings['idpTeamsServiceAccount']), 0, 64),
            );
        }
        if (isset($settings['idpTeamsOnGroupGone'])) {
            $gone = (string)$settings['idpTeamsOnGroupGone'];
            $config->setAppValue(
                Application::APP_ID,
                IdpTeamService::SETTING_ON_GROUP_GONE,
                in_array($gone, IdpTeamService::GONE_MODES, true)
                    ? $gone
                    : IdpTeamService::GONE_KEEP_AND_REPORT,
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
        // v4.9.16 — the OpenProject module's switch. Written whatever the
        // licence says: the panel hides the control while unlicensed, and a
        // stored '1' on an unlicensed instance is inert (OpenProjectModuleService
        // checks the licence first), so an instance that licenses later finds
        // the module already on if that is what the administrator chose.
        if (isset($settings['openProjectModuleEnabled'])) {
            $config->setAppValue(
                Application::APP_ID,
                \OCA\TeamHub\Service\OpenProject\OpenProjectModuleService::CONFIG_ENABLED,
                $settings['openProjectModuleEnabled'] ? '1' : '0'
            );
        }
        // v4.10.46 — the service teams module's switch. Written whatever the
        // licence says, exactly as the OpenProject one above: the panel hides
        // the control while unlicensed, and a stored '1' is inert until a
        // licence arrives (ServiceTeamService checks the licence first).
        if (isset($settings['serviceTeamsModuleEnabled'])) {
            $config->setAppValue(
                Application::APP_ID,
                \OCA\TeamHub\Service\ServiceTeam\ServiceTeamService::CONFIG_ENABLED,
                $settings['serviceTeamsModuleEnabled'] ? '1' : '0'
            );
        }
        // v4.12.3 — the sub-teams module's switch. `array_key_exists` and not
        // `isset`, unlike the three above: this one defaults to **on**, so the
        // value an administrator sends to switch it off is `false`, and
        // `isset()` would accept it but the panel's own `false` is exactly
        // what has to get through. Written whatever the licence says — the
        // panel hides the control while unlicensed, and a stored value is
        // inert until a licence arrives (SubTeamModuleService checks the
        // licence first).
        if (array_key_exists('subTeamsModuleEnabled', $settings)) {
            $config->setAppValue(
                Application::APP_ID,
                SubTeamModuleService::CONFIG_ENABLED,
                $settings['subTeamsModuleEnabled'] ? '1' : '0'
            );
        }
        // `fileReviewsModuleEnabled` was written here until v4.8.31. It is
        // ignored now rather than accepted-and-discarded: an older admin bundle
        // still posting the field gets the same answer as a current one, which
        // is that the licence decides.
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
        // Member count, in the order Circles itself prefers (v4.9.2).
        //
        // getPopulationInherited() expands nested teams and groups; getMembers()
        // counts direct rows only, so a team whose members include a group of
        // twelve reported 1. MaintenanceService has counted from the
        // circles_membership cache for exactly this reason since v4.6 — this was
        // the one path still disagreeing with it, which meant the same team could
        // show two different member counts depending on which screen asked.
        //
        // The Contacts app made the same move for the same reason, settling on
        // populationInherited as the single source. Both fall back rather than
        // fail: the inherited figure is hydrated from the circle's settings and
        // is 0 when the caller fetched the circle without them.
        $memberCount = 0;
        if (method_exists($circle, 'getPopulationInherited')) {
            $memberCount = (int)$circle->getPopulationInherited();
        }
        if ($memberCount === 0 && method_exists($circle, 'getPopulation')) {
            $memberCount = (int)$circle->getPopulation();
        }
        if ($memberCount === 0 && method_exists($circle, 'getMembers')) {
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
