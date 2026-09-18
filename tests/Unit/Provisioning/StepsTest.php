<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Provisioning;

use OCA\TeamHub\Db\ResourceLinkMapper;
use OCA\TeamHub\Db\TeamAppResource;
use OCA\TeamHub\Db\TeamAppResourceMapper;
use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\OpenProject\OpenProjectClient;
use OCA\TeamHub\Service\OpenProject\OpenProjectProjectService;
use OCA\TeamHub\Service\OpenProject\OpenProjectProvisioningService;
use OCA\TeamHub\Service\OpenProject\ProjectAlreadyLinkedException;
use OCA\TeamHub\Service\OpenProject\TeamOpenProjectLinkService;
use OCA\TeamHub\Service\PolicyService;
use OCA\TeamHub\Service\Provisioning\Blueprint;
use OCA\TeamHub\Service\Provisioning\ProvisioningContext;
use OCA\TeamHub\Service\Provisioning\Step\OpenProjectProjectStep;
use OCA\TeamHub\Service\Provisioning\Step\ProjectFolderStep;
use OCA\TeamHub\Service\Provisioning\Step\TalkStep;
use OCA\TeamHub\Service\Provisioning\Step\TeamStep;
use OCA\TeamHub\Service\Provisioning\StepResult;
use OCA\TeamHub\Service\ResourceService;
use OCA\TeamHub\Service\TeamExpiryService;
use OCA\TeamHub\Service\TeamService;
use OCA\TeamHub\Service\TeamTypeService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The steps that make things (v4.9.6): each one's idempotency, its failure
 * shape, and its rollback policy — the team-or-nothing rule, the project
 * that is never deleted, the folder that is linked and never made, the
 * resource that is adopted rather than made twice.
 */
class StepsTest extends TestCase {

    /** @var list<array> ledger rows written */
    private array $ledger = [];

    private function ctx(array $request = [], ?string $teamId = null, array $steps = [], string $mode = 'create', ?Blueprint $bp = null): ProvisioningContext {
        $request += [
            'name' => 'Apollo', 'description' => 'About', 'visibility' => 'private', 'profileKey' => '', 'preselectConfig' => 32,
            'endDate' => '', 'ownerUid' => '', 'openProject' => ['projectId' => 77, 'templateId' => 5, 'parentId' => 0, 'identifier' => 'apollo'],
            'components' => ['apps' => ['talk', 'files', 'calendar'], 'modules' => ['messages']], 'members' => [],
        ];
        $op = ['id' => 1, 'request' => $request, 'mode' => $mode, 'templateKey' => 'openproject', 'createdBy' => 'alice', 'teamId' => $teamId];
        return new ProvisioningContext($op, $steps, $bp ?? Blueprint::defaultsForOpenProject(), 'alice', $teamId);
    }

    /** @return ResourceLinkMapper&MockObject */
    private function ledger(): ResourceLinkMapper {
        $this->ledger = [];
        $m = $this->createMock(ResourceLinkMapper::class);
        $m->method('upsert')->willReturnCallback(function (...$args): array {
            $this->ledger[] = $args;
            return ['id' => count($this->ledger)];
        });
        return $m;
    }

    private static function project(int $id = 77, bool $linkable = true): array {
        return ['id' => $id, 'identifier' => 'apollo', 'name' => 'Apollo', 'active' => true, 'public' => false, 'status' => null,
            'statusExplanation' => null, 'description' => null, 'createdAt' => null, 'updatedAt' => null,
            'canCreateWorkPackage' => true, 'canEditProject' => $linkable, 'linkable' => $linkable];
    }

    // ── OpenProjectProjectStep ─────────────────────────────────────────

    private function projectStep(OpenProjectProvisioningService $op, ?OpenProjectProjectService $projects = null): OpenProjectProjectStep {
        $projects ??= $this->createMock(OpenProjectProjectService::class);
        $client = $this->createMock(OpenProjectClient::class);
        $client->method('projectUrl')->willReturnCallback(fn (string $ref) => 'https://op.example.test/projects/' . $ref);
        return new OpenProjectProjectStep($op, $projects, $client);
    }

