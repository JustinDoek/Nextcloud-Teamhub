<?php
declare(strict_types=1);

namespace OCA\TeamHub\Exception;

/**
 * Thrown by services when a Nextcloud app they depend on (Talk, Deck,
 * Tasks, Calendar, IntraVox, …) is not installed or is disabled.
 *
 * Controllers translate to HTTP 422 Unprocessable Entity — the request
 * is well-formed but the environment cannot satisfy it. Distinct from
 * 404 (target does not exist) and 500 (unexpected failure).
 */
class AppNotAvailableException extends \RuntimeException {

    public function __construct(
        string $message = 'Required app is not available',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
