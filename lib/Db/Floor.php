<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_floors — middle of the location hierarchy.
 *
 * building_id is a semantic FK to teamhub_buildings.id. Referential
 * integrity is enforced in PresenceLocationService, not at the DB level
 * (NC convention — ISchemaWrapper migrations are awkward with FKs).
 *
 * @method int     getId()
 * @method void    setId(int $id)
 * @method int     getBuildingId()
 * @method void    setBuildingId(int $buildingId)
 * @method string  getName()
 * @method void    setName(string $name)
 * @method int          getSortOrder()
 * @method void         setSortOrder(int $sortOrder)
 * @method int          getCreatedAt()
 * @method void         setCreatedAt(int $createdAt)
 * @method string|null  getSource()
 * @method void         setSource(?string $source)
 * @method string|null  getSourceRef()
 * @method void         setSourceRef(?string $sourceRef)
 */
class Floor extends Entity {

    protected int    $buildingId = 0;
    protected string $name       = '';
    protected int    $sortOrder  = 0;
    protected int    $createdAt  = 0;

    /**
     * Backend identifier this row was synced from, or null for a floor an
     * administrator created here (v4.13.53). A non-null value makes the row
     * read-only: its name and ordering belong to that backend.
     */
    protected ?string $source    = null;

    /** The key within that backend this row was matched on — the story name. */
    protected ?string $sourceRef = null;

    public function __construct() {
        $this->addType('buildingId', 'integer');
        $this->addType('sortOrder',  'integer');
        $this->addType('createdAt',  'integer');
    }

    /**
     * True when this floor is maintained by a resource backend rather than by
     * an administrator here. Treats '' as unmanaged, as Building does.
     */
    public function isManaged(): bool {
        return ($this->source ?? '') !== '';
    }
}
