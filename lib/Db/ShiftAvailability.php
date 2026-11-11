<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_shift_avail — one half-day somebody has spoken about.
 *
 * An absent row means **available**: see the migration for why that is the
 * only safe default.
 *
 * @method int    getId()
 * @method void   setId(int $id)
 * @method string getUserId()
 * @method void   setUserId(string $userId)
 * @method int    getDayOfWeek()
 * @method void   setDayOfWeek(int $dayOfWeek)
 * @method int    getHalfDay()
 * @method void   setHalfDay(int $halfDay)
 * @method int    getAvailable()
 * @method void   setAvailable(int $v)
 * @method int    getUpdatedAt()
 * @method void   setUpdatedAt(int $updatedAt)
 */
class ShiftAvailability extends Entity {

    protected string $userId    = '';
    protected int    $dayOfWeek = 0;
    protected int    $halfDay   = 0;
    protected int    $available = 1;
    protected int    $updatedAt = 0;

    public function __construct() {
        $this->addType('dayOfWeek', 'integer');
        $this->addType('halfDay',   'integer');
        $this->addType('available', 'integer');
        $this->addType('updatedAt', 'integer');
    }
}
