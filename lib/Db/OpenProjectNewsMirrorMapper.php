<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The news-mirror ledger, `teamhub_op_news_mirror` (v4.9.7, Phase 3).
 *
 * One row per (team, OpenProject connection, news item) that has been
 * copied into the team's message stream. Plain arrays rather than an
 * entity: two reads and one insert, none of which needs a model.
 *
 * Every read is narrowed by a team id the caller has already been
 * authorised for; there is no "all mirrors" query.
 */
class OpenProjectNewsMirrorMapper {

    public const TABLE = 'teamhub_op_news_mirror';

    public function __construct(private IDBConnection $db) {
    }

    /**
     * Which of `$newsIds` are already mirrored for this team and connection.
     *
     * @param int[] $newsIds
     * @return int[]
     */
    public function mirroredIds(string $teamId, string $connection, array $newsIds): array {
        $newsIds = array_values(array_unique(array_filter(array_map('intval', $newsIds), static fn (int $id): bool => $id > 0)));
        if ($newsIds === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('news_id')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('connection', $qb->createNamedParameter($connection)))
            ->andWhere($qb->expr()->in('news_id', $qb->createNamedParameter($newsIds, IQueryBuilder::PARAM_INT_ARRAY)));
        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = (int)$row['news_id'];
        }
        $result->closeCursor();
        return $out;
    }

    /**
     * Claim the slot for one news item before the message is written.
     * Returns the ledger row id, or null — without throwing — when the
     * unique index says somebody claimed it first, so two members loading
     * at the same moment can never produce two messages. `message_id` is 0
     * until `attachMessage()`.
     */
    public function claim(string $teamId, string $connection, int $newsId, string $mirroredBy): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->values([
                'team_id'     => $qb->createNamedParameter($teamId),
                'connection'  => $qb->createNamedParameter($connection),
                'news_id'     => $qb->createNamedParameter($newsId, IQueryBuilder::PARAM_INT),
                'message_id'  => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                'mirrored_by' => $qb->createNamedParameter($mirroredBy),
                'created_at'  => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            ]);
        try {
            $qb->executeStatement();
        } catch (DBException $e) {
            if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                return null;
            }
            throw $e;
        }
        return (int)$qb->getLastInsertId();
    }

    /** The message is written: remember which one. */
    public function attachMessage(int $ledgerId, int $messageId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($ledgerId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /** The message could not be written: give the slot back. */
    public function release(int $ledgerId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($ledgerId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * The news items behind a page of messages, keyed by message id — what
     * the stream needs to say "Source: OpenProject" on a mirrored post and
     * link it to the item (v4.9.9, Justin's review of 2026-09-14). The
     * caller has already read these messages, so a row it may not see
     * cannot come back from here; the ledger adds nothing but ids.
     *
     * @param int[] $messageIds
     * @return array<int, array{newsId: int, connection: string}>
     */
    public function findByMessageIds(array $messageIds): array {
        $messageIds = array_values(array_unique(array_filter(array_map('intval', $messageIds), static fn (int $id): bool => $id > 0)));
        if ($messageIds === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('message_id', 'news_id', 'connection')
            ->from(self::TABLE)
            ->where($qb->expr()->in('message_id', $qb->createNamedParameter($messageIds, IQueryBuilder::PARAM_INT_ARRAY)));
        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[(int)$row['message_id']] = ['newsId' => (int)$row['news_id'], 'connection' => (string)$row['connection']];
        }
        $result->closeCursor();
        return $out;
    }

    /** Part of the team-delete cascade. */
    public function deleteByTeam(string $teamId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
        return $qb->executeStatement();
    }
}
