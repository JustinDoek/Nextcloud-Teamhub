<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_project_member — one row per (team, user) that records a
 * project participant's available time budget (v3.96.0 — Time investment).
 *
 * availableMinutes = 0 means "uncapped": the report accumulates logged
 * minutes without a Remaining column for that user. Positive integer minutes
 * cap the allowance so the report can show Remaining = available − logged.
 *
 * A row's mere existence marks the user as a project participant — they
 * appear in the per-member report grid regardless of whether they've logged
 * any time yet. Team members with no row are not project participants but
 * can still view the tab if their role level meets project.timeViewMinLevel.
 *
 * @method int    getId()
 * @method void   setId(int $id)
 * @method string getTeamId()
 * @method void   setTeamId(string $teamId)
 * @method string getUserId()
 * @method void   setUserId(string $userId)
 * @method int    getAvailableMinutes()
 * @method void   setAvailableMinutes(int $availableMinutes)
 * @method int    getCreatedAt()
 * @method void   setCreatedAt(int $createdAt)
 * @method int    getUpdatedAt()
 * @method void   setUpdatedAt(int $updatedAt)
 */
class ProjectMember extends Entity {

    protected string $teamId           = '';
    protected string $userId           = '';
    protected int    $availableMinutes = 0;
    protected int    $createdAt        = 0;
    protected int    $updatedAt        = 0;

    public function __construct() {
        $this->addType('availableMinutes', 'integer');
        $this->addType('createdAt',        'integer');
        $this->addType('updatedAt',        'integer');
    }
}
