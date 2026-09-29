<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Exception\LicenseGateException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\WorkflowTransitionException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\Workflow\WorkflowEngine;
use OCA\TeamHub\Service\Workflow\WorkflowEventService;
use OCA\TeamHub\Service\Workflow\WorkflowLicenceTier;
use OCA\TeamHub\Service\Workflow\WorkflowNotificationService;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCA\TeamHub\Workflow\WorkflowEventType;
use OCA\TeamHub\Workflow\WorkflowStatus;
use OCA\TeamHub\Workflow\WorkflowStepDefinition;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The unlicensed workflow lifecycle (WorkflowHub phase 4, v4.10.16) —
 * `docs/unlicensed-workflow-data-lifecycle.md`.
 *
 * What an unlicensed instance may do with a built-in workflow, what its
 * participants are shown, what happens the moment one ends (everything
 * goes, in the same transaction), and what a licence change does to a
 * workflow that is already running.
 *
 * Roster on team `t1`: owner (9), mod (4), member (1); `itadmin` is in
 * group `it`; `ncadmin` is a Nextcloud administrator.
 */
class WorkflowUnlicensedLifecycleTest extends TestCase {

    private const TEAM = 't1';

    private InMemoryWorkflowInstanceMapper $instances;
    private InMemoryWorkflowStepMapper $steps;
    private InMemoryWorkflowParticipantMapper $participants;
    private InMemoryWorkflowEventMapper $events;
    private FakeActorResolver $resolver;
    private FakeLicenceTier $tier;
    private WorkflowDefinitionRegistry $registry;
    private ArchiveHarness $archive;
    /** @var array<int, array{event: string, actor: ?string}> */
    private array $audit = [];
    /** @var array<int, array{0: string, 1: array<int, mixed>}> */
    private array $notified = [];
    private int $now = 3_000_000;
    private int $rolledBack = 0;

    protected function setUp(): void {
        $this->instances    = new InMemoryWorkflowInstanceMapper();
        $this->steps        = new InMemoryWorkflowStepMapper();
        $this->participants = new InMemoryWorkflowParticipantMapper($this->instances);
        $this->events       = new InMemoryWorkflowEventMapper();
        $this->tier         = new FakeLicenceTier(WorkflowLicenceTier::BASIC);
        $this->archive      = new ArchiveHarness();

        $this->resolver = new FakeActorResolver();
        $this->resolver->levels[self::TEAM] = ['owner' => 9, 'mod' => 4, 'member' => 1];
        $this->resolver->groups = ['itadmin' => ['it'], 'ncadmin' => ['admin']];
        $this->resolver->admins = ['ncadmin'];

        $this->registry = new WorkflowDefinitionRegistry();
        // A built-in workflow explicitly allowed for unlicensed use:
        // the requester sends it, the group answers, the requester closes.
        $this->registry->register(new FixtureDefinition('allowed', 1, [
            new WorkflowStepDefinition('submit',  'Request submitted', WorkflowActor::initiator(), true),
            new WorkflowStepDefinition('process', 'Group processes',   WorkflowActor::group('it')),
            new WorkflowStepDefinition('confirm', 'Requester confirms', WorkflowActor::initiator()),
        ], null, WorkflowDefinitionRegistry::CONCURRENCY_UNBOUNDED));
        // One that is not allowed unlicensed.
        $this->registry->register(new FixtureDefinition('licensed_only', 1, [
            new WorkflowStepDefinition('decide', 'Owner decides', WorkflowActor::teamOwner()),
        ], null, WorkflowDefinitionRegistry::CONCURRENCY_UNBOUNDED, false, false));
    }

    private function engine(): WorkflowEngine {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => ++$this->now);

        $audit = $this->createMock(AuditService::class);
        $audit->method('log')->willReturnCallback(function (string $team, string $event, ?string $actor): void {
            $this->audit[] = ['event' => $event, 'actor' => $actor];
        });

