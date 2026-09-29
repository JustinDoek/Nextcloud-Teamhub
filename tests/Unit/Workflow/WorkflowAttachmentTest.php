<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Db\ServiceTeam;
use OCA\TeamHub\Db\WorkflowInstance;
use OCA\TeamHub\Db\WorkflowParticipant;
use OCA\TeamHub\Db\WorkflowStep;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\LicenseGateException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Exception\WorkflowTransitionException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\Workflow\WorkflowAttachmentService;
use OCA\TeamHub\Service\Workflow\WorkflowEventService;
use OCA\TeamHub\Service\Workflow\WorkflowLicenceTier;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowAttachmentVisibility;
use OCA\TeamHub\Workflow\WorkflowEventType;
use OCA\TeamHub\Workflow\WorkflowParticipantRole;
use OCA\TeamHub\Workflow\WorkflowStatus;
use OCA\TeamHub\Workflow\WorkflowStepStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Documents on a workflow, and their classification (WorkflowHub phase 6,
 * v4.10.21).
 *
 * The rule the archive depends on: **every document says who may see it,
 * and nothing can store one that does not.** These cases are the other
 * half of `WorkflowArchiveTest`, which takes documents as given and asks
 * what the projections do with them.
 *
 * One open service request on team `t1`: `requester` asked, the desk
 * `sdesk` holds the handling step, `agent1` works it, `svcowner` owns it.
 * `plainmember` is in the team but not on the request; `outsider` is
 * nobody. Files 11 and 12 are readable, 13 is a folder, 99 does not exist.
 */
class WorkflowAttachmentTest extends TestCase {

    private const TEAM     = 't1';
    private const DESK     = 'sdesk';
    private const INSTANCE = 1;

    private InMemoryWorkflowInstanceMapper $instances;
    private InMemoryWorkflowStepMapper $steps;
    private InMemoryWorkflowParticipantMapper $participants;
    private InMemoryWorkflowEventMapper $events;
    private InMemoryWorkflowAttachmentMapper $attachments;
    private FakeActorResolver $resolver;
    private FakeLicenceTier $tier;
    private int $now = 1_700_000_000;

    protected function setUp(): void {
        $this->instances    = new InMemoryWorkflowInstanceMapper();
        $this->steps        = new InMemoryWorkflowStepMapper();
        $this->participants = new InMemoryWorkflowParticipantMapper($this->instances);
        $this->events       = new InMemoryWorkflowEventMapper();
        $this->attachments  = new InMemoryWorkflowAttachmentMapper();
        $this->tier         = new FakeLicenceTier(WorkflowLicenceTier::FULL);

        $this->resolver = new FakeActorResolver();
        $this->resolver->levels[self::TEAM] = ['owner' => 9, 'requester' => 1, 'plainmember' => 1];
        $this->resolver->serviceAgents[self::DESK] = ['svcowner', 'agent1', 'agent2'];
        $this->resolver->groups = ['ncadmin' => ['admin']];
        $this->resolver->admins = ['ncadmin'];

        $this->givenAnOpenServiceRequest();
    }

    // ── The classification is always stated ────────────────────────────

    public function testADocumentWithoutAClassificationIsNotStored(): void {
        $service = $this->service();
        foreach (['', 'public', 'everyone', 'REQUESTER'] as $bad) {
            try {
                $service->attach(self::INSTANCE, 'requester', 11, $bad);
                self::fail('"' . $bad . '" is not a classification');
            } catch (ValidationException) {
                // expected
            }
        }
        self::assertSame([], $this->attachments->rows, 'nothing was stored on the way');
    }

    public function testAParticipantAttachesARequesterVisibleDocument(): void {
        $document = $this->service()->attach(self::INSTANCE, 'requester', 11, WorkflowAttachmentVisibility::REQUESTER);

        self::assertSame(11, $document['fileId']);
        self::assertSame('quote.pdf', $document['fileName']);
        self::assertSame(WorkflowAttachmentVisibility::REQUESTER, $document['visibility']);
        self::assertSame('requester', $document['addedBy']);
        // The step it belongs to is the active one unless the caller names
        // a real one.
        self::assertSame('handle', $document['stepKey']);

        // The arrival is in the history, and it is readable by everybody
        // because the document is.
        $event = end($this->events->rows);
        self::assertSame(WorkflowEventType::ATTACHMENT_ADDED, $event->getEventType());
        self::assertSame(WorkflowEventType::VISIBILITY_ALL, $event->getVisibility());
    }

