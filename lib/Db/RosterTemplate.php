<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_roster_template — one team's named week.
 *
 * The week itself lives in RosterTemplateRow; this row is its name, who it
 * belongs to and whether it is still offered. A template that is switched off
 * stays readable, because a week may have been laid out from it.
 *
 * Not to be confused with ShiftSchedule, which is the **instance-wide**
 * pattern a Nextcloud administrator composes (DESIGN §2.234). This one is a
 * team admin's and carries a role per line (DESIGN §2.240).
 *
 * @method int    getId()
 * @method void   setId(int $id)
 * @method string getTeamId()
 * @method void   setTeamId(string $teamId)
 * @method string getName()
 * @method void   setName(string $name)
 * @method string getDescription()
 * @method void   setDescription(string $description)
 * @method int    getIsActive()
 * @method void   setIsActive(int $v)
 * @method int    getSortOrder()
 * @method void   setSortOrder(int $sortOrder)
 * @method string getCreatedBy()
 * @method void   setCreatedBy(string $createdBy)
 * @method int    getCreatedAt()
 * @method void   setCreatedAt(int $createdAt)
 * @method int    getUpdatedAt()
 * @method void   setUpdatedAt(int $updatedAt)
 */
class RosterTemplate extends Entity {

    protected string $teamId      = '';
    protected string $name        = '';
    protected string $description = '';
    protected int    $isActive    = 1;
    protected int    $sortOrder   = 0;
    protected string $createdBy   = '';
    protected int    $createdAt   = 0;
    protected int    $updatedAt   = 0;

    public function __construct() {
        $this->addType('isActive',  'integer');
        $this->addType('sortOrder', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }
}
