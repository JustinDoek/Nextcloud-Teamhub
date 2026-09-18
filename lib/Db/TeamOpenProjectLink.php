<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_openproject_link — one team's link to one OpenProject
 * project (v4.9.3, OpenProject Phase 1).
 *
 * project_id:         OpenProject's numeric project id. The relationship.
 * project_identifier: the URL slug at the last successful read. Deep links
 *                     only — a rename in OpenProject is healed on the next
 *                     read, never fatal.
 * project_name:       display snapshot, same refresh rule.
 * host:               the OpenProject instance URL the link was made against.
 *                     Compared against the integration app's current URL on
 *                     every read; a mismatch makes the link "stale", which is
 *                     reported, not silently reinterpreted.
 * last_validated_at:  null until the first successful project read after
 *                     linking; then the moment of the most recent one.
 *
 * @method int     getId()
 * @method void    setId(int $id)
 * @method string  getTeamId()
 * @method void    setTeamId(string $teamId)
 * @method int     getProjectId()
 * @method void    setProjectId(int $projectId)
 * @method string  getProjectIdentifier()
 * @method void    setProjectIdentifier(string $projectIdentifier)
 * @method string  getProjectName()
 * @method void    setProjectName(string $projectName)
 * @method string  getHost()
 * @method void    setHost(string $host)
 * @method string  getCreatedBy()
 * @method void    setCreatedBy(string $createdBy)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 * @method int     getUpdatedAt()
 * @method void    setUpdatedAt(int $updatedAt)
 * @method ?int    getLastValidatedAt()
 * @method void    setLastValidatedAt(?int $lastValidatedAt)
 */
class TeamOpenProjectLink extends Entity {

    protected string  $teamId            = '';
    protected int     $projectId         = 0;
    protected string  $projectIdentifier = '';
    protected string  $projectName       = '';
    protected string  $host              = '';
    protected string  $createdBy         = '';
    protected int     $createdAt         = 0;
    protected int     $updatedAt         = 0;
    protected ?int    $lastValidatedAt   = null;

    public function __construct() {
        $this->addType('projectId',       'integer');
        $this->addType('createdAt',       'integer');
        $this->addType('updatedAt',       'integer');
        $this->addType('lastValidatedAt', 'integer');
    }
}
