<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Exception\WorkflowRateLimitException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\Workflow\WorkflowConfigService;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\Workflow\WorkflowEngine;
use OCA\TeamHub\Service\Workflow\WorkflowEventService;
use OCA\TeamHub\Service\Workflow\WorkflowNotificationService;
use OCA\TeamHub\Workflow\Definition\TeamRequestDefinition;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCA\TeamHub\Workflow\WorkflowEventType;
use OCA\TeamHub\Workflow\WorkflowStatus;
use OCA\TeamHub\Workflow\WorkflowStepStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The reference workflow — request a new team (WorkflowHub phase 2,
 * v4.10.14) — on the engine: the auto-completed first step, the four
 * actors, the handling desk frozen at creation, who may start and
 * approve, the notifications each step earns, and the status request
 * with its rate limit.
 *
 * **v4.10.23 — step 3 is the service team that holds the Nextcloud
 * services**, not a configured Nextcloud group. The desk is `d1`, its
 * agents are `itadmin` and `deskmate`, and `ncadmin` is a Nextcloud
 * administrator who is deliberately not on it.
 *
 * Roster on team `t1`: owner (9), mod (4), member (1), other (1),
 * outsider (not in the team).
 */
class TeamRequestWorkflowTest extends TestCase {

    private const TEAM = 't1';
    private const DESK = 'd1';

    private InMemoryWorkflowInstanceMapper $instances;
    private InMemoryWorkflowStepMapper $steps;
    private InMemoryWorkflowParticipantMapper $participants;
    private InMemoryWorkflowEventMapper $events;
    private FakeActorResolver $resolver;
    private WorkflowDefinitionRegistry $registry;
    private FakeLicenceTier $tier;
    private ArchiveHarness $archive;
    /** The desk that holds the Nextcloud services; '' = nobody does. */
    private string $handlingDesk = self::DESK;
    private int $now = 2_000_000;
    /** @var array<int, array{0: string, 1: array<int, mixed>}> */
    private array $notified = [];

    protected function setUp(): void {
        $this->instances    = new InMemoryWorkflowInstanceMapper();
        $this->steps        = new InMemoryWorkflowStepMapper();
        $this->participants = new InMemoryWorkflowParticipantMapper($this->instances);
        $this->events       = new InMemoryWorkflowEventMapper();
        $this->tier         = new FakeLicenceTier();
        $this->archive      = new ArchiveHarness();

        $this->resolver = new FakeActorResolver();
        $this->resolver->levels[self::TEAM] = ['owner' => 9, 'mod' => 4, 'member' => 1, 'other' => 1];
        $this->resolver->groups = ['ncadmin' => ['admin']];
        $this->resolver->admins = ['ncadmin'];
        $this->resolver->serviceAgents = [self::DESK => ['itadmin', 'deskmate']];

        $this->registry = new WorkflowDefinitionRegistry();
        $this->registry->register(new TeamRequestDefinition($this->serviceTeams()));
    }

    /**
     * A ServiceTeamService that says "this desk holds the Nextcloud
     * services, and these people work it". `$this->handlingDesk` is read
     * on every call so a test can move the bundle between two requests.
     */
    private function serviceTeams(): ServiceTeamService {
        $mock = $this->createMock(ServiceTeamService::class);
        $mock->method('serviceTeamForDefinition')->willReturnCallback(
            fn (): ?string => $this->handlingDesk === '' ? null : $this->handlingDesk,
        );
        $mock->method('isEligibleAgent')->willReturnCallback(
            fn (string $uid, string $id): bool => in_array($uid, $this->resolver->serviceAgents[$id] ?? [], true),
        );
        $mock->method('eligibleAgents')->willReturnCallback(
            fn (string $id): array => $this->resolver->serviceAgents[$id] ?? [],
        );
        $mock->method('isActiveServiceTeam')->willReturnCallback(
            fn (string $id): bool => isset($this->resolver->serviceAgents[$id]),
        );
        // What `listForParticipant()` asks: which desks is this person on?
        // Without it an agent's own request would not be in their list.
        $mock->method('serviceTeamsForAgent')->willReturnCallback(
            function (string $uid): array {
                $out = [];
                foreach ($this->resolver->serviceAgents as $deskId => $agents) {
                    if (in_array($uid, $agents, true)) {
                        $out[] = $deskId;
                    }
                }
                return $out;
            },
        );
        $mock->method('isServiceOwner')->willReturn(false);
        return $mock;
    }

