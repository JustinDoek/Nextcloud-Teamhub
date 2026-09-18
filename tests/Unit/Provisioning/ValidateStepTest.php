<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Provisioning;

use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\OpenProject\OpenProjectCapabilityService;
use OCA\TeamHub\Service\OpenProject\OpenProjectProvisioningService;
use OCA\TeamHub\Service\OpenProject\ProjectAlreadyLinkedException;
use OCA\TeamHub\Service\OpenProject\TeamOpenProjectLinkService;
use OCA\TeamHub\Service\Provisioning\Blueprint;
use OCA\TeamHub\Service\Provisioning\BlueprintService;
use OCA\TeamHub\Service\Provisioning\MembershipPlanService;
use OCA\TeamHub\Service\Provisioning\ProvisioningContext;
use OCA\TeamHub\Service\Provisioning\Step\ValidateStep;
use OCA\TeamHub\Service\Provisioning\StepResult;
use OCA\TeamHub\Service\TeamService;
use PHPUnit\Framework\TestCase;

/**
 * The validate step (v4.9.6): every refusal happens here, before anything
 * is made, and none of them is retried blindly.
 */
class ValidateStepTest extends TestCase {

    private array $caps = ['errorCode' => null, 'userMessage' => null];
    private bool $mayCreate = true;
    private bool $canCreateProjects = true;
    private bool $identifierFree = true;
    private array $templates = [['id' => 5, 'identifier' => 'tpl', 'name' => 'Template', 'active' => true, 'public' => false, 'canEditProject' => true, 'linkable' => true]];
    private array $missingRequired = [];
    private array $needsDecision = [];
    private ?\Throwable $linkRefusal = null;

    private function step(): ValidateStep {
        $members = $this->createMock(MemberService::class);
        $members->method('canCurrentUserCreateTeam')->willReturnCallback(fn () => $this->mayCreate);
        $teams = $this->createMock(TeamService::class);
        $caps = $this->createMock(OpenProjectCapabilityService::class);
        $caps->method('getCapabilities')->willReturnCallback(fn () => $this->caps);
        $op = $this->createMock(OpenProjectProvisioningService::class);
        $op->method('canCreateProjects')->willReturnCallback(fn () => $this->canCreateProjects);
        $op->method('isIdentifierAvailable')->willReturnCallback(fn () => $this->identifierFree);
        $op->method('listTemplates')->willReturnCallback(fn () => $this->templates);
        $op->method('listRoles')->willReturn([['id' => 3, 'name' => 'Project admin'], ['id' => 4, 'name' => 'Member']]);
        $links = $this->createMock(TeamOpenProjectLinkService::class);
        $links->method('assertLinkableForNewTeam')->willReturnCallback(function () {
            if ($this->linkRefusal !== null) throw $this->linkRefusal;
            return [];
        });
        $blueprints = $this->createMock(BlueprintService::class);
        $blueprints->method('componentsForTemplate')->willReturnCallback(fn () => ['blueprint' => [], 'components' => [], 'missingRequired' => $this->missingRequired]);
        $membership = $this->createMock(MembershipPlanService::class);
        $membership->method('resolveMapping')->willReturn(['owner' => ['name' => 'Project admin', 'id' => 3, 'missing' => false], 'member' => ['name' => 'Member', 'id' => 4, 'missing' => false]]);
        $membership->method('plan')->willReturnCallback(fn () => ['entries' => [], 'unmatched' => $this->needsDecision, 'needsDecision' => $this->needsDecision, 'usedRoleKeys' => ['owner']]);

        return new ValidateStep($members, $teams, $caps, $op, $links, $blueprints, $membership);
    }

    private function ctx(string $mode = 'create', array $openProject = [], ?Blueprint $bp = null): ProvisioningContext {
        $request = [
            'name' => 'Apollo', 'members' => [], 'ownerUid' => '',
            'openProject' => $openProject + ['projectId' => 77, 'templateId' => 5, 'parentId' => 0, 'identifier' => 'apollo'],
            'components' => ['apps' => ['talk'], 'modules' => []],
        ];
        $op = ['id' => 1, 'request' => $request, 'mode' => $mode, 'templateKey' => 'openproject', 'createdBy' => 'alice', 'teamId' => null];
        return new ProvisioningContext($op, [], $bp ?? Blueprint::defaultsForOpenProject(), 'alice', null);
    }

