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
        // v4.10.1 — Nextcloud 35's team spaces. Every mutation below asks it
        // first; on 33/34 it answers "unavailable" and the plain group-folder
        // path runs unchanged (DESIGN.md §2.133).
        private TeamSpaceService  $teamSpaceService,
    ) {}

    /** The team-space seam, for callers that need the version fact itself. */
    public function teamSpaces(): TeamSpaceService {
        return $this->teamSpaceService;
    }

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
     * v4.10.1 — The team's folder, made the way this Nextcloud makes it.
     *
     * On Nextcloud 35 with Team folders the result is the team's *space*
     * (created through `ITeamFolderProvider`, or the one the team already
     * has — the provider is idempotent). On 33/34 it is a plain group folder
     * with the circle assigned, exactly as before. One call site shape for
     * team creation, bulk import and provisioning, so none of them carries
     * the version split.
     *
     * @return array{folder_id: int, team_space: bool}
     */
    public function createTeamFolder(string $teamId, string $mountPoint): array {
        if ($this->teamSpaceService->isAvailable()) {
            $space = $this->teamSpaceService->createTeamSpace($teamId, $mountPoint);
            return ['folder_id' => $space['id'], 'team_space' => true];
        }
        $folderId = $this->createGroupFolder($mountPoint);
        $this->assignCircleToFolder($folderId, $teamId);
        return ['folder_id' => $folderId, 'team_space' => false];
    }

    /**
     * Assign a team's circle to a Group Folder.
     * FolderManager::addApplicableGroup detects circles by their single_id automatically.
     *
     * v4.10.1 — on Nextcloud 35 the folder also becomes the team's space when
     * the provider allows it (applicable to this circle alone, nobody's space
     * yet). A folder that is already this team's space is left as it is —
     * Team folders refuses `addApplicableGroup()` on any space, and there is
     * nothing to add. A folder that is *another* team's space is refused
     * outright: connecting it would fail halfway and leave a row pointing at
     * a folder the team cannot open.
     *
     * @param int    $folderId        GroupFolders folder ID
     * @param string $circleUniqueId  Team circle's unique_id / single_id
     * @return bool true when the folder is the team's space afterwards
     * @throws \RuntimeException when the folder belongs to another team
     */
    public function assignCircleToFolder(int $folderId, string $circleUniqueId): bool {
        $fm = $this->requireFolderManager();

        $this->logger->debug('[TeamHub][GroupFolderService] assignCircleToFolder', [
            'folderId' => $folderId, 'circleUniqueId' => $circleUniqueId, 'app' => Application::APP_ID,
        ]);

        if ($this->teamSpaceService->isAvailable()) {
            $owner = $this->teamSpaceService->spaceOwnerCircleId($folderId);
            if ($owner === $circleUniqueId) {
                return true;
            }
            if ($owner !== null) {
                throw new \RuntimeException('This folder is the team space of another team and cannot be connected.');
            }
        }

        $fm->addApplicableGroup($folderId, $circleUniqueId);

        $this->logger->info('[TeamHub][GroupFolderService] circle assigned to group folder', [
            'folderId' => $folderId, 'circleUniqueId' => $circleUniqueId, 'app' => Application::APP_ID,
        ]);

        if (!$this->teamSpaceService->isAvailable()) {
            return false;
        }
        if ($this->teamSpaceService->getTeamSpace($circleUniqueId) !== null) {
            // The team already has a space elsewhere; this stays a plain
            // second folder rather than a second space, which NC forbids.
            return false;
        }
        if (!$this->teamSpaceService->isLinkable($circleUniqueId, $folderId)) {
            return false;
        }
        try {
            $this->teamSpaceService->linkTeamSpace($circleUniqueId, $folderId);
            return true;
        } catch (\Throwable $e) {
            // The folder is connected either way; being a space is the bonus
            // that failed, and the reconcile job will offer it again.
            $this->logger->warning('[TeamHub][GroupFolderService] folder connected but could not be linked as team space', [
                'folderId' => $folderId, 'circleUniqueId' => $circleUniqueId,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * Remove a team's circle from a Group Folder.
     *
     * v4.10.1 — for the team's own space this is an *unlink*: Team folders
     * clears the ownership and removes the circle's access in one step, and
     * keeps the folder with everything in it (the same promise a plain
     * disconnect always made). Reconnecting is `assignCircleToFolder()`,
     * which links it again.
     */
    public function removeCircleFromFolder(int $folderId, string $circleUniqueId): void {
        $fm = $this->requireFolderManager();

        $this->logger->debug('[TeamHub][GroupFolderService] removeCircleFromFolder', [
            'folderId' => $folderId, 'circleUniqueId' => $circleUniqueId, 'app' => Application::APP_ID,
        ]);

        if ($this->teamSpaceService->isAvailable() && $this->teamSpaceService->isTeamSpaceOf($circleUniqueId, $folderId)) {
            $this->teamSpaceService->unlinkTeamSpace($circleUniqueId);
            $this->logger->info('[TeamHub][GroupFolderService] team space unlinked from circle', [
                'folderId' => $folderId, 'circleUniqueId' => $circleUniqueId, 'app' => Application::APP_ID,
            ]);
            return;
        }

        $fm->removeApplicableGroup($folderId, $circleUniqueId);

        $this->logger->info('[TeamHub][GroupFolderService] circle removed from group folder', [
            'folderId' => $folderId, 'circleUniqueId' => $circleUniqueId, 'app' => Application::APP_ID,
        ]);
    }

    /**
     * v4.10.1 — Take one group or circle off a folder's applicable list.
     *
     * The reconcile job's tool for "a team folder is for its team only":
     * everything else on the list goes, whichever kind of principal it is.
     * `FolderManager::removeApplicableGroup()` matches the id against both
     * columns, and refuses only the owning circle of a space — which this
     * is never asked to remove.
     *
     * @return array<int, array{id: string, kind: string}> the folder's applicable
     *         principals before the removal, so the caller can report them
     */
    public function listApplicable(int $folderId): array {
        $out = [];
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('group_id', 'circle_id')
                ->from('group_folders_groups')
                ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)));
            $r = $qb->executeQuery();
            while ($row = $r->fetch()) {
                $circle = (string)($row['circle_id'] ?? '');
                $group  = (string)($row['group_id'] ?? '');
                if ($circle !== '') {
                    $out[] = ['id' => $circle, 'kind' => 'circle'];
                } elseif ($group !== '') {
                    $out[] = ['id' => $group, 'kind' => 'group'];
                }
            }
            $r->closeCursor();
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][GroupFolderService] listApplicable failed', [
                'folderId' => $folderId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
        return $out;
    }

    public function removeApplicable(int $folderId, string $groupOrCircleId): void {
        $fm = $this->requireFolderManager();
        $fm->removeApplicableGroup($folderId, $groupOrCircleId);
        $this->logger->info('[TeamHub][GroupFolderService] principal removed from group folder', [
            'folderId' => $folderId, 'principal' => $groupOrCircleId, 'app' => Application::APP_ID,
        ]);
    }

    /**
     * Permanently delete a Group Folder and all its contents.
     *
     * v4.10.1 — a team space can only be deleted by its team, through the
     * provider (`FolderManager::removeFolder()` refuses a space outright).
     * `$teamId` is the team doing the deleting: its own space goes through
     * `removeTeamSpace()`; another team's space is refused rather than
     * deleted from under that team.
     *
     * @param int         $folderId GroupFolders folder ID
     * @param string|null $teamId   the team the folder is being deleted for
     * @throws \RuntimeException when the folder is another team's space
     */
    public function deleteGroupFolder(int $folderId, ?string $teamId = null): void {
        $fm = $this->requireFolderManager();

        $this->logger->debug('[TeamHub][GroupFolderService] deleteGroupFolder', [
            'folderId' => $folderId, 'teamId' => $teamId, 'app' => Application::APP_ID,
        ]);

        if ($this->teamSpaceService->isAvailable()) {
            $owner = $this->teamSpaceService->spaceOwnerCircleId($folderId);
            if ($owner !== null) {
                if ($teamId === null || $owner !== $teamId) {
                    throw new \RuntimeException('This folder is the team space of another team and cannot be deleted here.');
                }
                $this->teamSpaceService->removeTeamSpace($teamId);
                $this->logger->info('[TeamHub][GroupFolderService] team space deleted', [
                    'folderId' => $folderId, 'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
                return;
            }
        }

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
     *   [ 'folder_id' => int, 'mount_point' => string, 'root_id' => int ]
     */
    public function findGroupFolderForCircle(string $circleUniqueId): ?array {
        if (!$this->appManager->isInstalled('groupfolders')) {
            return null;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('gf.folder_id', 'gf.mount_point', 'gf.root_id')
                ->from('group_folders', 'gf')
                ->innerJoin('gf', 'group_folders_groups', 'gfg',
                    $qb->expr()->eq('gf.folder_id', 'gfg.folder_id')
                )
                ->where($qb->expr()->eq(
                    'gfg.circle_id',
                    $qb->createNamedParameter($circleUniqueId)
                ))
                // v4.8.24 — a circle can be attached to more than one group
                // folder and this took whatever the engine returned first, which
                // is not stable. `PolicyObservationMapper::teamFolderRootsByTeam()`
                // reads the same relationship in bulk for the compliance scan;
                // without a shared, deterministic rule the two could pick
                // different folders for one team and the tag would be applied to
                // one and read from the other.
                ->orderBy('gf.folder_id', 'ASC')
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
                // v4.8.24 — the fileid of the folder's `files` node, which is
                // what a system tag is assigned to. GroupFolders stores it on
                // the folder row, so no `filecache` lookup is needed; verified
                // on the test instance (root_id 195 → storage 10, path `files`).
                // 0 when GroupFolders has not materialised the folder yet.
                'root_id'     => (int) ($row['root_id'] ?? 0),
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

    /**
     * v3.100.10 — list GroupFolders that this team was PREVIOUSLY
     * connected to but is no longer (status='disconnected' in
     * teamhub_team_app_resources). Only folders present in the team's
     * own resource history are exposed — the picker never leaks folder
     * names the team was never a member of.
     *
     * Used by the picker's "Reconnect" section so team admins can
     * reattach folders they previously disconnected. Attaching a team
     * to a brand-new folder is a separate flow (admin creates a folder
     * via Team Folders → Create).
     *
     * @return array<int,array{folder_id:int, mount_point:string}>
     */
    public function listGroupFoldersAvailableToAttach(string $circleUniqueId): array {
        if (!$this->appManager->isInstalled('groupfolders')) {
            return [];
        }
        try {
            // 1. Find the team's disconnected 'gf:{id}' rows.
            $rqb = $this->db->getQueryBuilder();
            $rres = $rqb->select('resource_id')
                ->from('teamhub_team_app_resources')
                ->where($rqb->expr()->eq('team_id', $rqb->createNamedParameter($circleUniqueId)))
                ->andWhere($rqb->expr()->eq('app_id', $rqb->createNamedParameter('files')))
                ->andWhere($rqb->expr()->eq('status',  $rqb->createNamedParameter('disconnected')))
                ->executeQuery();
            $folderIds = [];
            while ($row = $rres->fetch()) {
                $rid = (string)$row['resource_id'];
                if (str_starts_with($rid, 'gf:')) {
                    $fid = (int)substr($rid, 3);
                    if ($fid > 0) {
                        $folderIds[$fid] = true;
                    }
                }
            }
            $rres->closeCursor();

            if (empty($folderIds)) {
                return [];
            }

            // 2. Verify each folder still exists and is not currently
            //    assigned to this team (defence in depth against edge
            //    cases where the ACL row survived our disconnect).
            $fqb = $this->db->getQueryBuilder();
            $fres = $fqb->select('gf.folder_id', 'gf.mount_point')
                ->from('group_folders', 'gf')
                ->where($fqb->expr()->in(
                    'gf.folder_id',
                    $fqb->createNamedParameter(
                        array_keys($folderIds),
                        IQueryBuilder::PARAM_INT_ARRAY,
                    ),
                ))
                ->orderBy('gf.mount_point', 'ASC')
                ->executeQuery();
            $folders = [];
            while ($row = $fres->fetch()) {
                $folders[(int)$row['folder_id']] = (string)$row['mount_point'];
            }
            $fres->closeCursor();

            // 3. Filter out any that are currently attached (paranoia).
            $aqb = $this->db->getQueryBuilder();
            $ares = $aqb->select('folder_id')
                ->from('group_folders_groups')
                ->where($aqb->expr()->eq(
                    'circle_id',
                    $aqb->createNamedParameter($circleUniqueId),
                ))
                ->executeQuery();
            $attached = [];
            while ($row = $ares->fetch()) {
                $attached[(int)$row['folder_id']] = true;
            }
            $ares->closeCursor();

            $out = [];
            foreach ($folders as $fid => $mp) {
                if (isset($attached[$fid])) {
                    continue;
                }
                // v4.10.1 — a folder that became another team's space since
                // this team let go of it is that team's now; offering it
                // would fail at connect time (Team folders refuses to share
                // a space). Hidden, not shown disabled.
                $owner = $this->teamSpaceService->spaceOwnerCircleId($fid);
                if ($owner !== null && $owner !== $circleUniqueId) {
                    continue;
                }
                $out[] = ['folder_id' => $fid, 'mount_point' => $mp];
            }
            return $out;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][GroupFolderService] listGroupFoldersAvailableToAttach failed', [
                'circleUniqueId' => $circleUniqueId,
                'error' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
            return [];
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
