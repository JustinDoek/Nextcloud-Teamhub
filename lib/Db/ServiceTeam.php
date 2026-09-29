<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One row of `teamhub_service_team` — a team that answers requests.
 *
 * **v4.10.23 — the row says which team, and nothing about who.** Phase 5
 * carried an owner, a roster, an assignment rule and an extra group here;
 * all four are gone (DESIGN §2.146). A service team's people are the team's
 * own people: its admins are answerable for the service, its members and
 * moderators work the queue, and a whole department joins by being added to
 * the team as a group. What is left on the row is the team and whether the
 * desk is open.
 *
 * `active` is 0 until the team claims something. A row with no catalogue
 * entry is a desk that holds no service — visible to its own admins on the
 * Services tab, invisible to everybody else.
 *
 * @method string  getTeamId()
 * @method void    setTeamId(string $v)
 * @method int     getActive()
 * @method void    setActive(int $v)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $v)
 * @method string  getCreatedBy()
 * @method void    setCreatedBy(string $v)
 * @method int     getUpdatedAt()
 * @method void    setUpdatedAt(int $v)
 */
class ServiceTeam extends Entity {

    protected string $teamId      = '';
    protected int    $active      = 0;
    protected int    $createdAt   = 0;
    protected string $createdBy   = '';
    protected int    $updatedAt   = 0;

    public function __construct() {
        $this->addType('active',    'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }

    public function isActive(): bool {
        return $this->getActive() === 1;
    }
}
