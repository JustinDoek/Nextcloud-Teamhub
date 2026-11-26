<?php

declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\RoomBusyException;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Calendar\IMetadataProvider;
use OCP\Calendar\Room\IManager as IRoomManager;
use OCP\Calendar\Room\IRoom;
use OCP\Calendar\Room\IRoomMetadata;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * The room finder behind the meeting wizard and Add event (v4.20.0).
 *
 * ## What it is for
 *
 * The pickers used to offer every room on the instance in one flat dropdown,
 * with no idea whether the room was free. People picked a room that was
 * already taken, the invitation went out anyway, and the booking failed at
 * the room's end, or worse, was accepted twice. This service answers the two
 * questions the picker needs first: **where is each room and how many people
 * does it seat**, and **is it free in this slot**.
 *
 * ## Where the answers come from
 *
 * Both come from Nextcloud's public calendar API, never from another app's
 * tables (DESIGN §2.261):
 *
 *   - **Building, floor, capacity, room number and features** are metadata a
 *     room backend publishes on the room itself (`IRoomMetadata`). The same
 *     read `PresenceLocationSyncService` makes, so the building names here
 *     match the ones in Admin → TeamHub → Locations.
 *   - **Busy or free** is read from the room's own calendar, through
 *     `IManager::newQuery()` on the room's principal
 *     (`principals/calendar-rooms/{backend}-{id}`). A room's calendar holds
 *     every booking the room has received. When a search carries both a start
 *     and an end, Nextcloud expands recurring events itself, so a weekly
 *     booking is judged by the occurrence in the window rather than by the
 *     first one.
 *
 * `checkAvailability()` was the obvious candidate and does not work here: it
 * drops every address that is not a user account, so a room comes back with
 * no answer at all.
 *
 * ## The rules
 *
 *   - **Unknown is never free.** A room whose calendar cannot be read is
 *     `unknown`, and the picker says so. The one exception is a room that has
 *     no calendar yet: it has never received a booking, and Nextcloud creates
 *     the calendar on first delivery, so there is nothing to collide with.
 *   - **A booking the room declined, a cancelled event and a transparent one
 *     do not block.** Those are the cases where the room is in the event but
 *     not taken by it.
 *   - **Only times leave the server.** Busy blocks carry a start and an end.
 *     No title, organiser or attendee, which is what Nextcloud Calendar's own
 *     free/busy view shows anyone who can see the room.
 *   - **A room restricted to groups is hidden** from anyone in none of them,
 *     as Nextcloud Calendar does.
 */
class RoomAvailabilityService {

    /**
     * Metadata keys written as literals: `BUILDING_NAME` is `@since 35.0.0`
     * and we support 33 upwards. The others have existed since 17.0.0.
     */
    private const BUILDING_NAME = '{http://nextcloud.com/ns}room-building-name';

    /** Above this many rooms in one request the caller must narrow by building. */
    public const MAX_ROOMS_PER_CHECK = 150;

    /** The longest timeline window one request may ask for, in seconds. */
    public const MAX_WINDOW_SECONDS = 48 * 3600;

    private const CACHE_KEY = 'room-metadata-v1';
    private const CACHE_TTL = 120;

    /** @var array<string, array<string, mixed>>|null per-request memo */
    private ?array $metadataMemo = null;

    private ?ICache $cache = null;

    public function __construct(
        private RoomDiscoveryService $roomDiscovery,
        private IRoomManager $roomManager,
        private ICalendarManager $calendarManager,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        ICacheFactory $cacheFactory,
        private LoggerInterface $logger,
    ) {
        try {
            $this->cache = $cacheFactory->createLocal(Application::APP_ID . '-rooms');
        } catch (\Throwable) {
            $this->cache = null;
        }
    }

    // -------------------------------------------------------------------------
    // Catalogue
    // -------------------------------------------------------------------------

