<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * GroupFolderService — integration layer between TeamHub and the Group Folders app.
 *
 * All GroupFolders interactions go through this service. FolderManager is resolved
 * lazily from the container (never constructor-injected) because it is an OCA class
 * whose constructor signature can change between GroupFolders releases. Failures to
 * resolve it are treated as "GroupFolders unavailable" and fall back gracefully.
 *
 * Design references: DESIGN.md §2.18, §2.19, §2.32
 */
class GroupFolderService {

    /** Cached FolderManager instance (null = not yet attempted or unavailable). */
    private mixed $folderManager = null;

    /** Whether we have already tried (and failed) to resolve FolderManager. */
    private bool $folderManagerResolutionAttempted = false;

    public function __construct(
        private IAppManager       $appManager,
        private IDBConnection     $db,
        private IConfig           $config,
        private ContainerInterface $container,
        private LoggerInterface   $logger,
    ) {}

    // ──────────────────────────────────────────────────────────────────────────
    // Availability checks
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Returns true if GroupFolders is installed AND FolderManager is resolvable.
     */
    public function isGroupFoldersAvailable(): bool {
        if (!$this->appManager->isInstalled('groupfolders')) {
            $this->logger->debug('[TeamHub][GroupFolderService] isGroupFoldersAvailable — app not installed', [
                'app' => Application::APP_ID,
            ]);
            return false;
        }
        $result = $this->getFolderManager() !== null;
        $this->logger->debug('[TeamHub][GroupFolderService] isGroupFoldersAvailable result', [
            'result' => $result, 'app' => Application::APP_ID,
        ]);
        return $result;
    }

    /**
     * Returns GroupFolders availability and team-creator group configuration status.
     *
     * Returns an array:
     *   - 'groupFoldersInstalled' bool
     *   - 'teamCreatorGroupsConfigured' bool  (at least one group set)
     *
     * Note: FolderManager::createFolder() has no auth check — it writes directly to
     * the DB. Authorization is only enforced at the HTTP controller level, which
     * TeamHub bypasses by calling FolderManager directly from PHP. Delegation
     * rights are therefore not a prerequisite for GroupFolders to work with TeamHub.
     */
    public function getDelegationStatus(): array {
        $installed = $this->appManager->isInstalled('groupfolders');

        $rawGroups = $this->config->getAppValue(Application::APP_ID, 'createTeamGroup', '');
        $configuredGroups = array_filter(array_map('trim', explode(',', $rawGroups)));

        return [
            'groupFoldersInstalled'       => $installed,
            'teamCreatorGroupsConfigured' => !empty($configuredGroups),
        ];
    }

        // ──────────────────────────────────────────────────────────────────────────
    // Folder lifecycle
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Create a new Group Folder with the given mount point name.
     * Returns the integer folder ID on success.
     *
     * @throws \RuntimeException if GroupFolders is unavailable or creation fails.
     */
    public function createGroupFolder(string $mountPoint): int {
        $fm = $this->requireFolderManager();

        $this->logger->debug('[TeamHub][GroupFolderService] createGroupFolder', [
            'mountPoint' => $mountPoint, 'app' => Application::APP_ID,
        ]);

        $folderId = $fm->createFolder($mountPoint);

        $this->logger->info('[TeamHub][GroupFolderService] group folder created', [
            'mountPoint' => $mountPoint, 'folderId' => $folderId, 'app' => Application::APP_ID,
        ]);

        return (int) $folderId;
    }

    /**
     * Assign a team's circle to a Group Folder.
     * FolderManager::addApplicableGroup detects circles by their single_id automatically.
     *
     * @param int    $folderId        GroupFolders folder ID
     * @param string $circleUniqueId  Team circle's unique_id / single_id
     */
    public function assignCircleToFolder(int $folderId, string $circleUniqueId): void {
        $fm = $this->requireFolderManager();

        $this->logger->debug('[TeamHub][GroupFolderService] assignCircleToFolder', [
            'folderId' => $folderId, 'circleUniqueId' => $circleUniqueId, 'app' => Application::APP_ID,
        ]);

        $fm->addApplicableGroup($folderId, $circleUniqueId);

        $this->logger->info('[TeamHub][GroupFolderService] circle assigned to group folder', [
            'folderId' => $folderId, 'circleUniqueId' => $circleUniqueId, 'app' => Application::APP_ID,
        ]);
    }

    /**
     * Remove a team's circle from a Group Folder.
     */
    public function removeCircleFromFolder(int $folderId, string $circleUniqueId): void {
        $fm = $this->requireFolderManager();

        $this->logger->debug('[TeamHub][GroupFolderService] removeCircleFromFolder', [
            'folderId' => $folderId, 'circleUniqueId' => $circleUniqueId, 'app' => Application::APP_ID,
        ]);

        $fm->removeApplicableGroup($folderId, $circleUniqueId);

        $this->logger->info('[TeamHub][GroupFolderService] circle removed from group folder', [
            'folderId' => $folderId, 'circleUniqueId' => $circleUniqueId, 'app' => Application::APP_ID,
        ]);
    }

