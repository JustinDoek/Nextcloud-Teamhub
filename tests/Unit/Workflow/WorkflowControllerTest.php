<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Controller\WorkflowController;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\LicenseGateException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Exception\WorkflowRateLimitException;
use OCA\TeamHub\Exception\WorkflowTransitionException;
use OCA\TeamHub\Service\Workflow\WorkflowEngine;
use OCA\TeamHub\Service\Workflow\WorkflowLicenceTier;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The WorkflowHub API (phase 2, v4.10.14): the caller is the session user
 * and nothing else, every engine refusal maps to the status code the
 * client can act on, and the response shapes are what the docs say.
 */
class WorkflowControllerTest extends TestCase {

    private WorkflowEngine&MockObject $engine;

    private FakeLicenceTier $tier;

    private function controller(?string $uid = 'alice', string $tier = WorkflowLicenceTier::FULL): WorkflowController {
        $this->engine = $this->createMock(WorkflowEngine::class);
        $session      = $this->createMock(IUserSession::class);
        if ($uid !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $session->method('getUser')->willReturn($user);
        } else {
            $session->method('getUser')->willReturn(null);
        }
        $this->tier = new FakeLicenceTier($tier);
        return new WorkflowController('teamhub', $this->createMock(IRequest::class), $this->engine, $this->tier, $session, $this->createMock(LoggerInterface::class));
    }

    public function testEveryRouteNeedsASession(): void {
        $c = $this->controller(null);
        $this->engine->expects(self::never())->method(self::anything());
        foreach ([
            $c->index(), $c->definitions(), $c->show(1), $c->start('t1', 'team_request', []),
            $c->complete(1), $c->reject(1, 'x'), $c->requestInformation(1, 'x'),
            $c->provideInformation(1, 'x'), $c->cancel(1), $c->requestStatus(1), $c->message(1, 'x'),
        ] as $response) {
            self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        }
    }

    public function testTheListIsTheSessionUsersAndCarriesTheStatusFilter(): void {
        $c = $this->controller('alice');
        $this->engine->expects(self::once())->method('listForParticipant')
            ->with('alice', 'in_progress')
            ->willReturn([['id' => 7]]);
        $r = $c->index('in_progress');
        self::assertSame(Http::STATUS_OK, $r->getStatus());
        $data = $r->getData();
        self::assertSame([['id' => 7]], $data['workflows']);
        // Phase 4: every read says which tier answered and what it has.
        self::assertSame('full', $data['tier']);
        self::assertTrue($data['capabilities']['completed_history']);

        $this->engine = $this->createMock(WorkflowEngine::class);
        $c = $this->controller('bob');
        $this->engine->expects(self::once())->method('listForParticipant')->with('bob', null)->willReturn([]);
        self::assertSame([], $c->index()->getData()['workflows']);
    }

    public function testShowReturnsTheWorkflowWithItsHistory(): void {
        $c = $this->controller('alice');
        $this->engine->method('get')->with(3, 'alice')->willReturn(['id' => 3, 'status' => 'in_progress']);
        $this->engine->method('listEvents')->with(3, 'alice')->willReturn([['type' => 'created', 'actorUid' => 'bob']]);
        $this->engine->method('describePeople')->with(['bob'])->willReturn(['bob' => 'Bob']);
        $r = $c->show(3);
        self::assertSame(Http::STATUS_OK, $r->getStatus());
        $data = $r->getData();
        self::assertSame(['id' => 3, 'status' => 'in_progress', 'history' => [['type' => 'created', 'actorUid' => 'bob']], 'people' => ['bob' => 'Bob']], $data['workflow']);
        self::assertSame('full', $data['tier']);
    }

    public function testStartPassesTheSessionUserTheTeamAndThePayloadAndAnswers201(): void {
        $c = $this->controller('alice');
        $this->engine->expects(self::once())->method('create')
            ->with('team_request', 't1', 'alice', ['teamName' => 'Sales', 'reason' => 'r'])
            ->willReturn(['id' => 9]);
        $r = $c->start('t1', 'team_request', ['teamName' => 'Sales', 'reason' => 'r']);
        self::assertSame(Http::STATUS_CREATED, $r->getStatus());
        self::assertSame(['workflow' => ['id' => 9]], $r->getData());
    }

