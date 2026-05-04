<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

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

        $id = $this->db->lastInsertId('oc_teamhub_comments');

        return [
            'id'         => $id,
            'message_id' => $messageId,
            'author_id'  => $authorId,
            'comment'    => $comment,
            'created_at' => $now,
        ];
    }

    public function update(int $id, string $authorId, string $comment): array {
        $qb = $this->db->getQueryBuilder();
        $qb->update('teamhub_comments')
            ->set('comment', $qb->createNamedParameter($comment))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('author_id', $qb->createNamedParameter($authorId)))
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
