<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Db\WorkflowInstance;
use OCA\TeamHub\Db\WorkflowInstanceMapper;
use OCP\AppFramework\Db\Entity;

/**
 * `teamhub_wf_instance` in an array (WorkflowHub phase 1 tests). Same
 * public contract as the real mapper; rows are stored and returned as
 * clones so a test sees exactly what a database would have kept.
 */
class InMemoryWorkflowInstanceMapper extends WorkflowInstanceMapper {

    /** @var array<int, WorkflowInstance> */
    public array $rows = [];
    private int $nextId = 1;

    public function __construct() {
        // No database.
    }

    public function insert(Entity $entity): Entity {
        $entity->setId($this->nextId++);
        $this->rows[(int)$entity->getId()] = clone $entity;
        return $entity;
    }

    public function update(Entity $entity): Entity {
        if (!isset($this->rows[(int)$entity->getId()])) {
            throw new \RuntimeException('update of an unknown instance');
        }
        $this->rows[(int)$entity->getId()] = clone $entity;
        return $entity;
    }

    /** Phase 4: set to make the purge's last delete fail, as a database would. */
    public bool $failDelete = false;

    public function deleteById(int $id): int {
        if ($this->failDelete) {
            throw new \RuntimeException('delete failed');
        }
        if (!isset($this->rows[$id])) {
            return 0;
        }
        unset($this->rows[$id]);
        return 1;
    }

    public function findById(int $id): ?WorkflowInstance {
        return isset($this->rows[$id]) ? clone $this->rows[$id] : null;
    }

    public function findByIds(array $ids, ?string $status = null): array {
        $out = [];
        foreach ($ids as $id) {
            $row = $this->rows[(int)$id] ?? null;
            if ($row === null || ($status !== null && $row->getStatus() !== $status)) {
                continue;
            }
            $out[] = clone $row;
        }
        usort($out, static fn (WorkflowInstance $a, WorkflowInstance $b): int =>
            [$b->getUpdatedAt(), $b->getId()] <=> [$a->getUpdatedAt(), $a->getId()]);
        return $out;
    }

    public function findOpenForDefinitionAndTeam(string $definitionKey, string $teamId, array $openStatuses): array {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row->getDefinitionKey() === $definitionKey && $row->getTeamId() === $teamId
                && in_array($row->getStatus(), $openStatuses, true)) {
                $out[] = clone $row;
            }
        }
        return $out;
    }
}
