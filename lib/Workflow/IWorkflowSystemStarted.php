<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow;

/**
 * A definition only TeamHub itself starts, never a person (v4.10.50).
 *
 * The first is the team adoption (`TeamAdoptionDefinition`): a team made
 * outside TeamHub is found by a background sweep, and the request to accept
 * it is opened on the owner's behalf. Nobody asks for it by hand, so it has
 * no card and its `isStartable()` answers false — which is what keeps the
 * public start route (`WorkflowEngine::create()`) refusing it.
 *
 * `WorkflowEngine::createForSystem()` starts only definitions carrying this
 * marker; it skips `isStartable()` and `canStart()`, because the caller is
 * server code that has already decided, not a user asking.
 */
interface IWorkflowSystemStarted {
}
