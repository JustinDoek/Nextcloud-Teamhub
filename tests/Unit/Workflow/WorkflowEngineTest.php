<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Exception\WorkflowTransitionException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\Workflow\WorkflowEngine;
use OCA\TeamHub\Service\Workflow\WorkflowEventService;
use OCA\TeamHub\Service\Workflow\WorkflowNotificationService;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCA\TeamHub\Workflow\WorkflowEventType;
use OCA\TeamHub\Workflow\WorkflowParticipantRole;
use OCA\TeamHub\Workflow\WorkflowStatus;
use OCA\TeamHub\Workflow\WorkflowStepDefinition;
use OCA\TeamHub\Workflow\WorkflowStepStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The WorkflowHub engine (phase 1, v4.10.13): creation, the sequential
 * step lifecycle, the terminal paths, who may do what, the participant
 * list that never shrinks, the definition version that never moves, and
 * one event per change.
 *
 * Roster on team `t1`: owner (9), admin8 (8), mod (4), member (1),
 * viagroup (member through a group only), outsider (not in the team),
 * ncadmin (Nextcloud administrator, group `admin`), reviewer (group
 * `reviewers`, not in the team).
 */
class WorkflowEngineTest extends TestCase {

    private const TEAM = 't1';

    private InMemoryWorkflowInstanceMapper $instances;
    private InMemoryWorkflowStepMapper $steps;
    private InMemoryWorkflowParticipantMapper $participants;
    private InMemoryWorkflowEventMapper $events;
    private FakeActorResolver $resolver;
    private FakeLicenceTier $tier;
    private WorkflowDefinitionRegistry $registry;
    private ArchiveHarness $archive;
    /** @var array<int, array{event: string, team: string, actor: ?string}> */
    private array $audit = [];
    private int $now = 1_000_000;
    private int $begun = 0;
    private int $committed = 0;
    private int $rolledBack = 0;
    /** @var array<int, array{0: string, 1: array<int, mixed>}> notifier method calls */
    public array $notified = [];

    protected function setUp(): void {
        $this->instances    = new InMemoryWorkflowInstanceMapper();
        $this->steps        = new InMemoryWorkflowStepMapper();
        $this->participants = new InMemoryWorkflowParticipantMapper($this->instances);
        $this->events       = new InMemoryWorkflowEventMapper();
        $this->registry     = new WorkflowDefinitionRegistry();
        $this->tier         = new FakeLicenceTier();
        $this->archive      = new ArchiveHarness();

        $this->resolver = new FakeActorResolver();
        $this->resolver->levels[self::TEAM] = ['owner' => 9, 'admin8' => 8, 'mod' => 4, 'member' => 1];
        $this->resolver->indirect[self::TEAM] = ['viagroup'];
        $this->resolver->groups = ['ncadmin' => ['admin'], 'reviewer' => ['reviewers']];
        $this->resolver->admins = ['ncadmin'];

        // The three-step fixture: any member → the reviewers group → the owner.
        $this->registry->register(new FixtureDefinition('three', 1, [
            new WorkflowStepDefinition('draft',  'Member drafts',   WorkflowActor::team()),
            new WorkflowStepDefinition('review', 'Reviewers check', WorkflowActor::group('reviewers')),
            new WorkflowStepDefinition('sign',   'Owner signs',     WorkflowActor::teamOwner()),
        ]));
    }

    private function engine(): WorkflowEngine {
        $time = $this->createMock(ITimeFactory::class);
        // Every read advances the clock, so ordering by time is meaningful.
        $time->method('getTime')->willReturnCallback(fn (): int => ++$this->now);

        $audit = $this->createMock(AuditService::class);
        $audit->method('log')->willReturnCallback(function (string $team, string $event, ?string $actor): void {
            $this->audit[] = ['event' => $event, 'team' => $team, 'actor' => $actor];
        });

        $db = $this->createMock(IDBConnection::class);
        $db->method('beginTransaction')->willReturnCallback(function (): void { $this->begun++; });
        $db->method('commit')->willReturnCallback(function (): void { $this->committed++; });
        $db->method('rollBack')->willReturnCallback(function (): void { $this->rolledBack++; });

        $notifier = $this->createMock(WorkflowNotificationService::class);
        foreach (['stepAvailable', 'statusRequested', 'informationRequested', 'informationProvided', 'ended', 'withdraw'] as $m) {
            $notifier->method($m)->willReturnCallback(function (...$args) use ($m): void {
                $this->notified[] = [$m, $args];
            });
        }
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
        // The engine uses n() for the status-request cooldown (v4.10.17).
        $l->method('n')->willReturnCallback(static fn (string $one, string $many, int $count, array $params = []): string => str_replace('%n', (string)$count, $count === 1 ? $one : $many));

        return new WorkflowEngine(
            $this->registry,
            $this->instances,
            $this->steps,
            $this->participants,
            new WorkflowEventService($this->events, $time),
            $this->resolver,
            $notifier,
            $this->tier,
            // v4.10.20 - no service team in these cases: a mock that answers
            // "no" to everything is exactly the pre-phase-5 behaviour.
            $this->noServiceTeams(),
            // v4.10.21 - the real archive service over in-memory tables, so
            // a licensed ending in any of these cases writes the record it
            // would write in production.
            $this->archive->service(
                $this->instances,
                $this->steps,
                $this->participants,
                new WorkflowEventService($this->events, $time),
                $this->resolver,
                $this->registry,
                $this->tier,
                $this->noServiceTeams(),
                $audit,
                $time,
                $l,
                $this->createMock(LoggerInterface::class),
            ),
            $this->archive->attachments,
            $audit,
            $db,
            $time,
            $l,
            $this->createMock(LoggerInterface::class),
        );
    }

