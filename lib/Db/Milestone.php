<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_milestones — a named, optionally-dated marker a team
 * admin places on the Timeline tab (v3.78.2 — Timeline Milestones, v1).
 *
 * milestoneDate is nullable: a milestone can exist before a firm date is
 * chosen. Undated milestones are listed in Manage Team → Integration
 * settings but are not plotted on the Timeline (there is no x-position to
 * plot them at).
 *
 * @method int      getId()
 * @method void     setId(int $id)
 * @method string   getTeamId()
 * @method void     setTeamId(string $teamId)
 * @method string   getLabel()
 * @method void     setLabel(string $label)
 * @method ?int     getMilestoneDate()
 * @method void     setMilestoneDate(?int $milestoneDate)
 * @method string   getCreatedBy()
 * @method void     setCreatedBy(string $createdBy)
 * @method int      getCreatedAt()
 * @method void     setCreatedAt(int $createdAt)
 * @method ?int     getPostedAt()
 * @method void     setPostedAt(?int $postedAt)
 */
class Milestone extends Entity {

    protected string $teamId        = '';
    protected string $label         = '';
    protected ?int    $milestoneDate = null;
    protected string $createdBy     = '';
    protected int    $createdAt     = 0;
    // v3.97.0 — set by MilestoneAutoPostService when the milestone's date
    // has passed and the hourly job has posted "Milestone reached: {label}"
    // to the team stream. NULL until posted.
    protected ?int   $postedAt      = null;

    public function __construct() {
        $this->addType('milestoneDate', 'integer');
        $this->addType('createdAt',     'integer');
        $this->addType('postedAt',      'integer');
    }
}
