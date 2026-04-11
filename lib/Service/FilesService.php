<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
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
        private LoggerInterface $logger,
    ) {}

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