    /** @return array<string, string> step key → status */
    private function stepStatuses(array $view): array {
        $out = [];
        foreach ($view['steps'] as $s) {
            $out[$s['key']] = $s['status'];
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Creation
    // ──────────────────────────────────────────────────────────────────────

    public function testCreatingAWorkflowMaterialisesTheStepsAndActivatesTheFirst(): void {
        $view = $this->engine()->create('three', self::TEAM, 'member', ['reason' => 'because']);

        self::assertSame(1, $view['id']);
        self::assertSame('three', $view['definitionKey']);
        self::assertSame(1, $view['definitionVersion']);
        self::assertSame(WorkflowStatus::SUBMITTED, $view['status']);
        self::assertSame('draft', $view['currentStep']);
        self::assertSame('member', $view['startedBy']);
        self::assertSame(['reason' => 'because'], $view['data']);
        self::assertSame(['type' => 'team', 'id' => self::TEAM], $view['subject']);
        self::assertNull($view['outcome']);
        self::assertNull($view['endedAt']);

        self::assertSame(
            ['draft' => WorkflowStepStatus::AVAILABLE, 'review' => WorkflowStepStatus::PENDING, 'sign' => WorkflowStepStatus::PENDING],
            $this->stepStatuses($view),
        );
        self::assertSame([1, 2, 3], array_column($view['steps'], 'order'));
        self::assertSame(['type' => 'group', 'id' => 'reviewers'], $view['steps'][1]['actor']);
        self::assertNotNull($view['steps'][0]['enteredAt']);
        self::assertNull($view['steps'][1]['enteredAt']);

        // The initiator and every step's actor are participants from the start.
        $roster = array_map(static fn (array $p): string => $p['actor']['type'] . ':' . $p['actor']['id'] . '=' . $p['role'], $view['participants']);
        self::assertSame([
            'user:member=' . WorkflowParticipantRole::INITIATOR,
            'team:=' . WorkflowParticipantRole::RESPONSIBLE,
            'group:reviewers=' . WorkflowParticipantRole::RESPONSIBLE,
            'team_owner:=' . WorkflowParticipantRole::RESPONSIBLE,
        ], $roster);

        self::assertSame([
            WorkflowEventType::PARTICIPANT_ADDED, WorkflowEventType::PARTICIPANT_ADDED,
            WorkflowEventType::PARTICIPANT_ADDED, WorkflowEventType::PARTICIPANT_ADDED,
            WorkflowEventType::CREATED, WorkflowEventType::STEP_AVAILABLE,
        ], $this->events->typesFor(1));
        self::assertSame(['workflow.three.created'], array_column($this->audit, 'event'));
        self::assertSame(1, $this->begun);
        self::assertSame(1, $this->committed);
        self::assertSame(0, $this->rolledBack);
    }

    public function testTheSameActorOnTwoStepsIsOneParticipant(): void {
        $this->registry->register(new FixtureDefinition('twice', 1, [
            new WorkflowStepDefinition('a', 'A', WorkflowActor::teamOwner()),
            new WorkflowStepDefinition('b', 'B', WorkflowActor::teamOwner()),
        ]));
        $view = $this->engine()->create('twice', self::TEAM, 'owner');
        // owner is the initiator (user) and the responsible actor (team_owner) — two rows, not three.
        self::assertCount(2, $view['participants']);
    }

    public function testAnUnknownDefinitionIsNotFound(): void {
        $this->expectException(NotFoundException::class);
        $this->engine()->create('nope', self::TEAM, 'member');
    }

    /**
     * v4.10.17 — a registered but dark definition answers exactly as an
     * unknown one: not listed, and 404 on create. The feature switch of
     * `docs/workflowhub-architecture.md` §11 step 2, which
     * `QuotaRequestDefinition` uses until the quota request runs on the
     * engine. Without it the route created a real instance of a definition
     * nothing was wired to.
     */
    public function testADarkDefinitionIsNeitherListedNorStartable(): void {
        $this->registry->register(new FixtureDefinition(
            'dark',
            1,
            [new WorkflowStepDefinition('a', 'A', WorkflowActor::team())],
            null,
            WorkflowDefinitionRegistry::CONCURRENCY_ONE_OPEN_PER_TEAM,
            false,
            true,
            false,
        ));
        $engine = $this->engine();

        $listed = array_column($engine->describeDefinitions(), 'key');
        self::assertNotContains('dark', $listed);
        self::assertContains('three', $listed, 'a startable definition is still offered');

        try {
            $engine->create('dark', self::TEAM, 'member');
            self::fail('a dark definition was started');
        } catch (NotFoundException) {
            // expected
        }
        self::assertSame([], $this->instances->rows, 'nothing was written');
    }

    /**
     * v4.10.17 — `submitted → waiting`. A workflow whose first step is not
     * auto-completed sits in `submitted` while that step is live, so asking
     * the requester a question on it re-derived the instance as `waiting`
     * — a transition the table refused with a 409 (HANDOFF §0-wf-submitted).
     * Reachable in the field through `QuotaRequestDefinition`, whose first
     * step is the administrators' `decide`.
     */
    public function testInformationCanBeAskedOnAnAsYetUnstartedFirstStep(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');
        self::assertSame(WorkflowStatus::SUBMITTED, $this->instances->rows[1]->getStatus());

        $view = $engine->requestInformation(1, 'member', 'which version?');
        self::assertSame(WorkflowStatus::WAITING, $view['status']);
        self::assertSame(WorkflowStepStatus::WAITING_FOR_INFORMATION, $view['steps'][0]['status']);

        // And it comes back out again the ordinary way.
        $view = $engine->provideInformation(1, 'member', 'v2');
        self::assertSame(WorkflowStatus::IN_PROGRESS, $view['status']);
    }

    public function testTheDefinitionDecidesWhoMayStart(): void {
        $this->registry->register(new FixtureDefinition('owners_only', 1, [
            new WorkflowStepDefinition('a', 'A', WorkflowActor::team()),
        ], static fn (string $uid, string $teamId, $resolver): bool => $resolver->memberLevel($uid, $teamId) >= 9));

        $engine = $this->engine();
        $view   = $engine->create('owners_only', self::TEAM, 'owner');
        self::assertSame(WorkflowStatus::SUBMITTED, $view['status']);

        $this->expectException(AccessDeniedException::class);
        $engine->create('owners_only', 't2', 'owner');
    }

    public function testTheDefinitionValidatesThePayload(): void {
        $this->registry->register(new FixtureDefinition('strict', 1, [
            new WorkflowStepDefinition('a', 'A', WorkflowActor::team()),
        ], null, WorkflowDefinitionRegistry::CONCURRENCY_ONE_OPEN_PER_TEAM, true));

        $this->expectException(ValidationException::class);
        $this->engine()->create('strict', self::TEAM, 'member', ['reason' => '   ']);
    }

    public function testOneOpenWorkflowPerTeamPerKind(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');
        // Another team is fine.
        $this->resolver->levels['t2'] = ['member' => 1];
        $engine->create('three', 't2', 'member');

        try {
            $engine->create('three', self::TEAM, 'member');
            self::fail('a second open workflow was accepted');
        } catch (WorkflowTransitionException) {
            // expected
        }
        self::assertCount(2, $this->instances->rows);
        self::assertSame(1, $this->rolledBack);

        // Once the first one has ended, a new one may open.
        $engine->cancel(1, 'member');
        self::assertSame(3, $engine->create('three', self::TEAM, 'member')['id']);
    }

    public function testAnUnboundedDefinitionAllowsSeveralOpenWorkflows(): void {
        $this->registry->register(new FixtureDefinition('many', 1, [
            new WorkflowStepDefinition('a', 'A', WorkflowActor::team()),
        ], null, WorkflowDefinitionRegistry::CONCURRENCY_UNBOUNDED));
        $engine = $this->engine();
        $engine->create('many', self::TEAM, 'member');
        $engine->create('many', self::TEAM, 'member');
        self::assertCount(2, $this->instances->rows);
    }

    // ──────────────────────────────────────────────────────────────────────
    // The step lifecycle
    // ──────────────────────────────────────────────────────────────────────

    public function testStartingTheFirstStepMovesTheWorkflowIntoProgress(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');
        $view = $engine->startStep(1, 'member');

        self::assertSame(WorkflowStatus::IN_PROGRESS, $view['status']);
        self::assertSame(WorkflowStepStatus::IN_PROGRESS, $view['steps'][0]['status']);
        self::assertNotNull($view['steps'][0]['startedAt']);
        self::assertSame('draft', $engine->getActiveStep(1, 'member')['key']);
        self::assertContains(WorkflowEventType::STEP_STARTED, $this->events->typesFor(1));
    }

    public function testCompletingStepsActivatesTheNextInOrder(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');

        $view = $engine->completeStep(1, 'member', 'drafted');
        self::assertSame(WorkflowStatus::IN_PROGRESS, $view['status']);
        self::assertSame('review', $view['currentStep']);
        self::assertSame(
            ['draft' => WorkflowStepStatus::COMPLETED, 'review' => WorkflowStepStatus::AVAILABLE, 'sign' => WorkflowStepStatus::PENDING],
            $this->stepStatuses($view),
        );
        self::assertSame('member', $view['steps'][0]['completedBy']);
        self::assertSame('drafted', $view['steps'][0]['reason']);
        self::assertSame('complete', $view['steps'][0]['actionTaken']);
        self::assertNotNull($view['steps'][1]['enteredAt']);
        self::assertSame('review', $engine->getActiveStep(1, 'member')['key']);

        $view = $engine->completeStep(1, 'reviewer');
        self::assertSame('sign', $view['currentStep']);
        self::assertSame(
            ['draft' => WorkflowStepStatus::COMPLETED, 'review' => WorkflowStepStatus::COMPLETED, 'sign' => WorkflowStepStatus::AVAILABLE],
            $this->stepStatuses($view),
        );
        // Still in progress — a later step is available after a completion, nothing is "submitted" any more.
        self::assertSame(WorkflowStatus::IN_PROGRESS, $view['status']);
    }

    public function testCompletingAnAvailableStepStartsItFirst(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');
        $view = $engine->completeStep(1, 'member');
        self::assertNotNull($view['steps'][0]['startedAt']);
        $types = $this->events->typesFor(1);
        $started   = array_search(WorkflowEventType::STEP_STARTED, $types, true);
        $completed = array_search(WorkflowEventType::STEP_COMPLETED, $types, true);
        self::assertNotFalse($started);
        self::assertNotFalse($completed);
        self::assertLessThan($completed, $started);
    }

    public function testCompletingTheFinalStepCompletesTheWorkflow(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');
        $engine->completeStep(1, 'member');
        $engine->completeStep(1, 'reviewer');
        $view = $engine->completeStep(1, 'owner', 'signed');

        self::assertSame(WorkflowStatus::COMPLETED, $view['status']);
        self::assertSame(WorkflowEngine::OUTCOME_COMPLETED, $view['outcome']);
        self::assertNull($view['currentStep']);
        self::assertSame('owner', $view['endedBy']);
        self::assertNotNull($view['endedAt']);
        self::assertSame(
            ['draft' => WorkflowStepStatus::COMPLETED, 'review' => WorkflowStepStatus::COMPLETED, 'sign' => WorkflowStepStatus::COMPLETED],
            $this->stepStatuses($view),
        );
        self::assertNull($engine->getActiveStep(1, 'owner'));
        // v4.10.21 — `archived` is the last event of every licensed ending;
        // `completed` is the one before it.
        self::assertSame(
            [WorkflowEventType::COMPLETED, WorkflowEventType::ARCHIVED],
            array_slice($this->events->typesFor(1), -2),
        );
        self::assertContains('workflow.three.completed', array_column($this->audit, 'event'));
        self::assertFalse($view['viewer']['canAct']);
        self::assertFalse($view['viewer']['canCancel']);
    }

    public function testRejectingAStepRejectsTheWorkflowAndSkipsTheRest(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');
        $engine->completeStep(1, 'member');
        $view = $engine->rejectStep(1, 'reviewer', 'not good enough');

        self::assertSame(WorkflowStatus::REJECTED, $view['status']);
        self::assertSame(WorkflowEngine::OUTCOME_REJECTED, $view['outcome']);
        self::assertSame('reviewer', $view['endedBy']);
        self::assertSame(
            ['draft' => WorkflowStepStatus::COMPLETED, 'review' => WorkflowStepStatus::REJECTED, 'sign' => WorkflowStepStatus::SKIPPED],
            $this->stepStatuses($view),
        );
        self::assertSame('not good enough', $view['steps'][1]['reason']);
        $types = $this->events->typesFor(1);
        self::assertContains(WorkflowEventType::STEP_REJECTED, $types);
        self::assertContains(WorkflowEventType::STEP_SKIPPED, $types);
        self::assertSame([WorkflowEventType::REJECTED, WorkflowEventType::ARCHIVED], array_slice($types, -2));
    }

    public function testRejectingNeedsAReason(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');
        $this->expectException(ValidationException::class);
        $engine->rejectStep(1, 'member', '  ');
    }

    public function testCancellingClosesEveryOpenStep(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');
        $engine->completeStep(1, 'member');
        $engine->startStep(1, 'reviewer');
        $view = $engine->cancel(1, 'member', 'changed my mind');

        self::assertSame(WorkflowStatus::CANCELLED, $view['status']);
        self::assertSame(WorkflowEngine::OUTCOME_CANCELLED, $view['outcome']);
        self::assertSame(
            ['draft' => WorkflowStepStatus::COMPLETED, 'review' => WorkflowStepStatus::CANCELLED, 'sign' => WorkflowStepStatus::CANCELLED],
            $this->stepStatuses($view),
        );
        self::assertSame(
            [WorkflowEventType::CANCELLED, WorkflowEventType::ARCHIVED],
            array_slice($this->events->typesFor(1), -2),
        );
    }

    public function testInformationRoundTrip(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');
        $engine->completeStep(1, 'member');
        $engine->startStep(1, 'reviewer');

        $view = $engine->requestInformation(1, 'reviewer', 'which version?');
        self::assertSame(WorkflowStatus::WAITING, $view['status']);
        self::assertSame(WorkflowStepStatus::WAITING_FOR_INFORMATION, $view['steps'][1]['status']);

        // Any participant may answer — the initiator here; an outsider may not.
        try {
            $engine->provideInformation(1, 'outsider', 'v2');
            self::fail('an outsider answered');
        } catch (AccessDeniedException) {
        }
        $view = $engine->provideInformation(1, 'member', 'v2');
        self::assertSame(WorkflowStatus::IN_PROGRESS, $view['status']);
        self::assertSame(WorkflowStepStatus::IN_PROGRESS, $view['steps'][1]['status']);
        self::assertSame([WorkflowEventType::INFORMATION_REQUESTED, WorkflowEventType::INFORMATION_PROVIDED],
            array_values(array_filter($this->events->typesFor(1), static fn (string $t): bool => str_starts_with($t, 'information_'))));
    }

    public function testBlockAndUnblock(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');
        $engine->completeStep(1, 'member');

        $view = $engine->block(1, 'reviewer', 'waiting on legal');
        self::assertSame(WorkflowStatus::BLOCKED, $view['status']);
        // Steps are untouched by a block.
        self::assertSame(WorkflowStepStatus::AVAILABLE, $view['steps'][1]['status']);
        self::assertFalse($view['viewer']['canAct']);

        try {
            $engine->completeStep(1, 'reviewer');
            self::fail('acted on a blocked workflow');
        } catch (WorkflowTransitionException) {
        }

        // A Nextcloud administrator may lift it without holding the step.
        $view = $engine->unblock(1, 'ncadmin');
        self::assertSame(WorkflowStatus::IN_PROGRESS, $view['status']);
        self::assertSame([WorkflowEventType::BLOCKED, WorkflowEventType::UNBLOCKED],
            array_values(array_filter($this->events->typesFor(1), static fn (string $t): bool => in_array($t, ['blocked', 'unblocked'], true))));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Invalid transitions
    // ──────────────────────────────────────────────────────────────────────

    public function testActingOnAnEndedWorkflowIsRefused(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');
        $engine->cancel(1, 'member');

        foreach ([
            fn () => $engine->startStep(1, 'member'),
            fn () => $engine->completeStep(1, 'member'),
            fn () => $engine->rejectStep(1, 'member', 'x'),
            fn () => $engine->cancel(1, 'member'),
            fn () => $engine->block(1, 'member', 'x'),
            fn () => $engine->requestInformation(1, 'member', 'x'),
        ] as $i => $call) {
            try {
                $call();
                self::fail('call ' . $i . ' succeeded on an ended workflow');
            } catch (WorkflowTransitionException) {
                // expected
            }
        }
        self::assertSame(WorkflowStatus::CANCELLED, $this->instances->rows[1]->getStatus());
    }

    public function testAStepCannotBeStartedTwice(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');
        $engine->startStep(1, 'member');
        $this->expectException(WorkflowTransitionException::class);
        $engine->startStep(1, 'member');
    }

    public function testAStepWaitingForInformationCannotBeCompleted(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');
        $engine->startStep(1, 'member');
        $engine->requestInformation(1, 'member', 'need the file');
        $this->expectException(WorkflowTransitionException::class);
        $engine->completeStep(1, 'member');
    }

    public function testInformationCannotBeProvidedWhenNobodyAsked(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');
        $engine->startStep(1, 'member');
        $this->expectException(WorkflowTransitionException::class);
        $engine->provideInformation(1, 'member', 'here');
    }

    public function testUnblockingAnUnblockedWorkflowIsRefused(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');
        $this->expectException(WorkflowTransitionException::class);
        $engine->unblock(1, 'ncadmin');
    }

    public function testARefusedTransitionRollsBackAndLeavesTheRowsAlone(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');
        $before = count($this->events->rows);
        try {
            $engine->provideInformation(1, 'member', 'unasked');
        } catch (WorkflowTransitionException) {
        }
        self::assertSame($before, count($this->events->rows));
        self::assertSame(WorkflowStatus::SUBMITTED, $this->instances->rows[1]->getStatus());
        self::assertSame(1, $this->rolledBack);
    }

    public function testTheTransitionTablesAreTheRule(): void {
        self::assertTrue(WorkflowStatus::canTransition(WorkflowStatus::SUBMITTED, WorkflowStatus::IN_PROGRESS));
        self::assertTrue(WorkflowStatus::canTransition(WorkflowStatus::BLOCKED, WorkflowStatus::WAITING));
        self::assertFalse(WorkflowStatus::canTransition(WorkflowStatus::COMPLETED, WorkflowStatus::IN_PROGRESS));
        self::assertFalse(WorkflowStatus::canTransition(WorkflowStatus::CANCELLED, WorkflowStatus::SUBMITTED));
        // v4.10.31 — an admin of the service team may close a request while
        // it waits (outcome `closed`); the *step* still cannot complete
        // while it waits for an answer (asserted below).
        self::assertTrue(WorkflowStatus::canTransition(WorkflowStatus::WAITING, WorkflowStatus::COMPLETED));
        self::assertTrue(WorkflowStatus::canTransition(WorkflowStatus::WAITING, WorkflowStatus::WAITING));

        self::assertTrue(WorkflowStepStatus::canTransition(WorkflowStepStatus::PENDING, WorkflowStepStatus::AVAILABLE));
        self::assertFalse(WorkflowStepStatus::canTransition(WorkflowStepStatus::PENDING, WorkflowStepStatus::IN_PROGRESS));
        self::assertFalse(WorkflowStepStatus::canTransition(WorkflowStepStatus::PENDING, WorkflowStepStatus::COMPLETED));
        self::assertFalse(WorkflowStepStatus::canTransition(WorkflowStepStatus::COMPLETED, WorkflowStepStatus::AVAILABLE));
        self::assertFalse(WorkflowStepStatus::canTransition(WorkflowStepStatus::WAITING_FOR_INFORMATION, WorkflowStepStatus::COMPLETED));
        self::assertTrue(WorkflowStepStatus::canTransition(WorkflowStepStatus::AVAILABLE, WorkflowStepStatus::REJECTED));

        $this->expectException(WorkflowTransitionException::class);
        WorkflowStepStatus::assertTransition(WorkflowStepStatus::SKIPPED, WorkflowStepStatus::AVAILABLE);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Definition version
    // ──────────────────────────────────────────────────────────────────────

    public function testTheDefinitionVersionIsFixedWhenTheInstanceStarts(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');

        // Version 2 ships with four steps while the instance is running.
        $this->registry->register(new FixtureDefinition('three', 2, [
            new WorkflowStepDefinition('draft',  'Member drafts',   WorkflowActor::team()),
            new WorkflowStepDefinition('legal',  'Legal checks',    WorkflowActor::group('legal')),
            new WorkflowStepDefinition('review', 'Reviewers check', WorkflowActor::group('reviewers')),
            new WorkflowStepDefinition('sign',   'Owner signs',     WorkflowActor::teamOwner()),
        ]));

        $view = $engine->get(1, 'member');
        self::assertSame(1, $view['definitionVersion']);
        self::assertSame(['draft', 'review', 'sign'], array_column($view['steps'], 'key'));

        // It runs to its end on the steps it was created with.
        $engine->completeStep(1, 'member');
        $engine->completeStep(1, 'reviewer');
        $view = $engine->completeStep(1, 'owner');
        self::assertSame(WorkflowStatus::COMPLETED, $view['status']);
        self::assertSame(1, $view['definitionVersion']);

        // A new instance is on version 2.
        $this->resolver->levels['t2'] = ['member' => 1];
        $fresh = $engine->create('three', 't2', 'member');
        self::assertSame(2, $fresh['definitionVersion']);
        self::assertSame(['draft', 'legal', 'review', 'sign'], array_column($fresh['steps'], 'key'));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Participants
    // ──────────────────────────────────────────────────────────────────────

    public function testParticipantsStayConnectedAfterTheirStepUntilTheEnd(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');
        $rosterAfterCreate = count($engine->get(1, 'member')['participants']);

        $engine->completeStep(1, 'member');
        // member's step is done; member still sees the workflow and is a participant.
        $view = $engine->get(1, 'member');
        self::assertTrue($view['viewer']['isParticipant']);
        self::assertFalse($view['viewer']['canAct']);
        self::assertGreaterThanOrEqual($rosterAfterCreate, count($view['participants']));

        $engine->completeStep(1, 'reviewer');
        // reviewer acted for the group: recorded in their own name, the group row kept.
        $view = $engine->get(1, 'reviewer');
        $keys = array_map(static fn (array $p): string => $p['actor']['type'] . ':' . $p['actor']['id'], $view['participants']);
        self::assertContains('user:reviewer', $keys);
        self::assertContains('group:reviewers', $keys);
        self::assertContains('user:member', $keys);
        self::assertTrue($view['viewer']['isParticipant']);

        $view = $engine->completeStep(1, 'owner');
        self::assertSame(WorkflowStatus::COMPLETED, $view['status']);
        // Nothing was removed on the way to completion.
        foreach ($this->participants->rows as $row) {
            self::assertNull($row->getRemovedAt());
        }
        // member (initiator), team, reviewers, team_owner (responsible), reviewer and owner (acted).
        self::assertCount(6, $view['participants']);
        self::assertTrue($engine->get(1, 'member')['viewer']['isParticipant']);
        self::assertContains(1, array_column($engine->listForParticipant('member'), 'id'));
        self::assertContains(1, array_column($engine->listForParticipant('reviewer'), 'id'));
    }

    public function testListingFollowsDirectActorsGroupsAndTeamRoles(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');          // 1: draft (team) available
        $this->resolver->levels['t2'] = ['owner' => 9, 'member' => 1];
        $engine->create('three', 't2', 'member');                // 2
        $engine->completeStep(2, 'member');
        $engine->completeStep(2, 'reviewer');                    // 2: sign (owner) available

        self::assertSame([2, 1], array_column($engine->listForParticipant('member'), 'id'));
        // The owner holds team_owner on both teams.
        self::assertSame([2, 1], array_column($engine->listForParticipant('owner'), 'id'));
        // A moderator of t1 holds `team` there and nothing on t2.
        self::assertSame([1], array_column($engine->listForParticipant('mod'), 'id'));
        // The reviewers group is a participant of both.
        self::assertSame([2, 1], array_column($engine->listForParticipant('reviewer'), 'id'));
        // An indirect member of t1 holds the `team` actor there.
        self::assertSame([1], array_column($engine->listForParticipant('viagroup'), 'id'));
        // An outsider sees nothing; a Nextcloud admin is not a participant by being an admin.
        self::assertSame([], $engine->listForParticipant('outsider'));
        self::assertSame([], $engine->listForParticipant('ncadmin'));

        // The status filter.
        $engine->cancel(1, 'member');
        self::assertSame([1], array_column($engine->listForParticipant('member', WorkflowStatus::CANCELLED), 'id'));
        self::assertSame([2], array_column($engine->listForParticipant('member', WorkflowStatus::IN_PROGRESS), 'id'));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Permissions
    // ──────────────────────────────────────────────────────────────────────

    public function testOnlyAHolderOfTheStepActorMayActOnIt(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');

        // Step 1: team — an outsider may not; an indirect member may.
        try {
            $engine->startStep(1, 'outsider');
            self::fail('an outsider started a team step');
        } catch (AccessDeniedException) {
        }
        $engine->completeStep(1, 'viagroup');

        // Step 2: the reviewers group — a team owner is not in it.
        try {
            $engine->completeStep(1, 'owner');
            self::fail('the owner acted for the reviewers group');
        } catch (AccessDeniedException) {
        }
        self::assertFalse($engine->get(1, 'owner')['viewer']['canAct']);
        self::assertTrue($engine->get(1, 'reviewer')['viewer']['canAct']);
        $engine->completeStep(1, 'reviewer');

        // Step 3: the team owner — an admin (8) and a moderator (4) are not the owner.
        foreach (['admin8', 'mod', 'member', 'reviewer', 'ncadmin'] as $uid) {
            try {
                $engine->completeStep(1, $uid);
                self::fail($uid . ' signed as the owner');
            } catch (AccessDeniedException) {
            }
        }
        self::assertSame(WorkflowStatus::COMPLETED, $engine->completeStep(1, 'owner')['status']);
    }

    public function testATeamModeratorStepAcceptsModeratorsAdminsAndOwners(): void {
        $this->registry->register(new FixtureDefinition('modstep', 1, [
            new WorkflowStepDefinition('check', 'Moderator checks', WorkflowActor::teamModerator()),
        ]));
        foreach (['mod' => true, 'admin8' => true, 'owner' => true, 'member' => false, 'viagroup' => false] as $uid => $allowed) {
            $engine = $this->engine();
            $this->instances->rows = [];
            $this->steps->rows = [];
            $this->participants->rows = [];
            $id = $engine->create('modstep', self::TEAM, 'member')['id'];
            try {
                $engine->completeStep($id, $uid);
                self::assertTrue($allowed, $uid . ' should have been refused');
            } catch (AccessDeniedException) {
                self::assertFalse($allowed, $uid . ' should have been allowed');
            }
        }
    }

    public function testAUserStepIsThatUserOnly(): void {
        $this->registry->register(new FixtureDefinition('named', 1, [
            new WorkflowStepDefinition('do', 'Owner does', WorkflowActor::user('owner')),
        ]));
        $engine = $this->engine();
        $engine->create('named', self::TEAM, 'member');
        try {
            $engine->completeStep(1, 'admin8');
            self::fail('somebody else acted on a named-user step');
        } catch (AccessDeniedException) {
        }
        self::assertSame(WorkflowStatus::COMPLETED, $engine->completeStep(1, 'owner')['status']);
    }

    public function testOnlyTheInitiatorOrANextcloudAdministratorMayCancel(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');
        foreach (['owner', 'reviewer', 'outsider'] as $uid) {
            try {
                $engine->cancel(1, $uid);
                self::fail($uid . ' cancelled somebody else\'s workflow');
            } catch (AccessDeniedException) {
            }
        }
        self::assertTrue($engine->get(1, 'member')['viewer']['canCancel']);
        self::assertFalse($engine->get(1, 'owner')['viewer']['canCancel']);
        self::assertSame(WorkflowStatus::CANCELLED, $engine->cancel(1, 'ncadmin', 'housekeeping')['status']);
    }

    public function testViewingIsForParticipantsAndAdministrators(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');
        self::assertSame(1, $engine->get(1, 'owner')['id']);
        self::assertSame(1, $engine->get(1, 'reviewer')['id']);
        self::assertSame(1, $engine->get(1, 'ncadmin')['id']);
        self::assertNotEmpty($engine->listEvents(1, 'member'));
        // admin8 holds no named actor, but holds `team` (step 1's actor): a participant.
        self::assertSame(1, $engine->get(1, 'admin8')['id']);

        try {
            $engine->get(1, 'outsider');
            self::fail('an outsider viewed a workflow they are not part of');
        } catch (AccessDeniedException) {
        }
        try {
            $engine->listEvents(1, 'outsider');
            self::fail('an outsider read events of a workflow they are not part of');
        } catch (AccessDeniedException) {
        }
        $this->expectException(NotFoundException::class);
        $engine->get(99, 'member');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Events
    // ──────────────────────────────────────────────────────────────────────

    public function testEveryStateChangeWritesAnEvent(): void {
        $engine = $this->engine();
        $engine->create('three', self::TEAM, 'member');
        $engine->startStep(1, 'member');
        $engine->requestInformation(1, 'member', 'q');
        $engine->provideInformation(1, 'owner', 'a');
        $engine->completeStep(1, 'member');
        $engine->block(1, 'ncadmin', 'hold');
        $engine->unblock(1, 'ncadmin');
        $engine->completeStep(1, 'reviewer');
        $engine->rejectStep(1, 'owner', 'no');

        $types = array_values(array_filter($this->events->typesFor(1), static fn (string $t): bool => $t !== WorkflowEventType::PARTICIPANT_ADDED));
        self::assertSame([
            WorkflowEventType::CREATED,
            WorkflowEventType::STEP_AVAILABLE,
            WorkflowEventType::STEP_STARTED,
            WorkflowEventType::INFORMATION_REQUESTED,
            WorkflowEventType::INFORMATION_PROVIDED,
            WorkflowEventType::STEP_COMPLETED,
            WorkflowEventType::STEP_AVAILABLE,
            WorkflowEventType::BLOCKED,
            WorkflowEventType::UNBLOCKED,
            WorkflowEventType::STEP_STARTED,
            WorkflowEventType::STEP_COMPLETED,
            WorkflowEventType::STEP_AVAILABLE,
            WorkflowEventType::STEP_REJECTED,
            WorkflowEventType::REJECTED,
            // v4.10.21 — the licensed ending seals the log and says so.
            WorkflowEventType::ARCHIVED,
        ], $types);

        $history = $engine->listEvents(1, 'member');
        // v4.10.21 — `archived` is last now, so the rejection is named
        // rather than taken off the end.
        $rejection = $history[array_search(WorkflowEventType::REJECTED, array_column($history, 'type'), true)];
        self::assertSame('no', $rejection['payload']['reason']);
        self::assertSame('owner', $rejection['actorUid']);
        self::assertSame(WorkflowEventType::ARCHIVED, end($history)['type']);
        $first = $history[array_search(WorkflowEventType::CREATED, array_column($history, 'type'), true)];
        self::assertSame(1, $first['payload']['definitionVersion']);
        // Every write went through a transaction that committed.
        self::assertSame($this->begun, $this->committed);
        self::assertSame(0, $this->rolledBack);
    }
    /**
     * A ServiceTeamService that says "no service team, nobody eligible" -
     * the answer on every instance that has none, which is every case in
     * this file.
     */
    // ──────────────────────────────────────────────────────────────────────
    // Definition hooks (v4.10.29)
    // ──────────────────────────────────────────────────────────────────────

    private function hooked(): HookedFixtureDefinition {
        $def = new HookedFixtureDefinition('hooked', 1, [
            new WorkflowStepDefinition('draft',  'Member drafts',   WorkflowActor::team()),
            new WorkflowStepDefinition('review', 'Reviewers check', WorkflowActor::group('reviewers')),
            new WorkflowStepDefinition('sign',   'Owner signs',     WorkflowActor::teamOwner()),
        ]);
        $this->registry->register($def);
        return $def;
    }

    public function testAHookedDefinitionChecksTheStartAgainstTheTeamAndItsAnswerIsStored(): void {
        $def  = $this->hooked();
        $view = $this->engine()->create('hooked', self::TEAM, 'member', ['reason' => 'x']);
        self::assertSame([['validateForTeam', self::TEAM]], $def->calls);
        self::assertSame('yes', $view['data']['recordedByServer'], 'what the hook returns is what is stored');
    }

    /** The side effect runs inside the completion, and a refusal leaves the step unanswered. */
    public function testAStepEffectRunsOnCompletionAndARefusalRollsBack(): void {
        $def    = $this->hooked();
        $engine = $this->engine();
        $engine->create('hooked', self::TEAM, 'member');
        $engine->completeStep(1, 'member');
        self::assertSame(['onStepCompleted', 'draft'], $def->calls[1]);

        $def->refuseStep = 'review';
        $rolledBack      = $this->rolledBack;
        try {
            $engine->completeStep(1, 'reviewer');
            self::fail('a refused effect completed the step');
        } catch (WorkflowTransitionException) {
            // expected
        }
        self::assertSame($rolledBack + 1, $this->rolledBack);
        self::assertSame(1, count(array_filter(
            $this->events->typesFor(1),
            static fn (string $t): bool => $t === WorkflowEventType::STEP_COMPLETED,
        )), 'only the draft was completed');
        self::assertSame(['onStepCompleted', 'review'], end($def->calls));

        $def->refuseStep = null;
        $view = $engine->completeStep(1, 'reviewer');
        self::assertSame(WorkflowStepStatus::COMPLETED, $this->stepStatuses($view)['review']);
    }

    public function testTheActiveStepCarriesTheDefinitionsOwnWords(): void {
        $this->hooked();
        $engine = $this->engine();
        $engine->create('hooked', self::TEAM, 'member');
        self::assertSame([], $engine->get(1, 'member')['actionLabels'], 'the draft uses the engine words');
        $view = $engine->completeStep(1, 'member');
        self::assertSame(['complete' => 'Grant', 'reject' => 'Decline'], $view['actionLabels']);

        // A definition without hooks never has any.
        $engine->create('three', self::TEAM, 'member');
        self::assertSame([], $engine->get(2, 'member')['actionLabels']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Import (v4.10.29)
    // ──────────────────────────────────────────────────────────────────────

    /** An open request arrives at its step with its history, and nobody is told. */
    public function testAnImportedOpenWorkflowArrivesAtItsStepWithoutNotifying(): void {
        $view = $this->engine()->importInstance('three', self::TEAM, 'member', 500, ['reason' => 'old'], [
            'draft' => ['status' => WorkflowStepStatus::COMPLETED, 'by' => 'member', 'at' => 500],
        ]);

        self::assertSame(WorkflowStatus::IN_PROGRESS, $view['status']);
        self::assertSame('review', $view['currentStep']);
        self::assertSame(500, $view['startedAt']);
        self::assertSame(
            ['draft' => WorkflowStepStatus::COMPLETED, 'review' => WorkflowStepStatus::AVAILABLE, 'sign' => WorkflowStepStatus::PENDING],
            $this->stepStatuses($view),
        );
        self::assertSame(500, $view['steps'][0]['completedAt'], 'the original time, not now');
        self::assertSame([], $this->notified, 'an import tells nobody');
        self::assertContains('workflow.three.imported', array_column($this->audit, 'event'));

        // …and it carries on like any other.
        $view = $this->engine()->completeStep(1, 'reviewer');
        self::assertSame('sign', $view['currentStep']);
    }

    public function testAnImportedRejectionEndsTheWorkflowWithItsReason(): void {
        $view = $this->engine()->importInstance('three', self::TEAM, 'member', 500, [], [
            'draft'  => ['status' => WorkflowStepStatus::COMPLETED, 'by' => 'member', 'at' => 500],
            'review' => ['status' => WorkflowStepStatus::REJECTED, 'by' => 'reviewer', 'at' => 600, 'reason' => 'Not now.'],
        ]);

        self::assertSame(WorkflowStatus::REJECTED, $view['status']);
        self::assertSame(WorkflowEngine::OUTCOME_REJECTED, $view['outcome']);
        self::assertSame(600, $view['endedAt']);
        self::assertSame('reviewer', $view['endedBy']);
        self::assertSame(WorkflowStepStatus::SKIPPED, $this->stepStatuses($view)['sign']);
        self::assertSame('Not now.', $view['steps'][1]['reason']);
        self::assertSame([], $this->notified);
    }

    /** A placeholder nobody holds takes the fallback, or the import is refused. */
    public function testAnImportedPlaceholderTakesTheFallbackActor(): void {
        $this->registry->register(new FixtureDefinition('desk', 1, [
            new WorkflowStepDefinition('ask',    'Ask',    WorkflowActor::initiator(), true),
            new WorkflowStepDefinition('handle', 'Handle', WorkflowActor::serviceAgentPlaceholder()),
        ]));

        try {
            $this->engine()->importInstance('desk', self::TEAM, 'member', 500, [], []);
            self::fail('imported a step nobody holds');
        } catch (WorkflowTransitionException) {
            // expected
        }

        $view = $this->engine()->importInstance('desk', self::TEAM, 'member', 500, [], [
            'ask' => ['status' => WorkflowStepStatus::COMPLETED, 'by' => 'member', 'at' => 500],
        ], WorkflowActor::group('admin'));
        self::assertSame(['type' => 'group', 'id' => 'admin'], $view['steps'][1]['actor']);
        self::assertSame(WorkflowStepStatus::AVAILABLE, $view['steps'][1]['status']);
    }

    private function noServiceTeams(): ServiceTeamService {
        $mock = $this->createMock(ServiceTeamService::class);
        $mock->method('isEligibleAgent')->willReturn(false);
        $mock->method('isServiceOwner')->willReturn(false);
        $mock->method('isActiveServiceTeam')->willReturn(false);
        $mock->method('get')->willReturn(null);
        $mock->method('serviceTeamForDefinition')->willReturn(null);
        $mock->method('eligibleAgents')->willReturn([]);
        return $mock;
    }


    // ──────────────────────────────────────────────────────────────────────
    // v4.10.50 — workflows TeamHub starts itself (the team adoption)
    // ──────────────────────────────────────────────────────────────────────

    private function systemStarted(): SystemFixtureDefinition {
        $def = new SystemFixtureDefinition('system', 1, [
            new WorkflowStepDefinition('found',  'Team found',   WorkflowActor::initiator(), true),
            new WorkflowStepDefinition('decide', 'Admins decide', WorkflowActor::group('admin')),
        ], static fn (): bool => false, startable: false);
        $this->registry->register($def);
        return $def;
    }

    public function testASystemStartedDefinitionIsRefusedOnThePublicRoute(): void {
        $this->systemStarted();
        $this->expectException(NotFoundException::class);
        $this->engine()->create('system', self::TEAM, 'member');
    }

    public function testCreateForSystemOpensItInTheInitiatorsNameWithoutTheStartGates(): void {
        $this->systemStarted();
        $view = $this->engine()->createForSystem('system', self::TEAM, 'member', ['reason' => 'found']);

        self::assertSame('member', $view['startedBy']);
        self::assertSame('decide', $view['currentStep']);
        self::assertSame(
            ['found' => WorkflowStepStatus::COMPLETED, 'decide' => WorkflowStepStatus::AVAILABLE],
            $this->stepStatuses($view),
        );
    }

    public function testCreateForSystemRefusesADefinitionWithoutTheMarker(): void {
        $this->expectException(NotFoundException::class);
        $this->engine()->createForSystem('three', self::TEAM, 'member');
    }

    public function testTheEndHookHearsARejectionWithItsReason(): void {
        $def    = $this->systemStarted();
        $engine = $this->engine();
        $engine->createForSystem('system', self::TEAM, 'member');
        $engine->rejectStep(1, 'ncadmin', 'not ours');

        self::assertSame([['rejected', 'ncadmin', 'not ours']], $def->ended);
    }

    public function testTheEndHookHearsAWithdrawal(): void {
        $def    = $this->systemStarted();
        $engine = $this->engine();
        $engine->createForSystem('system', self::TEAM, 'member');
        $engine->cancel(1, 'ncadmin', 'decided elsewhere');

        self::assertSame([['cancelled', 'ncadmin', 'decided elsewhere']], $def->ended);
    }

    public function testTheEndHookIsNotToldAboutACompletion(): void {
        $def    = $this->systemStarted();
        $engine = $this->engine();
        $engine->createForSystem('system', self::TEAM, 'member');
        $engine->completeStep(1, 'ncadmin');

        self::assertSame([], $def->ended);
    }

    public function testTheReferenceIsInTheViewForWhomItIsMeantWhileOpen(): void {
        $this->systemStarted();
        $engine = $this->engine();
        $engine->createForSystem('system', self::TEAM, 'member');

        self::assertSame(
            ['label' => 'Open the grid', 'url' => '/settings/admin/teamhub#team-adoption'],
            $engine->get(1, 'ncadmin')['reference'],
        );
        self::assertNull($engine->get(1, 'member')['reference']);

        $engine->completeStep(1, 'ncadmin');
        self::assertNull($engine->get(1, 'ncadmin')['reference']);
    }

}
