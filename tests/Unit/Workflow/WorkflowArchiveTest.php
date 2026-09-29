<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Db\ServiceTeam;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\LicenseGateException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\Workflow\WorkflowArchiveService;
use OCA\TeamHub\Service\Workflow\WorkflowEngine;
use OCA\TeamHub\Service\Workflow\WorkflowEventService;
use OCA\TeamHub\Service\Workflow\WorkflowLicenceTier;
use OCA\TeamHub\Service\Workflow\WorkflowNotificationService;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowArchiveAudience;
use OCA\TeamHub\Workflow\WorkflowAttachmentVisibility;
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
 * Licensed workflow archiving (WorkflowHub phase 6, v4.10.21) —
 * `docs/workflow-archiving.md`.
 *
 * The product rules this covers, in the order they matter:
 *
 *   - a licensed workflow that ends is **recorded once**, and the record is
 *     one row over the workflow that already exists — no second copy;
 *   - the same record is read as **two projections**, one per party, and
 *     the requesting team's never carries an internal note **whoever is
 *     asking**;
 *   - who may read which projection is decided from **live roles**, so a
 *     membership or an agent seat that ends closes the archive with it;
 *   - an **unlicensed** ending is phase 4's, untouched: the workflow is
 *     purged and nothing is archived.
 *
 * The cast, and it is chosen so the two audiences cannot be confused:
 * `requester` (level 1) asks from team `t1`, where `teamadmin` (8) and
 * `plainmember` (1) also are. The desk `sdesk` is answered by `svcowner`
 * (its owner), `agent1` and `agent2` — **none of whom is in `t1`**, so an
 * agent reading the record is reading the service projection and nothing
 * else. `ncadmin` administers the server; `outsider` is nobody.
 */
class WorkflowArchiveTest extends TestCase {

    private const TEAM = 't1';
    private const DESK = 'sdesk';

    private InMemoryWorkflowInstanceMapper $instances;
    private InMemoryWorkflowStepMapper $steps;
    private InMemoryWorkflowParticipantMapper $participants;
    private InMemoryWorkflowEventMapper $events;
    private FakeActorResolver $resolver;
    private FakeLicenceTier $tier;
    private WorkflowDefinitionRegistry $registry;
    private ArchiveHarness $archive;
    private ServiceTeamService $serviceTeams;
    /** Mutable so a test can take somebody off the desk after the fact. */
    private array $deskAgents = ['svcowner', 'agent1', 'agent2'];
    private int $now = 1_700_000_000;

