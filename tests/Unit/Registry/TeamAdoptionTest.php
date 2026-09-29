<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Registry;

use OCA\TeamHub\BackgroundJob\TeamAdoptionProvisionJob;
use OCA\TeamHub\Db\TeamAdoptionMapper;
use OCA\TeamHub\Db\TeamTemplateMapper;
use OCA\TeamHub\Db\TeamTypeMapper;
use OCA\TeamHub\Db\WorkflowStepMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Exception\WorkflowTransitionException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\PolicyService;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\TeamAdoptionDecisionService;
use OCA\TeamHub\Service\TeamAdoptionService;
use OCA\TeamHub\Service\TeamRegistryService;
use OCA\TeamHub\Service\Workflow\WorkflowEngine;
use OCA\TeamHub\Service\Workflow\WorkflowLicenceTier;
use OCA\TeamHub\Workflow\Definition\TeamAdoptionDefinition;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Teams made outside TeamHub (v4.10.50, DESIGN §2.149): Justin's four
 * routes, what accepting writes, a decline that is final, and who may
 * decide.
 */
class TeamAdoptionTest extends TestCase {

    private InMemoryTeamAdoptionMapper $adoptions;

    /** @var list<array{0: string, 1: string, 2: ?string}> team, origin-owner, lock */
    private array $registered = [];
    /** @var list<array{0: string, 1: string}> */
    private array $types = [];
    /** @var list<array{0: string, 1: ?string, 2: ?string, 3: ?string}> */
    private array $policies = [];
    /** @var list<array<string,mixed>> */
    private array $jobs = [];
    /** @var list<string> */
    private array $auditEvents = [];
    /** @var list<array{0: string, 1: string}> subject, user */
    private array $notices = [];
    /** @var list<array{0: string, 1: string, 2: string}> definition, team, initiator */
    private array $systemStarts = [];

    private string $creatorGroup = 'creators';
    private bool $licensed = true;
    private ?string $holder = null;
    /** @var array<string,true> */
    private array $circles = ['c1' => true, 'c2' => true];
    /** @var array<string,true> uids that are desk members of the holder */
    private array $deskMembers = ['desk1' => true];

    protected function setUp(): void {
        $this->adoptions = new InMemoryTeamAdoptionMapper($this->createMock(IDBConnection::class));
    }

