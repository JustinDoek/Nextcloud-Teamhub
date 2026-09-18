<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\OpenProject;

use OCA\TeamHub\Controller\OpenProjectController;
use OCA\TeamHub\Db\TeamOpenProjectLink;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\MyWork\Provider\OpenProjectWorkProvider;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\OpenProject\ProjectAlreadyLinkedException;
use OCA\TeamHub\Service\OpenProject\OpenProjectActivityService;
use OCA\TeamHub\Service\OpenProject\OpenProjectCapabilityService;
use OCA\TeamHub\Service\OpenProject\OpenProjectMeetingMirrorService;
use OCA\TeamHub\Service\OpenProject\OpenProjectMeetingService;
use OCA\TeamHub\Service\OpenProject\OpenProjectMessages;
use OCA\TeamHub\Service\OpenProject\OpenProjectNewsService;
use OCA\TeamHub\Service\OpenProject\OpenProjectProjectService;
use OCA\TeamHub\Service\OpenProject\OpenProjectWorkPackageService;
use OCA\TeamHub\Service\OpenProject\TeamOpenProjectLinkService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * The HTTP edge: membership gates, the code → status mapping, and that no
 * failure is ever a 200.
 */
class OpenProjectControllerTest extends OpenProjectTestCase {

    /** @var MemberService&MockObject */
    private MemberService $members;
    /** @var OpenProjectCapabilityService&MockObject */
    private OpenProjectCapabilityService $caps;
    /** @var OpenProjectProjectService&MockObject */
    private OpenProjectProjectService $projects;
    /** @var OpenProjectWorkPackageService&MockObject */
    private OpenProjectWorkPackageService $work;
    /** @var OpenProjectActivityService&MockObject */
    private OpenProjectActivityService $activity;
    /** @var OpenProjectMeetingService&MockObject */
    private OpenProjectMeetingService $meetingsService;
    /** @var OpenProjectMeetingMirrorService&MockObject */
    private OpenProjectMeetingMirrorService $meetingMirror;
    /** @var OpenProjectNewsService&MockObject */
    private OpenProjectNewsService $newsService;
    /** @var OpenProjectWorkProvider&MockObject */
    private OpenProjectWorkProvider $workProvider;
    /** @var TeamOpenProjectLinkService&MockObject */
    private TeamOpenProjectLinkService $links;

    private function controller(bool $member = true, bool $admin = true, bool $canCreate = true): OpenProjectController {
        $this->members = $this->createMock(MemberService::class);
        $this->members->method('canCurrentUserCreateTeam')->willReturn($canCreate);
        if (!$member) {
            $this->members->method('requireMemberLevel')->willThrowException(new AccessDeniedException('You are not a member of this team'));
        }
        if (!$admin) {
            $this->members->method('requireAdminLevel')->willThrowException(new AccessDeniedException('Insufficient permissions. Admin or owner role required.'));
        }
        $this->caps     = $this->createMock(OpenProjectCapabilityService::class);
        $this->projects = $this->createMock(OpenProjectProjectService::class);
        $this->work     = $this->createMock(OpenProjectWorkPackageService::class);
        $this->links    = $this->createMock(TeamOpenProjectLinkService::class);
        $this->activity     = $this->createMock(OpenProjectActivityService::class);
        $this->meetingsService = $this->createMock(OpenProjectMeetingService::class);
        $this->meetingMirror   = $this->createMock(OpenProjectMeetingMirrorService::class);
        $this->newsService     = $this->createMock(OpenProjectNewsService::class);
        $this->workProvider = $this->createMock(OpenProjectWorkProvider::class);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn (string $s) => $s);

        return new OpenProjectController(
            'teamhub',
            $this->createMock(IRequest::class),
            $this->members,
            // v4.9.16 — the real module gate over the test case's knobs.
            $this->moduleService(),
            $this->caps,
            $this->projects,
            $this->work,
            $this->links,
            new OpenProjectMessages($l),
            $this->activity,
            $this->meetingsService,
            $this->meetingMirror,
            $this->newsService,
            $this->workProvider,
            $this->userSession('alice'),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function linkRow(): TeamOpenProjectLink {
        $row = new TeamOpenProjectLink();
        $row->setTeamId('team-a');
        $row->setProjectId(12);
        $row->setHost(self::HOST);
        return $row;
    }

    // ── Gates ──────────────────────────────────────────────────────────

    public function testNonMemberCannotReadTheOverview(): void {
        $c = $this->controller(member: false);
        $this->projects->expects($this->never())->method('overview');

        $r = $c->overview('team-a');

        $this->assertSame(Http::STATUS_FORBIDDEN, $r->getStatus());
    }

    public function testNonMemberCannotReadWorkOrTheLink(): void {
        $c = $this->controller(member: false);
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->work('team-a')->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->getLink('team-a')->getStatus());
    }