        $db = $this->createMock(IDBConnection::class);
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
            // v4.10.21 - the real archive service over in-memory tables. It
            // is what makes these cases say something: on the basic tier it
            // must never be reached, and the archive table must stay empty
            // through every unlicensed ending.
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

    /** Open the allowed workflow; `submit` auto-completes, `process` is with the group. */
    private function open(WorkflowEngine $engine): array {
        return $engine->create('allowed', self::TEAM, 'member', ['reason' => 'because']);
    }

    /** @return string[] notifier methods called, in order */
    private function notifications(): array {
        return array_map(static fn (array $c): string => $c[0], $this->notified);
    }

    /** Every row of every workflow table, for the "nothing is left" assertions. */
    private function rowCounts(): array {
        return [
            'instances'    => count($this->instances->rows),
            'steps'        => count($this->steps->rows),
            'participants' => count($this->participants->rows),
            'events'       => count($this->events->rows),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1–3. What an unlicensed instance may start and take part in
    // ──────────────────────────────────────────────────────────────────────

    public function testAnUnlicensedInstanceMayStartABuiltInWorkflowThatAllowsIt(): void {
        $view = $this->open($this->engine());

        self::assertSame('allowed', $view['definitionKey']);
        self::assertSame(WorkflowStatus::IN_PROGRESS, $view['status']);
        self::assertFalse($view['purged']);
        // The holders of the step that is now theirs are told (after the
        // withdrawal that precedes every hand-over).
        self::assertSame(['withdraw', 'stepAvailable'], $this->notifications());
    }

    public function testAnUnlicensedInstanceMayNotStartAWorkflowThatIsNotAllowedUnlicensed(): void {
        try {
            $this->engine()->create('licensed_only', self::TEAM, 'owner', []);
            self::fail('expected a licence gate');
        } catch (LicenseGateException $e) {
            self::assertSame('unlicensed', $e->getEnforcementLevel());
        }
        self::assertSame(0, count($this->instances->rows), 'nothing was written');
    }

    public function testTheDefinitionListHidesWhatCannotBeStartedUnlicensed(): void {
        $engine = $this->engine();
        self::assertSame(['allowed'], array_column($engine->describeDefinitions(), 'key'));

        $this->tier->set(WorkflowLicenceTier::FULL);
        self::assertEqualsCanonicalizing(['allowed', 'licensed_only'], array_column($engine->describeDefinitions(), 'key'));
    }

    public function testAParticipantTakesPartInAnUnlicensedWorkflowThroughTheirGroup(): void {
        $engine = $this->engine();
        $this->open($engine);

        // Action required for the group's member, waiting for the requester.
        $forGroup = $engine->listForParticipant('itadmin');
        self::assertCount(1, $forGroup);
        self::assertTrue($forGroup[0]['viewer']['isResponsible']);
        self::assertTrue($forGroup[0]['viewer']['canAct']);

        $forMember = $engine->listForParticipant('member');
        self::assertCount(1, $forMember);
        self::assertFalse($forMember[0]['viewer']['isResponsible']);
        self::assertTrue($forMember[0]['viewer']['isParticipant']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4–5. What the view shows, and what it does not
    // ──────────────────────────────────────────────────────────────────────

    public function testTheUnlicensedViewNamesTheResponsibleActorButNotTheStep(): void {
        $engine = $this->engine();
        $view   = $engine->get($this->open($engine)['id'], 'member');

        // Who is responsible, and what the viewer may do: both there.
        self::assertSame(['type' => 'group', 'id' => 'it'], $view['responsible']);
        self::assertArrayHasKey('canAct', $view['viewer']);
        // Which step, and how far along: neither.
        self::assertSame([], $view['steps'], 'no step list, so no step progress');
        self::assertNull($view['currentStep']);
    }

    public function testTheLicensedViewShowsTheStepsAndTheProgress(): void {
        $this->tier->set(WorkflowLicenceTier::FULL);
        $engine = $this->engine();
        $view   = $engine->get($this->open($engine)['id'], 'member');

        self::assertSame(['submit', 'process', 'confirm'], array_column($view['steps'], 'key'));
        self::assertSame('process', $view['currentStep']);
        self::assertSame(['type' => 'group', 'id' => 'it'], $view['responsible']);
    }

    public function testTheUnlicensedTimelineIsTheBasicOneWithoutStepBookkeeping(): void {
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];
        $engine->requestInformation($id, 'itadmin', 'how many people?');
        $engine->provideInformation($id, 'member', 'twelve');

        $types = array_column($engine->listEvents($id, 'member'), 'type');
        self::assertSame([
            WorkflowEventType::CREATED,
            WorkflowEventType::STEP_COMPLETED,
            WorkflowEventType::INFORMATION_REQUESTED,
            WorkflowEventType::INFORMATION_PROVIDED,
        ], $types);
        foreach ($engine->listEvents($id, 'member') as $event) {
            self::assertNull($event['stepKey'], 'a basic timeline names no step');
            self::assertNull($event['stepId']);
        }

        // The rows themselves are untouched: the licence decides what is
        // shown, never what was recorded.
        $this->tier->set(WorkflowLicenceTier::FULL);
        $full = $engine->listEvents($id, 'member');
        self::assertContains(WorkflowEventType::STEP_AVAILABLE, array_column($full, 'type'));
        self::assertContains('process', array_column($full, 'stepKey'));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. No status updates
    // ──────────────────────────────────────────────────────────────────────

    public function testAnUnlicensedParticipantMayNotAskForAStatusUpdate(): void {
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];

        $view = $engine->get($id, 'member');
        self::assertFalse($view['viewer']['canRequestStatus'], 'the action is not offered');

        try {
            $engine->requestStatus($id, 'member', 'any news?');
            self::fail('expected a licence gate');
        } catch (LicenseGateException $e) {
            self::assertSame('unlicensed', $e->getEnforcementLevel());
        }
        self::assertNotContains(WorkflowEventType::STATUS_REQUESTED, $this->events->typesFor($id));
        self::assertNotContains('statusRequested', $this->notifications(), 'nobody was rung');
    }

    public function testALicensedParticipantMayAskForAStatusUpdate(): void {
        $this->tier->set(WorkflowLicenceTier::FULL);
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];

        self::assertTrue($engine->get($id, 'member')['viewer']['canRequestStatus']);
        $engine->requestStatus($id, 'member', 'any news?');
        self::assertContains(WorkflowEventType::STATUS_REQUESTED, $this->events->typesFor($id));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 10. No completed history
    // ──────────────────────────────────────────────────────────────────────

    public function testAnEndedWorkflowARetainingLicenceKeptIsHiddenWhenTheLicenceGoes(): void {
        // Ended while licensed: the rows stay.
        $this->tier->set(WorkflowLicenceTier::FULL);
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];
        $engine->completeStep($id, 'itadmin');
        $engine->completeStep($id, 'member');
        self::assertSame(WorkflowStatus::COMPLETED, $engine->get($id, 'member')['status']);
        self::assertCount(1, $engine->listForParticipant('member', WorkflowStatus::COMPLETED));

        // The licence goes: the history is not reachable any more.
        $this->tier->set(WorkflowLicenceTier::BASIC);
        self::assertSame([], $engine->listForParticipant('member'), 'no Completed section');
        self::assertSame([], $engine->listForParticipant('member', WorkflowStatus::COMPLETED));
        $this->expectException(NotFoundException::class);
        $engine->get($id, 'member');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Completion on an unlicensed instance
    // ──────────────────────────────────────────────────────────────────────

    public function testCompletingTheFinalStepRemovesEverythingAndTellsEverybodyOnce(): void {
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];
        $engine->completeStep($id, 'itadmin');   // process → confirm
        $this->notified = [];

        $final = $engine->completeStep($id, 'member');

        // The caller sees the completion once — the temporary confirmation.
        self::assertSame(WorkflowStatus::COMPLETED, $final['status']);
        self::assertSame(WorkflowEngine::OUTCOME_COMPLETED, $final['outcome']);
        self::assertTrue($final['purged']);

        // The required notifications went out, after the commit, once.
        self::assertSame(['withdraw', 'ended'], $this->notifications());

        // And nothing is left in any of the four tables.
        self::assertSame(['instances' => 0, 'steps' => 0, 'participants' => 0, 'events' => 0], $this->rowCounts());
    }

    public function testACompletedWorkflowIsGoneFromMyWorkForEveryParticipant(): void {
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];
        $engine->completeStep($id, 'itadmin');
        $engine->completeStep($id, 'member');

        foreach (['member', 'itadmin', 'ncadmin'] as $uid) {
            self::assertSame([], $engine->listForParticipant($uid), $uid . ' still sees it');
        }
    }

    public function testACompletedWorkflowIsNotExposedThroughAnyUserFacingRead(): void {
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];
        $engine->completeStep($id, 'itadmin');
        $engine->completeStep($id, 'member');

        // Not for the participant, not for the administrator, not by status.
        foreach (['member', 'ncadmin'] as $uid) {
            try {
                $engine->get($id, $uid);
                self::fail('the workflow is still readable by ' . $uid);
            } catch (NotFoundException) {
                self::assertTrue(true);
            }
        }
        self::assertSame([], $engine->listForParticipant('member', WorkflowStatus::COMPLETED));
        $this->expectException(NotFoundException::class);
        $engine->listEvents($id, 'member');
    }

