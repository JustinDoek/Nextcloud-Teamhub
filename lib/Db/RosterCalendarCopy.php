<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_roster_cal: one shift copied into one person's calendar
 * (DESIGN §2.250).
 *
 * The row is the only way back to the event, so it is written after the event
 * is and removed after the event is.
 *
 * @method int    getId()
 * @method void   setId(int $id)
 * @method int    getShiftId()
 * @method void   setShiftId(int $shiftId)
 * @method string getUserId()
 * @method void   setUserId(string $userId)
 * @method string getTeamId()
 * @method void   setTeamId(string $teamId)
 * @method int    getCalendarId()
 * @method void   setCalendarId(int $calendarId)
 * @method string getObjectUri()
 * @method void   setObjectUri(string $objectUri)
 * @method string getTransp()
 * @method void   setTransp(string $transp)
 * @method int    getCreatedAt()
 * @method void   setCreatedAt(int $createdAt)
 * @method int    getUpdatedAt()
 * @method void   setUpdatedAt(int $updatedAt)
 */
class RosterCalendarCopy extends Entity {

    /** Shown as busy: counts in free/busy, so nobody books a meeting over it. */
    public const TRANSP_BUSY = 'OPAQUE';

    /** Shown as free: in the calendar for reference, invisible to free/busy. */
    public const TRANSP_FREE = 'TRANSPARENT';

    public const TRANSPS = [self::TRANSP_BUSY, self::TRANSP_FREE];

    protected int    $shiftId    = 0;
    protected string $userId     = '';
    protected string $teamId     = '';
    protected int    $calendarId = 0;
    protected string $objectUri  = '';
    protected string $transp     = self::TRANSP_BUSY;
    protected int    $createdAt  = 0;
    protected int    $updatedAt  = 0;

    public function __construct() {
        $this->addType('shiftId',    'integer');
        $this->addType('calendarId', 'integer');
        $this->addType('createdAt',  'integer');
        $this->addType('updatedAt',  'integer');
    }
}
