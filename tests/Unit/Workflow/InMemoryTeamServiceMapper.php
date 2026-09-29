<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Db\TeamService;
use OCA\TeamHub\Db\TeamServiceMapper;
use OCP\AppFramework\Db\Entity;

/** `teamhub_team_service` in an array (v4.10.33, the service builder). */
class InMemoryTeamServiceMapper extends TeamServiceMapper {

    /** @var array<int, TeamService> */
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
        $this->rows[(int)$entity->getId()] = clone $entity;
        return $entity;
    }

    public function delete(Entity $entity): Entity {
        unset($this->rows[(int)$entity->getId()]);
        return $entity;
    }

    public function findById(int $id): ?TeamService {
        return isset($this->rows[$id]) ? clone $this->rows[$id] : null;
    }

    public function findByTeam(string $teamId): array {
        $out = array_values(array_filter($this->rows, static fn (TeamService $r): bool => $r->getTeamId() === $teamId));
        usort($out, static fn (TeamService $a, TeamService $b): int => [$a->getSortOrder(), $a->getId()] <=> [$b->getSortOrder(), $b->getId()]);
        return array_map(static fn (TeamService $r): TeamService => clone $r, $out);
    }

    public function findAllServices(): array {
        return array_values(array_map(static fn (TeamService $r): TeamService => clone $r, $this->rows));
    }

    public function findEverPublished(): array {
        return array_values(array_map(
            static fn (TeamService $r): TeamService => clone $r,
            array_filter($this->rows, static fn (TeamService $r): bool => $r->getPubVersion() > 0),
        ));
    }

    public function findListed(): array {
        return array_values(array_map(
            static fn (TeamService $r): TeamService => clone $r,
            array_filter($this->rows, static fn (TeamService $r): bool => $r->isListed() && $r->getPubVersion() > 0),
        ));
    }

    public function hasEverPublished(string $teamId): bool {
        foreach ($this->rows as $row) {
            if ($row->getTeamId() === $teamId && $row->getPubVersion() > 0) {
                return true;
            }
        }
        return false;
    }

    public function nextSortOrder(string $teamId): int {
        $max = 0;
        foreach ($this->rows as $row) {
            if ($row->getTeamId() === $teamId) {
                $max = max($max, $row->getSortOrder());
            }
        }
        return $max + 1;
    }

    public function unlistByTeam(string $teamId, int $now): void {
        foreach ($this->rows as $row) {
            if ($row->getTeamId() === $teamId && $row->isListed()) {
                $row->setListed(0);
                $row->setUpdatedAt($now);
            }
        }
    }

    public function countByTeam(string $teamId): int {
        return count(array_filter($this->rows, static fn (TeamService $r): bool => $r->getTeamId() === $teamId));
    }
}
