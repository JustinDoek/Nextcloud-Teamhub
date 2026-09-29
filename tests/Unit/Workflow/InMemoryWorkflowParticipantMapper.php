<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Db\WorkflowParticipant;
use OCA\TeamHub\Db\WorkflowParticipantMapper;
use OCP\AppFramework\Db\Entity;

/**
 * `teamhub_wf_participant` in an array (WorkflowHub phase 1 tests). The
 * team-relative lookup joins on the instance mapper handed in, as the SQL
 * joins on the instance table.
 */
class InMemoryWorkflowParticipantMapper extends WorkflowParticipantMapper {

    /** @var array<int, WorkflowParticipant> */
    public array $rows = [];
    private int $nextId = 1;

    public function __construct(
        private InMemoryWorkflowInstanceMapper $instances,
    ) {
        // No database.
    }

    public function insert(Entity $entity): Entity {
        $entity->setId($this->nextId++);
        $this->rows[(int)$entity->getId()] = clone $entity;
        return $entity;
    }

    public function update(Entity $entity): Entity {
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
        return $out;
    }

    public function findByInstances(array $instanceIds): array {
        $out = [];
        foreach ($instanceIds as $id) {
            $rows = $this->findByInstance((int)$id);
            if ($rows !== []) {
                $out[(int)$id] = $rows;
            }
        }
        return $out;
    }

    public function findInstanceIdsForActors(array $actors): array {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row->getRemovedAt() !== null) {
                continue;
            }
            foreach ($actors as [$type, $id]) {
                if ($row->getActorType() === $type && $row->getActorId() === $id) {
                    $out[$row->getInstanceId()] = true;
                }
            }
        }
        return array_map('intval', array_keys($out));
    }

    public function findTeamRelativeForTeams(array $teamIds, array $teamRelativeTypes): array {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row->getRemovedAt() !== null || !in_array($row->getActorType(), $teamRelativeTypes, true)) {
                continue;
            }
            $instance = $this->instances->rows[$row->getInstanceId()] ?? null;
            if ($instance === null || !in_array($instance->getTeamId(), $teamIds, true)) {
                continue;
            }
            $out[] = [
                'instanceId' => $row->getInstanceId(),
                'teamId'     => $instance->getTeamId(),
                'actorType'  => $row->getActorType(),
            ];
        }
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
