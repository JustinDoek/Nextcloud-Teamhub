<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Teams\ITeamManager;
use OCP\Teams\Team;
use Psr\Log\LoggerInterface;

/**
 * TeamSpaceService — TeamHub's one seam to Nextcloud's team-folder API (v4.10.1).
 *
 * Nextcloud 35 gives every team an exclusive "team space": a Team folder
 * (group folder) whose `team_circle_id` names the circle, applicable to that
 * circle alone, ACL-managed by it, with a `.system` subfolder for apps. Core
 * exposes it through `OCP\Teams\ITeamFolderProvider`, reached via
 * `ITeamManager::getTeamFolderProvider()`, and the Team folders app (23+)
 * registers the implementation. On Nextcloud 33/34 neither exists.
 *
 * Every method here answers "not available" on 33/34 — `isAvailable()` is
 * false, lookups return null, writers throw — so a caller branches once and
 * the older versions keep the plain group-folder path they always had. That
 * is the whole version split of the team-space work: it lives in this class
 * and in the `isAvailable()` checks of its callers, nowhere else.
 *
 * Deliberately no type hints on the 35-only classes (`ITeamFolderProvider`,
 * `TeamFolder`): a signature naming a class that does not exist on the
 * running server would fail the moment PHP checked it, and this class has to
 * load on every supported version. Results are plain arrays, which is what
 * services return here anyway.
 *
 * Design: DESIGN.md §2.133.
 */
class TeamSpaceService {

    /** Resolved provider (`?ITeamFolderProvider`), memoised per request. */
    private ?object $provider = null;
    private bool $providerResolved = false;

    /**
     * Per-request memo of `getTeamSpace()`, team id → space or null. The
     * provider's lookup touches storage (it makes sure `.system` exists), and
     * a resource listing asks for the same team several times over; every
     * writer below forgets the team it changed.
     *
     * @var array<string, array{id: int, mount_point: string, quota: int|null}|null>
     */
    private array $spaceMemo = [];

    public function __construct(
        private ITeamManager    $teamManager,
        private IAppConfig      $appConfig,
        private IURLGenerator   $urlGenerator,
        private IDBConnection   $db,
        private LoggerInterface $logger,
    ) {}

    // ──────────────────────────────────────────────────────────────────────
    // Availability
    // ──────────────────────────────────────────────────────────────────────

    /**
     * True when the running Nextcloud offers a team-folder provider: NC 35+
     * with the Team folders app enabled. False on 33/34, and on 35 without
     * Team folders.
     */
    public function isAvailable(): bool {
        return $this->provider() !== null;
    }

    /**
     * The provider, or null. `getTeamFolderProvider()` is `@since 35.0.0`,
     * so on 33/34 the method itself is absent — `method_exists` is the
     * version check, not a version number.
     */
    private function provider(): ?object {
        if ($this->providerResolved) {
            return $this->provider;
        }
        $this->providerResolved = true;
        try {
            if (method_exists($this->teamManager, 'getTeamFolderProvider')) {
                $this->provider = $this->teamManager->getTeamFolderProvider();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamSpaceService] team folder provider lookup failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            $this->provider = null;
        }
        return $this->provider;
    }

