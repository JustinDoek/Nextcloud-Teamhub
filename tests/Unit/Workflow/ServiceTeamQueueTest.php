<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Db\ServiceTeam;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\LicenseGateException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Exception\WorkflowTransitionException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\ServiceTeam\ServiceDeskStatisticsService;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\Workflow\WorkflowEngine;
use OCA\TeamHub\Service\Workflow\WorkflowEventService;
use OCA\TeamHub\Service\Workflow\WorkflowNotificationService;
use OCA\TeamHub\Service\Workflow\WorkflowShareService;
use OCA\TeamHub\Db\WorkflowAttachment;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCA\TeamHub\Workflow\WorkflowEventType;
use OCA\TeamHub\Workflow\WorkflowStepDefinition;
use OCA\TeamHub\Workflow\WorkflowStepStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Service Teams: the queue (WorkflowHub phase 5, v4.10.20).
 *
 * The three verbs that make a queue a queue - claim, assign, release -
 * plus the two separations the feature exists to keep: **a claimed request
 * is one agent's**, and **an internal note is the desk's**.
 *
 * The desk under test is `sdesk`, with owner `svcowner` and agents `agent1`
 * and `agent2`. `requester` asks from team `t1`; `outsider` is on no desk
 * and in no team.
 */
class ServiceTeamQueueTest extends TestCase {

    private const TEAM  = 't1';
    private const DESK  = 'sdesk';

    private InMemoryWorkflowInstanceMapper $instances;
    private InMemoryWorkflowStepMapper $steps;
    private InMemoryWorkflowParticipantMapper $participants;
    private InMemoryWorkflowEventMapper $events;
    private FakeActorResolver $resolver;
    private FakeLicenceTier $tier;
    private WorkflowDefinitionRegistry $registry;
    private ServiceTeamService $serviceTeams;
    private ArchiveHarness $archive;
    private int $now = 2_000_000;
    /** @var array<int, array{0: string, 1: array<int, mixed>}> */
    public array $notified = [];
    /** v4.10.27 — what the engine wrote to the audit log: [teamId, eventType, actor, meta]. */
    public array $audited = [];

    protected function setUp(): void {
        $this->instances    = new InMemoryWorkflowInstanceMapper();
        $this->steps        = new InMemoryWorkflowStepMapper();
        $this->participants = new InMemoryWorkflowParticipantMapper($this->instances);
        $this->events       = new InMemoryWorkflowEventMapper();

        $this->resolver = new FakeActorResolver();
        $this->resolver->levels[self::TEAM] = ['requester' => 1, 'svcowner' => 1, 'agent1' => 1, 'agent2' => 1];
        $this->resolver->serviceAgents[self::DESK] = ['svcowner', 'agent1', 'agent2'];

        $this->tier    = new FakeLicenceTier('full');
        $this->archive = new ArchiveHarness();

        // The shape of ServiceRequestDefinition: submit (auto), handle (the
        // desk), confirm (the requester).
        $definition = new FixtureDefinition('svc', 1, [
            new WorkflowStepDefinition('submit', 'Request submitted', WorkflowActor::initiator(), true),
            new WorkflowStepDefinition('handle', 'Service team handles it', WorkflowActor::serviceAgentPlaceholder()),
            new WorkflowStepDefinition('confirm', 'Requester confirms', WorkflowActor::initiator()),
        ], null, WorkflowDefinitionRegistry::CONCURRENCY_UNBOUNDED);
        $definition->resolvedActor = WorkflowActor::serviceAgent(self::DESK);
        $this->registry = new WorkflowDefinitionRegistry();
        $this->registry->register($definition);

        $this->serviceTeams = $this->serviceTeamsFor(self::DESK, 'svcowner', ['svcowner', 'agent1', 'agent2']);
    }

    // ── A service a service team built (v4.10.31) ───────────────────────

    /**
     * The shape `TeamServiceDefinition` produces: submit, a desk step, a
     * planned requester action, a second desk step, the confirmation.
     */
    private function registerBuiltService(): void {
        $definition = new ClosableFixtureDefinition('built', 1, [
            new WorkflowStepDefinition('submit', 'Request submitted', WorkflowActor::initiator(), true),
            new WorkflowStepDefinition('step_1', 'Assess the request', WorkflowActor::serviceAgentPlaceholder(), false, 'Functional admin'),
            new WorkflowStepDefinition('step_2', 'Sign the agreement', WorkflowActor::initiator()),
            new WorkflowStepDefinition('step_3', 'Install the app', WorkflowActor::serviceAgentPlaceholder(), false, 'Technical admin'),
            new WorkflowStepDefinition('confirm', 'Requester confirms', WorkflowActor::initiator()),
        ], null, WorkflowDefinitionRegistry::CONCURRENCY_UNBOUNDED);
        $definition->resolvedActor = WorkflowActor::serviceAgent(self::DESK);
        $this->registry->register($definition);
    }

    private function stepView(array $view, string $key): array {
        foreach ($view['steps'] as $step) {
            if ($step['key'] === $key) {
                return $step;
            }
        }
        $this->fail('no step ' . $key);
    }

    public function testABuiltServiceCarriesTheRoleEachDeskStepNeeds(): void {
        $this->registerBuiltService();
        $engine = $this->engine();
        $view   = $engine->create('built', self::TEAM, 'requester', ['reason' => 'x']);

        $this->assertSame('Functional admin', $this->stepView($view, 'step_1')['roleLabel']);
        $this->assertSame('', $this->stepView($view, 'step_2')['roleLabel'], 'a requester action needs no role');
        $this->assertSame('Technical admin', $this->stepView($view, 'step_3')['roleLabel']);
        // A label, not a permission: every agent may claim it.
        $queue = $engine->listQueue(self::DESK, 'agent2');
        $this->assertCount(1, $queue['unclaimed']);
        $this->assertTrue($queue['unclaimed'][0]['internal']['claimable']);
    }

    /**
     * v4.10.36 — links on steps (`docs/service-builder.md` § 6.1): a
     * requester step's are for everybody on the request, a desk step's are
     * the team's own material and reach its agents only. Copied at start, so
     * a later version of the service changes no running request.
     */
    public function testATeamStepsLinksAreTheTeamsAndARequesterStepsAreEverybodys(): void {
        $definition = new ClosableFixtureDefinition('linked', 1, [
            new WorkflowStepDefinition('submit', 'Request submitted', WorkflowActor::initiator(), true),
            new WorkflowStepDefinition('step_1', 'Assess', WorkflowActor::serviceAgentPlaceholder(), false, '',
                [['label' => 'Checklist', 'url' => 'https://wiki.example.org/c', 'kind' => 'link']]),
            new WorkflowStepDefinition('step_2', 'Fill in the intake', WorkflowActor::initiator(), false, '',
                [['label' => 'Intake form', 'url' => 'https://forms.example.org/i', 'kind' => 'form']]),
            new WorkflowStepDefinition('step_3', 'Install', WorkflowActor::serviceAgentPlaceholder()),
            new WorkflowStepDefinition('confirm', 'Requester confirms', WorkflowActor::initiator()),
        ], null, WorkflowDefinitionRegistry::CONCURRENCY_UNBOUNDED);
        $definition->resolvedActor = WorkflowActor::serviceAgent(self::DESK);
        $this->registry->register($definition);
        $engine = $this->engine();
        $id     = $engine->create('linked', self::TEAM, 'requester', ['reason' => 'x'])['id'];

        $agent = $engine->get($id, 'agent1');
        $this->assertSame('https://wiki.example.org/c', $this->stepView($agent, 'step_1')['links'][0]['url']);
        $this->assertSame('form', $this->stepView($agent, 'step_2')['links'][0]['kind']);
        $this->assertSame([], $this->stepView($agent, 'step_3')['links']);

        $requester = $engine->get($id, 'requester');
        $this->assertSame([], $this->stepView($requester, 'step_1')['links'], 'the team\'s own material is not the requester\'s');
        $this->assertSame('Intake form', $this->stepView($requester, 'step_2')['links'][0]['label']);
        $this->assertSame([], $this->stepView($requester, 'submit')['links']);
    }

