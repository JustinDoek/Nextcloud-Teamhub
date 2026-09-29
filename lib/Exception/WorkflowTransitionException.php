<?php
declare(strict_types=1);

namespace OCA\TeamHub\Exception;

/**
 * A workflow or step was asked to move to a status it cannot reach from
 * where it is — the step already completed, the workflow already ended, a
 * start on a step that is not available. Maps to 409 at the edge: the
 * caller's view is stale, and a refresh shows why.
 */
class WorkflowTransitionException extends \RuntimeException {

    public function __construct(
        string $message = 'That workflow step is no longer in a state that allows this.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
