<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_holidays — admin-locked dates that overwrite slots
 * to the 'holiday' presence type with source='holiday'.
 *
 * holiday_date is stored as ISO YYYY-MM-DD VARCHAR(10) per DESIGN.md §2.4
 * (cross-DB safety over native DATE).
 *
 * @method int     getId()
 * @method void    setId(int $id)
 * @method string  getHolidayDate()
 * @method void    setHolidayDate(string $holidayDate)
 * @method string  getName()
 * @method void    setName(string $name)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 */
class Holiday extends Entity {

    protected string $holidayDate = '';
    protected string $name        = '';
    protected int    $createdAt   = 0;

    public function __construct() {
        $this->addType('createdAt', 'integer');
    }
}