    public function testActionsActForTheSessionUserOnly(): void {
        $c = $this->controller('carol');
        $this->engine->expects(self::once())->method('completeStep')->with(4, 'carol', 'done')->willReturn(['id' => 4]);
        $this->engine->expects(self::once())->method('rejectStep')->with(4, 'carol', 'no')->willReturn(['id' => 4]);
        $this->engine->expects(self::once())->method('requestInformation')->with(4, 'carol', 'q')->willReturn(['id' => 4]);
        $this->engine->expects(self::once())->method('provideInformation')->with(4, 'carol', 'a')->willReturn(['id' => 4]);
        $this->engine->expects(self::once())->method('cancel')->with(4, 'carol', 'bye')->willReturn(['id' => 4]);
        $this->engine->expects(self::once())->method('requestStatus')->with(4, 'carol', 'news?')->willReturn(['id' => 4]);
        $this->engine->expects(self::once())->method('postMessage')->with(4, 'carol', 'hi', [7])->willReturn(['id' => 4]);

        foreach ([
            $c->complete(4, 'done'), $c->reject(4, 'no'), $c->requestInformation(4, 'q'),
            $c->provideInformation(4, 'a'), $c->cancel(4, 'bye'), $c->requestStatus(4, 'news?'),
            $c->message(4, 'hi', [7]),
        ] as $r) {
            self::assertSame(Http::STATUS_OK, $r->getStatus());
            self::assertSame(['workflow' => ['id' => 4]], $r->getData());
        }
    }

    public function testEngineRefusalsBecomeTheRightStatusCodes(): void {
        $cases = [
            [new AccessDeniedException('not yours'),          Http::STATUS_FORBIDDEN],
            [new NotFoundException('gone'),                   Http::STATUS_NOT_FOUND],
            [new ValidationException('bad'),                  Http::STATUS_BAD_REQUEST],
            [new \InvalidArgumentException('bad actor'),      Http::STATUS_BAD_REQUEST],
            [new WorkflowTransitionException('stale'),        Http::STATUS_CONFLICT],
            [new WorkflowRateLimitException('later', 3600),   Http::STATUS_TOO_MANY_REQUESTS],
            [new \RuntimeException('database exploded'),      Http::STATUS_INTERNAL_SERVER_ERROR],
        ];
        foreach ($cases as [$exception, $status]) {
            $c = $this->controller('alice');
            $this->engine->method('completeStep')->willThrowException($exception);
            $r = $c->complete(1);
            self::assertSame($status, $r->getStatus(), get_class($exception));
            $data = $r->getData();
            if ($status === Http::STATUS_INTERNAL_SERVER_ERROR) {
                // Nothing internal leaks: a generic message and a correlation id.
                self::assertSame('Failed to complete the step', $data['error']);
                self::assertArrayHasKey('ref', $data);
            } else {
                self::assertSame($exception->getMessage(), $data['error']);
            }
            if ($status === Http::STATUS_CONFLICT) {
                self::assertTrue($data['conflict']);
            }
            if ($status === Http::STATUS_TOO_MANY_REQUESTS) {
                self::assertSame(3600, $data['retryAfter']);
                self::assertSame('3600', $r->getHeaders()['Retry-After']);
            }
        }
    }

    public function testStartMapsRefusalsToo(): void {
        $c = $this->controller('alice');
        $this->engine->method('create')->willThrowException(new WorkflowTransitionException('already open'));
        self::assertSame(Http::STATUS_CONFLICT, $c->start('t1', 'teamspace_quota', [])->getStatus());

        $c = $this->controller('alice');
        $this->engine->method('create')->willThrowException(new NotFoundException('no such definition'));
        self::assertSame(Http::STATUS_NOT_FOUND, $c->start('t1', 'nope', [])->getStatus());
    }

    public function testDefinitionsAreListedForAnySignedInUser(): void {
        $c = $this->controller('alice');
        $this->engine->method('describeDefinitions')->willReturn([['key' => 'team_request']]);
        $data = $c->definitions()->getData();
        self::assertSame([['key' => 'team_request']], $data['definitions']);
        self::assertSame('full', $data['tier']);
    }

