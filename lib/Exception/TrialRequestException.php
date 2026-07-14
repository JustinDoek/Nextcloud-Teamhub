<?php
declare(strict_types=1);

namespace OCA\TeamHub\Exception;

/**
 * Thrown by LicenseService::requestTrial when the licensing back-end
 * refuses. Carries the back-end's HTTP status so the controller can
 * map it directly to the client response without substring-matching
 * the message.
 *
 * Codes used today:
 *   409 — already-used gate tripped (one-shot per UUID)
 *   429 — per-IP rate limit tripped on the back-end
 *   400 — everything else (bad payload, unreachable, etc.)
 */
class TrialRequestException extends \RuntimeException {

    public function __construct(
        string $message,
        private int $httpStatus = 400,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getHttpStatus(): int {
        return $this->httpStatus;
    }
}
