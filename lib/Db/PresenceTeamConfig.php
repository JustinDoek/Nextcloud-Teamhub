<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_presence_team — per-team presence configuration.
 *
 * presence_enabled: 0 = presence tab hidden, 1 = presence tab visible.
 * hide_reasons:     0 = full detail visible, 1 = only busy/free/off shown.
 *
 * One row per team (team_id is unique-indexed). Rows are created on first
 * config write; absence of a row means both fields are at their default (0).
 *
 * @method int     getId()
 * @method void    setId(int $id)
 * @method string  getTeamId()
 * @method void    setTeamId(string $teamId)
 * @method int     getPresenceEnabled()
 * @method void    setPresenceEnabled(int $v)
 * @method int     getHideReasons()
 * @method void    setHideReasons(int $v)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 * @method int     getUpdatedAt()
 * @method void    setUpdatedAt(int $updatedAt)
 */
class PresenceTeamConfig extends Entity {

    protected string $teamId          = '';
    protected int    $presenceEnabled = 0;
    protected int    $hideReasons     = 0;
    protected int    $createdAt       = 0;
    protected int    $updatedAt       = 0;

    public function __construct() {
        $this->addType('presenceEnabled', 'integer');
        $this->addType('hideReasons',     'integer');
        $this->addType('createdAt',       'integer');
        $this->addType('updatedAt',       'integer');
    }
}
