<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Data-access layer for teamhub_team_adoption (v4.10.50, DESIGN §2.149).
 *
 * One row per circle TeamHub found that it did not create, holding the
 * decision about it. Rows are plain arrays — the service shapes them for
 * the grid. A DI leaf: holds only IDBConnection.
 */
class TeamAdoptionMapper {

    public const TABLE = 'teamhub_team_adoption';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACCEPTED  = 'accepted';
    public const STATUS_DECLINED  = 'declined';
    public const STATUS_WITHDRAWN = 'withdrawn';

    /** Accepted by itself: the instance has no team creator group. */
    public const ROUTE_AUTO  = 'auto';
    /** A request in the queue of the team that holds the Nextcloud services. */
    public const ROUTE_DESK  = 'desk';
    /** A task in the Nextcloud administrators' My Work. */
    public const ROUTE_ADMIN = 'admin';
    /** Only the grid (and a notification): no My Work on this instance. */
    public const ROUTE_GRID  = 'grid';

    public const PROVISION_NONE   = 'none';
    public const PROVISION_QUEUED = 'queued';
    public const PROVISION_DONE   = 'done';
    public const PROVISION_FAILED = 'failed';

    private const COLUMNS = [
        'id', 'team_id', 'team_name', 'owner_uid', 'status', 'route', 'workflow_id',
        'template_key', 'profile_key', 'decided_by', 'decided_at', 'reason',
        'provision_status', 'provision_note', 'detected_at',
    ];

    public function __construct(
        private IDBConnection $db,
    ) {}

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select(...self::COLUMNS)
            ->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        return $row === false ? null : $this->shape($row);
    }

    /** @return array<string,mixed>|null */
    public function findByTeam(string $teamId): ?array {
        if ($teamId === '') {
            return null;
        }
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select(...self::COLUMNS)
            ->from(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        return $row === false ? null : $this->shape($row);
    }

    /** @return array<string,mixed>|null */
    public function findByWorkflow(int $workflowId): ?array {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select(...self::COLUMNS)
            ->from(self::TABLE)
            ->where($qb->expr()->eq('workflow_id', $qb->createNamedParameter($workflowId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        return $row === false ? null : $this->shape($row);
    }

    /**
     * Every pending row, oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public function findPending(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(...self::COLUMNS)
            ->from(self::TABLE)
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_PENDING)))
            ->orderBy('detected_at', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->all($qb);
    }

    /**
     * Rows decided since `$since`, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function findDecidedSince(int $since, int $limit = 200): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(...self::COLUMNS)
            ->from(self::TABLE)
            ->where($qb->expr()->neq('status', $qb->createNamedParameter(self::STATUS_PENDING)))
            ->andWhere($qb->expr()->gte('decided_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
            ->orderBy('decided_at', 'DESC')
            ->setMaxResults($limit);
        return $this->all($qb);
    }

    /**
     * Rows whose provisioning job is queued. The provisioning job reads one
     * of these at a time; this is for the sweep's retry of a lost job.
     *
     * @return list<array<string,mixed>>
     */
    public function findQueuedBefore(int $before): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(...self::COLUMNS)
            ->from(self::TABLE)
            ->where($qb->expr()->eq('provision_status', $qb->createNamedParameter(self::PROVISION_QUEUED)))
            ->andWhere($qb->expr()->lt('decided_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)));
        return $this->all($qb);
    }

    /**
     * Every team id that has a row, whatever its status. The sweep skips
     * them: one decision per team, and a declined one is final.
     *
     * @return array<string,true>
     */
    public function allTeamIds(): array {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('team_id')->from(self::TABLE)->executeQuery();
        $ids = [];
        while ($row = $res->fetch()) {
            $ids[(string)$row['team_id']] = true;
        }
        $res->closeCursor();
        return $ids;
    }

    // ------------------------------------------------------------------
    // Write
    // ------------------------------------------------------------------

    /**
     * Record a newly found team. Returns the row id, or null when the team
     * already has a row (the unique index is the lock against two sweeps).
     */
    public function insert(string $teamId, string $teamName, string $ownerUid, string $route, int $now): ?int {
        if ($teamId === '' || $this->findByTeam($teamId) !== null) {
            return null;
        }
        $qb = $this->db->getQueryBuilder();
        try {
            $qb->insert(self::TABLE)
                ->values([
                    'team_id'     => $qb->createNamedParameter($teamId),
                    'team_name'   => $qb->createNamedParameter(mb_substr($teamName, 0, 255)),
                    'owner_uid'   => $qb->createNamedParameter($ownerUid),
                    'status'      => $qb->createNamedParameter(self::STATUS_PENDING),
                    'route'       => $qb->createNamedParameter($route),
                    'detected_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                ])
                ->executeStatement();
        } catch (\OCP\DB\Exception $e) {
            if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                return null;
            }
            throw $e;
        }
        return $qb->getLastInsertId();
    }

    /**
     * Update named columns of one row.
     *
     * @param array<string,string|int|null> $fields
     */
    public function update(int $id, array $fields): void {
        if ($fields === []) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE);
        foreach ($fields as $column => $value) {
            if (!in_array($column, self::COLUMNS, true) || $column === 'id' || $column === 'team_id') {
                throw new \InvalidArgumentException('Not an updatable column: ' . $column);
            }
            if ($value === null) {
                $qb->set($column, $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
            } elseif (is_int($value)) {
                $qb->set($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT));
            } else {
                $qb->set($column, $qb->createNamedParameter($value));
            }
        }
        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * Move a row from pending to a decision — only if it is still pending.
     * Returns false when somebody else decided first; the caller reports a
     * conflict instead of deciding twice.
     *
     * @param array<string,string|int|null> $fields
     */
    public function decideIfPending(int $id, string $status, array $fields): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('status', $qb->createNamedParameter($status));
        foreach ($fields as $column => $value) {
            if (!in_array($column, self::COLUMNS, true) || in_array($column, ['id', 'team_id', 'status'], true)) {
                throw new \InvalidArgumentException('Not an updatable column: ' . $column);
            }
            if ($value === null) {
                $qb->set($column, $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
            } elseif (is_int($value)) {
                $qb->set($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT));
            } else {
                $qb->set($column, $qb->createNamedParameter($value));
            }
        }
        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_PENDING)));
        return $qb->executeStatement() === 1;
    }

    // ------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function all(IQueryBuilder $qb): array {
        $res  = $qb->executeQuery();
        $rows = [];
        while ($row = $res->fetch()) {
            $rows[] = $this->shape($row);
        }
        $res->closeCursor();
        return $rows;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function shape(array $row): array {
        return [
            'id'              => (int)$row['id'],
            'teamId'          => (string)$row['team_id'],
            'teamName'        => (string)($row['team_name'] ?? ''),
            'ownerUid'        => (string)($row['owner_uid'] ?? ''),
            'status'          => (string)$row['status'],
            'route'           => (string)$row['route'],
            'workflowId'      => $row['workflow_id'] === null ? null : (int)$row['workflow_id'],
            'templateKey'     => (string)($row['template_key'] ?? ''),
            'profileKey'      => (string)($row['profile_key'] ?? ''),
            'decidedBy'       => (string)($row['decided_by'] ?? ''),
            'decidedAt'       => $row['decided_at'] === null ? null : (int)$row['decided_at'],
            'reason'          => (string)($row['reason'] ?? ''),
            'provisionStatus' => (string)($row['provision_status'] ?? self::PROVISION_NONE),
            'provisionNote'   => (string)($row['provision_note'] ?? ''),
            'detectedAt'      => (int)($row['detected_at'] ?? 0),
        ];
    }
}
