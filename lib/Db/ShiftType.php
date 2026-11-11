<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_shift_type — the administrator-managed shift vocabulary.
 *
 * Times are wall clock `HH:MM` strings, not timestamps: a shift type is a
 * floating pattern, true in every timezone the instance serves, and resolving
 * it to an instant before a date is in hand is the bug DESIGN §2.4 exists to
 * prevent. `endsNextDay()` is derived rather than stored, so the two cannot
 * disagree about a night shift.
 *
 * SMALLINT-stored booleans are addType('integer') and coerced at the read
 * boundary, the same contract as PresenceType.
 *
 * @method int    getId()
 * @method void   setId(int $id)
 * @method string getSlug()
 * @method void   setSlug(string $slug)
 * @method string getLabel()
 * @method void   setLabel(string $label)
 * @method string getStartTime()
 * @method void   setStartTime(string $startTime)
 * @method string getEndTime()
 * @method void   setEndTime(string $endTime)
 * @method string getColor()
 * @method void   setColor(string $color)
 * @method string getIcon()
 * @method void   setIcon(string $icon)
 * @method int    getSortOrder()
 * @method void   setSortOrder(int $sortOrder)
 * @method int    getIsActive()
 * @method void   setIsActive(int $v)
 * @method int    getIsBuiltin()
 * @method void   setIsBuiltin(int $v)
 * @method int    getCreatedAt()
 * @method void   setCreatedAt(int $createdAt)
 * @method int    getUpdatedAt()
 * @method void   setUpdatedAt(int $updatedAt)
 */
class ShiftType extends Entity {

    protected string $slug      = '';
    protected string $label     = '';
    protected string $startTime = '09:00';
    protected string $endTime   = '17:00';
    protected string $color     = '';
    protected string $icon      = '';
    protected int    $sortOrder = 0;
    protected int    $isActive  = 1;
    protected int    $isBuiltin = 0;
    protected int    $createdAt = 0;
    protected int    $updatedAt = 0;

    public function __construct() {
        $this->addType('sortOrder', 'integer');
        $this->addType('isActive',  'integer');
        $this->addType('isBuiltin', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }

    /** Minutes past midnight, or null when the stored value is not `HH:MM`. */
    public static function minutes(string $hhmm): ?int {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $m) !== 1) {
            return null;
        }
        return ((int)$m[1] * 60) + (int)$m[2];
    }

    public function startMinutes(): ?int {
        return self::minutes($this->startTime);
    }

    public function endMinutes(): ?int {
        return self::minutes($this->endTime);
    }

    /**
     * Does this shift run past midnight?
     *
     * Derived from the two times rather than stored, so a *Night* type cannot
     * claim one thing in a column and another in its hours. An end equal to
     * the start is a 24-hour shift, which still ends the next day.
     */
    public function endsNextDay(): bool {
        $start = $this->startMinutes();
        $end   = $this->endMinutes();
        if ($start === null || $end === null) {
            return false;
        }
        return $end <= $start;
    }

    /** Length in minutes, across midnight where it applies. */
    public function lengthMinutes(): int {
        $start = $this->startMinutes();
        $end   = $this->endMinutes();
        if ($start === null || $end === null) {
            return 0;
        }
        return $end > $start ? $end - $start : (1440 - $start) + $end;
    }
}
