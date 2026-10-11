<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One row of `teamhub_wf_decision` — a decision linked to a service request
 * (v4.11.12).
 *
 * A **pointer to a decision that lives in the Decisions module**, never a
 * copy of it: the question, the answer, the approvers and the audit trail
 * stay where the team made them, and this row holds the one fact the
 * Decisions module cannot hold — that this request is about that decision,
 * who said so, and when.
 *
 * `teamId` is the decision's team, which is always the service team handling
 * the request (Justin, 2026-09-30: a decision is asked for and recorded on
 * the desk's side). It is stored rather than joined so a row can be rendered
 * and a stale link recognised without reading the decision back, and so the
 * team check does not depend on the decision still existing.
 *
 * **Linking grants nothing.** Whether the reader may open the decision is
 * answered by the Decisions module every time, against that reader — a link
 * is a statement about the request, not an access path. What makes a
 * decision readable outside its team is its own `is_public` flag.
 *
 * @method int    getInstanceId()
 * @method void   setInstanceId(int $v)
 * @method int    getDecisionId()
 * @method void   setDecisionId(int $v)
 * @method string getTeamId()
 * @method void   setTeamId(string $v)
 * @method string getAddedBy()
 * @method void   setAddedBy(string $v)
 * @method int    getAddedAt()
 * @method void   setAddedAt(int $v)
 */
class WorkflowDecision extends Entity {

    protected int    $instanceId = 0;
    protected int    $decisionId = 0;
    protected string $teamId     = '';
    protected string $addedBy    = '';
    protected int    $addedAt    = 0;

    public function __construct() {
        $this->addType('instanceId', 'integer');
        $this->addType('decisionId', 'integer');
        $this->addType('addedAt',    'integer');
    }
}
