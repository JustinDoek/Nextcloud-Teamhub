<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_team_app_resources rows.
 *
 * Tracks the many-to-many relationship between teams and their connected
 * NC app resources (Deck boards, Calendars, Files folders, Talk rooms).
 *
 * origin values    : teamhub_create | teamhub_connect |
 *                    discovered_auto_accepted | discovered_pending
 * status values    : active | pending | ignored
 * risk_status values: none | owner_disabled | transfer_failed
 */
class TeamAppResource extends Entity {

    /** @var string circles_circle.unique_id */
    protected string $teamId = '';

    /** @var string deck | calendar | files | talk */
    protected string $appId = '';

    /** @var string board_id, calendar_id, file_source id, talk room token */
    protected string $resourceId = '';

    /** @var string How this row was created — see class docblock */
    protected string $origin = '';

    /** @var string Current presence state — see class docblock */
    protected string $status = 'active';

    /** @var string Owner-departure risk flag — see class docblock */
    protected string $riskStatus = 'none';

    /** @var int Display order within the app's resource list */
    protected int $displayOrder = 0;

    /** @var string|null UID of admin who accepted or ignored this resource */
    protected ?string $decidedBy = null;

    /** @var int|null Unix timestamp of the accept/ignore decision */
    protected ?int $decidedAt = null;

    /** @var int|null Unix timestamp of the last risk_status change */
    protected ?int $riskSetAt = null;

    /** @var int Unix timestamp of row creation */
    protected int $createdAt = 0;

    /** @var string|null UID of the NC user who owns the underlying resource */
    protected ?string $ownerUid = null;

    /** @var int Unix timestamp of last update */
    protected int $updatedAt = 0;

    public function __construct() {
        $this->addType('displayOrder', 'integer');
        $this->addType('decidedAt',    'integer');
        $this->addType('riskSetAt',    'integer');
        $this->addType('createdAt',    'integer');
        $this->addType('updatedAt',    'integer');
    }
}
