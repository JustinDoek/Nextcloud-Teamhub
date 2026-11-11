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
 * @method string|null  getSource()
 * @method void         setSource(?string $source)
 * @method string|null  getSourceRef()
 * @method void         setSourceRef(?string $sourceRef)
 */
class Building extends Entity {

    protected string  $name      = '';
    protected ?string $address   = null;
    protected int     $sortOrder = 0;
    protected int     $createdAt = 0;

    /**
     * Backend identifier this row was synced from, or null for a building an
     * administrator created here (v4.13.53). A non-null value makes the row
     * read-only: its name, address and ordering belong to that backend.
     */
    protected ?string $source    = null;

    /** The key within that backend this row was matched on — the building name. */
    protected ?string $sourceRef = null;

    public function __construct() {
        $this->addType('sortOrder', 'integer');
        $this->addType('createdAt', 'integer');
    }

    /**
     * True when this building is maintained by a resource backend rather than
     * by an administrator here. Treats '' as unmanaged: the column is
     * nullable, and an empty string from an older row must not read as a
     * third state.
     */
    public function isManaged(): bool {
        return ($this->source ?? '') !== '';
    }
}
