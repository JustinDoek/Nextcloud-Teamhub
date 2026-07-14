<?php
declare(strict_types=1);

namespace OCA\TeamHub\Exception;

/**
 * Thrown by services when the current user lacks the required
 * membership, ownership, or admin level for the requested operation.
 *
 * Controllers translate to HTTP 403 Forbidden — the class of the
 * exception carries the status, so callers do not need to match on the
 * message text (which is user-facing and localisable).
 */
class AccessDeniedException extends \RuntimeException {

    public function __construct(
        string $message = 'Access denied',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
