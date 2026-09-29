<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\TeamSpace;

use OCA\TeamHub\Service\GroupFolderService;
use OCA\TeamHub\Service\TeamSpaceService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Teams\ITeamManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * `GroupFolderService`'s version split (v4.10.1): with a team-folder
 * provider, creation makes a space, assigning links, removing unlinks and
 * deleting goes through the provider; without one, the plain
 * `FolderManager` calls of Nextcloud 33/34 run as before.
 *
 * The `FolderManager` is a scripted stand-in with the same guards as Team
 * folders 23 (a space cannot be removed, shared or deleted directly).
 */
class GroupFolderServiceTeamSpaceTest extends TestCase {

    private FakeTeamFolderProvider $provider;
    /** @var string[] */
    private array $fmCalls = [];

    protected function setUp(): void {
        if (!interface_exists(\OCP\Teams\ITeamFolderProvider::class)) {
            $this->markTestSkipped('Nextcloud 35 API not present');
        }
        $this->provider = new FakeTeamFolderProvider();
    }

    private function folderManager(): object {
        $test = $this;
        $provider = $this->provider;
        return new class($provider, $test) {
            public function __construct(private FakeTeamFolderProvider $p, private GroupFolderServiceTeamSpaceTest $t) {}
            public function createFolder(string $mountPoint): int {
                $this->t->fm('create:' . $mountPoint);
                return $this->p->addFolder($mountPoint);
            }
            public function addApplicableGroup(int $folderId, string $id): void {
                $this->t->fm("add:$folderId:$id");
                if (($this->p->folders[$folderId]['team'] ?? null) !== null) {
                    throw new \Exception('This team space belongs to a team and its sharing cannot be changed independently');
                }
                $this->p->applicable[$folderId][] = $id;
            }
            public function removeApplicableGroup(int $folderId, string $id): void {
                $this->t->fm("removeApplicable:$folderId:$id");
                if (($this->p->folders[$folderId]['team'] ?? null) === $id) {
                    throw new \Exception('This team space belongs to this team and its access cannot be removed independently');
                }
                $this->p->applicable[$folderId] = array_values(array_diff($this->p->applicable[$folderId] ?? [], [$id]));
            }
            public function removeFolder(int $folderId): void {
                $this->t->fm("removeFolder:$folderId");
                if (($this->p->folders[$folderId]['team'] ?? null) !== null) {
                    throw new \Exception('This team space belongs to a team and cannot be deleted directly');
                }
                unset($this->p->folders[$folderId], $this->p->applicable[$folderId]);
            }
        };
    }

    public function fm(string $call): void {
        $this->fmCalls[] = $call;
    }

    private function service(bool $withProvider): GroupFolderService {
        $manager = $this->createMock(ITeamManager::class);
        $manager->method('getTeamFolderProvider')->willReturn($withProvider ? $this->provider : null);
        $url = $this->createMock(IURLGenerator::class);
        $url->method('linkToRouteAbsolute')->willReturn('https://nc.example/apps/teamhub/');

        // spaceOwnerCircleId() reads group_folders directly; the fake estate
        // answers instead, through a subclass of the real seam.
        $provider = $this->provider;
        $spaces = new class($manager, $this->createMock(IAppConfig::class), $url, $this->createMock(IDBConnection::class), $this->createMock(LoggerInterface::class), $provider) extends TeamSpaceService {
            public function __construct(ITeamManager $m, IAppConfig $c, IURLGenerator $u, IDBConnection $d, LoggerInterface $l, private FakeTeamFolderProvider $p) {
                parent::__construct($m, $c, $u, $d, $l);
            }
            public function spaceOwnerCircleId(int $folderId): ?string {
                return $this->isAvailable() ? ($this->p->folders[$folderId]['team'] ?? null) : null;
            }
        };

        $apps = $this->createMock(IAppManager::class);
        $apps->method('isInstalled')->willReturn(true);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->folderManager());

