<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_rooms — bottom of the location hierarchy.
 *
 * floor_id is a semantic FK to teamhub_floors.id. Referential integrity
 * is enforced in PresenceLocationService, not at the DB level.
 *
 * @method int     getId()
 * @method void    setId(int $id)
 * @method int     getFloorId()
 * @method void    setFloorId(int $floorId)
 * @method string  getName()
 * @method void    setName(string $name)
 * @method int     getSortOrder()
 * @method void    setSortOrder(int $sortOrder)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 */
class Room extends Entity {

    protected int    $floorId   = 0;
    protected string $name      = '';
    protected int    $sortOrder = 0;
    protected int    $createdAt = 0;

    public function __construct() {
        $this->addType('floorId',   'integer');
        $this->addType('sortOrder', 'integer');
        $this->addType('createdAt', 'integer');
    }
}
