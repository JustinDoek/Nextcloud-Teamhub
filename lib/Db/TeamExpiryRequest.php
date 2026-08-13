<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_expiry_request — one extension request (v4.6.13).
 *
 * A team admin proposes a new expiration date with a reason; a Nextcloud admin
 * approves or denies it. Rows are never deleted on decision — a decided request
 * is the record the team side reads to learn the outcome, and the queue filters
 * on `status` rather than on the row being gone.
 *
 * `proposedUntil` and `grantedUntil` are separate because an admin may approve
 * for less time than was asked for. `grantedUntil` stays null on a denial.
 *
 * STATUS_SUPERSEDED is what an open request becomes when the expiry is extended
 * by some other route (an admin extending directly from the All teams table, or
 * the expiry being cleared entirely). Without it the queue would keep asking
 * for a decision that no longer has a question behind it.
 *
 * @method int      getId()
 * @method void     setId(int $id)
 * @method string   getTeamId()
 * @method void     setTeamId(string $teamId)
 * @method string   getRequestedBy()
 * @method void     setRequestedBy(string $requestedBy)
 * @method int      getRequestedAt()
 * @method void     setRequestedAt(int $requestedAt)
 * @method int      getProposedUntil()
 * @method void     setProposedUntil(int $proposedUntil)
 * @method ?string  getReason()
 * @method void     setReason(?string $reason)
 * @method string   getStatus()
 * @method void     setStatus(string $status)
 * @method ?string  getDecidedBy()
 * @method void     setDecidedBy(?string $decidedBy)
 * @method ?int     getDecidedAt()
 * @method void     setDecidedAt(?int $decidedAt)
 * @method ?int     getGrantedUntil()
 * @method void     setGrantedUntil(?int $grantedUntil)
 * @method ?string  getDecisionNote()
 * @method void     setDecisionNote(?string $decisionNote)
 */
class TeamExpiryRequest extends Entity {

    public const STATUS_PENDING    = 'pending';
    public const STATUS_APPROVED   = 'approved';
    public const STATUS_DENIED     = 'denied';
    public const STATUS_SUPERSEDED = 'superseded';

    /** Every status a row may legally carry. */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_DENIED,
        self::STATUS_SUPERSEDED,
    ];

    protected string  $teamId        = '';
    protected string  $requestedBy   = '';
    protected int     $requestedAt   = 0;
    protected int     $proposedUntil = 0;
    protected ?string $reason        = null;
    protected string  $status        = self::STATUS_PENDING;
    protected ?string $decidedBy     = null;
    protected ?int    $decidedAt     = null;
    protected ?int    $grantedUntil  = null;
    protected ?string $decisionNote  = null;

    public function __construct() {
        $this->addType('requestedAt',   'integer');
        $this->addType('proposedUntil', 'integer');
        $this->addType('decidedAt',     'integer');
        $this->addType('grantedUntil',  'integer');
    }
}
