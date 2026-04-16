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

    /**
     * Return files starred by $uid that live inside the team's shared folder
     * (identified by $folderId — the file_source from the share row).
     *
     * Uses NC's ITagManager to get the user's favourited file IDs, then
     * intersects with IRootFolder::getById() nodes that are descendants of the
     * team folder.
     *
     * @return array  Array of file-info maps, or empty array.
     */
    public function getFavoriteFiles(int $folderId, string $uid): array {
        $this->logger->debug('[TeamHub][FilesService] getFavoriteFiles — start', [
            'folderId' => $folderId, 'uid' => $uid, 'app' => Application::APP_ID,
        ]);

        try {
            $rootFolder = $this->container->get(IRootFolder::class);
            $userFolder = $rootFolder->getUserFolder($uid);

            // Resolve the team folder node from its file-source ID.
            $teamFolderNodes = $userFolder->getById($folderId);
            if (empty($teamFolderNodes)) {
                $this->logger->warning('[TeamHub][FilesService] getFavoriteFiles — team folder not found', [
                    'folderId' => $folderId, 'uid' => $uid, 'app' => Application::APP_ID,
                ]);
                return [];
            }
            $teamFolder     = $teamFolderNodes[0];
            $teamFolderPath = rtrim($teamFolder->getPath(), '/') . '/';

            // Get all file IDs the user has marked as favourite.
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
                    // Only include files (not folders) that are inside the team folder.
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

    /**
     * Return the $limit most recently modified files inside the team folder,
     * newest first.
     *
     * Walks the folder tree recursively using NC's Folder::search() with a
     * SearchQuery that orders by mtime DESC and filters for files only.
     *
     * @return array  Array of file-info maps, or empty array.
     */
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

            // Collect all files via a recursive walk. The team folder is
            // typically small so this is safe. The internal SearchQuery API
            // proved unreliable across NC versions for negated comparators.
            $allFiles = [];
            $this->collectFiles($teamFolder, $allFiles);

            $this->logger->debug('[TeamHub][FilesService] getRecentFiles — collected ' . count($allFiles) . ' files', [
                'uid' => $uid, 'app' => Application::APP_ID,
            ]);

            // Sort by mtime descending (newest first) and take the top $limit.
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
     * Recursively collect all file nodes (not folders) under $folder.
     * Results are appended to $files by reference.
     *
     * @param \OCP\Files\Folder $folder
     * @param Node[]            $files
     */
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
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Convert a Node to the array shape returned to the frontend.
     * Path is made relative to the team folder root for display.
     */
    private function nodeToArray(Node $node, string $teamFolderPath): array {
        $fullPath     = $node->getPath();
        $relativePath = ltrim(substr($fullPath, strlen($teamFolderPath) - 1), '/');
        $name         = $node->getName();
        $ext          = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return [
            'id'           => $node->getId(),
            'name'         => $name,
            'path'         => $relativePath,
            'mtime'        => $node->getMTime(),
            'size'         => $node->getSize(),
            'mimetype'     => $node->getMimetype(),
            'extension'    => $ext,
        ];
    }

    public function createSharedFolder(string $teamId, string $teamName, string $uid): array {

        $userFolder = $this->container->get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
        $folderName = $teamName;
        $counter    = 1;
        while ($userFolder->nodeExists($folderName)) {
            $folderName = $teamName . ' (' . $counter++ . ')';
        }
        $folder = $userFolder->newFolder($folderName);

        $shareManager = $this->container->get(\OCP\Share\IManager::class);
        $share = $shareManager->newShare();
        $share->setShareType(7) // IShare::TYPE_CIRCLE
              ->setSharedWith($teamId)
              ->setSharedBy($uid)
              ->setNode($folder)
              ->setPermissions(\OCP\Constants::PERMISSION_ALL);
        $share = $shareManager->createShare($share);


        return ['folder_id' => $folder->getId(), 'path' => $folder->getPath(), 'share_id' => $share->getId()];
    }

    /**
     * Create a calendar and share it with the circle via the dav_shares table.
     * The circle principal is principals/circles/{teamId}.
     */
    public function deleteSharedFolder(string $teamId, \OCP\IDBConnection $db): array {
        try {
            // Find the share row: share_type=7 (TYPE_CIRCLE), share_with=teamId
            $qb = $db->getQueryBuilder();
            $res = $qb->select('id', 'uid_initiator', 'file_source')
                ->from('share')
                ->where($qb->expr()->eq('share_with', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(7)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if (!$row) {
                return ['deleted' => false, 'detail' => 'No Files share found for this team'];
            }

            $shareId   = (int)$row['id'];
            $ownerUid  = $row['uid_initiator'];
            $fileId    = (int)$row['file_source'];

            // Delete the share via IManager (triggers proper cleanup)
            try {
                $shareManager = $this->container->get(\OCP\Share\IManager::class);
                $share = $shareManager->getShareById('ocinternal:' . $shareId);
                $shareManager->deleteShare($share);
            } catch (\Throwable $e) {
                // Fallback: direct DB delete of the share row
                $this->logger->warning('[FilesService] deleteSharedFolder: IManager delete failed, using QB', [
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                $dqb = $db->getQueryBuilder();
                $dqb->delete('share')
                    ->where($dqb->expr()->eq('id', $dqb->createNamedParameter($shareId)))
                    ->executeStatement();
            }

            // Delete the folder node itself
            try {
                $rootFolder = $this->container->get(\OCP\Files\IRootFolder::class);
                $userFolder = $rootFolder->getUserFolder($ownerUid);
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

    /**
     * Delete the calendar shared with this team circle.
     * Uses CalDavBackend::deleteCalendar() which cascades all events.
     */

}
