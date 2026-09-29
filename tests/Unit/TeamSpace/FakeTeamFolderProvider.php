<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\TeamSpace;

use OCP\Teams\ITeamFolderProvider;
use OCP\Teams\Team;
use OCP\Teams\TeamFolder;
use OCP\Teams\TeamResource;

/**
 * An in-memory `ITeamFolderProvider` with the same rules as Team folders 23's
 * `TeamSpaceProvider`: one space per team, a folder linkable only when it
 * is nobody's space and applicable to that circle alone, unlink keeps the
 * folder, remove deletes it. The tests script the folder estate through
 * `$folders` / `$applicable` and read back what the code under test did.
 *
 * Only ever instantiated on a Nextcloud that has the interface (35+); the
 * tests skip themselves otherwise.
 */
class FakeTeamFolderProvider implements ITeamFolderProvider {

    /** @var array<int, array{mount_point: string, quota: int, team: ?string}> */
    public array $folders = [];

    /** @var array<int, string[]> folder id → applicable group/circle ids */
    public array $applicable = [];

    /** @var string[] a log of the writes, for assertions */
    public array $calls = [];

    private int $nextId = 100;

    public function addFolder(string $mountPoint, array $applicable = [], ?string $team = null, int $quota = 0): int {
        $id = $this->nextId++;
        $this->folders[$id]    = ['mount_point' => $mountPoint, 'quota' => $quota, 'team' => $team];
        $this->applicable[$id] = $applicable;
        return $id;
    }

    public function getId(): string {
        return 'groupfolders';
    }

    public function getName(): string {
        return 'Team spaces';
    }

    public function getIconSvg(): string {
        return '<svg/>';
    }

    public function getSharedWith(string $teamId): array {
        return [];
    }

    public function isSharedWithTeam(string $teamId, string $resourceId): bool {
        return false;
    }

    public function getTeamsForResource(string $resourceId): array {
        return [];
    }

    public function getLinkableTeamFolders(string $circleId): array {
        $out = [];
        foreach ($this->folders as $id => $f) {
            if ($f['team'] === null && ($this->applicable[$id] ?? []) === [$circleId]) {
                $out[] = new TeamFolder($id, $f['mount_point'], $f['quota']);
            }
        }
        return $out;
    }

    public function linkTeamFolder(string $circleId, int $folderId): TeamFolder {
        $this->calls[] = "link:$circleId:$folderId";
        if ($this->spaceOf($circleId) !== null) {
            return $this->getTeamFolder($circleId);
        }
        foreach ($this->getLinkableTeamFolders($circleId) as $f) {
            if ($f->getId() === $folderId) {
                $this->folders[$folderId]['team'] = $circleId;
                return $f;
            }
        }
        throw new \InvalidArgumentException('The folder is not available for this team');
    }

    public function getTeamFolder(string $teamId): ?TeamFolder {
        $id = $this->spaceOf($teamId);
        return $id === null ? null : new TeamFolder($id, $this->folders[$id]['mount_point'], $this->folders[$id]['quota']);
    }

    public function createTeamFolder(Team $team, int $quota = 0): TeamFolder {
        $this->calls[] = 'create:' . $team->getId() . ':' . $team->getDisplayName() . ':' . $quota;
        $existing = $this->getTeamFolder($team->getId());
        if ($existing !== null) {
            return $existing;
        }
        $id = $this->addFolder($team->getDisplayName(), [$team->getId()], $team->getId(), $quota);
        return new TeamFolder($id, $team->getDisplayName(), $quota);
    }

    public function updateTeamFolderQuota(string $teamId, int $quota): TeamFolder {
        $id = $this->spaceOf($teamId);
        if ($id === null) {
            throw new \RuntimeException('No team space linked to this team');
        }
        $this->folders[$id]['quota'] = $quota;
        return $this->getTeamFolder($teamId);
    }

    public function unlinkTeamFolder(string $teamId): ?TeamFolder {
        $this->calls[] = "unlink:$teamId";
        $id = $this->spaceOf($teamId);
        if ($id === null) {
            return null;
        }
        $folder = $this->getTeamFolder($teamId);
        $this->folders[$id]['team'] = null;
        $this->applicable[$id] = array_values(array_diff($this->applicable[$id] ?? [], [$teamId]));
        return $folder;
    }

    public function removeTeamFolder(string $teamId): bool {
        $this->calls[] = "remove:$teamId";
        $id = $this->spaceOf($teamId);
        if ($id === null) {
            return false;
        }
        unset($this->folders[$id], $this->applicable[$id]);
        return true;
    }

    private function spaceOf(string $teamId): ?int {
        foreach ($this->folders as $id => $f) {
            if ($f['team'] === $teamId) {
                return $id;
            }
        }
        return null;
    }
}