    public function testLinkModeReadsAndChecksTheProjectAndMakesNothing(): void {
        $op = $this->createMock(OpenProjectProvisioningService::class);
        $op->expects($this->never())->method('copyProject');
        $op->expects($this->never())->method('createProject');
        $projects = $this->createMock(OpenProjectProjectService::class);
        $projects->method('getProject')->willReturn(self::project());

        $result = $this->projectStep($op, $projects)->run($this->ctx(mode: 'link'));
        $this->assertSame(StepResult::COMPLETED, $result->status);
        $this->assertSame('77', $result->externalId);
        $this->assertSame('linked', $result->detail['mode']);
        $this->assertSame('https://op.example.test/projects/apollo', $result->detail['url']);
    }

    public function testLinkModeRefusesAProjectTheCreatorDoesNotAdminister(): void {
        $projects = $this->createMock(OpenProjectProjectService::class);
        $projects->method('getProject')->willReturn(self::project(77, false));
        $result = $this->projectStep($this->createMock(OpenProjectProvisioningService::class), $projects)->run($this->ctx(mode: 'link'));
        $this->assertSame(StepResult::FAILED, $result->status);
        $this->assertSame(OpenProjectException::PERMISSION_DENIED, $result->errorCode);
        $this->assertFalse($result->retrySafe);
    }

    public function testCreateModeCopiesTheTemplateAndWaitsForTheJob(): void {
        $op = $this->createMock(OpenProjectProvisioningService::class);
        $op->method('findProjectByIdentifier')->willReturn(null);
        $op->expects($this->once())->method('copyProject')
            ->with('alice', 5, 'Apollo', 'apollo', 'About', false, null, $this->anything())
            ->willReturn(['jobId' => 'job-9', 'status' => 'in_queue', 'message' => null, 'projectId' => null]);

        $result = $this->projectStep($op)->run($this->ctx());
        $this->assertSame(StepResult::RUNNING, $result->status);
        $this->assertSame('job-9', $result->externalRef);
        $this->assertSame('job-9', $result->detail['jobId']);
    }

    public function testARetryPollsTheStoredJobInsteadOfSubmittingAgain(): void {
        $op = $this->createMock(OpenProjectProvisioningService::class);
        $op->expects($this->never())->method('copyProject');
        $op->method('jobStatus')->with('alice', 'job-9')->willReturn(['jobId' => 'job-9', 'status' => 'success', 'message' => null, 'projectId' => 78]);
        $projects = $this->createMock(OpenProjectProjectService::class);
        $projects->method('getProject')->with('alice', 78)->willReturn(self::project(78));

        $ctx = $this->ctx(steps: ['openproject_project' => ['externalRef' => 'job-9', 'detail' => ['jobId' => 'job-9']]]);
        $result = $this->projectStep($op, $projects)->run($ctx);
        $this->assertSame(StepResult::COMPLETED, $result->status);
        $this->assertSame('78', $result->externalId);
        $this->assertSame('created', $result->detail['mode']);
        $this->assertSame(78, $ctx->projectId());
    }

    public function testAFailedJobIsRetrySafeAndForgetsTheJob(): void {
        $op = $this->createMock(OpenProjectProvisioningService::class);
        $op->method('jobStatus')->willReturn(['jobId' => 'job-9', 'status' => 'failure', 'message' => 'Identifier taken', 'projectId' => null]);
        $ctx = $this->ctx(steps: ['openproject_project' => ['externalRef' => 'job-9', 'detail' => ['jobId' => 'job-9']]]);
        $result = $this->projectStep($op)->run($ctx);
        $this->assertSame(StepResult::FAILED, $result->status);
        $this->assertSame(OpenProjectException::JOB_FAILED, $result->errorCode);
        $this->assertSame('Identifier taken', $result->errorMessage);
        $this->assertNull($result->detail['jobId']);
    }

    public function testAProjectAlreadyUnderOurIdentifierIsAdoptedNotMadeAgain(): void {
        $op = $this->createMock(OpenProjectProvisioningService::class);
        $op->method('findProjectByIdentifier')->with('alice', 'apollo')->willReturn(self::project(79));
        $op->expects($this->never())->method('copyProject');
        $result = $this->projectStep($op)->run($this->ctx());
        $this->assertSame(StepResult::COMPLETED, $result->status);
        $this->assertTrue($result->detail['adopted']);
        $this->assertSame('79', $result->externalId);
    }

