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
 *                    | discovered_managed
 * status values    : active | pending | ignored | disconnected | managed
 *                    `managed` (v4.20.48): another app claims the folder;
 *                    decided_by is then `app:<app id>`, see managingAppId()
 * risk_status values: none | owner_disabled | transfer_failed
 */
class TeamAppResource extends Entity {

    /** Another app manages this resource; nobody is asked to review it. */
    public const STATUS_MANAGED = 'managed';

    /** Origin of a row that was claimed the first time it was seen. */
    public const ORIGIN_MANAGED = 'discovered_managed';

    /** `decided_by` prefix for an app; a Nextcloud uid cannot contain a colon. */
    private const MANAGED_BY_PREFIX = 'app:';

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

    /** The `decided_by` value for a resource this app manages. */
    public static function managedBy(string $appId): string {
        return self::MANAGED_BY_PREFIX . $appId;
    }

    /** The id of the app managing this resource, or null when none does. */
    public function managingAppId(): ?string {
        if ($this->status !== self::STATUS_MANAGED) {
            return null;
        }
        $by = (string)$this->decidedBy;
        return str_starts_with($by, self::MANAGED_BY_PREFIX)
            ? substr($by, strlen(self::MANAGED_BY_PREFIX))
            : '';
    }
}
