<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_roster_assign — who is on a shift.
 *
 * `origin` records how they got there, because the two are not the same fact:
 * a team admin putting somebody on a shift is an instruction, and somebody
 * taking an open shift is a volunteer. A later swap flow has to tell them
 * apart, and so does anybody reading the roster afterwards.
 *
 * @method int    getId()
 * @method void   setId(int $id)
 * @method int    getShiftId()
 * @method void   setShiftId(int $shiftId)
 * @method string getUserId()
 * @method void   setUserId(string $userId)
 * @method string getOrigin()
 * @method void   setOrigin(string $origin)
 * @method string getAssignedBy()
 * @method void   setAssignedBy(string $assignedBy)
 * @method int    getCreatedAt()
 * @method void   setCreatedAt(int $createdAt)
 */
class RosterAssignment extends Entity {

    /** A team admin put this person on the shift. */
    public const ORIGIN_ASSIGNED = 'assigned';

    /** This person took an open shift themselves. */
    public const ORIGIN_CLAIMED = 'claimed';

    public const ORIGINS = [self::ORIGIN_ASSIGNED, self::ORIGIN_CLAIMED];

    protected int    $shiftId    = 0;
    protected string $userId     = '';
    protected string $origin     = self::ORIGIN_ASSIGNED;
    protected string $assignedBy = '';
    protected int    $createdAt  = 0;

    public function __construct() {
        $this->addType('shiftId',   'integer');
        $this->addType('createdAt', 'integer');
    }
}
