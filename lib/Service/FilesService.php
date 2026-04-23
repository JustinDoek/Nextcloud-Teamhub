<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
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
                    ->andWhere($qb->expr()->eq('s.share_type', $qb->createNamedParameter(7)))
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
            $this->logger->warning('[FilesService] createSharedFolder — Circles session bootstrap failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        $userFolder = $this->container->get(IRootFolder::class)->getUserFolder($uid);
        $folderName = $teamName;
        $counter    = 1;
        while ($userFolder->nodeExists($folderName)) {
            $folderName = $teamName . ' (' . $counter++ . ')';
        }
        $folder = $userFolder->newFolder($folderName);

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
            $this->logger->error('[FilesService] createSharedFolder — share failed, deleting folder', [
                'teamId'     => $teamId,
                'folderName' => $folderName,
                'error'      => $e->getMessage(),
                'app'        => Application::APP_ID,
            ]);
            try {
                $folder->delete();
            } catch (\Throwable $deleteEx) {
                $this->logger->warning('[FilesService] createSharedFolder — orphan folder delete also failed', [
                    'error' => $deleteEx->getMessage(),
                    'app'   => Application::APP_ID,
                ]);
            }
            throw $e;
        }

        return ['folder_id' => $folder->getId(), 'path' => $folder->getPath(), 'share_id' => $share->getId()];
    }

    public function deleteSharedFolder(string $teamId, \OCP\IDBConnection $db): array {
        try {
            $qb  = $db->getQueryBuilder();
            $res = $qb->select('id', 'uid_initiator', 'file_source')
                ->from('share')
                ->where($qb->expr()->eq('share_with', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(7)))
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
                $this->logger->warning('[FilesService] deleteSharedFolder: IManager delete failed, using QB', [
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
                $this->logger->warning('[FilesService] deleteSharedFolder: folder node delete failed', [
                    'fileId' => $fileId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            return ['deleted' => true, 'detail' => "Files folder {$fileId} and share deleted"];

        } catch (\Throwable $e) {
            $this->logger->error('[FilesService] deleteSharedFolder failed', [
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