    public function testOnlyAnAgentOfTheHandlingDeskMayClassifyADocumentAsInternal(): void {
        $service = $this->service();

        foreach (['requester', 'owner', 'ncadmin'] as $uid) {
            try {
                $service->attach(self::INSTANCE, $uid, 11, WorkflowAttachmentVisibility::INTERNAL);
                self::fail($uid . ' must not be able to decide what the desk keeps to itself');
            } catch (AccessDeniedException) {
                // expected
            }
        }

        $document = $service->attach(self::INSTANCE, 'agent1', 11, WorkflowAttachmentVisibility::INTERNAL);
        self::assertSame(WorkflowAttachmentVisibility::INTERNAL, $document['visibility']);

        // An internal document's arrival is internal too: the requester's
        // history does not learn that a file they may not read exists.
        $event = end($this->events->rows);
        self::assertSame(WorkflowEventType::VISIBILITY_INTERNAL, $event->getVisibility());
    }

    public function testAnAgentMayAlsoAttachSomethingForTheRequester(): void {
        $document = $this->service()->attach(self::INSTANCE, 'agent1', 12, WorkflowAttachmentVisibility::REQUESTER);
        self::assertSame(WorkflowAttachmentVisibility::REQUESTER, $document['visibility']);
    }

    public function testSomebodyOutsideTheWorkflowAttachesNothing(): void {
        $service = $this->service();
        foreach (['plainmember', 'outsider'] as $uid) {
            try {
                $service->attach(self::INSTANCE, $uid, 11, WorkflowAttachmentVisibility::REQUESTER);
                self::fail($uid . ' is not part of this workflow');
            } catch (AccessDeniedException) {
                // expected
            }
        }
        self::assertSame([], $this->attachments->rows);
    }

    // ── The file is resolved in the caller's own Nextcloud ─────────────

    public function testAFileTheCallerCannotReadCannotBeAttached(): void {
        $service = $this->service();
        // 99 is in nobody's folder; `plainmember` can read nothing at all.
        try {
            $service->attach(self::INSTANCE, 'requester', 99, WorkflowAttachmentVisibility::REQUESTER);
            self::fail('a file that does not resolve cannot be attached');
        } catch (NotFoundException) {
            // expected
        }
        try {
            $service->attach(self::INSTANCE, 'requester', 0, WorkflowAttachmentVisibility::REQUESTER);
            self::fail('there is no file 0');
        } catch (NotFoundException) {
            // expected
        }
        self::assertSame([], $this->attachments->rows);
    }

    public function testAFolderIsNotADocument(): void {
        $this->expectException(NotFoundException::class);
        $this->service()->attach(self::INSTANCE, 'requester', 13, WorkflowAttachmentVisibility::REQUESTER);
    }

    public function testTheSameFileIsNotAttachedTwice(): void {
        $service = $this->service();
        $service->attach(self::INSTANCE, 'requester', 11, WorkflowAttachmentVisibility::REQUESTER);

        // Two rows for one file could disagree about its classification,
        // which is the one thing this table must not allow.
        $this->expectException(ValidationException::class);
        $service->attach(self::INSTANCE, 'agent1', 11, WorkflowAttachmentVisibility::INTERNAL);
    }

    // ── Reading ────────────────────────────────────────────────────────

    public function testTheRequesterDoesNotSeeTheDesksOwnMaterial(): void {
        $service = $this->service();
        $service->attach(self::INSTANCE, 'agent1', 11, WorkflowAttachmentVisibility::REQUESTER);
        $service->attach(self::INSTANCE, 'agent1', 12, WorkflowAttachmentVisibility::INTERNAL);

        self::assertSame(['quote.pdf'], array_column($service->listFor(self::INSTANCE, 'requester'), 'fileName'));
        self::assertSame(
            ['quote.pdf', 'internal.pdf'],
            array_column($service->listFor(self::INSTANCE, 'agent1'), 'fileName'),
        );
        // A Nextcloud administrator reads the workflow, not the desk.
        self::assertSame(['quote.pdf'], array_column($service->listFor(self::INSTANCE, 'ncadmin'), 'fileName'));
    }

    public function testSomebodyOutsideTheWorkflowListsNothing(): void {
        $this->expectException(AccessDeniedException::class);
        $this->service()->listFor(self::INSTANCE, 'outsider');
    }

