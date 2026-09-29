<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\MyWork;

use OCA\TeamHub\Db\MyWorkStateMapper;
use OCA\TeamHub\MyWork\IWorkProvider;
use OCA\TeamHub\MyWork\Provider\TeamExpiryAdminWorkProvider;
use OCA\TeamHub\MyWork\ProviderRegistry;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\MyWorkConfigService;
use OCA\TeamHub\Service\MyWorkService;
use OCA\TeamHub\Service\TeamService;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `MyWorkService::describeProvidersForViewer()` (v4.9.17): the source group
 * on each provider, and the instance-scoped providers left out for anybody
 * who is not a Nextcloud administrator — hidden, not shown at zero.
 */
class MyWorkServiceProvidersTest extends TestCase {

    /** @var array<string, IWorkProvider> */
    private array $providers = [];

    /**
     * @param bool $isAdmin   a Nextcloud administrator (the Administration group)
     * @param bool $teamAdmin holds admin level in at least one team (the Teams group, v4.10.4)
     */
    private function service(bool $isAdmin, bool $teamAdmin = true): MyWorkService {
        $ordinary = $this->createMock(IWorkProvider::class);
        $admin    = $this->createMock(TeamExpiryAdminWorkProvider::class);
        $admin->method('isInstanceScoped')->willReturn(true);
        $this->providers = [
            'deck'             => $ordinary,
            'approval'         => $ordinary,
            'teamadmin'        => $ordinary,
            'teamexpiry_admin' => $admin,
            'file_review'      => $ordinary,
        ];

        $registry = $this->createMock(ProviderRegistry::class);
        $registry->method('all')->willReturn($this->providers);
        $registry->method('get')->willReturnCallback(fn (string $id) => $this->providers[$id] ?? null);
        $registry->method('describeAll')->willReturn(array_map(
            static fn (string $id): array => ['id' => $id, 'name' => ucfirst($id), 'enabled' => true, 'available' => true],
            array_keys($this->providers),
        ));

        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturn($isAdmin);

        $teams = $this->createMock(TeamService::class);
        $teams->method('getUserTeams')->willReturn($teamAdmin
            ? [['id' => 't1', 'name' => 'One', 'level' => 1], ['id' => 't2', 'name' => 'Two', 'level' => 8]]
            : [['id' => 't1', 'name' => 'One', 'level' => 1], ['id' => 't3', 'name' => 'Three', 'level' => 4]]);

        return new MyWorkService(
            $registry,
            $this->createMock(MyWorkConfigService::class),
            $this->createMock(MyWorkStateMapper::class),
            $teams,
            $this->createMock(AuditService::class),
            $this->createMock(ICacheFactory::class),
            $groups,
            $this->createMock(IL10N::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testAMemberIsNotToldAboutTheAdministrationProviders(): void {
        $listed = $this->service(isAdmin: false)->describeProvidersForViewer('jaap');

        $this->assertSame(['deck', 'approval', 'teamadmin', 'file_review'], array_column($listed, 'id'));
        $this->assertSame(
            [null, 'files', 'teams', 'files'],
            array_map(static fn (array $p) => $p['group'], $listed),
        );
    }

    public function testAnAdministratorSeesEverythingWithTheAdministrationGroup(): void {
        $listed = $this->service(isAdmin: true)->describeProvidersForViewer('lieke');

        $this->assertSame(['deck', 'approval', 'teamadmin', 'teamexpiry_admin', 'file_review'], array_column($listed, 'id'));
        $byId = array_column($listed, 'group', 'id');
        $this->assertSame('administration', $byId['teamexpiry_admin']);
        $this->assertNull($byId['deck']);
    }

    public function testAMemberWhoAdministersNoTeamIsNotToldAboutTheTeamsGroup(): void {
        // v4.10.4 — level 4 (moderator) in one team, member in another: the
        // Teams providers would return nothing, so no tab and no card.
        $listed = $this->service(isAdmin: false, teamAdmin: false)->describeProvidersForViewer('jaap');
        $this->assertSame(['deck', 'approval', 'file_review'], array_column($listed, 'id'));
        $this->assertNotContains('teams', array_map(static fn (array $p) => $p['group'], $listed));
    }

    public function testANextcloudAdministratorWhoAdministersNoTeamKeepsAdministrationButNotTeams(): void {
        $listed = $this->service(isAdmin: true, teamAdmin: false)->describeProvidersForViewer('lieke');
        $this->assertSame(['deck', 'approval', 'teamexpiry_admin', 'file_review'], array_column($listed, 'id'));
    }

    public function testTheUnfilteredListIsUntouchedForTheAdminPage(): void {
        // The admin page keeps describeProviders(): no group key, no filtering.
        $all = $this->service(isAdmin: false)->describeProviders();
        $this->assertCount(5, $all);
        $this->assertArrayNotHasKey('group', $all[0]);
    }
}
