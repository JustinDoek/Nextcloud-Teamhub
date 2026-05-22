<?php
declare(strict_types=1);

namespace OCA\TeamHub\Exception;

/**
 * Thrown by presence-module services when a request must be rejected because
 * it conflicts with existing state. Maps to HTTP 409 Conflict at the
 * controller boundary.
 *
 * Examples:
 *   - Deleting a built-in presence type
 *   - Deleting a presence type still referenced by templates or slots
 *   - Deleting a building/floor/room whose subtree contains referenced rooms
 *   - Adding a holiday for a date that already exists
 *
 * The optional $affectedCount is the count of in-use rows the operation
 * would have touched — surfaced in the controller payload so the admin UI
 * can show "in use by N entries" rather than a bare conflict message.
 */
class PresenceConflictException extends \RuntimeException {

    public function __construct(
        string $message,
        private int $affectedCount = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getAffectedCount(): int {
        return $this->affectedCount;
    }
}