    /**
     * Permanently delete a Group Folder and all its contents.
     *
     * @param int $folderId GroupFolders folder ID
     */
    public function deleteGroupFolder(int $folderId): void {
        $fm = $this->requireFolderManager();

        $this->logger->debug('[TeamHub][GroupFolderService] deleteGroupFolder', [
            'folderId' => $folderId, 'app' => Application::APP_ID,
        ]);

        $fm->removeFolder($folderId);

        $this->logger->info('[TeamHub][GroupFolderService] group folder deleted', [
            'folderId' => $folderId, 'app' => Application::APP_ID,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Discovery queries
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Find any Group Folder currently assigned to the given circle.
     * Queries group_folders + group_folders_groups directly via QB (no FolderManager needed).
     *
     * Returns null if none found, or:
     *   [ 'folder_id' => int, 'mount_point' => string ]
     */
    public function findGroupFolderForCircle(string $circleUniqueId): ?array {
        if (!$this->appManager->isInstalled('groupfolders')) {
            return null;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('gf.folder_id', 'gf.mount_point')
                ->from('group_folders', 'gf')
                ->innerJoin('gf', 'group_folders_groups', 'gfg',
                    $qb->expr()->eq('gf.folder_id', 'gfg.folder_id')
                )
                ->where($qb->expr()->eq(
                    'gfg.circle_id',
                    $qb->createNamedParameter($circleUniqueId)
                ))
                ->setMaxResults(1);

            $r = $qb->executeQuery();
            $row = $r->fetch();
            $r->closeCursor();

            if ($row === false) {
                return null;
            }

            $this->logger->debug('[TeamHub][GroupFolderService] findGroupFolderForCircle result', [
                'circleUniqueId' => $circleUniqueId,
                'folderId'       => $row['folder_id'],
                'mountPoint'     => $row['mount_point'],
                'app'            => Application::APP_ID,
            ]);

            return [
                'folder_id'   => (int) $row['folder_id'],
                'mount_point' => (string) $row['mount_point'],
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][GroupFolderService] findGroupFolderForCircle failed', [
                'circleUniqueId' => $circleUniqueId,
                'error'          => $e->getMessage(),
                'app'            => Application::APP_ID,
            ]);
            return null;
        }
    }

    /**
     * Return all Group Folder IDs (as strings) assigned to the given circle.
     * Used by ResourceDiscoveryService to get the full live set.
     *
     * resource_id for a group-folder-backed files resource is the GroupFolders
     * folder ID prefixed with 'gf:' to distinguish it from a share-based file_source.
     * e.g. 'gf:42'
     *
     * @return string[]
     */
    public function getRealGroupFolderResourceIds(string $circleUniqueId): array {
        if (!$this->appManager->isInstalled('groupfolders')) {
            return [];
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('gfg.folder_id')
                ->from('group_folders_groups', 'gfg')
                ->where($qb->expr()->eq(
                    'gfg.circle_id',
                    $qb->createNamedParameter($circleUniqueId)
                ));

            $r = $qb->executeQuery();
            $ids = [];
            while ($row = $r->fetch()) {
                $ids[] = 'gf:' . (int) $row['folder_id'];
            }
            $r->closeCursor();

            $this->logger->debug('[TeamHub][GroupFolderService] getRealGroupFolderResourceIds', [
                'circleUniqueId' => $circleUniqueId, 'count' => count($ids), 'app' => Application::APP_ID,
            ]);

            return $ids;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][GroupFolderService] getRealGroupFolderResourceIds failed', [
                'circleUniqueId' => $circleUniqueId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Resolve a 'gf:{id}' resource_id to folder metadata.
     * Returns null if the folder no longer exists.
     *
     * @return array{folder_id: int, mount_point: string}|null
     */
    public function resolveGroupFolderResourceId(string $resourceId): ?array {
        if (!str_starts_with($resourceId, 'gf:')) {
            return null;
        }

        $folderId = (int) substr($resourceId, 3);
        if ($folderId <= 0) {
            return null;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('folder_id', 'mount_point')
                ->from('group_folders')
                ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1);

            $r = $qb->executeQuery();
            $row = $r->fetch();
            $r->closeCursor();

            if ($row === false) {
                return null;
            }

            return [
                'folder_id'   => (int) $row['folder_id'],
                'mount_point' => (string) $row['mount_point'],
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][GroupFolderService] resolveGroupFolderResourceId failed', [
                'resourceId' => $resourceId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Lazily resolve OCA\GroupFolders\Folder\FolderManager from the container.
     * Returns null if GroupFolders is not installed or FolderManager cannot be resolved.
     */
    private function getFolderManager(): mixed {
        if ($this->folderManagerResolutionAttempted) {
            return $this->folderManager;
        }

        $this->folderManagerResolutionAttempted = true;

        if (!$this->appManager->isInstalled('groupfolders')) {
            return null;
        }

        try {
            $this->folderManager = $this->container->get('OCA\\GroupFolders\\Folder\\FolderManager');
            $this->logger->info('[TeamHub][GroupFolderService] FolderManager resolved OK', [
                'class' => get_class($this->folderManager), 'app' => Application::APP_ID,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][GroupFolderService] FolderManager resolution failed — GroupFolders unavailable', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            $this->folderManager = null;
        }

        return $this->folderManager;
    }

    /**
     * Like getFolderManager() but throws if unavailable.
     *
     * @throws \RuntimeException
     */
    private function requireFolderManager(): mixed {
        $fm = $this->getFolderManager();
        if ($fm === null) {
            throw new \RuntimeException('Group Folders app is not available or FolderManager could not be resolved.');
        }
        return $fm;
    }
}
