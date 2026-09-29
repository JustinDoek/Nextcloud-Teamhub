<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\Workflow\WorkflowActorResolver;
use OCA\TeamHub\Workflow\IWorkflowDefinition;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCA\TeamHub\Workflow\WorkflowStepDefinition;
use OCP\IL10N;

/**
 * A workflow definition the tests shape per case (WorkflowHub phase 1):
 * any steps, any version, a starter rule given as a closure.
 */
class FixtureDefinition implements IWorkflowDefinition {

    /** What `resolveActor()` answers; null unless a test sets it. */
    public ?WorkflowActor $resolvedActor = null;

    /** @var callable(string, string, WorkflowActorResolver): bool */
    private $canStart;

    /**
     * @param WorkflowStepDefinition[] $steps
     * @param null|callable(string, string, WorkflowActorResolver): bool $canStart default: anybody
     */
    public function __construct(
        private string $key,
        private int    $version,
        private array  $steps,
        ?callable      $canStart = null,
        private string $concurrency = WorkflowDefinitionRegistry::CONCURRENCY_ONE_OPEN_PER_TEAM,
        private bool   $requireReason = false,
        public bool    $unlicensed = true,
        public bool    $startable = true,
    ) {
        $this->canStart = $canStart ?? static fn (): bool => true;
    }

    public function getKey(): string {
        return $this->key;
    }

    public function getVersion(): int {
        return $this->version;
    }

    public function getName(): string {
        return 'Fixture ' . $this->key;
    }

    public function getTitle(IL10N $l, array $data): string {
        return $l->t('Fixture %s', [$this->key]);
    }

    public function getDescription(IL10N $l, array $data): string {
        return (string)($data['reason'] ?? '');
    }

    public function getStepLabel(IL10N $l, string $stepKey): ?string {
        foreach ($this->steps as $step) {
            if ($step->key === $stepKey) {
                return $l->t($step->label);
            }
        }
        return null;
    }

    public function getSteps(): array {
        return $this->steps;
    }

    /**
     * The fixture's actors are concrete. A test that needs a placeholder
     * resolved sets `$this->resolvedActor` and gets it back for every
     * unresolved step.
     */
    public function resolveActor(WorkflowStepDefinition $step, string $teamId, array $data): ?WorkflowActor {
        return $this->resolvedActor;
    }

    public function getConcurrency(): string {
        return $this->concurrency;
    }

    public function canStart(string $uid, string $teamId, WorkflowActorResolver $resolver): bool {
        return ($this->canStart)($uid, $teamId, $resolver);
    }

    public function validateStart(array $data): array {
        if ($this->requireReason && trim((string)($data['reason'] ?? '')) === '') {
            throw new ValidationException('A reason is required.');
        }
        return $data;
    }

    public function subjectOf(string $teamId, array $data): array {
        return ['team', $teamId];
    }

    /** v4.10.17: a fixture is startable unless the test darkens it. */
    public function isStartable(): bool {
        return $this->startable;
    }

    /** Phase 4: a fixture is allowed unlicensed unless the test says otherwise. */
    public function allowsUnlicensedUse(): bool {
        return $this->unlicensed;
    }

    /** Nothing in a fixture title depends on the data. */
    public function getNotificationData(array $data): array {
        return [];
    }
}
