<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\People;

use OCA\TeamHub\Service\PersonSublineService;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\Accounts\PropertyDoesNotExistException;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `PersonSublineService` (v4.10.7): the line that tells two people with the
 * same name apart — the administrator's fields in order, two values, groups
 * as one value, private fields hidden from members but not from admins.
 */
class PersonSublineServiceTest extends TestCase {

    private string $configured = '';
    /** @var array<string, array<string, array{0:string,1:string}>> uid => property => [value, scope] */
    private array $profiles = [];
    /** @var array<string, string[]> uid => group display names */
    private array $groups = [];
    /** @var array<string, string> uid => display name */
    private array $names = [];

    private function service(): PersonSublineService {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->configured !== '' ? $this->configured : $default);

        $am = $this->createMock(IAccountManager::class);
        $am->method('getAccount')->willReturnCallback(function (IUser $user): IAccount {
            $props   = $this->profiles[$user->getUID()] ?? [];
            $account = $this->createMock(IAccount::class);
            $account->method('getProperty')->willReturnCallback(function (string $name) use ($props): IAccountProperty {
                if (!isset($props[$name])) {
                    throw new PropertyDoesNotExistException($name);
                }
                $p = $this->createMock(IAccountProperty::class);
                $p->method('getValue')->willReturn($props[$name][0]);
                $p->method('getScope')->willReturn($props[$name][1]);
                return $p;
            });
            return $account;
        });

        $gm = $this->createMock(IGroupManager::class);
        $gm->method('getUserGroups')->willReturnCallback(function (IUser $user): array {
            $out = [];
            foreach ($this->groups[$user->getUID()] ?? [] as $name) {
                $g = $this->createMock(IGroup::class);
                $g->method('getDisplayName')->willReturn($name);
                $g->method('getGID')->willReturn($name);
                $out[] = $g;
            }
            return $out;
        });

        $um = $this->createMock(IUserManager::class);
        $um->method('get')->willReturnCallback(fn (string $uid): ?IUser => isset($this->names[$uid]) ? $this->user($uid) : null);

