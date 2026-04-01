<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class MessageMapper {
    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    /**
     * Find non-pinned messages by team ID (pinned message is returned separately).
     */
    public function findByTeamId(string $teamId, int $limit = 50, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('m.*', $qb->createFunction('COALESCE(c.comment_count, 0) AS comment_count'))
            ->from('teamhub_messages', 'm')
            ->leftJoin('m', $qb->createFunction(
                '(SELECT message_id, COUNT(*) as comment_count FROM oc_teamhub_comments GROUP BY message_id)'
            ), 'c', 'm.id = c.message_id')
            ->where($qb->expr()->eq('m.team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('m.pinned', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->orderBy('m.created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $result = $qb->executeQuery();
        $messages = [];

        while ($row = $result->fetch()) {
            $messages[] = $this->rowToArray($row);
        }

        $result->closeCursor();
        return $messages;
    }

    /**
     * Find the single pinned message for a team, or null if none.
     */
    public function findPinnedByTeamId(string $teamId): ?array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('m.*', $qb->createFunction('COALESCE(c.comment_count, 0) AS comment_count'))
            ->from('teamhub_messages', 'm')
            ->leftJoin('m', $qb->createFunction(
                '(SELECT message_id, COUNT(*) as comment_count FROM oc_teamhub_comments GROUP BY message_id)'
            ), 'c', 'm.id = c.message_id')
            ->where($qb->expr()->eq('m.team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('m.pinned', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row ? $this->rowToArray($row) : null;
    }

    /**
     * Find a single message by ID.
     */
    public function find(int $id): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('teamhub_messages')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            throw new \Exception('Message not found');
        }

        return $this->rowToArray($row);
    }

    /**
     * Create a new message.
     */
    public function create(string $teamId, string $authorId, string $subject, string $message, string $priority = 'normal', string $messageType = 'normal', ?array $pollOptions = null): array {
        $qb = $this->db->getQueryBuilder();
        $now = time();

        $values = [
            'team_id'      => $qb->createNamedParameter($teamId),
            'author_id'    => $qb->createNamedParameter($authorId),
            'subject'      => $qb->createNamedParameter($subject),
            'message'      => $qb->createNamedParameter($message),
            'priority'     => $qb->createNamedParameter($priority),
            'message_type' => $qb->createNamedParameter($messageType),
            'pinned'       => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
            'created_at'   => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'updated_at'   => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
        ];

        if ($messageType === 'poll' && $pollOptions) {
            $values['poll_options'] = $qb->createNamedParameter(json_encode($pollOptions));
        }

        $qb->insert('teamhub_messages')->values($values);
        $qb->executeStatement();
        $id = $qb->getLastInsertId();

        return $this->find($id);
    }

    /**
     * Update a message.
     */
    public function update(int $id, string $subject, string $message): array {
        $qb = $this->db->getQueryBuilder();

        $qb->update('teamhub_messages')
            ->set('subject', $qb->createNamedParameter($subject))
            ->set('message', $qb->createNamedParameter($message))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();

        return $this->find($id);
    }

    /**
     * Delete a message.
     */
    public function delete(int $id): void {
        $qb = $this->db->getQueryBuilder();

        $qb->delete('teamhub_messages')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Unpin all messages for a team (call before setting a new pin).
     */
    public function unpinAllForTeam(string $teamId): void {
        $qb = $this->db->getQueryBuilder();

        $qb->update('teamhub_messages')
            ->set('pinned', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('pinned', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Pin a message. Caller must call unpinAllForTeam() first.
     */
    public function pin(int $id): array {
        $qb = $this->db->getQueryBuilder();

        $qb->update('teamhub_messages')
            ->set('pinned', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();

        return $this->find($id);
    }

    /**
     * Unpin a specific message.
     */
    public function unpin(int $id): array {
        $qb = $this->db->getQueryBuilder();

        $qb->update('teamhub_messages')
            ->set('pinned', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();

        return $this->find($id);
    }

    /**
     * Close a poll to prevent further voting.
     */
    public function closePoll(int $id): array {
        $qb = $this->db->getQueryBuilder();

        $qb->update('teamhub_messages')
            ->set('poll_closed', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();

        return $this->find($id);
    }

    /**
     * Mark a question as solved with a specific comment.
     */
    public function markQuestionSolved(int $id, int $commentId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->update('teamhub_messages')
            ->set('question_solved', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
            ->set('solved_comment_id', $qb->createNamedParameter($commentId, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();

        return $this->find($id);
    }

    /**
     * Unmark a question as solved.
     */
    public function unmarkQuestionSolved(int $id): array {
        $qb = $this->db->getQueryBuilder();

        $qb->update('teamhub_messages')
            ->set('question_solved', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('solved_comment_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();

        return $this->find($id);
    }

    /**
     * Find aggregated messages from multiple teams.
     */
    public function findAggregated(array $teamIds, int $limit = 10): array {
        if (empty($teamIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('teamhub_messages')
            ->where($qb->expr()->in('team_id', $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $messages = [];

        while ($row = $result->fetch()) {
            $messages[] = $this->rowToArray($row);
        }

        $result->closeCursor();
        return $messages;
    }

    /**
     * Get the Unix timestamp of the most recent message for a team (0 if none).
     */
    public function getLatestMessageTimestamp(string $teamId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('MAX(created_at) as latest'))
            ->from('teamhub_messages')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['latest'] ?? 0);
    }

    private function rowToArray(array $row): array {
        $pollOptions = null;
        if (!empty($row['poll_options'])) {
            $pollOptions = json_decode($row['poll_options'], true);
        }

        return [
            'id'              => (int)$row['id'],
            'team_id'         => $row['team_id'],
            'author_id'       => $row['author_id'],
            'subject'         => $row['subject'],
            'message'         => $row['message'],
            'priority'        => $row['priority'] ?? 'normal',
            'messageType'     => $row['message_type'] ?? 'normal',
            'pinned'          => (bool)($row['pinned'] ?? false),
            'pollOptions'     => $pollOptions,
            'pollClosed'      => (bool)($row['poll_closed'] ?? false),
            'questionSolved'  => (bool)($row['question_solved'] ?? false),
            'solvedCommentId' => isset($row['solved_comment_id']) ? (int)$row['solved_comment_id'] : null,
            'comment_count'   => (int)($row['comment_count'] ?? 0),
            'created_at'      => (int)$row['created_at'],
            'updated_at'      => (int)$row['updated_at'],
        ];
    }
}
