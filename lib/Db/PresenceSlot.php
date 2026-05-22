<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_presence_slots — one row per (user, slot_date, half_day).
 *
 * slot_date: ISO YYYY-MM-DD VARCHAR(10) per DESIGN.md §2.47.
 * half_day:  0=Morning, 1=Afternoon.
 * source:    'template' (auto-materialised) | 'override' (user-edited) | 'holiday' (admin-locked).
 *
 * calendar_event_uid is preserved on holiday overwrite for B4.
 *
 * @method int         getId()
 * @method void        setId(int $id)
 * @method string      getUserId()
 * @method void        setUserId(string $userId)
 * @method string      getSlotDate()
 * @method void        setSlotDate(string $slotDate)
 * @method int         getHalfDay()
 * @method void        setHalfDay(int $halfDay)
 * @method int|null    getPresenceTypeId()
 * @method void        setPresenceTypeId(?int $presenceTypeId)
 * @method int|null    getLocationRoomId()
 * @method void        setLocationRoomId(?int $locationRoomId)
 * @method string      getSource()
 * @method void        setSource(string $source)
 * @method string|null getCalendarEventUid()
 * @method void        setCalendarEventUid(?string $calendarEventUid)
 * @method int         getCreatedAt()
 * @method void        setCreatedAt(int $createdAt)
 * @method int         getUpdatedAt()
 * @method void        setUpdatedAt(int $updatedAt)
 */
class PresenceSlot extends Entity {

    protected string  $userId            = '';
    protected string  $slotDate          = '';
    protected int     $halfDay           = 0;
    protected ?int    $presenceTypeId    = null;
    protected ?int    $locationRoomId    = null;
    protected string  $source            = 'template';
    protected ?string $calendarEventUid  = null;
    protected int     $createdAt         = 0;
    protected int     $updatedAt         = 0;

    public function __construct() {
        $this->addType('halfDay',        'integer');
        $this->addType('presenceTypeId', 'integer');
        $this->addType('locationRoomId', 'integer');
        $this->addType('createdAt',      'integer');
        $this->addType('updatedAt',      'integer');
    }
}
