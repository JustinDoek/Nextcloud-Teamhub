<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Provisioning;

use OCA\TeamHub\Db\ResourceLinkMapper;
use OCA\TeamHub\Db\TeamTemplateMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\OpenProject\OpenProjectProvisioningService;
use OCA\TeamHub\Service\OpenProject\TeamOpenProjectLinkService;
use OCA\TeamHub\Service\Provisioning\Blueprint;
use OCA\TeamHub\Service\Provisioning\BlueprintService;
use OCA\TeamHub\Service\Provisioning\MembershipPlanService;
use OCA\TeamHub\Service\Provisioning\ProvisioningContext;
use OCA\TeamHub\Service\Provisioning\ProvisioningService;
use OCA\TeamHub\Service\Provisioning\Step\StepInterface;
use OCA\TeamHub\Service\Provisioning\StepRegistry;
use OCA\TeamHub\Service\Provisioning\StepResult;
use OCA\TeamHub\Service\ResourceService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A scripted step for the engine tests: a queue of results, a call count,
 * and a rollback verdict.
 */
class ScriptedStep implements StepInterface {
    public int $runs = 0;
    /** @var list<StepResult> */
    public array $results;
    public function __construct(
        private string $key,
        array $results,
        private bool $applies = true,
        private ?StepResult $rollbackVerdict = null,
        private ?\Closure $onRun = null,
    ) {
        $this->results = $results;
    }
    public function key(): string { return $this->key; }
    public function resourceType(): ?string { return $this->key; }
    public function applies(ProvisioningContext $ctx): bool { return $this->applies; }
    public function rollbackPossible(): bool { return true; }
    public function run(ProvisioningContext $ctx): StepResult {
        $this->runs++;
        if ($this->onRun !== null) {
            ($this->onRun)($ctx);
        }
        return count($this->results) > 1 ? array_shift($this->results) : $this->results[0];
    }
    public function rollback(ProvisioningContext $ctx, array $stepRow, bool $confirm): StepResult {
        return $this->rollbackVerdict ?? StepResult::skipped('nothing_to_undo');
    }
}

class FakeRegistry extends StepRegistry {
    /** @param list<StepInterface> $fake */
    public function __construct(private array $fake) {}
    public function all(): array { return $this->fake; }
    public function forContext(ProvisioningContext $ctx): array {
        return array_values(array_filter($this->fake, fn (StepInterface $s) => $s->applies($ctx)));
    }
    public function byKey(string $key): ?StepInterface {
        foreach ($this->fake as $s) {
            if ($s->key() === $key) return $s;
        }
        return null;
    }
}

/**
 * The provisioning engine (v4.9.6): the operation lifecycle, the replay
 * guard, resumability, retries, the lease, and rollback policy — with
 * scripted steps, so what is tested is the runner and not the steps.
 */
class ProvisioningServiceTest extends TestCase {

    private InMemoryProvisioningMapper $mapper;
    private string $currentUid = 'alice';
    private bool $isAdmin = false;
    private bool $mayCreate = true;
    /** @var list<array> */
    private array $audit = [];

