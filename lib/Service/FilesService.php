<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\ITagManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * FilesService — shared folder creation and deletion for TeamHub teams.
 *
 * Extracted from ResourceService in v3.2.0.
 */
class FilesService {

    public function __construct(
        private IUserSession $userSession,
        private ContainerInterface $container,
        private ITagManager $tagManager,
        private LoggerInterface $logger,
        private IConfig $config,
    ) {}

    // -------------------------------------------------------------------------
    // Files widget data
    // -------------------------------------------------------------------------

    public function getFavoriteFiles(int $folderId, string $uid): array {
        $this->logger->debug('[TeamHub][FilesService] getFavoriteFiles — start', [
            'folderId' => $folderId, 'uid' => $uid, 'app' => Application::APP_ID,
        ]);

        try {
            $rootFolder = $this->container->get(IRootFolder::class);
            $userFolder = $rootFolder->getUserFolder($uid);

            $teamFolderNodes = $userFolder->getById($folderId);
            if (empty($teamFolderNodes)) {
                $this->logger->warning('[TeamHub][FilesService] getFavoriteFiles — team folder not found', [
                    'folderId' => $folderId, 'uid' => $uid, 'app' => Application::APP_ID,
                ]);
                return [];
            }
            $teamFolder     = $teamFolderNodes[0];
            $teamFolderPath = rtrim($teamFolder->getPath(), '/') . '/';

            $tagger      = $this->tagManager->load('files', [], false, $uid);
            $favoriteIds = $tagger->getFavorites();

            $this->logger->debug('[TeamHub][FilesService] getFavoriteFiles — user has ' . count($favoriteIds) . ' favourites', [
                'uid' => $uid, 'app' => Application::APP_ID,
            ]);

            if (empty($favoriteIds)) {
                return [];
            }

            $results = [];
            foreach ($favoriteIds as $fileId) {
                try {
                    $nodes = $userFolder->getById((int)$fileId);
                    if (empty($nodes)) {
                        continue;
                    }
                    $node = $nodes[0];
                    if ($node->getType() !== \OCP\Files\FileInfo::TYPE_FILE) {
                        continue;
                    }
                    $nodePath = $node->getPath();
                    if (strncmp($nodePath, $teamFolderPath, strlen($teamFolderPath)) !== 0) {
                        continue;
                    }
                    $results[] = $this->nodeToArray($node, $teamFolderPath);
                } catch (\Throwable $e) {
                    $this->logger->debug('[TeamHub][FilesService] getFavoriteFiles — skipping node ' . $fileId, [
                        'error' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }
            }

            $this->logger->debug('[TeamHub][FilesService] getFavoriteFiles — returning ' . count($results) . ' files', [
                'uid' => $uid, 'app' => Application::APP_ID,
            ]);
            return $results;

        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][FilesService] getFavoriteFiles failed', [
                'folderId' => $folderId, 'uid' => $uid,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    public function getRecentFiles(int $folderId, string $uid, int $limit = 5): array {
        $this->logger->debug('[TeamHub][FilesService] getRecentFiles — start', [
            'folderId' => $folderId, 'uid' => $uid, 'limit' => $limit, 'app' => Application::APP_ID,
        ]);

        try {
            $rootFolder = $this->container->get(IRootFolder::class);
            $userFolder = $rootFolder->getUserFolder($uid);

            $teamFolderNodes = $userFolder->getById($folderId);
            if (empty($teamFolderNodes)) {
                $this->logger->warning('[TeamHub][FilesService] getRecentFiles — team folder not found', [
                    'folderId' => $folderId, 'uid' => $uid, 'app' => Application::APP_ID,
                ]);
                return [];
            }
            $teamFolder     = $teamFolderNodes[0];
            $teamFolderPath = rtrim($teamFolder->getPath(), '/') . '/';

            $allFiles = [];
            $this->collectFiles($teamFolder, $allFiles);

            $this->logger->debug('[TeamHub][FilesService] getRecentFiles — collected ' . count($allFiles) . ' files', [
                'uid' => $uid, 'app' => Application::APP_ID,
            ]);

            usort($allFiles, static function (Node $a, Node $b): int {
                return $b->getMTime() <=> $a->getMTime();
            });

            $results = [];
            foreach (array_slice($allFiles, 0, $limit) as $node) {
                $results[] = $this->nodeToArray($node, $teamFolderPath);
            }

            $this->logger->debug('[TeamHub][FilesService] getRecentFiles — returning ' . count($results) . ' files', [
                'uid' => $uid, 'app' => Application::APP_ID,
            ]);
            return $results;

        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][FilesService] getRecentFiles failed', [
                'folderId' => $folderId, 'uid' => $uid,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Return files and folders shared directly with the team circle,
     * excluding the team folder share itself, paginated (newest first).
     *
     * Note: oc_share has no mimetype column — mimetype is resolved via the file node.
     *
     * @param string   $teamId        Circle single ID
     * @param string   $uid           Current user UID (kept for API consistency)
     * @param int|null $teamFolderId  file_source of the team folder share to exclude
     * @param int      $limit         Items per page (max 50)
     * @param int      $offset        Pagination offset
     * @return array{ items: array, total: int }
     */
    public function getSharedWithTeam(
        string $teamId,
        string $uid,
        ?int $teamFolderId,
        int $limit = 10,
        int $offset = 0
    ): array {
        $this->logger->debug('[TeamHub][FilesService] getSharedWithTeam — start', [
            'teamId'       => $teamId,
            'teamFolderId' => $teamFolderId,
            'limit'        => $limit,
            'offset'       => $offset,
            'app'          => Application::APP_ID,
        ]);

        try {
            $db = $this->container->get(\OCP\IDBConnection::class);

            $buildBase = function () use ($db, $teamId, $teamFolderId) {
                $qb = $db->getQueryBuilder();
                $qb->from('share', 's')
                    ->where($qb->expr()->eq('s.share_with', $qb->createNamedParameter($teamId)))
                    ->andWhere($qb->expr()->eq('s.share_type', $qb->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->in(
                        's.item_type',
                        $qb->createNamedParameter(['file', 'folder'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)
                    ));

                if ($teamFolderId !== null) {
                    $qb->andWhere($qb->expr()->neq(
                        's.file_source',
                        $qb->createNamedParameter($teamFolderId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                    ));
                }
                return $qb;
            };

            $countQb  = $buildBase();
            $countQb->select($countQb->func()->count('s.id', 'total'));
            $countRes = $countQb->executeQuery();
            $total    = (int)($countRes->fetchOne() ?? 0);
            $countRes->closeCursor();

            $this->logger->debug('[TeamHub][FilesService] getSharedWithTeam — total: ' . $total, [
                'app' => Application::APP_ID,
            ]);

            if ($total === 0) {
                return ['items' => [], 'total' => 0];
            }

            $dataQb = $buildBase();
            $dataQb->select('s.id', 's.file_source', 's.file_target', 's.item_type', 's.uid_initiator', 's.stime')
                   ->orderBy('s.stime', 'DESC')
                   ->setMaxResults($limit)
                   ->setFirstResult($offset);

            $dataRes = $dataQb->executeQuery();
            $rows    = $dataRes->fetchAll();
            $dataRes->closeCursor();

            $this->logger->debug('[TeamHub][FilesService] getSharedWithTeam — fetched ' . count($rows) . ' rows', [
                'app' => Application::APP_ID,
            ]);

            $userManager = $this->container->get(\OCP\IUserManager::class);
            $rootFolder  = $this->container->get(IRootFolder::class);

            $items = [];
            foreach ($rows as $row) {
                $fileId    = (int)$row['file_source'];
                $sharerUid = (string)$row['uid_initiator'];
                $itemType  = (string)$row['item_type'];
                $sharedAt  = (int)$row['stime'];

                $sharerUser        = $userManager->get($sharerUid);
                $sharerDisplayName = $sharerUser ? $sharerUser->getDisplayName() : $sharerUid;

                $name      = '';
                $mimetype  = '';
                $extension = '';
                try {
                    $userFolder = $rootFolder->getUserFolder($sharerUid);
                    $nodes      = $userFolder->getById($fileId);
                    if (!empty($nodes)) {
                        $node      = $nodes[0];
                        $name      = $node->getName();
                        $mimetype  = $node->getMimetype();
                        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    }
                } catch (\Throwable $e) {
                    $this->logger->debug('[TeamHub][FilesService] getSharedWithTeam — could not resolve node ' . $fileId, [
                        'error' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }

                if ($name === '' && !empty($row['file_target'])) {
                    $name = basename((string)$row['file_target']);
                }

                $items[] = [
                    'id'           => $fileId,
                    'name'         => $name,
                    'item_type'    => $itemType,
                    'mimetype'     => $mimetype,
                    'extension'    => $extension,
                    'shared_by'    => $sharerDisplayName,
                    'shared_by_id' => $sharerUid,
                    'shared_at'    => $sharedAt,
                ];
            }

            $this->logger->debug('[TeamHub][FilesService] getSharedWithTeam — returning ' . count($items) . ' items', [
                'app' => Application::APP_ID,
            ]);

            return ['items' => $items, 'total' => $total];

        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][FilesService] getSharedWithTeam failed', [
                'teamId' => $teamId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            return ['items' => [], 'total' => 0];
        }
    }

    // -------------------------------------------------------------------------
    // Shared folder management
    // -------------------------------------------------------------------------

    public function createSharedFolder(string $teamId, string $teamName, string $uid): array {

        $user = $this->container->get(\OCP\IUserManager::class)->get($uid);
        if (!$user) {
            throw new \Exception('User not found: ' . $uid);
        }

        try {
            $federatedUserService = $this->container->get(\OCA\Circles\Service\FederatedUserService::class);
            $federatedUserService->setLocalCurrentUser($user);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][FilesService] createSharedFolder — Circles session bootstrap failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        $userFolder = $this->container->get(IRootFolder::class)->getUserFolder($uid);

        // Respect NC's 'share_folder' config.php setting (used by AIO and others).
        // When set, new shares land inside that path rather than the user root.
        // Example: 'share_folder' => '/Shared' means we create inside /Shared/.
        // Fall back to the user root when the setting is absent or the folder
        // doesn't exist (rather than fail silently or create it unexpectedly).
        $shareFolder = trim($this->config->getSystemValue('share_folder', ''), '/');
        $targetFolder = $userFolder;
        if ($shareFolder !== '') {
            try {
                if ($userFolder->nodeExists($shareFolder)) {
                    $node = $userFolder->get($shareFolder);
                    if ($node instanceof \OCP\Files\Folder) {
                        $targetFolder = $node;
                        $this->logger->debug('[TeamHub][FilesService] createSharedFolder — using share_folder path', [
                            'path' => $shareFolder, 'uid' => $uid, 'app' => Application::APP_ID,
                        ]);
                    } else {
                        $this->logger->warning('[TeamHub][FilesService] createSharedFolder — share_folder path is not a folder, falling back to root', [
                            'path' => $shareFolder, 'uid' => $uid, 'app' => Application::APP_ID,
                        ]);
                    }
                } else {
                    $this->logger->warning('[TeamHub][FilesService] createSharedFolder — share_folder path does not exist, falling back to root', [
                        'path' => $shareFolder, 'uid' => $uid, 'app' => Application::APP_ID,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][FilesService] createSharedFolder — share_folder resolution failed, falling back to root', [
                    'path' => $shareFolder, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        // v4.3.19 — defensive sanitisation as belt-and-braces on top of
        // TeamService::assertValidTeamName. If a legacy team from before
        // that validator still carries "/" or "\" in its name, passing
        // it to Folder::newFolder here would silently create a nested
        // path ("A/B" → folder "A" with subfolder "B") instead of a
        // single folder. Replace path separators + control chars with
        // "-"; runs of "-" collapse; leading/trailing "-" trimmed.
        $folderName = $this->sanitiseForFolderName($teamName);
        $counter    = 1;
        while ($targetFolder->nodeExists($folderName)) {
            $folderName = $this->sanitiseForFolderName($teamName) . ' (' . $counter++ . ')';
        }
        $folder = $targetFolder->newFolder($folderName);

        try {
            $shareManager = $this->container->get(\OCP\Share\IManager::class);
            $share = $shareManager->newShare();
            $share->setShareType(\OCP\Share\IShare::TYPE_CIRCLE)
                  ->setSharedWith($teamId)
                  ->setSharedBy($uid)
                  ->setNode($folder)
                  ->setPermissions(\OCP\Constants::PERMISSION_ALL);
            $share = $shareManager->createShare($share);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][FilesService] createSharedFolder — share failed, deleting folder', [
                'teamId'     => $teamId,
                'folderName' => $folderName,
                'error'      => $e->getMessage(),
                'app'        => Application::APP_ID,
            ]);
            try {
                $folder->delete();
            } catch (\Throwable $deleteEx) {
                $this->logger->warning('[TeamHub][FilesService] createSharedFolder — orphan folder delete also failed', [
                    'error' => $deleteEx->getMessage(),
                    'app'   => Application::APP_ID,
                ]);
            }
            throw $e;
        }

        return ['folder_id' => $folder->getId(), 'path' => $folder->getPath(), 'share_id' => $share->getId()];
    }

    /**
     * Connect an existing folder to a team by sharing it with the team's circle.
     *
     * SECURITY: Caller MUST verify the user has team-admin level. This method
     * additionally verifies that $uid actually owns the node — preventing
     * forged fileId attacks. We resolve the node via $uid's user folder, so a
     * fileId pointing to someone else's file will throw NotFoundException.
     *
     * Refuses to connect if the team already has a Files folder shared
     * (one folder per team).
     *
     * @return array{success:bool, folder_id?:int, path?:string, share_id?:string, error?:string}
     */
    public function connectExistingFolder(string $teamId, int $fileId, string $uid): array {

        $user = $this->container->get(\OCP\IUserManager::class)->get($uid);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found'];
        }

        try {
            $db = $this->container->get(\OCP\IDBConnection::class);

            // Refuse if a folder is already connected for this team.
            $chk = $db->getQueryBuilder();
            $cres = $chk->select('id')
                ->from('share')
                ->where($chk->expr()->eq('share_with', $chk->createNamedParameter($teamId)))
                ->andWhere($chk->expr()->eq('share_type', $chk->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($chk->expr()->eq('item_type', $chk->createNamedParameter('folder')))
                ->setMaxResults(1)
                ->executeQuery();
            $existing = $cres->fetch();
            $cres->closeCursor();

            if ($existing) {
                return ['success' => false, 'error' => 'Team already has a Files folder — disable the current one first'];
            }

            // SECURITY: resolve the node via $uid's user folder. This both verifies
            // ownership (the user has access to it via their root) and gives us the
            // Node object we need for IShareManager.
            $userFolder = $this->container->get(IRootFolder::class)->getUserFolder($uid);
            $nodes = $userFolder->getById($fileId);
            if (empty($nodes)) {
                return ['success' => false, 'error' => 'Folder not found or not accessible by user'];
            }
            $node = $nodes[0];

            if (!($node instanceof \OCP\Files\Folder)) {
                return ['success' => false, 'error' => 'Selected item is not a folder'];
            }

            // Bootstrap a Circles session as the user — required for createShare with a circle target.
            try {
                $federatedUserService = $this->container->get(\OCA\Circles\Service\FederatedUserService::class);
                $federatedUserService->setLocalCurrentUser($user);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][FilesService] connectExistingFolder — Circles session bootstrap failed', [
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            $shareManager = $this->container->get(\OCP\Share\IManager::class);
            $share = $shareManager->newShare();
            $share->setShareType(\OCP\Share\IShare::TYPE_CIRCLE)
                  ->setSharedWith($teamId)
                  ->setSharedBy($uid)
                  ->setNode($node)
                  ->setPermissions(\OCP\Constants::PERMISSION_ALL);
            $share = $shareManager->createShare($share);


            return [
                'success'   => true,
                'folder_id' => $node->getId(),
                'path'      => $node->getPath(),
                'share_id'  => $share->getId(),
            ];

        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][FilesService] connectExistingFolder failed', [
                'teamId' => $teamId, 'fileId' => $fileId,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['success' => false, 'error' => 'Operation failed — see server log for details'];
        }
    }

    /**
     * Suspend team access to the shared Files folder by removing the circle share row.
     * The folder itself and its contents remain intact in the owner's Files.
     *
     * Returns an array of IDs needed for resume, or null if no share found.
     *
     * @return array{share_id: int, uid_initiator: string, file_source: int, permissions: int}|null
     */
    public function suspendFilesAccess(string $teamId, \OCP\IDBConnection $db): ?array {
        try {
            $qb  = $db->getQueryBuilder();
            $res = $qb->select('id', 'uid_initiator', 'file_source', 'permissions')
                ->from('share')
                ->where($qb->expr()->eq('share_with', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('item_type', $qb->createNamedParameter('folder')))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if (!$row) {
                return null;
            }

            $shareId     = (int)$row['id'];
            $permissions = (int)$row['permissions'];

            // v3.100.8 — revert of the W-4 migration for this site. The
            // IManager::deleteShare path leaves a row in `share` that the
            // reconnect duplicate-check ("already has a Files folder")
            // then hits, so users can't reattach the same share after
            // disconnecting. The audit log entry is already written by
            // ResourceService, so no observability is lost by staying
            // with the raw DELETE. See apps.md W-4 verdict.
            $dqb = $db->getQueryBuilder();
            $dqb->delete('share')
                ->where($dqb->expr()->eq('id', $dqb->createNamedParameter($shareId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $this->logger->debug('[TeamHub][FilesService] suspendFilesAccess: share removed', [
                'teamId' => $teamId, 'shareId' => $shareId, 'app' => Application::APP_ID,
            ]);

            return [
                'share_id'      => $shareId,
                'uid_initiator' => (string)$row['uid_initiator'],
                'file_source'   => (int)$row['file_source'],
                'permissions'   => $permissions,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][FilesService] suspendFilesAccess failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
    }

    /**
     * Resume team access to the shared Files folder by re-creating the circle share.
     * Uses the NC IManager to ensure all share metadata is populated correctly.
     */
    public function resumeFilesAccess(
        string $teamId,
        string $ownerUid,
        int $fileId,
        int $permissions,
        \OCP\IDBConnection $db
    ): bool {
        try {
            $rootFolder  = $this->container->get(\OCP\Files\IRootFolder::class);
            $userFolder  = $rootFolder->getUserFolder($ownerUid);
            $nodes       = $userFolder->getById($fileId);

            if (empty($nodes)) {
                $this->logger->warning('[TeamHub][FilesService] resumeFilesAccess: folder node not found', [
                    'teamId' => $teamId, 'fileId' => $fileId, 'app' => Application::APP_ID,
                ]);
                return false;
            }

            $shareManager = $this->container->get(\OCP\Share\IManager::class);
            $share        = $shareManager->newShare();
            $share->setNode($nodes[0]);
            $share->setShareType(7);          // IShare::TYPE_CIRCLE
            $share->setSharedWith($teamId);
            $share->setShareOwner($ownerUid);
            $share->setSharedBy($ownerUid);
            $share->setPermissions($permissions);
            $shareManager->createShare($share);

            $this->logger->debug('[TeamHub][FilesService] resumeFilesAccess: share re-created', [
                'teamId' => $teamId, 'fileId' => $fileId, 'app' => Application::APP_ID,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][FilesService] resumeFilesAccess failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * Remove the team's circle access from a specific shared folder (by file_source ID).
     * Deletes only the share row. The folder node is untouched.
     */
    /**
     * List file folders available to connect to a team.
     *
     * Returns two groups in order:
     *   1. Group Folders where the team's circle is a member (type=group_folder) — top
     *   2. Shared folders the current user owns, shared with any circle, not yet connected
     *      to any TeamHub team (type=shared_folder)
     *
     * Items shape: { id: string, name: string, type: 'group_folder'|'shared_folder' }
     * The id for group_folder items is 'gf:{folder_id}'; for shared_folder items it is
     * the file_source integer as a string.
     *
     * @return array<int, array{id:string, name:string, type:string}>
     */
    public function listConnectableFileFolders(
        string $uid,
        string $teamId,
        \OCA\TeamHub\Service\GroupFolderService $groupFolderService,
        string $activeFilesType = 'none',
        bool $isTeamAdmin = false
    ): array {
        $out = [];

        // ── 1. Group Folders where this team's circle is a member ─────────────
        // Always shown UNLESS a GF is already the active resource (nothing to switch to).
        if ($groupFolderService->isGroupFoldersAvailable() && $teamId !== '' && $activeFilesType !== 'gf') {
            try {
                $db = $this->container->get(\OCP\IDBConnection::class);
                $qb = $db->getQueryBuilder();
                $qb->select('gf.folder_id', 'gf.mount_point')
                    ->from('group_folders', 'gf')
                    ->innerJoin('gf', 'group_folders_groups', 'gfg',
                        $qb->expr()->eq('gf.folder_id', 'gfg.folder_id')
                    )
                    ->where($qb->expr()->eq(
                        'gfg.circle_id',
                        $qb->createNamedParameter($teamId)
                    ))
                    ->orderBy('gf.mount_point', 'ASC');

                $r = $qb->executeQuery();
                while ($row = $r->fetch()) {
                    $out[] = [
                        'id'   => 'gf:' . (int)$row['folder_id'],
                        'name' => (string)$row['mount_point'],
                        'type' => 'group_folder',
                    ];
                }
                $r->closeCursor();

                $this->logger->debug('[TeamHub][FilesService] listConnectableFileFolders — group folders', [
                    'teamId' => $teamId, 'count' => count($out), 'app' => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][FilesService] listConnectableFileFolders — GF query failed', [
                    'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        // ── 1b. v3.100.9 — Available Group Folders (team NOT yet a member)
        // Only visible to team admins because attaching a team to a
        // group folder is an admin-level action. This is the reconnect
        // path: after removeCircleFromFolder() strips the team from a
        // folder's ACL, the folder no longer appears in section 1 above;
        // this section brings it back so admins can re-attach it. Also
        // covers "add the team to a fresh group folder we've never used
        // before." Suppressed when activeFilesType === 'gf' (already
        // connected to a GF — one GF per team is the convention).
        if ($isTeamAdmin && $teamId !== '' && $activeFilesType !== 'gf'
            && $groupFolderService->isGroupFoldersAvailable()
        ) {
            try {
                foreach ($groupFolderService->listGroupFoldersAvailableToAttach($teamId) as $gf) {
                    // Note: use type='group_folder' (not a new sub-type) so
                    // the existing frontend picker renders the entry with
                    // no build required. Connect flow is idempotent so
                    // reconnect works the same code path as fresh attach.
                    $out[] = [
                        'id'         => 'gf:' . $gf['folder_id'],
                        'name'       => $gf['mount_point'],
                        'type'       => 'group_folder',
                        'available'  => true,
                    ];
                }
                $this->logger->debug('[TeamHub][FilesService] listConnectableFileFolders — available GFs', [
                    'teamId' => $teamId, 'total_after' => count($out),
                    'app' => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][FilesService] listConnectableFileFolders — available GF query failed', [
                    'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        // ── 2. Shared folders owned by $uid, shared with a circle (type=7),
        //       not already connected to any TeamHub team.
        // Suppressed when activeFilesType='shared' (shared folder already active —
        // only GF options are relevant) or 'gf' (GF already active — nothing to add).
        if ($activeFilesType === 'none') {
        try {
            $db = $this->container->get(\OCP\IDBConnection::class);

            // Find file_source IDs already connected in teamhub_team_app_resources
            // so we can exclude them. Only exclude non-gf: entries (legacy shares).
            $usedQb = $db->getQueryBuilder();
            $usedRes = $usedQb->select('resource_id')
                ->from('teamhub_team_app_resources')
                ->where($usedQb->expr()->eq('app_id', $usedQb->createNamedParameter('files')))
                ->andWhere($usedQb->expr()->eq('status', $usedQb->createNamedParameter('active')))
                ->executeQuery();
            $usedIds = [];
            while ($row = $usedRes->fetch()) {
                $rid = (string)$row['resource_id'];
                if (!str_starts_with($rid, 'gf:')) {
                    $usedIds[] = $rid;
                }
            }
            $usedRes->closeCursor();

            // Find circle shares (type=7) owned by $uid for folders.
            $qb = $db->getQueryBuilder();
            $qb->select('s.file_source', 'f.name', 'f.path')
                ->from('share', 's')
                ->leftJoin('s', 'filecache', 'f',
                    $qb->expr()->eq('s.file_source', 'f.fileid')
                )
                ->where($qb->expr()->eq('s.uid_owner', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->eq('s.share_type', $qb->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('s.item_type', $qb->createNamedParameter('folder')))
                ->orderBy('f.name', 'ASC');

            if (!empty($usedIds)) {
                $qb->andWhere($qb->expr()->notIn(
                    's.file_source',
                    $qb->createNamedParameter(
                        array_map('intval', $usedIds),
                        \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY
                    )
                ));
            }

            $r = $qb->executeQuery();
            $seen = [];
            while ($row = $r->fetch()) {
                $fid = (string)(int)$row['file_source'];
                if (isset($seen[$fid])) continue; // deduplicate multi-share
                $seen[$fid] = true;
                // name is the filecache entry's own name component.
                // If empty (can happen on some storage backends), fall back to
                // the last segment of the path, then to the raw file ID.
                $name = (string)($row['name'] ?? '');
                if ($name === '' && isset($row['path']) && $row['path'] !== '') {
                    $name = basename((string)$row['path']);
                }
                if ($name === '') {
                    $name = $fid;
                }
                $out[] = [
                    'id'   => $fid,
                    'name' => $name,
                    'type' => 'shared_folder',
                ];
            }
            $r->closeCursor();

            $this->logger->debug('[TeamHub][FilesService] listConnectableFileFolders — shared folders', [
                'uid' => $uid, 'total' => count($out), 'app' => Application::APP_ID,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][FilesService] listConnectableFileFolders — share query failed', [
                'uid' => $uid, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
        } // end if activeFilesType === 'none'

        return $out;
    }

    /**
     * Resolve the filecache ID (oc_filecache.fileid) for a Group Folder by its
     * mount point name. Group folders are mounted at the root of every member's
     * file tree under their mount point name. We look them up via IRootFolder
     * with the current user's context.
     *
     * Returns null if the group folder cannot be found in the current user's file tree.
     */
    public function getGroupFolderFilecacheId(string $mountPoint, string $uid): ?int {
        try {
            $rootFolder = $this->container->get(IRootFolder::class);
            $userFolder = $rootFolder->getUserFolder($uid);
            // Group folders appear directly in the user's root under their mount point name.
            if (!$userFolder->nodeExists($mountPoint)) {
                $this->logger->warning('[TeamHub][FilesService] getGroupFolderFilecacheId — node not found', [
                    'mountPoint' => $mountPoint, 'uid' => $uid, 'app' => Application::APP_ID,
                ]);
                return null;
            }
            $node = $userFolder->get($mountPoint);
            $id   = $node->getId();
            $this->logger->debug('[TeamHub][FilesService] getGroupFolderFilecacheId — resolved', [
                'mountPoint' => $mountPoint, 'uid' => $uid, 'filecacheId' => $id, 'app' => Application::APP_ID,
            ]);
            return (int) $id;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][FilesService] getGroupFolderFilecacheId failed', [
                'mountPoint' => $mountPoint, 'uid' => $uid,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
    }

    public function removeFilesAccess(string $teamId, int $fileId, \OCP\IDBConnection $db): bool {
        try {
            // Find the share ID for this specific file_source + teamId circle share.
            $qb  = $db->getQueryBuilder();
            $res = $qb->select('id')
                ->from('share')
                ->where($qb->expr()->eq('share_with',  $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('share_type',  $qb->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('file_source', $qb->createNamedParameter($fileId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if (!$row) {
                $this->logger->warning('[TeamHub][FilesService] removeFilesAccess: share not found', [
                    'teamId' => $teamId, 'fileId' => $fileId, 'app' => Application::APP_ID,
                ]);
                return false;
            }
            $shareId = (int)$row['id'];

            // v3.100.8 — revert of the W-4 migration for this site. See
            // suspendFilesAccess above for rationale.
            $dqb = $db->getQueryBuilder();
            $dqb->delete('share')
                ->where($dqb->expr()->eq('id', $dqb->createNamedParameter($shareId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $this->logger->debug('[TeamHub][FilesService] removeFilesAccess: share removed', [
                'teamId' => $teamId, 'fileId' => $fileId, 'shareId' => $shareId,
                'app' => Application::APP_ID,
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][FilesService] removeFilesAccess failed', [
                'teamId' => $teamId, 'fileId' => $fileId,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * Delete a specific shared folder by file_source ID (multi-resource-aware).
     * Removes the circle share and the folder node.
     */
    public function deleteFolderById(int $fileId, string $teamId, \OCP\IDBConnection $db): array {
        try {
            // Remove the circle share first.
            $this->removeFilesAccess($teamId, $fileId, $db);

            // Delete the actual node via NC filesystem.
            try {
                $folder = $this->container->get(IRootFolder::class)->getById($fileId);
                if (!empty($folder)) {
                    $folder[0]->delete();
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][FilesService] deleteFolderById: node delete failed', [
                    'fileId' => $fileId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            $this->logger->info('[TeamHub][FilesService] deleteFolderById: folder deleted', [
                'fileId' => $fileId, 'app' => Application::APP_ID,
            ]);
            return ['deleted' => true, 'file_id' => $fileId];
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][FilesService] deleteFolderById failed', [
                'fileId' => $fileId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['deleted' => false, 'detail' => $e->getMessage()];
        }
    }

    public function deleteSharedFolder(string $teamId, \OCP\IDBConnection $db): array {
        try {
            $qb  = $db->getQueryBuilder();
            $res = $qb->select('id', 'uid_initiator', 'file_source')
                ->from('share')
                ->where($qb->expr()->eq('share_with', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('item_type', $qb->createNamedParameter('folder')))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if (!$row) {
                return ['deleted' => false, 'detail' => 'No Files share found for this team'];
            }

            $shareId  = (int)$row['id'];
            $ownerUid = $row['uid_initiator'];
            $fileId   = (int)$row['file_source'];

            try {
                $shareManager = $this->container->get(\OCP\Share\IManager::class);
                $share = $shareManager->getShareById('ocinternal:' . $shareId);
                $shareManager->deleteShare($share);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][FilesService] deleteSharedFolder: IManager delete failed, using QB', [
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                $dqb = $db->getQueryBuilder();
                $dqb->delete('share')
                    ->where($dqb->expr()->eq('id', $dqb->createNamedParameter($shareId)))
                    ->executeStatement();
            }

            try {
                $userFolder = $this->container->get(IRootFolder::class)->getUserFolder($ownerUid);
                $nodes = $userFolder->getById($fileId);
                if (!empty($nodes)) {
                    $nodes[0]->delete();
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][FilesService] deleteSharedFolder: folder node delete failed', [
                    'fileId' => $fileId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            return ['deleted' => true, 'detail' => "Files folder {$fileId} and share deleted"];

        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][FilesService] deleteSharedFolder failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['deleted' => false, 'detail' => 'Operation failed — see server log for details'];
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function collectFiles(\OCP\Files\Folder $folder, array &$files): void {
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node->getType() === \OCP\Files\FileInfo::TYPE_FILE) {
                $files[] = $node;
            } elseif ($node instanceof \OCP\Files\Folder) {
                $this->collectFiles($node, $files);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Message image cache
    // -------------------------------------------------------------------------

    /** Hidden folder name inside the team folder used to store cached message images. */
    public const IMAGE_CACHE_FOLDER = '.teamhub-cache';

    /**
     * Copy a file from the requesting user's personal Files into the team
     * folder image cache, then return the cached file's numeric fileId.
     *
     * The team folder is circle-shared with all team members, so the cached
     * copy is accessible to every member without per-user ACL checks.
     * The file survives even if the original poster leaves the team.
     *
     * @param int    $teamFolderId  NC fileId of the team folder root (from resources.files.folder_id)
     * @param string $sourcePath   Absolute path inside the requesting user's DAV root (e.g. /Photos/cat.png)
     * @param string $uid          Requesting user's uid
     * @return array{ fileId: int, cachePath: string }
     * @throws \RuntimeException on any failure
     */
    public function cacheImageInTeamFolder(int $teamFolderId, string $sourcePath, string $uid): array {
        $this->logger->debug('[TeamHub][FilesService] cacheImageInTeamFolder — start', [
            'teamFolderId' => $teamFolderId,
            'sourcePath'   => $sourcePath,
            'uid'          => $uid,
            'app'          => Application::APP_ID,
        ]);

        $rootFolder = $this->container->get(IRootFolder::class);

        // 1. Resolve the source file via the user's own folder — security boundary:
        //    this ensures the user actually has access to the file they're caching.
        $userFolder = $rootFolder->getUserFolder($uid);
        try {
            $sourceNode = $userFolder->get($sourcePath);
        } catch (\OCP\Files\NotFoundException $e) {
            throw new \RuntimeException('Source file not found or not accessible: ' . $sourcePath);
        }
        if (!($sourceNode instanceof \OCP\Files\File)) {
            throw new \RuntimeException('Source path is not a file: ' . $sourcePath);
        }

        // 2. Resolve the team folder root by its numeric fileId.
        //    We look it up in the user's mountpoints — the user must be able to
        //    see the team folder (they are a circle member).
        $teamFolderNodes = $rootFolder->getById($teamFolderId);
        if (empty($teamFolderNodes)) {
            throw new \RuntimeException('Team folder not found: ' . $teamFolderId);
        }
        /** @var \OCP\Files\Folder $teamFolder */
        $teamFolder = $teamFolderNodes[0];
        if (!($teamFolder instanceof \OCP\Files\Folder)) {
            throw new \RuntimeException('Team folder ID does not point to a folder: ' . $teamFolderId);
        }

        // 3. Ensure the hidden cache subfolder exists.
        try {
            $cacheFolder = $teamFolder->get(self::IMAGE_CACHE_FOLDER);
            if (!($cacheFolder instanceof \OCP\Files\Folder)) {
                throw new \RuntimeException('Cache path exists but is not a folder');
            }
        } catch (\OCP\Files\NotFoundException $e) {
            $cacheFolder = $teamFolder->newFolder(self::IMAGE_CACHE_FOLDER);
            $this->logger->debug('[TeamHub][FilesService] cacheImageInTeamFolder — created cache folder', [
                'teamFolderId' => $teamFolderId, 'app' => Application::APP_ID,
            ]);
        }

        // 4. Build a timestamp-prefixed filename to avoid collisions.
        $originalName = $sourceNode->getName();
        $timestamp    = date('Ymd-His');
        $cachedName   = $timestamp . '-' . $originalName;

        // 5. Copy the file contents into the cache folder.
        $content = $sourceNode->getContent();
        /** @var \OCP\Files\File $cachedFile */
        $cachedFile = $cacheFolder->newFile($cachedName, $content);

        $this->logger->debug('[TeamHub][FilesService] cacheImageInTeamFolder — cached', [
            'cachedName'   => $cachedName,
            'fileId'       => $cachedFile->getId(),
            'teamFolderId' => $teamFolderId,
            'app'          => Application::APP_ID,
        ]);

        return [
            'fileId'    => $cachedFile->getId(),
            'cachePath' => self::IMAGE_CACHE_FOLDER . '/' . $cachedName,
        ];
    }

    /**
     * Delete all files inside the hidden image cache folder for a team,
     * without removing the folder itself.
     * Returns the number of files deleted.
     *
     * @throws \RuntimeException if the team folder cannot be found
     */
    public function clearImageCache(int $teamFolderId): int {
        $this->logger->debug('[TeamHub][FilesService] clearImageCache — start', [
            'teamFolderId' => $teamFolderId, 'app' => Application::APP_ID,
        ]);

        $rootFolder       = $this->container->get(IRootFolder::class);
        $teamFolderNodes  = $rootFolder->getById($teamFolderId);
        if (empty($teamFolderNodes)) {
            throw new \RuntimeException('Team folder not found: ' . $teamFolderId);
        }
        /** @var \OCP\Files\Folder $teamFolder */
        $teamFolder = $teamFolderNodes[0];

        try {
            $cacheFolder = $teamFolder->get(self::IMAGE_CACHE_FOLDER);
        } catch (\OCP\Files\NotFoundException $e) {
            // No cache folder — nothing to clear
            return 0;
        }

        if (!($cacheFolder instanceof \OCP\Files\Folder)) {
            throw new \RuntimeException('Cache path is not a folder');
        }

        $deleted = 0;
        foreach ($cacheFolder->getDirectoryListing() as $node) {
            try {
                $node->delete();
                $deleted++;
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][FilesService] clearImageCache — failed to delete node', [
                    'name'  => $node->getName(),
                    'error' => $e->getMessage(),
                    'app'   => Application::APP_ID,
                ]);
            }
        }

        $this->logger->debug('[TeamHub][FilesService] clearImageCache — done', [
            'deleted' => $deleted, 'teamFolderId' => $teamFolderId, 'app' => Application::APP_ID,
        ]);

        return $deleted;
    }

    /**
     * Sanitise an arbitrary string into something safe to hand to
     * Folder::newFolder() (v4.3.19). Any character that could be
     * interpreted as a path separator or is otherwise illegal in a
     * filename is collapsed to a single "-".
     *
     * Only called by createSharedFolder — TeamService::assertValidTeamName
     * already rejects these chars at team-create time. This is the
     * belt-and-braces net for teams renamed via the Circles UI or
     * legacy rows that predate the input validator.
     */
    private function sanitiseForFolderName(string $name): string {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return 'team';
        }
        // Replace path separators and control chars with "-".
        $cleaned = preg_replace('#[/\\\\\x00-\x1F\x7F]+#u', '-', $trimmed);
        // Collapse runs of "-" and trim.
        $cleaned = trim((string)preg_replace('/-+/', '-', (string)$cleaned), '-');
        if ($cleaned === '' || $cleaned === '.' || $cleaned === '..') {
            return 'team';
        }
        if (mb_strlen($cleaned) > 255) {
            $cleaned = mb_substr($cleaned, 0, 255);
        }
        return $cleaned;
    }

    private function nodeToArray(Node $node, string $teamFolderPath): array {
        $fullPath     = $node->getPath();
        $relativePath = ltrim(substr($fullPath, strlen($teamFolderPath) - 1), '/');
        $name         = $node->getName();
        $ext          = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return [
            'id'        => $node->getId(),
            'name'      => $name,
            'path'      => $relativePath,
            'mtime'     => $node->getMTime(),
            'size'      => $node->getSize(),
            'mimetype'  => $node->getMimetype(),
            'extension' => $ext,
        ];
    }

}
