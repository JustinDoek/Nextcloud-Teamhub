<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\CirclesConfig;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\TeamImageService;
use OCA\TeamHub\Db\PendingDeletionMapper;
use OCA\TeamHub\Db\TeamAppMapper;
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

    public function __construct(
        private MemberService        $memberService,
        private ResourceService      $resourceService,
        private ActivityService      $activityService,
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
            $this->logger->warning('[TeamService] getUserTeams called without authenticated user', ['app' => Application::APP_ID]);
            return [];
        }

        if (!$this->appManager->isInstalled('circles')) {
            $this->logger->warning('[TeamService] Circles app is not enabled', ['app' => Application::APP_ID]);
            return [];
        }

        try {
            $db  = $this->container->get(\OCP\IDBConnection::class);
            $uid = $user->getUID();

            // Resolve the user's personal-circle single_id so we can check
            // circles_membership for indirect (group/team) membership.
            $userSingleId = $this->memberService->resolveUserSingleId($uid, $db);
            $this->logger->debug('[TeamService] getUserTeams: resolved single_id', [
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
               ->orderBy('c.name', 'ASC');

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

            $this->logger->debug('[TeamService] getUserTeams: found teams', [
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

            $this->logger->info('[TeamService] getTeams: unread counts computed', [
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

                $teams[] = [
                    'id'          => $id,
                    'name'        => $row['name'],
                    'description' => $row['description'] ?? '',
                    'members'     => $memberCounts[$id] ?? 0,
                    'unread'      => $unread,
                    'image_url'   => $this->teamImageService->getImageUrl($id),
                    // Circles config bitmask — exposed so the frontend can render
                    // human-readable "team type" labels (open/invite/public/etc).
                    'config'      => (int)($row['config'] ?? 0),
                ];
            }


            return $teams;

        } catch (\Exception $e) {
            $this->logger->error('[TeamService] Error in getUserTeams', ['exception' => $e, 'app' => Application::APP_ID]);
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
                $this->logger->debug('[TeamService] getTeam: access denied (not direct or indirect member)', [
                    'uid' => $uid, 'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
                throw new \Exception('Team not found or access denied');
            }
            $this->logger->debug('[TeamService] getTeam: access granted via indirect membership', [
                'uid' => $uid, 'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
        }

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
            'id'          => $row['unique_id'],
            'name'        => $row['name'],
            'description' => $row['description'] ?? '',
            'members'     => $memberCount,
            'image_url'   => $this->teamImageService->getImageUrl($row['unique_id']),
            // Circles config bitmask — exposed so the frontend can render
            // human-readable "team type" labels (open/invite/public/etc).
            'config'      => (int)($row['config'] ?? 0),
        ];
    }

    // =========================================================================
    // Team CRUD
    // =========================================================================

    /**
     * Create a new team.
     * Description is always set via updateTeamDescription() separately.
     */
    public function createTeam(string $name): array {

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

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
            $this->logger->error('[TeamService] Error creating team', ['exception' => $e, 'app' => Application::APP_ID]);
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

        // Collect the app_ids that currently have provisioned resources for
        // this team. We read teamhub_team_apps directly (not via getTeamApps())
        // so we don't need to go through TeamAppMapper and can catch any error.
        // We delete resources for ALL apps found in the table, regardless of the
        // `enabled` flag — a disabled app may still have its resource provisioned
        // (the resource persists when an app tab is hidden; it is only deleted
        // when the admin explicitly removes it via the manage-team interface).
        $appsToClean = [];
        try {
            $qb = $db->getQueryBuilder();
            $res = $qb->select('app_id')
                ->from('teamhub_team_apps')
                ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
                ->executeQuery();
            while ($row = $res->fetch()) {
                if (!empty($row['app_id'])) {
                    $appsToClean[] = (string)$row['app_id'];
                }
            }
            $res->closeCursor();
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamService] deleteTeam: could not read teamhub_team_apps', [
                'teamId' => $teamId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
        }

        // ── Step 2: Delete provisioned Nextcloud app resources ────────────────
        // Run BEFORE circle destruction so that sub-services that need to
        // look up the circle (e.g. CalDAV principaluri = principals/circles/{id})
        // still find it. Each app is independently try/caught so one failure
        // does not prevent the others or the circle deletion from proceeding.
        foreach ($appsToClean as $app) {
            try {
                $result = $this->resourceService->deleteTeamResource($teamId, $app);
                $this->logger->debug('[TeamService] deleteTeam: resource deleted', [
                    'teamId' => $teamId,
                    'app'    => $app,
                    'result' => $result,
                    'class'  => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamService] deleteTeam: resource deletion failed', [
                    'teamId' => $teamId,
                    'app'    => $app,
                    'error'  => $e->getMessage(),
                    'class'  => Application::APP_ID,
                ]);
            }
        }

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
            $this->logger->error('[TeamService] Error updating team description', [
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
     * Bits managed by TeamHub:
     *   1=CFG_OPEN, 2=CFG_INVITE, 4=CFG_REQUEST, 16=CFG_PROTECTED,
     *   512=CFG_VISIBLE, 1024=CFG_SINGLE
     * All other bits (e.g. 256=CFG_PERSONAL, 32768=CFG_ROOT) are preserved.
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

            // CFG_VISIBLE bitmask (bit 9 = 512): circles with this bit set are
            // discoverable by non-members. We push the filter into SQL so we never
            // load all circles into PHP — critical for scalability to 1,000+ groups.
            //
            // Strategy: LEFT JOIN on direct membership (user_type=1) AND on the
            // circles_membership cache (indirect via group/team).
            // Include the circle if:
            //   (a) the user has a direct member row (m.user_id IS NOT NULL), OR
            //   (b) the user appears in circles_membership (ms.single_id IS NOT NULL), OR
            //   (c) the circle has CFG_VISIBLE set (config & 512 != 0)
            $CFG_VISIBLE = 512;

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
                       )
                   )
               )
               ->orderBy('c.name', 'ASC');

            $result = $qb->executeQuery();
            $teams  = [];

            while ($row = $result->fetch()) {
                $name = $row['name'] ?? '';
                // Filter out internal Circles auto-circles (personal/group circles)
                if (str_starts_with($name, 'user:') || str_starts_with($name, 'group:')) {
                    continue;
                }

                $config           = (int)($row['config'] ?? 0);
                $isOpen           = ($config & 1) > 0;
                $isDirectMember   = $row['member_uid'] !== null;
                $isIndirectMember = !$isDirectMember && ($row['ms_single_id'] ?? null) !== null;

                $teams[] = [
                    'id'               => $row['unique_id'],
                    'name'             => $name,
                    'description'      => $row['description'] ?? '',
                    'isMember'         => $isDirectMember || $isIndirectMember,
                    'isDirectMember'   => $isDirectMember,
                    'requiresApproval' => !$isOpen,
                    'image_url'        => $this->teamImageService->getImageUrl($row['unique_id']),
                ];
            }
            $result->closeCursor();

            return $teams;

        } catch (\Exception $e) {
            $this->logger->error('[TeamService] Error browsing teams', ['exception' => $e, 'app' => Application::APP_ID]);
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
            // Presence module — default off. NC admin must explicitly enable.
            'presenceModuleEnabled'  => $config->getAppValue(Application::APP_ID, 'presence_module_enabled', '0') === '1',
            // Decisions module — default off. NC admin must explicitly enable.
            'decisionsModuleEnabled' => $config->getAppValue(Application::APP_ID, 'decisions_module_enabled', '0') === '1',
            // Decisions module — default off. NC admin must explicitly enable.
            'decisionsModuleEnabled' => $config->getAppValue(Application::APP_ID, 'decisions_module_enabled', '0') === '1',
            // RoomVox API token: never return the token value itself, only a
            // boolean indicating whether one is configured. The admin can
            // overwrite it (write field) but can't read it back (read field).
            // This matches NC's pattern for SMTP passwords and other secrets.
            'roomvoxTokenConfigured' => $config->getAppValue(Application::APP_ID, 'roomvox_api_token', '') !== '',
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
            $this->logger->warning('[TeamService] getDelegationStatus failed — returning safe defaults', [
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
            $this->logger->warning('[TeamService] saveAdminSettings — non-admin attempt', [
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
        if (array_key_exists('createTeamGroup', $settings)) {
            $raw = $settings['createTeamGroup'];

            // Accept JSON array sent from the multi-picker (e.g. '["admins","managers"]')
            if (is_string($raw) && str_starts_with(trim($raw), '[')) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $raw = implode(',', array_filter(array_map('trim', $decoded)));
                }
            }

            // Sanitise: only printable, non-comma chars per group ID
            $gids = array_filter(
                array_map('trim', explode(',', (string)$raw)),
                fn($g) => $g !== '' && preg_match('/^[^\s,]+$/', $g)
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
        ];
    }

}
