<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Provisioning;

use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\OpenProject\OpenProjectClient;
use OCA\TeamHub\Service\OpenProject\OpenProjectProvisioningService;
use OCA\TeamHub\Service\Provisioning\Blueprint;
use OCA\TeamHub\Service\Provisioning\MembershipPlanService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Role mapping, member matching and the preview (v4.9.6): what the wizard
 * shows before anything is created, and what needs a decision.
 */
class MembershipPlanServiceTest extends TestCase {

    /** @var array<string, array{id:int, login:string, name:string, email:string}|null> login → OpenProject user */
    private array $opUsers = [];
    /** @var array<string,string> uid → connected OpenProject user id */
    private array $connected = [];

    private function service(): MembershipPlanService {
        $op = $this->createMock(OpenProjectProvisioningService::class);
        $op->method('findUser')->willReturnCallback(fn (string $caller, string $login, string $email = '') => $this->opUsers[$login] ?? null);
        $op->method('findGroup')->willReturnCallback(fn (string $caller, string $name) => $name === 'Staff' ? ['id' => 30, 'name' => 'Staff'] : null);

        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturnCallback(function (string $uid, string $app, string $key, $default = '') {
            if ($app !== OpenProjectClient::INTEGRATION_APP_ID) return $default;
            if ($key === 'user_id') return $this->connected[$uid] ?? '';
            if ($key === 'user_name') return isset($this->connected[$uid]) ? 'Connected ' . $uid : '';
            return $default;
        });
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid) {
            $u = $this->createMock(IUser::class);
            $u->method('getEMailAddress')->willReturn($uid . '@example.test');
            $u->method('getDisplayName')->willReturn(ucfirst($uid));
            return $u;
        });
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('get')->willReturn(null);

        return new MembershipPlanService($op, $config, $users, $groups, $this->createMock(IDBConnection::class), $this->createMock(LoggerInterface::class));
    }

    private static function liveRoles(): array {
        return [['id' => 3, 'name' => 'Project admin'], ['id' => 4, 'name' => 'Member'], ['id' => 5, 'name' => 'Reader']];
    }

    public function testRoleKeysFollowCirclesLevelsAndExternalsAreGuests(): void {
        $this->assertSame('owner', MembershipPlanService::roleKey(9));
        $this->assertSame('admin', MembershipPlanService::roleKey(8));
        $this->assertSame('moderator', MembershipPlanService::roleKey(4));
        $this->assertSame('member', MembershipPlanService::roleKey(1));
        $this->assertSame('guest', MembershipPlanService::roleKey(9, 'email'));
        $this->assertSame('guest', MembershipPlanService::roleKey(1, 'federated'));
        $this->assertSame('admin', MembershipPlanService::roleKey(8, 'group'));
    }

    public function testMappingIsResolvedAgainstLiveRolesCaseInsensitively(): void {
        $bp = Blueprint::fromArray(['roles' => ['mapping' => ['owner' => 'PROJECT ADMIN', 'member' => 'Member', 'moderator' => 'Reviewer', 'guest' => null]]]);
        $resolved = $this->service()->resolveMapping($bp, self::liveRoles());

        $this->assertSame(['name' => 'PROJECT ADMIN', 'id' => 3, 'missing' => false], $resolved['owner']);
        $this->assertSame(['name' => 'Reviewer', 'id' => null, 'missing' => true], $resolved['moderator']);
        $this->assertSame(['name' => null, 'id' => null, 'missing' => false], $resolved['guest']);
        $this->assertSame(['name' => null, 'id' => null, 'missing' => false], $resolved['admin'], 'unmapped keys get no access');
    }

    public function testAMissingRoleIsRefusedOnlyWhenAMemberUsesIt(): void {
        $svc = $this->service();
        $bp = Blueprint::fromArray(['roles' => ['mapping' => ['owner' => 'Project admin', 'moderator' => 'Reviewer']]]);
        $resolved = $svc->resolveMapping($bp, self::liveRoles());
        $svc->assertMappingUsable($resolved, ['owner', 'member']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Reviewer');
        $svc->assertMappingUsable($resolved, ['owner', 'moderator']);
    }

    public function testThePlanMatchesConnectedAccountsFirstThenByLoginAndReportsTheRest(): void {
        $this->connected['alice'] = '11';
        $this->opUsers['bob'] = ['id' => 12, 'login' => 'bob', 'name' => 'Bob', 'email' => 'bob@example.test'];
        $svc = $this->service();
        $bp = Blueprint::defaultsForOpenProject();
        $resolved = $svc->resolveMapping($bp, self::liveRoles());

        $plan = $svc->plan('alice', [
            ['id' => 'alice', 'type' => 'user', 'level' => 9],
            ['id' => 'bob', 'type' => 'user', 'level' => 1],
            ['id' => 'carol', 'type' => 'user', 'level' => 4],
            ['id' => 'dave', 'type' => 'user', 'level' => 1, 'decision' => 'omit'],
            ['id' => 'ext@remote.tld', 'type' => 'federated', 'level' => 1],
            ['id' => 'Staff', 'type' => 'group', 'level' => 1],
        ], $resolved, [
            ['id' => 90, 'principalId' => 12, 'principalType' => 'user', 'principalName' => 'Bob', 'roles' => [['id' => 5, 'name' => 'Reader']]],
        ]);

        $byId = array_column($plan['entries'], null, 'id');
        $this->assertSame('connected', $byId['alice']['matchedBy']);
        $this->assertSame(11, $byId['alice']['principal']['id']);
        $this->assertSame(['id' => 3, 'name' => 'Project admin'], $byId['alice']['openProjectRole']);
        $this->assertSame('add', $byId['alice']['status']);

        $this->assertSame('login', $byId['bob']['matchedBy']);
        $this->assertSame('exists', $byId['bob']['status']);
        $this->assertTrue($byId['bob']['roleDrift'], 'Reader in OpenProject, Member expected');

        $this->assertSame('unmatched', $byId['carol']['status']);
        $this->assertNull($byId['carol']['principal']);
        $this->assertSame('unmatched', $byId['dave']['status']);
        $this->assertSame('omit', $byId['dave']['decision']);

        $this->assertSame('no_access', $byId['ext@remote.tld']['status'], 'guests map to null by default');
        $this->assertSame('group', $byId['Staff']['matchedBy']);
        $this->assertSame(30, $byId['Staff']['principal']['id']);

        $this->assertSame(['carol', 'dave'], $plan['unmatched']);
        $this->assertSame(['carol'], $plan['needsDecision'], 'dave already decided');
        $this->assertEqualsCanonicalizing(['owner', 'member', 'moderator', 'guest'], $plan['usedRoleKeys']);
    }

    public function testAnUnknownDecisionIsRefused(): void {
        $svc = $this->service();
        $this->expectException(ValidationException::class);
        $svc->plan('alice', [['id' => 'x', 'type' => 'user', 'decision' => 'ignore']], $svc->resolveMapping(Blueprint::defaultsForOpenProject(), self::liveRoles()));
    }
}