    protected function setUp(): void {
        $this->instances    = new InMemoryWorkflowInstanceMapper();
        $this->steps        = new InMemoryWorkflowStepMapper();
        $this->participants = new InMemoryWorkflowParticipantMapper($this->instances);
        $this->events       = new InMemoryWorkflowEventMapper();
        $this->tier         = new FakeLicenceTier(WorkflowLicenceTier::FULL);
        $this->archive      = new ArchiveHarness();

        $this->resolver = new FakeActorResolver();
        $this->resolver->levels[self::TEAM] = ['owner' => 9, 'teamadmin' => 8, 'requester' => 1, 'plainmember' => 1];
        $this->resolver->serviceAgents[self::DESK] = &$this->deskAgents;
        $this->resolver->groups = ['ncadmin' => ['admin']];
        $this->resolver->admins = ['ncadmin'];

        $this->registry = $this->registryAt(1);
        $this->serviceTeams = $this->serviceTeamsMock();
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. A licensed workflow that completes is recorded — once
    // ──────────────────────────────────────────────────────────────────────

    public function testALicensedCompletionWritesOneAuthoritativeRecord(): void {
        $engine = $this->engine();
        $view   = $this->runToCompletion($engine);

        self::assertSame(WorkflowStatus::COMPLETED, $view['status']);
        self::assertArrayHasKey('archive', $view, 'the ending hands back the record it wrote');
        self::assertFalse($view['purged'], 'a licensed workflow is recorded, not removed');

        $rows = $this->archive->all();
        self::assertCount(1, $rows, 'one workflow, one record');
        $record = $rows[0];
        self::assertSame($view['id'], $record->getInstanceId());
        self::assertMatchesRegularExpression('/^WF-\d{4}-\d{6}$/', $record->getRefNumber());
        self::assertSame($view['archive']['reference'], $record->getRefNumber());
        self::assertSame(WorkflowStatus::COMPLETED, $record->getWfStatus());
        self::assertSame(WorkflowEngine::OUTCOME_COMPLETED, $record->getOutcome());
        self::assertSame(self::TEAM, $record->getTeamId());
        self::assertSame(self::DESK, $record->getServiceTeamId());
        self::assertSame('requester', $record->getStartedBy());

        // The workflow itself is still there: the record points at it rather
        // than replacing it. That is the whole of "no second copy".
        self::assertNotNull($this->instances->findById($view['id']));
        self::assertNotSame([], $this->steps->findByInstance($view['id']));
        self::assertNotSame([], $this->events->findByInstance($view['id']));
    }

    public function testARejectionAndAWithdrawalAreRecordedToo(): void {
        $engine = $this->engine();
        $rejected = $this->open($engine);
        $engine->claimStep($rejected['id'], 'agent1');
        $engine->rejectStep($rejected['id'], 'agent1', 'we do not do this');

        $withdrawn = $this->open($engine);
        $engine->cancel($withdrawn['id'], 'requester', 'no longer needed');

        self::assertCount(2, $this->archive->all());
        $first = $this->archive->archives->findByInstance($rejected['id']);
        self::assertSame(WorkflowEngine::OUTCOME_REJECTED, $first?->getOutcome());
        $second = $this->archive->archives->findByInstance($withdrawn['id']);
        self::assertSame(WorkflowEngine::OUTCOME_CANCELLED, $second?->getOutcome());

        // And the requester reads them as a decision, not as an engine word.
        $service = $this->archiveService();
        self::assertSame(
            WorkflowArchiveService::DECISION_REJECTED,
            $service->get((int)$first->getId(), 'requester')['outcome']['decision'],
        );
        self::assertSame(
            WorkflowArchiveService::DECISION_WITHDRAWN,
            $service->get((int)$second->getId(), 'requester')['outcome']['decision'],
        );
    }

    public function testTheRecordIsBoundToTheDefinitionVersionItWasCreatedOn(): void {
        $engine = $this->engine();
        $view   = $this->runToCompletion($engine);
        self::assertSame(1, $this->archive->all()[0]->getDefinitionVersion());

        // The definition ships a version 2 afterwards. The record does not
        // move with it: an instance created on version 1 is a record of
        // version 1 for ever.
        $service = $this->archiveService($this->registryAt(2));
        $record  = $service->authoritativeRecord($this->archiveId(), 'requester');
        self::assertSame(1, $record['authoritative']['definitionVersion']);
        self::assertSame(2, $this->registryAt(2)->get('svc')?->getVersion());
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. The projections
    // ──────────────────────────────────────────────────────────────────────

    public function testBothProjectionsAreCreatedAndPointAtTheOneRecord(): void {
        $this->runToCompletion($this->engine());
        $archiveId   = $this->archiveId();
        $projections = $this->archive->projectionsOf($archiveId);

        self::assertSame(
            [WorkflowArchiveAudience::REQUESTING_TEAM, WorkflowArchiveAudience::SERVICE_TEAM],
            array_keys($projections),
        );
        self::assertSame(self::TEAM, $projections[WorkflowArchiveAudience::REQUESTING_TEAM]->getTeamId());
        self::assertSame(self::DESK, $projections[WorkflowArchiveAudience::SERVICE_TEAM]->getTeamId());
        foreach ($projections as $row) {
            self::assertSame($archiveId, $row->getArchiveId(), 'both readings are of the same record');
        }
    }

    public function testAWorkflowNoDeskHandledHasNoServiceProjection(): void {
        $engine = $this->engine();
        $view   = $engine->create('plain', self::TEAM, 'requester', ['reason' => 'because']);
        $engine->completeStep($view['id'], 'owner', 'approved');

        $archiveId   = $this->archiveId();
        $projections = $this->archive->projectionsOf($archiveId);
        self::assertSame([WorkflowArchiveAudience::REQUESTING_TEAM], array_keys($projections));
        self::assertSame('', $this->archive->all()[0]->getServiceTeamId());

        // Absent, not empty: asking for the service view of a request no
        // desk handled is a refusal, not a blank page.
        $this->expectException(AccessDeniedException::class);
        $this->archiveService()->get($archiveId, 'svcowner', WorkflowArchiveAudience::SERVICE_TEAM);
    }

    public function testTheProjectionsHoldNoWorkflowContentOfTheirOwn(): void {
        $this->runToCompletion($this->engine());
        $projections = $this->archive->projectionsOf($this->archiveId());

        // A projection is an index entry. Its one text column is a search
        // haystack built from the requester's own words — and an internal
        // note must not be findable through it.
        foreach ($projections as $row) {
            $haystack = (string)$row->getSearchText();
            self::assertStringContainsString('fixture svc', $haystack);
            self::assertStringNotContainsString('supplier quote', $haystack);
        }
        self::assertSame(
            $projections[WorkflowArchiveAudience::REQUESTING_TEAM]->getSearchText(),
            $projections[WorkflowArchiveAudience::SERVICE_TEAM]->getSearchText(),
            'the desk searches the same words the requester does',
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. What the requesting team reads
    // ──────────────────────────────────────────────────────────────────────

    public function testTheRequestingTeamProjectionShowsWhatTheRequesterIsOwed(): void {
        $engine = $this->engine();
        $this->runToCompletion($engine);
        $view = $this->archiveService()->get($this->archiveId(), 'requester');

        self::assertSame(WorkflowArchiveAudience::REQUESTING_TEAM, $view['audience']);
        self::assertSame(WorkflowArchiveService::ROLE_REQUESTER, $view['viewerRole']);

        // Request summary, business outcome, approval, completion date,
        // reference, documents, follow-up — the seven the brief names.
        self::assertSame('Fixture svc', $view['request']['title']);
        self::assertSame('a new laptop', $view['request']['summary']);
        self::assertSame(self::TEAM, $view['request']['teamId']);
        self::assertSame(WorkflowEngine::OUTCOME_COMPLETED, $view['outcome']['outcome']);
        self::assertSame(WorkflowArchiveService::DECISION_APPROVED, $view['outcome']['decision']);
        self::assertGreaterThan(0, $view['outcome']['completedAt']);
        self::assertMatchesRegularExpression('/^WF-\d{4}-\d{6}$/', $view['reference']);
        self::assertSame(['handover.pdf'], array_column($view['documents'], 'fileName'));
        self::assertContains('closing_note', array_column($view['followUp'], 'kind'));

        // And the link back to what this is a reading of.
        self::assertSame($view['archiveId'], $view['link']['archiveId']);
        self::assertSame($this->archive->all()[0]->getInstanceId(), $view['link']['instanceId']);
        self::assertSame('svc', $view['link']['definitionKey']);
        self::assertSame(1, $view['link']['definitionVersion']);

        // There is no internal half on this reading at all.
        self::assertNull($view['internal']);
    }

    public function testATeamAdministratorReadsTheTeamsArchiveAndAPlainMemberDoesNot(): void {
        $this->runToCompletion($this->engine());
        $service   = $this->archiveService();
        $archiveId = $this->archiveId();

        self::assertSame(
            WorkflowArchiveService::ROLE_TEAM_ADMIN,
            $service->get($archiveId, 'teamadmin')['viewerRole'],
        );
        self::assertSame(
            WorkflowArchiveService::ROLE_NC_ADMIN,
            $service->get($archiveId, 'ncadmin')['viewerRole'],
        );

        // A member of the team who was never part of this request does not
        // read it: a team's archive is not a team's noticeboard.
        $this->expectException(AccessDeniedException::class);
        $service->get($archiveId, 'plainmember');
    }

    public function testSomebodyOutsideEverythingReadsNothing(): void {
        $this->runToCompletion($this->engine());
        $this->expectException(AccessDeniedException::class);
        $this->archiveService()->get($this->archiveId(), 'outsider');
    }

    public function testAnUnknownAudienceIsRefusedRatherThanGuessed(): void {
        $this->runToCompletion($this->engine());
        $this->expectException(ValidationException::class);
        $this->archiveService()->get($this->archiveId(), 'requester', 'everybody');
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. What the Service Team reads
    // ──────────────────────────────────────────────────────────────────────

    public function testTheServiceProjectionAddsTheInternalHalf(): void {
        $this->runToCompletion($this->engine());
        $view = $this->archiveService()->get($this->archiveId(), 'agent1', WorkflowArchiveAudience::SERVICE_TEAM);

        self::assertSame(WorkflowArchiveService::ROLE_SERVICE_AGENT, $view['viewerRole']);
        // Everything the requester reads, unchanged …
        self::assertSame('Fixture svc', $view['request']['title']);
        self::assertSame(WorkflowArchiveService::DECISION_APPROVED, $view['outcome']['decision']);

        // … and then the desk's own half, all six parts of it.
        $internal = $view['internal'];
        self::assertSame(self::DESK, $internal['serviceTeamId']);
        self::assertContains('agent1', $internal['assignedAgents']);
        self::assertSame(['submit', 'handle', 'confirm'], array_column($internal['processingSteps'], 'key'));
        self::assertSame(['supplier quote is high'], array_column($internal['internalNotes'], 'note'));
        self::assertSame([], $internal['escalations'], 'nothing was escalated in this case');
        self::assertContains(WorkflowEventType::STEP_CLAIMED, array_column($internal['technicalActions'], 'kind'));
        self::assertSame('agent1', $internal['operationalOutcome']['handledBy']);
        self::assertSame(1, $internal['operationalOutcome']['claims']);
        self::assertIsInt($internal['operationalOutcome']['totalSeconds']);

        // The desk sees both documents; the requester saw one.
        self::assertSame(
            ['handover.pdf', 'supplier-quote.pdf'],
            array_column($internal['documents'], 'fileName'),
        );
    }

    public function testTheServiceOwnerIsNamedAsSuchAndAnOutsiderIsRefused(): void {
        $this->runToCompletion($this->engine());
        $service = $this->archiveService();

        self::assertSame(
            WorkflowArchiveService::ROLE_SERVICE_OWNER,
            $service->get($this->archiveId(), 'svcowner', WorkflowArchiveAudience::SERVICE_TEAM)['viewerRole'],
        );

        // Administering the server is not working the desk — the same
        // boundary a live internal note has (v4.10.20).
        $this->expectException(AccessDeniedException::class);
        $service->get($this->archiveId(), 'ncadmin', WorkflowArchiveAudience::SERVICE_TEAM);
    }

    public function testAnEscalationIsRecordedForTheDesk(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);
        $engine->claimStep($view['id'], 'agent1');
        $engine->block($view['id'], 'agent1', 'waiting on the supplier');
        $engine->unblock($view['id'], 'agent1');
        $engine->completeStep($view['id'], 'agent1', 'done');
        $engine->completeStep($view['id'], 'requester', 'thanks');

        $internal = $this->archiveService()
            ->get($this->archiveId(), 'agent1', WorkflowArchiveAudience::SERVICE_TEAM)['internal'];
        self::assertSame(
            [WorkflowEventType::BLOCKED, WorkflowEventType::UNBLOCKED],
            array_column($internal['escalations'], 'kind'),
        );
        self::assertSame('waiting on the supplier', $internal['escalations'][0]['reason']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. Internal notes never leak — the rule of the whole phase
    // ──────────────────────────────────────────────────────────────────────

    public function testAnInternalNoteNeverReachesTheRequestingTeamProjection(): void {
        $this->runToCompletion($this->engine());
        $service   = $this->archiveService();
        $archiveId = $this->archiveId();

        foreach (['requester', 'teamadmin', 'ncadmin'] as $uid) {
            $view = $service->get($archiveId, $uid);
            $types = array_column($view['history'], 'type');
            self::assertNotContains(WorkflowEventType::INTERNAL_NOTE, $types, $uid . ' must not read the desk\'s notes');
            self::assertStringNotContainsString(
                'supplier quote is high',
                json_encode($view, JSON_UNESCAPED_UNICODE) ?: '',
                'the note must not appear anywhere in ' . $uid . '\'s projection',
            );
            self::assertNull($view['internal']);
        }
    }

    /**
     * The one that would be easy to get wrong: somebody who is *both* an
     * agent of the handling desk and a member of the requesting team.
     *
     * The filter belongs to the audience, not to the viewer. Asking for the
     * requesting-team reading gives the requesting-team reading — even to a
     * person who could have asked for the other one and been given the note.
     */
    public function testTheRequestingTeamReadingCarriesNoInternalNoteEvenForAnAgent(): void {
        // agent2 joins the requesting team as well.
        $this->resolver->levels[self::TEAM]['agent2'] = 1;
        $this->runToCompletion($this->engine());
        $service   = $this->archiveService();
        $archiveId = $this->archiveId();

        $asTeam = $service->get($archiveId, 'agent2', WorkflowArchiveAudience::REQUESTING_TEAM);
        self::assertNotContains(WorkflowEventType::INTERNAL_NOTE, array_column($asTeam['history'], 'type'));
        self::assertNull($asTeam['internal']);
        self::assertSame(['handover.pdf'], array_column($asTeam['documents'], 'fileName'));

        // The same person, asking as the desk, does read it.
        $asDesk = $service->get($archiveId, 'agent2', WorkflowArchiveAudience::SERVICE_TEAM);
        self::assertContains(WorkflowEventType::INTERNAL_NOTE, array_column($asDesk['history'], 'type'));
        self::assertSame(['supplier quote is high'], array_column($asDesk['internal']['internalNotes'], 'note'));
    }

    public function testAnInternalDocumentIsNotEvenMentionedToTheRequestingTeam(): void {
        $this->runToCompletion($this->engine());
        $view = $this->archiveService()->get($this->archiveId(), 'requester');

        self::assertSame([WorkflowAttachmentVisibility::REQUESTER], array_unique(array_column($view['documents'], 'visibility')));
        // Not even as "a document you may not open": the event that recorded
        // it is internal too, so its arrival is invisible as well.
        self::assertStringNotContainsString('supplier-quote.pdf', json_encode($view) ?: '');
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. The unlicensed ending is phase 4's, untouched
    // ──────────────────────────────────────────────────────────────────────

    public function testAnUnlicensedCompletionArchivesNothingAndPurgesAsBefore(): void {
        $this->tier->set(WorkflowLicenceTier::BASIC);
        $engine = $this->engine();
        $view   = $engine->create('plain', self::TEAM, 'requester', ['reason' => 'because']);
        $after  = $engine->completeStep($view['id'], 'owner', 'approved');

        self::assertTrue($after['purged'], 'phase 4: the ending is the removal');
        self::assertArrayNotHasKey('archive', $after);
        self::assertSame([], $this->archive->all(), 'nothing is recorded on an unlicensed instance');
        self::assertSame([], $this->archive->projections->rows);
        // And the workflow really is gone, exactly as phase 4 leaves it.
        self::assertSame([], $this->instances->rows);
        self::assertSame([], $this->steps->rows);
        self::assertSame([], $this->events->rows);
    }

    public function testAnUnlicensedInstanceCannotReadAnArchiveAtAll(): void {
        $this->runToCompletion($this->engine());
        $archiveId = $this->archiveId();

        $this->tier->set(WorkflowLicenceTier::BASIC);
        $service = $this->archiveService();
        try {
            $service->get($archiveId, 'requester');
            self::fail('the archive is licensed');
        } catch (LicenseGateException) {
            // expected
        }
        $this->expectException(LicenseGateException::class);
        $service->search('requester');
    }

    /**
     * The case the two halves meet in: a licence that lapses *while* the
     * workflow runs. The ending is then the unlicensed one, and the purge
     * must take the record with it rather than leave a row pointing at
     * nothing.
     */
    public function testALicenceThatLapsesMidFlightLeavesNoRecordBehind(): void {
        $engine = $this->engine();
        $view   = $this->open($engine);
        $engine->claimStep($view['id'], 'agent1');

        $this->tier->set(WorkflowLicenceTier::BASIC);
        $engine->completeStep($view['id'], 'agent1', 'done');
        $after = $engine->completeStep($view['id'], 'requester', 'thanks');

        self::assertTrue($after['purged']);
        self::assertSame([], $this->archive->all());
        self::assertSame([], $this->archive->projections->rows);
        self::assertSame([], $this->archive->attachments->rows, 'the documents go with the workflow');
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. Permission changes after completion
    // ──────────────────────────────────────────────────────────────────────

    public function testLeavingTheTeamClosesItsArchiveEvenForTheRequester(): void {
        $this->runToCompletion($this->engine());
        $service   = $this->archiveService();
        $archiveId = $this->archiveId();
        self::assertSame(WorkflowArchiveService::ROLE_REQUESTER, $service->get($archiveId, 'requester')['viewerRole']);

        // The requester leaves the team. Nothing on the record changes and
        // no pass runs: the read asks the live roles, and the answer moves.
        unset($this->resolver->levels[self::TEAM]['requester']);

        self::assertSame([], $service->search('requester')['results']);
        $this->expectException(AccessDeniedException::class);
        $service->get($archiveId, 'requester');
    }

    public function testComingOffTheDeskClosesTheServiceProjection(): void {
        $this->runToCompletion($this->engine());
        $service   = $this->archiveService();
        $archiveId = $this->archiveId();
        self::assertSame(
            WorkflowArchiveService::ROLE_SERVICE_AGENT,
            $service->get($archiveId, 'agent1', WorkflowArchiveAudience::SERVICE_TEAM)['viewerRole'],
        );

        // agent1 is taken off the desk — after having handled this very
        // request, and while still named all over its internal half.
        $this->deskAgents = ['svcowner', 'agent2'];

        $this->expectException(AccessDeniedException::class);
        $service->get($archiveId, 'agent1', WorkflowArchiveAudience::SERVICE_TEAM);
    }

    public function testBecomingATeamAdministratorOpensTheTeamsArchive(): void {
        $this->runToCompletion($this->engine());
        $service   = $this->archiveService();
        $archiveId = $this->archiveId();

        try {
            $service->get($archiveId, 'plainmember');
            self::fail('a plain member reads nothing');
        } catch (AccessDeniedException) {
            // expected
        }

        $this->resolver->levels[self::TEAM]['plainmember'] = WorkflowArchiveService::TEAM_ARCHIVE_LEVEL;
        self::assertSame(
            WorkflowArchiveService::ROLE_TEAM_ADMIN,
            $service->get($archiveId, 'plainmember')['viewerRole'],
        );
    }

    /**
     * A team that no longer exists at all. The resolver answers 0 for every
     * level, so the archive closes for its own members rather than throwing
     * — the deleted-membership case at its extreme.
     */
    public function testADeletedTeamClosesItsArchiveWithoutBreaking(): void {
        $this->runToCompletion($this->engine());
        $service   = $this->archiveService();
        $archiveId = $this->archiveId();

        unset($this->resolver->levels[self::TEAM]);

        self::assertSame([], $service->search('requester')['results']);
        self::assertSame([], $service->search('teamadmin')['results']);
        // The desk's own reading is unaffected: it is not the requesting
        // team's membership that grants it.
        self::assertSame(
            WorkflowArchiveService::ROLE_SERVICE_AGENT,
            $service->get($archiveId, 'agent1', WorkflowArchiveAudience::SERVICE_TEAM)['viewerRole'],
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Search and filtering
    // ──────────────────────────────────────────────────────────────────────

    public function testSearchReturnsOnlyWhatTheViewerMayRead(): void {
        $engine = $this->engine();
        $this->runToCompletion($engine);
        $second = $this->open($engine);
        $engine->claimStep($second['id'], 'agent1');
        $engine->rejectStep($second['id'], 'agent1', 'no');

        $service = $this->archiveService();

        $asRequester = $service->search('requester');
        self::assertCount(2, $asRequester['results']);
        self::assertSame(
            [WorkflowArchiveAudience::REQUESTING_TEAM, WorkflowArchiveAudience::REQUESTING_TEAM],
            array_column($asRequester['results'], 'audience'),
        );

        $asAgent = $service->search('agent1');
        self::assertCount(2, $asAgent['results']);
        self::assertSame(
            [WorkflowArchiveAudience::SERVICE_TEAM, WorkflowArchiveAudience::SERVICE_TEAM],
            array_column($asAgent['results'], 'audience'),
        );

        self::assertSame([], $service->search('outsider')['results'], 'an empty scope finds nothing, not everything');
        self::assertSame([], $service->search('plainmember')['results'], 'in the team, not on the request');
    }

    public function testFiltersNarrowByOutcomeReferenceTextAndDate(): void {
        $engine = $this->engine();
        $this->runToCompletion($engine);
        $second = $this->open($engine);
        $engine->claimStep($second['id'], 'agent1');
        $engine->rejectStep($second['id'], 'agent1', 'no');

        $service  = $this->archiveService();
        $rejected = $this->archive->archives->findByInstance($second['id']);

        self::assertCount(1, $service->search('requester', ['outcome' => WorkflowEngine::OUTCOME_REJECTED])['results']);
        self::assertCount(2, $service->search('requester', ['definitionKey' => 'svc'])['results']);
        self::assertSame([], $service->search('requester', ['definitionKey' => 'nothing'])['results']);

        // The reference, pasted in as people quote it.
        $byReference = $service->search('requester', ['q' => $rejected->getRefNumber()])['results'];
        self::assertCount(1, $byReference);
        self::assertSame($rejected->getRefNumber(), $byReference[0]['reference']);

        // Free text over the request's own words, case-insensitively.
        self::assertCount(2, $service->search('requester', ['q' => 'FIXTURE'])['results']);
        self::assertSame([], $service->search('requester', ['q' => 'supplier quote'])['results']);

        // And the completion date.
        self::assertCount(2, $service->search('requester', ['from' => 1, 'to' => $this->now + 1000])['results']);
        self::assertSame([], $service->search('requester', ['from' => $this->now + 1000])['results']);
    }

    public function testSearchCanBeNarrowedToOneAudienceAndRefusesAnInventedOne(): void {
        $this->runToCompletion($this->engine());
        $service = $this->archiveService();

        self::assertCount(1, $service->search('agent1', ['audience' => WorkflowArchiveAudience::SERVICE_TEAM])['results']);
        self::assertSame([], $service->search('agent1', ['audience' => WorkflowArchiveAudience::REQUESTING_TEAM])['results']);

        $this->expectException(ValidationException::class);
        $service->search('requester', ['audience' => 'everybody']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // The authoritative record and its seal
    // ──────────────────────────────────────────────────────────────────────

    public function testTheRecordLinkVerifiesTheSealedHistory(): void {
        $this->runToCompletion($this->engine());
        $service = $this->archiveService();
        $record  = $service->authoritativeRecord($this->archiveId(), 'requester');

        self::assertSame($this->archive->all()[0]->getInstanceId(), $record['authoritative']['instanceId']);
        self::assertTrue($record['integrity']['sealed']);
        self::assertTrue($record['integrity']['verified']);
        self::assertSame(count($this->events->findByInstance($record['authoritative']['instanceId'])), $record['integrity']['eventCount']);
        // The `archived` event is inside the seal: the log is sealed
        // complete, including the fact that it was sealed.
        self::assertContains(WorkflowEventType::ARCHIVED, array_column($record['history'], 'type'));
    }

    public function testTheRecordLinkIsNotAWayAroundTheAudienceFilter(): void {
        $this->runToCompletion($this->engine());
        $service = $this->archiveService();

        $asRequester = $service->authoritativeRecord($this->archiveId(), 'requester');
        self::assertNotContains(WorkflowEventType::INTERNAL_NOTE, array_column($asRequester['history'], 'type'));

        $asAgent = $service->authoritativeRecord($this->archiveId(), 'agent1', WorkflowArchiveAudience::SERVICE_TEAM);
        self::assertContains(WorkflowEventType::INTERNAL_NOTE, array_column($asAgent['history'], 'type'));
    }

    public function testTamperingWithTheSealedHistoryIsDetected(): void {
        $this->runToCompletion($this->engine());
        $record = $this->archive->all()[0];
        self::assertTrue($this->archiveService()->verifySeal($record));

        // Rewrite one event's payload, as an edit straight into the table
        // would. Every field is in the chain, so the digest moves.
        foreach ($this->events->rows as $event) {
            if ($event->getEventType() === WorkflowEventType::STEP_COMPLETED) {
                $event->setPayload(['note' => 'something else entirely']);
                break;
            }
        }
        self::assertFalse($this->archiveService()->verifySeal($record));
    }

    public function testRemovingAnEventFromTheSealedHistoryIsDetected(): void {
        $this->runToCompletion($this->engine());
        $record = $this->archive->all()[0];

        foreach ($this->events->rows as $id => $event) {
            if ($event->getEventType() === WorkflowEventType::INTERNAL_NOTE) {
                unset($this->events->rows[$id]);
                break;
            }
        }
        self::assertFalse($this->archiveService()->verifySeal($record), 'the count and the chain both move');
    }

    public function testAnEndedWorkflowTakesNoFurtherEvents(): void {
        $engine = $this->engine();
        $view   = $this->runToCompletion($engine);
        $sealed = count($this->events->findByInstance($view['id']));

        foreach ([
            static fn () => $engine->addInternalNote($view['id'], 'agent1', 'one more thought'),
            static fn () => $engine->completeStep($view['id'], 'requester', 'again'),
            static fn () => $engine->cancel($view['id'], 'requester', 'undo'),
        ] as $write) {
            try {
                $write();
                self::fail('an ended workflow accepts no write');
            } catch (\Throwable) {
                // expected — the engine refuses every transition on an
                // instance that is not open.
            }
        }
        self::assertCount($sealed, $this->events->findByInstance($view['id']));
        self::assertTrue($this->archiveService()->verifySeal($this->archive->all()[0]));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Retention metadata
    // ──────────────────────────────────────────────────────────────────────

    public function testARecordKeepsIndefinitelyByDefaultAndSaysSo(): void {
        $this->runToCompletion($this->engine());
        $record    = $this->archive->all()[0];
        $retention = $this->archiveService()->get($this->archiveId(), 'requester')['retention'];

        self::assertSame(0, $record->getRetentionUntil());
        self::assertSame('keep', $record->getRetentionPolicy());
        self::assertFalse($retention['legalHold']);
        self::assertFalse($retention['enforced'], 'this phase writes the metadata and deletes nothing on it');
        self::assertGreaterThan(0, $retention['archivedAt']);
    }

    public function testAConfiguredWindowIsWrittenOntoTheRecord(): void {
        $this->archive->config->retentionDays = 30;
        $this->runToCompletion($this->engine());
        $record = $this->archive->all()[0];

        self::assertSame($record->getCompletedAt() + 30 * 86400, $record->getRetentionUntil());
        self::assertSame('days:30', $record->getRetentionPolicy());
        // Still nothing acts on it: the expired-record query exists and
        // finds nothing, because nothing has expired and nothing prunes.
        self::assertSame([], $this->archive->archives->findExpired($this->now));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /** Open a service request: `submit` auto-completes, `handle` is with the desk. */
    private function open(WorkflowEngine $engine): array {
        return $engine->create('svc', self::TEAM, 'requester', ['reason' => 'a new laptop']);
    }

    /**
     * The whole reference flow: the requester asks, an agent takes it,
     * writes a note for the desk, attaches one document for each audience,
     * answers, and the requester confirms.
     */
    private function runToCompletion(WorkflowEngine $engine): array {
        $view = $this->open($engine);
        $engine->claimStep($view['id'], 'agent1');
        $engine->addInternalNote($view['id'], 'agent1', 'supplier quote is high');
        $this->attach($view['id'], 'agent1', 11, 'handover.pdf', WorkflowAttachmentVisibility::REQUESTER);
        $this->attach($view['id'], 'agent1', 12, 'supplier-quote.pdf', WorkflowAttachmentVisibility::INTERNAL);
        $engine->completeStep($view['id'], 'agent1', 'ordered, see the handover note');
        return $engine->completeStep($view['id'], 'requester', 'received, thanks');
    }

    /**
     * A document on the workflow, written straight to the table.
     *
     * `WorkflowAttachmentService` owns the rules about *who may attach
     * what*, and `WorkflowAttachmentTest` owns those cases. Here the
     * question is only what the archive does with a document once it
     * exists, so the row is placed rather than negotiated.
     */
    private function attach(int $instanceId, string $uid, int $fileId, string $name, string $visibility): void {
        $row = new \OCA\TeamHub\Db\WorkflowAttachment();
        $row->setInstanceId($instanceId);
        $row->setFileId($fileId);
        $row->setFileName($name);
        $row->setVisibility($visibility);
        $row->setStepKey('handle');
        $row->setAddedBy($uid);
        $row->setAddedAt(++$this->now);
        $this->archive->attachments->insert($row);
    }

    private function archiveId(): int {
        $rows = $this->archive->all();
        return (int)end($rows)->getId();
    }

    /** The two definitions: one a desk answers, one nobody does. */
    private function registryAt(int $version): WorkflowDefinitionRegistry {
        $svc = new FixtureDefinition('svc', $version, [
            new WorkflowStepDefinition('submit',  'Request submitted',       WorkflowActor::initiator(), true),
            new WorkflowStepDefinition('handle',  'Service team handles it', WorkflowActor::serviceAgentPlaceholder()),
            new WorkflowStepDefinition('confirm', 'Requester confirms',      WorkflowActor::initiator()),
        ], null, WorkflowDefinitionRegistry::CONCURRENCY_UNBOUNDED);
        $svc->resolvedActor = WorkflowActor::serviceAgent(self::DESK);

        $plain = new FixtureDefinition('plain', 1, [
            new WorkflowStepDefinition('submit', 'Request submitted', WorkflowActor::initiator(), true),
            new WorkflowStepDefinition('decide', 'Owner decides',     WorkflowActor::teamOwner()),
        ], null, WorkflowDefinitionRegistry::CONCURRENCY_UNBOUNDED);

        $registry = new WorkflowDefinitionRegistry();
        $registry->register($svc);
        $registry->register($plain);
        return $registry;
    }

    private function archiveService(?WorkflowDefinitionRegistry $registry = null): WorkflowArchiveService {
        $time = $this->timeFactory();
        return $this->archive->service(
            $this->instances,
            $this->steps,
            $this->participants,
            new WorkflowEventService($this->events, $time),
            $this->resolver,
            $registry ?? $this->registry,
            $this->tier,
            $this->serviceTeams,
            $this->createMock(AuditService::class),
            $time,
            $this->l10n(),
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * A ServiceTeamService over the mutable roster, so a test can take
     * somebody off the desk after the workflow has already been archived.
     */
    private function serviceTeamsMock(): ServiceTeamService {
        // v4.10.23 — the row carries the team and whether the desk is open,
        // and nothing about people: who owns and who works the service are
        // team roles, which is what `isServiceOwner` below stands in for.
        $row = new ServiceTeam();
        $row->setTeamId(self::DESK);
        $row->setActive(1);

        $mock = $this->createMock(ServiceTeamService::class);
        $mock->method('isEligibleAgent')->willReturnCallback(
            fn (string $uid, string $id): bool => $id === self::DESK && in_array($uid, $this->deskAgents, true),
        );
        $mock->method('isServiceOwner')->willReturnCallback(
            fn (string $uid, string $id): bool => $id === self::DESK && $uid === 'svcowner'
                && in_array($uid, $this->deskAgents, true),
        );
        $mock->method('isActiveServiceTeam')->willReturnCallback(
            static fn (string $id): bool => $id === self::DESK,
        );
        $mock->method('get')->willReturnCallback(
            static fn (string $id): ?ServiceTeam => $id === self::DESK ? $row : null,
        );
        $mock->method('serviceTeamForDefinition')->willReturn(self::DESK);
        $mock->method('eligibleAgents')->willReturnCallback(
            fn (string $id): array => $id === self::DESK ? $this->deskAgents : [],
        );
        $mock->method('serviceTeamsForAgent')->willReturnCallback(
            fn (string $uid): array => in_array($uid, $this->deskAgents, true) ? [self::DESK] : [],
        );
        $mock->method('requireLicence')->willReturnCallback(function (): void {
            if ($this->tier->tier() !== WorkflowLicenceTier::FULL) {
                throw new LicenseGateException('unlicensed', 'Service Teams require an active TeamHub licence.');
            }
        });
        return $mock;
    }

    private function timeFactory(): ITimeFactory {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => ++$this->now);
        $time->method('getDateTime')->willReturn(new \DateTime());
        return $time;
    }

    private function l10n(): IL10N {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
        $l->method('n')->willReturnCallback(static fn (string $one, string $many, int $count, array $p = []): string => $count === 1 ? $one : $many);
        return $l;
    }

    private function engine(): WorkflowEngine {
        $time  = $this->timeFactory();
        $l     = $this->l10n();
        $audit = $this->createMock(AuditService::class);

        $db = $this->createMock(IDBConnection::class);
        $db->method('beginTransaction')->willReturnCallback(static function (): void {});
        $db->method('commit')->willReturnCallback(static function (): void {});
        $db->method('rollBack')->willReturnCallback(static function (): void {});

        $notifier = $this->createMock(WorkflowNotificationService::class);

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
            $this->archive->service(
                $this->instances,
                $this->steps,
                $this->participants,
                new WorkflowEventService($this->events, $time),
                $this->resolver,
                $this->registry,
                $this->tier,
                $this->serviceTeams,
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
}
