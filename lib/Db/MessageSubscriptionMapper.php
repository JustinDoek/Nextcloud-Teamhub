<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Per-message comment-notification subscription overrides (v4.8.7, GitHub #95).
 *
 * Every method here speaks in **overrides**. A `null` from a read means "no
 * row", which is not the same as "not subscribed" — the default that applies
 * in that case depends on whether the reader authored the message, and
 * resolving that is MessageSubscriptionService's job, not this mapper's.
 * Keeping the distinction in the return type is what stops a caller from
 * reading a missing row as an unsubscribe.
 */
class MessageSubscriptionMapper {
    public function __construct(
        private IDBConnection $db,
    ) {
    }

    /**
     * The override this user has set on this message, if any.
     *
     * @return bool|null true = explicitly subscribed, false = explicitly
     *                   unsubscribed, null = no override recorded.
     */
    public function findOverride(int $messageId, string $userId): ?bool {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('subscribed')
            ->from('teamhub_msg_subscription')
            ->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if ($row === false || $row === null) {
            return null;
        }
        return (int)$row['subscribed'] === 1;
    }

    /**
     * One viewer's overrides across many messages, in a single query.
     *
     * This is what keeps the stream's subscription state off the per-card
     * request path: a page of messages costs one query here, not one per
     * card. An empty id list short-circuits rather than emitting a query
     * with an empty IN clause, which is a syntax error on Postgres.
     *
     * @param int[] $messageIds
     * @return array<int,bool> message id => explicit state. Messages with no
     *                         override are absent from the map entirely.
     */
    public function findOverridesForMessages(array $messageIds, string $userId): array {
        $ids = array_values(array_unique(array_map('intval', $messageIds)));
        if ($ids === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('message_id', 'subscribed')
            ->from('teamhub_msg_subscription')
            ->where($qb->expr()->in('message_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->executeQuery();

        $map = [];
        while ($row = $result->fetch()) {
            $map[(int)$row['message_id']] = (int)$row['subscribed'] === 1;
        }
        $result->closeCursor();
        return $map;
    }

    /**
     * Every override recorded against one message.
     *
     * Returns both directions — the users who opted in and the ones who opted
     * out — because the recipient list needs both: the first to add, the
     * second to subtract from the author default.
     *
     * @return array<string,bool> user id => explicit state
     */
    public function findOverridesForMessage(int $messageId): array {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('user_id', 'subscribed')
            ->from('teamhub_msg_subscription')
            ->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
            ->executeQuery();

        $map = [];
        while ($row = $result->fetch()) {
            $map[(string)$row['user_id']] = (int)$row['subscribed'] === 1;
        }
        $result->closeCursor();
        return $map;
    }

    /**
     * Record an override, replacing any the user already had.
     *
     * Read-then-write rather than `IDBConnection::insertOrUpdate`, matching
     * `MyWorkStateMapper::upsert` and for the same reason recorded there: the
     * upsert helper's conflict-target behaviour has differed between
     * MySQL/MariaDB and Postgres, and this path is a human clicking a toggle,
     * nowhere near hot enough to trade correctness for a round trip. The
     * unique index means a lost race throws rather than duplicating.
     */
    public function setOverride(int $messageId, string $userId, bool $subscribed): void {
        $now      = time();
        $existing = $this->findOverride($messageId, $userId);

        if ($existing !== null) {
            $qb = $this->db->getQueryBuilder();
            $qb->update('teamhub_msg_subscription')
                ->set('subscribed', $qb->createNamedParameter($subscribed ? 1 : 0, IQueryBuilder::PARAM_INT))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->executeStatement();
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->insert('teamhub_msg_subscription')
            ->values([
                'message_id' => $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT),
                'user_id'    => $qb->createNamedParameter($userId),
                'subscribed' => $qb->createNamedParameter($subscribed ? 1 : 0, IQueryBuilder::PARAM_INT),
                'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ])
            ->executeStatement();
    }

    /**
     * Drop a user's subscriptions to one team's **non-public** messages.
     *
     * Called when somebody leaves or is removed from a team. Their
     * subscriptions to that team's ordinary messages are dead the moment they
     * lose membership — they can no longer open those threads — so the rows
     * are deleted rather than left to be filtered out on every send.
     *
     * Public messages are deliberately excluded. A public message and its
     * comment thread stay readable to everyone after 4.8.7, so a subscription
     * to one is still meaningful to somebody who is no longer in the team, and
     * silently dropping it would unsubscribe them from something they can
     * still read.
     *
     * Two queries rather than one `DELETE … WHERE message_id IN (SELECT …)`:
     * that form is where MySQL's "can't specify target table" restriction and
     * Postgres's `USING` syntax diverge, and the id list here is bounded by
     * this one user's subscriptions rather than by the team's message count.
     *
     * @return int Rows deleted.
     */
    public function deleteForUserInTeam(string $teamId, string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('s.message_id')
            ->from('teamhub_msg_subscription', 's')
            ->innerJoin('s', 'teamhub_messages', 'm', $qb->expr()->eq('s.message_id', 'm.id'))
            ->where($qb->expr()->eq('s.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('m.team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('m.is_public', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();

        $ids = array_map(static fn ($r) => (int)$r['message_id'], $rows);
        if ($ids === []) {
            return 0;
        }

        $qb2 = $this->db->getQueryBuilder();
        return (int)$qb2->delete('teamhub_msg_subscription')
            ->where($qb2->expr()->eq('user_id', $qb2->createNamedParameter($userId)))
            ->andWhere($qb2->expr()->in(
                'message_id',
                $qb2->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY),
            ))
            ->executeStatement();
    }

    /**
     * Drop every override for a message.
     *
     * Nothing calls this yet, and that is deliberate rather than an
     * oversight: `MessageMapper::delete()` does not purge `teamhub_comments`
     * either, so a message delete already leaves its thread behind (the
     * "orphan comment" branch in CommentController exists because of it).
     * Adding a cascade for the smaller table while the larger one keeps
     * accumulating would make the inconsistency harder to see, not better.
     * The method exists so closing that gap is a one-line change when it is
     * taken deliberately — same reason `ProjectMapper::deleteByTeamId()`
     * exists ahead of its caller.
     */
    public function deleteByMessageId(int $messageId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('teamhub_msg_subscription')
            ->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }
}
