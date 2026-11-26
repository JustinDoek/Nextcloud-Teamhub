<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * `teamhub_reactions` (v4.20.27): one row per person per emoji per target.
 *
 * Plain arrays in and out, like the other message-side mappers. The column
 * set is in `Version000420027Date20261118120000`.
 */
class ReactionMapper {

    private const TABLE = 'teamhub_reactions';

    public function __construct(
        private IDBConnection $db,
    ) {}

    /**
     * Insert one reaction. Returns false when the same person already gave
     * the same emoji to the same target (the unique index refused it), which
     * the caller treats as success: the state it asked for is the state.
     */
    public function insert(string $teamId, string $targetType, int $targetId, int $messageId, string $userId, string $emoji): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->values([
                'team_id'     => $qb->createNamedParameter($teamId),
                'target_type' => $qb->createNamedParameter($targetType),
                'target_id'   => $qb->createNamedParameter($targetId, IQueryBuilder::PARAM_INT),
                'message_id'  => $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT),
                'user_id'     => $qb->createNamedParameter($userId),
                'emoji'       => $qb->createNamedParameter($emoji),
                'created_at'  => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            ]);
        try {
            $qb->executeStatement();
            return true;
        } catch (DbException $e) {
            if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                return false;
            }
            throw $e;
        }
    }

    public function deleteOne(string $targetType, int $targetId, string $userId, string $emoji): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('target_type', $qb->createNamedParameter($targetType)))
            ->andWhere($qb->expr()->eq('target_id', $qb->createNamedParameter($targetId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('emoji', $qb->createNamedParameter($emoji)))
            ->executeStatement();
    }

    /**
     * Every reaction on the given targets, oldest first.
     *
     * @param int[] $targetIds
     * @return list<array{id:int, target_id:int, user_id:string, emoji:string}>
     */
    public function findForTargets(string $targetType, array $targetIds): array {
        $targetIds = array_values(array_unique(array_filter(array_map('intval', $targetIds), static fn (int $id): bool => $id > 0)));
        if ($targetIds === []) {
            return [];
        }
        $out = [];
        // Postgres and Oracle cap a bound list; a page never comes near it,
        // but chunking keeps the method honest for any caller.
        foreach (array_chunk($targetIds, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $res = $qb->select('target_id', 'user_id', 'emoji', 'id')
                ->from(self::TABLE)
                ->where($qb->expr()->eq('target_type', $qb->createNamedParameter($targetType)))
                ->andWhere($qb->expr()->in('target_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->orderBy('id', 'ASC')
                ->executeQuery();
            while ($row = $res->fetch()) {
                $out[] = [
                    'id'        => (int)$row['id'],
                    'target_id' => (int)$row['target_id'],
                    'user_id'   => (string)$row['user_id'],
                    'emoji'     => (string)$row['emoji'],
                ];
            }
            $res->closeCursor();
        }
        usort($out, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        return $out;
    }

    /** How many distinct emojis a target carries, and how many of them are `$userId`'s. */
    public function countsFor(string $targetType, int $targetId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $res = $qb->select('user_id', 'emoji')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('target_type', $qb->createNamedParameter($targetType)))
            ->andWhere($qb->expr()->eq('target_id', $qb->createNamedParameter($targetId, IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        $emojis = [];
        $mine   = 0;
        while ($row = $res->fetch()) {
            $emojis[(string)$row['emoji']] = true;
            if ((string)$row['user_id'] === $userId) {
                $mine++;
            }
        }
        $res->closeCursor();
        return ['distinct' => count($emojis), 'mine' => $mine, 'emojis' => array_keys($emojis)];
    }

    /** A message and every comment under it. */
    public function deleteByMessageId(int $messageId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    public function deleteByTarget(string $targetType, int $targetId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('target_type', $qb->createNamedParameter($targetType)))
            ->andWhere($qb->expr()->eq('target_id', $qb->createNamedParameter($targetId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }
}