    public function testNoArchiveEntryAndNoTeamResultRecordAreCreated(): void {
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];
        $engine->completeStep($id, 'itadmin');
        $engine->completeStep($id, 'member');

        // The only trace of the workflow is the audit line the purge writes;
        // no table gained a row to stand in for the finished workflow.
        self::assertSame(['instances' => 0, 'steps' => 0, 'participants' => 0, 'events' => 0], $this->rowCounts());
        self::assertContains('workflow.allowed.purged', array_column($this->audit, 'event'));
    }

    public function testTheSubmissionTheNotesAndTheTimelineAreAllRemoved(): void {
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];
        $engine->requestInformation($id, 'itadmin', 'how many people?');
        $engine->provideInformation($id, 'member', 'twelve, in the Rotterdam office');
        $engine->completeStep($id, 'itadmin', 'created');
        $engine->completeStep($id, 'member');

        // The form submission, the two notes, the completion note and every
        // timeline row went with the instance — nothing is retained quietly.
        $everything = json_encode([$this->instances->rows, $this->steps->rows, $this->events->rows, $this->participants->rows]);
        self::assertStringNotContainsString('Rotterdam', (string)$everything);
        self::assertStringNotContainsString('how many people', (string)$everything);
        self::assertStringNotContainsString('because', (string)$everything);
    }

    public function testARejectedAndAWithdrawnWorkflowFollowTheSameRule(): void {
        // Rejected.
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];
        $view   = $engine->rejectStep($id, 'itadmin', 'not needed');
        self::assertSame(WorkflowStatus::REJECTED, $view['status']);
        self::assertTrue($view['purged']);
        self::assertSame(['instances' => 0, 'steps' => 0, 'participants' => 0, 'events' => 0], $this->rowCounts());

        // Withdrawn by the initiator.
        $id   = $this->open($engine)['id'];
        $view = $engine->cancel($id, 'member', 'changed my mind');
        self::assertSame(WorkflowStatus::CANCELLED, $view['status']);
        self::assertTrue($view['purged']);
        self::assertSame(['instances' => 0, 'steps' => 0, 'participants' => 0, 'events' => 0], $this->rowCounts());
    }

    public function testALicensedInstanceKeepsTheEndedWorkflowInstead(): void {
        $this->tier->set(WorkflowLicenceTier::FULL);
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];
        $engine->completeStep($id, 'itadmin');
        $view = $engine->completeStep($id, 'member');

        self::assertFalse($view['purged']);
        self::assertSame(1, count($this->instances->rows));
        self::assertNotSame(0, count($this->events->rows));
        self::assertSame(WorkflowStatus::COMPLETED, $engine->get($id, 'member')['status']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Deletion that fails, and duplicate processing
    // ──────────────────────────────────────────────────────────────────────

    public function testAFailingDeletionRollsTheCompletionBackInsteadOfLeavingHalfAWorkflow(): void {
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];
        $engine->completeStep($id, 'itadmin');
        $this->notified = [];
        // The first statement of the purge fails, the way a database that
        // goes away mid-transaction would make it fail.
        $this->events->failDelete = true;

        try {
            $engine->completeStep($id, 'member');
            self::fail('the failing delete should have propagated');
        } catch (\RuntimeException $e) {
            self::assertSame('delete failed', $e->getMessage());
        }

        self::assertSame(1, $this->rolledBack, 'the whole transaction was rolled back');
        self::assertSame([], $this->notifications(), 'a rolled-back completion tells nobody');
        // Nothing was removed: the workflow is whole, and the participants
        // keep it in My Work until somebody completes it again. (The status
        // the transaction had already written is the database's to roll
        // back; these mappers hold no transaction of their own.)
        self::assertArrayHasKey($id, $this->instances->rows);
        self::assertNotSame(0, count($this->steps->rows));
        self::assertNotSame(0, count($this->participants->rows));
        self::assertNotSame(0, count($this->events->rows));

        // What a retry does after a real rollback is the ordinary
        // completion path again, which the tests above cover; these mappers
        // cannot replay it because they did not roll the step row back.
    }

    public function testASecondCompletionOfAPurgedWorkflowIsNotFoundRatherThanADuplicate(): void {
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];
        $engine->completeStep($id, 'itadmin');
        $engine->completeStep($id, 'member');
        $this->notified = [];

        // A client that retried, or a job holding the id: the workflow is
        // gone, so the second call changes nothing and notifies nobody.
        try {
            $engine->completeStep($id, 'member');
            self::fail('expected a 404');
        } catch (NotFoundException) {
            self::assertTrue(true);
        }
        self::assertSame([], $this->notifications());
        self::assertSame(['instances' => 0, 'steps' => 0, 'participants' => 0, 'events' => 0], $this->rowCounts());
    }

    public function testAnEndedWorkflowARetainingLicenceKeptRefusesASecondCompletion(): void {
        // The other half of duplicate processing: on the licensed tier the
        // row is still there, and the transition table refuses (409).
        $this->tier->set(WorkflowLicenceTier::FULL);
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];
        $engine->completeStep($id, 'itadmin');
        $engine->completeStep($id, 'member');

        $this->expectException(WorkflowTransitionException::class);
        $engine->completeStep($id, 'member');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Licence transitions while a workflow is running
    // ──────────────────────────────────────────────────────────────────────

    public function testALicenceArrivingMidFlightLetsTheWorkflowContinueWithItsEventsIntact(): void {
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];
        $engine->requestInformation($id, 'itadmin', 'how many people?');
        $engine->provideInformation($id, 'member', 'twelve');
        $before = $this->events->typesFor($id);

        $this->tier->set(WorkflowLicenceTier::FULL);

        // Same instance, same steps, same history — nothing was rewritten.
        self::assertSame($before, $this->events->typesFor($id));
        $view = $engine->get($id, 'member');
        self::assertSame('allowed', $view['definitionKey']);
        self::assertSame(1, $view['definitionVersion']);
        self::assertSame(['submit', 'process', 'confirm'], array_column($view['steps'], 'key'));
        self::assertSame('process', $view['currentStep']);
        self::assertSame($id, $view['id']);

        // And it runs on under the licensed capabilities.
        self::assertTrue($engine->get($id, 'member')['viewer']['canRequestStatus']);
        $engine->completeStep($id, 'itadmin');
        $final = $engine->completeStep($id, 'member');
        self::assertSame(WorkflowStatus::COMPLETED, $final['status']);
        self::assertFalse($final['purged'], 'it ended while licensed, so it is kept');
    }

    public function testAnExpiringLicenceNeverBlocksCompletionOfARunningWorkflow(): void {
        // Started while licensed, and of a definition that could not be
        // started unlicensed at all.
        $this->tier->set(WorkflowLicenceTier::FULL);
        $engine = $this->engine();
        $id     = $engine->create('licensed_only', self::TEAM, 'member', [])['id'];

        $this->tier->set(WorkflowLicenceTier::BASIC);

        // Every step transition still works: completing, answering,
        // rejecting and withdrawing are never licence-gated.
        self::assertTrue($engine->get($id, 'owner')['viewer']['canAct']);
        $final = $engine->completeStep($id, 'owner');
        self::assertSame(WorkflowStatus::COMPLETED, $final['status']);
        self::assertTrue($final['purged'], 'and it follows the unlicensed rules on the way out');

        // The same for the other transitions on a workflow that is under
        // way: asking, answering, rejecting and withdrawing all still work.
        $this->tier->set(WorkflowLicenceTier::FULL);
        $open = $this->open($engine)['id'];
        $this->tier->set(WorkflowLicenceTier::BASIC);
        $engine->requestInformation($open, 'itadmin', 'how many people?');
        $engine->provideInformation($open, 'member', 'twelve');
        self::assertSame(WorkflowStatus::REJECTED, $engine->rejectStep($open, 'itadmin', 'not now')['status']);

        $this->tier->set(WorkflowLicenceTier::FULL);
        $open = $this->open($engine)['id'];
        $this->tier->set(WorkflowLicenceTier::BASIC);
        self::assertSame(WorkflowStatus::CANCELLED, $engine->cancel($open, 'member', 'changed my mind')['status']);
    }

    public function testAWorkflowCompletedAfterExpiryFollowsTheUnlicensedRules(): void {
        $this->tier->set(WorkflowLicenceTier::FULL);
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];
        $engine->completeStep($id, 'itadmin');

        // The licence expires with one step to go.
        $this->tier->set(WorkflowLicenceTier::BASIC);
        $final = $engine->completeStep($id, 'member');

        self::assertSame(WorkflowStatus::COMPLETED, $final['status']);
        self::assertTrue($final['purged'], 'the tier at the moment it ends decides');
        self::assertSame(['instances' => 0, 'steps' => 0, 'participants' => 0, 'events' => 0], $this->rowCounts());
    }

    public function testAWorkflowDeletedBeforeALicenceArrivesIsNeverRestored(): void {
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];
        $engine->completeStep($id, 'itadmin');
        $engine->completeStep($id, 'member');
        self::assertSame(['instances' => 0, 'steps' => 0, 'participants' => 0, 'events' => 0], $this->rowCounts());

        $this->tier->set(WorkflowLicenceTier::FULL);

        self::assertSame(['instances' => 0, 'steps' => 0, 'participants' => 0, 'events' => 0], $this->rowCounts());
        self::assertSame([], $engine->listForParticipant('member'));
        self::assertSame([], $engine->listForParticipant('member', WorkflowStatus::COMPLETED));
        $this->expectException(NotFoundException::class);
        $engine->get($id, 'member');
    }

    public function testAWorkflowStartedUnlicensedAndFinishedLicensedIsKept(): void {
        // The mirror of the rule above: the tier at the *end* decides, so a
        // licence that arrives before the last step saves the workflow.
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];
        $engine->completeStep($id, 'itadmin');

        $this->tier->set(WorkflowLicenceTier::FULL);
        $final = $engine->completeStep($id, 'member');

        self::assertFalse($final['purged']);
        self::assertSame(WorkflowStatus::COMPLETED, $engine->get($id, 'member')['status']);
    }
    /**
     * A ServiceTeamService that says "no service team, nobody eligible" -
     * the answer on every instance that has none, which is every case in
     * this file.
     */
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

}