    /**
     * Every room the user may book, with where it is and how many it seats.
     *
     * The shape is the old `/rooms` entry plus the new fields, so a caller
     * that only knew `id`, `displayName`, `email` and `source` keeps working.
     *
     * @return list<array{key: string, id: string, displayName: string, email: string, source: string, kind: string, building: string, floor: string, roomNumber: string, capacity: ?int, features: list<string>, type: string}>
     */
    public function catalogue(string $uid): array {
        $metadata = $this->metadataByEmail();
        $userGroups = null;

        $out = [];
        foreach ($this->roomDiscovery->listAvailableRooms() as $room) {
            $email = strtolower(trim((string)($room['email'] ?? '')));
            $meta  = $metadata[$email] ?? null;

            $restrictions = $meta['groups'] ?? [];
            if ($restrictions !== []) {
                $userGroups ??= $this->groupsOf($uid);
                if (array_intersect($restrictions, $userGroups) === []) {
                    continue;
                }
            }

            $source = (string)($room['source'] ?? '');
            $kind   = (string)($room['kind'] ?? 'room');
            $id     = (string)($room['id'] ?? '');

            $out[] = [
                // Unique across sources: a RoomVox room and a CRM room may
                // share an id, and the picker keys its rows on this.
                'key'         => $source . ':' . $kind . ':' . $id,
                'id'          => $id,
                'displayName' => (string)($room['displayName'] ?? $id),
                'email'       => (string)($room['email'] ?? ''),
                'source'      => $source,
                'kind'        => $kind,
                'building'    => (string)($meta['building'] ?? ''),
                'floor'       => (string)($meta['floor'] ?? ''),
                'roomNumber'  => (string)($meta['roomNumber'] ?? ''),
                'capacity'    => $meta['capacity'] ?? null,
                'features'    => $meta['features'] ?? [],
                'type'        => (string)($meta['type'] ?? ''),
            ];
        }
        return $out;
    }

    /** @return list<string> */
    private function groupsOf(string $uid): array {
        $user = $this->userManager->get($uid);
        return $user === null ? [] : array_values(array_map('strval', $this->groupManager->getUserGroupIds($user)));
    }

    /**
     * The metadata every room backend publishes, keyed by lower-cased email.
     *
     * Email is the join key because it is the one thing both lists carry:
     * `RoomDiscoveryService` reads CRM rooms from Nextcloud's own room table,
     * whose ids are the backend's ids, and the metadata comes from the room
     * manager. Cached briefly: the picker reads the catalogue once on open and
     * again on every slot change, and for the Calendar resource app each room
     * is its own lookup.
     *
     * @return array<string, array<string, mixed>>
     */
    private function metadataByEmail(): array {
        if ($this->metadataMemo !== null) {
            return $this->metadataMemo;
        }
        $cached = $this->cache?->get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $this->metadataMemo = $cached;
        }

        $out = [];
        try {
            $backends = $this->roomManager->getBackends();
        } catch (\Throwable $e) {
            $this->logger->info('[TeamHub][RoomAvailabilityService] room backends unavailable; rooms listed without location', [
                'exception' => get_class($e), 'app' => Application::APP_ID,
            ]);
            return $this->metadataMemo = [];
        }

