<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Provisioning;

use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\Provisioning\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * The blueprint model (v4.9.6): validation, defaults, and — most of all —
 * backward compatibility: a template row without a blueprint behaves
 * exactly as it did before Phase 2.
 */
class BlueprintTest extends TestCase {

    /** @return array<string,mixed> a template row as TeamTemplateMapper hydrates it */
    private static function row(string $key, array $apps, array $modules, ?array $blueprint = null): array {
        return [
            'templateKey' => $key, 'label' => ucfirst($key), 'description' => null,
            'apps' => $apps, 'modules' => $modules, 'expiryEnabled' => true, 'expiryDefaultDays' => 0,
            'preselectConfig' => 32, 'sortIndex' => 0, 'defaultProfileKey' => null, 'isSeeded' => true,
            'updatedBy' => null, 'updatedAt' => null, 'blueprint' => $blueprint,
        ];
    }

    public function testATemplateWithoutABlueprintIsDerivedAndEverythingIsRequired(): void {
        $bp = Blueprint::fromTemplateRow(self::row('project', ['talk', 'files', 'calendar', 'deck'], ['decisions', 'timeline', 'messages', 'pages']));

        $this->assertFalse($bp->isStored());
        $this->assertSame(['talk', 'files', 'calendar', 'deck', 'intravox'], $bp->requiredApps(), 'pages is the Intravox alias');
        $this->assertSame([], $bp->optionalApps());
        $this->assertSame(['decisions', 'timeline', 'messages'], $bp->requiredModules());
        $this->assertFalse($bp->requiresOpenProject(), 'only the openproject template needs a project');
        $this->assertSame('teamhub', $bp->folderBehavior());
        $this->assertSame('create', $bp->talkBehavior());
        $this->assertSame('none', $bp->collectiveBehavior());
        $this->assertSame([], $bp->roleMapping());
    }

    public function testTheOpenProjectTemplateDerivedBlueprintRequiresAProject(): void {
        $bp = Blueprint::fromTemplateRow(self::row('openproject', ['talk', 'files', 'calendar'], ['decisions', 'messages', 'pages']));
        $this->assertTrue($bp->requiresOpenProject());
        $this->assertSame(['create', 'link'], $bp->openProjectModes());
        $this->assertTrue($bp->isTemplateApproved(42), 'no approved list approves everything accessible');
    }

    public function testShippedDefaultsMatchTheMigrationSeed(): void {
        $bp = Blueprint::defaultsForOpenProject();
        $this->assertTrue($bp->isStored());
        $this->assertSame(['talk', 'files', 'calendar', 'intravox'], $bp->requiredApps(), 'the seeded row: Talk, Files, Calendar, Pages');
        $this->assertSame([], $bp->optionalApps(), 'nothing is optional — the template decides');
        $this->assertSame(['decisions', 'messages'], $bp->requiredModules());
        $this->assertSame([], $bp->optionalModules());
        $this->assertSame('both', $bp->folderBehavior());
        $this->assertSame('none', $bp->collectiveBehavior());
        $this->assertFalse($bp->copyOptions()['members'], 'membership is TeamHub\'s step, not the copy\'s');
        $this->assertSame('Project admin', $bp->roleMapping()['owner']);
        $this->assertNull($bp->roleMapping()['guest'], 'guests get no OpenProject access unless configured');
        $this->assertContains('widget-openproject', $bp->dashboardWidgets());
    }

    public function testTheRowDecidesTheAppsEvenWithAStoredBlueprint(): void {
        $stored = Blueprint::defaultsForOpenProject()->toArray();
        $stored['apps'] = ['required' => ['deck'], 'optional' => ['collectives']];
        $stored['folder'] = ['behavior' => 'openproject'];
        $stored['roles']['mapping']['member'] = 'Reader';
        $bp = Blueprint::fromTemplateRow(self::row('openproject', ['talk', 'files'], ['wiki', 'messages'], $stored));
        $this->assertTrue($bp->isStored());
        $this->assertSame(['talk', 'files', 'collectives'], $bp->requiredApps(), 'the row\'s apps and modules, not the stored ones');
        $this->assertSame([], $bp->optionalApps());
        $this->assertSame(['messages'], $bp->requiredModules());
        $this->assertSame('create', $bp->collectiveBehavior(), 'behaviours follow the row');
        $this->assertSame('none', $bp->calendarBehavior());
        $this->assertSame('openproject', $bp->folderBehavior(), 'the folder behaviour is the stored one');
        $this->assertSame('Reader', $bp->roleMapping()['member'], 'and so is everything the row cannot say');
    }

    public function testAStoredBlueprintThatNoLongerValidatesFallsBackToTheDerivedOne(): void {
        $bp = Blueprint::fromTemplateRow(self::row('openproject', ['talk', 'files'], ['messages'], ['version' => 99]));
        $this->assertFalse($bp->isStored());
        $this->assertSame(['talk', 'files'], $bp->requiredApps());
    }

    public function testUnknownVocabularyIsRefusedByName(): void {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unknown application in apps.required: jira');
        Blueprint::fromArray(['apps' => ['required' => ['jira']]]);
    }

    public function testUnknownRoleKeyIsRefused(): void {
        $this->expectException(ValidationException::class);
        Blueprint::fromArray(['roles' => ['mapping' => ['superuser' => 'Project admin']]]);
    }

    public function testUnknownCopyKeyIsRefused(): void {
        $this->expectException(ValidationException::class);
        Blueprint::fromArray(['openproject' => ['copy' => ['everything' => true]]]);
    }

    public function testRequiredWinsOverOptionalAndListsAreDeduplicated(): void {
        $bp = Blueprint::fromArray([
            'apps'    => ['required' => ['talk', 'spreed'], 'optional' => ['talk', 'wiki']],
            'modules' => ['required' => ['messages'], 'optional' => ['messages', 'decisions']],
        ]);
        $this->assertSame(['talk'], $bp->requiredApps(), 'spreed is the Talk alias');
        $this->assertSame(['collectives'], $bp->optionalApps());
        $this->assertSame(['decisions'], $bp->optionalModules());
    }

    public function testApprovedTemplatesAcceptIdsAndObjects(): void {
        $bp = Blueprint::fromArray(['openproject' => ['required' => true, 'approvedTemplates' => [7, ['id' => 9, 'identifier' => 'gov-template', 'name' => 'Governed <b>project</b>']]]]);
        $this->assertTrue($bp->isTemplateApproved(7));
        $this->assertTrue($bp->isTemplateApproved(9));
        $this->assertFalse($bp->isTemplateApproved(8));
        $this->assertSame('Governed project', $bp->approvedTemplates()[1]['name'], 'plain text only');
    }

    public function testGovernanceAndLifecycleAreCarriedAsScalars(): void {
        $bp = Blueprint::fromArray(['governance' => ['reviewRequired' => true], 'lifecycle' => ['archiveAfterDays' => 90]]);
        $this->assertSame(['reviewRequired' => true], $bp->governance());
        $this->assertSame(['archiveAfterDays' => 90], $bp->lifecycle());

        $this->expectException(ValidationException::class);
        Blueprint::fromArray(['governance' => ['nested' => ['not' => 'allowed']]]);
    }

    public function testToArrayRoundTrips(): void {
        $first  = Blueprint::defaultsForOpenProject()->toArray();
        $second = Blueprint::fromArray($first)->toArray();
        $this->assertSame($first, $second);
        $this->assertArrayNotHasKey('stored', $first);
    }
}
