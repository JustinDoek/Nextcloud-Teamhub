<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
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
        private MemberService $memberService,
        private ResourceService $resourceService,
        private ActivityService $activityService,
        private TeamAppMapper $teamAppMapper,
        private IUserSession $userSession,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private IUserManager $userManager,
    ) {
        $this->logger->debug('[TeamService] constructed', ['app' => Application::APP_ID]);
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
     * Reads directly from DB — probeCircles() on this instance filters out all
     * circles with non-zero config, so it cannot be used as the team list source.
     */
    public function getUserTeams(): array {
        $this->logger->debug('[TeamService] getUserTeams', ['app' => Application::APP_ID]);

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

            $qb = $db->getQueryBuilder();
            $qb->select('c.unique_id', 'c.name', 'c.description', 'c.config', 'm.level')
               ->from('circles_circle', 'c')
               ->join('c', 'circles_member', 'm', 'c.unique_id = m.circle_id')
               ->where($qb->expr()->eq('m.user_id',   $qb->createNamedParameter($uid)))
               ->andWhere($qb->expr()->eq('m.user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->eq('m.status',  $qb->createNamedParameter('Member')))
               ->orderBy('c.name', 'ASC');

            $result = $qb->executeQuery();
            $teams  = [];

            while ($row = $result->fetch()) {
                $name = $row['name'] ?? '';
                if (str_starts_with($name, 'user:') || str_starts_with($name, 'group:')) {
                    continue;
                }

                $countQb  = $db->getQueryBuilder();
                $countRes = $countQb->select($countQb->func()->count('*', 'cnt'))
                    ->from('circles_member')
                    ->where($countQb->expr()->eq('circle_id', $countQb->createNamedParameter($row['unique_id'])))
                    ->andWhere($countQb->expr()->eq('status', $countQb->createNamedParameter('Member')))
                    ->executeQuery();
                $countRow    = $countRes->fetch();
                $memberCount = $countRow ? (int)$countRow['cnt'] : 0;
                $countRes->closeCursor();

                $teams[] = [
                    'id'          => $row['unique_id'],
                    'name'        => $name,
                    'description' => $row['description'] ?? '',
                    'members'     => $memberCount,
                    'unread'      => $this->hasUnreadMessages($db, $row['unique_id'], $uid),
                ];
            }
            $result->closeCursor();

            $this->logger->debug('[TeamService] getUserTeams result', [
                'uid'   => $uid,
                'count' => count($teams),
                'app'   => Application::APP_ID,
            ]);

            return $teams;

        } catch (\Exception $e) {
            $this->logger->error('[TeamService] Error in getUserTeams', ['exception' => $e, 'app' => Application::APP_ID]);
            return [];
        }
    }

    /**
     * True if the team has at least one message posted after the user last visited it.
     */
    private function hasUnreadMessages(\OCP\IDBConnection $db, string $teamId, string $userId): bool {
        $qb  = $db->getQueryBuilder();
        $res = $qb->select('last_seen_at')
            ->from('teamhub_last_seen')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        $lastSeen = $row ? (int)$row['last_seen_at'] : 0;

        $qb2  = $db->getQueryBuilder();
        $res2 = $qb2->select($qb2->createFunction('MAX(created_at) as latest'))
            ->from('teamhub_messages')
            ->where($qb2->expr()->eq('team_id', $qb2->createNamedParameter($teamId)))
            ->executeQuery();
        $row2  = $res2->fetch();
        $res2->closeCursor();
        $latest = (int)($row2['latest'] ?? 0);

        return $latest > 0 && $latest > $lastSeen;
    }

    /**
     * Get a specific team by ID.
     */
    public function getTeam(string $teamId): array {
        $this->logger->debug('[TeamService] getTeam', ['teamId' => $teamId, 'app' => Application::APP_ID]);

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $circlesManager = $this->getCirclesManager();
        $federatedUser  = $circlesManager->getFederatedUser($user->getUID(), 1);
        $circlesManager->startSession($federatedUser);
        try {
            $circlesManager->getCircle($teamId);
        } catch (\Exception $e) {
            throw new \Exception('Team not found or access denied');
        } finally {
            $circlesManager->stopSession();
        }

        $db  = $this->container->get(\OCP\IDBConnection::class);
        $qb  = $db->getQueryBuilder();
        $res = $qb->select('c.unique_id', 'c.name', 'c.description')
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
        $this->logger->debug('[TeamService] createTeam', ['name' => $name, 'app' => Application::APP_ID]);

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
            $this->logger->info('[TeamService] createTeam done', [
                'teamId' => $result['id'],
                'name'   => $name,
                'app'    => Application::APP_ID,
            ]);
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
        $this->logger->debug('[TeamService] deleteTeam', ['teamId' => $teamId, 'app' => Application::APP_ID]);

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $manager       = $this->getCirclesManager();
        $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
        $manager->startSession($federatedUser);

        try {
            $circle  = $manager->getCircle($teamId);
            $isOwner = false;

            if (method_exists($circle, 'getOwner')) {
                $owner = $circle->getOwner();
                if ($owner && method_exists($owner, 'getUserId') && $owner->getUserId() === $user->getUID()) {
                    $isOwner = true;
                }
            }
            if (!$isOwner && method_exists($circle, 'getMembers')) {
                foreach ($circle->getMembers() as $member) {
                    $mUid  = method_exists($member, 'getUserId') ? $member->getUserId() : null;
                    $level = method_exists($member, 'getLevel')  ? $member->getLevel()  : 0;
                    if ($mUid === $user->getUID() && $level >= 9) {
                        $isOwner = true;
                        break;
                    }
                }
            }
            if (!$isOwner) {
                throw new \Exception('Only the team owner can delete a team.');
            }

            $manager->destroyCircle($teamId);

            try {
                $db = $this->container->get(\OCP\IDBConnection::class);
                $db->executeStatement('DELETE FROM `*PREFIX*teamhub_team_apps` WHERE `team_id` = ?', [$teamId]);
            } catch (\Throwable $e) { /* Not fatal */ }

            $this->logger->info('[TeamService] deleteTeam done', ['teamId' => $teamId, 'app' => Application::APP_ID]);

        } finally {
            $manager->stopSession();
        }
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
        $this->logger->debug('[TeamService] updateTeamDescription', ['teamId' => $teamId, 'app' => Application::APP_ID]);

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $manager       = $this->getCirclesManager();
        $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
        $manager->startSession($federatedUser);

        try {
            $manager->getCircle($teamId);

            $db       = $this->container->get(\OCP\IDBConnection::class);
            $affected = $db->executeStatement(
                'UPDATE `*PREFIX*circles_circle` SET `description` = ? WHERE `unique_id` = ?',
                [$description, $teamId]
            );
            if ($affected === 0) {
                $db->executeStatement(
                    'UPDATE `*PREFIX*circles_circle` SET `long_desc` = ? WHERE `unique_id` = ?',
                    [$description, $teamId]
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('[TeamService] Error updating team description', [
                'teamId'    => $teamId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            throw new \Exception('Failed to update description: ' . $e->getMessage());
        } finally {
            $manager->stopSession();
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
        $this->logger->debug('[TeamService] updateTeamConfig', [
            'teamId'   => $teamId,
            'incoming' => $config,
            'app'      => Application::APP_ID,
        ]);

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $MANAGED_BITS = 1 | 2 | 4 | 16 | 512 | 1024;

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

        $this->logger->info('[TeamService] updateTeamConfig bitmask', [
            'teamId'     => $teamId,
            'before'     => $currentConfig,
            'before_bin' => decbin($currentConfig),
            'incoming'   => $config,
            'after'      => $newConfig,
            'after_bin'  => decbin($newConfig),
            'app'        => Application::APP_ID,
        ]);

        $db->executeStatement(
            'UPDATE `*PREFIX*circles_circle` SET `config` = ? WHERE `unique_id` = ?',
            [$newConfig, $teamId]
        );

        // Flush Circles' in-process object cache
        try {
            $manager       = $this->getCirclesManager();
            $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
            $manager->startSession($federatedUser);
            try {
                $manager->getCircle($teamId);
            } finally {
                $manager->stopSession();
            }
        } catch (\Throwable $e) {
            $this->logger->info('[TeamService] updateTeamConfig: cache flush via getCircle failed (non-fatal): ' . $e->getMessage(), ['app' => Application::APP_ID]);
        }

        // Bust APCu cache
        if (function_exists('apcu_delete') && class_exists('APCUIterator')) {
            try {
                foreach (new \APCUIterator('/^(circles|NC__circles)/') as $item) {
                    apcu_delete($item['key']);
                }
            } catch (\Throwable $e) { /* non-fatal */ }
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
        $this->logger->debug('[TeamService] browseAllTeams', ['app' => Application::APP_ID]);

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        try {
            $db  = $this->container->get(\OCP\IDBConnection::class);
            $uid = $user->getUID();

            $qb = $db->getQueryBuilder();
            $qb->select('c.unique_id', 'c.name', 'c.description', 'c.config')
               ->from('circles_circle', 'c')
               ->orderBy('c.name', 'ASC');
            $result = $qb->executeQuery();

            $memberQb = $db->getQueryBuilder();
            $memRes   = $memberQb->select('circle_id')
                ->from('circles_member')
                ->where($memberQb->expr()->eq('user_id',   $memberQb->createNamedParameter($uid)))
                ->andWhere($memberQb->expr()->eq('user_type', $memberQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($memberQb->expr()->eq('status',  $memberQb->createNamedParameter('Member')))
                ->executeQuery();
            $memberIds = [];
            while ($mRow = $memRes->fetch()) {
                $memberIds[$mRow['circle_id']] = true;
            }
            $memRes->closeCursor();

            $CFG_VISIBLE = 512;
            $teams = [];
            while ($row = $result->fetch()) {
                $name     = $row['name'] ?? '';
                $id       = $row['unique_id'];
                $config   = (int)$row['config'];
                $isMember = isset($memberIds[$id]);

                if (str_starts_with($name, 'user:') || str_starts_with($name, 'group:')) {
                    continue;
                }
                if (!$isMember && !($config & $CFG_VISIBLE)) {
                    continue;
                }

                $teams[] = [
                    'id'          => $id,
                    'name'        => $name,
                    'description' => $row['description'] ?? '',
                    'isMember'    => $isMember,
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
        foreach ($apps as $app) {
            $this->teamAppMapper->upsert($teamId, $app['app_id'], $app['enabled'], $app['config'] ?? null);
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
            'wizardDescription' => $config->getAppValue(Application::APP_ID, 'wizardDescription', ''),
            'inviteTypes'       => $config->getAppValue(Application::APP_ID, 'inviteTypes', 'user,group'),
            'pinMinLevel'       => $config->getAppValue(Application::APP_ID, 'pinMinLevel', 'moderator'),
            'createTeamGroup'   => $rawGroups,           // legacy flat string — keep for canCreateTeam()
            'createTeamGroups'  => $createTeamGroups,   // structured array for the picker
        ];
    }

    /**
     * Save admin settings. Requires server admin.
     * createTeamGroup accepts either a comma-separated string or a JSON array of group IDs.
     */
    public function saveAdminSettings(array $settings): void {
        $this->logger->debug('[TeamService] saveAdminSettings', [
            'keys' => array_keys($settings),
            'app'  => Application::APP_ID,
        ]);

        $config = $this->container->get(\OCP\IConfig::class);

        if (isset($settings['wizardDescription'])) {
            $config->setAppValue(Application::APP_ID, 'wizardDescription', (string)$settings['wizardDescription']);
        }
        if (isset($settings['inviteTypes'])) {
            $allowed = ['user', 'group', 'email', 'federated'];
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
        ];
    }

    private function probeCircles(\OCA\Circles\CirclesManager $manager): array {
        if (!method_exists($manager, 'probeCircles')) {
            return $manager->getCircles();
        }
        $probeResult = $manager->probeCircles();
        if (is_array($probeResult)) {
            return $probeResult;
        }
        if (is_object($probeResult) && method_exists($probeResult, 'getCircles')) {
            return $probeResult->getCircles();
        }
        return [];
    }

    private function findCircle(\OCA\Circles\CirclesManager $manager, string $teamId): mixed {
        $circles = $this->probeCircles($manager);
        foreach ($circles as $circle) {
            $id = method_exists($circle, 'getSingleId') ? $circle->getSingleId() : $circle->getId();
            if ($id === $teamId) {
                return $circle;
            }
        }
        return null;
    }

    // =========================================================================
    // Known dead code — kept for emergency use only (no registered routes)
    // =========================================================================

    public function debugCircleConfig(string $teamId): array {
        $user = $this->userSession->getUser();
        $db   = $this->container->get(\OCP\IDBConnection::class);

        $qb    = $db->getQueryBuilder();
        $res   = $qb->select('config', 'unique_id', 'name', 'description')
            ->from('circles_circle')
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $dbRow = $res->fetch();
        $res->closeCursor();

        $probeInfo = $getCircleInfo = null;
        try {
            $manager       = $this->getCirclesManager();
            $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
            $manager->startSession($federatedUser);
            try {
                $circles = $this->probeCircles($manager);
                foreach ($circles as $c) {
                    $id = method_exists($c, 'getSingleId') ? $c->getSingleId() : $c->getId();
                    if ($id === $teamId) {
                        $probeInfo = [
                            'found'      => true,
                            'config'     => method_exists($c, 'getConfig') ? $c->getConfig() : 'no getConfig()',
                            'config_bin' => method_exists($c, 'getConfig') ? decbin((int)$c->getConfig()) : 'n/a',
                            'name'       => method_exists($c, 'getName') ? $c->getName() : 'n/a',
                        ];
                        break;
                    }
                }
                if ($probeInfo === null) {
                    $probeInfo = ['found' => false, 'total_circles_returned' => count($circles)];
                }
                try {
                    $circle        = $manager->getCircle($teamId);
                    $getCircleInfo = [
                        'found'      => true,
                        'config'     => method_exists($circle, 'getConfig') ? $circle->getConfig() : 'no getConfig()',
                        'config_bin' => method_exists($circle, 'getConfig') ? decbin((int)$circle->getConfig()) : 'n/a',
                    ];
                } catch (\Exception $e) {
                    $getCircleInfo = ['found' => false, 'error' => $e->getMessage()];
                }
            } finally {
                $manager->stopSession();
            }
        } catch (\Exception $e) {
            $probeInfo = ['error' => $e->getMessage()];
        }

        $qb3  = $db->getQueryBuilder();
        $mres = $qb3->select('*')
            ->from('circles_member')
            ->where($qb3->expr()->eq('circle_id', $qb3->createNamedParameter($teamId)))
            ->executeQuery();
        $memberRows = $mres->fetchAll();
        $mres->closeCursor();

        $uid             = $user->getUID();
        $currentUserRows = array_filter($memberRows, fn($r) => ($r['user_id'] ?? '') === $uid);

        return [
            'teamId'               => $teamId,
            'currentUser'          => $uid,
            'db'                   => $dbRow ? ['config' => (int)$dbRow['config'], 'config_bin' => decbin((int)$dbRow['config']), 'name' => $dbRow['name']] : ['found' => false],
            'probeCircles'         => $probeInfo,
            'getCircle'            => $getCircleInfo,
            'db_members'           => array_map(fn($r) => ['user_id' => $r['user_id'] ?? '?', 'user_type' => $r['user_type'] ?? '?', 'level' => $r['level'] ?? '?', 'status' => $r['status'] ?? '?', 'instance' => $r['instance'] ?? '', 'single_id' => $r['single_id'] ?? ''], $memberRows),
            'currentUserInMembers'  => count($currentUserRows) > 0,
            'currentUserMemberRows' => array_values($currentUserRows),
        ];
    }

    public function debugAllCircles(): array {
        $user = $this->userSession->getUser();
        $db   = $this->container->get(\OCP\IDBConnection::class);

        $qb  = $db->getQueryBuilder();
        $res = $qb->select('c.unique_id', 'c.name', 'c.config', 'm.level', 'm.status', 'm.user_type')
            ->from('circles_circle', 'c')
            ->join('c', 'circles_member', 'm', 'c.unique_id = m.circle_id')
            ->where($qb->expr()->eq('m.user_id', $qb->createNamedParameter($user->getUID())))
            ->andWhere($qb->expr()->eq('m.user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        $dbCircles = [];
        while ($row = $res->fetch()) {
            $cfg = (int)$row['config'];
            $dbCircles[$row['unique_id']] = ['name' => $row['name'], 'config' => $cfg, 'config_bin' => decbin($cfg), 'member_level' => $row['level'], 'member_status' => $row['status'], 'in_probe' => false];
        }
        $res->closeCursor();

        $probeIds = [];
        try {
            $manager       = $this->getCirclesManager();
            $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
            $manager->startSession($federatedUser);
            try {
                $circles = $this->probeCircles($manager);
                foreach ($circles as $c) {
                    $id = method_exists($c, 'getSingleId') ? $c->getSingleId() : $c->getId();
                    $probeIds[] = $id;
                    if (isset($dbCircles[$id])) {
                        $dbCircles[$id]['in_probe'] = true;
                    }
                }
            } finally {
                $manager->stopSession();
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        return ['user' => $user->getUID(), 'probe_count' => count($probeIds), 'db_count' => count($dbCircles), 'circles' => array_values(array_map(fn($id, $c) => array_merge(['id' => $id], $c), array_keys($dbCircles), $dbCircles))];
    }

    public function repairCircleMembership(string $teamId): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }
        $uid = $user->getUID();
        $db  = $this->container->get(\OCP\IDBConnection::class);

        $qb  = $db->getQueryBuilder();
        $res = $qb->select('unique_id', 'name', 'config')->from('circles_circle')
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))->setMaxResults(1)->executeQuery();
        $circleRow = $res->fetch();
        $res->closeCursor();
        if (!$circleRow) {
            return ['error' => 'Circle not found in DB'];
        }

        $qb2  = $db->getQueryBuilder();
        $mres = $qb2->select('*')->from('circles_member')
            ->where($qb2->expr()->eq('circle_id', $qb2->createNamedParameter($teamId)))->executeQuery();
        $existingMembers = $mres->fetchAll();
        $mres->closeCursor();

        $myRow = null;
        foreach ($existingMembers as $r) {
            if (($r['user_id'] ?? '') === $uid && (int)($r['user_type'] ?? 0) === 1) {
                $myRow = $r;
                break;
            }
        }

        $action = 'none';
        if ($myRow === null) {
            $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $singleId = '';
            for ($i = 0; $i < 21; $i++) {
                $singleId .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $repairCols   = array_flip($this->resourceService->getTableColumns('circles_member'));
            $qbI = $db->getQueryBuilder();
            $repairValues = [
                'single_id' => $qbI->createNamedParameter($singleId),
                'circle_id' => $qbI->createNamedParameter($teamId),
                'user_id'   => $qbI->createNamedParameter($uid),
                'user_type' => $qbI->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                'member_id' => $qbI->createNamedParameter($uid),
                'instance'  => $qbI->createNamedParameter(''),
                'level'     => $qbI->createNamedParameter(9, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                'status'    => $qbI->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                'joined'    => $qbI->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
            ];
            foreach (['display_name' => $user->getDisplayName() ?: $uid, 'cached_name' => $user->getDisplayName() ?: $uid, 'note' => '', 'contact_id' => '', 'contact_meta' => ''] as $col => $val) {
                if (isset($repairCols[$col])) {
                    $repairValues[$col] = $qbI->createNamedParameter($val);
                }
            }
            $qbI->insert('circles_member')->values($repairValues)->executeStatement();
            $action = 'inserted_owner_row';
        } elseif ((int)($myRow['level'] ?? 0) < 9 || (int)($myRow['status'] ?? 0) !== 1) {
            $db->executeStatement('UPDATE `*PREFIX*circles_member` SET `level` = 9, `status` = 1 WHERE `circle_id` = ? AND `user_id` = ? AND `user_type` = 1', [$teamId, $uid]);
            $action = 'repaired_existing_row (was level=' . $myRow['level'] . ' status=' . $myRow['status'] . ')';
        } else {
            $action = 'row_ok_level=' . $myRow['level'] . '_status=' . $myRow['status'] . ' — circle may be broken for another reason';
        }

        if (function_exists('apcu_delete') && class_exists('APCUIterator')) {
            try {
                foreach (new \APCUIterator('/^(circles|NC__circles)/') as $item) {
                    apcu_delete($item['key']);
                }
            } catch (\Throwable $e) {}
        }

        $circlesVisible = false;
        try {
            $manager = $this->getCirclesManager();
            $manager->startSession($manager->getFederatedUser($uid, 1));
            try {
                $circlesVisible = $manager->getCircle($teamId) !== null;
            } finally {
                $manager->stopSession();
            }
        } catch (\Throwable $e) {}

        return ['action' => $action, 'circlesVisible' => $circlesVisible];
    }

    public function debugActivity(string $teamId): array {
        $db        = $this->container->get(\OCP\IDBConnection::class);
        $resources = $this->resourceService->getTeamResources($teamId);

        $resolved = ['circle_id' => $teamId, 'folder_id' => $resources['files']['folder_id'] ?? null, 'folder_path' => $resources['files']['path'] ?? null, 'deck_board' => $resources['deck']['board_id'] ?? null, 'calendar_id' => $resources['calendar']['id'] ?? null, 'talk_token' => $resources['talk']['token'] ?? null];

        $shareQb  = $db->getQueryBuilder();
        $shareRes = $shareQb->select('id', 'share_type', 'share_with', 'item_type', 'item_source', 'file_source', 'file_target', 'uid_owner')
            ->from('share')->where($shareQb->expr()->eq('share_with', $shareQb->createNamedParameter($teamId)))->setMaxResults(10)->executeQuery();
        $shareRows = $shareRes->fetchAll();
        $shareRes->closeCursor();

        $qb  = $db->getQueryBuilder();
        $res = $qb->select('activity_id', 'app', 'type', 'user', 'object_type', 'object_id', 'file', 'subject', 'timestamp')
            ->from('activity')
            ->where($qb->expr()->orX($qb->expr()->eq('app', $qb->createNamedParameter('files')), $qb->expr()->eq('app', $qb->createNamedParameter('files_sharing')), $qb->expr()->eq('app', $qb->createNamedParameter('deck')), $qb->expr()->eq('app', $qb->createNamedParameter('calendar')), $qb->expr()->eq('app', $qb->createNamedParameter('dav')), $qb->expr()->eq('app', $qb->createNamedParameter('spreed')), $qb->expr()->eq('app', $qb->createNamedParameter('circles'))))
            ->orderBy('timestamp', 'DESC')->setMaxResults(30)->executeQuery();
        $recentRows = $res->fetchAll();
        $res->closeCursor();

        $folderInfo = null;
        if (!empty($resources['files']['folder_id'])) {
            $fcQb  = $db->getQueryBuilder();
            $fcRes = $fcQb->select('fileid', 'path', 'name', 'parent')->from('filecache')
                ->where($fcQb->expr()->eq('fileid', $fcQb->createNamedParameter((int)$resources['files']['folder_id'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->setMaxResults(1)->executeQuery();
            $folderInfo = $fcRes->fetch() ?: null;
            $fcRes->closeCursor();
        }

        return ['resolved_resources' => $resolved, 'share_rows_for_team' => $shareRows, 'folder_in_filecache' => $folderInfo, 'recent_activity_rows' => array_map(fn($r) => ['activity_id' => $r['activity_id'], 'app' => $r['app'], 'type' => $r['type'], 'user' => $r['user'], 'object_type' => $r['object_type'], 'object_id' => $r['object_id'], 'file' => $r['file'], 'subject' => substr($r['subject'] ?? '', 0, 80), 'timestamp' => date('Y-m-d H:i:s', (int)$r['timestamp'])], $recentRows)];
    }

    public function debugResourceTables(): array {
        $tables = ['talk_rooms', 'talk_attendees', 'deck_boards', 'deck_stacks', 'deck_board_acl', 'circles_circle', 'circles_member'];
        $result = [];
        foreach ($tables as $table) {
            try {
                $cols           = $this->resourceService->getTableColumns($table);
                $result[$table] = !empty($cols) ? $cols : ['(no columns found — table may not exist)'];
            } catch (\Exception $e) {
                $result[$table] = ['error' => $e->getMessage()];
            }
        }
        return $result;
    }

    public function debugCirclesMethods(): array {
        $manager        = $this->getCirclesManager();
        $managerMethods = get_class_methods($manager);
        sort($managerMethods);

        $user          = $this->userSession->getUser();
        $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
        $manager->startSession($federatedUser);

        $circleMethods = [];
        $circleClass   = null;
        try {
            $circle        = $manager->createCircle('__debug_delete_me__');
            $circleClass   = get_class($circle);
            $circleMethods = get_class_methods($circle);
            sort($circleMethods);
            try { $manager->destroyCircle($circle->getSingleId()); } catch (\Exception $e) {}
        } catch (\Exception $e) {
            $circleMethods = ['error: ' . $e->getMessage()];
        } finally {
            $manager->stopSession();
        }

        return ['circlesManagerClass' => get_class($manager), 'circlesManagerMethods' => $managerMethods, 'circleClass' => $circleClass, 'circleMethods' => $circleMethods];
    }
}
