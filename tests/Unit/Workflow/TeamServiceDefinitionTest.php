<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Workflow\Definition\TeamServiceDefinition;
use OCA\TeamHub\Workflow\IWorkflowClosableByDesk;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * A service a service team built (v4.10.31): its steps come from the
 * published document, its desk is the team that built it, and it is dark
 * the moment it is unpublished or its team stops being a service team.
 */
class TeamServiceDefinitionTest extends TestCase {

    private const DOCUMENT = [
        'title'       => 'Application intake',
        'description' => 'Ask for a new application.',
        'category'    => 'apps_tools',
        'leadDays'    => 3,
        'askTeam'     => true,
        'steps'       => [
            ['kind' => 'desk',      'label' => 'Assess the request', 'role' => 'Functional admin'],
            ['kind' => 'requester', 'label' => 'Sign the agreement'],
            ['kind' => 'desk',      'label' => 'Install the app',    'role' => 'Technical admin'],
        ],
    ];

    private function definition(bool $listed = true, bool $activeDesk = true, array $document = self::DOCUMENT): TeamServiceDefinition {
        $teams = $this->createMock(ServiceTeamService::class);
        $teams->method('isActiveServiceTeam')->willReturnCallback(
            static fn (string $id): bool => $activeDesk && $id === 'desk1',
        );
        return new TeamServiceDefinition($teams, 7, 'desk1', 3, $listed, $document);
    }

    private function l10n(): IL10N {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
        return $l;
    }

    public function testTheKeyNamesTheServiceAndReadsBack(): void {
        $this->assertSame('team_service_7', $this->definition()->getKey());
        $this->assertSame(7, TeamServiceDefinition::idOf('team_service_7'));
        $this->assertNull(TeamServiceDefinition::idOf('service_general'));
        $this->assertNull(TeamServiceDefinition::idOf('team_service_x'));
        $this->assertSame(3, $this->definition()->getVersion());
    }

    public function testTheStepsAreSubmitTheBuildersStepsAndConfirm(): void {
        $steps = $this->definition()->getSteps();
        $this->assertSame(['submit', 'step_1', 'step_2', 'step_3', 'confirm'], array_map(static fn ($s): string => $s->key, $steps));
        $this->assertTrue($steps[0]->autoCompleteOnCreate);

        $this->assertTrue($steps[1]->actor->isUnresolved(), 'a desk step is the service team, filled in at start');
        $this->assertSame('Functional admin', $steps[1]->roleLabel);
        $this->assertSame('Assess the request', $steps[1]->label);

        $this->assertTrue($steps[2]->actor->isInitiator(), 'a requester action is the requester\'s');
        $this->assertSame('', $steps[2]->roleLabel);

        $this->assertSame('Technical admin', $steps[3]->roleLabel);
        $this->assertTrue($steps[4]->actor->isInitiator());
    }

    public function testTheShapePassesTheRegistry(): void {
        $registry = new WorkflowDefinitionRegistry();
        $registry->register($this->definition());
        $this->assertTrue($registry->has('team_service_7'));
    }

    public function testDeskStepsGoToTheTeamThatBuiltIt(): void {
        $definition = $this->definition();
        $desk = $definition->getSteps()[1];
        $this->assertTrue($definition->resolveActor($desk, 't1', [])->equals(WorkflowActor::serviceAgent('desk1')));

        $gone = $this->definition(true, false);
        $this->assertNull($gone->resolveActor($desk, 't1', []), 'no active service team, no desk: the engine refuses the request');
    }

    public function testOnlyTheBuildersOwnWordsAreUntranslated(): void {
        $definition = $this->definition();
        $this->assertSame('Request submitted', $definition->getStepLabel($this->l10n(), 'submit'));
        $this->assertSame('Requester confirms', $definition->getStepLabel($this->l10n(), 'confirm'));
        $this->assertNull($definition->getStepLabel($this->l10n(), 'step_1'), 'the engine falls back to the copy on the step row');
    }

    public function testTheRequestKeepsTheTitleItWasMadeUnder(): void {
        $data = $this->definition()->validateStart(['summary' => 'Miro', 'details' => 'For workshops']);
        $this->assertSame('Application intake', $data['serviceTitle']);
        $this->assertSame('team_service_7', $data['serviceKey']);

        $renamed = $this->definition(true, true, ['title' => 'App intake'] + self::DOCUMENT);
        $this->assertSame('Application intake: Miro', $renamed->getTitle($this->l10n(), $data));
    }

    public function testASummaryAndADescriptionAreRequired(): void {
        $this->expectException(ValidationException::class);
        $this->definition()->validateStart(['summary' => '', 'details' => 'x']);
    }

    public function testDarkWhenUnpublishedOrTheTeamIsNoLongerAServiceTeam(): void {
        $this->assertTrue($this->definition()->isStartable());
        $this->assertFalse($this->definition(false)->isStartable(), 'unpublished: registered, not startable');
        $this->assertFalse($this->definition(true, false)->isStartable());
        $this->assertFalse($this->definition()->allowsUnlicensedUse());
    }

    public function testTheDeskMayCloseTheConfirmation(): void {
        $this->assertInstanceOf(IWorkflowClosableByDesk::class, $this->definition());
    }
}
