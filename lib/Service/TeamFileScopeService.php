<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\TeamAppResourceMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * "Which team does this file belong to", in one place (v4.8.18).
 *
 * The logic was written for `ApprovalWorkProvider::mapFilesToTeams()` in
 * v4.5.21 and is now needed by file reviews as well — for the file action's
 * eligibility test, for the request-time assertion that a file really is inside
 * the team it claims, and for the My Work provider. Copying it would have been
 * the natural thing and the wrong one: the rules for what counts as a team
 * folder (group folder mount points, plain shared folders, per-user visibility)
 * are subtle enough that two copies would answer differently within a release
 * or two, and the disagreement would show up as a review that cannot be opened
 * from the tab it was created in.
 *
 * ## Everything here is per-user
 *
 * A team's Files resource is a folder id, but a folder id has no single path:
 * it appears at a different place in every member's tree, and not at all for a
 * member who is not in it. So every method takes the user whose view is being
 * asked about, and the answer for one user is not usable for another. This is
 * also, conveniently, the authorisation: a file that does not resolve in your
 * own user folder is a file you cannot see, and it drops out of every result
 * here without a special case.
 *
 * ## Paths are prefixes, not identities
 *
 * `mapFilesToTeams()` matches on `$path === $folder` or
 * `str_starts_with($path . '/', $folder . '/')`. The trailing slash on both
 * sides is what stops `/Team A Archive/x` from matching the team folder
 * `/Team A` — a plain `str_starts_with($path, $folder)` would claim it.
 */
class TeamFileScopeService {

    public function __construct(
        private IRootFolder $rootFolder,
        private TeamAppResourceMapper $resourceMapper,
        private GroupFolderService $groupFolderService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The user's own root, or null when they have none (a disabled or deleted
     * account reached through a stale row).
     */
    public function userFolder(string $userId): ?Folder {
        try {
            return $this->rootFolder->getUserFolder($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamFileScopeService] No user folder', [
                'user' => $userId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
    }

    /**
     * Absolute paths of a team's registered Files resources, in the calling
     * user's own view of the file tree.
     *
     * Moved verbatim from ApprovalWorkProvider::teamFolderPaths() (v4.5.21).
     * Its unused `$userId` parameter is gone; everything else behaves as it did.
     *
     * @return string[]
     */
    public function teamFolderPaths(string $teamId, Folder $userFolder): array {
        $paths = [];

        try {
            $rows = $this->resourceMapper->findActiveByTeamAndApp($teamId, 'files');
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as $row) {
            $resourceId = (string)$row->getResourceId();

            // Group folder: 'gf:{folderId}'. Resolve the mount point, then the
            // node — group folders are mounted at the root of every member's
            // tree under that name.
            if (str_starts_with($resourceId, 'gf:')) {
                $meta = $this->groupFolderService->resolveGroupFolderResourceId($resourceId);
                if ($meta === null) {
                    continue;
                }
                try {
                    if ($userFolder->nodeExists($meta['mount_point'])) {
                        $paths[] = rtrim($userFolder->get($meta['mount_point'])->getPath(), '/');
                    }
                } catch (\Throwable) {
                    // Not mounted for this user — they are not in the folder.
                }
                continue;
            }

            $fileId = (int)$resourceId;
            if ($fileId <= 0) {
                continue;
            }
            try {
                $node = $userFolder->getFirstNodeById($fileId);
                if ($node !== null) {
                    $paths[] = rtrim($node->getPath(), '/');
                }
            } catch (\Throwable) {
                // Folder gone or not shared with this user.
            }
        }

        return $paths;
    }

    /**
     * Team folder paths for several teams at once, in one user's view.
     *
     * @param string[] $teamIds
     * @return array<string, string[]> teamId => paths, teams with none omitted
     */
    public function pathsByTeam(string $userId, array $teamIds, ?Folder $userFolder = null): array {
        $userFolder ??= $this->userFolder($userId);
        if ($userFolder === null) {
            return [];
        }

        $out = [];
        foreach ($teamIds as $teamId) {
            $paths = $this->teamFolderPaths((string)$teamId, $userFolder);
            if ($paths !== []) {
                $out[(string)$teamId] = $paths;
            }
        }

        return $out;
    }

    /**
     * Resolve file ids to the team whose folder contains them.
     *
     * A file can sit under two teams' folders at once — nothing prevents a team
     * from attaching a folder that is inside another team's — so the first
     * match becomes `teamId` and the rest are reported in `others` rather than
     * discarded. Callers that must pick one pick `teamId`; callers that must
     * not guess can see that there was a choice.
     *
     * A file that resolves to no team, or that the user cannot see at all, is
     * absent from the result rather than present with a null team. That is what
     * makes "not in a team folder" and "deleted or revoked" the same answer to
     * every caller, which is the correct handling of both.
     *
     * @param string[] $teamIds
     * @param int[]    $fileIds
     * @return array<int, array{teamId:string, others:string[], node:\OCP\Files\Node}>
     */
    public function mapFilesToTeams(string $userId, array $teamIds, array $fileIds): array {
        if ($fileIds === [] || $teamIds === []) {
            return [];
        }

        $userFolder = $this->userFolder($userId);
        if ($userFolder === null) {
            return [];
        }

        $teamFolders = $this->pathsByTeam($userId, $teamIds, $userFolder);
        if ($teamFolders === []) {
            return [];
        }

        $out = [];
        foreach ($fileIds as $fileId) {
            $fileId = (int)$fileId;
            try {
                $node = $userFolder->getFirstNodeById($fileId);
            } catch (\Throwable) {
                $node = null;
            }
            if ($node === null) {
                continue;
            }

            $path    = rtrim($node->getPath(), '/');
            $primary = null;
            $others  = [];

            foreach ($teamFolders as $teamId => $paths) {
                foreach ($paths as $folderPath) {
                    if ($path === $folderPath || str_starts_with($path . '/', $folderPath . '/')) {
                        if ($primary === null) {
                            $primary = (string)$teamId;
                        } elseif ($primary !== (string)$teamId && !in_array((string)$teamId, $others, true)) {
                            $others[] = (string)$teamId;
                        }
                        break;
                    }
                }
            }

            if ($primary === null) {
                continue;
            }

            $out[$fileId] = ['teamId' => $primary, 'others' => $others, 'node' => $node];
        }

        return $out;
    }

    /**
     * One file, one answer. Null when the user cannot see it or it is in no
     * team folder.
     *
     * @param string[] $teamIds
     * @return array{teamId:string, others:string[], node:\OCP\Files\Node}|null
     */
    public function resolveFile(string $userId, array $teamIds, int $fileId): ?array {
        return $this->mapFilesToTeams($userId, $teamIds, [$fileId])[$fileId] ?? null;
    }

    /**
     * Is this file inside this specific team's folders, for this user?
     *
     * Deliberately not "is it in `resolveFile()`'s `teamId`": a file under two
     * teams' folders belongs to both, and a request made from team B must not
     * be refused because team A happened to sort first.
     *
     * @return bool
     */
    public function isFileInTeam(string $userId, string $teamId, int $fileId): bool {
        $resolved = $this->resolveFile($userId, [$teamId], $fileId);

        return $resolved !== null && $resolved['teamId'] === $teamId;
    }
}
