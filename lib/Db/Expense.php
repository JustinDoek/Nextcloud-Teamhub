<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_expense — one budgeted line item under a lane
 * (v3.92.0 — Budget page).
 *
 * projectedMinor is always set (planned amount). realMinor is nullable —
 * null means "not yet realised" so the projected number still stands. Once
 * the expense actually happens, the editor sets realMinor.
 *
 * incurredAt is a UTC-midnight Unix timestamp (matches teamhub_milestones
 * and teamhub_project.startDate/targetEnd conventions). Nullable — plan
 * lines can pre-date a firm decision.
 *
 * @method int      getId()
 * @method void     setId(int $id)
 * @method string   getTeamId()
 * @method void     setTeamId(string $teamId)
 * @method int      getLaneId()
 * @method void     setLaneId(int $laneId)
 * @method string   getDescription()
 * @method void     setDescription(string $description)
 * @method int      getProjectedMinor()
 * @method void     setProjectedMinor(int $projectedMinor)
 * @method ?int     getRealMinor()
 * @method void     setRealMinor(?int $realMinor)
 * @method ?int     getIncurredAt()
 * @method void     setIncurredAt(?int $incurredAt)
 * @method string   getCreatedBy()
 * @method void     setCreatedBy(string $createdBy)
 * @method int      getCreatedAt()
 * @method void     setCreatedAt(int $createdAt)
 * @method int      getUpdatedAt()
 * @method void     setUpdatedAt(int $updatedAt)
 */
class Expense extends Entity {

    protected string $teamId         = '';
    protected int    $laneId         = 0;
    protected string $description    = '';
    protected int    $projectedMinor = 0;
    protected ?int   $realMinor      = null;
    protected ?int   $incurredAt     = null;
    protected string $createdBy      = '';
    protected int    $createdAt      = 0;
    protected int    $updatedAt      = 0;

    public function __construct() {
        $this->addType('laneId',         'integer');
        $this->addType('projectedMinor', 'integer');
        $this->addType('realMinor',      'integer');
        $this->addType('incurredAt',     'integer');
        $this->addType('createdAt',      'integer');
        $this->addType('updatedAt',      'integer');
    }
}