    // ── The paperclip (v4.10.38, `docs/service-builder.md` § 7) ─────────

    /** @var array<int, array<string, mixed>> what the fake share service was asked to share */
    private array $shared = [];
    /** @var array<int, WorkflowAttachment[]> what it was asked to remove */
    private array $removed = [];

    /** A share service that resolves every id to a file and records what it is asked. */
    private function fakeShares(array $live = []): WorkflowShareService {
        $shares = $this->createMock(WorkflowShareService::class);
        $shares->method('resolve')->willReturnCallback(
            static fn (string $uid, mixed $ids): array => array_map(static fn ($id): array => ['fileId' => (int)$id, 'name' => 'file' . $id . '.pdf'], (array)$ids),
        );
        $shares->method('share')->willReturnCallback(function ($instance, $uid, $files, $audience, $recipient, $visibility, $stepKey, $settings): void {
            $this->shared[] = compact('uid', 'files', 'audience', 'recipient', 'visibility', 'stepKey', 'settings');
        });
        $shares->method('liveShares')->willReturn($live);
        $shares->method('removeShares')->willReturnCallback(function (array $rows): array {
            $this->removed[] = $rows;
            return [];
        });
        return $shares;
    }

    private function lastEventPayload(int $id, string $type): array {
        $payload = null;
        foreach ((new WorkflowEventService($this->events, $this->timeFactory()))->listForInstance($id) as $event) {
            if ($event['type'] === $type) {
                $payload = $event['payload'];
            }
        }
        $this->assertNotNull($payload, 'no ' . $type . ' event');
        return $payload;
    }

    public function testTheRequesterSharesWithTheTeamAndTheTeamWithTheRequester(): void {
        $engine = $this->engine($this->fakeShares());
        $id = $engine->create('svc', self::TEAM, 'requester', ['reason' => 'x', 'fileIds' => [11]])['id'];
        $this->assertSame([['fileId' => 11, 'name' => 'file11.pdf']], $this->lastEventPayload($id, 'created')['files']);
        $this->assertSame(['team', self::DESK, 'requester'], [$this->shared[0]['audience'], $this->shared[0]['recipient'], $this->shared[0]['visibility']]);
        $this->assertSame(['allowed' => true, 'edit' => false, 'days' => 14], $this->shared[0]['settings'], 'a built-in service shares with the defaults');

        $engine->claimStep($id, 'agent1');
        $engine->requestInformation($id, 'agent1', 'Which laptop?', null, [12]);
        $this->assertSame(['requester', 'requester', 'agent1'], [$this->shared[1]['audience'], $this->shared[1]['recipient'], $this->shared[1]['uid']]);
        $this->assertSame('file12.pdf', $this->lastEventPayload($id, 'information_requested')['files'][0]['name']);

        $engine->addInternalNote($id, 'agent1', 'See the quote', null, [13]);
        $this->assertSame(['team', self::DESK, 'internal'], [$this->shared[2]['audience'], $this->shared[2]['recipient'], $this->shared[2]['visibility']],
            'an internal note\'s file stays the team\'s');
    }

    public function testFilesOnTheWriteThatEndsTheRequestAreNotSharedAndEveryShareGoes(): void {
        $live = [new WorkflowAttachment()];
        $engine = $this->engine($this->fakeShares($live));
        $id = $engine->create('svc', self::TEAM, 'requester', ['reason' => 'x'])['id'];
        $engine->claimStep($id, 'agent1');
        $engine->completeStep($id, 'agent1');
        $this->shared = [];

        $engine->completeStep($id, 'requester', 'Thanks', null, [21]);
        $this->assertSame('file21.pdf', $this->lastEventPayload($id, 'step_completed')['files'][0]['name'], 'the event still names it');
        $this->assertSame([], $this->shared, 'nobody is handed a share the ending takes back');
        $this->assertSame([$live], $this->removed, 'the request\'s shares are removed when it ends');
    }

    public function testAServiceThatTakesNoFilesRefusesThemBeforeAnythingIsWritten(): void {
        $this->registerTaskedService();
        $engine = $this->engine($this->fakeShares());
        $id = $engine->create('tasked', self::TEAM, 'requester', ['reason' => 'x'])['id'];
        $instance = $this->instances->findById($id);
        $instance->setData(['reason' => 'x', 'fileSharing' => ['allowed' => false]]);
        $this->instances->update($instance);

        $engine->claimStep($id, 'agent1', 'step_1_1');
        try {
            $engine->completeStep($id, 'agent1', null, 'step_1_1', [5]);
            $this->fail('files on a service that takes none');
        } catch (ValidationException $e) {
        }
        $this->assertSame(WorkflowStepStatus::IN_PROGRESS, $this->stepStatus($id, 'step_1_1'), 'nothing moved');
    }

    public function testWithoutTheShareServiceFilesAreRefusedAndNothingElseChanges(): void {
        $engine = $this->engine();
        $this->expectException(ValidationException::class);
        $engine->create('svc', self::TEAM, 'requester', ['reason' => 'x', 'fileIds' => [1]]);
    }

    /**
     * v4.10.39 — a service that starts with the requester (a form to fill in
     * first): the new request is the requester's to act on at once, its link
     * reaches them, and the team sees it under *With requester* until the
     * requester has done it.
     */
    public function testAServiceMayStartWithTheRequestersForm(): void {
        $definition = new ClosableFixtureDefinition('form_first', 1, [
            new WorkflowStepDefinition('submit', 'Request submitted', WorkflowActor::initiator(), true),
            new WorkflowStepDefinition('step_1', 'Fill in the intake form', WorkflowActor::initiator(), false, '',
                [['label' => 'Intake form', 'url' => 'https://forms.example.org/i', 'kind' => 'form']]),
            new WorkflowStepDefinition('step_2', 'Assess', WorkflowActor::serviceAgentPlaceholder()),
            new WorkflowStepDefinition('confirm', 'Requester confirms', WorkflowActor::initiator()),
        ], null, WorkflowDefinitionRegistry::CONCURRENCY_UNBOUNDED);
        $definition->resolvedActor = WorkflowActor::serviceAgent(self::DESK);
        $this->registry->register($definition);
        $engine = $this->engine();

        $view = $engine->create('form_first', self::TEAM, 'requester', ['reason' => 'x']);
        $this->assertSame('step_1', $view['viewer']['stepKey']);
        $this->assertTrue($this->stepView($view, 'step_1')['canAct'], 'the requester can do it straight away');
        $this->assertSame('Intake form', $this->stepView($view, 'step_1')['links'][0]['label']);

        $queue = $engine->listQueue(self::DESK, 'agent1');
        $this->assertSame([], $queue['unclaimed']);
        $this->assertSame([$view['id']], array_column($queue['withOthers'], 'id'), 'the team sees it arrive');

        $engine->completeStep($view['id'], 'requester', null, 'step_1');
        $this->assertCount(1, $engine->listQueue(self::DESK, 'agent1')['unclaimed'], 'done: the team has it');
    }

    /** v4.10.39 — asked from no team: recorded against the service team, and the view says so. */
    public function testAPersonalRequestSaysSo(): void {
        $engine = $this->engine();
        $this->assertFalse($this->open($engine)['personal'], 'asked from team t1');
        $personal = $engine->create('svc', self::DESK, 'requester', ['reason' => 'x']);
        $this->assertTrue($personal['personal']);
    }

