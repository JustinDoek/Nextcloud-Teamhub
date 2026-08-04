<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\CirclesConfig;
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
            $seen    = [];
            while ($row = $result->fetch()) {
                $uid = $row['unique_id'];
                // Deduplicate: if a circle has multiple level=9 member rows (edge case
                // after manual repair attempts), the LEFT JOIN produces duplicate rows.
                // Keep the first occurrence (it has an owner_uid set) and skip the rest.
                if (!isset($seen[$uid])) {
                    $seen[$uid] = true;
                    $rawRows[] = $row;
                }
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

                // Skip system circles — same exclusion list as getAllTeams() and checkMembershipIntegrity().
                // Real user-created teams have a plain name (NC33+) or 'app:circles:' prefix (older NC).
                $systemPrefixes = ['user:', 'group:', 'mail:', 'app:occ:', 'contact:'];
                $isSystemCircle = false;
                foreach ($systemPrefixes as $pfx) {
                    if (str_starts_with($name, $pfx)) {
                        $isSystemCircle = true;
                        break;
                    }
                }
                if ($isSystemCircle) {
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
            $this->logger->warning('[TeamHub][MaintenanceService] assignOwner: membership cache rebuild failed', [
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
                $dqb = $this->db->getQueryBuilder();
                $dqb->delete($table)
                    ->where($dqb->expr()->eq($column, $dqb->createNamedParameter($teamId)))
                    ->executeStatement();
            } catch (\Throwable $e) {
            }
        }
    }

    private function getTableColumns(string $table): array {
        try {
            // Use DBAL SchemaManager — works on both MySQL and Postgres.
            // NC prefixes the table name automatically via the connection.
            $sm   = $this->db->createSchemaManager();
            $cols = array_keys($sm->listTableColumns($this->db->getPrefix() . $table));
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

        // ── Step A: detect contradictory team nesting ────────────────────────
        // Team-as-member is now a supported feature (>= 3.40.1). A sub-team
        // relationship is only an issue when the child team has CFG_ROOT (8192)
        // set — meaning "Prevent this team from being a member of another team"
        // is active in both TeamHub and Contacts, yet the team IS nested.
        // That is a contradictory state: the admin said "no nesting" but nesting
        // exists. Flag it and offer to remove the membership.
        //
        // Valid nesting (CFG_ROOT NOT set on child) is intentional and is skipped.
        $CFG_ROOT_BIT = \OCA\TeamHub\Constants\CirclesConfig::CFG_ROOT; // 8192
        $nestedQb = $this->db->getQueryBuilder();
        $nestedRes = $nestedQb
            ->select('cm.circle_id', 'cm.single_id', 'parent.name AS parent_name', 'child.name AS child_name', 'child.config AS child_config')
            ->from('circles_member', 'cm')
            ->innerJoin('cm', 'circles_circle', 'parent', $nestedQb->expr()->eq('parent.unique_id', 'cm.circle_id'))
            ->innerJoin('cm', 'circles_circle', 'child',  $nestedQb->expr()->eq('child.unique_id',  'cm.single_id'))
            ->where($nestedQb->expr()->eq('cm.user_type',    $nestedQb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($nestedQb->expr()->eq('cm.status',    $nestedQb->createNamedParameter('Member')))
            ->andWhere($nestedQb->expr()->eq('child.source', $nestedQb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($nestedQb->expr()->eq('parent.source', $nestedQb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere(
                // Only flag when the child has CFG_ROOT set (prevention active but nesting exists)
                $nestedQb->createFunction('(child.config & ' . $CFG_ROOT_BIT . ')') . ' > 0'
            )
            ->executeQuery();

        while ($nestedRow = $nestedRes->fetch()) {
            $issues[] = [
                'id'               => $nestedRow['circle_id'],
                'name'             => $nestedRow['parent_name'],
                'issue_type'       => 'nested_team',
                'nested_team_id'   => $nestedRow['single_id'],
                'nested_team_name' => $nestedRow['child_name'],
                'direct_count'     => 0,
                'effective_count'  => 0,
                'member_count'     => 0,
                'membership_count' => 0,
            ];
        }
        $nestedRes->closeCursor();

        // ── Step B: detect user-created teams with CFG_SINGLE (1024) wrongly set ─
        // CFG_SINGLE marks a circle as a "personal circle" (the auto-created
        // per-user circle). When set on a user-created team (source=16), Circles
        // treats the team as personal and hides it from all API queries.
        // This happens when TeamHub previously wrote bitmask 1024 as the
        // "prevent nesting" setting — which was the wrong bit.
        // Fix: clear bit 1024 from the config.
        $CFG_SINGLE = 1024;
        $singleQb = $this->db->getQueryBuilder();
        $singleRes = $singleQb
            ->select('c.unique_id', 'c.name', 'c.config', 'c.display_name', 'c.sanitized_name')
            ->from('circles_circle', 'c')
            ->where($singleQb->expr()->eq('c.source', $singleQb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->executeQuery();

        while ($singleRow = $singleRes->fetch()) {
            $cfg  = (int)$singleRow['config'];
            $name = (string)($singleRow['name'] ?? '');

            if (!($cfg & $CFG_SINGLE)) {
                continue; // bit not set — fine
            }

            // Skip circles that are legitimately personal or system — these
            // are supposed to have CFG_SINGLE set. Only flag user-created teams.
            $skipPrefixes = ['user:', 'mail:', 'app:occ:', 'group:', 'contact:'];
            $skip = false;
            foreach ($skipPrefixes as $pfx) {
                if (str_starts_with($name, $pfx)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            // Resolve display name
            $displayName = $singleRow['display_name'] ?? '';
            if ($displayName === '') {
                $displayName = $singleRow['sanitized_name'] ?? '';
            }
            if ($displayName === '') {
                $n = $singleRow['name'] ?? '';
                $displayName = str_starts_with($n, 'app:circles:') ? substr($n, 12) : $n;
            }
            $issues[] = [
                'id'               => $singleRow['unique_id'],
                'name'             => $displayName,
                'issue_type'       => 'cfg_single_set',
                'direct_count'     => 0,
                'effective_count'  => 0,
                'member_count'     => 0,
                'membership_count' => 0,
            ];
        }
        $singleRes->closeCursor();

        // ── Step C: detect circles with duplicate user rows (same user_id, user_type=1) ─
        // This happens when a team-nesting operation adds a side-effect member row
        // for a user that is already a direct member, or when a repair attempt
        // inserts a second owner row. Both cause duplicate entries in the admin list
        // and confusion in Circles' membership checks.
        // We detect ANY case where the same user_id appears more than once as a
        // direct member (user_type=1) in the same circle.
        $dupQb = $this->db->getQueryBuilder();
        $dupQb->select('cm.circle_id', 'cm.user_id', 'cc.name',
                       $dupQb->func()->count('cm.id', 'row_count'),
                       $dupQb->func()->max('cm.level', 'max_level'))
            ->from('circles_member', 'cm')
            ->innerJoin('cm', 'circles_circle', 'cc', $dupQb->expr()->eq('cc.unique_id', 'cm.circle_id'))
            ->where($dupQb->expr()->eq('cm.user_type', $dupQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($dupQb->expr()->eq('cm.status',   $dupQb->createNamedParameter('Member')))
            ->andWhere($dupQb->expr()->eq('cc.source',   $dupQb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->groupBy('cm.circle_id', 'cm.user_id', 'cc.name')
            ->having($dupQb->expr()->gt(
                $dupQb->createFunction('COUNT(cm.id)'),
                $dupQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
            ));

        $dupRes = $dupQb->executeQuery();
        while ($dupRow = $dupRes->fetch()) {
            $issues[] = [
                'id'               => $dupRow['circle_id'],
                'name'             => $dupRow['name'],
                'issue_type'       => 'duplicate_member',
                'duplicate_uid'    => $dupRow['user_id'],
                'row_count'        => (int)$dupRow['row_count'],
                'max_level'        => (int)$dupRow['max_level'],
                'direct_count'     => 0,
                'effective_count'  => 0,
                'member_count'     => 0,
                'membership_count' => 0,
            ];
        }
        $dupRes->closeCursor();

        // ── Step D: detect source=16 teams with no owner (level=9) ──────────
        // Uses LEFT JOIN to find circles with zero level=9 member rows.
        $noOwnerQb  = $this->db->getQueryBuilder();
        $noOwnerRes = $noOwnerQb
            ->select('c.unique_id', 'c.name', 'c.display_name', 'c.sanitized_name')
            ->from('circles_circle', 'c')
            ->leftJoin('c', 'circles_member', 'o',
                $noOwnerQb->expr()->andX(
                    $noOwnerQb->expr()->eq('o.circle_id',  'c.unique_id'),
                    $noOwnerQb->expr()->eq('o.level',      $noOwnerQb->createNamedParameter(9, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)),
                    $noOwnerQb->expr()->eq('o.status',     $noOwnerQb->createNamedParameter('Member')),
                    $noOwnerQb->expr()->eq('o.user_type',  $noOwnerQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                )
            )
            ->where($noOwnerQb->expr()->eq('c.source', $noOwnerQb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($noOwnerQb->expr()->isNull('o.circle_id'))
            ->executeQuery();

        $systemPfx = ['user:', 'mail:', 'app:occ:', 'group:', 'contact:'];
        while ($noOwnerRow = $noOwnerRes->fetch()) {
            $name = (string)($noOwnerRow['name'] ?? '');
            $skip = false;
            foreach ($systemPfx as $pfx) {
                if (str_starts_with($name, $pfx)) { $skip = true; break; }
            }
            if ($skip) continue;

            $displayName = $noOwnerRow['display_name'] ?? '';
            if ($displayName === '') $displayName = $noOwnerRow['sanitized_name'] ?? '';
            if ($displayName === '') {
                $displayName = str_starts_with($name, 'app:circles:') ? substr($name, 12) : $name;
            }

            // Count existing direct members to determine if auto-repair is possible
            $mbQb  = $this->db->getQueryBuilder();
            $mbRes = $mbQb->select($mbQb->func()->count('id', 'cnt'))
                ->from('circles_member')
                ->where($mbQb->expr()->eq('circle_id', $mbQb->createNamedParameter($noOwnerRow['unique_id'])))
                ->andWhere($mbQb->expr()->eq('status',   $mbQb->createNamedParameter('Member')))
                ->andWhere($mbQb->expr()->eq('user_type', $mbQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeQuery();
            $memberCount = (int)$mbRes->fetchOne();
            $mbRes->closeCursor();

            $issues[] = [
                'id'               => $noOwnerRow['unique_id'],
                'name'             => $displayName,
                'issue_type'       => 'no_owner',
                'has_members'      => $memberCount > 0,
                'direct_count'     => $memberCount,
                'effective_count'  => 0,
                'member_count'     => $memberCount,
                'membership_count' => 0,
            ];
        }
        $noOwnerRes->closeCursor();

        // ── Step E: detect source=16 teams where display_name doesn't match ──
        // Circles sometimes sets display_name to the owner's name instead of the
        // team name (a Circles bug). This causes Circles to treat the circle as a
        // personal circle, applying CFG_SINGLE and hiding it from all API queries.
        // We detect cases where display_name differs from sanitized_name.
        // Repair: set display_name = sanitized_name (or the stripped team name).
        $dnQb  = $this->db->getQueryBuilder();
        $dnRes = $dnQb
            ->select('c.unique_id', 'c.name', 'c.display_name', 'c.sanitized_name')
            ->from('circles_circle', 'c')
            ->where($dnQb->expr()->eq('c.source', $dnQb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($dnQb->expr()->neq('c.display_name', $dnQb->createNamedParameter('')))
            ->andWhere($dnQb->expr()->neq('c.sanitized_name', $dnQb->createNamedParameter('')))
            ->andWhere($dnQb->expr()->neq('c.display_name', 'c.sanitized_name'))
            ->executeQuery();

        while ($dnRow = $dnRes->fetch()) {
            $name = (string)($dnRow['name'] ?? '');
            $skip = false;
            foreach ($systemPfx as $pfx) {
                if (str_starts_with($name, $pfx)) { $skip = true; break; }
            }
            if ($skip) continue;

            $displayName    = (string)$dnRow['display_name'];
            $sanitizedName  = (string)$dnRow['sanitized_name'];

            $issues[] = [
                'id'               => $dnRow['unique_id'],
                'name'             => $displayName,   // show the wrong name so admin recognises it
                'issue_type'       => 'wrong_display_name',
                'correct_name'     => $sanitizedName,
                'direct_count'     => 0,
                'effective_count'  => 0,
                'member_count'     => 0,
                'membership_count' => 0,
            ];
        }
        $dnRes->closeCursor();

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
                $issues[] = [
                    'id'               => $teamId,
                    'name'             => $circle['name'],
                    'issue_type'       => 'stale_cache',
                    'direct_count'     => $directCount,
                    'effective_count'  => $effectiveCount,
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
     * Remove duplicate circles_member rows for the same user_id in a circle.
     * Keeps the row with the highest level (owner survives over moderator/member).
     * If levels are equal, keeps the oldest row by id.
     * Returns the number of rows deleted.
     */
    public function repairDuplicateMember(string $teamId, string $userId): int {
        $this->requireNcAdmin();

        // Fetch all rows for this user in this circle, ordered by level DESC then id ASC
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('id', 'level')
            ->from('circles_member')
            ->where($qb->expr()->eq('circle_id',  $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('user_id',  $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status',   $qb->createNamedParameter('Member')))
            ->orderBy('level', 'DESC')
            ->addOrderBy('id', 'ASC')
            ->executeQuery();

        $rows = [];
        while ($row = $res->fetch()) {
            $rows[] = ['id' => (int)$row['id'], 'level' => (int)$row['level']];
        }
        $res->closeCursor();

        if (count($rows) <= 1) {
            return 0;
        }

        // Keep the first row (highest level, oldest id among ties), delete the rest
        $keepId = $rows[0]['id'];
        $delIds = array_map(fn($r) => $r['id'], array_slice($rows, 1));

        $delQb = $this->db->getQueryBuilder();
        $delQb->delete('circles_member')
            ->where($delQb->expr()->in('id', $delQb->createNamedParameter($delIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
        $removed = $delQb->executeStatement();

        $this->logger->info('[TeamHub][MaintenanceService] repairDuplicateMember: removed duplicate rows', [
            'teamId' => $teamId, 'userId' => $userId,
            'kept' => $keepId, 'removed' => $removed, 'app' => Application::APP_ID,
        ]);

        return $removed;
    }

    /**
     * Fix a circle's display_name to match its sanitized_name.
     * Used when Circles has incorrectly set display_name to the owner's name.
     */
    public function fixDisplayName(string $teamId): string {
        $this->requireNcAdmin();

        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('sanitized_name', 'name', 'source')
            ->from('circles_circle')
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();

        if (!$row) {
            throw new \Exception("Circle not found: {$teamId}");
        }

        $correctName = (string)$row['sanitized_name'];
        if ($correctName === '') {
            // Fall back to stripping the app:circles: prefix from name
            $rawName = (string)$row['name'];
            $correctName = str_starts_with($rawName, 'app:circles:')
                ? substr($rawName, strlen('app:circles:'))
                : $rawName;
        }

        $updQb = $this->db->getQueryBuilder();
        $updQb->update('circles_circle')
            ->set('display_name', $updQb->createNamedParameter($correctName))
            ->where($updQb->expr()->eq('unique_id', $updQb->createNamedParameter($teamId)))
            ->executeStatement();

        $this->logger->info('[TeamHub][MaintenanceService] fixDisplayName: corrected display_name', [
            'teamId' => $teamId, 'newDisplayName' => $correctName, 'app' => Application::APP_ID,
        ]);

        return $correctName;
    }

    /**
     * Repair a team with no owner by promoting the highest-level member
     * or inserting the calling admin if the team is empty.
     * Returns the uid of the new owner.
     */
    public function repairMissingOwner(string $teamId): string {
        $this->requireNcAdmin();

        $adminUser = $this->userSession->getUser();
        if (!$adminUser) {
            throw new \Exception('No authenticated session');
        }

        // Find the highest-level existing direct member (excluding any already at 9)
        $mbQb  = $this->db->getQueryBuilder();
        $mbRes = $mbQb->select('id', 'user_id', 'level')
            ->from('circles_member')
            ->where($mbQb->expr()->eq('circle_id',  $mbQb->createNamedParameter($teamId)))
            ->andWhere($mbQb->expr()->eq('status',   $mbQb->createNamedParameter('Member')))
            ->andWhere($mbQb->expr()->eq('user_type', $mbQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->orderBy('level', 'DESC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults(1)
            ->executeQuery();
        $existing = $mbRes->fetch();
        $mbRes->closeCursor();

        if ($existing) {
            // Promote existing highest-level member to owner
            $rowId  = (int)$existing['id'];
            $newUid = (string)$existing['user_id'];

            $updQb = $this->db->getQueryBuilder();
            $updQb->update('circles_member')
                ->set('level', $updQb->createNamedParameter(9, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->where($updQb->expr()->eq('id', $updQb->createNamedParameter($rowId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $this->logger->info('[TeamHub][MaintenanceService] assignOwner: promoted existing member to owner', [
                'teamId' => $teamId, 'uid' => $newUid, 'app' => Application::APP_ID,
            ]);
            return $newUid;
        }

        // No members — insert the calling admin as owner
        $adminUid = $adminUser->getUID();
        $now      = time();

        // Generate a single_id for the new member row — use the admin's personal circle unique_id
        $singleId = $this->memberService->resolveUserSingleId($adminUid, $this->db) ?? $adminUid;

        $insQb = $this->db->getQueryBuilder();
        $insQb->insert('circles_member')
            ->setValue('circle_id',  $insQb->createNamedParameter($teamId))
            ->setValue('single_id',  $insQb->createNamedParameter($singleId))
            ->setValue('user_id',    $insQb->createNamedParameter($adminUid))
            ->setValue('user_type',  $insQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->setValue('level',      $insQb->createNamedParameter(9, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->setValue('status',     $insQb->createNamedParameter('Member'))
            ->setValue('cached_name', $insQb->createNamedParameter($adminUid))
            ->setValue('cached_update', $insQb->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->setValue('joined',     $insQb->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->executeStatement();

        $this->logger->info('[TeamHub][MaintenanceService] assignOwner: inserted admin as owner', [
            'teamId' => $teamId, 'uid' => $adminUid, 'app' => Application::APP_ID,
        ]);
        return $adminUid;
    }

    /**
     * Clear the CFG_SINGLE (1024) bit from a user-created team's config.
     * This bit marks a circle as a "personal circle" and hides it from
     * Circles' own API when set incorrectly on a user-created team (source=16).
     */
    public function clearCfgSingle(string $teamId): void {
        $this->requireNcAdmin();

        // Read current config
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('config', 'source')
            ->from('circles_circle')
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();

        if (!$row) {
            throw new \Exception("Circle not found: {$teamId}");
        }
        if ((int)$row['source'] !== 16) {
            throw new \Exception("Circle {$teamId} is not a user-created team (source={$row['source']})");
        }

        $currentConfig = (int)$row['config'];
        $newConfig     = $currentConfig & ~1024; // clear CFG_SINGLE

        $updQb = $this->db->getQueryBuilder();
        $updQb->update('circles_circle')
            ->set('config', $updQb->createNamedParameter($newConfig, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->where($updQb->expr()->eq('unique_id', $updQb->createNamedParameter($teamId)))
            ->executeStatement();

        $this->logger->info('[TeamHub][MaintenanceService] clearCfgSingle: cleared bit 1024', [
            'teamId' => $teamId, 'oldConfig' => $currentConfig, 'newConfig' => $newConfig,
            'app' => Application::APP_ID,
        ]);
    }

    /**
     * Remove a team-as-member row from circles_member.
     * Deletes the row where circle_id=parentTeamId, single_id=childTeamId, user_type=16.
     * This repairs the visibility corruption caused by a team being nested inside another team.
     */
    public function removeNestedTeam(string $parentTeamId, string $childTeamId): void {
        $this->requireNcAdmin();

        $qb = $this->db->getQueryBuilder();
        $qb->delete('circles_member')
            ->where($qb->expr()->eq('circle_id',  $qb->createNamedParameter($parentTeamId)))
            ->andWhere($qb->expr()->eq('single_id', $qb->createNamedParameter($childTeamId)))
            ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));

        $removed = $qb->executeStatement();

        $this->logger->info('[TeamHub][MaintenanceService] removeNestedTeam: removed circles_member rows', [
            'parentTeamId' => $parentTeamId, 'childTeamId' => $childTeamId,
            'rows' => $removed, 'app' => Application::APP_ID,
        ]);

        if ($removed === 0) {
            throw new \Exception("No nested-team row found for parent={$parentTeamId} child={$childTeamId}");
        }
    }

    // -------------------------------------------------------------------------
    // Ghost member cleanup — deleted NC users still in circles_member
    // -------------------------------------------------------------------------

    /**
     * Find direct user members (user_type=1, status=Member) whose NC account
     * no longer exists. Optionally filter by display-name / uid substring.
     *
     * Returns array of shape:
     *   [{ userId, displayName, teams: [{ teamId, teamName }] }]
     *
     * Grouped by user: one entry per ghost uid, listing every team they appear in.
     * Capped at 200 results to prevent overloading the admin view.
     */
    public function findGhostMembers(string $search = ''): array {
        $this->requireNcAdmin();

        $search = trim(strtolower($search));

        // Query all user_type=1 (local user) rows from circles_member, regardless of status.
        // Circles does NOT immediately remove rows when NC deletes a user — the row stays,
        // which is why deleted users still appear in the members widget.
        // We intentionally omit the status filter to catch rows in any state.
        $qb = $this->db->getQueryBuilder();
        $qb->select('cm.user_id', 'cc.unique_id AS team_id', 'cc.name AS team_name')
            ->from('circles_member', 'cm')
            ->innerJoin('cm', 'circles_circle', 'cc', $qb->expr()->eq('cm.circle_id', 'cc.unique_id'))
            ->where($qb->expr()->eq('cm.user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));

        // Optionally select display_name / sanitized_name — column presence varies by Circles version.
        try {
            $this->db->getQueryBuilder()->select('display_name')->from('circles_circle')->setMaxResults(1)->executeQuery()->closeCursor();
            $qb->addSelect('cc.display_name AS team_display_name');
        } catch (\Throwable $e) {
            // Column absent — ignored
        }
        try {
            $this->db->getQueryBuilder()->select('sanitized_name')->from('circles_circle')->setMaxResults(1)->executeQuery()->closeCursor();
            $qb->addSelect('cc.sanitized_name AS team_sanitized_name');
        } catch (\Throwable $e) {
            // Column absent — ignored
        }

        $result = $qb->executeQuery();

        // System circle prefixes — same exclusion list as getAllTeams()
        $systemPrefixes = ['user:', 'group:', 'mail:', 'app:occ:', 'contact:'];

        /** @var array<string, array{userId: string, displayName: string, teams: list<array{teamId: string, teamName: string}>}> $ghosts */
        $ghosts = [];

        while ($row = $result->fetch()) {
            $uid              = (string)$row['user_id'];
            $teamId           = (string)$row['team_id'];
            $rawName          = (string)$row['team_name'];
            $displayNameCol   = (string)($row['team_display_name'] ?? '');
            $sanitizedNameCol = (string)($row['team_sanitized_name'] ?? '');
            // Skip Circles internal placeholders
            if ($uid === '' || $uid === 'owner') {
                continue;
            }

            // Skip system-generated circles (same logic as getAllTeams)
            $isSystem = false;
            foreach ($systemPrefixes as $prefix) {
                if (str_starts_with($rawName, $prefix)) {
                    $isSystem = true;
                    break;
                }
            }
            if ($isSystem) {
                continue;
            }

            // Only flag users whose NC account no longer exists.
            // userExists() returns false for fully deleted accounts.
            // Disabled accounts still return true — they are not ghosts.
            if ($this->userManager->userExists($uid)) {
                continue;
            }

            // Apply optional search filter against uid
            if ($search !== '' && !str_contains(strtolower($uid), $search)) {
                continue;
            }

            if (!isset($ghosts[$uid])) {
                $ghosts[$uid] = [
                    'userId'      => $uid,
                    'displayName' => $uid,
                    'teams'       => [],
                ];
            }

            // Resolve human-readable team name — same priority as getAllTeams()
            if ($displayNameCol !== '') {
                $teamDisplayName = $displayNameCol;
            } elseif ($sanitizedNameCol !== '') {
                $teamDisplayName = $sanitizedNameCol;
            } elseif (str_starts_with($rawName, 'app:circles:')) {
                $teamDisplayName = substr($rawName, strlen('app:circles:'));
            } else {
                $teamDisplayName = $rawName;
            }

            $ghosts[$uid]['teams'][] = [
                'teamId'   => $teamId,
                'teamName' => $teamDisplayName,
            ];
        }
        $result->closeCursor();

        // Sort by uid, cap at 200
        usort($ghosts, fn($a, $b) => strcmp($a['userId'], $b['userId']));
        $ghosts = array_values(array_slice($ghosts, 0, 200));

        $this->logger->info('[TeamHub][MaintenanceService] findGhostMembers: scan complete', [
            'ghost_count' => count($ghosts), 'search' => $search, 'app' => Application::APP_ID,
        ]);

        return $ghosts;
    }

    /**
     * v4.2.10 — Compliance-tab summary. Cheap: reuses findGhostMembers and
     * getOrphanedTeams (both already scan the same rows the manual buttons
     * on the Maintenance tab work with) and collapses each into a count +
     * a first-hit sample string for the info popover. Called once per
     * Compliance-tab open.
     *
     * @return array{
     *     ghost_memberships: array{count: int, sample_uid: string|null},
     *     orphan_teams:      array{count: int, sample_name: string|null}
     * }
     */
    public function getComplianceSummary(): array {
        $this->requireNcAdmin();

        $ghosts = $this->findGhostMembers('');
        $orphans = $this->getOrphanedTeams();

        return [
            'ghost_memberships' => [
                'count'      => count($ghosts),
                'sample_uid' => $ghosts[0]['userId'] ?? null,
            ],
            'orphan_teams' => [
                'count'       => count($orphans),
                'sample_name' => $orphans[0]['name'] ?? null,
            ],
        ];
    }

    /**
     * Remove a single ghost user from all teams, or from a specific team.
     *
     * @param string      $userId NC uid of the deleted user
     * @param string|null $teamId If given, remove only from that team; otherwise from all
     */
    public function removeGhostMember(string $userId, ?string $teamId = null): int {
        $this->requireNcAdmin();

        // Safety: refuse to remove an account that still exists
        if ($this->userManager->userExists($userId)) {
            throw new \Exception("User {$userId} still exists — only deleted users can be removed via ghost cleanup.");
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete('circles_member')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));

        if ($teamId !== null) {
            $qb->andWhere($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)));
        }

        $removed = $qb->executeStatement();

        $this->logger->info('[TeamHub][MaintenanceService] removeGhostMember: removed rows', [
            'userId' => $userId, 'teamId' => $teamId ?? 'all', 'rows' => $removed, 'app' => Application::APP_ID,
        ]);

        return $removed;
    }

    // -------------------------------------------------------------------------

    /**
     * Rebuild the circles_membership cache for a single team.
     * Equivalent to `occ circles:memberships --force <teamId>`.
     *
     * @throws \Exception if not NC admin or if the rebuild fails
     */
    public function repairMembershipCache(string $teamId): void {
        $this->requireNcAdmin();

        // Strip any system-managed bits that may be set on this user team
        // (source=16). System bits like CFG_SINGLE (1), CFG_SYSTEM (4),
        // CFG_NO_OWNER (512), CFG_HIDDEN (1024), CFG_BACKEND (2048) must never
        // be set on a regular user team — they cause Circles to treat the team
        // as system-managed, hiding it from listings and breaking edits.
        try {
            $cfgQb  = $this->db->getQueryBuilder();
            $cfgRes = $cfgQb->select('config', 'source')
                ->from('circles_circle')
                ->where($cfgQb->expr()->eq('unique_id', $cfgQb->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $cfgRow = $cfgRes->fetch();
            $cfgRes->closeCursor();

            $forbidden = CirclesConfig::SYSTEM_BITS_FORBIDDEN_ON_USER_TEAMS;
            if ($cfgRow && (int)$cfgRow['source'] === 16 && ((int)$cfgRow['config'] & $forbidden)) {
                $oldCfg = (int)$cfgRow['config'];
                $newCfg = $oldCfg & ~$forbidden;
                $updQb  = $this->db->getQueryBuilder();
                $updQb->update('circles_circle')
                    ->set('config', $updQb->createNamedParameter($newCfg, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    ->where($updQb->expr()->eq('unique_id', $updQb->createNamedParameter($teamId)))
                    ->executeStatement();
                $this->logger->info('[TeamHub][MaintenanceService] repairMembershipCache: cleared forbidden system bits', [
                    'teamId' => $teamId, 'oldConfig' => $oldCfg, 'newConfig' => $newCfg,
                    'clearedMask' => $oldCfg & $forbidden,
                    'app' => Application::APP_ID,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MaintenanceService] repairMembershipCache: forbidden-bit clear failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        try {
            $membershipService = $this->container->get(\OCA\Circles\Service\MembershipService::class);
            $membershipService->onUpdate($teamId);
            $this->logger->info('[TeamHub][MaintenanceService] repairMembershipCache: rebuilt cache for team', [
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MaintenanceService] repairMembershipCache failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            throw new \Exception('Failed to rebuild membership cache: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Config bitmask integrity & repair
    // -------------------------------------------------------------------------

    /**
     * Reset a single team's user-managed config bits to clean defaults.
     *
     * Clears every TeamHub-managed bit (CFG_VISIBLE, CFG_OPEN, CFG_INVITE,
     * CFG_REQUEST, CFG_PROTECTED, CFG_ROOT) AND every system bit forbidden on
     * user teams (CFG_SINGLE, CFG_PERSONAL, CFG_SYSTEM, CFG_NO_OWNER,
     * CFG_HIDDEN, CFG_BACKEND). Preserves federation bits set by Circles itself.
     *
     * v4.5.37 — **CFG_APP is no longer cleared.** It left
     * SYSTEM_BITS_FORBIDDEN_ON_USER_TEAMS, so this mask no longer reaches it,
     * and that is deliberate: the bit is another app's claim on the circle
     * (Collectives sets it), and Repair had been quietly removing it. A reset
     * on a wiki-enabled team now leaves the wiki's claim intact.
     *
     * Use cases:
     *   - Admin sees a team with corrupted config and wants a clean slate.
     *   - Integrity check flagged forbidden bits set externally.
     *   - The 3.39.1 one-shot migration's repair fallback.
     *   - Recovering a team whose CFG_PERSONAL bit blocks the Wiki (v4.5.35).
     *
     * @return array{oldConfig: int, newConfig: int}
     */
    public function resetTeamConfig(string $teamId): array {
        $this->requireNcAdmin();

        $cfgQb  = $this->db->getQueryBuilder();
        $cfgRes = $cfgQb->select('config', 'source')
            ->from('circles_circle')
            ->where($cfgQb->expr()->eq('unique_id', $cfgQb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $cfgRow = $cfgRes->fetch();
        $cfgRes->closeCursor();

        if (!$cfgRow) {
            throw new \Exception('Team not found: ' . $teamId);
        }
        if ((int)$cfgRow['source'] !== 16) {
            throw new \Exception('Team is not a user-created team (source != 16) — refusing to reset config: ' . $teamId);
        }

        $oldConfig    = (int)$cfgRow['config'];
        $clearMask    = CirclesConfig::MANAGED_BITS | CirclesConfig::SYSTEM_BITS_FORBIDDEN_ON_USER_TEAMS;
        $newConfig    = $oldConfig & ~$clearMask;

        $updQb = $this->db->getQueryBuilder();
        $updQb->update('circles_circle')
            ->set('config', $updQb->createNamedParameter($newConfig, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->where($updQb->expr()->eq('unique_id', $updQb->createNamedParameter($teamId)))
            ->executeStatement();

        // Bust Circles' APCu cache.
        if (function_exists('apcu_delete') && class_exists('APCUIterator')) {
            try {
                foreach (new \APCUIterator('/^(circles|NC__circles)/') as $item) {
                    apcu_delete($item['key']);
                }
            } catch (\Throwable $e) { /* non-fatal */ }
        }

        $this->logger->info('[TeamHub][MaintenanceService] resetTeamConfig: cleaned config bits', [
            'teamId'    => $teamId,
            'oldConfig' => $oldConfig,
            'newConfig' => $newConfig,
            'cleared'   => $oldConfig & $clearMask,
            'app'       => Application::APP_ID,
        ]);

        // Audit log entry — visible in Manage team → Activity.
        try {
            $auditService = $this->container->get(\OCA\TeamHub\Service\AuditService::class);
            $currentUid   = $this->userSession->getUser() ? $this->userSession->getUser()->getUID() : 'system';
            $auditService->log(
                $teamId,
                'team.config_reset',
                $currentUid,
                'team',
                $teamId,
                ['oldConfig' => $oldConfig, 'newConfig' => $newConfig],
            );
        } catch (\Throwable $e) {
            // Audit failure is non-fatal — the repair already succeeded.
            $this->logger->warning('[TeamHub][MaintenanceService] resetTeamConfig: audit log failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        return ['oldConfig' => $oldConfig, 'newConfig' => $newConfig];
    }

    /**
     * Scan every source=16 user team for config corruption.
     *
     * Two separate findings, and keeping them separate is the point (v4.5.37):
     *
     *   issues     — a system bit that breaks Circles' own config API is set.
     *                Real corruption. Repairable with resetTeamConfig().
     *   appClaimed — another Nextcloud app has flagged the circle as its own
     *                (CFG_APP). Informational. Not a fault, not repairable
     *                here, and deliberately NOT counted as an issue.
     *
     * They used to be one list, which reported twelve healthy teams as corrupt
     * and offered a Repair button that would have stripped Collectives' claim
     * on each of them. See `CirclesConfig::APP_OWNED_BITS`.
     *
     * @return array{issues: array[], appClaimed: array[]}
     */
    public function checkConfigIntegrity(): array {
        $this->requireNcAdmin();

        $forbidden = CirclesConfig::SYSTEM_BITS_FORBIDDEN_ON_USER_TEAMS;
        $appOwned  = CirclesConfig::APP_OWNED_BITS;

        // One pass over both masks — a team can be corrupt *and* app-claimed,
        // and those are independent facts about it.
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('unique_id', 'name', 'config')
            ->from('circles_circle')
            ->where($qb->expr()->eq('source', $qb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere(
                $qb->expr()->gt(
                    $qb->createFunction('(config & ' . ($forbidden | $appOwned) . ')'),
                    $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                )
            )
            ->executeQuery();

        $issues     = [];
        $appClaimed = [];
        while ($row = $res->fetch()) {
            $config  = (int)$row['config'];
            $badBits = $config & $forbidden;
            $appBits = $config & $appOwned;

            if ($badBits > 0) {
                $issues[] = [
                    'id'      => (string)$row['unique_id'],
                    'name'    => (string)($row['name'] ?? ''),
                    'config'  => $config,
                    'badBits' => $badBits,
                ];
            }
            if ($appBits > 0) {
                $appClaimed[] = [
                    'id'      => (string)$row['unique_id'],
                    'name'    => (string)($row['name'] ?? ''),
                    'config'  => $config,
                    'appBits' => $appBits,
                ];
            }
        }
        $res->closeCursor();

        $this->logger->info('[TeamHub][MaintenanceService] checkConfigIntegrity: scan complete', [
            'issuesFound' => count($issues),
            'appClaimed'  => count($appClaimed),
            'app'         => Application::APP_ID,
        ]);

        return ['issues' => $issues, 'appClaimed' => $appClaimed];
    }

    // -------------------------------------------------------------------------
    // Per-user team overview (Audit tab — "Find teams for a user")
    // -------------------------------------------------------------------------

    /**
     * Resolve the NC user's personal "single" circle unique_id, which is what
     * circles_membership keys effective access by. Returns null if the user
     * has no single circle (very rare — usually only system users).
     */
    private function resolveUserSingleId(string $userId): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('m.single_id')
            ->from('circles_member', 'm')
            ->innerJoin('m', 'circles_circle', 'c', $qb->expr()->eq('c.unique_id', 'm.circle_id'))
            ->where($qb->expr()->eq('m.user_id',  $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('m.user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('c.source',    $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $res = $qb->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        return $row && !empty($row['single_id']) ? (string)$row['single_id'] : null;
    }

    /**
     * Return every user-created team the given NC user belongs to, with role,
     * owner, and how membership is established (direct vs. via group/sub-team).
     *
     * Used by the Audit tab "Find teams for a user" panel to support offboarding
     * and role-change workflows. Inherited memberships are returned but flagged
     * as not removable from this UI — admin must remove the user from the source
     * group/team instead.
     *
     * Result shape per row:
     *   teamId, teamName, teamDescription, ownerUid, ownerDisplayName,
     *   role ('Owner'|'Admin'|'Moderator'|'Member'), level (int),
     *   isOwner (bool), source ('direct'|'group'|'team'),
     *   sourceName (string|null — only when source != 'direct'),
     *   removable (bool — true only when direct AND !isOwner)
     *
     * @return list<array<string, mixed>>
     */
    public function listTeamsForUser(string $userId): array {
        $this->requireNcAdmin();

        if ($userId === '') {
            throw new \InvalidArgumentException('userId is required');
        }

        // Verify the user exists in NC so we fail fast with a clear error
        if ($this->userManager->get($userId) === null) {
            throw new \Exception('User not found: ' . $userId);
        }

        $singleId = $this->resolveUserSingleId($userId);
        if ($singleId === null) {
            // No single circle = no effective memberships
            return [];
        }

        // Step 1: all user-teams the user has effective access to.
        // circles_membership.single_id = the user's single_id when they have access
        // to circles_membership.circle_id.
        $qb = $this->db->getQueryBuilder();
        $qb->select(
                'c.unique_id AS team_id',
                'c.name AS team_name',
                'c.display_name AS team_display_name',
                'c.sanitized_name AS team_sanitized_name',
                'c.description AS team_description',
            )
            ->from('circles_membership', 'ms')
            ->innerJoin('ms', 'circles_circle', 'c', $qb->expr()->eq('c.unique_id', 'ms.circle_id'))
            ->where($qb->expr()->eq('ms.single_id', $qb->createNamedParameter($singleId)))
            // source=16 = user-created team. Excludes personal, group, system,
            // mail-share and app circles in one shot.
            ->andWhere($qb->expr()->eq('c.source', $qb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        $res = $qb->executeQuery();
        $teams = [];
        while ($row = $res->fetch()) {
            $teamId = (string)$row['team_id'];
            $teams[$teamId] = [
                'team_id'          => $teamId,
                'name'             => (string)$row['team_name'],
                'display_name'     => (string)($row['team_display_name'] ?? ''),
                'sanitized_name'   => (string)($row['team_sanitized_name'] ?? ''),
                'description'      => (string)($row['team_description'] ?? ''),
            ];
        }
        $res->closeCursor();

        if (empty($teams)) {
            return [];
        }

        $teamIds = array_keys($teams);

        // Step 2: owner per team (level=9, user_type=1)
        $oQb = $this->db->getQueryBuilder();
        $oRes = $oQb->select('circle_id', 'user_id')
            ->from('circles_member')
            ->where($oQb->expr()->in('circle_id', $oQb->createNamedParameter($teamIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($oQb->expr()->eq('level',     $oQb->createNamedParameter(9, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($oQb->expr()->eq('user_type', $oQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($oQb->expr()->eq('status',    $oQb->createNamedParameter('Member')))
            ->executeQuery();
        $owners = [];
        while ($row = $oRes->fetch()) {
            $cid = (string)$row['circle_id'];
            if (!isset($owners[$cid])) {
                $owners[$cid] = (string)$row['user_id'];
            }
        }
        $oRes->closeCursor();

        // Resolve owner display names once
        $ownerUids  = array_unique(array_values($owners));
        $ownerNames = [];
        foreach ($ownerUids as $uid) {
            $u = $this->userManager->get($uid);
            $ownerNames[$uid] = $u !== null ? ($u->getDisplayName() ?: $uid) : $uid;
        }

        // Step 3: direct membership row for THIS user, per team (gives role/level)
        $dQb  = $this->db->getQueryBuilder();
        $dRes = $dQb->select('circle_id', 'level')
            ->from('circles_member')
            ->where($dQb->expr()->in('circle_id', $dQb->createNamedParameter($teamIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($dQb->expr()->eq('user_id',  $dQb->createNamedParameter($userId)))
            ->andWhere($dQb->expr()->eq('user_type', $dQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($dQb->expr()->eq('status',    $dQb->createNamedParameter('Member')))
            ->executeQuery();
        $directLevels = [];
        while ($row = $dRes->fetch()) {
            $directLevels[(string)$row['circle_id']] = (int)$row['level'];
        }
        $dRes->closeCursor();

        // Step 4: for teams where membership is inherited, find the source.
        // For each such team, list group (user_type=2) and sub-team (user_type=16)
        // members of the team and check whether the user is in any of them via
        // circles_membership.
        $inheritedTeamIds = array_values(array_diff($teamIds, array_keys($directLevels)));
        $sourcesPerTeam = []; // teamId => [['kind' => 'group'|'team', 'name' => str], ...]

        if (!empty($inheritedTeamIds)) {
            // Get every group/sub-team member row for these teams
            $sQb  = $this->db->getQueryBuilder();
            $sRes = $sQb->select('m.circle_id', 'm.single_id', 'm.user_id', 'm.user_type',
                                 'c.name', 'c.display_name', 'c.sanitized_name')
                ->from('circles_member', 'm')
                ->leftJoin('m', 'circles_circle', 'c', $sQb->expr()->eq('c.unique_id', 'm.single_id'))
                ->where($sQb->expr()->in('m.circle_id', $sQb->createNamedParameter($inheritedTeamIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($sQb->expr()->in('m.user_type', $sQb->createNamedParameter([2, 16], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($sQb->expr()->eq('m.status',    $sQb->createNamedParameter('Member')))
                ->executeQuery();

            $candidates = []; // teamId => [['single_id' => ..., 'kind' => ..., 'name' => ...], ...]
            $candidateSingleIds = [];
            while ($row = $sRes->fetch()) {
                $tid       = (string)$row['circle_id'];
                $singleIdC = (string)($row['single_id'] ?? '');
                if ($singleIdC === '') {
                    continue;
                }
                $kind = ((int)$row['user_type'] === 2) ? 'group' : 'team';
                // Prefer the sub-circle's resolved display name; for groups,
                // circles_member.user_id holds the human label used by Circles.
                $name = '';
                if (!empty($row['display_name'])) {
                    $name = (string)$row['display_name'];
                } elseif (!empty($row['sanitized_name'])) {
                    $name = (string)$row['sanitized_name'];
                } elseif (!empty($row['user_id'])) {
                    $name = (string)$row['user_id'];
                } elseif (!empty($row['name'])) {
                    $name = (string)$row['name'];
                }
                $candidates[$tid][] = ['single_id' => $singleIdC, 'kind' => $kind, 'name' => $name];
                $candidateSingleIds[$singleIdC] = true;
            }
            $sRes->closeCursor();

            // Which of those sub-circles does the user actually belong to?
            if (!empty($candidateSingleIds)) {
                $cQb  = $this->db->getQueryBuilder();
                $cRes = $cQb->select('circle_id')
                    ->from('circles_membership')
                    ->where($cQb->expr()->eq('single_id', $cQb->createNamedParameter($singleId)))
                    ->andWhere($cQb->expr()->in('circle_id', $cQb->createNamedParameter(array_keys($candidateSingleIds), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
                    ->executeQuery();
                $userInSubCircles = [];
                while ($row = $cRes->fetch()) {
                    $userInSubCircles[(string)$row['circle_id']] = true;
                }
                $cRes->closeCursor();

                foreach ($candidates as $tid => $list) {
                    foreach ($list as $cand) {
                        if (isset($userInSubCircles[$cand['single_id']])) {
                            $sourcesPerTeam[$tid][] = ['kind' => $cand['kind'], 'name' => $cand['name']];
                        }
                    }
                }
            }
        }

        // Step 5: assemble
        $out = [];
        foreach ($teams as $tid => $t) {
            $displayName = $t['display_name'] !== ''
                ? $t['display_name']
                : ($t['sanitized_name'] !== ''
                    ? $t['sanitized_name']
                    : (str_starts_with($t['name'], 'app:circles:')
                        ? substr($t['name'], strlen('app:circles:'))
                        : $t['name']));

            $ownerUid = $owners[$tid] ?? null;
            $isOwner  = $ownerUid !== null && $ownerUid === $userId;

            if (isset($directLevels[$tid])) {
                $level = $directLevels[$tid];
                $role  = match (true) {
                    $level >= 9 => 'Owner',
                    $level >= 8 => 'Admin',
                    $level >= 4 => 'Moderator',
                    default     => 'Member',
                };
                $source     = 'direct';
                $sourceName = null;
                $removable  = !$isOwner;
            } else {
                // Inherited — pick the first identified source (most teams will
                // have exactly one). UI only needs a hint of why the row exists.
                $first = $sourcesPerTeam[$tid][0] ?? null;
                $level = 1; // Member-equivalent for display purposes
                $role  = 'Member';
                if ($first !== null) {
                    $source     = $first['kind']; // 'group' or 'team'
                    $sourceName = $first['name'];
                } else {
                    // Cache says they're in but we couldn't trace the source —
                    // very rare. Mark as unknown-inherited so UI still grays it out.
                    $source     = 'inherited';
                    $sourceName = null;
                }
                $removable  = false;
            }

            $out[] = [
                'teamId'           => $tid,
                'teamName'         => $displayName,
                'teamDescription'  => $t['description'],
                'ownerUid'         => $ownerUid,
                'ownerDisplayName' => $ownerUid !== null ? ($ownerNames[$ownerUid] ?? $ownerUid) : null,
                'role'             => $role,
                'level'            => $level,
                'isOwner'          => $isOwner,
                'source'           => $source,
                'sourceName'       => $sourceName,
                'removable'        => $removable,
            ];
        }

        usort($out, fn ($a, $b) => strcasecmp($a['teamName'], $b['teamName']));

        return $out;
    }

    /**
     * Remove a user's DIRECT membership from a single team, from NC admin context.
     *
     * Differs from MemberService::removeMember in that the caller is gated by
     * NC admin (not team admin/moderator). Refuses to remove the team owner —
     * the admin must reassign ownership in the Maintenance tab first. Refuses
     * to remove inherited memberships — those have no direct row to delete; the
     * user must be removed from the granting group or sub-team instead.
     *
     * Rebuilds the circles_membership cache for the team (so share pickers
     * reflect the change immediately) and writes a teamhub-internal audit event
     * so the action shows up in the per-team audit log.
     *
     * @throws \Exception when the user is the owner, not a direct member, or
     *                    the caller is not an NC admin.
     */
    public function adminRemoveUserFromTeam(string $teamId, string $userId): void {
        $this->requireNcAdmin();

        if ($teamId === '' || $userId === '') {
            throw new \InvalidArgumentException('teamId and userId are required');
        }

        // Look up the direct member row to get level (and verify it exists)
        $mQb  = $this->db->getQueryBuilder();
        $mRes = $mQb->select('level')
            ->from('circles_member')
            ->where($mQb->expr()->eq('circle_id', $mQb->createNamedParameter($teamId)))
            ->andWhere($mQb->expr()->eq('user_id',  $mQb->createNamedParameter($userId)))
            ->andWhere($mQb->expr()->eq('user_type', $mQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $mRow = $mRes->fetch();
        $mRes->closeCursor();

        if (!$mRow) {
            throw new \Exception('User is not a direct member of this team — remove them from the source group or sub-team instead');
        }
        if ((int)$mRow['level'] >= 9) {
            throw new \Exception('Cannot remove the team owner — reassign ownership first in the Maintenance tab');
        }

        // Delete the direct member row
        $delQb = $this->db->getQueryBuilder();
        $delQb->delete('circles_member')
            ->where($delQb->expr()->eq('circle_id', $delQb->createNamedParameter($teamId)))
            ->andWhere($delQb->expr()->eq('user_id',  $delQb->createNamedParameter($userId)))
            ->andWhere($delQb->expr()->eq('user_type', $delQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        // Rebuild the circles_membership cache so share pickers update.
        try {
            $membershipService = $this->container->get(\OCA\Circles\Service\MembershipService::class);
            $membershipService->onUpdate($teamId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MaintenanceService] adminRemoveUserFromTeam: cache rebuild failed', [
                'teamId' => $teamId, 'userId' => $userId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // Audit log — surfaces in the per-team audit log shown elsewhere in this tab.
        $actor = $this->userSession->getUser();
        $this->auditService->log(
            $teamId,
            'member.removed_by_admin',
            $actor !== null ? $actor->getUID() : null,
            'user',
            $userId,
            ['source' => 'admin_audit_panel'],
        );

        $this->logger->info('[TeamHub][MaintenanceService] adminRemoveUserFromTeam: removed', [
            'teamId' => $teamId, 'userId' => $userId, 'app' => Application::APP_ID,
        ]);
    }
}