        return new PersonSublineService($config, $am, $gm, $um, $this->createMock(LoggerInterface::class));
    }

    private function user(string $uid): IUser {
        $u = $this->createMock(IUser::class);
        $u->method('getUID')->willReturn($uid);
        $u->method('getDisplayName')->willReturn($this->names[$uid] ?? $uid);
        $u->method('getManagerUids')->willReturn(isset($this->profiles[$uid]['__manager']) ? [$this->profiles[$uid]['__manager'][0]] : []);
        return $u;
    }

    public function testDefaultIsJobTitleThenOrganisation(): void {
        $this->names['jari'] = 'Jari Feet';
        $this->profiles['jari'] = [
            IAccountManager::PROPERTY_ROLE         => ['Controller', IAccountManager::SCOPE_LOCAL],
            IAccountManager::PROPERTY_ORGANISATION => ['Finance', IAccountManager::SCOPE_LOCAL],
        ];
        $this->groups['jari'] = ['finance', 'everyone'];

        $this->assertSame('Controller · Finance', $this->service()->sublineFor($this->user('jari')));
    }

    public function testGroupsAreNotInTheDefault(): void {
        // 4.10.9 — "JaapAgent · TeamHub creators": a group says which club
        // somebody joined, not who they are. Profile empty → line empty.
        $this->names['jari'] = 'Jari Feet';
        $this->groups['jari'] = ['Finance', 'Utrecht office'];

        $this->assertSame('', $this->service()->sublineFor($this->user('jari')));
    }

    public function testGroupsWhenTickedAreOneValueOfAtMostThreeNames(): void {
        $this->configured = 'role,groups';
        $this->names['jari'] = 'Jari Feet';
        $this->groups['jari'] = ['Finance', 'Utrecht office', 'Everyone', 'A fourth group'];

        $this->assertSame('Finance, Utrecht office, Everyone', $this->service()->sublineFor($this->user('jari')));

        $this->profiles['jari'] = [
            IAccountManager::PROPERTY_ROLE => ['Controller', IAccountManager::SCOPE_LOCAL],
        ];
        $this->groups['jari'] = ['Finance'];
        $this->assertSame('Controller · Finance', $this->service()->sublineFor($this->user('jari')));
    }

    public function testAPrivateFieldIsHiddenFromMembersButNotFromAnAdmin(): void {
        $this->names['jari'] = 'Jari Feet';
        $this->profiles['jari'] = [
            IAccountManager::PROPERTY_ROLE         => ['Controller', IAccountManager::SCOPE_PRIVATE],
            IAccountManager::PROPERTY_ORGANISATION => ['Finance', IAccountManager::SCOPE_LOCAL],
        ];
        $svc = $this->service();

        $this->assertSame('Finance', $svc->sublineFor($this->user('jari'), false));
        $this->assertSame('Controller · Finance', $svc->sublineFor($this->user('jari'), true));
    }

    public function testTheAdministratorsOrderWins(): void {
        $this->configured = 'groups,role';
        $this->names['jari'] = 'Jari Feet';
        $this->profiles['jari'] = [
            IAccountManager::PROPERTY_ROLE         => ['Controller', IAccountManager::SCOPE_LOCAL],
            IAccountManager::PROPERTY_ORGANISATION => ['Finance', IAccountManager::SCOPE_LOCAL],
        ];
        $this->groups['jari'] = ['Finance'];

        $this->assertSame('Finance · Controller', $this->service()->sublineFor($this->user('jari')));
    }

    public function testNoneMeansNoSublineAndNothingFilledInMeansEmpty(): void {
        $this->names['jari'] = 'Jari Feet';
        $this->profiles['jari'] = [IAccountManager::PROPERTY_ROLE => ['Controller', IAccountManager::SCOPE_LOCAL]];

        $this->configured = PersonSublineService::NONE;
        $this->assertSame('', $this->service()->sublineFor($this->user('jari')));
        $this->assertSame([], $this->service()->sublinesFor(['jari']));

        $this->configured = '';
        $this->names['empty'] = 'Nobody Special';
        $this->assertSame('', $this->service()->sublineFor($this->user('empty')), 'no implicit uid fallback');
    }

    public function testUidAndEmailAreOptInFields(): void {
        $this->configured = 'uid,email';
        $this->names['jfeet2'] = 'Jari Feet';
        $this->profiles['jfeet2'] = [IAccountManager::PROPERTY_EMAIL => ['jfeet2@company.com', IAccountManager::SCOPE_LOCAL]];

        $this->assertSame('jfeet2 · jfeet2@company.com', $this->service()->sublineFor($this->user('jfeet2')));
    }

    public function testManagerIsResolvedToADisplayName(): void {
        $this->configured = 'manager';
        $this->names['jari'] = 'Jari Feet';
        $this->names['anna'] = 'Anna Boss';
        $this->profiles['jari'] = ['__manager' => ['anna', '']];

        $this->assertSame('Anna Boss', $this->service()->sublineFor($this->user('jari')));
    }

    public function testSanitizeAndStorableValue(): void {
        $this->assertSame(['role', 'groups'], PersonSublineService::sanitizeFields(['role', 'bogus', 'groups', 'role']));
        $this->assertSame('role,groups', PersonSublineService::storableValue(['role', 'groups']));
        $this->assertSame(PersonSublineService::NONE, PersonSublineService::storableValue([]));
        $this->assertSame(PersonSublineService::NONE, PersonSublineService::storableValue(['bogus']));
    }

    public function testSublinesForSkipsUnknownAccounts(): void {
        $this->names['jari'] = 'Jari Feet';
        $this->profiles['jari'] = [IAccountManager::PROPERTY_ROLE => ['Controller', IAccountManager::SCOPE_LOCAL]];

        $this->assertSame(['jari' => 'Controller'], $this->service()->sublinesFor(['jari', 'ghost']));
    }
}