    private function requireProvider(): object {
        $provider = $this->provider();
        if ($provider === null) {
            throw new \RuntimeException('Team spaces are not available on this Nextcloud (needs Nextcloud 35 with the Team folders app).');
        }
        return $provider;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Reads
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The team's space, or null when it has none (or spaces are unavailable).
     *
     * @return array{id: int, mount_point: string, quota: int|null}|null
     */
    public function getTeamSpace(string $teamId): ?array {
        $provider = $this->provider();
        if ($provider === null || $teamId === '') {
            return null;
        }
        if (array_key_exists($teamId, $this->spaceMemo)) {
            return $this->spaceMemo[$teamId];
        }
        try {
            $folder = $provider->getTeamFolder($teamId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamSpaceService] getTeamFolder failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
        return $this->spaceMemo[$teamId] = ($folder === null ? null : $this->folderToArray($folder));
    }

    /** Forget a team's memoised space after a write. */
    private function forget(string $teamId): void {
        unset($this->spaceMemo[$teamId]);
    }

    /** True when `$folderId` is this team's own space. */
    public function isTeamSpaceOf(string $teamId, int $folderId): bool {
        $space = $this->getTeamSpace($teamId);
        return $space !== null && $space['id'] === $folderId;
    }

    /**
     * Which circle owns `$folderId` as its space — null for a plain group
     * folder. Reads `group_folders.team_circle_id`, which exists whenever a
     * provider is registered (Team folders 23+); never queried otherwise.
     */
    public function spaceOwnerCircleId(int $folderId): ?string {
        if (!$this->isAvailable() || $folderId <= 0) {
            return null;
        }
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('team_circle_id')
                ->from('group_folders')
                ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1);
            $r   = $qb->executeQuery();
            $row = $r->fetch();
            $r->closeCursor();
            if ($row === false) {
                return null;
            }
            $owner = (string)($row['team_circle_id'] ?? '');
            return $owner === '' ? null : $owner;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamSpaceService] spaceOwnerCircleId failed', [
                'folderId' => $folderId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
    }

