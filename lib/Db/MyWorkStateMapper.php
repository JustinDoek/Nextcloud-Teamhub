<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_mywork_state (v4.5.21).
 *
 * @extends QBMapper<MyWorkState>
 */
class MyWorkStateMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_mywork_state', MyWorkState::class);
    }

    /**
     * Every state row for a user, keyed by "{providerId}:{itemId}" so the
     * merge step is an array lookup rather than a query per item.
     *
     * One query per My Work render. The table is per-user and only grows when
     * someone snoozes something, so this stays small in practice; if an
     * install ever proves otherwise, the fix is to pass the fetched item
     * ids down as an IN() filter — the call site already has them.
     *
     * @return array<string, MyWorkState>
     */
    public function findAllForUserKeyed(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $out = [];
        foreach ($this->findEntities($qb) as $row) {
            $out[$row->getProviderId() . ':' . $row->getItemId()] = $row;
        }
        return $out;
    }

    public function findOne(string $userId, string $providerId, string $itemId): ?MyWorkState {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id',     $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('provider_id', $qb->createNamedParameter($providerId)))
            ->andWhere($qb->expr()->eq('item_id',     $qb->createNamedParameter($itemId)))
            ->setMaxResults(1);

        $rows = $this->findEntities($qb);
        return $rows[0] ?? null;
    }

    /**
     * Insert-or-update the state row for one item.
     *
     * Deliberately a read-then-write rather than an upsert: NC's
     * `IDBConnection::insertOrUpdate` exists but its behaviour across
     * MySQL/MariaDB and Postgres has historically differed on which columns
     * participate in the conflict target, and this path is not hot enough to
     * justify the risk. The unique index on (user_id, provider_id, item_id)
     * means a lost race throws rather than duplicating; callers treat that as
     * a normal failure.
     */
    public function upsert(
        string $userId,
        string $providerId,
        string $itemId,
        string $teamId,
        ?int $snoozeUntil = null,
    ): MyWorkState {
        $now      = time();
        $existing = $this->findOne($userId, $providerId, $itemId);

        if ($existing !== null) {
            if ($snoozeUntil !== null) {
                $existing->setSnoozeUntil(max(0, $snoozeUntil));
            }
            if ($teamId !== '') {
                $existing->setTeamId($teamId);
            }
            $existing->setUpdatedAt($now);
            return $this->update($existing);
        }

        $entity = new MyWorkState();
        $entity->setUserId($userId);
        $entity->setProviderId($providerId);
        $entity->setItemId($itemId);
        $entity->setTeamId($teamId);
        $entity->setSnoozeUntil(max(0, $snoozeUntil ?? 0));
        $entity->setCreatedAt($now);
        $entity->setUpdatedAt($now);

        return $this->insert($entity);
    }

    /**
     * Drop every row that is not currently snoozing something, so the table
     * does not accumulate one row per snooze forever. Called from the daily
     * maintenance job.
     *
     * Since v4.5.40 a snooze deadline is the *only* thing a row carries, so
     * "not in the future" and "says nothing" are the same condition — this
     * collects both expired snoozes and the `snooze_until = 0` rows an
     * explicit unsnooze leaves behind. Before 4.5.40 the second kind had to be
     * kept because it might also have carried a follow state; it no longer
     * can. A row written this second is safe: a real snooze is always set
     * ahead of `$now`.
     */
    public function purgeExpired(int $now): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->lt('snooze_until', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
    }

    /** Remove every row for a user — wired to account deletion. */
    public function deleteAllForUser(string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $qb->executeStatement();
    }
}
