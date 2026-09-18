<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Raw QueryBuilder mapper for the two provisioning tables (v4.9.6, OpenProject
 * Phase 2): `teamhub_provisioning` (one row per operation) and
 * `teamhub_provisioning_step` (one row per step of it).
 *
 * Same shape as {@see TeamImportMapper}: no QBMapper entities, plain arrays
 * out, every value bound through `createNamedParameter()`. The rows are the
 * durable state of one workspace creation — what the wizard polls, what the
 * team page reads to say "not finished yet", what an administrator retries.
 *
 * ## The lease
 *
 * `claim()` takes the operation for one runner with a conditional UPDATE:
 * either the lock is free, or it is older than the stale window (a runner
 * that died holding it). Two pumps racing — the wizard's tab and the
 * background job that decided it was abandoned — cannot both win, so a step
 * never executes twice at the same time. `release()` gives it back under the
 * same token, so a runner cannot release somebody else's lease.
 */
class ProvisioningMapper {

    private const OPS   = 'teamhub_provisioning';
    private const STEPS = 'teamhub_provisioning_step';

    public function __construct(private IDBConnection $db) {}

    // -------------------------------------------------------------------------
    // Operations
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $request the sanitised request
     * @return int the new operation's id
     */
    public function createOperation(
        string $createdBy,
        string $idempotencyKey,
        string $templateKey,
        string $mode,
        array  $request,
    ): int {
        $now = time();
        $qb  = $this->db->getQueryBuilder();
        $qb->insert(self::OPS)->values([
            'team_id'         => $qb->createNamedParameter(null),
            'template_key'    => $qb->createNamedParameter($templateKey),
            'mode'            => $qb->createNamedParameter($mode),
            'status'          => $qb->createNamedParameter('pending'),
            'current_step'    => $qb->createNamedParameter(null),
            'idempotency_key' => $qb->createNamedParameter(mb_substr($idempotencyKey, 0, 64)),
            'created_by'      => $qb->createNamedParameter($createdBy),
            'created_at'      => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'updated_at'      => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'request_json'    => $qb->createNamedParameter(json_encode($request, JSON_UNESCAPED_UNICODE)),
        ]);
        $qb->executeStatement();

        return (int)$this->db->lastInsertId('*PREFIX*' . self::OPS);
    }

