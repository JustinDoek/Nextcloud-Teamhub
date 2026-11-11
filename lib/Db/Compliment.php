<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_compliment — one compliment, with the note that was
 * written with it (v4.13.2, DESIGN §2.186).
 *
 * `seenAt` is the whole state machine: NULL means the recipient has not been
 * shown it yet, a timestamp means they have. Nothing is deleted on read, so
 * "since your last visit" is answerable without storing a visit.
 *
 * @method int     getId()
 * @method void    setId(int $id)
 * @method string  getRecipientUid()
 * @method void    setRecipientUid(string $recipientUid)
 * @method string  getSenderUid()
 * @method void    setSenderUid(string $senderUid)
 * @method string  getTeamId()
 * @method void    setTeamId(string $teamId)
 * @method string  getNote()
 * @method void    setNote(string $note)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 * @method ?int    getSeenAt()
 * @method void    setSeenAt(?int $seenAt)
 */
class Compliment extends Entity {

    protected string $recipientUid = '';
    protected string $senderUid    = '';
    protected string $teamId       = '';
    protected string $note         = '';
    protected int    $createdAt    = 0;
    protected ?int   $seenAt       = null;

    public function __construct() {
        $this->addType('createdAt', 'integer');
        $this->addType('seenAt',    'integer');
    }
}
