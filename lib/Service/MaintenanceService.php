<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
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
        private IDBConnection    $db,
        private IUserSession     $userSession,
        private IUserManager     $userManager,
        private ContainerInterface $container,
        private LoggerInterface  $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Orphaned teams
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
     * Delete an orphaned team — destroys the circle and removes all TeamHub data.
     * Does NOT require the caller to be the team owner (admin-only operation).
     *
     * Mimics deleteTeam() in TeamService but without the ownership check.
     */
    public function deleteOrphanedTeam(string $teamId): void {

        $this->requireNcAdmin();

        try {
            $appManager = $this->container->get(\OCP\App\IAppManager::class);
            if ($appManager->isInstalled('circles')) {
                $circlesManager = $this->container->get(\OCA\Circles\CirclesManager::class);

                // Use the app user (no session needed for admin maintenance)
                $appUser = $this->userSession->getUser();
                if ($appUser) {
                    try {
                        $federatedUser = $circlesManager->getFederatedUser($appUser->getUID(), 1);
                        $circlesManager->startSession($federatedUser);
                        try {
                            $circlesManager->destroyCircle($teamId);
                        } finally {
                            $circlesManager->stopSession();
                        }
                    } catch (\Throwable $e) {
                        // Circle may already be gone — fall through to TeamHub cleanup
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        // Always clean up TeamHub data regardless of Circles result
        $this->deleteTeamHubData($teamId);

    }

    /**
     * Assign a new owner to an orphaned team.
     *
     * Strategy:
     * 1. Use the Circles API (addMember) to properly register the user as a member
     *    so the full Circles membership model is consistent (single_id, federation, etc.).
     * 2. Promote to level 9 (owner) via direct DB UPDATE — same pattern used by
     *    MemberService::updateMemberLevel().
     * 3. Set status to 'Member' to ensure the row is active.
     *
     * If the user is already a member (addMember throws), skip straight to promotion.
     */
    public function assignOwner(string $teamId, string $userId): void {

        $this->requireNcAdmin();

        // Validate the user exists
        $user = $this->userManager->get($userId);
        if (!$user) {
            throw new \Exception('User not found: ' . $userId);
        }

        // Step 1: Clean up any previously raw-inserted member row for this user
        // so that addMember() can create a fully consistent Circles membership.
        $cleanQb = $this->db->getQueryBuilder();
        $deleted  = $cleanQb->delete('circles_member')
            ->where($cleanQb->expr()->eq('circle_id',  $cleanQb->createNamedParameter($teamId)))
            ->andWhere($cleanQb->expr()->eq('user_id',   $cleanQb->createNamedParameter($userId)))
            ->andWhere($cleanQb->expr()->eq('user_type', $cleanQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        // Step 2: Add via Circles API so membership is fully consistent.
        // We start a Circles session as the admin user (who is also the current session user).
        $appManager = $this->container->get(\OCP\App\IAppManager::class);
        if (!$appManager->isInstalled('circles')) {
            throw new \Exception('Circles app is not installed');
        }

        $circlesManager = $this->container->get(\OCA\Circles\CirclesManager::class);
        $adminUser      = $this->userSession->getUser();
        if (!$adminUser) {
            throw new \Exception('No authenticated session');
        }

        try {
            $adminFederated = $circlesManager->getFederatedUser($adminUser->getUID(), 1);
            $circlesManager->startSession($adminFederated);

            try {
                $targetFederated = $circlesManager->getFederatedUser($userId, 1);
                $circlesManager->addMember($teamId, $targetFederated);
            } catch (\Throwable $e) {
                // User may already be a member — that is fine, proceed to promotion.
            } finally {
                $circlesManager->stopSession();
            }
        } catch (\Throwable $e) {
            // Circles session setup failed — log and continue to DB promotion.
        }

        // Step 3: Promote to owner (level 9) via direct DB UPDATE.
        // This is safe — it is the same approach used throughout MemberService.
        $updateQb = $this->db->getQueryBuilder();
        $affected  = $updateQb->update('circles_member')
            ->set('level',  $updateQb->createNamedParameter(9, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->set('status', $updateQb->createNamedParameter('Member'))
            ->where($updateQb->expr()->eq('circle_id',  $updateQb->createNamedParameter($teamId)))
            ->andWhere($updateQb->expr()->eq('user_id',   $updateQb->createNamedParameter($userId)))
            ->andWhere($updateQb->expr()->eq('user_type', $updateQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->executeStatement();


        if ($affected === 0) {
            throw new \Exception('Failed to promote user to owner — member row not found after addMember(). Check Circles logs.');
        }

        // Step 4: Reset the circle config bitmask to 0.
        // A non-zero config causes CirclesManager::probeCircles() to silently drop
        // the circle, making it invisible to TeamHub even after ownership is restored.
        // Resetting to 0 ensures the circle behaves as a standard visible team.
        $configQb = $this->db->getQueryBuilder();
        $configQb->update('circles_circle')
            ->set('config', $configQb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->where($configQb->expr()->eq('unique_id', $configQb->createNamedParameter($teamId)))
            ->executeStatement();

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
}
