<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_msg_attach.
 *
 * Read patterns:
 *   - by message_id (Decisions finalize → list files to copy)
 *
 * Write patterns:
 *   - insert one row per attachment registration
 *   - delete-by-message_id on message hard delete (handled by MessageService)
 */
class MessageAttachmentMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_msg_attach', MessageAttachment::class);
    }

    /**
     * All attachments for one message, oldest first.
     *
     * @return MessageAttachment[]
     */
    public function findByMessageId(int $messageId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Hard-delete all attachment rows for a message. Does NOT touch the
     * underlying files in Nextcloud — caller deals with the file lifecycle
     * separately (or chooses to leave shared files alone, which is the
     * current MessageService behaviour).
     */
    public function deleteByMessageId(int $messageId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