    // ── Tasks in a step (v4.10.37, `docs/service-builder.md` § 4) ───────

    /**
     * The shape `TeamServiceDefinition` makes of a step with tasks: *Assess*
     * holds a privacy check and a security check (non-blocking); *Provide*
     * holds two requester tasks; then *Install*, then the confirmation.
     */
    private function registerTaskedService(): void {
        $desk = WorkflowActor::serviceAgentPlaceholder();
        $req  = WorkflowActor::initiator();
        $definition = new ClosableFixtureDefinition('tasked', 1, [
            new WorkflowStepDefinition('submit', 'Request submitted', $req, true),
            new WorkflowStepDefinition('step_1_1', 'Privacy check', $desk, false, 'Privacy officer', [], 'step_1', 'Assess'),
            new WorkflowStepDefinition('step_1_2', 'Security check', $desk, false, 'CISO', [], 'step_1', 'Assess', true),
            new WorkflowStepDefinition('step_2_1', 'Fill in the form', $req, false, '',
                [['label' => 'Intake form', 'url' => 'https://forms.example.org/i', 'kind' => 'form']], 'step_2', 'Provide'),
            new WorkflowStepDefinition('step_2_2', 'Sign the agreement', $req, false, '', [], 'step_2', 'Provide'),
            new WorkflowStepDefinition('step_3', 'Install', $desk, false, '', [], 'step_3'),
            new WorkflowStepDefinition('confirm', 'Requester confirms', $req),
        ], null, WorkflowDefinitionRegistry::CONCURRENCY_UNBOUNDED);
        $definition->resolvedActor = WorkflowActor::serviceAgent(self::DESK);
        $this->registry->register($definition);
    }

    public function testEveryTaskOfAStepArrivesInTheQueueOnItsOwn(): void {
        $this->registerTaskedService();
        $engine = $this->engine();
        $view   = $engine->create('tasked', self::TEAM, 'requester', ['reason' => 'x']);
        $id     = $view['id'];

        $this->assertSame(WorkflowStepStatus::AVAILABLE, $this->stepStatus($id, 'step_1_1'));
        $this->assertSame(WorkflowStepStatus::AVAILABLE, $this->stepStatus($id, 'step_1_2'), 'the tasks of a step start together');
        $this->assertSame(WorkflowStepStatus::PENDING, $this->stepStatus($id, 'step_2_1'));
        $this->assertSame('Assess', $this->stepView($view, 'step_1_1')['stageLabel']);
        $this->assertTrue($this->stepView($view, 'step_1_2')['nonBlocking']);
        $this->assertSame($this->stepView($view, 'step_1_1')['order'], $this->stepView($view, 'step_1_2')['order']);

        $queue = $engine->listQueue(self::DESK, 'agent1');
        $this->assertSame(['step_1_1', 'step_1_2'], array_map(static fn (array $v): string => $v['viewer']['stepKey'], $queue['unclaimed']),
            'one row per task, each answering for its own');
        $this->assertSame(2, $engine->unclaimedCount(self::DESK), 'the badge counts tasks');
    }

    public function testTwoAgentsTakeATaskEachAndTheStepWaitsForTheRequiredOne(): void {
        $this->registerTaskedService();
        $engine = $this->engine();
        $id     = $engine->create('tasked', self::TEAM, 'requester', ['reason' => 'x'])['id'];

        $mine = $engine->claimStep($id, 'agent1', 'step_1_1');
        $this->assertSame('step_1_1', $mine['viewer']['stepKey']);
        $engine->claimStep($id, 'agent2', 'step_1_2');
        $this->assertSame('agent2', $engine->get($id, 'agent2', 'step_1_2')['internal']['assignee']);
        // agent2 may not complete agent1's task.
        try {
            $engine->completeStep($id, 'agent2', null, 'step_1_1');
            $this->fail('another agent\'s task');
        } catch (AccessDeniedException $e) {
        }

        // The non-blocking task first: the step does not move.
        $engine->completeStep($id, 'agent2', null, 'step_1_2');
        $this->assertSame(WorkflowStepStatus::PENDING, $this->stepStatus($id, 'step_2_1'));
        // The required one: it moves, and both requester tasks open.
        $engine->completeStep($id, 'agent1', null, 'step_1_1');
        $this->assertSame(WorkflowStepStatus::AVAILABLE, $this->stepStatus($id, 'step_2_1'));
        $this->assertSame(WorkflowStepStatus::AVAILABLE, $this->stepStatus($id, 'step_2_2'));
    }

    public function testTheRequesterHasARowPerTaskAndEachLinkIsTheirs(): void {
        $this->registerTaskedService();
        $engine = $this->engine();
        $id     = $engine->create('tasked', self::TEAM, 'requester', ['reason' => 'x'])['id'];
        $engine->claimStep($id, 'agent1', 'step_1_1');
        $engine->completeStep($id, 'agent1', null, 'step_1_1');

        $rows = array_values(array_filter($engine->listForParticipant('requester'), static fn (array $v): bool => $v['id'] === $id));
        $this->assertSame(['step_2_1', 'step_2_2'], array_map(static fn (array $v): string => $v['viewer']['stepKey'], $rows),
            'two tasks of the requester, two rows in My Work');
        $this->assertTrue($rows[0]['viewer']['actionRequired']);
        $this->assertTrue($this->stepView($rows[0], 'step_2_1')['canAct'], 'the form link is the requester\'s to press');
        $this->assertSame('Intake form', $this->stepView($rows[0], 'step_2_1')['links'][0]['label']);
        $this->assertFalse($this->stepView($engine->get($id, 'agent1'), 'step_2_1')['canAct'], 'not the team\'s');

        // Pressing the link completes that task only.
        $engine->completeStep($id, 'requester', null, 'step_2_1');
        $this->assertSame(WorkflowStepStatus::COMPLETED, $this->stepStatus($id, 'step_2_1'));
        $this->assertSame(WorkflowStepStatus::PENDING, $this->stepStatus($id, 'step_3'));
        $engine->completeStep($id, 'requester', null, 'step_2_2');
        $this->assertSame(WorkflowStepStatus::AVAILABLE, $this->stepStatus($id, 'step_3'), 'back to the team');
    }

    public function testANonBlockingTaskMustBeDoneBeforeTheRequestEnds(): void {
        $this->registerTaskedService();
        $engine = $this->engine();
        $id     = $engine->create('tasked', self::TEAM, 'requester', ['reason' => 'x'])['id'];
        $engine->claimStep($id, 'agent1', 'step_1_1');
        $engine->completeStep($id, 'agent1', null, 'step_1_1');
        $engine->completeStep($id, 'requester', null, 'step_2_1');
        $engine->completeStep($id, 'requester', null, 'step_2_2');
        $engine->claimStep($id, 'agent1', 'step_3');
        $engine->completeStep($id, 'agent1', null, 'step_3');
        $this->assertSame(WorkflowStepStatus::AVAILABLE, $this->stepStatus($id, 'confirm'));
        $this->assertSame(WorkflowStepStatus::AVAILABLE, $this->stepStatus($id, 'step_1_2'), 'still open from the first step');

        try {
            $engine->completeStep($id, 'requester', null, 'confirm');
            $this->fail('the security check is still open');
        } catch (WorkflowTransitionException $e) {
            $this->assertStringContainsString('Security check', $e->getMessage());
        }
        $engine->claimStep($id, 'agent2', 'step_1_2');
        $engine->completeStep($id, 'agent2', null, 'step_1_2');
        $this->assertSame(WorkflowStepStatus::AVAILABLE, $this->stepStatus($id, 'confirm'), 'finishing it late moves nothing');
        $this->assertSame('completed', $engine->completeStep($id, 'requester', null, 'confirm')['status']);
    }