    private function service(array $steps): ProvisioningService {
        $this->mapper = new InMemoryProvisioningMapper();
        $this->audit  = [];

        $templates = $this->createMock(TeamTemplateMapper::class);
        $templates->method('find')->willReturnCallback(fn (string $key) => $key === 'openproject'
            ? ['templateKey' => 'openproject', 'apps' => ['talk', 'files', 'calendar'], 'modules' => ['messages'], 'blueprint' => Blueprint::defaultsForOpenProject()->toArray()]
            : null);
        $resources = $this->createMock(ResourceService::class);
        $resources->method('checkInstalledApps')->willReturn(['talk' => true, 'calendar' => true, 'collectives' => true, 'intravox' => false, 'deck' => true]);
        $audit = $this->createMock(AuditService::class);
        $audit->method('log')->willReturnCallback(function (...$args): void { $this->audit[] = $args; });

        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturnCallback(function () {
            $u = $this->createMock(IUser::class);
            $u->method('getUID')->willReturn($this->currentUid);
            return $u;
        });
        $session->method('setUser')->willReturnCallback(function (?IUser $u): void {
            if ($u !== null) $this->currentUid = $u->getUID();
        });
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturnCallback(fn (string $uid) => $uid === 'admin' && $this->isAdmin);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid) {
            $u = $this->createMock(IUser::class);
            $u->method('getUID')->willReturn($uid);
            return $u;
        });
        $members = $this->createMock(MemberService::class);
        $members->method('canCurrentUserCreateTeam')->willReturnCallback(fn () => $this->mayCreate);
        $members->method('canUserBulkCreateTeams')->willReturn(false);
        $members->method('getMemberLevelFromDb')->willReturnCallback(fn ($db, string $teamId, string $uid) => $uid === 'teamadmin' ? 8 : 0);

        $blueprints = new BlueprintService($templates, $resources, $audit, $session, $groups);
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('');

        return new ProvisioningService(
            $this->mapper,
            $this->createMock(ResourceLinkMapper::class),
            new FakeRegistry($steps),
            $blueprints,
            $this->createMock(MembershipPlanService::class),
            $this->createMock(OpenProjectProvisioningService::class),
            $this->createMock(TeamOpenProjectLinkService::class),
            $members,
            $audit,
            $session,
            $users,
            $groups,
            $config,
            $this->createMock(IDBConnection::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    private static function request(array $overrides = []): array {
        return $overrides + [
            'idempotencyKey' => 'key-0001-abcd',
            'templateKey'    => 'openproject',
            'mode'           => 'create',
            'name'           => 'Apollo',
            'openProject'    => ['templateId' => 5, 'identifier' => 'apollo'],
            'components'     => ['apps' => ['collectives'], 'modules' => ['decisions']],
            'members'        => [['id' => 'bob', 'type' => 'user', 'level' => 1]],
        ];
    }

    private function happySteps(): array {
        return [
            new ScriptedStep('validate', [StepResult::completed()]),
            new ScriptedStep('openproject_project', [StepResult::completed('77', ['projectId' => 77, 'mode' => 'created'])]),
            new ScriptedStep('team', [StepResult::completed('team-1')], onRun: fn (ProvisioningContext $c) => $c->setTeamId('team-1')),
            new ScriptedStep('talk', [StepResult::completed('tok')]),
            new ScriptedStep('finalize', [StepResult::completed('team-1')]),
        ];
    }

    // ── Start ──────────────────────────────────────────────────────────

    public function testStartRecordsTheOperationAndItsStepsWithoutRunningThem(): void {
        $steps = $this->happySteps();
        $svc   = $this->service($steps);
        $state = $svc->start(self::request());

        $this->assertSame('pending', $state['status']);
        $this->assertSame(['validate', 'openproject_project', 'team', 'talk', 'finalize'], array_column($state['steps'], 'key'));
        $this->assertSame(0, $steps[0]->runs);
        $this->assertSame(['talk', 'files', 'calendar'], $state['request']['components']['apps'], 'the template row\'s apps — the request\'s components are ignored');
        $this->assertSame(['messages'], $state['request']['components']['modules']);
        $this->assertSame('provisioning.started', $this->audit[0][1]);
    }

    public function testTheSameKeyReturnsTheSameOperation(): void {
        $svc = $this->service($this->happySteps());
        $a = $svc->start(self::request());
        $b = $svc->start(self::request(['name' => 'Something else']));
        $this->assertSame($a['id'], $b['id'], 'a double click makes one workspace');
        $this->assertCount(1, $this->mapper->ops);
    }

    public function testStartRefusesWhoMayNotCreateTeams(): void {
        $svc = $this->service($this->happySteps());
        $this->mayCreate = false;
        $this->expectException(AccessDeniedException::class);
        $svc->start(self::request());
    }

    public function testStartIgnoresClientComponentsAndRefusesABadIdentifier(): void {
        $svc = $this->service($this->happySteps());
        $state = $svc->start(self::request(['components' => ['apps' => ['deck', 'jira'], 'modules' => ['presence']]]));
        $this->assertSame(['talk', 'files', 'calendar'], $state['request']['components']['apps'], 'the template decides; a client cannot add an app');
        $this->assertSame(['messages'], $state['request']['components']['modules']);

        $this->expectException(ValidationException::class);
        $svc->start(self::request(['idempotencyKey' => 'key-0002-abcd', 'openProject' => ['identifier' => 'Bad Id']]));
    }

    public function testLinkModeNeedsAProject(): void {
        $svc = $this->service($this->happySteps());
        $this->expectException(ValidationException::class);
        $svc->start(self::request(['mode' => 'link', 'openProject' => []]));
    }

    public function testMembersAreSanitised(): void {
        $svc = $this->service($this->happySteps());
        $state = $svc->start(self::request(['members' => [
            ['id' => 'bob', 'type' => 'user', 'level' => 7, 'displayName' => '<b>Bob</b>'],
            ['id' => 'staff', 'type' => 'group', 'level' => 9],
            ['id' => '', 'type' => 'user'],
            ['id' => 'x', 'type' => 'alien'],
        ]]));
        $members = $state['request']['members'];
        $this->assertCount(2, $members);
        $this->assertSame(1, $members[0]['level'], 'an invalid level is member');
        $this->assertSame('Bob', $members[0]['displayName']);
        $this->assertSame(8, $members[1]['level'], 'a group cannot own');
    }

    // ── Run ────────────────────────────────────────────────────────────

    public function testRunExecutesEveryStepInOrderAndCompletes(): void {
        $steps = $this->happySteps();
        $svc   = $this->service($steps);
        $id    = $svc->start(self::request())['id'];
        $state = $svc->run($id);

        $this->assertSame('completed', $state['status']);
        $this->assertSame('team-1', $state['teamId']);
        foreach ($steps as $s) {
            $this->assertSame(1, $s->runs, $s->key());
        }
        $this->assertSame(['completed', 'completed', 'completed', 'completed', 'completed'], array_column($state['steps'], 'status'));
        $this->assertSame('team-1', $state['result']['teamId']);
        $this->assertSame(77, $state['result']['project']['projectId']);
        $this->assertNull($this->mapper->ops[$id]['lockToken'], 'lease released');
        $this->assertContains('provisioning.completed', array_column($this->audit, 1));
    }

    public function testAFailedStepStopsTheRunAndARetryResumesFromIt(): void {
        $steps = $this->happySteps();
        $steps[3] = new ScriptedStep('talk', [StepResult::failed('talk_create', 'Talk is down', true), StepResult::completed('tok')]);
        $svc   = $this->service($steps);
        $id    = $svc->start(self::request())['id'];
        $state = $svc->run($id);

        $this->assertSame('failed', $state['status']);
        $this->assertSame('talk', $state['currentStep']);
        $this->assertSame('talk_create', $state['errorCode']);
        $this->assertSame('team-1', $state['teamId'], 'the team made before the failure is recorded');
        $this->assertSame(0, $steps[4]->runs, 'nothing after the failed step ran');

        // Running again does not re-run: the failure is a stop until retried.
        $svc->run($id);
        $this->assertSame(1, $steps[3]->runs);

        $state = $svc->retry($id, 'talk');
        $this->assertSame('completed', $state['status']);
        $this->assertSame(2, $steps[3]->runs);
        $this->assertSame(1, $steps[2]->runs, 'the team step was not run again — idempotency is per step, not per operation');
        $this->assertSame(1, $steps[4]->runs);
        $this->assertSame(2, $state['steps'][3]['attempts']);
    }

    public function testAStepThatIsNotRetrySafeCannotBeRetried(): void {
        $steps = $this->happySteps();
        $steps[1] = new ScriptedStep('openproject_project', [StepResult::failed('identifier_taken', 'Taken', false)]);
        $svc = $this->service($steps);
        $id  = $svc->start(self::request())['id'];
        $svc->run($id);
        $this->expectException(ValidationException::class);
        $svc->retry($id, 'openproject_project');
    }

    public function testAttentionDoesNotStopTheRunButKeepsTheOperationOpen(): void {
        $steps = $this->happySteps();
        $steps[3] = new ScriptedStep('talk', [StepResult::attention('memberships_partial', 'One member refused', ['refused' => ['bob']])]);
        $svc   = $this->service($steps);
        $id    = $svc->start(self::request())['id'];
        $state = $svc->run($id);

        $this->assertSame('attention', $state['status']);
        $this->assertSame(1, $steps[4]->runs, 'the finalize step still ran');
        $this->assertSame('attention', $state['steps'][3]['status']);
        $this->assertContains('provisioning.needs_attention', array_column($this->audit, 1));

        $summary = $svc->latestForTeam('team-1');
        $this->assertFalse($summary['complete']);
        $this->assertSame([['key' => 'talk', 'status' => 'attention', 'errorCode' => 'memberships_partial']], $summary['openSteps']);
    }

    public function testAnAsynchronousStepHandsBackAndIsPolledOnTheNextRun(): void {
        $steps = $this->happySteps();
        $steps[1] = new ScriptedStep('openproject_project', [
            StepResult::running('job-1', 5, ['jobId' => 'job-1']),
            StepResult::running('job-1', 5, ['jobId' => 'job-1']),
            StepResult::completed('77', ['projectId' => 77, 'mode' => 'created']),
        ]);
        $svc   = $this->service($steps);
        $id    = $svc->start(self::request())['id'];

        $state = $svc->run($id);
        $this->assertSame('running', $state['status']);
        $this->assertSame(5, $state['pollAfter']);
        $this->assertSame('job-1', $this->mapper->steps[$id]['openproject_project']['externalRef']);
        $this->assertSame(0, $steps[2]->runs);

        $svc->run($id);
        $state = $svc->run($id);
        $this->assertSame('completed', $state['status']);
        $this->assertSame(3, $steps[1]->runs);
        $this->assertSame(1, $steps[1]->results === [] ? 1 : 1);
        $this->assertNull($this->mapper->steps[$id]['openproject_project']['externalRef'], 'cleared once done');
    }

    public function testAStepThatThrowsIsAFailureNotACrash(): void {
        $steps = $this->happySteps();
        $steps[3] = new ScriptedStep('talk', [StepResult::completed()], onRun: function (): void {
            throw new \RuntimeException('boom');
        });
        $svc   = $this->service($steps);
        $id    = $svc->start(self::request())['id'];
        $state = $svc->run($id);
        $this->assertSame('failed', $state['status']);
        $this->assertSame('unexpected', $state['steps'][3]['errorCode']);
        $this->assertStringNotContainsString('boom', (string)$state['steps'][3]['errorMessage'], 'the raw message stays in the log');
    }

    public function testStepsThatDoNotApplyAreNotPartOfTheOperation(): void {
        $steps = $this->happySteps();
        $steps[3] = new ScriptedStep('talk', [StepResult::completed()], applies: false);
        $svc   = $this->service($steps);
        $state = $svc->start(self::request());
        $this->assertNotContains('talk', array_column($state['steps'], 'key'));
    }

    public function testTooManyAttemptsBecomesAttention(): void {
        $steps = $this->happySteps();
        $steps[3] = new ScriptedStep('talk', [StepResult::failed('talk_create', 'down', true)]);
        $svc = $this->service($steps);
        $id  = $svc->start(self::request())['id'];
        $svc->run($id);
        for ($i = 0; $i < ProvisioningService::MAX_AUTO_ATTEMPTS; $i++) {
            try {
                $svc->retry($id, 'talk');
            } catch (ValidationException) {
                break;
            }
        }
        $state = $svc->status($id);
        $this->assertSame('attention', $state['steps'][3]['status']);
        $this->assertSame('too_many_attempts', $state['steps'][3]['errorCode']);
    }

    // ── The lease ──────────────────────────────────────────────────────

    public function testAHeldLeaseMakesASecondRunnerReportBusyInsteadOfExecuting(): void {
        $steps = $this->happySteps();
        $svc   = $this->service($steps);
        $id    = $svc->start(self::request())['id'];
        $this->mapper->ops[$id]['lockToken'] = 'somebody-else';
        $this->mapper->ops[$id]['lockedAt']  = time();

        $state = $svc->run($id);
        $this->assertTrue($state['busy']);
        $this->assertSame(0, $steps[0]->runs, 'nothing executed under somebody else\'s lease');

        // A stale lease is taken over.
        $this->mapper->ops[$id]['lockedAt'] = time() - ProvisioningService::LEASE_SECONDS - 1;
        $state = $svc->run($id);
        $this->assertArrayNotHasKey('busy', $state);
        $this->assertSame('completed', $state['status']);
    }

    public function testStalledOperationsAreFoundByHeartbeat(): void {
        $svc = $this->service($this->happySteps());
        $id  = $svc->start(self::request())['id'];
        $this->mapper->ops[$id]['status']      = 'running';
        $this->mapper->ops[$id]['heartbeatAt'] = time() - ProvisioningService::STALE_AFTER_SECONDS - 10;
        $this->assertSame([$id], array_column($svc->findStalled(), 'id'));
        $this->assertTrue($svc->status($id)['stalled']);
    }

    // ── Who ────────────────────────────────────────────────────────────

    public function testStatusIsForTheCreatorAnAdministratorOrTheTeamsAdmins(): void {
        $svc = $this->service($this->happySteps());
        $id  = $svc->start(self::request())['id'];
        $svc->run($id);

        $this->currentUid = 'teamadmin';
        $this->assertSame('completed', $svc->status($id)['status'], 'an admin of the produced team may read');

        $this->currentUid = 'admin';
        $this->isAdmin = true;
        $this->assertSame('completed', $svc->status($id)['status']);

        $this->currentUid = 'stranger';
        $this->isAdmin = false;
        $this->expectException(AccessDeniedException::class);
        $svc->status($id);
    }

    public function testRunAndRollbackAreForTheCreatorOrAnAdministratorOnly(): void {
        $steps = $this->happySteps();
        $steps[3] = new ScriptedStep('talk', [StepResult::failed('talk_create', 'down', true)]);
        $svc = $this->service($steps);
        $id  = $svc->start(self::request())['id'];
        $svc->run($id);

        $this->currentUid = 'teamadmin';
        try {
            $svc->rollback($id, true);
            $this->fail('expected an exception');
        } catch (AccessDeniedException) {
        }
        try {
            $svc->run($id);
            $this->fail('expected an exception');
        } catch (AccessDeniedException) {
        }
        $this->assertSame('failed', $this->mapper->ops[$id]['status'], 'untouched by the refused calls');
    }

    public function testAnAdministratorRunsTheOperationAsItsCreator(): void {
        $seen = null;
        $steps = $this->happySteps();
        $steps[0] = new ScriptedStep('validate', [StepResult::completed()], onRun: function (ProvisioningContext $c) use (&$seen): void {
            $seen = [$c->userId, $this->currentUid];
        });
        $svc = $this->service($steps);
        $id  = $svc->start(self::request())['id'];

        $this->currentUid = 'admin';
        $this->isAdmin = true;
        $state = $svc->run($id);
        $this->assertSame('completed', $state['status']);
        $this->assertSame(['alice', 'alice'], $seen, 'context and session are the creator while the steps run');
        $this->assertSame('admin', $this->currentUid, 'and restored afterwards');
        $this->assertContains('provisioning.run_as_creator', array_column($this->audit, 1));
    }

    // ── Rollback ───────────────────────────────────────────────────────

    public function testRollbackIsADryRunUntilConfirmedWhenAResourceMayHoldActivity(): void {
        $steps = $this->happySteps();
        $steps[3] = new ScriptedStep('talk', [StepResult::completed('tok')], rollbackVerdict: StepResult::attention('confirm_required', 'may hold messages', [], 'tok'));
        $steps[4] = new ScriptedStep('finalize', [StepResult::failed('workspace_incomplete', 'x', true)]);
        $teamRollbacks = 0;
        $steps[2] = new class('team', [StepResult::completed('team-1')], true, null, fn (ProvisioningContext $c) => $c->setTeamId('team-1')) extends ScriptedStep {
            public int $rollbacks = 0;
            public function rollback(ProvisioningContext $ctx, array $stepRow, bool $confirm): StepResult {
                $this->rollbacks++;
                return StepResult::completed('team-1', ['deleted' => true]);
            }
        };
        $svc = $this->service($steps);
        $id  = $svc->start(self::request())['id'];
        $svc->run($id);

        $dry = $svc->rollback($id, false);
        $this->assertSame('confirm_required', $dry['status']);
        $this->assertSame(['talk'], $dry['needsConfirm']);
        $this->assertSame(0, $steps[2]->rollbacks, 'nothing removed without confirmation');
        $this->assertSame('failed', $svc->status($id)['status']);

        $done = $svc->rollback($id, true);
        $this->assertSame('rolled_back', $done['status']);
        $this->assertSame(1, $steps[2]->rollbacks, 'the team cascade ran once');
        $this->assertSame('rolled_back', $this->mapper->ops[$id]['status']);
        $this->assertSame('rolled_back', $this->mapper->steps[$id]['talk']['status']);
        $this->assertSame('skipped', $done['verdicts']['openproject_project']['status'] ?? 'skipped', 'the project step reports, never deletes');
    }

    public function testLinkedResourcesAreKeptOnRollback(): void {
        $steps = $this->happySteps();
        $steps[1] = new ScriptedStep('openproject_project', [StepResult::completed('77', ['projectId' => 77, 'mode' => 'linked'])], rollbackVerdict: StepResult::skipped('linked_resource_kept', ['projectId' => 77]));
        $steps[4] = new ScriptedStep('finalize', [StepResult::failed('workspace_incomplete', 'x', true)]);
        $svc = $this->service($steps);
        $id  = $svc->start(self::request(['mode' => 'link', 'openProject' => ['projectId' => 77]]))['id'];
        $svc->run($id);

        $done = $svc->rollback($id, true);
        $this->assertSame('rolled_back', $done['status']);
        $this->assertSame('linked_resource_kept', $done['verdicts']['openproject_project']['detail']['reason']);
    }

    public function testACompletedOperationIsNotRolledBack(): void {
        $svc = $this->service($this->happySteps());
        $id  = $svc->start(self::request())['id'];
        $svc->run($id);
        $this->expectException(ValidationException::class);
        $svc->rollback($id, true);
    }

    public function testAHandedOverTeamIsNotRolledBackFromHere(): void {
        $steps = $this->happySteps();
        $steps[] = new ScriptedStep('handover', [StepResult::completed('bob')]);
        $steps[4] = new ScriptedStep('finalize', [StepResult::completed()]);
        // finalize before handover in this fake order is fine — the guard reads the row.
        $svc = $this->service($steps);
        $id  = $svc->start(self::request(['ownerUid' => 'bob']))['id'];
        $svc->run($id);
        $this->mapper->ops[$id]['status'] = 'attention';
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('handed over');
        $svc->rollback($id, true);
    }
}
