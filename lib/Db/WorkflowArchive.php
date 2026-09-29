<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One row of `teamhub_wf_archive` — **the authoritative record of a
 * completed licensed workflow** (WorkflowHub phase 6, v4.10.21;
 * `docs/workflow-archiving.md`).
 *
 * Written once, inside the transaction that ends the workflow, and never
 * updated afterwards. It is deliberately *thin*: it does not hold the
 * request, the history, the notes or the documents, because those already
 * exist in `teamhub_wf_instance`, `_step`, `_participant`, `_event` and
 * `_attachment` and copying them would create the second independent copy
 * the product rule forbids. What it holds is what a record needs that the
 * running workflow never had —
 *
 *   - `refNumber`, the human reference the archive is quoted by;
 *   - `instanceId`, the one link to everything else, and the reason the
 *     rows of an archived workflow are never deleted while it stands;
 *   - `eventCount` + `eventSeal`, a hash chain over the event log as it
 *     stood at archival, so the immutability of the history is a property
 *     that can be *checked* rather than a rule that is merely stated;
 *   - the retention metadata (`retentionUntil`, `retentionPolicy`,
 *     `legalHold`);
 *   - `definitionKey` + `definitionVersion` copied from the instance, so a
 *     record stays bound to the version of the definition it was created
 *     on even if the instance row is one day migrated.
 *
 * The outcome fields (`wfStatus`, `outcome`, `completedAt`, `completedBy`)
 * are copied for one reason only: **searching and sorting an archive must
 * not require reading every workflow**. They are filter keys, and the
 * projections render the outcome from the instance, not from here.
 *
 * @method int     getInstanceId()
 * @method void    setInstanceId(int $v)
 * @method string  getRefNumber()
 * @method void    setRefNumber(string $v)
 * @method string  getDefinitionKey()
 * @method void    setDefinitionKey(string $v)
 * @method int     getDefinitionVersion()
 * @method void    setDefinitionVersion(int $v)
 * @method string  getTeamId()
 * @method void    setTeamId(string $v)
 * @method string  getServiceTeamId()
 * @method void    setServiceTeamId(string $v)
 * @method string  getSubjectType()
 * @method void    setSubjectType(string $v)
 * @method string  getSubjectId()
 * @method void    setSubjectId(string $v)
 * @method string  getWfStatus()
 * @method void    setWfStatus(string $v)
 * @method string  getOutcome()
 * @method void    setOutcome(string $v)
 * @method string  getStartedBy()
 * @method void    setStartedBy(string $v)
 * @method int     getStartedAt()
 * @method void    setStartedAt(int $v)
 * @method string  getCompletedBy()
 * @method void    setCompletedBy(string $v)
 * @method int     getCompletedAt()
 * @method void    setCompletedAt(int $v)
 * @method int     getArchivedAt()
 * @method void    setArchivedAt(int $v)
 * @method int     getEventCount()
 * @method void    setEventCount(int $v)
 * @method string  getEventSeal()
 * @method void    setEventSeal(string $v)
 * @method int     getRetentionUntil()
 * @method void    setRetentionUntil(int $v)
 * @method string  getRetentionPolicy()
 * @method void    setRetentionPolicy(string $v)
 * @method int     getLegalHold()
 * @method void    setLegalHold(int $v)
 */
class WorkflowArchive extends Entity {

    protected int    $instanceId        = 0;
    protected string $refNumber         = '';
    protected string $definitionKey     = '';
    protected int    $definitionVersion = 1;
    protected string $teamId            = '';
    /** '' when no service team handled the workflow. */
    protected string $serviceTeamId     = '';
    protected string $subjectType       = '';
    protected string $subjectId         = '';
    /**
     * The terminal instance status. Not named `status`: `WorkflowInstance`
     * already owns that word for the live row, and a reader of a join would
     * have to guess which of the two they were looking at.
     */
    protected string $wfStatus          = '';
    protected string $outcome           = '';
    protected string $startedBy         = '';
    protected int    $startedAt         = 0;
    protected string $completedBy       = '';
    protected int    $completedAt       = 0;
    protected int    $archivedAt        = 0;
    protected int    $eventCount        = 0;
    protected string $eventSeal         = '';
    /** Unix seconds after which the record may be removed; 0 = keep indefinitely. */
    protected int    $retentionUntil    = 0;
    /** The policy that produced `retentionUntil`, for the record's own explanation. */
    protected string $retentionPolicy   = '';
    /** 1 suspends retention entirely. Nothing sets it yet — see the deferred list. */
    protected int    $legalHold         = 0;

    public function __construct() {
        $this->addType('instanceId',        'integer');
        $this->addType('definitionVersion', 'integer');
        $this->addType('startedAt',         'integer');
        $this->addType('completedAt',       'integer');
        $this->addType('archivedAt',        'integer');
        $this->addType('eventCount',        'integer');
        $this->addType('retentionUntil',    'integer');
        $this->addType('legalHold',         'integer');
    }

    public function isOnLegalHold(): bool {
        return $this->getLegalHold() === 1;
    }
}
