<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Provisioning;

use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Service\MaintenanceService;
use OCA\TeamHub\Service\OpenProject\OpenProjectProvisioningService;
use OCA\TeamHub\Service\Provisioning\Blueprint;
use OCA\TeamHub\Service\Provisioning\MembershipPlanService;
use OCA\TeamHub\Service\Provisioning\ProvisioningContext;
use OCA\TeamHub\Service\Provisioning\Step\HandoverStep;
use OCA\TeamHub\Service\Provisioning\StepResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The handover step (v4.9.6): the TeamHub handover, and — Justin's finding
 * on the first real run — the creator's own OpenProject membership on a
 * project this operation created, removed when they leave the team.
 */
class HandoverStepTest extends TestCase {

    /** @var list<int> membership ids deleted */
    private array $deleted = [];
    /** @var array<string,mixed> what applyCreationRoles() answers */
    private array $handover = ['status' => 'transferred', 'creatorLeft' => true];
    private bool $handoverThrows = false;
    private ?string $deleteFails = null;

    private function step(): HandoverStep {
        $this->deleted = [];
        $maintenance = $this->createMock(MaintenanceService::class);
        $maintenance->method('applyCreationRoles')->willReturnCallback(function () {
            if ($this->handoverThrows) {
                throw new \Exception('Only the team owner can set roles while creating a team', 403);
            }
            return ['levels' => [], 'owner' => ['uid' => 'lieke'] + $this->handover];
        });
        $op = $this->createMock(OpenProjectProvisioningService::class);
        $op->method('listMemberships')->willReturn([
            ['id' => 41, 'principalId' => 5, 'principalType' => 'user', 'principalName' => 'Inge', 'roles' => [['id' => 3, 'name' => 'Project admin']]],
            ['id' => 42, 'principalId' => 6, 'principalType' => 'user', 'principalName' => 'Lieke', 'roles' => [['id' => 3, 'name' => 'Project admin']]],
        ]);
        $op->method('deleteMembership')->willReturnCallback(function (string $uid, int $id): void {
            if ($this->deleteFails !== null) {
                throw new OpenProjectException($this->deleteFails, 'x');
            }
            $this->deleted[] = $id;
        });
        $plans = $this->createMock(MembershipPlanService::class);
        $plans->method('matchUser')->willReturn(['id' => 5, 'name' => 'Inge', 'type' => 'user', 'matchedBy' => 'connected']);
        return new HandoverStep($maintenance, $op, $plans, $this->createMock(LoggerInterface::class));
    }

    private function ctx(string $mode = 'create', array $steps = [], string $owner = 'lieke'): ProvisioningContext {
        $steps += ['openproject_project' => ['detail' => ['projectId' => 77, 'mode' => $mode === 'create' ? 'created' : 'linked']]];
        $op = ['id' => 1, 'request' => ['ownerUid' => $owner, 'members' => [['id' => 'lieke', 'type' => 'user', 'level' => 9]], 'openProject' => ['projectId' => 77]], 'mode' => $mode, 'templateKey' => 'openproject', 'createdBy' => 'inge', 'teamId' => 'team-1'];
        return new ProvisioningContext($op, $steps, Blueprint::defaultsForOpenProject(), 'inge', 'team-1');
    }

    public function testAppliesOnlyWithAnAppointedOwnerWhoIsNotTheCreator(): void {
        $step = $this->step();
        $this->assertTrue($step->applies($this->ctx()));
        $this->assertFalse($step->applies($this->ctx(owner: '')));
        $this->assertFalse($step->applies($this->ctx(owner: 'inge')));
    }

    public function testACreatorWhoLeavesIsRemovedFromTheProjectTheyCreated(): void {
        $result = $this->step()->run($this->ctx());
        $this->assertSame(StepResult::COMPLETED, $result->status);
        $this->assertSame([41], $this->deleted, 'the creator\'s membership, not the new owner\'s');
        $this->assertTrue($result->detail['creatorRemovedFromProject']);
        $this->assertTrue($result->detail['creatorLeft']);
    }

    public function testACreatorWhoStaysOnTheTeamKeepsTheirMembership(): void {
        $this->handover = ['status' => 'transferred', 'creatorLeft' => false];
        $result = $this->step()->run($this->ctx());
        $this->assertSame(StepResult::COMPLETED, $result->status);
        $this->assertSame([], $this->deleted);
        $this->assertArrayNotHasKey('creatorRemovedFromProject', $result->detail);
    }

    public function testAnExistingProjectIsNeverTouched(): void {
        $result = $this->step()->run($this->ctx(mode: 'link'));
        $this->assertSame(StepResult::COMPLETED, $result->status);
        $this->assertSame([], $this->deleted, 'their membership pre-existed');
    }

    public function testARemovalOpenProjectRefusesIsAttentionNotFailure(): void {
        $this->deleteFails = OpenProjectException::PERMISSION_DENIED;
        $result = $this->step()->run($this->ctx());
        $this->assertSame(StepResult::ATTENTION, $result->status);
        $this->assertSame('creator_still_in_project', $result->errorCode);
        $this->assertSame(OpenProjectException::PERMISSION_DENIED, $result->detail['error']);
    }

    public function testAFailedTeamHubHandoverIsAttentionAndTouchesNothingInOpenProject(): void {
        $this->handover = ['status' => 'failed', 'error' => 'circles'];
        $result = $this->step()->run($this->ctx());
        $this->assertSame(StepResult::ATTENTION, $result->status);
        $this->assertSame('handover_failed', $result->errorCode);
        $this->assertSame([], $this->deleted);
    }

    public function testARetryAfterTheHandoverHappenedStillRemovesTheCreator(): void {
        $this->handoverThrows = true;
        $ctx = $this->ctx(steps: ['handover' => ['detail' => ['owner' => ['status' => 'transferred', 'creatorLeft' => true]]]]);
        $result = $this->step()->run($ctx);
        $this->assertSame(StepResult::COMPLETED, $result->status);
        $this->assertTrue($result->detail['adopted']);
        $this->assertSame([41], $this->deleted);
    }
}
