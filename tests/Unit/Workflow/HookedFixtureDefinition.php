<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Db\WorkflowInstance;
use OCA\TeamHub\Exception\WorkflowTransitionException;
use OCA\TeamHub\Workflow\IWorkflowDefinitionHooks;
use OCP\IL10N;

/**
 * A fixture definition with the v4.10.29 hooks: records what the engine
 * called, and refuses a step completion on demand.
 */
class HookedFixtureDefinition extends FixtureDefinition implements IWorkflowDefinitionHooks {

    /** @var array<int, array{0: string, 1: mixed}> hook calls, in order */
    public array $calls = [];

    /** A step key whose completion the hook refuses. */
    public ?string $refuseStep = null;

    public function validateForTeam(string $teamId, array $data): array {
        $this->calls[] = ['validateForTeam', $teamId];
        return $data + ['recordedByServer' => 'yes'];
    }

    public function onStepCompleted(WorkflowInstance $instance, string $stepKey, string $uid): void {
        $this->calls[] = ['onStepCompleted', $stepKey];
        if ($stepKey === $this->refuseStep) {
            throw new WorkflowTransitionException('refused by the definition');
        }
    }

    public function getActionLabels(IL10N $l, string $stepKey): array {
        return $stepKey === 'review' ? ['complete' => 'Grant', 'reject' => 'Decline'] : [];
    }
}
