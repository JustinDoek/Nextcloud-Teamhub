<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Db\WorkflowAttachment;
use OCA\TeamHub\Db\WorkflowAttachmentMapper;
use OCP\AppFramework\Db\Entity;

/**
 * `teamhub_wf_attachment` in an array (WorkflowHub phase 6 tests).
 *
 * The unique index on (instance_id, file_id) is enforced: two rows for one
 * file could disagree about its classification, which is the one thing
 * this table must not allow, and a test that could not fail on it would
 * not be testing that.
 */
class InMemoryWorkflowAttachmentMapper extends WorkflowAttachmentMapper {

    /** @var array<int, WorkflowAttachment> */
    public array $rows = [];
    private int $nextId = 1;

    public function __construct() {
        // No database.
    }

    public function insert(Entity $entity): Entity {
        foreach ($this->rows as $row) {
            if ($row->getInstanceId() === $entity->getInstanceId() && $row->getFileId() === $entity->getFileId()) {
                throw new \RuntimeException('duplicate attachment for file ' . $entity->getFileId());
            }
        }
        $entity->setId($this->nextId++);
        $this->rows[(int)$entity->getId()] = clone $entity;
        return $entity;
    }

    public function findById(int $id): ?WorkflowAttachment {
        return isset($this->rows[$id]) ? clone $this->rows[$id] : null;
    }

    public function findByInstance(int $instanceId): array {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row->getInstanceId() === $instanceId) {
                $out[] = clone $row;
            }
        }
        usort($out, static fn (WorkflowAttachment $a, WorkflowAttachment $b): int =>
            [$a->getAddedAt(), $a->getId()] <=> [$b->getAddedAt(), $b->getId()]);
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

    public function findByFile(int $instanceId, int $fileId): ?WorkflowAttachment {
        foreach ($this->rows as $row) {
            if ($row->getInstanceId() === $instanceId && $row->getFileId() === $fileId) {
                return clone $row;
            }
        }
        return null;
    }

    public function deleteById(int $id): int {
        if (!isset($this->rows[$id])) {
            return 0;
        }
        unset($this->rows[$id]);
        return 1;
    }

    public function deleteByInstance(int $instanceId): int {
        $removed = 0;
        foreach ($this->rows as $id => $row) {
            if ($row->getInstanceId() === $instanceId) {
                unset($this->rows[$id]);
                $removed++;
            }
        }
        return $removed;
    }
}
