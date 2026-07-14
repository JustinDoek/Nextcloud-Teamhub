<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_budget_lane_editor (v3.93.0) — one row per (lane, user).
 * A user in this list has edit access to the lane regardless of team role;
 * see BudgetService for the resolution rules.
 *
 * @method int    getId()
 * @method void   setId(int $id)
 * @method int    getLaneId()
 * @method void   setLaneId(int $laneId)
 * @method string getUserId()
 * @method void   setUserId(string $userId)
 * @method int    getCreatedAt()
 * @method void   setCreatedAt(int $createdAt)
 */
class BudgetLaneEditor extends Entity {

    protected int    $laneId    = 0;
    protected string $userId    = '';
    protected int    $createdAt = 0;

    public function __construct() {
        $this->addType('laneId',    'integer');
        $this->addType('createdAt', 'integer');
    }
}