    public function testTheWriteRoutesCarryNoCsrfExemptionAndTheStatusRequestIsRateLimited(): void {
        $rc = new \ReflectionClass(WorkflowController::class);
        foreach (['index', 'definitions', 'show', 'start', 'complete', 'reject', 'requestInformation', 'provideInformation', 'cancel', 'requestStatus', 'message'] as $method) {
            $attrs = array_map(static fn (\ReflectionAttribute $a): string => $a->getName(), $rc->getMethod($method)->getAttributes());
            self::assertContains('OCP\AppFramework\Http\Attribute\NoAdminRequired', $attrs, $method);
            self::assertNotContains('OCP\AppFramework\Http\Attribute\NoCSRFRequired', $attrs, $method);
            self::assertNotContains('OCP\AppFramework\Http\Attribute\PublicPage', $attrs, $method);
        }
        foreach (['requestStatus', 'message'] as $method) {
            $attrs = array_map(static fn (\ReflectionAttribute $a): string => $a->getName(), $rc->getMethod($method)->getAttributes());
            self::assertContains('OCP\AppFramework\Http\Attribute\UserRateLimit', $attrs, $method);
        }
    }

    // ── The licence at the edge (phase 4, v4.10.16) ───────────────────────

    public function testALicenceGateFromTheEngineBecomesA403TheClientCanRender(): void {
        $c = $this->controller('alice', WorkflowLicenceTier::BASIC);
        $this->engine->method('requestStatus')
            ->willThrowException(new LicenseGateException('unlicensed', 'Status update requests require an active TeamHub licence.'));
        $r = $c->requestStatus(1, 'news?');
        self::assertSame(Http::STATUS_FORBIDDEN, $r->getStatus());
        $data = $r->getData();
        self::assertTrue($data['licenseGate'], 'the frontend distinguishes this from "not yours"');
        self::assertSame('unlicensed', $data['enforcementLevel']);
        self::assertSame('Status update requests require an active TeamHub licence.', $data['error']);
    }

    public function testStartingALicensedDefinitionUnlicensedIsTheSame403(): void {
        $c = $this->controller('alice', WorkflowLicenceTier::BASIC);
        $this->engine->method('create')
            ->willThrowException(new LicenseGateException('soft-lock', 'This workflow requires an active TeamHub licence.'));
        $r = $c->start('t1', 'licensed_only', []);
        self::assertSame(Http::STATUS_FORBIDDEN, $r->getStatus());
        self::assertTrue($r->getData()['licenseGate']);
        self::assertSame('soft-lock', $r->getData()['enforcementLevel']);
    }

    public function testNoRouteRefusesAnActionOnARunningWorkflowBecauseOfTheLicence(): void {
        // The rule the lifecycle document states: an expired licence must
        // never block completing a workflow that exists. The controller has
        // no gate of its own, so an unlicensed caller reaches the engine for
        // every one of these.
        $c = $this->controller('alice', WorkflowLicenceTier::BASIC);
        $this->engine->expects(self::once())->method('completeStep')->willReturn(['id' => 1]);
        $this->engine->expects(self::once())->method('rejectStep')->willReturn(['id' => 1]);
        $this->engine->expects(self::once())->method('provideInformation')->willReturn(['id' => 1]);
        $this->engine->expects(self::once())->method('requestInformation')->willReturn(['id' => 1]);
        $this->engine->expects(self::once())->method('cancel')->willReturn(['id' => 1]);
        foreach ([
            $c->complete(1), $c->reject(1, 'no'), $c->provideInformation(1, 'a'),
            $c->requestInformation(1, 'q'), $c->cancel(1),
        ] as $r) {
            self::assertSame(Http::STATUS_OK, $r->getStatus());
        }
    }

    public function testAnUnlicensedReadReportsTheBasicTierAndItsCapabilities(): void {
        $c = $this->controller('alice', WorkflowLicenceTier::BASIC);
        $this->engine->method('listForParticipant')->willReturn([]);
        $data = $c->index()->getData();
        self::assertSame('basic', $data['tier']);
        self::assertTrue($data['capabilities']['view_action_required']);
        self::assertTrue($data['capabilities']['view_timeline']);
        foreach ([
            'view_current_step', 'view_step_progress', 'request_status_update', 'completed_history',
            'manage_definitions', 'service_teams', 'analytics', 'audit_export',
            'reopen_completed', 'archive_results',
        ] as $licensed) {
            self::assertFalse($data['capabilities'][$licensed], $licensed);
        }
    }
}
