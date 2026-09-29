<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Db\WorkflowEvent;
use OCA\TeamHub\Db\WorkflowEventMapper;
use OCP\AppFramework\Db\Entity;

/** `teamhub_wf_event` in an array (WorkflowHub phase 1 tests). Append only. */
class InMemoryWorkflowEventMapper extends WorkflowEventMapper {

    /** @var array<int, WorkflowEvent> */
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

    public function findByInstance(int $instanceId): array {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row->getInstanceId() === $instanceId) {
                $out[] = clone $row;
            }
        }
        usort($out, static fn (WorkflowEvent $a, WorkflowEvent $b): int =>
            [$a->getOccurredAt(), $a->getId()] <=> [$b->getOccurredAt(), $b->getId()]);
        return $out;
    }

    /** v4.10.17 — the batched cooldown lookup, over the same array. */
    public function findOwnEventsSince(array $instanceIds, string $actorUid, string $eventType, int $since): array {
        $wanted = array_map('intval', $instanceIds);
        if ($wanted === [] || $actorUid === '') {
            return [];
        }
        $out = [];
        foreach ($this->rows as $row) {
            $id = $row->getInstanceId();
            if (!in_array($id, $wanted, true)
                || $row->getActorUid() !== $actorUid
                || $row->getEventType() !== $eventType
                || $row->getOccurredAt() <= $since
            ) {
                continue;
            }
            $key = (string)($row->getStepKey() ?? '');
            $at  = $row->getOccurredAt();
            if (!isset($out[$id][$key]) || $out[$id][$key] < $at) {
                $out[$id][$key] = $at;
            }
        }
        return $out;
    }

    /** v4.10.27 — the earliest event of some types per step, over the same array. */
    public function findFirstEventsOfTypes(array $instanceIds, array $eventTypes): array {
        $wanted = array_map('intval', $instanceIds);
        $out    = [];
        foreach ($this->rows as $row) {
            $id = $row->getInstanceId();
            if (!in_array($id, $wanted, true) || !in_array($row->getEventType(), $eventTypes, true)) {
                continue;
            }
            $key = (string)($row->getStepKey() ?? '');
            $at  = $row->getOccurredAt();
            if (!isset($out[$id][$key]) || $out[$id][$key] > $at) {
                $out[$id][$key] = $at;
            }
        }
        return $out;
    }

    /** Phase 4: set to make the purge's first delete fail, as a database would. */
    public bool $failDelete = false;

    public function deleteByInstance(int $instanceId): void {
        if ($this->failDelete) {
            throw new \RuntimeException('delete failed');
        }
        foreach ($this->rows as $id => $row) {
            if ($row->getInstanceId() === $instanceId) {
                unset($this->rows[$id]);
            }
        }
    }

    /** @return string[] event types of one instance, in order */
    public function typesFor(int $instanceId): array {
        return array_map(static fn (WorkflowEvent $e): string => $e->getEventType(), $this->findByInstance($instanceId));
    }
}
