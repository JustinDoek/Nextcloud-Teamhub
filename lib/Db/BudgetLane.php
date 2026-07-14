<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_budget_lane — one row per (team, Deck stack).
 *
 * A "lane" is the budget scope of one Deck stack (workstream, visualised in
 * ProjectSwimlaneView). It records the lane's allocated share of the project
 * total plus two role gates:
 *   - viewMinLevel — the minimum team role (1=member, 4=moderator, 8=admin)
 *     to see this lane on the Budget page.
 *   - editMinLevel — same convention, for adding/editing/deleting expenses in
 *     this lane (and changing the lane's own allocation from Manage Team).
 *
 * allocatedMinor is nullable — a lane row exists as soon as the Budget page
 * discovers the Deck stack, even before an allocation is set.
 *
 * @method int      getId()
 * @method void     setId(int $id)
 * @method string   getTeamId()
 * @method void     setTeamId(string $teamId)
 * @method int      getDeckStackId()
 * @method void     setDeckStackId(int $deckStackId)
 * @method ?int     getAllocatedMinor()
 * @method void     setAllocatedMinor(?int $allocatedMinor)
 * @method int      getViewMinLevel()
 * @method void     setViewMinLevel(int $viewMinLevel)
 * @method int      getEditMinLevel()
 * @method void     setEditMinLevel(int $editMinLevel)
 * @method int      getCreatedAt()
 * @method void     setCreatedAt(int $createdAt)
 * @method int      getUpdatedAt()
 * @method void     setUpdatedAt(int $updatedAt)
 */
class BudgetLane extends Entity {

    protected string $teamId         = '';
    protected int    $deckStackId    = 0;
    protected ?int   $allocatedMinor = null;
    protected int    $viewMinLevel   = 1;
    protected int    $editMinLevel   = 8;
    protected int    $createdAt      = 0;
    protected int    $updatedAt      = 0;

    public function __construct() {
        $this->addType('deckStackId',    'integer');
        $this->addType('allocatedMinor', 'integer');
        $this->addType('viewMinLevel',   'integer');
        $this->addType('editMinLevel',   'integer');
        $this->addType('createdAt',      'integer');
        $this->addType('updatedAt',      'integer');
    }
}
