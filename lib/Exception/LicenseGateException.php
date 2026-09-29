<?php
declare(strict_types=1);

namespace OCA\TeamHub\Exception;

/**
 * Thrown when a request would create or extend Advanced-project state but
 * the instance's license doesn't currently permit it (v3.100.0, Track F).
 *
 * Distinct from InvalidArgumentException so the controller layer can map
 * it to a specific HTTP status (403 Forbidden) and so the frontend can
 * distinguish "your input was wrong" from "your license doesn't cover
 * this action" — the latter needs a "renew / buy" call-to-action, not a
 * validation error.
 *
 * $enforcementLevel is one of:
 *   'unlicensed' — no key entered
 *   'grace'      — paid key expired < PAID_GRACE_DAYS ago; existing teams still write
 *   'soft-lock'  — paid key expired ≥ PAID_GRACE_DAYS ago, or a trial expired at all;
 *                  existing teams read-only
 *
 * See LicenseService for the full lifecycle.
 */
class LicenseGateException extends \RuntimeException {

    public function __construct(
        private string $enforcementLevel,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getEnforcementLevel(): string {
        return $this->enforcementLevel;
    }
}