    /**
     * Folder ids the provider would accept for `linkTeamSpace()`: applicable
     * to this circle only, and not yet anybody's space.
     *
     * @return int[]
     */
    public function getLinkableFolderIds(string $teamId): array {
        $provider = $this->provider();
        if ($provider === null || $teamId === '') {
            return [];
        }
        try {
            $ids = [];
            foreach ($provider->getLinkableTeamFolders($teamId) as $folder) {
                $ids[] = (int)$folder->getId();
            }
            return $ids;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamSpaceService] getLinkableTeamFolders failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    public function isLinkable(string $teamId, int $folderId): bool {
        return in_array($folderId, $this->getLinkableFolderIds($teamId), true);
    }

    /**
     * Nextcloud's own default quota for new team spaces (Circles' app config
     * `team_folder_default_quota`, bytes, 0 = unlimited). TeamHub creates
     * spaces with the same default NC's wizard would, so an administrator
     * who set it sees it honoured whichever app made the team.
     */
    public function defaultQuota(): int {
        try {
            return max(0, $this->appConfig->getValueInt('circles', 'team_folder_default_quota', 0));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Absolute link to the team's TeamHub home. */
    public function teamLink(string $teamId): string {
        return $this->urlGenerator->linkToRouteAbsolute('teamhub.page.index') . '?team=' . urlencode($teamId);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Writes — every one throws when spaces are unavailable
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Create the team's space — or return the one it already has (the
     * provider is idempotent). The mount point is derived from
     * `$displayName`, so a template's custom folder name still applies.
     *
     * @return array{id: int, mount_point: string, quota: int|null}
     */
    public function createTeamSpace(string $teamId, string $displayName, ?int $quota = null): array {
        $provider = $this->requireProvider();
        $this->forget($teamId);
        $name     = trim($displayName) !== '' ? trim($displayName) : $teamId;
        $team     = new Team($teamId, $name, $this->teamLink($teamId));
        $folder   = $provider->createTeamFolder($team, $quota ?? $this->defaultQuota());
        $this->logger->info('[TeamHub][TeamSpaceService] team space ready', [
            'teamId' => $teamId, 'folderId' => $folder->getId(), 'app' => Application::APP_ID,
        ]);
        return $this->folderToArray($folder);
    }

    /**
     * Make an existing, exclusively applicable group folder the team's space.
     * Metadata only: nothing in the folder moves.
     *
     * @return array{id: int, mount_point: string, quota: int|null}
     */
    public function linkTeamSpace(string $teamId, int $folderId): array {
        $provider = $this->requireProvider();
        $this->forget($teamId);
        $folder   = $provider->linkTeamFolder($teamId, $folderId);
        $this->logger->info('[TeamHub][TeamSpaceService] group folder linked as team space', [
            'teamId' => $teamId, 'folderId' => $folderId, 'app' => Application::APP_ID,
        ]);
        return $this->folderToArray($folder);
    }

    /**
     * Sever the space from its team: the folder and its contents stay, the
     * circle's access row goes, `team_circle_id` is cleared. Null when the
     * team had no space.
     *
     * @return array{id: int, mount_point: string, quota: int|null}|null
     */
    public function unlinkTeamSpace(string $teamId): ?array {
        $provider = $this->requireProvider();
        $this->forget($teamId);
        $folder   = $provider->unlinkTeamFolder($teamId);
        if ($folder === null) {
            return null;
        }
        $this->logger->info('[TeamHub][TeamSpaceService] team space unlinked', [
            'teamId' => $teamId, 'folderId' => $folder->getId(), 'app' => Application::APP_ID,
        ]);
        return $this->folderToArray($folder);
    }

    /**
     * Set the space's quota in bytes (0 = unlimited). The one write the quota
     * workflow makes when a Nextcloud administrator grants a request.
     *
     * @return array{id: int, mount_point: string, quota: int|null}
     */
    public function updateQuota(string $teamId, int $bytes): array {
        $provider = $this->requireProvider();
        $this->forget($teamId);
        $folder = $provider->updateTeamFolderQuota($teamId, max(0, $bytes));
        $this->logger->info('[TeamHub][TeamSpaceService] team space quota updated', [
            'teamId' => $teamId, 'quota' => $bytes, 'app' => Application::APP_ID,
        ]);
        return $this->folderToArray($folder);
    }

    /** Delete the team's space with everything in it. False when it had none. */
    public function removeTeamSpace(string $teamId): bool {
        $provider = $this->requireProvider();
        $this->forget($teamId);
        $removed  = (bool)$provider->removeTeamFolder($teamId);
        $this->logger->info('[TeamHub][TeamSpaceService] team space removed', [
            'teamId' => $teamId, 'removed' => $removed, 'app' => Application::APP_ID,
        ]);
        return $removed;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @param object $folder `OCP\Teams\TeamFolder`
     * @return array{id: int, mount_point: string, quota: int|null}
     */
    private function folderToArray(object $folder): array {
        $id    = (int)$folder->getId();
        $quota = method_exists($folder, 'getQuota') ? $folder->getQuota() : null;
        return [
            'id'          => $id,
            'mount_point' => (string)$folder->getMountPoint(),
            'quota'       => $quota ?? $this->quotaFromTable($id),
        ];
    }

    /**
     * v4.10.4 — Team folders 23.0.1 builds every `TeamFolder` without its
     * quota (`new TeamFolder($id, $mountPoint)` in its TeamSpaceService, so
     * `getQuota()` is always null and the Manage-team quota text never
     * showed, seen on the instance 2026-09-20). Read the column ourselves in
     * that case, the way `spaceOwnerCircleId()` reads `team_circle_id`.
     * Team folders' sentinels — `-4` (SPACE_DEFAULT: the instance default,
     * unlimited unless `groupfolders.quota.default` says otherwise) and `-3`
     * (unlimited) — come back as 0, which is what the provider's contract
     * means by "no quota"; a real limit comes back in bytes.
     */
    private function quotaFromTable(int $folderId): ?int {
        if ($folderId <= 0) {
            return null;
        }
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('quota')
                ->from('group_folders')
                ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1);
            $r   = $qb->executeQuery();
            $row = $r->fetch();
            $r->closeCursor();
            if ($row === false || !isset($row['quota'])) {
                return null;
            }
            $quota = (int)$row['quota'];
            return $quota > 0 ? $quota : 0;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamSpaceService] quotaFromTable failed', [
                'folderId' => $folderId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
    }
}
