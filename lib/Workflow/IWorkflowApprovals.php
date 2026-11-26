<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow;

/**
 * A definition with approval steps (v4.20.58, `docs/service-builder.md`
 * § 14.4).
 *
 * Beside `IWorkflowDefinition::resolveActor()` rather than inside it,
 * because an approver depends on **who asks**: the requester's manager, and
 * never the requester themself.
 */
interface IWorkflowApprovals {

    /**
     * Who approves this step for a request by `$requesterUid`, or null when
     * nobody can — the engine then refuses to start the request.
     *
     * @param array<string, mixed> $data the instance data, as `validateStart()` returned it
     */
    public function resolveApprover(WorkflowStepDefinition $step, string $requesterUid, array $data): ?WorkflowActor;
}
