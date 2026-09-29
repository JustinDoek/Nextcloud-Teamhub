<?php
declare(strict_types=1);

namespace OCA\TeamHub\Exception;

/**
 * A participant asked for a status update again before the interval was
 * over (WorkflowHub phase 2). Maps to 429 at the edge; the message says
 * when they may ask again.
 */
class WorkflowRateLimitException extends \RuntimeException {

    public function __construct(
        string $message = 'You asked for an update recently; try again later.',
        public readonly int $retryAfterSeconds = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
