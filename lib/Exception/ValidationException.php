<?php
declare(strict_types=1);

namespace OCA\TeamHub\Exception;

/**
 * Thrown by services when the caller-supplied input fails validation.
 * Controllers translate to HTTP 400 Bad Request and pass the message
 * through to the client — validation messages are the one case where
 * the exception text is intended for the end-user.
 */
class ValidationException extends \RuntimeException {

    public function __construct(
        string $message = 'Invalid input',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
