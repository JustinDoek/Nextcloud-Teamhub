<?php

declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Exception\LicenseGateException;

/**
 * A recurring meeting from request to calendar (v4.20.8).
 *
 * One place for the three things both create paths (Add event and the
 * meeting wizard) and the room finder need from a repeat setting:
 *
 *   1. **Licence.** Recurring meetings are a licensed feature. An unlicensed
 *      instance never gets a series written, whatever the client sends; the
 *      client hides the Repeat field, but that is presentation.
 *   2. **The rule and its dates**, in the organiser's time zone
 *      (`MeetingRecurrence`).
 *   3. **The room on every date.** A room booked for one Tuesday of twelve
 *      is a failed booking eleven weeks from now, so the server checks each
 *      date up to a year ahead (at most `MAX_CHECKED` dates) before writing.
 *
 * DESIGN §2.263.
 */
class MeetingSeriesService {

    /** How far ahead a series is checked, and drawn in the finder. */
    public const HORIZON_SECONDS = 365 * 86400;

    /** At most this many dates are checked (a daily series for a year is 365). */
    public const MAX_CHECKED = 200;

    public function __construct(
        private TimezoneService $timezones,
        private RoomAvailabilityService $rooms,
        private LicenseService $license,
    ) {
    }

    /**
     * The request's repeat setting, validated, or null for a single meeting.
     *
     * @return array<string, mixed>|null
     * @throws LicenseGateException when the instance is unlicensed
     * @throws \InvalidArgumentException when the setting is malformed
     */
    public function fromRequest(mixed $raw): ?array {
        $rec = MeetingRecurrence::parse($raw);
        if ($rec === null) {
            return null;
        }
        if (!$this->license->isLicensed()) {
            throw new LicenseGateException(
                $this->license->getEnforcementLevel(),
                'Recurring meetings require an active TeamHub licence.',
            );
        }
        return $rec;
    }

    /**
     * The RRULE and the dates of a series whose first meeting is the slot.
     *
     * @param array<string, mixed> $rec from fromRequest()
     * @return array{rrule: string, occurrences: list<array{0: int, 1: int}>}
     */
    public function plan(array $rec, int $slotStart, int $slotEnd, string $uid): array {
        if ($slotEnd <= $slotStart) {
            throw new \InvalidArgumentException('The end must be later than the start');
        }
        $local = (new \DateTimeImmutable('@' . $slotStart))->setTimezone($this->timezones->forUser($uid));
        $rrule = MeetingRecurrence::toRrule($rec, $local);
        $occurrences = MeetingRecurrence::occurrences(
            $rrule,
            $local,
            $slotEnd - $slotStart,
            $slotStart + self::HORIZON_SECONDS,
            self::MAX_CHECKED,
        );
        return ['rrule' => $rrule, 'occurrences' => $occurrences];
    }

    /**
     * Refuse the series when the room is taken on any of its dates.
     *
     * @param list<array{0: int, 1: int}> $occurrences
     * @throws \OCA\TeamHub\Exception\RoomBusyException
     */
    public function assertRoomFree(string $uid, string $roomEmail, array $occurrences): void {
        $this->rooms->assertRoomFreeForSeries($uid, $roomEmail, $occurrences);
    }
}