    private function l(): IL10N {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => vsprintf($text, $p));
        return $l;
    }

    private function groupManager(): IGroupManager {
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'ncadmin');
        $groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $gid): bool => $uid === 'ncadmin' && $gid === 'admin');
        $admin = $this->createMock(IUser::class);
        $admin->method('getUID')->willReturn('ncadmin');
        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn([$admin]);
        $groups->method('get')->willReturn($group);
        return $groups;
    }

    private function serviceTeams(): ServiceTeamService {
        $st = $this->createMock(ServiceTeamService::class);
        $st->method('serviceTeamForDefinition')->willReturnCallback(fn (): ?string => $this->holder);
        $st->method('isEligibleAgent')->willReturnCallback(fn (string $uid, string $team): bool => $team === $this->holder && isset($this->deskMembers[$uid]));
        return $st;
    }

    private function decisions(): TeamAdoptionDecisionService {
        $registry = $this->createMock(TeamRegistryService::class);
        $registry->method('registerAdopted')->willReturnCallback(function (string $team, string $owner): void {
            $this->registered[] = [$team, $owner];
        });
        $types = $this->createMock(TeamTypeMapper::class);
        $types->method('upsert')->willReturnCallback(function (string $team, string $type): void {
            $this->types[] = [$team, $type];
        });
        $templates = $this->createMock(TeamTemplateMapper::class);
        $templates->method('findAll')->willReturn([
            ['templateKey' => 'collaboration', 'label' => 'Collaboration'],
            ['templateKey' => 'project',       'label' => 'Project'],
            ['templateKey' => 'openproject',   'label' => 'OpenProject'],
            ['templateKey' => 'service',       'label' => 'Service'],
        ]);
        $policy = $this->createMock(PolicyService::class);
        $policy->method('defaultProfileForTemplate')->willReturnCallback(static fn (?string $t): ?string => $t === 'project' ? 'internal' : 'standard');
        $policy->method('assignAtCreation')->willReturnCallback(function (string $team, ?string $profile, ?string $template, ?string $actor): array {
            $this->policies[] = [$team, $profile, $template, $actor];
            return [];
        });
        $audit = $this->createMock(AuditService::class);
        $audit->method('log')->willReturnCallback(function (string $team, string $event): void {
            $this->auditEvents[] = $event;
        });
        $jobs = $this->createMock(IJobList::class);
        $jobs->method('add')->willReturnCallback(function (string $class, mixed $arg): void {
            $this->jobs[] = ['class' => $class, 'arg' => $arg];
        });
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(2_000_000);

        $test = $this;
        return new class($this->adoptions, $registry, $types, $templates, $policy, $audit, $this->serviceTeams(), $this->groupManager(), $jobs, $this->createMock(IDBConnection::class), $time, $this->l(), $this->createMock(LoggerInterface::class), $test) extends TeamAdoptionDecisionService {
            public function __construct($a, $r, $ty, $te, $p, $au, $st, $g, $j, $db, $ti, $l, $lo, private TeamAdoptionTest $t) {
                parent::__construct($a, $r, $ty, $te, $p, $au, $st, $g, $j, $db, $ti, $l, $lo);
            }
            public function circleExists(string $teamId): bool {
                return $this->t->circleExists($teamId);
            }
        };
    }

    /** @internal for the anonymous subclass */
    public function circleExists(string $teamId): bool {
        return isset($this->circles[$teamId]);
    }

    private function service(): TeamAdoptionService {
        $engine = $this->createMock(WorkflowEngine::class);
        $engine->method('createForSystem')->willReturnCallback(function (string $def, string $team, string $uid): array {
            $this->systemStarts[] = [$def, $team, $uid];
            return ['id' => 77];
        });
        $tier = $this->createMock(WorkflowLicenceTier::class);
        $tier->method('isFull')->willReturnCallback(fn (): bool => $this->licensed);
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $key === 'createTeamGroup' ? $this->creatorGroup : $default);
        $users = $this->createMock(IUserManager::class);
        $users->method('getDisplayName')->willReturnCallback(static fn (string $uid): string => ucfirst($uid));
        $notifications = $this->createMock(INotificationManager::class);
        $notifications->method('createNotification')->willReturnCallback(function (): INotification {
            $n = $this->createMock(INotification::class);
            $state = new \stdClass();
            foreach (['setApp', 'setDateTime', 'setObject'] as $m) {
                $n->method($m)->willReturnSelf();
            }
            $n->method('setUser')->willReturnCallback(function (string $u) use ($n, $state) { $state->user = $u; return $n; });
            $n->method('setSubject')->willReturnCallback(function (string $s) use ($n, $state) { $state->subject = $s; return $n; });
            $n->method('getUser')->willReturnCallback(fn () => $state->user ?? '');
            $n->method('getSubject')->willReturnCallback(fn () => $state->subject ?? '');
            return $n;
        });
        $notifications->method('notify')->willReturnCallback(function (INotification $n): void {
            $this->notices[] = [$n->getSubject(), $n->getUser()];
        });
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(2_000_000);

        return new TeamAdoptionService(
            $this->adoptions,
            $this->decisions(),
            $engine,
            $this->createMock(WorkflowStepMapper::class),
            $tier,
            $this->serviceTeams(),
            $this->servicePolicy(),
            $this->createMock(IDBConnection::class),
            $config,
            $this->groupManager(),
            $users,
            $this->createMock(IJobList::class),
            $notifications,
            $time,
            $this->l(),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function servicePolicy(): PolicyService {
        $policy = $this->createMock(PolicyService::class);
        $policy->method('profileExists')->willReturnCallback(static fn (string $key): bool => in_array($key, ['standard', 'internal'], true));
        $policy->method('defaultProfileForTemplate')->willReturnCallback(static fn (?string $t): ?string => $t === 'project' ? 'internal' : 'standard');
        $policy->method('creationContext')->willReturn(['profiles' => []]);
        return $policy;
    }

    private function candidate(string $team = 'c1', string $owner = 'olga'): array {
        return ['teamId' => $team, 'name' => 'Team ' . $team, 'owner' => $owner];
    }

    // ── Routing ────────────────────────────────────────────────────────

    public function testWithoutACreatorGroupATeamIsAcceptedByItself(): void {
        $this->creatorGroup = '';
        self::assertTrue($this->service()->route($this->candidate(), 1));

        $row = $this->adoptions->findByTeam('c1');
        self::assertSame(TeamAdoptionMapper::STATUS_ACCEPTED, $row['status']);
        self::assertSame(TeamAdoptionMapper::ROUTE_AUTO, $row['route']);
        self::assertSame('', $row['decidedBy'], 'nobody decided');
        self::assertSame([['c1', 'olga']], $this->registered);
        self::assertSame([['c1', 'collaboration']], $this->types, 'the default template');
        self::assertSame([['c1', null, 'collaboration', 'olga']], $this->policies, 'the template default policy, in the owner\'s name');
        self::assertSame(TeamAdoptionProvisionJob::class, $this->jobs[0]['class']);
        self::assertSame([['team_adoption_accepted', 'olga']], $this->notices);
        self::assertSame([], $this->systemStarts);
    }

    public function testATeamHoldingTheServicesGetsARequestInItsQueue(): void {
        $this->holder = 'desk';
        $this->service()->route($this->candidate(), 1);

        $row = $this->adoptions->findByTeam('c1');
        self::assertSame(TeamAdoptionMapper::STATUS_PENDING, $row['status']);
        self::assertSame(TeamAdoptionMapper::ROUTE_DESK, $row['route']);
        self::assertSame(77, $row['workflowId']);
        self::assertSame([[TeamAdoptionDefinition::KEY, 'c1', 'olga']], $this->systemStarts);
        self::assertSame([], $this->notices, 'the request is the message');
    }

    public function testWithoutAHolderTheAdministratorsGetATaskInMyWork(): void {
        $this->service()->route($this->candidate(), 1);

        self::assertSame(TeamAdoptionMapper::ROUTE_ADMIN, $this->adoptions->findByTeam('c1')['route']);
        self::assertCount(1, $this->systemStarts);
    }

    public function testUnlicensedTheGridIsTheOnlyPlaceAndTheAdministratorsAreTold(): void {
        $this->licensed = false;
        $this->holder = 'desk';
        $this->service()->route($this->candidate(), 1);

        self::assertSame(TeamAdoptionMapper::ROUTE_GRID, $this->adoptions->findByTeam('c1')['route']);
        self::assertSame([], $this->systemStarts);
        self::assertSame([['team_adoption_pending', 'ncadmin']], $this->notices);
    }

    public function testATeamWithARowIsNeverRoutedTwice(): void {
        $service = $this->service();
        self::assertTrue($service->route($this->candidate(), 1));
        self::assertFalse($service->route($this->candidate(), 2));
        self::assertCount(1, $this->adoptions->rows);
    }

    // ── Deciding in the grid ──────────────────────────────────────────

    public function testAnAdministratorAcceptsFromTheGridWithTheChosenTemplateAndPolicy(): void {
        $this->licensed = false;
        $service = $this->service();
        $service->route($this->candidate(), 1);
        $id = (int)$this->adoptions->findByTeam('c1')['id'];

        $row = $service->accept($id, 'ncadmin', 'project', 'internal');

        self::assertSame(TeamAdoptionMapper::STATUS_ACCEPTED, $row['status']);
        self::assertSame('project', $row['templateKey']);
        self::assertSame([['c1', 'project']], $this->types);
        self::assertSame([['c1', 'internal', 'project', 'ncadmin']], $this->policies);
        self::assertContains('team.adopted', $this->auditEvents);
    }

    public function testATemplateTheGridDoesNotOfferIsRefused(): void {
        $this->licensed = false;
        $service = $this->service();
        $service->route($this->candidate(), 1);

        $this->expectException(ValidationException::class);
        $service->accept((int)$this->adoptions->findByTeam('c1')['id'], 'ncadmin', 'openproject', '');
    }

    public function testDecliningNeedsAReasonAndIsFinal(): void {
        $this->licensed = false;
        $service = $this->service();
        $service->route($this->candidate(), 1);
        $id = (int)$this->adoptions->findByTeam('c1')['id'];

        try {
            $service->decline($id, 'ncadmin', '  ');
            self::fail('a decline without a reason was accepted');
        } catch (ValidationException) {
        }

        $row = $service->decline($id, 'ncadmin', 'Not a real team');
        self::assertSame(TeamAdoptionMapper::STATUS_DECLINED, $row['status']);
        self::assertSame([], $this->registered, 'nothing registered');
        self::assertContains(['team_adoption_declined', 'olga'], $this->notices);

        $this->expectException(WorkflowTransitionException::class);
        $service->accept($id, 'ncadmin', '', '');
    }

    public function testSomebodyOutsideTheDeskAndNotAnAdministratorCannotUseTheGrid(): void {
        $this->holder = 'desk';
        $this->expectException(AccessDeniedException::class);
        $this->service()->grid('jaap');
    }

    public function testADeskMemberAndAnAdministratorMayDecide(): void {
        $this->holder = 'desk';
        $decisions = $this->decisions();
        self::assertTrue($decisions->mayDecide('desk1'));
        self::assertTrue($decisions->mayDecide('ncadmin'));
        self::assertFalse($decisions->mayDecide('jaap'));

        $this->holder = null;
        self::assertFalse($this->decisions()->mayDecide('desk1'), 'a desk that no longer holds the service');
    }

    // ── The decision itself ───────────────────────────────────────────

    public function testAcceptingTwiceIsTheSameAcceptance(): void {
        $id = (int)$this->adoptions->insert('c1', 'Team c1', 'olga', TeamAdoptionMapper::ROUTE_ADMIN, 1);
        $decisions = $this->decisions();
        $decisions->accept($this->adoptions->find($id), 'ncadmin');
        $decisions->accept($this->adoptions->find($id), 'ncadmin');

        self::assertCount(1, $this->registered);
        self::assertCount(1, $this->jobs);
    }

    public function testATeamThatNoLongerExistsCannotBeAccepted(): void {
        $id = (int)$this->adoptions->insert('gone', 'Gone', 'olga', TeamAdoptionMapper::ROUTE_ADMIN, 1);
        $this->expectException(WorkflowTransitionException::class);
        $this->decisions()->accept($this->adoptions->find($id), 'ncadmin');
    }

    public function testTheGridNeverOffersTheOpenProjectOrServiceTemplates(): void {
        $keys = array_column($this->decisions()->offeredTemplates(), 'templateKey');
        self::assertSame(['collaboration', 'project'], $keys);
    }
}
