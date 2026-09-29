<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow;

/**
 * Every built-in workflow definition, by key (WorkflowHub phase 1).
 *
 * Filled from `Application.php` the way `ProviderRegistry` is: one
 * `register()` per definition class, each constructed in its own try/catch
 * so a definition that cannot be built does not take the others down. A
 * definition's `getSteps()` is validated once, on registration, so a
 * malformed definition fails at boot rather than at the first `create()`.
 */
class WorkflowDefinitionRegistry {

    public const CONCURRENCY_ONE_OPEN_PER_TEAM = 'one_open_per_team';
    public const CONCURRENCY_UNBOUNDED         = 'unbounded';

    /** @var array<string, IWorkflowDefinition> */
    private array $definitions = [];

    public function register(IWorkflowDefinition $definition): void {
        $key = $definition->getKey();
        if ($key === '' || strlen($key) > 64 || !preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException('A definition key is 1–64 lowercase characters, digits or underscores: ' . $key);
        }
        if ($definition->getVersion() < 1) {
            throw new \InvalidArgumentException('Definition ' . $key . ' needs a version of at least 1.');
        }
        if (!in_array($definition->getConcurrency(), [self::CONCURRENCY_ONE_OPEN_PER_TEAM, self::CONCURRENCY_UNBOUNDED], true)) {
            throw new \InvalidArgumentException('Definition ' . $key . ' has an unknown concurrency rule.');
        }
        $steps = $definition->getSteps();
        if ($steps === []) {
            throw new \InvalidArgumentException('Definition ' . $key . ' has no steps.');
        }
        $seen = [];
        foreach ($steps as $step) {
            if (!$step instanceof WorkflowStepDefinition) {
                throw new \InvalidArgumentException('Definition ' . $key . ' lists something that is not a step.');
            }
            if (isset($seen[$step->key])) {
                throw new \InvalidArgumentException('Definition ' . $key . ' repeats step key ' . $step->key);
            }
            $seen[$step->key] = true;
        }
        $this->definitions[$key] = $definition;
    }

    public function get(string $key): ?IWorkflowDefinition {
        return $this->definitions[$key] ?? null;
    }

    public function has(string $key): bool {
        return isset($this->definitions[$key]);
    }

    /** @return array<string, IWorkflowDefinition> keyed by definition key */
    public function all(): array {
        return $this->definitions;
    }
}