    public function testCreateWithoutATemplateCreatesDirectly(): void {
        $op = $this->createMock(OpenProjectProvisioningService::class);
        $op->method('findProjectByIdentifier')->willReturn(null);
        $op->expects($this->once())->method('createProject')->willReturn(self::project(80));
        $result = $this->projectStep($op)->run($this->ctx(['openProject' => ['templateId' => 0, 'identifier' => 'apollo', 'projectId' => 0, 'parentId' => 0]]));
        $this->assertSame('80', $result->externalId);
    }

    public function testValidationRefusalsAreNotRetrySafe(): void {
        $op = $this->createMock(OpenProjectProvisioningService::class);
        $op->method('findProjectByIdentifier')->willReturn(null);
        $op->method('copyProject')->willThrowException(new OpenProjectException(OpenProjectException::VALIDATION_FAILED, 'x', 422, null, 'Name is too long'));
        $result = $this->projectStep($op)->run($this->ctx());
        $this->assertSame(StepResult::FAILED, $result->status);
        $this->assertFalse($result->retrySafe);
        $this->assertSame('Name is too long', $result->errorMessage);
    }

    public function testTheProjectIsNeverDeletedOnRollback(): void {
        $step = $this->projectStep($this->createMock(OpenProjectProvisioningService::class));
        $ctx  = $this->ctx(steps: ['openproject_project' => ['detail' => ['projectId' => 77, 'mode' => 'created', 'identifier' => 'apollo']]]);
        $verdict = $step->rollback($ctx, ['detail' => ['mode' => 'created']], true);
        $this->assertSame(StepResult::ATTENTION, $verdict->status);
        $this->assertSame('manual_review', $verdict->errorCode);

        $verdict = $step->rollback($ctx, ['detail' => ['mode' => 'linked']], true);
        $this->assertSame(StepResult::SKIPPED, $verdict->status);
        $this->assertSame('linked_resource_kept', $verdict->detail['reason']);
    }

    // ── TeamStep ───────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $links scripted behaviour
     */
    private function teamStep(array &$calls, bool $linkFails = false, array $linkedTeams = [], int $creatorLevel = 9): TeamStep {
        $teams = $this->createMock(TeamService::class);
        $teams->method('createTeam')->willReturnCallback(function (string $name) use (&$calls) {
            $calls[] = 'createTeam:' . $name;
            return ['id' => 'team-new', 'name' => $name];
        });
        $teams->method('updateTeamDescription')->willReturnCallback(function () use (&$calls): void { $calls[] = 'description'; });
        $teams->method('updateTeamConfig')->willReturnCallback(function () use (&$calls): void { $calls[] = 'config'; });
        $teams->method('deleteTeam')->willReturnCallback(function (string $id) use (&$calls): void { $calls[] = 'deleteTeam:' . $id; });
        $policies = $this->createMock(PolicyService::class);
        $policies->method('assignAtCreation')->willReturnCallback(function () use (&$calls): array { $calls[] = 'policy'; return []; });
        $links = $this->createMock(TeamOpenProjectLinkService::class);
        $links->method('assertLinkableForNewTeam')->willReturnCallback(function () use (&$calls): array { $calls[] = 'assertLinkable'; return self::project(); });
        $links->method('linkNewTeam')->willReturnCallback(function (string $teamId, int $projectId) use (&$calls, $linkFails): array {
            $calls[] = 'link:' . $teamId . ':' . $projectId;
            if ($linkFails) {
                // linkNewTeam() deletes the team itself before rethrowing.
                $calls[] = 'deleteTeam:' . $teamId;
                throw new ProjectAlreadyLinkedException([['teamId' => 'other', 'name' => 'Other']]);
            }
            return ['projectId' => $projectId];
        });
        $links->method('linkedTeamsByProject')->willReturn($linkedTeams);
        $members = $this->createMock(MemberService::class);
        $members->method('getMemberLevelFromDb')->willReturn($creatorLevel);
        $expiry = $this->createMock(TeamExpiryService::class);
        $expiry->method('setAtCreation')->willReturnCallback(function () use (&$calls): void { $calls[] = 'expiry'; });

        return new TeamStep($teams, $this->createMock(TeamTypeService::class), $policies, $links, $expiry, $members, $this->ledger(), $this->createMock(IDBConnection::class), $this->createMock(LoggerInterface::class));
    }

