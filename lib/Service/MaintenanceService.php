<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Maintenance operations for NC admins.
 *
 * Orphaned team: a circle in circles_circle that has no member row
 * with status='Member' AND level=9 (owner). This can happen when an
 * owner account is deleted from Nextcloud without first deleting their teams.
 */
class MaintenanceService {

    public function __construct(
        private IDBConnection      $db,
        private IUserSession       $userSession,
        private IUserManager       $userManager,
        private ContainerInterface $container,
        private LoggerInterface    $logger,
        private ResourceService    $resourceService,
        private AuditService       $auditService,
    ) {}

    // -------------------------------------------------------------------------
    // All teams grid (admin maintenance view)
    // -------------------------------------------------------------------------

    /**
     * Return a paginated list of all real user-created teams on this NC instance.
     *
     * Filters applied:
     *   - Only circles whose name starts with 'app:circles:' (real user teams).
     *     user:, group:, app:occ:, mail: etc. are silently skipped.
     *   - Optional name search (case-insensitive LIKE %term%).
     *   - Optional orphans-only filter (no owner = no member row with level=9 + status=Member).
     *
     * Result shape per team:
     *   id, name, description, member_count, owner (uid|null), owner_display_name (string|null), creation (ISO 8601|null)
     *
     * Pagination is done in PHP after fetching all matching rows so we can
     * apply the complex in-PHP name filtering (strip system circles) before counting.
     * For very large installations (thousands of teams) this is still fast because
     * circles_circle is small compared to content tables.
     *
     * @param string $search      Substring to match against display name (empty = all).
     * @param int    $page        1-based page number.
     * @param int    $perPage     Rows per page (10|20|50|100).
     * @param bool   $orphansOnly When true, only return teams with no owner.
     *
     * @return array{ total: int, page: int, per_page: int, teams: list<array> }
     */
    public function getAllTeams(
        string $search = '',
        int    $page = 1,
        int    $perPage = 20,
        bool   $orphansOnly = false,
    ): array {


        // Clamp perPage to allowed values
        $perPage = in_array($perPage, [10, 20, 50, 100], true) ? $perPage : 20;
        $page    = max(1, $page);

        try {
            // ── Step 1: collect all circles with owner info ───────────────────
            // LEFT JOIN on circles_member (level=9 owner row) so we get owner in one pass.
            $qb = $this->db->getQueryBuilder();
            $qb->select(
                    'c.unique_id',
                    'c.name',
                    'c.description',
                    'c.creation',
                    'c.display_name',
                    'c.sanitized_name',
                    'o.user_id AS owner_uid',
                )
                ->from('circles_circle', 'c')
                ->leftJoin(
                    'c',
                    'circles_member',
                    'o',
                    $qb->expr()->andX(
                        $qb->expr()->eq('o.circle_id',  'c.unique_id'),
                        $qb->expr()->eq('o.level',      $qb->createNamedParameter(9, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)),
                        $qb->expr()->eq('o.status',     $qb->createNamedParameter('Member')),
                        $qb->expr()->eq('o.user_type',  $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)),
                    )
                )
                ->orderBy('c.name', 'ASC');

            $result = $qb->executeQuery();
            $rawRows = [];
            while ($row = $result->fetch()) {
                $rawRows[] = $row;
            }
            $result->closeCursor();


            // ── Step 2: filter to real user teams + apply search/orphan filter ─
            $lowerSearch = $search !== '' ? mb_strtolower($search) : '';

            $filtered = [];
            foreach ($rawRows as $row) {
                $name = $row['name'] ?? '';

                // Skip all known system/auto-generated circle types.
                // Real user-created teams are anything that does NOT carry one of
                // these internal prefixes. New Circles versions may use other
                // prefixes but these are the confirmed system ones.
                $systemPrefixes = ['user:', 'group:', 'mail:', 'app:occ:', 'contact:'];
                $isSystem = false;
                foreach ($systemPrefixes as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        $isSystem = true;
                        break;
                    }
                }
                if ($isSystem) {
                    continue;
                }

                // Resolve display name — prefer display_name -> sanitized_name -> name as-is.
                // Strip the 'app:circles:' prefix if present (legacy NC Circles format),
                // but leave the name untouched for any other format (e.g. plain team name).
                $displayName = '';
                if (!empty($row['display_name'])) {
                    $displayName = $row['display_name'];
                } elseif (!empty($row['sanitized_name'])) {
                    $displayName = $row['sanitized_name'];
                } elseif (str_starts_with($name, 'app:circles:')) {
                    $displayName = substr($name, strlen('app:circles:'));
                } else {
                    $displayName = $name;
                }


                // Search filter
                if ($lowerSearch !== '' && mb_strpos(mb_strtolower($displayName), $lowerSearch) === false) {
                    continue;
                }

                // Orphans-only filter
                $ownerUid = $row['owner_uid'] ?? null;
                if ($orphansOnly && $ownerUid !== null) {
                    continue;
                }

                $filtered[] = [
                    '_id'         => $row['unique_id'],
                    '_name'       => $displayName,
                    '_description'=> $row['description'] ?? '',
                    '_creation'   => $row['creation'] ?? null,
                    '_owner_uid'  => $ownerUid,
                ];
            }