    public function testRejectingOneTaskRejectsTheRequestAndSkipsTheOthers(): void {
        $this->registerTaskedService();
        $engine = $this->engine();
        $id     = $engine->create('tasked', self::TEAM, 'requester', ['reason' => 'x'])['id'];
        $engine->claimStep($id, 'agent1', 'step_1_1');

        $view = $engine->rejectStep($id, 'agent1', 'Not allowed', 'step_1_1');
        $this->assertSame('rejected', $view['status']);
        $this->assertSame(WorkflowStepStatus::SKIPPED, $this->stepStatus($id, 'step_1_2'));
        $this->assertSame(WorkflowStepStatus::SKIPPED, $this->stepStatus($id, 'step_2_1'));
    }

    public function testWithdrawingCancelsEveryOpenTask(): void {
        $this->registerTaskedService();
        $engine = $this->engine();
        $id     = $engine->create('tasked', self::TEAM, 'requester', ['reason' => 'x'])['id'];

        $engine->cancel($id, 'requester', 'No longer needed');
        $this->assertSame(WorkflowStepStatus::CANCELLED, $this->stepStatus($id, 'step_1_1'));
        $this->assertSame(WorkflowStepStatus::CANCELLED, $this->stepStatus($id, 'step_1_2'));
        $this->assertSame(WorkflowStepStatus::CANCELLED, $this->stepStatus($id, 'step_3'));
    }

    public function testATaskThatIsNoLongerOpenIsRefusedByName(): void {
        $this->registerTaskedService();
        $engine = $this->engine();
        $id     = $engine->create('tasked', self::TEAM, 'requester', ['reason' => 'x'])['id'];
        $engine->claimStep($id, 'agent1', 'step_1_1');
        $engine->completeStep($id, 'agent1', null, 'step_1_1');

        $this->expectException(WorkflowTransitionException::class);
        $engine->completeStep($id, 'agent1', null, 'step_1_1');
    }

    public function testARequestComesBackToTheDeskAfterThePlannedRequesterAction(): void {
        $this->registerBuiltService();
        $engine = $this->engine();
        $view   = $engine->create('built', self::TEAM, 'requester', ['reason' => 'x']);
        $id     = $view['id'];

        $engine->claimStep($id, 'agent1');
        $engine->completeStep($id, 'agent1');
        $this->assertSame('step_2', $this->instances->findById($id)->getCurrentStep());

        // With the requester, and coming back: neither in the queue nor closed.
        $queue = $engine->listQueue(self::DESK, 'agent1');
        $this->assertSame([], $queue['unclaimed']);
        $this->assertSame([], $queue['mine']);
        $this->assertSame([], $queue['closed'], 'a request that returns to the desk is not closed for it');

        $engine->completeStep($id, 'requester');
        $queue = $engine->listQueue(self::DESK, 'agent2');
        $this->assertCount(1, $queue['unclaimed'], 'the second desk step waits for a claim of its own');
        $this->assertSame([], $queue['closed'], 'still open for the desk');

        $engine->claimStep($id, 'agent2');
        $engine->completeStep($id, 'agent2');
        $queue = $engine->listQueue(self::DESK, 'agent2');
        $this->assertSame([], $queue['unclaimed']);
        $this->assertCount(1, $queue['closed'], 'closed once, when the last desk step is done');
        $this->assertSame('agent2', $queue['closed'][0]['desk']['closedBy']);
    }

    public function testAnAdminClosingAtTheConfirmationCompletesIt(): void {
        $this->registerBuiltService();
        $engine = $this->engine();
        $id     = $engine->create('built', self::TEAM, 'requester', ['reason' => 'x'])['id'];
        foreach ([['agent1', true], ['requester', false], ['agent1', true]] as [$uid, $claim]) {
            if ($claim) {
                $engine->claimStep($id, $uid);
            }
            $engine->completeStep($id, $uid);
        }
        $this->assertSame('confirm', $this->instances->findById($id)->getCurrentStep());

        $owner = $engine->get($id, 'svcowner');
        $this->assertTrue($owner['viewer']['canClose']);
        $this->assertFalse($owner['viewer']['canAct'], 'the confirmation is not the admin\'s own step');
        $this->assertFalse($engine->get($id, 'agent1')['viewer']['canClose'], 'only an admin of the desk');
        $this->assertFalse($engine->get($id, 'requester')['viewer']['canClose']);

        try {
            $engine->closeRequest($id, 'agent1');
            $this->fail('an agent who is not an admin cannot close it');
        } catch (AccessDeniedException) {
            // expected
        }
        try {
            $engine->completeStep($id, 'svcowner');
            $this->fail('closing is its own verb; completing the requester\'s step stays theirs');
        } catch (AccessDeniedException) {
            // expected
        }

        $done = $engine->closeRequest($id, 'svcowner', 'Requester did not respond');
        $this->assertSame('completed', $done['status']);
        $this->assertSame('completed', $done['outcome'], 'at the confirmation, closing completes it');
        $this->assertSame('svcowner', $this->stepView($done, 'confirm')['completedBy']);
    }

    public function testAnAdminMayCloseEarlyAndTheStepsNotDoneShowAsSkipped(): void {
        $this->registerBuiltService();
        $engine = $this->engine();
        $id     = $engine->create('built', self::TEAM, 'requester', ['reason' => 'x'])['id'];
        $engine->claimStep($id, 'agent1');
        $engine->completeStep($id, 'agent1');        // step_1 done; step_2 is the requester's

        $this->assertTrue($engine->get($id, 'svcowner')['viewer']['canClose'], 'at any step');
        $done = $engine->closeRequest($id, 'svcowner', 'Handled by phone');

        $this->assertSame('completed', $done['status']);
        $this->assertSame('closed', $done['outcome'], 'neither a success nor a rejection');
        $this->assertSame(WorkflowStepStatus::COMPLETED, $this->stepStatus($id, 'step_1'), 'what was done stays done');
        $this->assertSame(WorkflowStepStatus::SKIPPED, $this->stepStatus($id, 'step_2'));
        $this->assertSame(WorkflowStepStatus::SKIPPED, $this->stepStatus($id, 'step_3'));
        $this->assertSame(WorkflowStepStatus::SKIPPED, $this->stepStatus($id, 'confirm'));
        $this->assertContains(WorkflowEventType::CLOSED, $this->eventTypes($id));
        $this->assertContains('service.request_closed', array_column($this->audited, 1), 'the desk\'s activity stream says so');
    }

    public function testClosingWhileTheDeskIsWorkingSkipsTheClaimedStep(): void {
        $this->registerBuiltService();
        $engine = $this->engine();
        $id     = $engine->create('built', self::TEAM, 'requester', ['reason' => 'x'])['id'];
        $engine->claimStep($id, 'agent1');

        $engine->closeRequest($id, 'svcowner');
        $this->assertSame(WorkflowStepStatus::SKIPPED, $this->stepStatus($id, 'step_1'));
        $closed = $engine->listQueue(self::DESK, 'agent1')['closed'];
        $this->assertCount(1, $closed, 'closed for the desk');
        $this->assertSame(WorkflowStepStatus::SKIPPED, $closed[0]['desk']['status']);
    }

