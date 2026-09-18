<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Provisioning;

use OCA\TeamHub\Db\ProvisioningMapper;

/**
 * The provisioning tables in arrays (v4.9.6 tests). Same contract as the
 * real mapper, including the conditional lease claim, so the engine under
 * test is the engine that ships.
 */
class InMemoryProvisioningMapper extends ProvisioningMapper {

    /** @var array<int, array<string,mixed>> */
    public array $ops = [];
    /** @var array<int, array<string, array<string,mixed>>> operation → key → row */
    public array $steps = [];
    private int $nextId = 1;
    private int $nextStepId = 1;

    public function __construct() {
        // No database.
    }

    public function createOperation(string $createdBy, string $idempotencyKey, string $templateKey, string $mode, array $request): int {
        $id = $this->nextId++;
        $now = time();
        $this->ops[$id] = [
            'id' => $id, 'teamId' => null, 'templateKey' => $templateKey, 'mode' => $mode, 'status' => 'pending',
            'currentStep' => null, 'idempotencyKey' => $idempotencyKey, 'createdBy' => $createdBy,
            'createdAt' => $now, 'updatedAt' => $now, 'startedAt' => null, 'completedAt' => null,
            'heartbeatAt' => null, 'lockToken' => null, 'lockedAt' => null,
            'request' => $request, 'result' => null, 'errorCode' => null, 'errorMessage' => null,
        ];
        $this->steps[$id] = [];
        return $id;
    }

    public function findOperation(int $id): ?array {
        return $this->ops[$id] ?? null;
    }

    public function findByIdempotencyKey(string $createdBy, string $idempotencyKey): ?array {
        foreach ($this->ops as $op) {
            if ($op['createdBy'] === $createdBy && $op['idempotencyKey'] === $idempotencyKey) {
                return $op;
            }
        }
        return null;
    }

    public function findLatestByTeam(string $teamId): ?array {
        $found = null;
        foreach ($this->ops as $op) {
            if ($op['teamId'] === $teamId) {
                $found = $op;
            }
        }
        return $found;
    }

    public function findRecent(int $limit = 25, ?string $createdBy = null): array {
        $out = array_values(array_filter($this->ops, fn (array $op) => $createdBy === null || $op['createdBy'] === $createdBy));
        return array_slice(array_reverse($out), 0, $limit);
    }

    public function findStalledRunning(int $olderThan, int $limit = 5): array {
        return array_values(array_filter($this->ops, fn (array $op) => $op['status'] === 'running' && ($op['heartbeatAt'] === null || $op['heartbeatAt'] < $olderThan)));
    }

    public function findFinishedBefore(int $before, int $limit = 50): array {
        $out = [];
        foreach ($this->ops as $op) {
            if (in_array($op['status'], ['completed', 'rolled_back', 'cancelled'], true) && $op['completedAt'] !== null && $op['completedAt'] < $before) {
                $out[] = $op['id'];
            }
        }
        return $out;
    }

    public function claim(int $id, string $token, int $staleBefore): bool {
        $op = &$this->ops[$id];
        if ($op['lockToken'] !== null && $op['lockedAt'] !== null && $op['lockedAt'] >= $staleBefore) {
            return false;
        }
        $op['lockToken'] = $token;
        $op['lockedAt'] = time();
        $op['heartbeatAt'] = time();
        return true;
    }

    public function release(int $id, string $token): void {
        if (($this->ops[$id]['lockToken'] ?? null) === $token) {
            $this->ops[$id]['lockToken'] = null;
            $this->ops[$id]['lockedAt'] = null;
        }
    }

    public function touchHeartbeat(int $id): void {
        $this->ops[$id]['heartbeatAt'] = time();
    }

    public function updateOperation(int $id, array $fields): void {
        $map = ['status' => 'status', 'current_step' => 'currentStep', 'team_id' => 'teamId', 'error_code' => 'errorCode',
            'error_message' => 'errorMessage', 'started_at' => 'startedAt', 'completed_at' => 'completedAt', 'result' => 'result'];
        foreach ($map as $col => $key) {
            if (array_key_exists($col, $fields)) {
                $this->ops[$id][$key] = $fields[$col];
            }
        }
        $this->ops[$id]['updatedAt'] = time();
    }

    public function deleteOperation(int $id): void {
        unset($this->ops[$id], $this->steps[$id]);
    }

    public function createSteps(int $operationId, array $steps): void {
        $i = count($this->steps[$operationId] ?? []);
        foreach ($steps as $step) {
            $this->steps[$operationId][$step['key']] = [
                'id' => $this->nextStepId++, 'operationId' => $operationId, 'key' => $step['key'], 'sortIndex' => $i++,
                'status' => 'pending', 'resourceType' => $step['resourceType'] ?? null, 'externalId' => null, 'externalRef' => null,
                'attempts' => 0, 'startedAt' => null, 'completedAt' => null, 'errorCode' => null, 'errorMessage' => null,
                'retrySafe' => true, 'rollbackPossible' => !empty($step['rollbackPossible']), 'detail' => [], 'updatedAt' => time(),
            ];
        }
    }

    public function findSteps(int $operationId): array {
        return array_values($this->steps[$operationId] ?? []);
    }

    public function findStep(int $operationId, string $stepKey): ?array {
        return $this->steps[$operationId][$stepKey] ?? null;
    }

    public function updateStep(int $operationId, string $stepKey, array $fields): void {
        $row = &$this->steps[$operationId][$stepKey];
        $map = ['status' => 'status', 'external_id' => 'externalId', 'external_ref' => 'externalRef', 'error_code' => 'errorCode',
            'error_message' => 'errorMessage', 'started_at' => 'startedAt', 'completed_at' => 'completedAt',
            'retry_safe' => 'retrySafe', 'rollback_possible' => 'rollbackPossible', 'detail' => 'detail'];
        foreach ($map as $col => $key) {
            if (array_key_exists($col, $fields)) {
                $row[$key] = $fields[$col];
            }
        }
        if (!empty($fields['bumpAttempts'])) {
            $row['attempts']++;
        }
        $row['updatedAt'] = time();
    }
}
