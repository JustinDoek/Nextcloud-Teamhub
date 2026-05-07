<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_pending_dels rows.
 *
 * Status values: 'pending' | 'completed' | 'restored' | 'failed'
 */
class PendingDeletion extends Entity {

    /** @var string circles_circle.unique_id */
    protected string $teamId = '';

    /** @var string Captured team display name */
    protected string $teamName = '';

    /** @var int Unix timestamp when archive was initiated */
    protected int $archivedAt = 0;

    /** @var int Unix timestamp when hard-delete should fire */
    protected int $hardDeleteAt = 0;

    /** @var string|null NC-Files path to the produced ZIP */
    protected ?string $archivePath = null;

    /** @var string UID of the owner who initiated the archive */
    protected string $archivedBy = '';

    /** @var string pending | completed | restored | failed */
    protected string $status = 'pending';

    /** @var string|null Human-readable failure reason */
    protected ?string $failureReason = null;

    /** @var int|null Size of produced ZIP in bytes */
    protected ?int $archiveBytes = null;

    /**
     * JSON blob of suspended app resource IDs needed to resume on restore.
     * Shape: { talk: {room_id}, files: {share_id, uid_initiator, file_source, permissions},
     *          calendar: {calendar_id, principal_uri}, deck: {board_id, acl_table, type} }
     * Only keys for apps that were active and suspended are present.
     */
    protected ?string $suspendedResources = null;

    public function __construct() {
        $this->addType('archivedAt',   'integer');
        $this->addType('hardDeleteAt', 'integer');
        $this->addType('archiveBytes', 'integer');
    }
}
