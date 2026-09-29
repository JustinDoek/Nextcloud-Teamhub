<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Db\WorkflowArchive;
use OCA\TeamHub\Db\WorkflowArchiveMapper;
use OCP\AppFramework\Db\Entity;

/**
 * `teamhub_wf_archive` in an array (WorkflowHub phase 6 tests).
 *
 * Same public contract as the real mapper, including the one thing the
 * database enforces and a test would otherwise silently pass without: the
 * unique index on `instance_id`. A second archive for one workflow throws
 * here too, so "there is one authoritative record" is a property the tests
 * can actually fail on.
 */
class InMemoryWorkflowArchiveMapper extends WorkflowArchiveMapper {

    /** @var array<int, WorkflowArchive> */
    public array $rows = [];
    private int $nextId = 1;

    /** Set to make the archive write fail, as a database would. */
    public bool $failInsert = false;

    public function __construct() {
        // No database.
    }

    public function insert(Entity $entity): Entity {
        if ($this->failInsert) {
            throw new \RuntimeException('archive insert failed');
        }
        foreach ($this->rows as $row) {
            if ($row->getInstanceId() === $entity->getInstanceId()) {
                throw new \RuntimeException('duplicate archive for instance ' . $entity->getInstanceId());
            }
            if ($row->getRefNumber() === $entity->getRefNumber()) {
                throw new \RuntimeException('duplicate archive reference ' . $entity->getRefNumber());
            }
        }
        $entity->setId($this->nextId++);
        $this->rows[(int)$entity->getId()] = clone $entity;
        return $entity;
    }

    public function findById(int $id): ?WorkflowArchive {
        return isset($this->rows[$id]) ? clone $this->rows[$id] : null;
    }

    public function findByInstance(int $instanceId): ?WorkflowArchive {
        foreach ($this->rows as $row) {
            if ($row->getInstanceId() === $instanceId) {
                return clone $row;
            }
        }
        return null;
    }

    public function findByReference(string $reference): ?WorkflowArchive {
        foreach ($this->rows as $row) {
            if ($row->getRefNumber() === $reference) {
                return clone $row;
            }
        }
        return null;
    }

    public function findByIds(array $ids): array {
        $wanted = array_map('intval', $ids);
        $out    = [];
        foreach ($this->rows as $id => $row) {
            if (in_array($id, $wanted, true)) {
                $out[$id] = clone $row;
            }
        }
        return $out;
    }

    public function countForTeam(string $teamId): int {
        $n = 0;
        foreach ($this->rows as $row) {
            if ($row->getTeamId() === $teamId) {
                $n++;
            }
        }
        return $n;
    }

    public function findExpired(int $now, int $limit = 100): array {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row->getRetentionUntil() > 0 && $row->getRetentionUntil() <= $now && $row->getLegalHold() === 0) {
                $out[] = clone $row;
            }
        }
        usort($out, static fn (WorkflowArchive $a, WorkflowArchive $b): int =>
            $a->getRetentionUntil() <=> $b->getRetentionUntil());
        return array_slice($out, 0, $limit);
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
