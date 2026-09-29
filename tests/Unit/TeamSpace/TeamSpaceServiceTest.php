<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\TeamSpace;

use OCA\TeamHub\Service\TeamSpaceService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Teams\ITeamManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `TeamSpaceService` (v4.10.1): the one seam to Nextcloud's team-folder API.
 * Unavailable without a provider; with one, plain-array answers over the
 * provider's objects, memoised per team and forgotten on every write.
 */
class TeamSpaceServiceTest extends TestCase {

    private FakeTeamFolderProvider $provider;

    protected function setUp(): void {
        if (!interface_exists(\OCP\Teams\ITeamFolderProvider::class)) {
            $this->markTestSkipped('Nextcloud 35 API not present');
        }
        $this->provider = new FakeTeamFolderProvider();
    }

    private function service(?object $provider, int $defaultQuota = 0): TeamSpaceService {
        $manager = $this->createMock(ITeamManager::class);
        $manager->method('getTeamFolderProvider')->willReturn($provider);
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueInt')->willReturn($defaultQuota);
        $url = $this->createMock(IURLGenerator::class);
        $url->method('linkToRouteAbsolute')->willReturn('https://nc.example/apps/teamhub/');
        return new TeamSpaceService(
            $manager,
            $appConfig,
            $url,
            $this->createMock(IDBConnection::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testUnavailableWithoutAProviderAnswersNullAndRefusesWrites(): void {
        $s = $this->service(null);
        $this->assertFalse($s->isAvailable());
        $this->assertNull($s->getTeamSpace('t1'));
        $this->assertFalse($s->isTeamSpaceOf('t1', 5));
        $this->assertSame([], $s->getLinkableFolderIds('t1'));
        $this->assertNull($s->spaceOwnerCircleId(5), 'never queries group_folders without a provider');
        $this->expectException(\RuntimeException::class);
        $s->createTeamSpace('t1', 'Team');
    }

    public function testCreateIsIdempotentAndNamesTheSpaceAfterTheGivenName(): void {
        $s = $this->service($this->provider, 1024);
        $this->assertTrue($s->isAvailable());

        $space = $s->createTeamSpace('t1', 'Marketing (custom)');
        $this->assertSame('Marketing (custom)', $space['mount_point']);
        $this->assertSame(1024, $space['quota'], 'Circles\' default quota is honoured');
        $this->assertSame('create:t1:Marketing (custom):1024', $this->provider->calls[0]);

        $again = $s->createTeamSpace('t1', 'Something else');
        $this->assertSame($space['id'], $again['id'], 'the provider returns the existing space');
        $this->assertTrue($s->isTeamSpaceOf('t1', $space['id']));
        $this->assertFalse($s->isTeamSpaceOf('t2', $space['id']));
    }

    public function testLinkableUnlinkAndRemove(): void {
        $s  = $this->service($this->provider);
        $id = $this->provider->addFolder('Plain', ['t1']);
        $shared = $this->provider->addFolder('Shared', ['t1', 'admin']);

        $this->assertSame([$id], $s->getLinkableFolderIds('t1'));
        $this->assertTrue($s->isLinkable('t1', $id));
        $this->assertFalse($s->isLinkable('t1', $shared), 'shared with a group: not linkable');

        $linked = $s->linkTeamSpace('t1', $id);
        $this->assertSame($id, $linked['id']);
        $this->assertSame($id, $s->getTeamSpace('t1')['id'], 'memo forgotten on write');

        $unlinked = $s->unlinkTeamSpace('t1');
        $this->assertSame($id, $unlinked['id']);
        $this->assertNull($s->getTeamSpace('t1'));
        $this->assertArrayHasKey($id, $this->provider->folders, 'unlink keeps the folder');
        $this->assertSame([], $this->provider->applicable[$id], 'and takes the circle off it, like Team folders does');

        // Reconnecting is assign + link (GroupFolderService::assignCircleToFolder).
        $this->provider->applicable[$id] = ['t1'];
        $s->linkTeamSpace('t1', $id);
        $this->assertTrue($s->removeTeamSpace('t1'));
        $this->assertArrayNotHasKey($id, $this->provider->folders, 'remove deletes it');
        $this->assertFalse($s->removeTeamSpace('t1'), 'nothing left to remove');
    }

    public function testGetTeamSpaceIsMemoisedPerTeam(): void {
        $s = $this->service($this->provider);
        $s->createTeamSpace('t1', 'A');
        $first = $s->getTeamSpace('t1');
        // Mutate behind the service's back: the memo still answers.
        $this->provider->folders[$first['id']]['mount_point'] = 'renamed';
        $this->assertSame('A', $s->getTeamSpace('t1')['mount_point']);
        // A write on the team forgets it.
        $s->unlinkTeamSpace('t1');
        $this->assertNull($s->getTeamSpace('t1'));
    }
}
