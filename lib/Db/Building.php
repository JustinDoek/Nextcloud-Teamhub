<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_buildings — top of the location hierarchy.
 *
 * @method int          getId()
 * @method void         setId(int $id)
 * @method string       getName()
 * @method void         setName(string $name)
 * @method string|null  getAddress()
 * @method void         setAddress(?string $address)
 * @method int          getSortOrder()
 * @method void         setSortOrder(int $sortOrder)
 * @method int          getCreatedAt()
 * @method void         setCreatedAt(int $createdAt)
 */
class Building extends Entity {

    protected string  $name      = '';
    protected ?string $address   = null;
    protected int     $sortOrder = 0;
    protected int     $createdAt = 0;

    public function __construct() {
        $this->addType('sortOrder', 'integer');
        $this->addType('createdAt', 'integer');
    }
}