    public function testMemberWhoIsNotAdminCannotTest(): void {
        $c = $this->controller(admin: false);
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->testConnection('team-a')->getStatus());
    }

    /**
     * v4.9.16 — the module gate: every route but `capabilities` answers 403
     * with the code before membership or OpenProject is consulted, and the
     * unlicensed case carries the app-wide `licenseGate` marker.
     */
    public function testASwitchedOffModuleRefusesEveryRouteButCapabilities(): void {
        $this->withModuleOff();
        $this->withHost();
        $c = $this->controller();
        $this->projects->expects($this->never())->method('overview');
        $this->work->expects($this->never())->method('section');
        $this->links->expects($this->never())->method('getLink');

        foreach ([
            fn () => $c->overview('team-a'),
            fn () => $c->work('team-a'),
            fn () => $c->getLink('team-a'),
            fn () => $c->testConnection('team-a'),
            fn () => $c->searchProjects('demo'),
            fn () => $c->workPackageForm('team-a'),
            fn () => $c->createWorkPackage('team-a', 'Subject', 1),
            fn () => $c->meetings('team-a'),
            fn () => $c->attention('team-a'),
        ] as $call) {
            $r = $call();
            $this->assertSame(Http::STATUS_FORBIDDEN, $r->getStatus());
            $this->assertSame(OpenProjectException::MODULE_DISABLED, $r->getData()['code']);
            $this->assertArrayNotHasKey('licenseGate', $r->getData(), 'switched off is not a licence problem');
            $this->assertNotEmpty($r->getData()['administratorMessage']);
        }

        // The wizard learns the module is off from here, so it stays open.
        $this->caps->expects($this->once())->method('getCapabilities')->willReturn(['moduleAvailable' => false, 'errorCode' => OpenProjectException::MODULE_DISABLED]);
        $this->assertSame(Http::STATUS_OK, $c->capabilities()->getStatus());
    }

    public function testAnUnlicensedModuleIsALicenceGate(): void {
        $this->withUnlicensed();
        $this->withHost();
        $c = $this->controller();

        $r = $c->overview('team-a');

        $this->assertSame(Http::STATUS_FORBIDDEN, $r->getStatus());
        $this->assertSame(OpenProjectException::MODULE_UNLICENSED, $r->getData()['code']);
        $this->assertTrue($r->getData()['licenseGate']);
    }

    public function testTheControllerHasNoLinkWrite(): void {
        // v4.9.4 — the link is made by POST /teams and removed from
        // Maintenance; a saveLink here would be a second way to link.
        // (v4.9.15 added one member-level write, createWorkPackage — a
        // work package, never the link.)
        $this->assertFalse(method_exists(OpenProjectController::class, 'saveLink'));
        $this->assertFalse(method_exists(OpenProjectController::class, 'unlink'));
    }

    public function testSomebodyWhoCannotCreateTeamsCannotBrowseProjects(): void {
        $c = $this->controller(canCreate: false);
        $this->projects->expects($this->never())->method('search');
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->searchProjects('x')->getStatus());
    }

    public function testProjectSearchRunsAsTheUserWhoMayCreateTeams(): void {
        $c = $this->controller();
        $this->caps->method('requireReadable')->willReturn('alice');
        $this->projects->expects($this->once())->method('search')->with('alice', 'demo', 25)->willReturn([]);
        $this->links->method('linkedTeamsByProject')->willReturn([]);
        $r = $c->searchProjects('demo');
        $this->assertSame(Http::STATUS_OK, $r->getStatus());
        $this->assertSame([], $r->getData()['projects']);
    }

    public function testProjectSearchMarksTheProjectsAnotherTeamAlreadyLinks(): void {
        $c = $this->controller();
        $this->caps->method('requireReadable')->willReturn('alice');
        $this->projects->method('search')->willReturn([
            ['id' => 12, 'identifier' => 'free', 'name' => 'Free'],
            ['id' => 13, 'identifier' => 'taken', 'name' => 'Taken'],
        ]);
        $this->links->expects($this->once())->method('linkedTeamsByProject')->with([12, 13])
            ->willReturn([13 => [['teamId' => 'team-b', 'name' => 'Team B']]]);

        $projects = $c->searchProjects('')->getData()['projects'];

        $this->assertNull($projects[0]['linkedTeam']);
        $this->assertSame(['teamId' => 'team-b', 'name' => 'Team B'], $projects[1]['linkedTeam']);
    }

    // ── Status mapping ─────────────────────────────────────────────────

    /**
     * @dataProvider statusMapping
     */
    public function testOpenProjectFailuresMapToStatusAndCode(string $code, int $status): void {
        $c = $this->controller();
        $this->caps->method('requireReadable')->willReturn('alice');
        $this->links->method('requireLink')->willReturn($this->linkRow());
        $this->projects->method('overview')->willThrowException(new OpenProjectException($code, 'raw detail that must not leak'));

        $r    = $c->overview('team-a');
        $body = $r->getData();

        $this->assertSame($status, $r->getStatus());
        $this->assertSame($code, $body['code']);
        $this->assertNotEmpty($body['error']);
        $this->assertNotEmpty($body['administratorMessage']);
        $this->assertStringNotContainsString('raw detail', json_encode($body), 'the exception message is not the response');
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function statusMapping(): array {
        return [
            'not installed → 422'     => [OpenProjectException::INTEGRATION_NOT_INSTALLED, Http::STATUS_UNPROCESSABLE_ENTITY],
            'disabled → 422'          => [OpenProjectException::INTEGRATION_DISABLED, Http::STATUS_UNPROCESSABLE_ENTITY],
            'no host → 422'           => [OpenProjectException::HOST_NOT_CONFIGURED, Http::STATUS_UNPROCESSABLE_ENTITY],
            'incompatible → 422'      => [OpenProjectException::INTEGRATION_INCOMPATIBLE, Http::STATUS_UNPROCESSABLE_ENTITY],
            'not connected → 412'     => [OpenProjectException::USER_NOT_CONNECTED, Http::STATUS_PRECONDITION_FAILED],
            'auth failed → 412'       => [OpenProjectException::AUTH_FAILED, Http::STATUS_PRECONDITION_FAILED],
            'permission → 403'        => [OpenProjectException::PERMISSION_DENIED, Http::STATUS_FORBIDDEN],
            'not found → 404'         => [OpenProjectException::PROJECT_NOT_FOUND, Http::STATUS_NOT_FOUND],
            'stale → 409'             => [OpenProjectException::LINK_STALE, Http::STATUS_CONFLICT],
            'rate limited → 429'      => [OpenProjectException::RATE_LIMITED, Http::STATUS_TOO_MANY_REQUESTS],
            'unreachable → 502'       => [OpenProjectException::API_UNAVAILABLE, Http::STATUS_BAD_GATEWAY],
            'temporary → 502'         => [OpenProjectException::TEMPORARY_FAILURE, Http::STATUS_BAD_GATEWAY],
            'unsupported → 502'       => [OpenProjectException::UNSUPPORTED_RESPONSE, Http::STATUS_BAD_GATEWAY],
            // v4.9.15 — a refused write is the caller's input, not a gateway fault.
            'validation → 400'        => [OpenProjectException::VALIDATION_FAILED, Http::STATUS_BAD_REQUEST],
        ];
    }

    // ── Create work package (v4.9.15) ──────────────────────────────────

    public function testNonMemberCanNeitherLoadTheFormNorCreate(): void {
        $c = $this->controller(member: false);
        $this->work->expects($this->never())->method('createForm');
        $this->work->expects($this->never())->method('create');
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->workPackageForm('team-a')->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->createWorkPackage('team-a', 'x', 1)->getStatus());
    }

    public function testTheFormIsReadAsTheViewerForTheLinkedProject(): void {
        $c = $this->controller();
        $this->caps->method('requireReadable')->willReturn('alice');
        $this->links->method('requireLink')->willReturn($this->linkRow());
        $this->work->expects($this->once())->method('createForm')->with('alice', 12)
            ->willReturn(['types' => [['id' => 1, 'name' => 'Task']], 'defaultTypeId' => 1, 'assignees' => [], 'assigneesUnavailable' => false]);

        $r = $c->workPackageForm('team-a');

        $this->assertSame(Http::STATUS_OK, $r->getStatus());
        $this->assertSame(1, $r->getData()['defaultTypeId']);
    }

    public function testCreatePassesTheFormThroughAndAnswers201(): void {
        $c = $this->controller();
        $this->caps->method('requireReadable')->willReturn('alice');
        $this->links->method('requireLink')->willReturn($this->linkRow());
        $this->work->expects($this->once())->method('create')
            ->with('alice', 'team-a', 12, [
                'subject' => 'Plan', 'typeId' => 1, 'assigneeId' => 6, 'dueDate' => '2026-09-30', 'description' => 'Notes',
            ])
            ->willReturn(['id' => 41, 'subject' => 'Plan']);

        $r = $c->createWorkPackage('team-a', 'Plan', 1, 6, '2026-09-30', 'Notes');

        $this->assertSame(Http::STATUS_CREATED, $r->getStatus());
        $this->assertSame(41, $r->getData()['id']);
    }

    public function testCreateWithBadInputIsA400(): void {
        $c = $this->controller();
        $this->caps->method('requireReadable')->willReturn('alice');
        $this->links->method('requireLink')->willReturn($this->linkRow());
        $this->work->method('create')->willThrowException(new ValidationException('A type is required'));

        $r = $c->createWorkPackage('team-a', 'Plan', 0);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
        $this->assertSame('A type is required', $r->getData()['error']);
    }

    public function testCreateRefusedByOpenProjectShowsItsOwnSentence(): void {
        $c = $this->controller();
        $this->caps->method('requireReadable')->willReturn('alice');
        $this->links->method('requireLink')->willReturn($this->linkRow());
        $this->work->method('create')->willThrowException(new OpenProjectException(
            OpenProjectException::VALIDATION_FAILED, 'OpenProject answered 422 for work_packages', 422, null, "Subject can't be blank.",
        ));

        $r    = $c->createWorkPackage('team-a', '', 1);
        $body = $r->getData();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
        $this->assertSame(OpenProjectException::VALIDATION_FAILED, $body['code']);
        $this->assertSame("Subject can't be blank.", $body['error'], 'OpenProject\'s sentence, not the generic one');
        $this->assertNotEmpty($body['administratorMessage']);
    }

    public function testAProjectLinkedElsewhereIsA409WithTheTeam(): void {
        // The mapping lives in OpenProjectResponseTrait, which TeamController
        // uses for createTeam(); exercised here through the picker route.
        $c = $this->controller();
        $this->caps->method('requireReadable')->willReturn('alice');
        $this->projects->method('search')->willReturn([]);
        $this->links->method('linkedTeamsByProject')->willThrowException(new ProjectAlreadyLinkedException([['teamId' => 'team-b', 'name' => 'B']]));

        $r = $c->searchProjects('');

        $this->assertSame(Http::STATUS_CONFLICT, $r->getStatus());
        $this->assertSame('project_already_linked', $r->getData()['code']);
        $this->assertSame('team-b', $r->getData()['teams'][0]['teamId']);
    }

    public function testANonLinkableProjectIsA403(): void {
        $c = $this->controller();
        $this->caps->method('requireReadable')->willReturn('alice');
        $this->projects->method('search')->willThrowException(new AccessDeniedException('You must be an administrator of this project in OpenProject, or the project must be public'));
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->searchProjects('')->getStatus());
    }

    public function testAnUnexpectedExceptionIsA500WithAReferenceAndNoDetail(): void {
        $c = $this->controller();
        $this->caps->method('requireReadable')->willThrowException(new \RuntimeException('database exploded'));

        $r = $c->overview('team-a');

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $r->getStatus());
        $this->assertArrayHasKey('ref', $r->getData());
        $this->assertStringNotContainsString('exploded', json_encode($r->getData()));
    }

    // ── Happy paths ────────────────────────────────────────────────────

    public function testOverviewReturnsThePayloadAndRecordsAFreshRead(): void {
        $c = $this->controller();
        $this->caps->method('requireReadable')->willReturn('alice');
        $row = $this->linkRow();
        $this->links->method('requireLink')->willReturn($row);
        $payload = ['project' => ['id' => 12, 'name' => 'Demo'], 'counts' => [], 'fromCache' => false];
        $this->projects->method('overview')->with('alice', 'team-a', 12, self::HOST, false)->willReturn($payload);
        $this->links->expects($this->once())->method('recordSuccessfulRead')->with($row, $payload['project']);

        $r = $c->overview('team-a');

        $this->assertSame(Http::STATUS_OK, $r->getStatus());
        $this->assertSame('Demo', $r->getData()['project']['name']);
    }

    public function testACachedOverviewDoesNotTouchTheRow(): void {
        $c = $this->controller();
        $this->caps->method('requireReadable')->willReturn('alice');
        $this->links->method('requireLink')->willReturn($this->linkRow());
        $this->projects->method('overview')->willReturn(['project' => [], 'fromCache' => true]);
        $this->links->expects($this->never())->method('recordSuccessfulRead');

        $this->assertSame(Http::STATUS_OK, $c->overview('team-a', refresh: true)->getStatus());
    }

    public function testWorkPassesSectionAndPagingThrough(): void {
        $c = $this->controller();
        $this->caps->method('requireReadable')->willReturn('alice');
        $this->links->method('requireLink')->willReturn($this->linkRow());
        $this->work->expects($this->once())->method('section')
            ->with('alice', 'team-a', 12, self::HOST, 'overdue', 2, 5, true)
            ->willReturn(['section' => 'overdue', 'items' => []]);

        $r = $c->work('team-a', 'overdue', 2, 5, true);

        $this->assertSame(Http::STATUS_OK, $r->getStatus());
    }

    public function testCapabilitiesEndpointPassesProbeAndForce(): void {
        $c = $this->controller();
        $this->caps->expects($this->once())->method('getCapabilities')->with(true, true)->willReturn(['errorCode' => null]);
        $this->assertSame(Http::STATUS_OK, $c->capabilities(true, true)->getStatus());
    }

    // ── Phase 3 (v4.9.7): journal + attention ──────────────────────────

    public function testNonMemberCannotReadMeetingsOrTheAttentionBlock(): void {
        $c = $this->controller(member: false);
        $this->meetingsService->expects($this->never())->method('upcoming');
        $this->workProvider->expects($this->never())->method('attentionSummary');

        $this->assertSame(Http::STATUS_FORBIDDEN, $c->meetings('team-a')->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->attention('team-a')->getStatus());
    }

    public function testMeetingsPassTheLinkedProjectAndPagingThrough(): void {
        $c = $this->controller();
        $this->caps->method('requireReadable')->willReturn('alice');
        $this->links->method('requireLink')->willReturn($this->linkRow());
        $this->meetingsService->expects($this->once())->method('upcoming')
            ->with('alice', 'team-a', 12, self::HOST, 5, true)
            ->willReturn(['items' => [['id' => 3, 'title' => 'Sprint review']], 'total' => 1, 'retrievedAt' => 1, 'fromCache' => false]);

        $r = $c->meetings('team-a', 5, true);

        $this->assertSame(Http::STATUS_OK, $r->getStatus());
        $this->assertSame('Sprint review', $r->getData()['items'][0]['title']);
    }

    // ── v4.9.10: the one-way copy into the team calendar ───────────────

    public function testALiveMeetingsReadSyncsTheTeamCalendarAndReportsIt(): void {
        $c = $this->controller();
        $this->caps->method('requireReadable')->willReturn('alice');
        $this->links->method('requireLink')->willReturn($this->linkRow());
        $read = ['items' => [['id' => 3, 'title' => 'Sprint review']], 'cancelledIds' => [4], 'total' => 1, 'retrievedAt' => 1, 'fromCache' => false];
        $this->meetingsService->method('upcoming')->willReturn($read);
        $this->meetingMirror->expects($this->once())->method('syncForTeam')
            ->with('alice', 'team-a', ['host' => self::HOST, 'projectId' => 12], $read)
            ->willReturn(['state' => 'ok', 'created' => 1, 'updated' => 0, 'removed' => 1, 'skipped' => 0]);

        $r = $c->meetings('team-a');

        $this->assertSame(Http::STATUS_OK, $r->getStatus());
        $this->assertSame(['state' => 'ok', 'created' => 1, 'updated' => 0, 'removed' => 1, 'skipped' => 0], $r->getData()['sync']);
    }

    public function testACachedMeetingsReadDoesNotSync(): void {
        $c = $this->controller();
        $this->caps->method('requireReadable')->willReturn('alice');
        $this->links->method('requireLink')->willReturn($this->linkRow());
        $this->meetingsService->method('upcoming')
            ->willReturn(['items' => [], 'cancelledIds' => [], 'total' => 0, 'retrievedAt' => 1, 'fromCache' => true]);
        $this->meetingMirror->expects($this->never())->method('syncForTeam');

        $r = $c->meetings('team-a');
        $this->assertArrayNotHasKey('sync', $r->getData());
    }

    public function testASyncFailureLeavesTheRowsStanding(): void {
        $c = $this->controller();
        $this->caps->method('requireReadable')->willReturn('alice');
        $this->links->method('requireLink')->willReturn($this->linkRow());
        $this->meetingsService->method('upcoming')
            ->willReturn(['items' => [['id' => 3, 'title' => 'Sprint review']], 'cancelledIds' => [], 'total' => 1, 'retrievedAt' => 1, 'fromCache' => false]);
        $this->meetingMirror->method('syncForTeam')->willThrowException(new \RuntimeException('calendar gone'));

        $r = $c->meetings('team-a');

        $this->assertSame(Http::STATUS_OK, $r->getStatus());
        $this->assertSame('Sprint review', $r->getData()['items'][0]['title']);
        $this->assertSame('error', $r->getData()['sync']['state']);
    }

    public function testALiveOverviewReadMirrorsTheProjectsNews(): void {
        $c = $this->controller();
        $this->caps->method('requireReadable')->willReturn('alice');
        $this->links->method('requireLink')->willReturn($this->linkRow());
        $this->projects->method('overview')->willReturn(['project' => [], 'fromCache' => false]);
        $this->newsService->expects($this->once())->method('mirrorForTeam')->with('alice', 'team-a')->willReturn(1);

        $this->assertSame(Http::STATUS_OK, $c->overview('team-a')->getStatus());
    }

    public function testACachedOverviewReadDoesNotMirror(): void {
        $c = $this->controller();
        $this->caps->method('requireReadable')->willReturn('alice');
        $this->links->method('requireLink')->willReturn($this->linkRow());
        $this->projects->method('overview')->willReturn(['project' => [], 'fromCache' => true]);
        $this->newsService->expects($this->never())->method('mirrorForTeam');

        $this->assertSame(Http::STATUS_OK, $c->overview('team-a')->getStatus());
    }

    public function testAttentionSummarisesTheProvidersReadAndTheRecentActivity(): void {
        $c = $this->controller();
        $this->caps->method('requireReadable')->willReturn('alice');
        $this->links->method('requireLink')->willReturn($this->linkRow());
        $this->workProvider->expects($this->once())->method('attentionSummary')->with('alice', 'team-a')
            ->willReturn(['overdue' => 2, 'dueToday' => 1, 'dueThisWeek' => 3, 'updatedRecently' => 0, 'nextMilestone' => null, 'upcomingDays' => 7, 'warnings' => [], 'urls' => [], 'retrievedAt' => 1]);
        $this->activity->expects($this->once())->method('feed')
            ->with('alice', ['team-a'], $this->anything(), 0, 25)
            ->willReturn(['items' => [['id' => 'op:x:12:1'], ['id' => 'op:x:12:2']], 'status' => ['state' => 'ok'], 'projects' => []]);

        $r = $c->attention('team-a');

        $this->assertSame(Http::STATUS_OK, $r->getStatus());
        $data = $r->getData();
        $this->assertSame(2, $data['overdue']);
        $this->assertSame(['count' => 2, 'state' => 'ok'], $data['recentActivity']);
        $this->assertSame(12, $data['projectId']);
    }

    public function testAttentionSurvivesAFailingActivityRead(): void {
        $c = $this->controller();
        $this->caps->method('requireReadable')->willReturn('alice');
        $this->links->method('requireLink')->willReturn($this->linkRow());
        $this->workProvider->method('attentionSummary')
            ->willReturn(['overdue' => 0, 'dueToday' => 0, 'dueThisWeek' => 0, 'updatedRecently' => 0, 'nextMilestone' => null, 'upcomingDays' => 7, 'warnings' => [], 'urls' => [], 'retrievedAt' => 1]);
        $this->activity->method('feed')->willThrowException(new \RuntimeException('boom'));

        $r = $c->attention('team-a');

        $this->assertSame(Http::STATUS_OK, $r->getStatus());
        $this->assertSame('skipped', $r->getData()['recentActivity']['state']);
    }
}
