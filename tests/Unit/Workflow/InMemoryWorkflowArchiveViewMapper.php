<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Db\WorkflowArchiveView;
use OCA\TeamHub\Db\WorkflowArchiveViewMapper;
use OCP\AppFramework\Db\Entity;

/**
 * `teamhub_wf_archive_view` in an array (WorkflowHub phase 6 tests).
 *
 * `search()` reproduces the real query's shape rather than its SQL: the
 * scope is an OR over (audience, team) pairs, an empty scope returns
 * nothing, the text filter is a case-insensitive substring of the haystack
 * or an exact reference, and the page is newest first. The unique index on
 * (archive_id, audience) is enforced, so "one projection per audience"
 * can be failed on here too.
 */
class InMemoryWorkflowArchiveViewMapper extends WorkflowArchiveViewMapper {

    /** @var array<int, WorkflowArchiveView> */
    public array $rows = [];
    private int $nextId = 1;

    public function __construct() {
        // No database.
    }

    public function insert(Entity $entity): Entity {
        foreach ($this->rows as $row) {
            if ($row->getArchiveId() === $entity->getArchiveId() && $row->getAudience() === $entity->getAudience()) {
                throw new \RuntimeException('duplicate projection for archive ' . $entity->getArchiveId());
            }
        }
        $entity->setId($this->nextId++);
        $this->rows[(int)$entity->getId()] = clone $entity;
        return $entity;
    }

    public function findByArchive(int $archiveId): array {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row->getArchiveId() === $archiveId) {
                $out[] = clone $row;
            }
        }
        return $out;
    }

    public function search(array $scope, array $filters = []): array {
        if ($scope === []) {
            return [];
        }
        $q   = trim((string)($filters['q'] ?? ''));
        $out = [];
        foreach ($this->rows as $row) {
            $inScope = false;
            foreach ($scope as [$audience, $teamId]) {
                if ($row->getAudience() === $audience && $row->getTeamId() === $teamId) {
                    $inScope = true;
                    break;
                }
            }
            if (!$inScope) {
                continue;
            }
            if ($q !== ''
                && !str_contains((string)$row->getSearchText(), mb_strtolower($q))
                && $row->getRefNumber() !== mb_strtoupper($q)
            ) {
                continue;
            }
            foreach (['outcome' => 'getOutcome', 'definitionKey' => 'getDefinitionKey', 'serviceKey' => 'getServiceKey'] as $key => $getter) {
                $value = trim((string)($filters[$key] ?? ''));
                if ($value !== '' && $row->$getter() !== $value) {
                    continue 2;
                }
            }
            if ((int)($filters['from'] ?? 0) > 0 && $row->getCompletedAt() < (int)$filters['from']) {
                continue;
            }
            if ((int)($filters['to'] ?? 0) > 0 && $row->getCompletedAt() > (int)$filters['to']) {
                continue;
            }
            $out[] = clone $row;
        }

        usort($out, static fn (WorkflowArchiveView $a, WorkflowArchiveView $b): int =>
            [$b->getCompletedAt(), $b->getId()] <=> [$a->getCompletedAt(), $a->getId()]);

        $limit  = max(1, min(self::MAX_LIMIT, (int)($filters['limit'] ?? 25)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        return array_slice($out, $offset, $limit);
    }

    public function deleteByArchive(int $archiveId): int {
        $removed = 0;
        foreach ($this->rows as $id => $row) {
            if ($row->getArchiveId() === $archiveId) {
                unset($this->rows[$id]);
                $removed++;
            }
        }
        return $removed;
    }
}