            $total = count($filtered);

            // ── Step 3: paginate ──────────────────────────────────────────────
            $offset = ($page - 1) * $perPage;
            $page_rows = array_slice($filtered, $offset, $perPage);

            if (empty($page_rows)) {
                return ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'teams' => []];
            }

            // ── Step 4: effective member counts from circles_membership ──────────
            // circles_membership is the denormalised cache that Circles maintains.
            // It expands all groups and sub-circles, giving the true user count.
            // Using circles_member instead would undercount when groups/teams are members.
            $pageIds = array_column($page_rows, '_id');

            $cqb = $this->db->getQueryBuilder();
            $cqb->select('circle_id', $cqb->func()->count('*', 'cnt'))
                ->from('circles_membership')
                ->where($cqb->expr()->in('circle_id', $cqb->createNamedParameter($pageIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
                ->groupBy('circle_id');
            $cRes = $cqb->executeQuery();
            $memberCounts = [];
            while ($cRow = $cRes->fetch()) {
                $memberCounts[$cRow['circle_id']] = (int)$cRow['cnt'];
            }
            $cRes->closeCursor();

            // ── Step 5: owner display names ───────────────────────────────────
            $ownerUids = array_filter(array_unique(array_column($page_rows, '_owner_uid')));
            $ownerNames = [];
            foreach ($ownerUids as $uid) {
                $u = $this->userManager->get($uid);
                $ownerNames[$uid] = $u ? ($u->getDisplayName() ?: $uid) : $uid;
            }

            // ── Step 6: assemble ──────────────────────────────────────────────
            $teams = [];
            foreach ($page_rows as $r) {
                $uid = $r['_owner_uid'];
                $teams[] = [
                    'id'                 => $r['_id'],
                    'name'               => $r['_name'],
                    'description'        => $r['_description'],
                    'member_count'       => $memberCounts[$r['_id']] ?? 0,
                    'owner'              => $uid,
                    'owner_display_name' => $uid !== null ? ($ownerNames[$uid] ?? $uid) : null,
                    'creation'           => $r['_creation'],
                ];
            }

            return [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'teams'    => $teams,
            ];

        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub] MaintenanceService::getAllTeams failed', ['exception' => $e]);
            throw new \Exception('Failed to load teams: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Orphaned teams (legacy — kept for backward compat, now calls getAllTeams)
    // -------------------------------------------------------------------------

    /**
     * Return all circles that have no owner (level=9, status=Member).
     *
     * Result shape per team:
     *   id, name, member_count
     */
    public function getOrphanedTeams(): array {

        try {
            // Find circle IDs that have at least one owner
            $ownedQb = $this->db->getQueryBuilder();
            $ownedQb->select('circle_id')
                ->from('circles_member')
                ->where($ownedQb->expr()->eq('level',  $ownedQb->createNamedParameter(9,        \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($ownedQb->expr()->eq('status', $ownedQb->createNamedParameter('Member')))
                ->andWhere($ownedQb->expr()->eq('user_type', $ownedQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
            $ownedResult = $ownedQb->executeQuery();
            $ownedIds = [];
            while ($row = $ownedResult->fetch()) {
                $ownedIds[] = $row['circle_id'];
            }
            $ownedResult->closeCursor();


            // Get all circles — we'll filter out the owned ones.
            // sanitized_name holds the clean display name on some NC versions.
            $circleQb = $this->db->getQueryBuilder();
            $circleQb->select('c.unique_id', 'c.name', 'c.description')
                ->from('circles_circle', 'c')
                ->orderBy('c.name', 'ASC');

            // Also try to select sanitized_name — it exists in NC 25+ Circles.
            // We catch any column-not-found error after the fact; easier than schema inspection.
            $hasSanitized = false;
            try {
                $testQb = $this->db->getQueryBuilder();
                $testQb->select('sanitized_name')->from('circles_circle')->setMaxResults(1)->executeQuery()->closeCursor();
                $hasSanitized = true;
                $circleQb->addSelect('c.sanitized_name');
            } catch (\Throwable $e) {
            }

            $circleResult = $circleQb->executeQuery();

            $orphans = [];
            while ($row = $circleResult->fetch()) {
                $id   = $row['unique_id'];
                $name = $row['name'] ?? '';

                // Only include real user-created teams — these always start with 'app:circles:'.
                // Skip system circles: user personal circles (user:), group circles (group:),
                // app-internal circles (app:occ:), mail/contact circles (mail:), etc.
                if (!str_starts_with($name, 'app:circles:')) {
                    continue;
                }
                // Skip circles that have an owner
                if (in_array($id, $ownedIds, true)) {
                    continue;
                }

                // Count members
                $countQb = $this->db->getQueryBuilder();
                $countRes = $countQb->select($countQb->func()->count('*', 'cnt'))
                    ->from('circles_member')
                    ->where($countQb->expr()->eq('circle_id', $countQb->createNamedParameter($id)))
                    ->andWhere($countQb->expr()->eq('status', $countQb->createNamedParameter('Member')))
                    ->executeQuery();
                $countRow    = $countRes->fetch();
                $memberCount = $countRow ? (int)$countRow['cnt'] : 0;
                $countRes->closeCursor();

                // Prefer sanitized_name (clean display name stored by NC Circles).
                // Fall back to stripping the 'app:circles:' prefix from name.
                if ($hasSanitized && !empty($row['sanitized_name'])) {
                    $displayName = $row['sanitized_name'];
                } else {
                    $displayName = substr($name, strlen('app:circles:'));
                }

                $orphans[] = [
                    'id'           => $id,
                    'name'         => $displayName,
                    'raw_name'     => $name,
                    'description'  => $row['description'] ?? '',
                    'member_count' => $memberCount,
                ];
            }
            $circleResult->closeCursor();

            return $orphans;

        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub] MaintenanceService::getOrphanedTeams failed', ['exception' => $e]);
            throw new \Exception('Failed to load orphaned teams: ' . $e->getMessage());
        }
    }

    /**
     * Delete any team — admin-only, no ownership required.
     *
     * Strategy:
     *   1. Delete all team resources (Talk, Files, Calendar, Deck, IntraVox).
     *   2. Delete TeamHub DB rows.
     *   3. Reset circle config to 0 (clears CFG_PROTECTED, CFG_SINGLE etc.) so
     *      CircleService::destroy() does not refuse the operation.
     *   4. Insert/promote the admin to owner (level=9) so destroy() passes its
     *      ownership check.
     *   5. Call CircleService::destroy() — this removes the circle and ALL its
     *      member rows in one go.
     *   6. If destroy() still fails (edge case), fall back to raw DB DELETE of
     *      the circle row and clean up the admin member row we inserted.
     *
     * On any failure in step 5/6, the admin member row is removed so the grid
     * does not show phantom entries.
     */
    public function deleteOrphanedTeam(string $teamId): void {

        $this->requireNcAdmin();

        $adminUser = $this->userSession->getUser();
        if (!$adminUser) {
            throw new \Exception('No authenticated session');
        }
        $adminUid = $adminUser->getUID();


        // ── Step 1: Delete all team resources ────────────────────────────────
        foreach (['talk', 'files', 'calendar', 'deck', 'intravox'] as $app) {
            try {
                $result = $this->resourceService->deleteTeamResource($teamId, $app);
            } catch (\Throwable $e) {
            }
        }

        // ── Step 2: Delete TeamHub DB rows ────────────────────────────────────
        $this->deleteTeamHubData($teamId);

        // ── Step 3: Reset circle config to 0 ─────────────────────────────────
        // config bits like CFG_PROTECTED (16) or CFG_SINGLE (2048) cause
        // CircleService::destroy() to refuse the operation. Clear them all.
        // IMPORTANT: use a single QueryBuilder instance — calling getQueryBuilder()
        // multiple times creates independent builders that share parameter names
        // (dcValue1) causing a MySQL syntax error in the generated SQL.
        $cfgQb = $this->db->getQueryBuilder();
        $cfgQb->update('circles_circle')
            ->set('config', $cfgQb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->where($cfgQb->expr()->eq('unique_id', $cfgQb->createNamedParameter($teamId)))
            ->executeStatement();

        // ── Step 4: Ensure admin has a level=9 member row ────────────────────
        // CircleService::destroy() checks the caller is owner. Track whether we
        // inserted this row so we can clean it up if destroy fails.
        $adminRowInserted = false;

        $checkQb  = $this->db->getQueryBuilder();
        $checkRes = $checkQb->select('id', 'level')
            ->from('circles_member')
            ->where($checkQb->expr()->eq('circle_id',  $checkQb->createNamedParameter($teamId)))
            ->andWhere($checkQb->expr()->eq('user_id',   $checkQb->createNamedParameter($adminUid)))
            ->andWhere($checkQb->expr()->eq('user_type', $checkQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $existingRow = $checkRes->fetch();
        $checkRes->closeCursor();

        if ($existingRow) {
            if ((int)$existingRow['level'] < 9) {
                $promQb = $this->db->getQueryBuilder();
                $promQb->update('circles_member')
                    ->set('level',  $promQb->createNamedParameter(9, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    ->set('status', $promQb->createNamedParameter('Member'))
                    ->where($promQb->expr()->eq('circle_id',  $promQb->createNamedParameter($teamId)))
                    ->andWhere($promQb->expr()->eq('user_id',   $promQb->createNamedParameter($adminUid)))
                    ->andWhere($promQb->expr()->eq('user_type', $promQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->executeStatement();
            } else {
            }
        } else {
            $existingCols = array_flip($this->resourceService->getTableColumns('circles_member'));
            $singleId     = substr(md5($teamId . $adminUid . uniqid('', true)), 0, 31);
            $insertQb     = $this->db->getQueryBuilder();
            $values = [
                'circle_id' => $insertQb->createNamedParameter($teamId),
                'single_id' => $insertQb->createNamedParameter($singleId),
                'user_id'   => $insertQb->createNamedParameter($adminUid),
                'user_type' => $insertQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                'member_id' => $insertQb->createNamedParameter($adminUid),
                'instance'  => $insertQb->createNamedParameter(''),
                'level'     => $insertQb->createNamedParameter(9, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                'status'    => $insertQb->createNamedParameter('Member'),
                'joined'    => $insertQb->createNamedParameter(date('Y-m-d H:i:s', time())),
            ];
            $optional = [
                'display_name' => $insertQb->createNamedParameter($adminUser->getDisplayName() ?: $adminUid),
                'cached_name'  => $insertQb->createNamedParameter($adminUser->getDisplayName() ?: $adminUid),
                'note'         => $insertQb->createNamedParameter(''),
                'contact_id'   => $insertQb->createNamedParameter(''),
                'contact_meta' => $insertQb->createNamedParameter(''),
            ];
            foreach ($optional as $col => $val) {
                if (isset($existingCols[$col])) {
                    $values[$col] = $val;
                }
            }
            $insertQb->insert('circles_member')->values($values)->executeStatement();
            $adminRowInserted = true;
        }

        // ── Step 5: Destroy via CircleService ─────────────────────────────────
        $destroySucceeded = false;
        try {
            $appManager = $this->container->get(\OCP\App\IAppManager::class);
            if ($appManager->isInstalled('circles')) {
                $circleService        = $this->container->get(\OCA\Circles\Service\CircleService::class);
                $federatedUserService = $this->container->get(\OCA\Circles\Service\FederatedUserService::class);
                $federatedUserService->setLocalCurrentUser($adminUser);
                $circleService->destroy($teamId);
                $destroySucceeded = true;
            }
        } catch (\Throwable $e) {
        }

        // ── Step 6: Raw DB fallback if CircleService::destroy failed ──────────
        if (!$destroySucceeded) {
            try {
                // Delete all member rows for this circle
                $delMembQb = $this->db->getQueryBuilder();
                $delMembQb->delete('circles_member')
                    ->where($delMembQb->expr()->eq('circle_id', $delMembQb->createNamedParameter($teamId)))
                    ->executeStatement();

                // Delete the circle row itself
                $delCircQb = $this->db->getQueryBuilder();
                $delCircQb->delete('circles_circle')
                    ->where($delCircQb->expr()->eq('unique_id', $delCircQb->createNamedParameter($teamId)))
                    ->executeStatement();

            } catch (\Throwable $e) {
                throw new \Exception('Failed to delete team: ' . $e->getMessage());
            }
        }
    }

    /**
     * Assign a new owner to any team — admin-only, no membership check required.
     *
     * Strategy (pure DB — no Circles API calls that can fail):
     *
     * 1. Downgrade any existing owner rows for this circle to level 4 (moderator)
     *    so there is never more than one owner.
     * 2. Check whether the target user already has a member row in circles_member.
     *    - YES → UPDATE level=9, status=Member on that row.
     *    - NO  → INSERT a minimal member row with level=9, status=Member.
     *    The minimal INSERT uses only the columns the Circles app requires for
     *    basic membership display. Federation/single_id fields are left NULL/default
     *    and will be populated by Circles on next access.
     * 3. Reset the circle's config bitmask to 0 so probeCircles() does not hide it.
     *
     * This approach works for both existing members and non-members, and avoids
     * the addMember() API which fails when the user already has a row (even a
     * stale one) and cannot create a consistent membership object.
     */
    public function assignOwner(string $teamId, string $userId, bool $enforceNcAdmin = true): void {

        if ($enforceNcAdmin) {
            $this->requireNcAdmin();
        }

        // Capture the caller user object now — needed for the notification at the end.
        $adminUser = $this->userSession->getUser();
        if (!$adminUser) {
            throw new \Exception('No authenticated session');
        }

        // Capture the current owner UID *before* the demote step. Used in the
        // audit metadata so the log shows from-whom -> to-whom, and so the
        // event is meaningful when the previous owner has been deleted from NC.
        $previousOwnerUid = null;
        try {
            $prevQb = $this->db->getQueryBuilder();
            $prevRes = $prevQb->select('user_id')
                ->from('circles_member')
                ->where($prevQb->expr()->eq('circle_id', $prevQb->createNamedParameter($teamId)))
                ->andWhere($prevQb->expr()->eq('level',     $prevQb->createNamedParameter(9, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($prevQb->expr()->eq('user_type', $prevQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery();
            $prevRow = $prevRes->fetch();
            $prevRes->closeCursor();
            if ($prevRow && !empty($prevRow['user_id'])) {
                $previousOwnerUid = (string)$prevRow['user_id'];
            }
        } catch (\Throwable $e) { /* non-fatal — audit row will just lack prev-owner */ }


        // Validate the target user exists in NC
        $user = $this->userManager->get($userId);
        if (!$user) {
            throw new \Exception('User not found: ' . $userId);
        }

        // ── Step 1: Demote any current owners to moderator (level 4) ─────────
        $demoteQb = $this->db->getQueryBuilder();
        $demoted  = $demoteQb->update('circles_member')
            ->set('level', $demoteQb->createNamedParameter(4, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->where($demoteQb->expr()->eq('circle_id', $demoteQb->createNamedParameter($teamId)))
            ->andWhere($demoteQb->expr()->eq('level',   $demoteQb->createNamedParameter(9, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        // ── Step 2: Does the target user already have a member row? ──────────
        $checkQb  = $this->db->getQueryBuilder();
        $checkRes = $checkQb->select('id')
            ->from('circles_member')
            ->where($checkQb->expr()->eq('circle_id',  $checkQb->createNamedParameter($teamId)))
            ->andWhere($checkQb->expr()->eq('user_id',   $checkQb->createNamedParameter($userId)))
            ->andWhere($checkQb->expr()->eq('user_type', $checkQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $existingRow = $checkRes->fetch();
        $checkRes->closeCursor();

        if ($existingRow) {
            // UPDATE existing row
            $updateQb = $this->db->getQueryBuilder();
            $affected = $updateQb->update('circles_member')
                ->set('level',  $updateQb->createNamedParameter(9, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->set('status', $updateQb->createNamedParameter('Member'))
                ->where($updateQb->expr()->eq('circle_id',  $updateQb->createNamedParameter($teamId)))
                ->andWhere($updateQb->expr()->eq('user_id',   $updateQb->createNamedParameter($userId)))
                ->andWhere($updateQb->expr()->eq('user_type', $updateQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();
        } else {
            // INSERT a minimal member row — must match the column set that Circles expects.
            // Use the same pattern as MemberService::requestJoinTeam() which is confirmed working:
            //   - joined is DATETIME, not INT — must use date() format
            //   - instance must be '' (not NULL) for local users
            //   - member_id = userId for local users
            //   - optional columns (display_name, cached_name, note, contact_id, contact_meta)
            //     are probed via getTableColumns() and included only if the column exists.
            $singleId = substr(md5($teamId . $userId . uniqid('', true)), 0, 31);

            $insertQb     = $this->db->getQueryBuilder();
            $existingCols = array_flip($this->resourceService->getTableColumns('circles_member'));

            $values = [
                'circle_id'  => $insertQb->createNamedParameter($teamId),
                'single_id'  => $insertQb->createNamedParameter($singleId),
                'user_id'    => $insertQb->createNamedParameter($userId),
                'user_type'  => $insertQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                'member_id'  => $insertQb->createNamedParameter($userId),
                'instance'   => $insertQb->createNamedParameter(''),
                'level'      => $insertQb->createNamedParameter(9, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                'status'     => $insertQb->createNamedParameter('Member'),
                'joined'     => $insertQb->createNamedParameter(date('Y-m-d H:i:s', time())),
            ];

            $optional = [
                'display_name' => $insertQb->createNamedParameter($user->getDisplayName() ?: $userId),
                'cached_name'  => $insertQb->createNamedParameter($user->getDisplayName() ?: $userId),
                'note'         => $insertQb->createNamedParameter(''),
                'contact_id'   => $insertQb->createNamedParameter(''),
                'contact_meta' => $insertQb->createNamedParameter(''),
            ];
            foreach ($optional as $col => $val) {
                if (isset($existingCols[$col])) {
                    $values[$col] = $val;
                }
            }

            $insertQb->insert('circles_member')->values($values)->executeStatement();
        }

        // ── Step 3: Verify the promotion actually landed ──────────────────────
        $verifyQb  = $this->db->getQueryBuilder();
        $verifyRes = $verifyQb->select('level', 'status')
            ->from('circles_member')
            ->where($verifyQb->expr()->eq('circle_id',  $verifyQb->createNamedParameter($teamId)))
            ->andWhere($verifyQb->expr()->eq('user_id',   $verifyQb->createNamedParameter($userId)))
            ->andWhere($verifyQb->expr()->eq('user_type', $verifyQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $verifyRow = $verifyRes->fetch();
        $verifyRes->closeCursor();

        if (!$verifyRow || (int)$verifyRow['level'] !== 9) {
            throw new \Exception('Owner assignment failed — could not verify level=9 row in circles_member.');
        }

        // ── Step 4: Reset the circle config to 0 so probeCircles() shows it ──
        $configQb = $this->db->getQueryBuilder();
        $configQb->update('circles_circle')
            ->set('config', $configQb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->where($configQb->expr()->eq('unique_id', $configQb->createNamedParameter($teamId)))
            ->executeStatement();

        // ── Step 4b: Rebuild the circles_membership cache ─────────────────────
        // Raw SQL writes to circles_member do NOT update the circles_membership
        // denormalised cache that share pickers query. Without this, newly-added
        // owners cannot share resources with the team even though they are in
        // circles_member. This is equivalent to running:
        //   occ circles:memberships --force <teamId>
        try {
            $membershipService = $this->container->get(\OCA\Circles\Service\MembershipService::class);
            // onUpdate rebuilds the membership cache for the given single_id.
            // The circle's own unique_id is its single_id for top-level circles.
            $membershipService->onUpdate($teamId);
        } catch (\Throwable $e) {
            // Non-fatal — log and continue. Admin can run `occ circles:memberships`
            // manually if the cache rebuild fails here.
            $this->logger->warning('[MaintenanceService] assignOwner: membership cache rebuild failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // ── Step 5: Send NC notification to the new owner ────────────────────
        $this->sendOwnerAssignedNotification($teamId, $userId, $adminUser);

        // ── Step 6: Audit log ─────────────────────────────────────────────────
        // Logged AFTER all verification + notification steps so a failed
        // promotion never produces a misleading audit row.
        $this->auditService->log(
            $teamId,
            'team.owner_transferred',
            $adminUser->getUID(),
            'team',
            $teamId,
            [
                'previous_owner' => $previousOwnerUid,
                'new_owner'      => $userId,
                'enforced_admin' => $enforceNcAdmin,
            ],
        );
    }

    /**
     * Send a Nextcloud notification to the newly assigned team owner.
     * Non-fatal — logged but never throws.
     */
    private function sendOwnerAssignedNotification(string $teamId, string $newOwnerUid, \OCP\IUser $adminUser): void {
        try {
            $notificationManager = $this->container->get(INotificationManager::class);
            $urlGenerator        = $this->container->get(IURLGenerator::class);

            // Resolve team display name
            $nameQb  = $this->db->getQueryBuilder();
            $nameRes = $nameQb->select('name', 'display_name', 'sanitized_name')
                ->from('circles_circle')
                ->where($nameQb->expr()->eq('unique_id', $nameQb->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $nameRow  = $nameRes->fetch();
            $nameRes->closeCursor();

            $teamName = '';
            if ($nameRow) {
                if (!empty($nameRow['display_name'])) {
                    $teamName = $nameRow['display_name'];
                } elseif (!empty($nameRow['sanitized_name'])) {
                    $teamName = $nameRow['sanitized_name'];
                } else {
                    $raw = $nameRow['name'] ?? '';
                    $teamName = str_starts_with($raw, 'app:circles:')
                        ? substr($raw, strlen('app:circles:'))
                        : $raw;
                }
            }
            if ($teamName === '') {
                $teamName = $teamId;
            }

            $link = $urlGenerator->linkToRouteAbsolute('teamhub.page.index') . '?team=' . urlencode($teamId);

            $notification = $notificationManager->createNotification();
            $notification->setApp('teamhub')
                ->setUser($newOwnerUid)
                ->setDateTime(new \DateTime())
                ->setObject('owner_assigned', $teamId)
                ->setSubject('owner_assigned', [
                    'adminUid'  => $adminUser->getUID(),
                    'adminName' => $adminUser->getDisplayName() ?: $adminUser->getUID(),
                    'teamId'    => $teamId,
                    'teamName'  => $teamName,
                ])
                ->setLink($link);
            $notificationManager->notify($notification);

        } catch (\Throwable $e) {
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function requireNcAdmin(): void {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('Not authenticated');
        }
        $groupManager = $this->container->get(\OCP\IGroupManager::class);
        if (!$groupManager->isAdmin($user->getUID())) {
            throw new \Exception('NC admin privilege required');
        }
    }

    private function deleteTeamHubData(string $teamId): void {
        $tables = [
            'teamhub_messages'              => 'team_id',
            'teamhub_web_links'             => 'team_id',
            'teamhub_layout'                => 'team_id',
            'teamhub_team_apps'             => 'team_id',
            'teamhub_team_integrations'     => 'team_id',
        ];

        foreach ($tables as $table => $column) {
            try {
                $this->db->executeStatement(
                    'DELETE FROM `*PREFIX*' . $table . '` WHERE `' . $column . '` = ?',
                    [$teamId]
                );
            } catch (\Throwable $e) {
            }
        }
    }

    private function getTableColumns(string $table): array {
        try {
            $result = $this->db->executeQuery('SELECT * FROM `*PREFIX*' . $table . '` WHERE 1=0');
            $cols   = array_keys($result->fetch(\PDO::FETCH_ASSOC) ?: []);
            $result->closeCursor();
            if (empty($cols)) {
                // No rows returned — use column metadata
                $sm   = $this->db->createSchemaManager();
                $cols = array_keys($sm->listTableColumns('*PREFIX*' . $table));
            }
            return $cols;
        } catch (\Throwable $e) {
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Membership cache integrity check and repair
    // -------------------------------------------------------------------------

    /**
     * Scan every user-created team and check that the circles_membership
     * denormalised cache is populated for every team that has direct members.
     *
     * OLD (wrong) logic compared circles_member count vs circles_membership count.
     * Those numbers are expected to differ whenever groups or sub-teams are added:
     *   circles_member  = direct entries (1 row per user/group/team added)
     *   circles_membership = expanded cache (1 row per effective user)
     *
     * NEW logic:
     *   A team is HEALTHY when either:
     *     • it has 0 direct member rows (empty team — cache is correctly empty), OR
     *     • it has ≥1 effective user rows in circles_membership (cache is populated)
     *
     *   A team is UNHEALTHY (stale cache) when:
     *     • it has ≥1 direct member rows in circles_member AND
     *     • it has 0 rows in circles_membership
     *     → The cache needs rebuilding (run Repair).
     *
     * Returns { total_teams, healthy, mismatched, issues: [{id, name, direct_count, effective_count}] }
     */
    public function checkMembershipIntegrity(): array {
        $this->requireNcAdmin();

        $issues  = [];
        $total   = 0;
        $healthy = 0;

        // Fetch all user-created circles (source=16 = Nextcloud app-created circles)
        $qb = $this->db->getQueryBuilder();
        $res = $qb->select('c.unique_id', 'c.name')
            ->from('circles_circle', 'c')
            ->where($qb->expr()->eq('c.source', $qb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        $circles = $res->fetchAll();
        $res->closeCursor();

        foreach ($circles as $circle) {
            $total++;
            $teamId = $circle['unique_id'];

            // Count ANY direct member entries (all user_types, all statuses that matter)
            $mqb = $this->db->getQueryBuilder();
            $mqb->select($mqb->func()->count('*', 'c'))
                ->from('circles_member')
                ->where($mqb->expr()->eq('circle_id', $mqb->createNamedParameter($teamId)))
                ->andWhere($mqb->expr()->eq('status', $mqb->createNamedParameter('Member')));
            $mRes        = $mqb->executeQuery();
            $directCount = (int)$mRes->fetchOne();
            $mRes->closeCursor();

            // Count rows in the expanded cache
            $cqb = $this->db->getQueryBuilder();
            $cqb->select($cqb->func()->count('*', 'c'))
                ->from('circles_membership')
                ->where($cqb->expr()->eq('circle_id', $cqb->createNamedParameter($teamId)));
            $cRes          = $cqb->executeQuery();
            $effectiveCount = (int)$cRes->fetchOne();
            $cRes->closeCursor();

            // Healthy: empty team OR cache populated
            $isHealthy = ($directCount === 0) || ($effectiveCount > 0);

            if ($isHealthy) {
                $healthy++;
            } else {
                // Stale cache: has members but cache is empty
                $issues[] = [
                    'id'              => $teamId,
                    'name'            => $circle['name'],
                    'direct_count'    => $directCount,
                    'effective_count' => $effectiveCount,
                    // Keep legacy keys so the frontend stays compatible
                    'member_count'     => $directCount,
                    'membership_count' => $effectiveCount,
                ];
            }
        }

        return [
            'total_teams' => $total,
            'healthy'     => $healthy,
            'mismatched'  => count($issues),
            'issues'      => $issues,
        ];
    }

    /**
     * Rebuild the circles_membership cache for a single team.
     * Equivalent to `occ circles:memberships --force <teamId>`.
     *
     * @throws \Exception if not NC admin or if the rebuild fails
     */
    public function repairMembershipCache(string $teamId): void {
        $this->requireNcAdmin();

        try {
            $membershipService = $this->container->get(\OCA\Circles\Service\MembershipService::class);
            $membershipService->onUpdate($teamId);
            $this->logger->info('[MaintenanceService] repairMembershipCache: rebuilt cache for team', [
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[MaintenanceService] repairMembershipCache failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            throw new \Exception('Failed to rebuild membership cache: ' . $e->getMessage());
        }
    }
}