    public function testEverythingInOrderCompletes(): void {
        $result = $this->step()->run($this->ctx());
        $this->assertSame(StepResult::COMPLETED, $result->status);
        $this->assertTrue($result->detail['openProject']);
    }

    public function testNotAllowedToCreateTeams(): void {
        $this->mayCreate = false;
        $result = $this->step()->run($this->ctx());
        $this->assertSame('not_allowed', $result->errorCode);
        $this->assertFalse($result->retrySafe);
    }

    public function testMissingRequiredApplicationsAreNamed(): void {
        $this->missingRequired = ['calendar'];
        $result = $this->step()->run($this->ctx());
        $this->assertSame('missing_apps', $result->errorCode);
        $this->assertSame(['calendar'], $result->detail['missing']);
        $this->assertTrue($result->retrySafe, 'an administrator installs the app and retries');
    }

    public function testAnUnusableIntegrationFailsWithItsOwnCode(): void {
        $this->caps = ['errorCode' => OpenProjectException::USER_NOT_CONNECTED, 'userMessage' => 'Connect your account'];
        $result = $this->step()->run($this->ctx());
        $this->assertSame(OpenProjectException::USER_NOT_CONNECTED, $result->errorCode);
        $this->assertSame('Connect your account', $result->errorMessage);
    }

    public function testCreateModeNeedsTheRightToCreateProjects(): void {
        $this->canCreateProjects = false;
        $result = $this->step()->run($this->ctx());
        $this->assertSame(OpenProjectException::PERMISSION_DENIED, $result->errorCode);
        $this->assertFalse($result->retrySafe);
    }

    public function testATakenIdentifierIsRefused(): void {
        $this->identifierFree = false;
        $result = $this->step()->run($this->ctx());
        $this->assertSame('identifier_taken', $result->errorCode);
    }

    public function testAnUnapprovedTemplateIsRefusedBeforeOpenProjectIsAsked(): void {
        $bp = Blueprint::fromArray(['openproject' => ['required' => true, 'approvedTemplates' => [9]]]);
        $result = $this->step()->run($this->ctx(bp: $bp));
        $this->assertSame('template_not_approved', $result->errorCode);
    }

    public function testAnInaccessibleTemplateIsRefused(): void {
        $this->templates = [];
        $result = $this->step()->run($this->ctx());
        $this->assertSame('template_inaccessible', $result->errorCode);
    }

    public function testAParentIsRefusedWhenTheBlueprintForbidsIt(): void {
        $bp = Blueprint::fromArray(['openproject' => ['required' => true, 'allowParent' => false]]);
        $result = $this->step()->run($this->ctx(openProject: ['parentId' => 3], bp: $bp));
        $this->assertSame('parent_not_allowed', $result->errorCode);
    }

    public function testLinkModeUsesPhaseOnesCheckAndReportsATakenProject(): void {
        $this->linkRefusal = new ProjectAlreadyLinkedException([['teamId' => 't', 'name' => 'Other']]);
        $result = $this->step()->run($this->ctx(mode: 'link'));
        $this->assertSame('project_already_linked', $result->errorCode);
        $this->assertSame([['teamId' => 't', 'name' => 'Other']], $result->detail['teams']);
        $this->assertFalse($result->retrySafe);
    }

    public function testUnmatchedMembersWithoutADecisionStopTheOperation(): void {
        $this->needsDecision = ['carol'];
        $result = $this->step()->run($this->ctx());
        $this->assertSame('unmatched_members', $result->errorCode);
        $this->assertSame(['carol'], $result->detail['needsDecision']);
    }

    public function testAModeTheBlueprintForbidsIsRefused(): void {
        $bp = Blueprint::fromArray(['openproject' => ['required' => true, 'modes' => ['link']]]);
        $result = $this->step()->run($this->ctx(bp: $bp));
        $this->assertSame('mode_not_allowed', $result->errorCode);
    }

    public function testATemplateWithoutOpenProjectValidatesOnlyTheTeam(): void {
        $bp = Blueprint::derived(['templateKey' => 'collaboration', 'apps' => ['talk'], 'modules' => []]);
        $result = $this->step()->run($this->ctx(bp: $bp));
        $this->assertSame(StepResult::COMPLETED, $result->status);
        $this->assertFalse($result->detail['openProject']);
    }
}
