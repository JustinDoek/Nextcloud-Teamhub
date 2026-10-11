<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Data-access layer for teamhub_idp_group (v4.11.32, DESIGN §2.15x).
 *
 * One row per Nextcloud group TeamHub has considered for a team, holding both
 * the decision and the provenance. Rows are plain arrays — the service shapes
 * them for the grid. A DI leaf: holds only IDBConnection.
 *
 * Why the row outlives the group it describes: the live group → team link is
 * already derivable from Circles' tables, but Circles deletes the hidden
 * group copy as soon as the group goes, taking the link with it. The row is
 * what remains to say "this team came from a directory group that no longer
 * exists" — the one moment somebody needs to decide something.
 */
class IdpGroupMapper {

    public const TABLE = 'teamhub_idp_group';

    /** Found and qualifying; waiting for an administrator (review mode). */
    public const STATUS_PENDING = 'pending';

    /** A team exists for this group. */
    public const STATUS_CREATED = 'created';

    /** An administrator said no. Never offered again. */
    public const STATUS_DECLINED = 'declined';

    /** The team exists but its group does not any more. */
    public const STATUS_ORPHANED = 'orphaned';

    /** Creation was attempted and did not finish; `note` says why. */
    public const STATUS_FAILED = 'failed';

    private const COLUMNS = [
        'id', 'group_id', 'team_id', 'status', 'backend', 'group_dn',
        'template_key', 'profile_key', 'decided_by', 'decided_at', 'note',
        'detected_at',
    ];

    public function __construct(
        private IDBConnection $db,
    ) {}

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array {
        $qb = $this->db->getQueryBuilder();
        return $this->one(
            $qb->select(...self::COLUMNS)
                ->from(self::TABLE)
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1),
        );
    }

    /** @return array<string,mixed>|null */
    public function findByGroup(string $groupId): ?array {
        if ($groupId === '') {
            return null;
        }
        $qb = $this->db->getQueryBuilder();
        return $this->one(
            $qb->select(...self::COLUMNS)
                ->from(self::TABLE)
                ->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)))
                ->setMaxResults(1),
        );
    }

    /** @return array<string,mixed>|null */
    public function findByTeam(string $teamId): ?array {
        if ($teamId === '') {
            return null;
        }
        $qb = $this->db->getQueryBuilder();
        return $this->one(
            $qb->select(...self::COLUMNS)
                ->from(self::TABLE)
                ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
                ->setMaxResults(1),
        );
    }

    /** @return list<array<string,mixed>> */
    public function findByStatus(string $status): array {
        $qb = $this->db->getQueryBuilder();
        return $this->all(
            $qb->select(...self::COLUMNS)
                ->from(self::TABLE)
                ->where($qb->expr()->eq('status', $qb->createNamedParameter($status)))
                ->orderBy('detected_at', 'ASC'),
        );
    }

    /**
     * Every group that already has a row, whatever it says.
     *
     * The sweep's skip set: a group that has been decided — created, declined
     * or orphaned — is never reconsidered. Keyed by group id for an isset()
     * test rather than a linear scan.
     *
     * @return array<string,string> group id => status
     */
    public function allGroupStatuses(): array {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('group_id', 'status')->from(self::TABLE)->executeQuery();

        $out = [];
        while ($row = $res->fetch()) {
            $gid = (string)($row['group_id'] ?? '');
            if ($gid !== '') {
                $out[$gid] = (string)($row['status'] ?? '');
            }
        }
        $res->closeCursor();
        return $out;
    }

    /**
     * Every row, whatever it says.
     *
     * What the reconcile walks since v4.11.37: a row must not outlive the
     * group it is about, and that is as true of a `pending` or `failed` row
     * with no team as of a `created` one.
     *
     * @return list<array<string,mixed>>
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        return $this->all(
            $qb->select(...self::COLUMNS)
                ->from(self::TABLE)
                ->orderBy('detected_at', 'ASC'),
        );
    }

    /**
     * Rows that have a team.
     *
     * @return list<array<string,mixed>>
     */
    public function findWithTeam(): array {
        $qb = $this->db->getQueryBuilder();
        return $this->all(
            $qb->select(...self::COLUMNS)
                ->from(self::TABLE)
                ->where($qb->expr()->neq('team_id', $qb->createNamedParameter('')))
                ->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
                    [self::STATUS_CREATED, self::STATUS_ORPHANED],
                    IQueryBuilder::PARAM_STR_ARRAY,
                ))),
        );
    }

    // ------------------------------------------------------------------
    // Write
    // ------------------------------------------------------------------

    /**
     * Record a newly found group. Returns the row id, or null when the group
     * already has a row — the unique index is the lock against two sweeps,
     * or a sweep racing the listener.
     */
    public function insert(
        string $groupId,
        string $status,
        string $backend,
        string $groupDn,
        int $now,
    ): ?int {
        if ($groupId === '' || $this->findByGroup($groupId) !== null) {
            return null;
        }
        $qb = $this->db->getQueryBuilder();
        try {
            $qb->insert(self::TABLE)
                ->values([
                    'group_id'    => $qb->createNamedParameter($groupId),
                    'team_id'     => $qb->createNamedParameter(''),
                    'status'      => $qb->createNamedParameter($status),
                    'backend'     => $qb->createNamedParameter(mb_substr($backend, 0, 64)),
                    'group_dn'    => $qb->createNamedParameter(mb_substr($groupDn, 0, 1000)),
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
     * `group_id` is not updatable: it identifies the row, and a row that
     * changed group would silently rewrite history.
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
            if (!in_array($column, self::COLUMNS, true) || in_array($column, ['id', 'group_id'], true)) {
                throw new \InvalidArgumentException('Not an updatable column: ' . $column);
            }
            $this->set($qb, $column, $value);
        }
        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * Move a row out of pending — only if it is still pending.
     *
     * Returns false when somebody else decided first, so two administrators
     * in the same grid cannot both provision the same group.
     *
     * @param array<string,string|int|null> $fields
     */
    public function decideIfPending(int $id, string $status, array $fields): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('status', $qb->createNamedParameter($status));
        foreach ($fields as $column => $value) {
            if (!in_array($column, self::COLUMNS, true) || in_array($column, ['id', 'group_id', 'status'], true)) {
                throw new \InvalidArgumentException('Not an updatable column: ' . $column);
            }
            $this->set($qb, $column, $value);
        }
        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_PENDING)));
        return $qb->executeStatement() === 1;
    }

    /**
     * Forget a group's row entirely.
     *
     * Used when a team provisioned from a group is deleted through TeamHub's
     * own path: the decision is spent, and the group becomes a fresh
     * candidate if it is still in the directory. A *declined* row is never
     * removed here — that decision is meant to stick.
     */
    public function deleteByGroup(string $groupId): void {
        if ($groupId === '') {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)))
            ->andWhere($qb->expr()->neq('status', $qb->createNamedParameter(self::STATUS_DECLINED)))
            ->executeStatement();
    }

    // ------------------------------------------------------------------

    private function set(IQueryBuilder $qb, string $column, string|int|null $value): void {
        if ($value === null) {
            $qb->set($column, $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
        } elseif (is_int($value)) {
            $qb->set($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT));
        } else {
            $qb->set($column, $qb->createNamedParameter($value));
        }
    }

    /** @return array<string,mixed>|null */
    private function one(IQueryBuilder $qb): ?array {
        $res = $qb->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function all(IQueryBuilder $qb): array {
        $res  = $qb->executeQuery();
        $rows = [];
        while ($row = $res->fetch()) {
            $rows[] = $row;
        }
        $res->closeCursor();
        return $rows;
    }
}
