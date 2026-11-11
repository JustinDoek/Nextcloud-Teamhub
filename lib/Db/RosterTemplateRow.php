<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_roster_tpl_row — one line of a team's week template.
 *
 * A line is *a weekday, a shift type, the role somebody is needed for, and how
 * many people*. `dayOfWeek` is **0 = Monday**, matching PresenceTemplate and
 * the roster grid. A weekday with no row has no shift that day: absence, not a
 * zero, which is how a weekday-only template says nothing runs at the weekend.
 *
 * Unlike ShiftScheduleRow, two lines may share a weekday and a shift type as
 * long as the role differs — *Receptionist on Early* and *Barista on Early* on
 * the same Monday is the ordinary case rather than a doubled target.
 *
 * @method int    getId()
 * @method void   setId(int $id)
 * @method int    getTemplateId()
 * @method void   setTemplateId(int $templateId)
 * @method int    getDayOfWeek()
 * @method void   setDayOfWeek(int $dayOfWeek)
 * @method int    getShiftTypeId()
 * @method void   setShiftTypeId(int $shiftTypeId)
 * @method string getRole()
 * @method void   setRole(string $role)
 * @method int    getRequiredCount()
 * @method void   setRequiredCount(int $requiredCount)
 */
class RosterTemplateRow extends Entity {

    protected int    $templateId    = 0;
    protected int    $dayOfWeek     = 0;
    protected int    $shiftTypeId   = 0;
    protected string $role          = '';
    protected int    $requiredCount = 1;

    public function __construct() {
        $this->addType('templateId',    'integer');
        $this->addType('dayOfWeek',     'integer');
        $this->addType('shiftTypeId',   'integer');
        $this->addType('requiredCount', 'integer');
    }
}