    private function engine(): WorkflowEngine {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => ++$this->now);

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
            // v4.10.23 - the desk that handles step 3.
            $this->serviceTeams(),
            // v4.10.21 - the real archive service over in-memory tables: the
            // reference workflow is licensed here, so it ends with a record.
            $this->archive->service(
                $this->instances,
                $this->steps,
                $this->participants,
                new WorkflowEventService($this->events, $time),
                $this->resolver,
                $this->registry,
                $this->tier,
                $this->serviceTeams(),
                $this->createMock(AuditService::class),
                $time,
                $l,
                $this->createMock(LoggerInterface::class),
            ),
            $this->archive->attachments,
            $this->createMock(AuditService::class),
            $this->createMock(IDBConnection::class),
            $time,
            $l,
            $this->createMock(LoggerInterface::class),
        );
    }

    /** @return string[] notifier methods called, in order */
    private function notifications(): array {
        return array_map(static fn (array $c): string => $c[0], $this->notified);
    }

    private function request(WorkflowEngine $engine, string $by = 'member', string $team = self::TEAM): array {
        return $engine->create(TeamRequestDefinition::KEY, $team, $by, ['teamName' => 'Marketing', 'reason' => 'campaign work']);
    }

    // ──────────────────────────────────────────────────────────────────────

    public function testSubmittingIsTheFirstStepDoneAndTheApproversAreTold(): void {
        $view = $this->request($this->engine());

        self::assertSame('team_request', $view['definitionKey']);
        // v4.10.23 — version 2: step 3's actor changed from a configured
        // group to the desk.
        self::assertSame(2, $view['definitionVersion']);
        self::assertSame('Team request: Marketing', $view['title']);
        self::assertSame(['type' => 'team_request', 'id' => 'Marketing'], $view['subject']);
        self::assertSame(WorkflowStatus::IN_PROGRESS, $view['status']);
        self::assertSame('approve', $view['currentStep']);

        self::assertSame(['submit', 'approve', 'process', 'confirm'], array_column($view['steps'], 'key'));
        self::assertSame(
            [WorkflowStepStatus::COMPLETED, WorkflowStepStatus::AVAILABLE, WorkflowStepStatus::PENDING, WorkflowStepStatus::PENDING],
            array_column($view['steps'], 'status'),
        );
        self::assertSame('member', $view['steps'][0]['completedBy']);
        // The definition-only `initiator` actor was materialised into the requester.
        self::assertSame(['type' => 'user', 'id' => 'member'], $view['steps'][0]['actor']);
        self::assertSame(['type' => 'user', 'id' => 'member'], $view['steps'][3]['actor']);
        self::assertSame(['type' => 'team_moderator', 'id' => ''], $view['steps'][1]['actor']);
        // The definition's service-agent placeholder was resolved into the desk.
        self::assertSame(['type' => 'service_agent', 'id' => self::DESK], $view['steps'][2]['actor']);
        self::assertSame('Request submitted', $view['steps'][0]['label']);
        self::assertSame('The service team creates the team', $view['steps'][2]['label']);

        // Participants: the requester once (initiator, also the confirm actor), the moderators, the desk.
        $roster = array_map(static fn (array $p): string => $p['actor']['type'] . ':' . $p['actor']['id'], $view['participants']);
        self::assertSame(['user:member', 'team_moderator:', 'service_agent:' . self::DESK], $roster);

        // Requester's own step was started and completed in their name; the approvers were told, nobody else.
        $types = array_values(array_filter($this->events->typesFor(1), static fn (string $t): bool => $t !== WorkflowEventType::PARTICIPANT_ADDED));
        self::assertSame([
            WorkflowEventType::CREATED, WorkflowEventType::STEP_AVAILABLE, WorkflowEventType::STEP_STARTED,
            WorkflowEventType::STEP_COMPLETED, WorkflowEventType::STEP_AVAILABLE,
        ], $types);
        self::assertSame(['withdraw', 'stepAvailable'], $this->notifications());
        self::assertSame('approve', $this->notified[1][1][1]->getStepKey());
        self::assertSame('member', $this->notified[1][1][2]);

        // The requester is a participant but not responsible now.
        self::assertTrue($view['viewer']['isParticipant']);
        self::assertFalse($view['viewer']['canAct']);
        self::assertTrue($view['viewer']['canRequestStatus']);
        self::assertTrue($view['viewer']['canCancel']);
    }

    public function testTheWholePathToACreatedTeam(): void {
        $engine = $this->engine();
        $this->request($engine);

        // A plain member cannot approve; the moderator can.
        try {
            $engine->completeStep(1, 'other');
            self::fail('a member approved');
        } catch (AccessDeniedException) {
        }
        $view = $engine->completeStep(1, 'mod', 'fine by me');
        self::assertSame('process', $view['currentStep']);
        self::assertSame('mod', $view['steps'][1]['completedBy']);

        // The owner or a Nextcloud administrator cannot process; the desk can.
        // `ncadmin` is the one that matters: administering a server is not
        // the same as being on the desk (DESIGN §2.144).
        foreach (['owner', 'ncadmin', 'member'] as $uid) {
            try {
                $engine->completeStep(1, $uid);
                self::fail($uid . ' processed the request');
            } catch (AccessDeniedException) {
            }
        }
        // v4.10.39 — a desk task is claimed before it is acted on.
        $engine->claimStep(1, 'itadmin');
        $view = $engine->completeStep(1, 'itadmin', 'created as Marketing');
        self::assertSame('confirm', $view['currentStep']);
        // member, team_moderator, the desk, then mod and itadmin as they acted.
        self::assertSame(['type' => 'user', 'id' => 'itadmin'], $view['participants'][4]['actor']);

        // Only the requester confirms.
        try {
            $engine->completeStep(1, 'mod');
            self::fail('the moderator confirmed for the requester');
        } catch (AccessDeniedException) {
        }
        $view = $engine->completeStep(1, 'member');
        self::assertSame(WorkflowStatus::COMPLETED, $view['status']);
        self::assertSame(WorkflowEngine::OUTCOME_COMPLETED, $view['outcome']);
        self::assertSame(
            [WorkflowStepStatus::COMPLETED, WorkflowStepStatus::COMPLETED, WorkflowStepStatus::COMPLETED, WorkflowStepStatus::COMPLETED],
            array_column($view['steps'], 'status'),
        );

        // One bell per hand-over, one at the end; each preceded by a withdrawal.
        self::assertSame(
            ['withdraw', 'stepAvailable', 'withdraw', 'stepAvailable', 'withdraw', 'stepAvailable', 'withdraw', 'ended'],
            $this->notifications(),
        );
        // The end is told to everybody who took part by name: member, mod, itadmin.
        self::assertEqualsCanonicalizing(['member', 'mod', 'itadmin'], $this->notified[7][1][1]);
        self::assertContains(1, array_column($engine->listForParticipant('itadmin'), 'id'));
        self::assertContains(1, array_column($engine->listForParticipant('mod'), 'id'));
    }

    /**
     * v4.10.23 — the desk is resolved once, at creation, and copied onto
     * the step row. A request stays with the desk that took it even after
     * the Nextcloud services move to another team.
     */
    public function testTheHandlingDeskIsResolvedAtCreationAndFrozen(): void {
        $engine = $this->engine();
        $this->request($engine);

        $this->handlingDesk = 'd2';
        $this->resolver->serviceAgents['d2'] = ['helper'];
        $this->request($engine, 'other');

        self::assertSame(self::DESK, $engine->get(1, 'member')['steps'][2]['actor']['id']);
        self::assertSame('d2', $engine->get(2, 'other')['steps'][2]['actor']['id']);

        $engine->completeStep(1, 'mod');
        $engine->completeStep(2, 'mod');
        try {
            $engine->completeStep(1, 'helper');
            self::fail('the new desk processed a request created for the old one');
        } catch (AccessDeniedException) {
        }
        $engine->claimStep(1, 'itadmin');
        $engine->claimStep(2, 'helper');
        self::assertSame('confirm', $engine->completeStep(1, 'itadmin')['currentStep']);
        self::assertSame('confirm', $engine->completeStep(2, 'helper')['currentStep']);
    }

    /**
     * v4.10.23 — with nobody holding the Nextcloud services the workflow
     * is dark: `canStart()` refuses before a placeholder that resolves to
     * nothing could reach the engine. This is what hides the start points
     * in the navigation.
     */
    public function testNobodyCanAskForATeamWhenNoDeskAnswers(): void {
        $this->handlingDesk = '';
        $engine = $this->engine();
        try {
            $this->request($engine);
            self::fail('a team request opened with no desk to answer it');
        } catch (NotFoundException) {
            // The engine refuses a definition that is not startable the same
            // way it refuses one it has never heard of: on an instance where
            // nobody answers, this workflow does not exist.
        }
        self::assertSame([], $this->instances->rows);
    }

    public function testOnlyMembersOfTheRequestingTeamMayStartAndSeveralMayBeOpen(): void {
        $engine = $this->engine();
        try {
            $this->request($engine, 'outsider');
            self::fail('an outsider started a team request');
        } catch (AccessDeniedException) {
        }
        $this->request($engine, 'member');
        $this->request($engine, 'other');
        self::assertCount(2, $this->instances->rows);
    }

    public function testThePayloadIsValidated(): void {
        $engine = $this->engine();
        foreach ([
            ['reason' => 'x'],
            ['teamName' => '   ', 'reason' => 'x'],
            ['teamName' => 'Ok'],
            ['teamName' => str_repeat('a', 256), 'reason' => 'x'],
            ['teamName' => "bad\x00name", 'reason' => 'x'],
        ] as $bad) {
            try {
                $engine->create(TeamRequestDefinition::KEY, self::TEAM, 'member', $bad);
                self::fail('accepted ' . json_encode($bad));
            } catch (ValidationException) {
            }
        }
        $view = $engine->create(TeamRequestDefinition::KEY, self::TEAM, 'member', ['teamName' => '  Sales  ', 'reason' => ' r ', 'actor' => 'ncadmin']);
        // Trimmed, and anything the client added beyond the schema is dropped.
        self::assertSame(['teamName' => 'Sales', 'reason' => 'r'], $view['data']);
    }

    public function testAModeratorCanRejectAndTheRequesterIsTold(): void {
        $engine = $this->engine();
        $this->request($engine);
        $view = $engine->rejectStep(1, 'owner', 'we have one already');
        self::assertSame(WorkflowStatus::REJECTED, $view['status']);
        self::assertSame(
            [WorkflowStepStatus::COMPLETED, WorkflowStepStatus::REJECTED, WorkflowStepStatus::SKIPPED, WorkflowStepStatus::SKIPPED],
            array_column($view['steps'], 'status'),
        );
        self::assertSame('ended', end($this->notified)[0]);
        self::assertSame('we have one already', end($this->notified)[1][3]);
    }

    public function testInformationRoundTripTellsTheRightPeople(): void {
        $engine = $this->engine();
        $this->request($engine);
        $engine->requestInformation(1, 'mod', 'how many members?');
        self::assertSame('informationRequested', end($this->notified)[0]);
        self::assertSame(WorkflowStatus::WAITING, $engine->get(1, 'member')['status']);
        $engine->provideInformation(1, 'member', 'about twelve');
        self::assertSame('informationProvided', end($this->notified)[0]);
        self::assertSame(WorkflowStatus::IN_PROGRESS, $engine->get(1, 'member')['status']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Status requests
    // ──────────────────────────────────────────────────────────────────────

    public function testAWaitingParticipantMayAskForAStatusUpdateOnceADay(): void {
        $engine = $this->engine();
        $this->request($engine);
        $before = count($this->events->rows);

        self::assertTrue($engine->get(1, 'member')['viewer']['canRequestStatus'], 'offered before asking');

        $view = $engine->requestStatus(1, 'member', 'any news?');
        // v4.10.17 — the answer to the ask, and every later read, says the
        // viewer cannot ask again: the cooldown is part of the view, not
        // only of the 429. My Work kept offering the button and the second
        // press failed (CLAUDE.md § Permissions — hidden, not left to fail).
        self::assertFalse($view['viewer']['canRequestStatus'], 'not offered again in the same window');
        self::assertFalse($engine->get(1, 'member')['viewer']['canRequestStatus'], 'and not on a fresh read');
        self::assertFalse($engine->listForParticipant('member')[0]['viewer']['canRequestStatus'], 'nor in the list');
        // Somebody else's allowance is their own. `itadmin` is a participant
        // through the `process` step's group (`it` here) and holds nothing at
        // `approve`, so they may still ask. (`mod` may not, but for the older
        // reason: they hold the active step, so there is nobody to ask.)
        self::assertTrue($engine->get(1, 'itadmin')['viewer']['canRequestStatus'], 'per person, not per workflow');

        // Nothing moved; one event; one bell to the responsible actor.
        self::assertSame(WorkflowStatus::IN_PROGRESS, $view['status']);
        self::assertSame('approve', $view['currentStep']);
        self::assertSame(WorkflowStepStatus::AVAILABLE, $view['steps'][1]['status']);
        self::assertSame($before + 1, count($this->events->rows));
        $last = end($this->events->rows);
        self::assertSame(WorkflowEventType::STATUS_REQUESTED, $last->getEventType());
        self::assertSame('member', $last->getActorUid());
        self::assertSame('approve', $last->getStepKey());
        self::assertSame(['note' => 'any news?'], $last->getPayload());
        self::assertSame('statusRequested', end($this->notified)[0]);
        self::assertSame('member', end($this->notified)[1][2]);

        // A second ask on the same step inside the interval: refused, no event, no bell.
        $bells = count($this->notified);
        try {
            $engine->requestStatus(1, 'member', 'hello?');
            self::fail('a repeat status request went through');
        } catch (WorkflowRateLimitException $e) {
            self::assertGreaterThan(0, $e->retryAfterSeconds);
            self::assertLessThanOrEqual(WorkflowEngine::STATUS_REQUEST_INTERVAL, $e->retryAfterSeconds);
            // v4.10.17 — the message reaches the browser verbatim, so it is
            // translated and counts in whole hours with a real plural. It
            // used to be concatenated English ending in "hour(s)".
            self::assertStringContainsString('24 hours', $e->getMessage());
            self::assertStringNotContainsString('hour(s)', $e->getMessage());
        }
        self::assertSame($before + 1, count($this->events->rows));
        self::assertSame($bells, count($this->notified));

        // After the interval the requester may ask again.
        $this->now += WorkflowEngine::STATUS_REQUEST_INTERVAL;
        $engine->requestStatus(1, 'member');
        self::assertSame($before + 2, count($this->events->rows));

        // A new step is a new allowance, and every participant has their own:
        // the moderator who approved is a participant now and not responsible.
        $engine->completeStep(1, 'mod');
        $engine->requestStatus(1, 'member', 'is it made yet?');
        self::assertSame('process', end($this->events->rows)->getStepKey());
        $engine->requestStatus(1, 'mod');
        self::assertSame('mod', end($this->events->rows)->getActorUid());
    }

    public function testTheResponsibleActorAndOutsidersCannotAskForAStatusUpdate(): void {
        $engine = $this->engine();
        $this->request($engine);
        try {
            $engine->requestStatus(1, 'mod');
            self::fail('the responsible actor asked themselves');
        } catch (ValidationException) {
        }
        try {
            $engine->requestStatus(1, 'outsider');
            self::fail('an outsider asked');
        } catch (AccessDeniedException) {
        }
        // A Nextcloud administrator may view, but is not a participant here.
        try {
            $engine->requestStatus(1, 'ncadmin');
            self::fail('an administrator who is not a participant asked');
        } catch (AccessDeniedException) {
        }
        self::assertFalse($engine->get(1, 'mod')['viewer']['canRequestStatus']);
        self::assertSame([], array_filter($this->events->typesFor(1), static fn (string $t): bool => $t === WorkflowEventType::STATUS_REQUESTED));
    }

    public function testViewingIsForParticipantsOnly(): void {
        $engine = $this->engine();
        $this->request($engine);
        self::assertSame(1, $engine->get(1, 'mod')['id']);
        self::assertSame(1, $engine->get(1, 'itadmin')['id']);
        self::assertSame(1, $engine->get(1, 'member')['id']);
        // v4.10.31 — a desk member's My Work lists only what they worked
        // on; itadmin has not touched this request yet. Viewing (above) is
        // unchanged.
        self::assertSame([], array_column($engine->listForParticipant('itadmin'), 'id'));
        self::assertSame([], $engine->listForParticipant('other'));
        try {
            $engine->get(1, 'other');
            self::fail('a plain member saw a request they are not part of');
        } catch (AccessDeniedException) {
        }
        try {
            $engine->get(1, 'outsider');
            self::fail('an outsider saw the request');
        } catch (AccessDeniedException) {
        }
        // Nothing internal in the view.
        $view = $engine->get(1, 'member');
        self::assertArrayNotHasKey('id', $view['steps'][0]);
        self::assertArrayNotHasKey('id', $view['participants'][0]);
        self::assertArrayNotHasKey('retentionUntil', $view);
    }
}