    public function testTheTeamIsCreatedWithItsLinkInPhaseOnesOrderAndRecordedAtOnce(): void {
        $calls = [];
        $step  = $this->teamStep($calls);
        $recorded = null;
        $ctx = $this->ctx(['endDate' => '2027-01-01'], steps: ['openproject_project' => ['detail' => ['projectId' => 77, 'mode' => 'created', 'url' => 'u', 'identifier' => 'apollo', 'name' => 'Apollo']]]);
        $ctx->onTeamCreated = function (string $id) use (&$recorded): void { $recorded = $id; };

        $result = $step->run($ctx);
        $this->assertSame(StepResult::COMPLETED, $result->status);
        $this->assertSame('team-new', $result->externalId);
        $this->assertSame('team-new', $recorded, 'the operation row knows the team before the link is made');
        $this->assertSame(['assertLinkable', 'createTeam:Apollo', 'policy', 'link:team-new:77', 'description', 'config', 'expiry'], $calls);
        $this->assertSame(['team-new', 'openproject', 'project', '77', 'created'], array_slice($this->ledger[0], 0, 5));
    }

    public function testARefusedLinkLeavesNoTeamAndFailsTheStep(): void {
        $calls = [];
        $step  = $this->teamStep($calls, linkFails: true);
        $ctx   = $this->ctx(steps: ['openproject_project' => ['detail' => ['projectId' => 77, 'mode' => 'linked']]]);
        $result = $step->run($ctx);

        $this->assertSame(StepResult::FAILED, $result->status);
        $this->assertSame('project_already_linked', $result->errorCode);
        $this->assertFalse($result->retrySafe);
        $this->assertNull($ctx->teamId, 'team-or-nothing: the context forgets the deleted team');
        $this->assertContains('deleteTeam:team-new', $calls);
    }

    public function testARetryAdoptsTheTeamAlreadyLinkedToOurProjectIfTheCreatorOwnsIt(): void {
        $calls = [];
        $step  = $this->teamStep($calls, linkedTeams: [77 => [['teamId' => 'team-old', 'name' => 'Apollo']]]);
        $ctx   = $this->ctx(steps: ['openproject_project' => ['detail' => ['projectId' => 77, 'mode' => 'created']]]);
        $result = $step->run($ctx);

        $this->assertSame(StepResult::COMPLETED, $result->status);
        $this->assertSame('team-old', $ctx->teamId);
        $this->assertTrue($result->detail['adopted']);
        $this->assertSame([], $calls, 'nothing was created');
    }

    public function testATeamLinkedToOurProjectButOwnedByAnotherIsNotAdopted(): void {
        $calls = [];
        $step  = $this->teamStep($calls, linkedTeams: [77 => [['teamId' => 'team-old', 'name' => null]]], creatorLevel: 1);
        $ctx   = $this->ctx(steps: ['openproject_project' => ['detail' => ['projectId' => 77, 'mode' => 'created']]]);
        $step->run($ctx);
        $this->assertContains('assertLinkable', $calls, 'goes on to the normal path, where Phase 1 refuses the taken project');
    }

    public function testAnExistingTeamIdMeansTheStepIsDone(): void {
        $calls = [];
        $step  = $this->teamStep($calls);
        $result = $step->run($this->ctx(teamId: 'team-x', steps: ['openproject_project' => ['detail' => ['projectId' => 77]]]));
        $this->assertSame(StepResult::COMPLETED, $result->status);
        $this->assertSame([], $calls);
    }

    public function testRollbackDeletesTheTeamThroughTheCascade(): void {
        $calls = [];
        $step  = $this->teamStep($calls);
        $verdict = $step->rollback($this->ctx(teamId: 'team-x'), [], true);
        $this->assertSame(StepResult::COMPLETED, $verdict->status);
        $this->assertSame(['deleteTeam:team-x'], $calls);
    }

    // ── AbstractResourceStep via TalkStep ──────────────────────────────

    private function resourceRow(string $resourceId, string $origin): TeamAppResource {
        $row = new TeamAppResource();
        $row->setResourceId($resourceId);
        $row->setOrigin($origin);
        $row->setAppId('talk');
        return $row;
    }

