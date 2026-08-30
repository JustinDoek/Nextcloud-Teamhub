<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class CommentMapper {
    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function find(int $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from('teamhub_comments')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ?: null;
    }

    public function findByMessageId(int $messageId): array {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from('teamhub_comments')
            ->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId)))
            ->orderBy('created_at', 'ASC')
            ->executeQuery();

        $comments = [];
        while ($row = $result->fetch()) {
            $comments[] = $row;
        }
        $result->closeCursor();
        return $comments;
    }

    public function countByMessageId(int $messageId): int {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select($qb->createFunction('COUNT(*) as count'))
            ->from('teamhub_comments')
            ->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId)))
            ->executeQuery();

        $row = $result->fetch();
        $result->closeCursor();
        return (int)($row['count'] ?? 0);
    }

    public function create(int $messageId, string $authorId, string $comment): array {
        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->insert('teamhub_comments')
            ->values([
                'message_id' => $qb->createNamedParameter($messageId),
                'author_id'  => $qb->createNamedParameter($authorId),
                'comment'    => $qb->createNamedParameter($comment),
                'created_at' => $qb->createNamedParameter($now),
            ])
            ->executeStatement();

        // '*PREFIX*' — not a hard-coded 'oc_'. NC expands the placeholder to the
        // instance's configured `dbtableprefix`; a literal prefix is wrong on any
        // install that changed it, and Postgres resolves a *sequence* from this
        // name, so it returns 0 there rather than failing loudly. Every other
        // mapper in this app already uses the placeholder form.
        $id = (int)$this->db->lastInsertId('*PREFIX*teamhub_comments');

        return [
            'id'         => $id,
            'message_id' => $messageId,
            'author_id'  => $authorId,
            'comment'    => $comment,
            'created_at' => $now,
        ];
    }

    /**
     * Update a comment's text and record who did it.
     *
     * v4.7.4 — **the authorisation moved out of this query.** It used to
     * carry `AND author_id = :editorId`, which meant a non-author's update
     * matched zero rows, and the method then re-fetched and returned the
     * unchanged row — reporting success for a write that never happened.
     * That was survivable while the author was the only one allowed to edit;
     * it is not now that a moderator may, because a silent no-op is
     * indistinguishable from a silent unauthorised write to anyone reading
     * this code later. CommentController::updateComment holds the gate, and
     * this method now does what its name says.
     *
     * `$editorId` is the person making the change, not necessarily the
     * author.
     */
    public function update(int $id, string $editorId, string $comment): array {
        $qb = $this->db->getQueryBuilder();
        $qb->update('teamhub_comments')
            ->set('comment', $qb->createNamedParameter($comment))
            ->set('edited_by', $qb->createNamedParameter($editorId))
            ->set('edited_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->executeStatement();

        // Re-fetch to return the updated row
        $qb2 = $this->db->getQueryBuilder();
        $result = $qb2->select('*')
            ->from('teamhub_comments')
            ->where($qb2->expr()->eq('id', $qb2->createNamedParameter($id)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        if (!$row) {
            throw new \Exception('Comment not found after update');
        }
        return $row;
    }

    public function delete(int $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('teamhub_comments')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->executeStatement();
    }

    public function deleteByMessageId(int $messageId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('teamhub_comments')
            ->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId)))
            ->executeStatement();
    }
}
