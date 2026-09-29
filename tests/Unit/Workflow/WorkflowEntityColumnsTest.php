<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Db\WorkflowAttachment;
use OCA\TeamHub\Db\WorkflowStep;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;

/**
 * `QBMapper` writes a row through the entity's own getters —
 * `$entity->{'get' . ucfirst($field)}()` for every field. A typed helper
 * that happens to be *named* like a getter replaces the column's value on
 * write. v4.10.37 shipped `WorkflowStep::getLinks(): array`, and every link
 * was stored as the string "Array" (found on the instance, 2026-09-25). The
 * in-memory mappers of the other tests keep entities as objects and could
 * not see it; this test asks the question the real mapper asks.
 */
class WorkflowEntityColumnsTest extends TestCase {

    /** @return array<string, array{0: Entity}> */
    public static function entities(): array {
        $step = new WorkflowStep();
        $step->setLinks('[{"label":"Form","url":"https://forms.example.org/1","kind":"form"}]');
        $step->setStageLabel('Assess');
        $step->setNonBlocking(1);
        $step->setRoleLabel('Privacy officer');
        $step->setAssignee('agent1');

        $doc = new WorkflowAttachment();
        $doc->setShareId('ocCircleShare:12');
        $doc->setShareUntil(1_800_000_000);

        return ['step' => [$step], 'attachment' => [$doc]];
    }

    /** @dataProvider entities */
    public function testEveryGetterTheMapperCallsAnswersAColumnValue(Entity $entity): void {
        foreach (array_keys($entity->getFieldTypes()) as $field) {
            $value = $entity->{'get' . ucfirst($field)}();
            $this->assertTrue(
                $value === null || is_scalar($value),
                get_class($entity) . '::get' . ucfirst($field) . '() must answer a column value, got ' . get_debug_type($value),
            );
        }
    }

    public function testTheStepsLinksAreReadThroughLinkList(): void {
        $step = self::entities()['step'][0];
        $this->assertIsString($step->getLinks(), 'the raw JSON, which is what the column holds');
        $this->assertSame('https://forms.example.org/1', $step->linkList()[0]['url']);
        $step->setLinks('Array');
        $this->assertSame([], $step->linkList(), 'a row written by v4.10.37 reads as no links');
    }
}
