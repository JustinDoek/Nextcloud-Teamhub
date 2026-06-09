<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_decision_team — per-team Decisions module configuration.
 *
 * decisions_enabled:       0 = Decisions tab/widget hidden, 1 = visible.
 * decisions_level_enabled: 0 = Level field hidden (default), 1 = shown in
 *   the compose form, detail panel, and filter chips. Per-team toggle so
 *   teams that don't use strategic taxonomy aren't burdened with the field.
 *
 * One row per team (team_id is unique-indexed). Rows are created on first
 * config write; absence of a row means all flags = 0 (all disabled).
 *
 * @method int     getId()
 * @method void    setId(int $id)
 * @method string  getTeamId()
 * @method void    setTeamId(string $teamId)
 * @method int     getDecisionsEnabled()
 * @method void    setDecisionsEnabled(int $v)
 * @method int     getDecisionsLevelEnabled()
 * @method void    setDecisionsLevelEnabled(int $v)
 * @method int     getDecisionsActionMinLevel()
 * @method void    setDecisionsActionMinLevel(int $v)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 * @method int     getUpdatedAt()
 * @method void    setUpdatedAt(int $updatedAt)
 */
class DecisionTeamConfig extends Entity {

    protected string $teamId                = '';
    protected int    $decisionsEnabled          = 0;
    protected int    $decisionsLevelEnabled     = 0;
    protected int    $decisionsActionMinLevel   = 1;  // member
    protected int    $createdAt                 = 0;
    protected int    $updatedAt                 = 0;

    public function __construct() {
        $this->addType('decisionsEnabled',          'integer');
        $this->addType('decisionsLevelEnabled',     'integer');
        $this->addType('decisionsActionMinLevel',   'integer');
        $this->addType('createdAt',                 'integer');
        $this->addType('updatedAt',                 'integer');
    }
}
