<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_presence_types — admin-managed status catalogue.
 *
 * SMALLINT-stored booleans (requires_location, is_busy, selectable_by_user,
 * is_builtin) are addType('integer') so the DB layer hydrates them to int.
 * Service-layer code coerces to bool via (bool)$v at the read boundary.
 *
 * @method int     getId()
 * @method void    setId(int $id)
 * @method string  getSlug()
 * @method void    setSlug(string $slug)
 * @method string  getLabel()
 * @method void    setLabel(string $label)
 * @method string  getIcon()
 * @method void    setIcon(string $icon)
 * @method string  getColor()
 * @method void    setColor(string $color)
 * @method int     getRequiresLocation()
 * @method void    setRequiresLocation(int $v)
 * @method int     getIsBusy()
 * @method void    setIsBusy(int $v)
 * @method int     getSelectableByUser()
 * @method void    setSelectableByUser(int $v)
 * @method int     getIsBuiltin()
 * @method void    setIsBuiltin(int $v)
 * @method int     getSortOrder()
 * @method void    setSortOrder(int $sortOrder)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 */
class PresenceType extends Entity {

    protected string $slug              = '';
    protected string $label             = '';
    protected string $icon              = '';
    protected string $color             = '';
    protected int    $requiresLocation  = 0;
    protected int    $isBusy            = 1;
    protected int    $selectableByUser  = 1;
    protected int    $isBuiltin         = 0;
    protected int    $sortOrder         = 0;
    protected int    $createdAt         = 0;

    public function __construct() {
        $this->addType('requiresLocation',  'integer');
        $this->addType('isBusy',            'integer');
        $this->addType('selectableByUser',  'integer');
        $this->addType('isBuiltin',         'integer');
        $this->addType('sortOrder',         'integer');
        $this->addType('createdAt',         'integer');
    }
}
