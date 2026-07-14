<?php
declare(strict_types=1);

namespace OCA\TeamHub\Exception;

/**
 * Thrown by services when the addressed entity (team, message,
 * decision, milestone, etc.) does not exist or is not visible to the
 * caller.
 *
 * Controllers translate to HTTP 404 Not Found. The class of the
 * exception is the signal; the message is user-facing and localisable
 * and must not be pattern-matched.
 */
class NotFoundException extends \RuntimeException {

    public function __construct(
        string $message = 'Not found',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