    // ── Removing ───────────────────────────────────────────────────────

    public function testOnlyTheAttacherOrTheServiceOwnerRemovesADocument(): void {
        $service  = $this->service();
        $mine     = $service->attach(self::INSTANCE, 'requester', 11, WorkflowAttachmentVisibility::REQUESTER);
        $internal = $service->attach(self::INSTANCE, 'agent1', 12, WorkflowAttachmentVisibility::INTERNAL);

        try {
            $service->remove($mine['id'], 'agent1');
            self::fail('removing somebody else\'s evidence is the move this rule prevents');
        } catch (AccessDeniedException) {
            // expected
        }
        $service->remove($mine['id'], 'requester');
        self::assertNull($this->attachments->findById($mine['id']));

        // The service owner is answerable for what their desk keeps.
        $service->remove($internal['id'], 'svcowner');
        self::assertNull($this->attachments->findById($internal['id']));

        $removals = array_values(array_filter(
            $this->events->rows,
            static fn ($e): bool => $e->getEventType() === WorkflowEventType::ATTACHMENT_REMOVED,
        ));
        self::assertCount(2, $removals);
    }

    // ── An ended workflow is closed ────────────────────────────────────

    public function testAnEndedWorkflowTakesNoMoreDocumentsAndGivesNoneUp(): void {
        $service  = $this->service();
        $existing = $service->attach(self::INSTANCE, 'requester', 11, WorkflowAttachmentVisibility::REQUESTER);

        $instance = $this->instances->findById(self::INSTANCE);
        $instance->setStatus(WorkflowStatus::COMPLETED);
        $this->instances->update($instance);

        try {
            $service->attach(self::INSTANCE, 'agent1', 12, WorkflowAttachmentVisibility::INTERNAL);
            self::fail('the record is closed');
        } catch (WorkflowTransitionException) {
            // expected
        }
        try {
            $service->remove($existing['id'], 'requester');
            self::fail('a record whose evidence could still be withdrawn would not be one');
        } catch (WorkflowTransitionException) {
            // expected
        }
        self::assertNotNull($this->attachments->findById($existing['id']));

        // Reading an ended workflow's documents still works — that is what
        // the archive projections do.
        self::assertCount(1, $service->listFor(self::INSTANCE, 'requester'));
    }

    // ── The licence ────────────────────────────────────────────────────

    public function testDocumentsAreLicensedLikeTheArchiveTheyFeed(): void {
        $service  = $this->service();
        $existing = $service->attach(self::INSTANCE, 'requester', 11, WorkflowAttachmentVisibility::REQUESTER);

        $this->tier->set(WorkflowLicenceTier::BASIC);
        foreach ([
            static fn () => $service->attach(self::INSTANCE, 'requester', 12, WorkflowAttachmentVisibility::REQUESTER),
            static fn () => $service->listFor(self::INSTANCE, 'requester'),
            static fn () => $service->remove($existing['id'], 'requester'),
        ] as $call) {
            try {
                $call();
                self::fail('documents need the licence');
            } catch (LicenseGateException) {
                // expected
            }
        }
        // The row the licensed instance wrote is untouched: an expiring
        // licence closes the door, it does not empty the room.
        self::assertNotNull($this->attachments->findById($existing['id']));
    }

