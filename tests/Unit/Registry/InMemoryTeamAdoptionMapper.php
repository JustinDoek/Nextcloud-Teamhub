<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Registry;

use OCA\TeamHub\Db\TeamAdoptionMapper;
use OCP\IDBConnection;

/**
 * teamhub_team_adoption in memory (v4.10.50), with the same shaped rows
 * and the same "only while pending" rule for a decision.
 */
class InMemoryTeamAdoptionMapper extends TeamAdoptionMapper {

    /** @var array<int, array<string,mixed>> */
    public array $rows = [];
    private int $next = 1;

    private const COLUMN_TO_KEY = [
        'team_name' => 'teamName', 'owner_uid' => 'ownerUid', 'status' => 'status', 'route' => 'route',
        'workflow_id' => 'workflowId', 'template_key' => 'templateKey', 'profile_key' => 'profileKey',
        'decided_by' => 'decidedBy', 'decided_at' => 'decidedAt', 'reason' => 'reason',
        'provision_status' => 'provisionStatus', 'provision_note' => 'provisionNote', 'detected_at' => 'detectedAt',
    ];

    public function __construct(IDBConnection $db) {
        parent::__construct($db);
    }

    public function find(int $id): ?array {
        return $this->rows[$id] ?? null;
    }

    public function findByTeam(string $teamId): ?array {
        foreach ($this->rows as $row) {
            if ($row['teamId'] === $teamId) {
                return $row;
            }
        }
        return null;
    }

    public function findByWorkflow(int $workflowId): ?array {
        foreach ($this->rows as $row) {
            if ($row['workflowId'] === $workflowId) {
                return $row;
            }
        }
        return null;
    }

    public function findPending(): array {
        return array_values(array_filter($this->rows, static fn (array $r): bool => $r['status'] === self::STATUS_PENDING));
    }

    public function findDecidedSince(int $since, int $limit = 200): array {
        return array_values(array_filter(
            $this->rows,
            static fn (array $r): bool => $r['status'] !== self::STATUS_PENDING && (int)$r['decidedAt'] >= $since,
        ));
    }

    public function findQueuedBefore(int $before): array {
        return array_values(array_filter(
            $this->rows,
            static fn (array $r): bool => $r['provisionStatus'] === self::PROVISION_QUEUED && (int)$r['decidedAt'] < $before,
        ));
    }

    public function allTeamIds(): array {
        $ids = [];
        foreach ($this->rows as $row) {
            $ids[$row['teamId']] = true;
        }
        return $ids;
    }

    public function insert(string $teamId, string $teamName, string $ownerUid, string $route, int $now): ?int {
        if ($teamId === '' || $this->findByTeam($teamId) !== null) {
            return null;
        }
        $id = $this->next++;
        $this->rows[$id] = [
            'id' => $id, 'teamId' => $teamId, 'teamName' => $teamName, 'ownerUid' => $ownerUid,
            'status' => self::STATUS_PENDING, 'route' => $route, 'workflowId' => null,
            'templateKey' => '', 'profileKey' => '', 'decidedBy' => '', 'decidedAt' => null, 'reason' => '',
            'provisionStatus' => self::PROVISION_NONE, 'provisionNote' => '', 'detectedAt' => $now,
        ];
        return $id;
    }

    public function update(int $id, array $fields): void {
        foreach ($fields as $column => $value) {
            $this->rows[$id][self::COLUMN_TO_KEY[$column]] = $value;
        }
    }

    public function decideIfPending(int $id, string $status, array $fields): bool {
        if (($this->rows[$id]['status'] ?? null) !== self::STATUS_PENDING) {
            return false;
        }
        $this->rows[$id]['status'] = $status;
        $this->update($id, $fields);
        return true;
    }
}
