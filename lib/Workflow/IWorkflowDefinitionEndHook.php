<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow;

use OCA\TeamHub\Db\WorkflowInstance;

/**
 * Told when a workflow of this definition is rejected or withdrawn
 * (v4.10.50). Optional.
 *
 * The counterpart of `IWorkflowDefinitionHooks::onStepCompleted()` for the
 * two endings that complete no step. The team adoption keeps its decision in
 * a table of its own (`teamhub_team_adoption`); a *Decline* pressed on the
 * My Work row has to land there too, or the grid would keep offering a team
 * the request already declined.
 *
 * Called inside the transaction, after the instance has ended. A throw
 * rolls the ending back; the hook must be idempotent, because the grid may
 * already have recorded the decision before it ended the workflow.
 */
interface IWorkflowDefinitionEndHook {

    /**
     * @param string      $outcome `rejected` or `cancelled`
     * @param string|null $reason  what the person gave, if anything
     */
    public function onEnded(WorkflowInstance $instance, string $outcome, string $uid, ?string $reason): void;
}
