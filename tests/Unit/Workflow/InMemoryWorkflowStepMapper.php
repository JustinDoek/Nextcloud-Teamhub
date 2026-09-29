<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Db\WorkflowStep;
use OCA\TeamHub\Db\WorkflowStepMapper;
use OCP\AppFramework\Db\Entity;

/** `teamhub_wf_step` in an array (WorkflowHub phase 1 tests). */
class InMemoryWorkflowStepMapper extends WorkflowStepMapper {

    /** @var array<int, WorkflowStep> */
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
            throw new \RuntimeException('update of an unknown step');
        }
        $this->rows[(int)$entity->getId()] = clone $entity;
        return $entity;
    }

    public function findByInstance(int $instanceId): array {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row->getInstanceId() === $instanceId) {
                $out[] = clone $row;
            }
        }
        usort($out, static fn (WorkflowStep $a, WorkflowStep $b): int => $a->getStepOrder() <=> $b->getStepOrder());
        return $out;
    }

    public function findByInstances(array $instanceIds): array {
        $out = [];
        foreach ($instanceIds as $id) {
            $steps = $this->findByInstance((int)$id);
            if ($steps !== []) {
                $out[(int)$id] = $steps;
            }
        }
        return $out;
    }

    public function findActiveForActor(string $actorType, string $actorId): array {
        $active = ['available', 'in_progress', 'waiting_for_information'];
        $out    = [];
        foreach ($this->rows as $row) {
            if ($row->getActorType() === $actorType
                && $row->getActorId() === $actorId
                && in_array($row->getStepStatus(), $active, true)
            ) {
                $out[] = clone $row;
            }
        }
        usort($out, static fn (WorkflowStep $a, WorkflowStep $b): int => (int)$a->getId() <=> (int)$b->getId());
        return $out;
    }

    /** v4.10.27 — entered or finished since a moment, newest first. */
    public function findForActorSince(string $actorType, string $actorId, int $since): array {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row->getActorType() !== $actorType || $row->getActorId() !== $actorId) {
                continue;
            }
            if (($row->getEnteredAt() ?? -1) >= $since || ($row->getCompletedAt() ?? -1) >= $since) {
                $out[] = clone $row;
            }
        }
        usort($out, static fn (WorkflowStep $a, WorkflowStep $b): int =>
            [$b->getEnteredAt() ?? 0, (int)$b->getId()] <=> [$a->getEnteredAt() ?? 0, (int)$a->getId()]);
        return $out;
    }

    public function deleteByInstance(int $instanceId): void {
        foreach ($this->rows as $id => $row) {
            if ($row->getInstanceId() === $instanceId) {
                unset($this->rows[$id]);
            }
        }
    }
}