    public function testAResourceIsAdoptedFromTheRegistryRatherThanMadeTwice(): void {
        $resources = $this->createMock(ResourceService::class);
        $resources->expects($this->never())->method('createTeamResources');
        $registry = $this->createMock(TeamAppResourceMapper::class);
        $registry->method('findActiveByTeamAndApp')->willReturn([$this->resourceRow('tok-1', 'teamhub_create')]);

        $step = new TalkStep($resources, $registry, $this->ledger(), $this->createMock(LoggerInterface::class));
        $result = $step->run($this->ctx(teamId: 'team-x'));
        $this->assertSame(StepResult::COMPLETED, $result->status);
        $this->assertSame('tok-1', $result->externalId);
        $this->assertTrue($result->detail['adopted']);
        $this->assertSame(['team-x', 'talk', 'conversation', 'tok-1', 'created'], array_slice($this->ledger[0], 0, 5));
    }

    public function testAResourceIsCreatedThroughTheSharedPathAndRecorded(): void {
        $resources = $this->createMock(ResourceService::class);
        $resources->expects($this->once())->method('createTeamResources')->with('team-x', ['talk'], 'Apollo')->willReturn(['talk' => ['token' => 'tok-2']]);
        $registry = $this->createMock(TeamAppResourceMapper::class);
        $registry->method('findActiveByTeamAndApp')->willReturnOnConsecutiveCalls([], [$this->resourceRow('tok-2', 'teamhub_create')]);

        $step = new TalkStep($resources, $registry, $this->ledger(), $this->createMock(LoggerInterface::class));
        $result = $step->run($this->ctx(teamId: 'team-x'));
        $this->assertSame(StepResult::COMPLETED, $result->status);
        $this->assertSame('tok-2', $result->externalId);
    }

    public function testACreationErrorIsAFailureAndAPolicyRefusalASkip(): void {
        $registry = $this->createMock(TeamAppResourceMapper::class);
        $registry->method('findActiveByTeamAndApp')->willReturn([]);
        $resources = $this->createMock(ResourceService::class);
        $resources->method('createTeamResources')->willReturn(['talk' => ['error' => 'Talk is down']]);
        $step = new TalkStep($resources, $registry, $this->ledger(), $this->createMock(LoggerInterface::class));
        $result = $step->run($this->ctx(teamId: 'team-x'));
        $this->assertSame(StepResult::FAILED, $result->status);
        $this->assertSame('Talk is down', $result->errorMessage);

        $resources = $this->createMock(ResourceService::class);
        $resources->method('createTeamResources')->willReturn([]);
        $step = new TalkStep($resources, $registry, $this->ledger(), $this->createMock(LoggerInterface::class));
        $this->assertSame(StepResult::SKIPPED, $step->run($this->ctx(teamId: 'team-x'))->status);
    }

    public function testRollbackKeepsALinkedResourceAndAsksBeforeRemovingACreatedOne(): void {
        $step = new TalkStep($this->createMock(ResourceService::class), $this->createMock(TeamAppResourceMapper::class), $this->ledger(), $this->createMock(LoggerInterface::class));
        $ctx  = $this->ctx(teamId: 'team-x');
        $kept = $step->rollback($ctx, ['externalId' => 'tok', 'detail' => ['adopted' => true, 'origin' => 'teamhub_connect']], true);
        $this->assertSame('linked_resource_kept', $kept->detail['reason']);

        $ask = $step->rollback($ctx, ['externalId' => 'tok', 'detail' => ['origin' => 'teamhub_create']], false);
        $this->assertSame('confirm_required', $ask->errorCode);

        $ok = $step->rollback($ctx, ['externalId' => 'tok', 'detail' => ['origin' => 'teamhub_create']], true);
        $this->assertSame(StepResult::COMPLETED, $ok->status);
    }

    public function testTalkStepAppliesOnlyWhenTheBlueprintCreatesAConversation(): void {
        $step = new TalkStep($this->createMock(ResourceService::class), $this->createMock(TeamAppResourceMapper::class), $this->ledger(), $this->createMock(LoggerInterface::class));
        $this->assertTrue($step->applies($this->ctx()));
        $none = Blueprint::fromArray(['apps' => ['required' => ['talk']], 'talk' => ['behavior' => 'none']]);
        $this->assertFalse($step->applies($this->ctx(bp: $none)));
        $this->assertFalse($step->applies($this->ctx(['components' => ['apps' => ['files'], 'modules' => []]])));
    }