    public function testTheDeskMayCloseAGeneralServiceRequestButNotAWorkflowThatDoesSomething(): void {
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];     // 'svc' is a plain fixture: not closable
        $this->assertFalse($engine->get($id, 'svcowner')['viewer']['canClose']);
        $this->expectException(AccessDeniedException::class);
        $engine->closeRequest($id, 'svcowner');
    }

    // ── Whose turn it is (v4.10.31) ────────────────────────────────────

    public function testAnUnclaimedDeskStepIsNobodysTurnUntilSomebodyClaimsIt(): void {
        $this->registerBuiltService();
        $engine = $this->engine();
        $id     = $engine->create('built', self::TEAM, 'requester', ['reason' => 'x'])['id'];

        $view = $engine->get($id, 'agent1');
        $this->assertFalse($view['viewer']['canAct'], 'v4.10.39 — claimed first');
        $this->assertFalse($view['viewer']['actionRequired'], 'it is the desk\'s, not agent1\'s');

        $engine->claimStep($id, 'agent1');
        $this->assertTrue($engine->get($id, 'agent1')['viewer']['actionRequired']);
        $this->assertFalse($engine->get($id, 'agent2')['viewer']['actionRequired']);
        $this->assertFalse($engine->get($id, 'requester')['viewer']['actionRequired']);

        $engine->completeStep($id, 'agent1');       // now the requester's action
        $this->assertTrue($engine->get($id, 'requester')['viewer']['actionRequired']);
        $this->assertFalse($engine->get($id, 'agent1')['viewer']['actionRequired']);
    }

    public function testMyWorkListsADeskRequestOnlyForTheMembersWhoWorkedOnIt(): void {
        $this->registerBuiltService();
        $engine = $this->engine();
        $id     = $engine->create('built', self::TEAM, 'requester', ['reason' => 'x'])['id'];

        $ids = static fn (array $list): array => array_column($list, 'id');
        $this->assertSame([$id], $ids($engine->listForParticipant('requester')), 'the requester, always');
        $this->assertSame([], $ids($engine->listForParticipant('agent1')), 'unclaimed: the desk\'s, in the widget');

        $engine->claimStep($id, 'agent1');
        $engine->completeStep($id, 'agent1');
        $this->assertSame([$id], $ids($engine->listForParticipant('agent1')), 'worked on it: follows it to the end');
        $this->assertSame([], $ids($engine->listForParticipant('agent2')));
        // Not listed is not hidden: any member may still open it.
        $this->assertSame($id, $engine->get($id, 'agent2')['id']);
    }

    public function testARequestBackWithTheRequesterStaysInTheDesksWidget(): void {
        $this->registerBuiltService();
        $engine = $this->engine();
        $id     = $engine->create('built', self::TEAM, 'requester', ['reason' => 'x'])['id'];
        $engine->claimStep($id, 'agent1');
        $engine->completeStep($id, 'agent1');

        $queue = $engine->listQueue(self::DESK, 'agent1');
        $this->assertCount(1, $queue['withOthers']);
        $this->assertSame($id, $queue['withOthers'][0]['id']);
        $this->assertTrue($queue['withOthers'][0]['internal']['involved'], 'agent1 worked on it: My tasks keeps it');
        $this->assertFalse($engine->listQueue(self::DESK, 'agent2')['withOthers'][0]['internal']['involved']);

        $engine->completeStep($id, 'requester');
        $this->assertSame([], $engine->listQueue(self::DESK, 'agent1')['withOthers'], 'back at the desk: in the queue again');
    }

    // ── Claiming ───────────────────────────────────────────────────────

    public function testTheHandlingStepStartsUnclaimedAndInTheQueue(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);

        $queue = $engine->listQueue(self::DESK, 'agent1');
        $this->assertCount(1, $queue['unclaimed'], 'a new request waits for somebody to take it');
        $this->assertSame([], $queue['mine']);
        $this->assertSame([], $queue['others']);
        $this->assertSame('', $queue['unclaimed'][0]['internal']['assignee']);
        $this->assertTrue($queue['unclaimed'][0]['internal']['claimable']);
        $this->assertSame($view['id'], $queue['unclaimed'][0]['id']);
    }

    public function testClaimingMakesItMineAndStartsTheStep(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);

        $after = $engine->claimStep($view['id'], 'agent1');
        $this->assertSame('agent1', $after['internal']['assignee']);
        $this->assertSame(WorkflowStepStatus::IN_PROGRESS, $this->stepStatus($view['id'], 'handle'));
        $this->assertContains(WorkflowEventType::STEP_CLAIMED, $this->eventTypes($view['id']));

        $queue = $engine->listQueue(self::DESK, 'agent1');
        $this->assertSame([], $queue['unclaimed']);
        $this->assertCount(1, $queue['mine']);

        // The same queue reads differently for the colleague who did not claim it.
        $theirs = $engine->listQueue(self::DESK, 'agent2');
        $this->assertSame([], $theirs['mine']);
        $this->assertCount(1, $theirs['others']);
    }

    public function testASecondAgentCannotClaimWhatIsAlreadyTaken(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);
        $engine->claimStep($view['id'], 'agent1');

        $this->expectException(WorkflowTransitionException::class);
        $engine->claimStep($view['id'], 'agent2');
    }

    public function testSomebodyWhoIsNotAnAgentCannotClaimOrEvenSeeTheQueue(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);

        try {
            $engine->claimStep($view['id'], 'requester');
            $this->fail('the requester is not an agent of the desk');
        } catch (AccessDeniedException) {
            // expected
        }

        $this->expectException(AccessDeniedException::class);
        $engine->listQueue(self::DESK, 'outsider');
    }

    // ── Who may act on a claimed step ──────────────────────────────────

    public function testAClaimedRequestIsOnlyTheClaimersToComplete(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);
        $engine->claimStep($view['id'], 'agent1');

        try {
            $engine->completeStep($view['id'], 'agent2', 'done');
            $this->fail('a colleague must take it over first');
        } catch (AccessDeniedException) {
            // expected
        }

        // The service owner can always step in.
        $engine->completeStep($view['id'], 'svcowner', 'done');
        $this->assertSame(WorkflowStepStatus::COMPLETED, $this->stepStatus($view['id'], 'handle'));
    }

    /** v4.10.39 — Justin, 2026-09-25: a task is claimed before anybody acts on it. */
    public function testAnUnclaimedTaskMustBeClaimedBeforeAnybodyActsOnIt(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);
        $this->assertFalse($engine->get($view['id'], 'agent2')['viewer']['canAct'], 'no Complete step before the claim');
        $this->assertTrue($engine->get($view['id'], 'agent2')['internal']['claimable']);

        foreach ([
            fn () => $engine->completeStep($view['id'], 'agent2', 'answered without claiming'),
            fn () => $engine->rejectStep($view['id'], 'agent2', 'no'),
            fn () => $engine->requestInformation($view['id'], 'agent2', 'which one?'),
            fn () => $engine->completeStep($view['id'], 'svcowner'),
        ] as $act) {
            try {
                $act();
                $this->fail('acted on an unclaimed task');
            } catch (AccessDeniedException $e) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(WorkflowStepStatus::AVAILABLE, $this->stepStatus($view['id'], 'handle'));

        $engine->claimStep($view['id'], 'agent2');
        $engine->completeStep($view['id'], 'agent2', 'answered');
        $this->assertSame(WorkflowStepStatus::COMPLETED, $this->stepStatus($view['id'], 'handle'));
    }

    // ── Assigning ──────────────────────────────────────────────────────

    public function testAnAdminReassignsAndTheAgentIsTold(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);
        $engine->claimStep($view['id'], 'agent1');

        // v4.10.27 — reassigning is the team admin's (`/service-teams`).
        $after = $engine->assignStep($view['id'], 'svcowner', 'agent2');
        $this->assertSame('agent2', $after['internal']['assignee']);
        $this->assertContains(WorkflowEventType::STEP_ASSIGNED, $this->eventTypes($view['id']));
        $this->assertContains('stepAssigned', array_column($this->notified, 0));

        // And now it is agent2's to finish, not agent1's.
        $this->expectException(AccessDeniedException::class);
        $engine->completeStep($view['id'], 'agent1', 'done');
    }

    public function testAMemberCannotReassignNotEvenToThemselves(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);
        $engine->claimStep($view['id'], 'agent1');

        // A member who wants a colleague's request asks an admin, or the
        // colleague releases it. The flag the widget reads says the same.
        $this->assertFalse($engine->get($view['id'], 'agent2')['internal']['canAssign']);
        $this->assertTrue($engine->get($view['id'], 'svcowner')['internal']['canAssign']);
        $this->expectException(AccessDeniedException::class);
        $engine->assignStep($view['id'], 'agent2', 'agent2');
    }

    public function testAnAdminTakingOverIsAnAssignmentToThemselves(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);
        $engine->claimStep($view['id'], 'agent1');

        $after = $engine->assignStep($view['id'], 'svcowner', 'svcowner');
        $this->assertSame('svcowner', $after['internal']['assignee']);
        // Nobody is told: the person who did it is the recipient.
        $this->assertNotContains('stepAssigned', array_column($this->notified, 0));
    }

    public function testAssigningToSomebodyWhoIsNotAnAgentIsRefused(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);

        $this->expectException(ValidationException::class);
        $engine->assignStep($view['id'], 'svcowner', 'requester');
    }

    // ── Releasing ──────────────────────────────────────────────────────

    public function testReleasingPutsItBackInTheQueue(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);
        $engine->claimStep($view['id'], 'agent1');

        $after = $engine->releaseStep($view['id'], 'agent1', 'not mine to answer');
        $this->assertSame('', $after['internal']['assignee']);
        $this->assertSame(WorkflowStepStatus::AVAILABLE, $this->stepStatus($view['id'], 'handle'));
        $this->assertContains(WorkflowEventType::STEP_RELEASED, $this->eventTypes($view['id']));
        $this->assertCount(1, $engine->listQueue(self::DESK, 'agent2')['unclaimed']);
    }

    public function testOnlyTheAssigneeOrTheOwnerCanRelease(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);
        $engine->claimStep($view['id'], 'agent1');

        try {
            $engine->releaseStep($view['id'], 'agent2');
            $this->fail('releasing somebody else\'s work is how a request loses its owner');
        } catch (AccessDeniedException) {
            // expected
        }

        $after = $engine->releaseStep($view['id'], 'svcowner');
        $this->assertSame('', $after['internal']['assignee']);
    }

    // ── Internal notes ─────────────────────────────────────────────────

    public function testAnInternalNoteIsInvisibleToTheRequester(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);
        $engine->addInternalNote($view['id'], 'agent1', 'checked with finance first');

        $forAgent = $engine->listEvents($view['id'], 'agent1');
        $this->assertContains(WorkflowEventType::INTERNAL_NOTE, array_column($forAgent, 'type'));

        $forRequester = $engine->listEvents($view['id'], 'requester');
        $this->assertNotContains(WorkflowEventType::INTERNAL_NOTE, array_column($forRequester, 'type'));
        $this->assertNotContains(
            'checked with finance first',
            array_column(array_column($forRequester, 'payload'), 'note'),
        );
    }

    // ── Messages (v4.11.0) ────────────────────────────────────────────

    /** @return array<int, array<int, mixed>> the messagePosted calls' arguments */
    private function messagesNotified(): array {
        return array_values(array_map(
            static fn (array $call): array => $call[1],
            array_filter($this->notified, static fn (array $call): bool => $call[0] === 'messagePosted'),
        ));
    }

    public function testARequestersMessageOnAnUnclaimedRequestNotifiesNobody(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);

        $after = $engine->postMessage($view['id'], 'requester', 'It also happens on the second screen');
        $this->assertSame([], $this->messagesNotified(), 'whoever claims it reads it in the history');
        $this->assertSame(WorkflowStepStatus::AVAILABLE, $this->stepStatus($view['id'], 'handle'), 'a message moves nothing');
        $this->assertTrue($after['viewer']['canMessage']);
        $this->assertSame('requester', $after['viewer']['messageSide']);

        $forAgent = $engine->listEvents($view['id'], 'agent1');
        $this->assertContains('It also happens on the second screen', array_column(array_column($forAgent, 'payload'), 'note'));
    }

    public function testTheDeskClaimsBeforeItWritesToTheRequester(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);

        $this->assertFalse($engine->get($view['id'], 'agent1')['viewer']['canMessage']);
        try {
            $engine->postMessage($view['id'], 'agent1', 'On it');
            $this->fail('an unclaimed request has nobody to answer for the desk');
        } catch (AccessDeniedException) {
            // expected
        }
    }

    public function testTheRequesterAndTheClaimantWriteToEachOther(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);
        $engine->claimStep($view['id'], 'agent1');

        $engine->postMessage($view['id'], 'agent1', 'Does it happen after a restart?');
        $engine->postMessage($view['id'], 'requester', 'Yes, every time');
        $told = $this->messagesNotified();
        $this->assertSame(['requester'], $told[0][2]);
        $this->assertSame(['agent1'], $told[1][2]);

        $forRequester = $engine->listEvents($view['id'], 'requester');
        $this->assertSame(
            ['Does it happen after a restart?', 'Yes, every time'],
            array_column(array_column(array_values(array_filter(
                $forRequester,
                static fn (array $e): bool => $e['type'] === WorkflowEventType::MESSAGE,
            )), 'payload'), 'note'),
        );
    }

    public function testAColleagueWhoDidNotClaimItCannotWriteButTheTeamAdminCan(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);
        $engine->claimStep($view['id'], 'agent1');

        $this->assertFalse($engine->get($view['id'], 'agent2')['viewer']['canMessage']);
        try {
            $engine->postMessage($view['id'], 'agent2', 'Me too');
            $this->fail('the requester talks to one person on the desk');
        } catch (AccessDeniedException) {
            // expected
        }

        $engine->postMessage($view['id'], 'svcowner', 'I have asked finance');
        $recipients = $this->messagesNotified()[0][2];
        sort($recipients);
        $this->assertSame(['agent1', 'requester'], $recipients, 'the claimant hears of it too');
    }

    public function testNobodyOutsideTheRequestWrites(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);
        $engine->claimStep($view['id'], 'agent1');

        $this->expectException(AccessDeniedException::class);
        $engine->postMessage($view['id'], 'outsider', 'hello');
    }

    public function testTheConversationGoesOnUntilTheRequestIsClosed(): void {
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];
        $engine->claimStep($id, 'agent1');
        $engine->completeStep($id, 'agent1');      // marked done; the requester confirms next

        $engine->postMessage($id, 'requester', 'Still broken on the laptop');
        $this->assertSame(['agent1'], $this->messagesNotified()[0][2], 'the one who had it still hears');
        $engine->postMessage($id, 'agent1', 'Try it once more now');
        $this->assertSame(['requester'], $this->messagesNotified()[1][2]);

        $engine->completeStep($id, 'requester');
        $this->assertFalse($engine->get($id, 'requester')['viewer']['canMessage']);
        $this->expectException(WorkflowTransitionException::class);
        $engine->postMessage($id, 'requester', 'one more thing');
    }

    public function testAnEmptyMessageIsRefused(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);

        $this->expectException(ValidationException::class);
        $engine->postMessage($view['id'], 'requester', '   ');
    }

    public function testTheComposerKnowsWhenAQuestionIsWaiting(): void {
        $engine = $this->engine();
        $id     = $this->open($engine)['id'];
        $engine->claimStep($id, 'agent1');

        $this->assertTrue($engine->get($id, 'agent1')['viewer']['canAskRequester']);
        $this->assertFalse($engine->get($id, 'requester')['viewer']['answersQuestion']);

        $engine->requestInformation($id, 'agent1', 'Which laptop?');
        $this->assertFalse($engine->get($id, 'agent1')['viewer']['canAskRequester'], 'one question at a time');
        $this->assertTrue($engine->get($id, 'requester')['viewer']['answersQuestion']);

        // Blocked: an answer is refused, so the box does not offer one; a
        // plain message still goes.
        $engine->block($id, 'agent1', 'waiting on the supplier');
        $requester = $engine->get($id, 'requester')['viewer'];
        $this->assertFalse($requester['answersQuestion']);
        $this->assertTrue($requester['canMessage']);
        $engine->postMessage($id, 'requester', 'Any news from the supplier?');
    }

    public function testEvenANextcloudAdministratorDoesNotReadTheDesksNotes(): void {
        $this->resolver->admins[] = 'ncadmin';
        $engine = $this->engine();
        $view   = $this->open($engine);
        $engine->addInternalNote($view['id'], 'agent1', 'internal');

        $forAdmin = $engine->listEvents($view['id'], 'ncadmin');
        $this->assertNotContains(
            WorkflowEventType::INTERNAL_NOTE,
            array_column($forAdmin, 'type'),
            'administering a server is not the same as being on the desk',
        );
    }

    public function testTheRequesterSeesNoInternalBlockAtAll(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);
        $engine->claimStep($view['id'], 'agent1');

        $theirs = $engine->get($view['id'], 'requester');
        $this->assertNull($theirs['internal'], 'who is on the desk is the desk\'s business');
        $this->assertFalse($theirs['viewer']['isServiceAgent']);
        // But they are still told who is responsible, as any participant is.
        $this->assertSame(
            ['type' => WorkflowActor::TYPE_SERVICE_AGENT, 'id' => self::DESK],
            $theirs['responsible'],
        );
    }

    public function testAnInternalNoteNeedsTextAndAnAgent(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);

        try {
            $engine->addInternalNote($view['id'], 'agent1', '   ');
            $this->fail('an empty note is not a note');
        } catch (ValidationException) {
            // expected
        }

        $this->expectException(AccessDeniedException::class);
        $engine->addInternalNote($view['id'], 'requester', 'let me in');
    }

    // ── The licence ────────────────────────────────────────────────────

    public function testEveryQueueVerbIsLicensed(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);

        $this->tier->set('basic');
        foreach ([
            fn (): array => $engine->claimStep($view['id'], 'agent1'),
            fn (): array => $engine->assignStep($view['id'], 'agent1', 'agent2'),
            fn (): array => $engine->releaseStep($view['id'], 'agent1'),
            fn (): array => $engine->addInternalNote($view['id'], 'agent1', 'note'),
            fn (): array => $engine->listQueue(self::DESK, 'agent1'),
        ] as $index => $call) {
            try {
                $call();
                $this->fail('a queue verb must not work without a licence');
            } catch (LicenseGateException $e) {
                $this->assertSame('unlicensed', $e->getEnforcementLevel(), 'verb ' . $index);
            }
        }
    }

    public function testAnExpiredLicenceStillLetsTheDeskFinishWhatItStarted(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);
        $engine->claimStep($view['id'], 'agent1');

        // The licence lapses mid-flight. Completing is never gated - the
        // rule from phase 4, and Service Teams do not change it.
        $this->tier->set('basic');
        $engine->completeStep($view['id'], 'agent1', 'done anyway');
        $this->assertSame(WorkflowStepStatus::COMPLETED, $this->stepStatus($view['id'], 'handle'));
    }

    // ── Default assignment ─────────────────────────────────────────────

    /**
     * v4.10.23 — the "assign to the service owner" mode is gone with the
     * single owner it needed, so a new request always waits. This replaces
     * the test that proved the other branch.
     */
    public function testANewRequestAlwaysWaitsInTheQueue(): void {
        $engine = $this->engine();
        $this->open($engine);

        $queue = $engine->listQueue(self::DESK, 'svcowner');
        $this->assertCount(1, $queue['unclaimed'], 'a new request waits for an agent to claim it');
        $this->assertSame([], $queue['mine']);
        $this->assertSame('', $queue['unclaimed'][0]['internal']['assignee']);
    }

    // ── Phase B: the desk on the service team (v4.10.27) ──────────────

    public function testTheClosedTabHoldsWhatTheDeskFinishedInTheLastThirtyDays(): void {
        $engine = $this->engine();
        $a = $this->open($engine);
        $b = $this->open($engine);
        $this->open($engine); // still waiting — not closed

        $engine->claimStep($a['id'], 'agent1');
        $engine->completeStep($a['id'], 'agent1', 'done');
        $this->now += 60;
        $engine->claimStep($b['id'], 'agent2');
        $engine->rejectStep($b['id'], 'agent2', 'not something we do');

        $closed = $engine->listQueue(self::DESK, 'agent1')['closed'];
        $this->assertSame([$b['id'], $a['id']], array_column($closed, 'id'), 'newest first');
        $this->assertSame(WorkflowStepStatus::REJECTED, $closed[0]['desk']['status']);
        // Answered is closed for the desk while the requester has yet to confirm.
        $this->assertSame(WorkflowStepStatus::COMPLETED, $closed[1]['desk']['status']);
        $this->assertSame('agent1', $closed[1]['desk']['closedBy']);

        $this->now += (WorkflowEngine::CLOSED_WINDOW_DAYS + 1) * 86400;
        $this->assertSame([], $engine->listQueue(self::DESK, 'agent1')['closed'], 'older than the window drops off');
    }

    public function testTheBadgeCountsWhatNobodyHasClaimed(): void {
        $engine = $this->engine();
        $a = $this->open($engine);
        $this->open($engine);
        $this->assertSame(2, $engine->unclaimedCount(self::DESK));

        $engine->claimStep($a['id'], 'agent1');
        $this->assertSame(1, $engine->unclaimedCount(self::DESK));
        $this->assertSame(0, $engine->unclaimedCount('another-desk'));
    }

    public function testTheDeskWritesItsStateChangesToItsOwnTeamAndNotesStayOut(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);
        $engine->claimStep($view['id'], 'agent1');
        $engine->addInternalNote($view['id'], 'agent2', 'second opinion');
        $engine->assignStep($view['id'], 'svcowner', 'agent2');
        $engine->releaseStep($view['id'], 'agent2');
        $engine->claimStep($view['id'], 'agent2');
        $engine->completeStep($view['id'], 'agent2', 'done');

        $desk = array_values(array_filter($this->audited, static fn (array $a): bool => $a[0] === self::DESK));
        $this->assertSame([
            'service.request_received',
            'service.request_claimed',
            'service.request_assigned',
            'service.request_released',
            'service.request_claimed',
            'service.request_answered',
        ], array_column($desk, 1), 'every state change, in order — and the internal note is not one');
        $this->assertSame('agent2', $desk[2][3]['to']);
        $this->assertArrayHasKey('title', $desk[0][3]);

        // The requesting team's own record is unchanged: nothing service.* lands there.
        $theirs = array_filter($this->audited, static fn (array $a): bool => $a[0] === self::TEAM);
        $this->assertSame([], array_filter(array_column($theirs, 1), static fn (string $t): bool => str_starts_with($t, 'service.')));
    }

    public function testAWithdrawnRequestIsTheDesksToo(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);
        $engine->cancel($view['id'], 'requester', 'sorted it myself');

        $this->assertContains('service.request_cancelled', array_column($this->audited, 1));
        $closed = $engine->listQueue(self::DESK, 'agent1')['closed'];
        $this->assertSame(WorkflowStepStatus::CANCELLED, $closed[0]['desk']['status']);
    }

    public function testTheStatisticsCountThePeriodAndMeasureFromTheQueue(): void {
        $engine = $this->engine();
        $a = $this->open($engine);          // arrives at T
        $b = $this->open($engine);
        $this->open($engine);               // c: stays unclaimed

        $this->now += 600;                  // a is claimed 10 minutes after arriving
        $engine->claimStep($a['id'], 'agent1');
        $engine->releaseStep($a['id'], 'agent1');
        $this->now += 600;                  // and claimed again: the FIRST claim counts
        $engine->claimStep($a['id'], 'agent2');
        $this->now += 1800;                 // answered 50 minutes after arriving
        $engine->completeStep($a['id'], 'agent2', 'done');

        $engine->cancel($b['id'], 'requester');

        $stats  = $this->statistics()->forDesk(self::DESK, 'agent1', 30);
        $totals = $stats['totals'];
        $this->assertSame(3, $totals['received']);
        $this->assertSame(1, $totals['claimed']);
        $this->assertSame(1, $totals['closed']);
        $this->assertSame(1, $totals['withdrawn']);
        $this->assertSame(1, $totals['open']);
        $this->assertSame(1, $totals['unclaimed']);
        $this->assertSame(600, $totals['medianFirstClaim'], 'from the event log, not the step row a release cleared');
        $this->assertSame(3000, $totals['medianClose']);

        $this->assertCount(1, $stats['services']);
        $this->assertSame(3, $stats['services'][0]['received'], 'the breakdown adds up to the totals');
    }

    public function testTheStatisticsAreTheTeamsAndOnlyForAPeriodTheWidgetOffers(): void {
        $this->expectException(AccessDeniedException::class);
        $this->statistics()->forDesk(self::DESK, 'outsider', 30);
    }

    public function testAnUnofferedPeriodIsRefused(): void {
        $this->expectException(ValidationException::class);
        $this->statistics()->forDesk(self::DESK, 'agent1', 365);
    }

    public function testTheMedianIsTheMiddleValue(): void {
        $this->assertNull(ServiceDeskStatisticsService::median([]));
        $this->assertSame(5, ServiceDeskStatisticsService::median([9, 1, 5]));
        $this->assertSame(4, ServiceDeskStatisticsService::median([2, 6, 1, 9]));
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function statistics(): ServiceDeskStatisticsService {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
        return new ServiceDeskStatisticsService(
            $this->serviceTeams,
            $this->steps,
            $this->instances,
            $this->events,
            $this->registry,
            $this->timeFactory(),
            $l,
        );
    }

    /** An AuditService that remembers what it was asked to write. */
    private function auditRecorder(): AuditService {
        $audit = $this->createMock(AuditService::class);
        $audit->method('log')->willReturnCallback(
            function (string $teamId, string $type, ?string $actor, ?string $targetType = null, ?string $targetId = null, ?array $meta = null): void {
                $this->audited[] = [$teamId, $type, $actor, $meta ?? []];
            },
        );
        return $audit;
    }

    /** @return array<string, mixed> */
    private function open(WorkflowEngine $engine): array {
        return $engine->create('svc', self::TEAM, 'requester', ['reason' => 'because']);
    }

    private function stepStatus(int $instanceId, string $key): string {
        foreach ($this->steps->findByInstance($instanceId) as $step) {
            if ($step->getStepKey() === $key) {
                return $step->getStepStatus();
            }
        }
        return '';
    }

    /** @return string[] */
    private function eventTypes(int $instanceId): array {
        return array_map(
            static fn (array $e): string => $e['type'],
            (new WorkflowEventService($this->events, $this->timeFactory()))->listForInstance($instanceId),
        );
    }

    /**
     * A ServiceTeamService over a fixed roster. Mocked rather than faked:
     * the eligibility *rule* has its own test, and what the engine needs
     * from it here is one honest answer per question.
     *
     * `$owner` is the person `isServiceOwner()` answers true for — since
     * v4.10.23 that means an admin of the service team, not a column.
     *
     * @param string[] $agents
     */
    private function serviceTeamsFor(
        string $teamId,
        string $owner,
        array  $agents,
    ): ServiceTeamService {
        $row = new ServiceTeam();
        $row->setTeamId($teamId);
        $row->setActive(1);

        $mock = $this->createMock(ServiceTeamService::class);
        $mock->method('isEligibleAgent')->willReturnCallback(
            static fn (string $uid, string $id): bool => $id === $teamId && in_array($uid, $agents, true),
        );
        $mock->method('isServiceOwner')->willReturnCallback(
            static fn (string $uid, string $id): bool => $id === $teamId && $uid === $owner,
        );
        $mock->method('isActiveServiceTeam')->willReturnCallback(
            static fn (string $id): bool => $id === $teamId,
        );
        $mock->method('get')->willReturnCallback(
            static fn (string $id): ?ServiceTeam => $id === $teamId ? $row : null,
        );
        $mock->method('serviceTeamForDefinition')->willReturn($teamId);
        // v4.10.31 — My Work finds a desk's requests through the desks a person works.
        $mock->method('serviceTeamsForAgent')->willReturnCallback(
            static fn (string $uid): array => in_array($uid, $agents, true) ? [$teamId] : [],
        );
        $mock->method('eligibleAgents')->willReturnCallback(
            static fn (string $id): array => $id === $teamId ? $agents : [],
        );
        // The licence gate the engine calls before every queue verb.
        $mock->method('requireLicence')->willReturnCallback(function (): void {
            if ($this->tier->tier() !== 'full') {
                throw new LicenseGateException('unlicensed', 'Service Teams require an active TeamHub licence.');
            }
        });
        // v4.11.0 — the same gate as a question: whether the view offers the conversation.
        $mock->method('isAvailable')->willReturnCallback(fn (): bool => $this->tier->tier() === 'full');
        return $mock;
    }

    private function timeFactory(): ITimeFactory {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);
        $time->method('getDateTime')->willReturn(new \DateTime());
        return $time;
    }

    private function engine(?WorkflowShareService $shares = null): WorkflowEngine {
        $time = $this->timeFactory();

        $db = $this->createMock(IDBConnection::class);
        $db->method('beginTransaction')->willReturnCallback(static function (): void {});
        $db->method('commit')->willReturnCallback(static function (): void {});
        $db->method('rollBack')->willReturnCallback(static function (): void {});

        $notifier = $this->createMock(WorkflowNotificationService::class);
        foreach (['stepAvailable', 'stepAssigned', 'statusRequested', 'informationRequested', 'informationProvided', 'messagePosted', 'ended', 'withdraw'] as $m) {
            $notifier->method($m)->willReturnCallback(function (...$args) use ($m): void {
                $this->notified[] = [$m, $args];
            });
        }

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
        $l->method('n')->willReturnCallback(static fn (string $one, string $many, int $count, array $p = []): string => $count === 1 ? $one : $many);

        return new WorkflowEngine(
            $this->registry,
            $this->instances,
            $this->steps,
            $this->participants,
            new WorkflowEventService($this->events, $time),
            $this->resolver,
            $notifier,
            $this->tier,
            $this->serviceTeams,
            // v4.10.21 - the real archive service over in-memory tables: a
            // request answered by this desk ends with two projections, and
            // the internal half must stay on the desk's side of them.
            $this->archive->service(
                $this->instances,
                $this->steps,
                $this->participants,
                new WorkflowEventService($this->events, $time),
                $this->resolver,
                $this->registry,
                $this->tier,
                $this->serviceTeams,
                $this->createMock(AuditService::class),
                $time,
                $l,
                $this->createMock(LoggerInterface::class),
            ),
            $this->archive->attachments,
            $this->auditRecorder(),
            $db,
            $time,
            $l,
            $this->createMock(LoggerInterface::class),
            $shares,
        );
    }
}
