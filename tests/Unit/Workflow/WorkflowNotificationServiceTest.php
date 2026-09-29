<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Db\WorkflowInstance;
use OCA\TeamHub\Db\WorkflowStep;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\Workflow\WorkflowNotificationService;
use OCA\TeamHub\Workflow\Definition\TeamRequestDefinition;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who gets told what (WorkflowHub phase 2, v4.10.14): recipients come
 * from the step's actor, resolved server-side; the person who caused the
 * event is skipped; the parameters carry what the Notifier needs and
 * nothing more; withdrawal is by object.
 */
class WorkflowNotificationServiceTest extends TestCase {

    /** @var array<int, array{user: string, subject: string, params: array<string, mixed>, object: array{0: string, 1: string}}> */
    private array $sent = [];
    /** @var array<int, array{0: string, 1: string}> */
    private array $processed = [];
    private FakeActorResolver $resolver;
    private WorkflowDefinitionRegistry $registry;

    protected function setUp(): void {
        // The real reference definition decides what a notification may
        // carry (phase 4): the team name, never the reason.
        //
        // v4.10.23 — step 3 is the service team that holds the Nextcloud
        // services rather than a configured group, so the definition takes
        // a ServiceTeamService. One honest answer is all it needs here.
        $serviceTeams = $this->createMock(ServiceTeamService::class);
        $serviceTeams->method('serviceTeamForDefinition')->willReturn('desk');
        $this->registry = new WorkflowDefinitionRegistry();
        $this->registry->register(new TeamRequestDefinition($serviceTeams));
    }