    // ── ProjectFolderStep ──────────────────────────────────────────────

    private function folderStep(array $storages, ?ResourceService $resources = null, ?TeamAppResourceMapper $registry = null): ProjectFolderStep {
        $op = $this->createMock(OpenProjectProvisioningService::class);
        $op->method('projectStorages')->willReturn($storages);
        return new ProjectFolderStep(
            $resources ?? $this->createMock(ResourceService::class),
            $registry ?? $this->createMock(TeamAppResourceMapper::class),
            $this->ledger(),
            $this->createMock(LoggerInterface::class),
            $op,
        );
    }

    private static function managed(): array {
        return ['id' => 2, 'storageId' => 1, 'storageName' => 'Nextcloud', 'projectFolderMode' => 'automatic', 'projectFolderFileId' => 4711, 'openUrl' => 'https://op.example.test/open'];
    }

    public function testOpenProjectBehaviourLinksTheManagedFolderAndMakesNoTeamFolder(): void {
        $resources = $this->createMock(ResourceService::class);
        $resources->expects($this->never())->method('createTeamResources');
        $bp   = Blueprint::fromArray(['apps' => ['required' => ['files']], 'openproject' => ['required' => true], 'folder' => ['behavior' => 'openproject']]);
        $step = $this->folderStep([self::managed()], $resources);
        $ctx  = $this->ctx(teamId: 'team-x', steps: ['openproject_project' => ['detail' => ['projectId' => 77]]], bp: $bp);

        $result = $step->run($ctx);
        $this->assertSame(StepResult::COMPLETED, $result->status);
        $this->assertSame('4711', $result->externalId);
        $this->assertSame('linked', $result->detail['mode']);
        $this->assertSame(['team-x', 'files', 'openproject_folder', '4711', 'linked'], array_slice($this->ledger[0], 0, 5));
    }

    public function testOpenProjectBehaviourWithoutAManagedFolderNeedsAttentionInsteadOfMakingACompetingOne(): void {
        $resources = $this->createMock(ResourceService::class);
        $resources->expects($this->never())->method('createTeamResources');
        $bp   = Blueprint::fromArray(['apps' => ['required' => ['files']], 'openproject' => ['required' => true], 'folder' => ['behavior' => 'openproject']]);
        $step = $this->folderStep([['id' => 3, 'storageId' => 1, 'storageName' => 'x', 'projectFolderMode' => 'inactive', 'projectFolderFileId' => null, 'openUrl' => null]], $resources);
        $result = $step->run($this->ctx(teamId: 'team-x', steps: ['openproject_project' => ['detail' => ['projectId' => 77]]], bp: $bp));
        $this->assertSame(StepResult::ATTENTION, $result->status);
        $this->assertSame('no_managed_folder', $result->errorCode);
        $this->assertTrue($result->retrySafe);
    }

    public function testBothBehaviourMakesTheTeamFolderAndLinksTheManagedOneWhenPresent(): void {
        $row = new TeamAppResource();
        $row->setResourceId('gf:12');
        $row->setOrigin('teamhub_create');
        $row->setAppId('files');
        $registry = $this->createMock(TeamAppResourceMapper::class);
        $registry->method('findActiveByTeamAndApp')->willReturnOnConsecutiveCalls([], [$row]);
        $resources = $this->createMock(ResourceService::class);
        $resources->expects($this->once())->method('createTeamResources')->with('team-x', ['files'], 'Apollo')->willReturn(['files' => ['folder_id' => 12]]);

        $step   = $this->folderStep([self::managed()], $resources, $registry);
        $result = $step->run($this->ctx(teamId: 'team-x', steps: ['openproject_project' => ['detail' => ['projectId' => 77]]]));
        $this->assertSame(StepResult::COMPLETED, $result->status);
        $this->assertSame('gf:12', $result->externalId);
        $this->assertSame(4711, $result->detail['openProjectFolder']['fileId']);
        $this->assertCount(2, $this->ledger, 'the managed folder and the team folder');
    }
}