        foreach ($backends as $backend) {
            try {
                $backendId = $backend->getBackendIdentifier();
                $rooms = $backend->getAllRooms();
                // The Calendar resource app answers getAllRooms() with nothing
                // and publishes its rooms through listAllRooms() + getRoom().
                if ($rooms === []) {
                    $rooms = [];
                    foreach ($backend->listAllRooms() as $roomUid) {
                        try {
                            $room = $backend->getRoom($roomUid);
                        } catch (\Throwable) {
                            continue;
                        }
                        if ($room instanceof IRoom) {
                            $rooms[] = $room;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->debug('[TeamHub][RoomAvailabilityService] backend could not list its rooms', [
                    'exception' => get_class($e), 'app' => Application::APP_ID,
                ]);
                continue;
            }

            foreach ($rooms as $room) {
                if (!$room instanceof IRoom) {
                    continue;
                }
                try {
                    $email = strtolower(trim($room->getEMail()));
                    if ($email === '' || isset($out[$email])) {
                        continue;
                    }
                    $out[$email] = $this->describe($room, (string)$backendId);
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        $this->cache?->set(self::CACHE_KEY, $out, self::CACHE_TTL);
        return $this->metadataMemo = $out;
    }

    /**
     * @return array{building: string, floor: string, roomNumber: string, capacity: ?int, features: list<string>, type: string, groups: list<string>}
     */
    private function describe(IRoom $room, string $backendId): array {
        $building = '';
        $floor = '';
        $number = '';
        $capacity = null;
        $features = [];
        $type = '';

        if ($room instanceof IMetadataProvider) {
            $building = $this->readKey($room, self::BUILDING_NAME);
            if ($building === '') {
                // Before Nextcloud 35 a backend published the address only,
                // and clients were told to read the building from it. Same
                // fallback PresenceLocationSyncService uses, so both agree.
                $building = $this->readKey($room, IRoomMetadata::BUILDING_ADDRESS);
            }
            $floor  = $this->readKey($room, IRoomMetadata::BUILDING_STORY);
            $number = $this->readKey($room, IRoomMetadata::BUILDING_ROOM_NUMBER);
            $type   = $this->readKey($room, IRoomMetadata::ROOM_TYPE);

            $rawCapacity = $this->readKey($room, IRoomMetadata::CAPACITY);
            if ($rawCapacity !== '' && ctype_digit($rawCapacity) && (int)$rawCapacity > 0) {
                $capacity = (int)$rawCapacity;
            }

            $rawFeatures = $this->readKey($room, IRoomMetadata::FEATURES);
            if ($rawFeatures !== '') {
                $features = array_values(array_filter(array_map(
                    static fn (string $f): string => strtoupper(trim($f)),
                    explode(',', $rawFeatures)
                )));
            }
        }

        $groups = [];
        try {
            $groups = array_values(array_filter(array_map('strval', $room->getGroupRestrictions())));
        } catch (\Throwable) {
            $groups = [];
        }

        return [
            'building'   => $building,
            'floor'      => $floor,
            'roomNumber' => $number,
            'capacity'   => $capacity,
            'features'   => $features,
            'type'       => $type,
            'groups'     => $groups,
        ];
    }

    private function readKey(IMetadataProvider $room, string $key): string {
        try {
            if (!$room->hasMetadataForKey($key)) {
                return '';
            }
            return trim((string)($room->getMetadataForKey($key) ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    // -------------------------------------------------------------------------
    // Availability
    // -------------------------------------------------------------------------

    /**
     * Free or busy for the slot, and the busy blocks across a wider window.
     *
     * The window is what the picker draws as each room's timeline; the slot is
     * the meeting being planned. Both are Unix timestamps.
     *
     * v4.20.8: with `$series` (a recurring meeting's [start, end] dates, the
     * first one included) each room also carries `series: {total, conflicts,
     * checked}`, and a room free in the slot but taken on a later date is
     * `partial`.
     *
     * @param ?string $building only rooms in this building; '' for rooms with none; null for all
     * @param list<array{0: int, 1: int}> $series
     * @return array<string, array{status: string, busy: list<array{start: string, end: string}>, series?: array{total: int, conflicts: list<string>, checked: bool}}> keyed by catalogue key
     */
    public function availability(
        string $uid,
        int $slotStart,
        int $slotEnd,
        int $windowStart,
        int $windowEnd,
        ?string $building = null,
        array $series = [],
    ): array {
        if ($slotEnd <= $slotStart) {
            throw new \InvalidArgumentException('The end must be later than the start');
        }
        // The window always contains the slot, so the timeline can show it.
        $windowStart = min($windowStart, $slotStart);
        $windowEnd   = max($windowEnd, $slotEnd);
        if ($windowEnd - $windowStart > self::MAX_WINDOW_SECONDS) {
            throw new \InvalidArgumentException('The requested window is too long');
        }

        $rooms = $this->catalogue($uid);
        if ($building !== null) {
            $rooms = array_values(array_filter($rooms, static fn (array $r): bool => $r['building'] === $building));
        }
        if (count($rooms) > self::MAX_ROOMS_PER_CHECK) {
            throw new \InvalidArgumentException('Too many rooms to check at once. Choose a building first.');
        }

        $out = [];
        foreach ($rooms as $room) {
            $verdict = $this->roomAvailability($room, $slotStart, $slotEnd, $windowStart, $windowEnd);
            if ($series !== []) {
                // v4.20.8 — a recurring meeting: the room's other dates too.
                // Free on the first date but taken on a later one is
                // `partial`: still offered, never the best fit.
                $conflicts = $this->seriesConflicts($room, $series);
                if ($conflicts === null) {
                    $verdict['series'] = ['total' => count($series), 'conflicts' => [], 'checked' => false];
                } else {
                    $verdict['series'] = [
                        'total'     => count($series),
                        'conflicts' => array_map(static fn (int $ts): string => gmdate('Y-m-d\TH:i:s\Z', $ts), $conflicts),
                        'checked'   => true,
                    ];
                    if ($verdict['status'] === 'free' && $conflicts !== []) {
                        $verdict['status'] = 'partial';
                    }
                }
            }
            $out[$room['key']] = $verdict;
        }
        return $out;
    }

    /**
     * Refuse a recurring booking for a room that is taken on any of its
     * dates (v4.20.8). As with a single slot, a room whose calendar cannot
     * be read is let through.
     *
     * @param list<array{0: int, 1: int}> $occurrences [start, end] per date
     * @throws RoomBusyException naming the clashing dates
     */
    public function assertRoomFreeForSeries(string $uid, string $roomEmail, array $occurrences): void {
        $roomEmail = strtolower(trim($roomEmail));
        if ($roomEmail === '' || $occurrences === []) {
            return;
        }
        foreach ($this->catalogue($uid) as $room) {
            if (strtolower($room['email']) !== $roomEmail) {
                continue;
            }
            $conflicts = $this->seriesConflicts($room, $occurrences);
            if ($conflicts !== null && $conflicts !== []) {
                throw new RoomBusyException(
                    $room['displayName'],
                    null,
                    array_map(static fn (int $ts): string => gmdate('Y-m-d\TH:i:s\Z', $ts), $conflicts),
                );
            }
            return;
        }
    }

    /**
     * The starts of the occurrences the room is taken for, or null when its
     * calendar cannot be read. One calendar search covers the whole series.
     *
     * @param array<string, mixed> $room a catalogue entry
     * @param list<array{0: int, 1: int}> $occurrences
     * @return list<int>|null
     */
    private function seriesConflicts(array $room, array $occurrences): ?array {
        $first = PHP_INT_MAX;
        $last  = 0;
        foreach ($occurrences as [$s, $e]) {
            $first = min($first, $s);
            $last  = max($last, $e);
        }
        if ($last <= $first) {
            return [];
        }
        $intervals = $this->busyIntervals($room, $first, $last);
        if ($intervals === null) {
            return null;
        }
        $conflicts = [];
        foreach ($occurrences as [$s, $e]) {
            foreach ($intervals as [$bs, $be]) {
                if ($bs < $e && $be > $s) {
                    $conflicts[] = $s;
                    break;
                }
            }
        }
        return $conflicts;
    }

    /**
     * Refuse a booking for a room that is already taken in the slot.
     *
     * The picker shows availability, but the slot can be taken between the
     * picker loading and the user pressing Create, and an older client does
     * not check at all. So the server checks once more before anything is
     * written. A room whose calendar cannot be read is let through: refusing
     * on `unknown` would make a broken room backend stop every meeting.
     *
     * @throws RoomBusyException
     */
    public function assertRoomFree(string $uid, string $roomEmail, int $slotStart, int $slotEnd): void {
        $roomEmail = strtolower(trim($roomEmail));
        if ($roomEmail === '' || $slotEnd <= $slotStart) {
            return;
        }
        foreach ($this->catalogue($uid) as $room) {
            if (strtolower($room['email']) !== $roomEmail) {
                continue;
            }
            $verdict = $this->roomAvailability($room, $slotStart, $slotEnd, $slotStart, $slotEnd);
            if ($verdict['status'] === 'busy') {
                throw new RoomBusyException($room['displayName']);
            }
            return;
        }
    }

    /**
     * @param array<string, mixed> $room a catalogue entry
     * @return array{status: string, busy: list<array{start: string, end: string}>}
     */
    private function roomAvailability(array $room, int $slotStart, int $slotEnd, int $windowStart, int $windowEnd): array {
        $merged = $this->busyIntervals($room, $windowStart, $windowEnd);
        if ($merged === null) {
            return ['status' => 'unknown', 'busy' => []];
        }

        $busy = false;
        $blocks = [];
        foreach ($merged as [$s, $e]) {
            if ($s < $slotEnd && $e > $slotStart) {
                $busy = true;
            }
            $cs = max($s, $windowStart);
            $ce = min($e, $windowEnd);
            if ($ce > $cs) {
                $blocks[] = [
                    'start' => gmdate('Y-m-d\TH:i:s\Z', $cs),
                    'end'   => gmdate('Y-m-d\TH:i:s\Z', $ce),
                ];
            }
        }

        return ['status' => $busy ? 'busy' : 'free', 'busy' => $blocks];
    }

    /**
     * The room's bookings between two instants, merged, or null when its
     * calendar cannot be read (v4.20.8: split out of roomAvailability() so a
     * series is checked with one search).
     *
     * @param array<string, mixed> $room a catalogue entry
     * @return list<array{0: int, 1: int}>|null
     */
    private function busyIntervals(array $room, int $from, int $to): ?array {
        $principal = $this->principalFor($room);
        if ($principal === null) {
            return null;
        }

        try {
            $query = $this->calendarManager->newQuery($principal);
            $query->addType('VEVENT');
            $query->setTimerangeStart(new \DateTimeImmutable('@' . $from));
            $query->setTimerangeEnd(new \DateTimeImmutable('@' . $to));
            $results = $this->calendarManager->searchForPrincipal($query);
        } catch (\Throwable $e) {
            $this->logger->info('[TeamHub][RoomAvailabilityService] room calendar could not be read', [
                'roomKey'   => $room['key'],
                'exception' => get_class($e),
                'app'       => Application::APP_ID,
            ]);
            return null;
        }

        if ($results === []) {
            // Nothing in the window. Free, if the room has a calendar at all,
            // or if it has none yet: Nextcloud creates a room's calendar on
            // its first booking. A RoomVox room without one is the exception,
            // because RoomVox may keep its bookings elsewhere.
            try {
                $hasCalendar = $this->calendarManager->getCalendarsForPrincipal($principal) !== [];
            } catch (\Throwable) {
                return null;
            }
            if (!$hasCalendar && $room['source'] !== 'crm') {
                return null;
            }
            return [];
        }

        $intervals = [];
        foreach ($results as $result) {
            foreach (($result['objects'] ?? []) as $object) {
                $interval = self::blockingInterval($object, (string)$room['email']);
                if ($interval !== null) {
                    $intervals[] = $interval;
                }
            }
        }
        return self::mergeIntervals($intervals);
    }

    /**
     * The room's principal URI, built the way Nextcloud's room and resource
     * principal backends build it: `{collection}/{backendId}-{resourceId}`.
     *
     * @param array<string, mixed> $room
     */
    private function principalFor(array $room): ?string {
        $id = (string)($room['id'] ?? '');
        if ($id === '') {
            return null;
        }
        $backendId = match ($room['source'] ?? '') {
            'crm'     => 'calendar_resource_management',
            'roomvox' => 'roomvox',
            default   => null,
        };
        if ($backendId === null) {
            return null;
        }
        $collection = ($room['kind'] ?? 'room') === 'resource'
            ? 'principals/calendar-resources'
            : 'principals/calendar-rooms';
        return $collection . '/' . $backendId . '-' . $id;
    }

    /**
     * The interval one expanded event instance takes the room for, or null
     * when it does not take it.
     *
     * `$object` is one VEVENT as `ICalendar::search()` returns it: each
     * property a `[value, parameters]` pair, multi-valued properties a list of
     * those pairs.
     *
     * @param array<string, mixed> $object
     * @return array{0: int, 1: int}|null
     */
    public static function blockingInterval(array $object, string $roomEmail): ?array {
        $start = $object['DTSTART'][0] ?? null;
        if (!$start instanceof \DateTimeInterface) {
            return null;
        }

        if (strtoupper(trim((string)($object['STATUS'][0] ?? ''))) === 'CANCELLED') {
            return null;
        }
        if (strtoupper(trim((string)($object['TRANSP'][0] ?? ''))) === 'TRANSPARENT') {
            return null;
        }

        $roomEmail = strtolower(trim($roomEmail));
        if ($roomEmail !== '') {
            foreach (($object['ATTENDEE'] ?? []) as $attendee) {
                if (!is_array($attendee)) {
                    continue;
                }
                $address = strtolower(trim((string)($attendee[0] ?? '')));
                if (preg_replace('/^mailto:/', '', $address) !== $roomEmail) {
                    continue;
                }
                $partstat = $attendee[1]['PARTSTAT'] ?? null;
                if ($partstat !== null && strtoupper(trim((string)$partstat)) === 'DECLINED') {
                    return null;
                }
            }
        }

        $startTs = $start->getTimestamp();
        $end = $object['DTEND'][0] ?? null;
        if ($end instanceof \DateTimeInterface) {
            $endTs = $end->getTimestamp();
        } elseif (isset($object['DURATION'][0]) && is_string($object['DURATION'][0])) {
            try {
                $interval = \Sabre\VObject\DateTimeParser::parseDuration($object['DURATION'][0]);
                $endTs = \DateTimeImmutable::createFromInterface($start)->add($interval)->getTimestamp();
            } catch (\Throwable) {
                return null;
            }
        } elseif (self::isDateOnly($object['DTSTART'])) {
            // RFC 5545: an all-day event with neither DTEND nor DURATION lasts one day.
            $endTs = $startTs + 86400;
        } else {
            // A timed event with no end is an instant, and takes nothing.
            return null;
        }

        return $endTs > $startTs ? [$startTs, $endTs] : null;
    }

    /** @param array<int, mixed> $pair */
    private static function isDateOnly(array $pair): bool {
        $value = $pair[1]['VALUE'] ?? null;
        return $value !== null && strtoupper(trim((string)$value)) === 'DATE';
    }

    /**
     * @param list<array{0: int, 1: int}> $intervals
     * @return list<array{0: int, 1: int}> sorted, non-overlapping, touching blocks joined
     */
    public static function mergeIntervals(array $intervals): array {
        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $out = [];
        foreach ($intervals as [$s, $e]) {
            $last = count($out) - 1;
            if ($last >= 0 && $s <= $out[$last][1]) {
                $out[$last][1] = max($out[$last][1], $e);
                continue;
            }
            $out[] = [$s, $e];
        }
        return $out;
    }
}