    private function service(): WorkflowNotificationService {
        $manager = $this->createMock(INotificationManager::class);
        $manager->method('createNotification')->willReturnCallback(function (): INotification {
            $n     = $this->createMock(INotification::class);
            $state = ['user' => '', 'subject' => '', 'params' => [], 'object' => ['', '']];
            $n->method('setApp')->willReturnSelf();
            $n->method('setDateTime')->willReturnSelf();
            $n->method('setUser')->willReturnCallback(function (string $u) use ($n, &$state): INotification { $state['user'] = $u; return $n; });
            $n->method('setObject')->willReturnCallback(function (string $t, string $i) use ($n, &$state): INotification { $state['object'] = [$t, $i]; return $n; });
            $n->method('setSubject')->willReturnCallback(function (string $s, array $p = []) use ($n, &$state): INotification { $state['subject'] = $s; $state['params'] = $p; return $n; });
            // Closures, not arrow functions: an arrow function captures $state by value at creation.
            $n->method('getUser')->willReturnCallback(function () use (&$state): string { return $state['user']; });
            $n->method('getSubject')->willReturnCallback(function () use (&$state): string { return $state['subject']; });
            $n->method('getSubjectParameters')->willReturnCallback(function () use (&$state): array { return $state['params']; });
            $n->method('getObjectType')->willReturnCallback(function () use (&$state): string { return $state['object'][0]; });
            $n->method('getObjectId')->willReturnCallback(function () use (&$state): string { return $state['object'][1]; });
            return $n;
        });
        $manager->method('notify')->willReturnCallback(function (INotification $n): void {
            $this->sent[] = ['user' => $n->getUser(), 'subject' => $n->getSubject(), 'params' => $n->getSubjectParameters(), 'object' => [$n->getObjectType(), $n->getObjectId()]];
        });
        $manager->method('markProcessed')->willReturnCallback(function (INotification $n): void {
            $this->processed[] = [$n->getObjectType(), $n->getObjectId()];
        });

        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid): ?IUser {
            $u = $this->createMock(IUser::class);
            $u->method('getUID')->willReturn($uid);
            $u->method('getDisplayName')->willReturn(ucfirst($uid));
            return $u;
        });

        $this->resolver = new class extends FakeActorResolver {
            /** @var array<string, string[]> actor key → uids */
            public array $holders = [];
            public function holdersOf(WorkflowActor $actor, string $teamId): array {
                return $this->holders[$actor->key()] ?? [];
            }
        };
        $this->resolver->holders = ['group:it' => ['ida', 'ivan'], 'team_moderator:' => ['mod', 'owner']];

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getDateTime')->willReturn(new \DateTime('@1700000000'));

        return new WorkflowNotificationService($manager, $this->resolver, $this->registry, $users, $time, $this->createMock(LoggerInterface::class));
    }

    private function instance(): WorkflowInstance {
        $i = new WorkflowInstance();
        $i->setId(12);
        $i->setDefinitionKey('team_request');
        $i->setTeamId('t1');
        $i->setStartedBy('member');
        $i->setOutcome('rejected');
        $i->setData(['teamName' => 'Sales', 'reason' => 'r']);
        return $i;
    }

    private function step(string $key, string $actorType, string $actorId): WorkflowStep {
        $s = new WorkflowStep();
        $s->setStepKey($key);
        $s->setLabel('Label ' . $key);
        $s->setActorType($actorType);
        $s->setActorId($actorId);
        return $s;
    }

    public function testAStepGoesToItsHoldersMinusWhoeverHandedItOver(): void {
        $svc = $this->service();
        $svc->stepAvailable($this->instance(), $this->step('approve', 'team_moderator', ''), 'mod');
        self::assertSame(['owner'], array_column($this->sent, 'user'));
        $n = $this->sent[0];
        self::assertSame('workflow_step_available', $n['subject']);
        self::assertSame(['workflow', '12'], $n['object']);
        self::assertSame('team_request', $n['params']['definitionKey']);
        self::assertSame('approve', $n['params']['stepKey']);
        self::assertSame('mod', $n['params']['actorUid']);
        self::assertSame('Mod', $n['params']['actorName']);
        // Phase 4: the title's data only — the reason stays with the instance.
        self::assertSame(['teamName' => 'Sales'], $n['params']['data']);
        self::assertArrayNotHasKey('reason', $n['params']['data']);
        self::assertArrayNotHasKey('note', $n['params']['data']);
    }

    public function testAStatusRequestReachesEveryHolderOfTheActiveStep(): void {
        $svc = $this->service();
        $svc->statusRequested($this->instance(), $this->step('process', 'group', 'it'), 'member', 'any news?');
        self::assertEqualsCanonicalizing(['ida', 'ivan'], array_column($this->sent, 'user'));
        self::assertSame('workflow_status_requested', $this->sent[0]['subject']);
        self::assertSame('any news?', $this->sent[0]['params']['note']);
    }

    public function testInformationRequestsGoToTheInitiatorAndAnswersToTheHolders(): void {
        $svc = $this->service();
        $svc->informationRequested($this->instance(), $this->step('process', 'group', 'it'), 'ida', 'how big?');
        self::assertSame(['member'], array_column($this->sent, 'user'));
        self::assertSame('workflow_information_requested', $this->sent[0]['subject']);

        $this->sent = [];
        $svc->informationProvided($this->instance(), $this->step('process', 'group', 'it'), 'member', 'twelve');
        self::assertEqualsCanonicalizing(['ida', 'ivan'], array_column($this->sent, 'user'));
        self::assertSame('workflow_information_provided', $this->sent[0]['subject']);
    }

    public function testTheEndIsToldToEveryNamedParticipantExceptTheOneWhoEndedIt(): void {
        $svc = $this->service();
        $svc->ended($this->instance(), ['member', 'mod', 'ida'], 'mod', 'no');
        self::assertEqualsCanonicalizing(['member', 'ida'], array_column($this->sent, 'user'));
        self::assertSame('workflow_ended', $this->sent[0]['subject']);
        self::assertSame('rejected', $this->sent[0]['params']['outcome']);
        self::assertSame('no', $this->sent[0]['params']['note']);
    }

    public function testWithdrawalIsByInstance(): void {
        $this->service()->withdraw(12);
        self::assertSame([['workflow', '12']], $this->processed);
    }

    public function testAnUnknownUserActorGetsNothing(): void {
        $svc = $this->service();
        $this->resolver->holders = [];
        $svc->stepAvailable($this->instance(), $this->step('confirm', 'user', 'ghost'), null);
        self::assertSame([], $this->sent);
    }
}
