<?php
declare(strict_types=1);

namespace OCA\TeamHub\Exception;

/**
 * The room picked for a meeting is already booked in that slot (v4.20.0).
 *
 * Thrown by `RoomAvailabilityService::assertRoomFree()` before anything is
 * written, and mapped to 409 Conflict with `code: room_busy` at the
 * controller boundary so the client can show its own translated sentence.
 *
 * v4.20.8: a recurring meeting is refused when the room is taken on any of
 * its dates; `getDates()` lists those dates (ISO 8601 instants, the start of
 * each clashing occurrence) so the client can name them. Empty for a single
 * meeting.
 */
class RoomBusyException extends \RuntimeException {

    /**
     * @param list<string> $dates
     */
    public function __construct(
        private string $roomName,
        ?\Throwable $previous = null,
        private array $dates = [],
    ) {
        parent::__construct('The room is already booked at that time', 0, $previous);
    }

    public function getRoomName(): string {
        return $this->roomName;
    }

    /** @return list<string> */
    public function getDates(): array {
        return $this->dates;
    }
}