    /** @return array<string,mixed>|null */
    public function findOperation(int $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::OPS)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $this->hydrateOperation($row);
    }

    /**
     * The operation a creator already started with this key, if any — the
     * replay guard's lookup.
     *
     * @return array<string,mixed>|null
     */
    public function findByIdempotencyKey(string $createdBy, string $idempotencyKey): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::OPS)
            ->where($qb->expr()->eq('created_by', $qb->createNamedParameter($createdBy)))
            ->andWhere($qb->expr()->eq('idempotency_key', $qb->createNamedParameter(mb_substr($idempotencyKey, 0, 64))))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $this->hydrateOperation($row);
    }

    /**
     * The newest operation that produced (or is producing) this team.
     *
     * @return array<string,mixed>|null
     */
    public function findLatestByTeam(string $teamId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::OPS)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $this->hydrateOperation($row);
    }

    /**
     * The newest operation of a team, reduced to what the team page needs:
     * is provisioning finished, and if not, where it stands. Cheap enough
     * for the layout bundle (two reads); no request payload travels.
     *
     * @return ?array{id:int, status:string, currentStep:?string, complete:bool,
     *   openSteps:list<array{key:string, status:string, errorCode:?string}>, createdBy:string, updatedAt:int}
     */
    public function latestSummaryForTeam(string $teamId): ?array {
        $op = $this->findLatestByTeam($teamId);
        if ($op === null) {
            return null;
        }
        $open = [];
        foreach ($this->findSteps($op['id']) as $s) {
            if (in_array($s['status'], ['failed', 'attention'], true)) {
                $open[] = ['key' => $s['key'], 'status' => $s['status'], 'errorCode' => $s['errorCode']];
            }
        }
        return [
            'id'          => $op['id'],
            'status'      => $op['status'],
            'currentStep' => $op['currentStep'],
            'complete'    => $op['status'] === 'completed',
            'openSteps'   => $open,
            'createdBy'   => $op['createdBy'],
            'updatedAt'   => $op['updatedAt'],
        ];
    }

    /**
     * Recent operations, newest first, optionally one creator's only.
     *
     * @return list<array<string,mixed>>
     */
    public function findRecent(int $limit = 25, ?string $createdBy = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::OPS)
            ->orderBy('id', 'DESC')
            ->setMaxResults(max(1, min(100, $limit)));
        if ($createdBy !== null) {
            $qb->where($qb->expr()->eq('created_by', $qb->createNamedParameter($createdBy)));
        }
        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = $this->hydrateOperation($row);
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Operations that are `running` but whose heartbeat is older than
     * `$olderThan` — the ones a background job may adopt.
     *
     * @return list<array<string,mixed>>
     */
    public function findStalledRunning(int $olderThan, int $limit = 5): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::OPS)
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('running')))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('heartbeat_at'),
                $qb->expr()->lt('heartbeat_at', $qb->createNamedParameter($olderThan, IQueryBuilder::PARAM_INT)),
            ))
            ->orderBy('id', 'ASC')
            ->setMaxResults(max(1, $limit));
        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = $this->hydrateOperation($row);
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Ids of terminal operations that ended before `$before`, for pruning.
     *
     * @return list<int>
     */
    public function findFinishedBefore(int $before, int $limit = 50): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from(self::OPS)
            ->where($qb->expr()->in('status', $qb->createNamedParameter(
                ['completed', 'rolled_back', 'cancelled'],
                IQueryBuilder::PARAM_STR_ARRAY,
            )))
            ->andWhere($qb->expr()->isNotNull('completed_at'))
            ->andWhere($qb->expr()->lt('completed_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(max(1, $limit));
        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = (int)$row['id'];
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Take the lease. Succeeds when the lock is free or stale.
     *
     * @return bool whether this caller holds the lease now
     */
    public function claim(int $id, string $token, int $staleBefore): bool {
        $now = time();
        $qb  = $this->db->getQueryBuilder();
        $affected = $qb->update(self::OPS)
            ->set('lock_token',   $qb->createNamedParameter($token))
            ->set('locked_at',    $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->set('heartbeat_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('lock_token'),
                $qb->expr()->isNull('locked_at'),
                $qb->expr()->lt('locked_at', $qb->createNamedParameter($staleBefore, IQueryBuilder::PARAM_INT)),
            ))
            ->executeStatement();

        return $affected > 0;
    }

    /** Give the lease back — only under the token that took it. */
    public function release(int $id, string $token): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::OPS)
            ->set('lock_token', $qb->createNamedParameter(null))
            ->set('locked_at',  $qb->createNamedParameter(null))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('lock_token', $qb->createNamedParameter($token)))
            ->executeStatement();
    }

    public function touchHeartbeat(int $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::OPS)
            ->set('heartbeat_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * Update the operation's summary fields. Only the keys given are written.
     *
     * @param array<string,mixed> $fields status | current_step | team_id |
     *        started_at | completed_at | error_code | error_message | result
     */
    public function updateOperation(int $id, array $fields): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::OPS)
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        foreach (['status', 'current_step', 'team_id', 'error_code'] as $col) {
            if (array_key_exists($col, $fields)) {
                $qb->set($col, $qb->createNamedParameter($fields[$col] === null ? null : (string)$fields[$col]));
            }
        }
        if (array_key_exists('error_message', $fields)) {
            $msg = $fields['error_message'];
            $qb->set('error_message', $qb->createNamedParameter($msg === null ? null : mb_substr((string)$msg, 0, 1024)));
        }
        foreach (['started_at', 'completed_at'] as $col) {
            if (array_key_exists($col, $fields)) {
                $qb->set($col, $qb->createNamedParameter(
                    $fields[$col] === null ? null : (int)$fields[$col],
                    $fields[$col] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT,
                ));
            }
        }
        if (array_key_exists('result', $fields)) {
            $qb->set('result_json', $qb->createNamedParameter(
                $fields['result'] === null ? null : json_encode($fields['result'], JSON_UNESCAPED_UNICODE),
            ));
        }
        $qb->executeStatement();
    }

    /** Deletes the operation and its steps. Steps first, so nothing is orphaned. */
    public function deleteOperation(int $id): void {
        $s = $this->db->getQueryBuilder();
        $s->delete(self::STEPS)
            ->where($s->expr()->eq('operation_id', $s->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        $o = $this->db->getQueryBuilder();
        $o->delete(self::OPS)
            ->where($o->expr()->eq('id', $o->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    // -------------------------------------------------------------------------
    // Steps
    // -------------------------------------------------------------------------

    /**
     * Create the step rows of a new operation, all `pending`, in order.
     *
     * @param list<array{key: string, resourceType: ?string, rollbackPossible: bool}> $steps
     */
    public function createSteps(int $operationId, array $steps): void {
        $now = time();
        foreach (array_values($steps) as $i => $step) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert(self::STEPS)->values([
                'operation_id'      => $qb->createNamedParameter($operationId, IQueryBuilder::PARAM_INT),
                'step_key'          => $qb->createNamedParameter($step['key']),
                'sort_index'        => $qb->createNamedParameter($i, IQueryBuilder::PARAM_INT),
                'status'            => $qb->createNamedParameter('pending'),
                'resource_type'     => $qb->createNamedParameter($step['resourceType'] ?? null),
                'attempts'          => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                'retry_safe'        => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                'rollback_possible' => $qb->createNamedParameter(!empty($step['rollbackPossible']) ? 1 : 0, IQueryBuilder::PARAM_INT),
                'updated_at'        => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ]);
            $qb->executeStatement();
        }
    }

    /**
     * Every step of an operation, in order.
     *
     * @return list<array<string,mixed>>
     */
    public function findSteps(int $operationId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::STEPS)
            ->where($qb->expr()->eq('operation_id', $qb->createNamedParameter($operationId, IQueryBuilder::PARAM_INT)))
            ->orderBy('sort_index', 'ASC');
        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = $this->hydrateStep($row);
        }
        $result->closeCursor();

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function findStep(int $operationId, string $stepKey): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::STEPS)
            ->where($qb->expr()->eq('operation_id', $qb->createNamedParameter($operationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('step_key', $qb->createNamedParameter($stepKey)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $this->hydrateStep($row);
    }

    /**
     * Update one step. Only the keys given are written; `attempts` is
     * incremented when `bumpAttempts` is set rather than assigned, so two
     * runners cannot lose a count.
     *
     * @param array<string,mixed> $fields status | external_id | external_ref |
     *        started_at | completed_at | error_code | error_message |
     *        retry_safe | rollback_possible | detail | bumpAttempts
     */
    public function updateStep(int $operationId, string $stepKey, array $fields): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::STEPS)
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('operation_id', $qb->createNamedParameter($operationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('step_key', $qb->createNamedParameter($stepKey)));

        foreach (['status', 'external_id', 'external_ref', 'error_code'] as $col) {
            if (array_key_exists($col, $fields)) {
                $qb->set($col, $qb->createNamedParameter(
                    $fields[$col] === null ? null : mb_substr((string)$fields[$col], 0, 255),
                ));
            }
        }
        if (array_key_exists('error_message', $fields)) {
            $msg = $fields['error_message'];
            $qb->set('error_message', $qb->createNamedParameter($msg === null ? null : mb_substr((string)$msg, 0, 1024)));
        }
        foreach (['started_at', 'completed_at'] as $col) {
            if (array_key_exists($col, $fields)) {
                $qb->set($col, $qb->createNamedParameter(
                    $fields[$col] === null ? null : (int)$fields[$col],
                    $fields[$col] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT,
                ));
            }
        }
        foreach (['retry_safe', 'rollback_possible'] as $col) {
            if (array_key_exists($col, $fields)) {
                $qb->set($col, $qb->createNamedParameter(!empty($fields[$col]) ? 1 : 0, IQueryBuilder::PARAM_INT));
            }
        }
        if (array_key_exists('detail', $fields)) {
            $qb->set('detail_json', $qb->createNamedParameter(
                $fields['detail'] === null ? null : json_encode($fields['detail'], JSON_UNESCAPED_UNICODE),
            ));
        }
        if (!empty($fields['bumpAttempts'])) {
            $qb->set('attempts', $qb->func()->add('attempts', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
        }
        $qb->executeStatement();
    }

    // -------------------------------------------------------------------------
    // Hydration
    // -------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function hydrateOperation(array $row): array {
        $request = json_decode((string)($row['request_json'] ?? ''), true);
        $result  = json_decode((string)($row['result_json'] ?? ''), true);

        return [
            'id'             => (int)$row['id'],
            'teamId'         => $row['team_id'] !== null ? (string)$row['team_id'] : null,
            'templateKey'    => (string)$row['template_key'],
            'mode'           => (string)$row['mode'],
            'status'         => (string)$row['status'],
            'currentStep'    => $row['current_step'] !== null ? (string)$row['current_step'] : null,
            'idempotencyKey' => (string)$row['idempotency_key'],
            'createdBy'      => (string)$row['created_by'],
            'createdAt'      => (int)$row['created_at'],
            'updatedAt'      => (int)$row['updated_at'],
            'startedAt'      => $row['started_at'] !== null ? (int)$row['started_at'] : null,
            'completedAt'    => $row['completed_at'] !== null ? (int)$row['completed_at'] : null,
            'heartbeatAt'    => $row['heartbeat_at'] !== null ? (int)$row['heartbeat_at'] : null,
            'lockToken'      => $row['lock_token'] !== null ? (string)$row['lock_token'] : null,
            'lockedAt'       => $row['locked_at'] !== null ? (int)$row['locked_at'] : null,
            'request'        => is_array($request) ? $request : [],
            'result'         => is_array($result) ? $result : null,
            'errorCode'      => $row['error_code'] !== null ? (string)$row['error_code'] : null,
            'errorMessage'   => $row['error_message'] !== null ? (string)$row['error_message'] : null,
        ];
    }

    /** @return array<string,mixed> */
    private function hydrateStep(array $row): array {
        $detail = json_decode((string)($row['detail_json'] ?? ''), true);

        return [
            'id'               => (int)$row['id'],
            'operationId'      => (int)$row['operation_id'],
            'key'              => (string)$row['step_key'],
            'sortIndex'        => (int)$row['sort_index'],
            'status'           => (string)$row['status'],
            'resourceType'     => $row['resource_type'] !== null ? (string)$row['resource_type'] : null,
            'externalId'       => $row['external_id'] !== null ? (string)$row['external_id'] : null,
            'externalRef'      => $row['external_ref'] !== null ? (string)$row['external_ref'] : null,
            'attempts'         => (int)$row['attempts'],
            'startedAt'        => $row['started_at'] !== null ? (int)$row['started_at'] : null,
            'completedAt'      => $row['completed_at'] !== null ? (int)$row['completed_at'] : null,
            'errorCode'        => $row['error_code'] !== null ? (string)$row['error_code'] : null,
            'errorMessage'     => $row['error_message'] !== null ? (string)$row['error_message'] : null,
            'retrySafe'        => (int)$row['retry_safe'] === 1,
            'rollbackPossible' => (int)$row['rollback_possible'] === 1,
            'detail'           => is_array($detail) ? $detail : [],
            'updatedAt'        => (int)$row['updated_at'],
        ];
    }
}
