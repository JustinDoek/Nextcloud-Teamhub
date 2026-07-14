<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_time_log — one row per logged block of work on a Deck
 * card (v3.96.0 — Time investment).
 *
 * userId is the person the time was worked BY (a Deck card assignee).
 * createdBy is who actually submitted the row — normally the same UID but
 * differs when an admin logs on behalf of a member. Both are kept so the
 * report can attribute effort to userId while the audit trail preserves the
 * true submitter.
 *
 * stackId is denormalised at write. Cards move between stacks (Deck lets an
 * assignee drag them across the board) and cards can be deleted; capturing
 * the stack at the moment the log was submitted keeps the per-lane rollup
 * stable and lets us surface totals for lanes whose cards are gone.
 *
 * workedAt is a UTC-midnight Unix timestamp — matches teamhub_expense's
 * incurredAt and teamhub_milestones.milestoneDate conventions.
 *
 * @method int    getId()
 * @method void   setId(int $id)
 * @method string getTeamId()
 * @method void   setTeamId(string $teamId)
 * @method int    getCardId()
 * @method void   setCardId(int $cardId)
 * @method int    getStackId()
 * @method void   setStackId(int $stackId)
 * @method string getUserId()
 * @method void   setUserId(string $userId)
 * @method int    getMinutes()
 * @method void   setMinutes(int $minutes)
 * @method string getDescription()
 * @method void   setDescription(string $description)
 * @method int    getWorkedAt()
 * @method void   setWorkedAt(int $workedAt)
 * @method string getCreatedBy()
 * @method void   setCreatedBy(string $createdBy)
 * @method int    getCreatedAt()
 * @method void   setCreatedAt(int $createdAt)
 * @method int    getUpdatedAt()
 * @method void   setUpdatedAt(int $updatedAt)
 */
class TimeLog extends Entity {

    protected string $teamId      = '';
    protected int    $cardId      = 0;
    protected int    $stackId     = 0;
    protected string $userId      = '';
    protected int    $minutes     = 0;
    protected string $description = '';
    protected int    $workedAt    = 0;
    protected string $createdBy   = '';
    protected int    $createdAt   = 0;
    protected int    $updatedAt   = 0;

    public function __construct() {
        $this->addType('cardId',    'integer');
        $this->addType('stackId',   'integer');
        $this->addType('minutes',   'integer');
        $this->addType('workedAt',  'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }
}
