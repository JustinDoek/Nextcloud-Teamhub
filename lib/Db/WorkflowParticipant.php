<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One row of `teamhub_wf_participant` (WorkflowHub phase 1, v4.10.13).
 *
 * An actor connected to an instance and why (`wfRole`, one of
 * `OCA\TeamHub\Workflow\WorkflowParticipantRole`). Rows are added by the
 * engine and never removed by a step transition — a participant whose
 * step is done stays connected until the workflow ends, which is what
 * lets them keep seeing it.
 *
 * @method int     getInstanceId()
 * @method void    setInstanceId(int $v)
 * @method string  getActorType()
 * @method void    setActorType(string $v)
 * @method string  getActorId()
 * @method void    setActorId(string $v)
 * @method string  getWfRole()
 * @method void    setWfRole(string $v)
 * @method int     getAddedAt()
 * @method void    setAddedAt(int $v)
 * @method ?string getAddedBy()
 * @method void    setAddedBy(?string $v)
 * @method ?int    getRemovedAt()
 * @method void    setRemovedAt(?int $v)
 */
class WorkflowParticipant extends Entity {

    protected int     $instanceId = 0;
    protected string  $actorType  = '';
    protected string  $actorId    = '';
    protected string  $wfRole     = '';
    protected int     $addedAt    = 0;
    protected ?string $addedBy    = null;
    protected ?int    $removedAt  = null;

    public function __construct() {
        $this->addType('instanceId', 'integer');
        $this->addType('addedAt',    'integer');
        $this->addType('removedAt',  'integer');
    }
}
