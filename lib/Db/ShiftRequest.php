<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_shift_request — "can somebody take this shift".
 *
 * The request is the durable record; the Talk message is only how people hear
 * about it. That order matters: a conversation can be deleted, a message can
 * scroll away, and the roster still has to be able to say who asked, who took
 * it and when.
 *
 * `teamId` is carried rather than joined because the claim link is opened cold
 * and the team is the first thing that has to be checked.
 *
 * @method int    getId()
 * @method void   setId(int $id)
 * @method int    getShiftId()
 * @method void   setShiftId(int $shiftId)
 * @method string getTeamId()
 * @method void   setTeamId(string $teamId)
 * @method string getRequesterUid()
 * @method void   setRequesterUid(string $requesterUid)
 * @method string getReason()
 * @method void   setReason(string $reason)
 * @method string getStatus()
 * @method void   setStatus(string $status)
 * @method string getClaimedBy()
 * @method void   setClaimedBy(string $claimedBy)
 * @method int    getResolvedAt()
 * @method void   setResolvedAt(int $resolvedAt)
 * @method int    getCreatedAt()
 * @method void   setCreatedAt(int $createdAt)
 */
class ShiftRequest extends Entity {

    /** Nobody has taken it yet. */
    public const STATUS_OPEN = 'open';

    /** Somebody took the shift over; the hand-over is done. */
    public const STATUS_CLAIMED = 'claimed';

    /** The requester changed their mind, or the shift went away. */
    public const STATUS_WITHDRAWN = 'withdrawn';

    protected int    $shiftId      = 0;
    protected string $teamId       = '';
    protected string $requesterUid = '';
    protected string $reason       = '';
    protected string $status       = self::STATUS_OPEN;
    protected string $claimedBy    = '';
    protected int    $resolvedAt   = 0;
    protected int    $createdAt    = 0;

    public function __construct() {
        $this->addType('shiftId',    'integer');
        $this->addType('resolvedAt', 'integer');
        $this->addType('createdAt',  'integer');
    }

    public function isOpen(): bool {
        return $this->status === self::STATUS_OPEN;
    }
}
