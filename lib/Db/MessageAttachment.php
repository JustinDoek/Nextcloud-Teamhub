<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_msg_attach — sidecar table linking a Nextcloud file
 * (uploaded by the message author and shared with the team circle) to the
 * teamhub_messages row it was attached to.
 *
 * Until v3.71.2, attachments only lived as URLs embedded in the message
 * body. This table makes the link durable so the Decisions module can
 * find a finalized decision's attachments and copy them into
 * .proposals/{decisionId}/ for the Source list.
 *
 * @method int     getId()
 * @method void    setId(int $id)
 * @method int     getMessageId()
 * @method void    setMessageId(int $messageId)
 * @method int     getFileId()
 * @method void    setFileId(int $fileId)
 * @method string  getFileName()
 * @method void    setFileName(string $fileName)
 * @method string  getUploadedBy()
 * @method void    setUploadedBy(string $uploadedBy)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 */
class MessageAttachment extends Entity {

    protected int     $messageId   = 0;
    protected int     $fileId      = 0;
    protected string  $fileName    = '';
    protected string  $uploadedBy  = '';
    protected int     $createdAt   = 0;

    public function __construct() {
        $this->addType('messageId', 'integer');
        $this->addType('fileId',    'integer');
        $this->addType('createdAt', 'integer');
    }
}
