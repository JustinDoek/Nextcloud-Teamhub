<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_presence_template — one row per (user, day_of_week, half_day).
 *
 * day_of_week: 0=Mon … 6=Sun (ISO 8601).
 * half_day:    0=Morning, 1=Afternoon.
 *
 * presence_type_id and location_room_id are nullable — a null presence_type_id
 * means "no entry set for this cell" (user hasn't configured this half-day).
 *
 * @method int      getId()
 * @method void     setId(int $id)
 * @method string   getUserId()
 * @method void     setUserId(string $userId)
 * @method int      getDayOfWeek()
 * @method void     setDayOfWeek(int $dayOfWeek)
 * @method int      getHalfDay()
 * @method void     setHalfDay(int $halfDay)
 * @method int|null getPresenceTypeId()
 * @method void     setPresenceTypeId(?int $presenceTypeId)
 * @method int|null getLocationRoomId()
 * @method void     setLocationRoomId(?int $locationRoomId)
 * @method int      getCreatedAt()
 * @method void     setCreatedAt(int $createdAt)
 * @method int      getUpdatedAt()
 * @method void     setUpdatedAt(int $updatedAt)
 */
class PresenceTemplate extends Entity {

    protected string $userId         = '';
    protected int    $dayOfWeek      = 0;
    protected int    $halfDay        = 0;
    protected ?int   $presenceTypeId = null;
    protected ?int   $locationRoomId = null;
    protected int    $createdAt      = 0;
    protected int    $updatedAt      = 0;

    public function __construct() {
        $this->addType('dayOfWeek',      'integer');
        $this->addType('halfDay',        'integer');
        $this->addType('presenceTypeId', 'integer');
        $this->addType('locationRoomId', 'integer');
        $this->addType('createdAt',      'integer');
        $this->addType('updatedAt',      'integer');
    }
}