    public function testTheCapIsEnforced(): void {
        $service = $this->service();
        for ($i = 0; $i < WorkflowAttachmentService::MAX_PER_WORKFLOW; $i++) {
            $row = new \OCA\TeamHub\Db\WorkflowAttachment();
            $row->setInstanceId(self::INSTANCE);
            $row->setFileId(1000 + $i);
            $row->setFileName('f' . $i . '.pdf');
            $row->setVisibility(WorkflowAttachmentVisibility::REQUESTER);
            $row->setAddedBy('requester');
            $row->setAddedAt(++$this->now);
            $this->attachments->insert($row);
        }

        $this->expectException(ValidationException::class);
        $service->attach(self::INSTANCE, 'requester', 11, WorkflowAttachmentVisibility::REQUESTER);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * One open service request, written straight to the tables: the
     * engine's own path is covered by its own tests, and what these cases
     * need is the shape, not the journey.
     */
    private function givenAnOpenServiceRequest(): void {
        $instance = new WorkflowInstance();
        $instance->setDefinitionKey('svc');
        $instance->setDefinitionVersion(1);
        $instance->setTeamId(self::TEAM);
        $instance->setStatus(WorkflowStatus::IN_PROGRESS);
        $instance->setCurrentStep('handle');
        $instance->setStartedBy('requester');
        $instance->setStartedAt($this->now);
        $instance->setUpdatedAt($this->now);
        $this->instances->insert($instance);

        $submit = new WorkflowStep();
        $submit->setInstanceId(self::INSTANCE);
        $submit->setStepKey('submit');
        $submit->setStepOrder(1);
        $submit->setStepStatus(WorkflowStepStatus::COMPLETED);
        $submit->setActorType(WorkflowActor::TYPE_USER);
        $submit->setActorId('requester');
        $this->steps->insert($submit);

        $handle = new WorkflowStep();
        $handle->setInstanceId(self::INSTANCE);
        $handle->setStepKey('handle');
        $handle->setStepOrder(2);
        $handle->setStepStatus(WorkflowStepStatus::IN_PROGRESS);
        $handle->setActorType(WorkflowActor::TYPE_SERVICE_AGENT);
        $handle->setActorId(self::DESK);
        $this->steps->insert($handle);

        $initiator = new WorkflowParticipant();
        $initiator->setInstanceId(self::INSTANCE);
        $initiator->setActorType(WorkflowActor::TYPE_USER);
        $initiator->setActorId('requester');
        $initiator->setWfRole(WorkflowParticipantRole::INITIATOR);
        $initiator->setAddedAt($this->now);
        $this->participants->insert($initiator);

        $desk = new WorkflowParticipant();
        $desk->setInstanceId(self::INSTANCE);
        $desk->setActorType(WorkflowActor::TYPE_SERVICE_AGENT);
        $desk->setActorId(self::DESK);
        $desk->setWfRole(WorkflowParticipantRole::RESPONSIBLE);
        $desk->setAddedAt($this->now);
        $this->participants->insert($desk);
    }

    /**
     * A root folder in which 11 and 12 are files, 13 is a folder and
     * everything else is absent — for every user but `plainmember`, who
     * has no folder at all.
     */
    private function rootFolder(): IRootFolder {
        $quote = $this->createMock(File::class);
        $quote->method('getName')->willReturn('quote.pdf');
        $quote->method('isReadable')->willReturn(true);

        $internal = $this->createMock(File::class);
        $internal->method('getName')->willReturn('internal.pdf');
        $internal->method('isReadable')->willReturn(true);

        $directory = $this->createMock(Folder::class);
        $directory->method('getName')->willReturn('a folder');

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturnCallback(
            static fn (int $id): array => match ($id) {
                11      => [$quote],
                12      => [$internal],
                13      => [$directory],
                default => [],
            },
        );

        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(
            function (string $uid) use ($userFolder): Folder {
                if ($uid === 'plainmember') {
                    throw new FilesNotFoundException('no home for ' . $uid);
                }
                return $userFolder;
            },
        );
        return $root;
    }

    private function serviceTeamsMock(): ServiceTeamService {
        // v4.10.23 — no owner on the row; `isServiceOwner` below is the
        // team-admin check that replaced it.
        $row = new ServiceTeam();
        $row->setTeamId(self::DESK);
        $row->setActive(1);

        $mock = $this->createMock(ServiceTeamService::class);
        $mock->method('isEligibleAgent')->willReturnCallback(
            static fn (string $uid, string $id): bool => $id === self::DESK
                && in_array($uid, ['svcowner', 'agent1', 'agent2'], true),
        );
        $mock->method('isServiceOwner')->willReturnCallback(
            static fn (string $uid, string $id): bool => $id === self::DESK && $uid === 'svcowner',
        );
        $mock->method('get')->willReturnCallback(
            static fn (string $id): ?ServiceTeam => $id === self::DESK ? $row : null,
        );
        return $mock;
    }

    private function service(): WorkflowAttachmentService {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => ++$this->now);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

        return new WorkflowAttachmentService(
            $this->attachments,
            $this->instances,
            $this->steps,
            $this->participants,
            new WorkflowEventService($this->events, $time),
            $this->resolver,
            $this->tier,
            $this->serviceTeamsMock(),
            $this->createMock(AuditService::class),
            $this->rootFolder(),
            $time,
            $l,
            $this->createMock(LoggerInterface::class),
        );
    }
}