        return new GroupFolderService(
            $apps,
            $this->createMock(IDBConnection::class),
            $this->createMock(IConfig::class),
            $container,
            $this->createMock(LoggerInterface::class),
            $spaces,
        );
    }

    public function testOn33And34EverythingIsAPlainGroupFolder(): void {
        $s = $this->service(false);
        $this->assertFalse($s->teamSpaces()->isAvailable());

        $created = $s->createTeamFolder('t1', 'Team');
        $this->assertFalse($created['team_space']);
        $this->assertSame(['create:Team', 'add:100:t1'], $this->fmCalls);
        $this->assertNull($this->provider->folders[100]['team']);

        $s->removeCircleFromFolder(100, 't1');
        $this->assertSame('removeApplicable:100:t1', end($this->fmCalls));
        $s->deleteGroupFolder(100, 't1');
        $this->assertSame('removeFolder:100', end($this->fmCalls));
    }

    public function testOn35CreationMakesTheTeamSpace(): void {
        $s = $this->service(true);
        $created = $s->createTeamFolder('t1', 'Marketing');
        $this->assertTrue($created['team_space']);
        $this->assertSame('t1', $this->provider->folders[$created['folder_id']]['team']);
        $this->assertSame('Marketing', $this->provider->folders[$created['folder_id']]['mount_point']);
        $this->assertSame([], $this->fmCalls, 'FolderManager never touched: the provider did it all');
    }

    public function testOn35AssigningAnExclusiveFolderLinksIt(): void {
        $s  = $this->service(true);
        $id = $this->provider->addFolder('Plain');
        $this->assertTrue($s->assignCircleToFolder($id, 't1'));
        $this->assertSame('t1', $this->provider->folders[$id]['team']);
        $this->assertSame(["add:$id:t1"], $this->fmCalls);
        // Assigning again — the team's own space now — is a silent yes.
        $this->assertTrue($s->assignCircleToFolder($id, 't1'));
        $this->assertCount(1, $this->fmCalls, 'no second addApplicableGroup, which Team folders would refuse');
    }

    public function testOn35AFolderSharedWithOthersStaysAPlainFolder(): void {
        $s  = $this->service(true);
        $id = $this->provider->addFolder('Dept', ['grp:x']);
        $this->assertFalse($s->assignCircleToFolder($id, 't1'));
        $this->assertNull($this->provider->folders[$id]['team']);
        $this->assertSame(['grp:x', 't1'], $this->provider->applicable[$id], 'connected all the same');
    }

    public function testOn35AnotherTeamsSpaceIsRefused(): void {
        $s  = $this->service(true);
        $id = $this->provider->addFolder('Theirs', ['t2'], 't2');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('another team');
        $s->assignCircleToFolder($id, 't1');
    }

    public function testOn35RemovingTheSpaceUnlinksItAndKeepsTheFolder(): void {
        $s  = $this->service(true);
        $id = $this->provider->addFolder('Mine', ['t1'], 't1');
        $s->removeCircleFromFolder($id, 't1');
        $this->assertSame([], $this->fmCalls, 'unlink went through the provider');
        $this->assertNull($this->provider->folders[$id]['team']);
        $this->assertArrayHasKey($id, $this->provider->folders);
        $this->assertSame(['unlink:t1'], $this->provider->calls);

        // A plain folder still goes the FolderManager way.
        $plain = $this->provider->addFolder('Plain', ['t1']);
        $s->removeCircleFromFolder($plain, 't1');
        $this->assertSame(["removeApplicable:$plain:t1"], $this->fmCalls);
    }

    public function testOn35DeletingTheTeamsSpaceGoesThroughTheProvider(): void {
        $s  = $this->service(true);
        $id = $this->provider->addFolder('Mine', ['t1'], 't1');
        $s->deleteGroupFolder($id, 't1');
        $this->assertArrayNotHasKey($id, $this->provider->folders);
        $this->assertSame(['remove:t1'], $this->provider->calls);
        $this->assertSame([], $this->fmCalls);
    }

    public function testOn35DeletingAnotherTeamsSpaceIsRefused(): void {
        $s  = $this->service(true);
        $id = $this->provider->addFolder('Theirs', ['t2'], 't2');
        try {
            $s->deleteGroupFolder($id, 't1');
            $this->fail('expected a refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('another team', $e->getMessage());
        }
        $this->assertArrayHasKey($id, $this->provider->folders, 'nothing deleted');
    }
}
