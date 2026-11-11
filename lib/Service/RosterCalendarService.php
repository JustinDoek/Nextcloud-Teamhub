<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\RosterCalendarCopy;
use OCA\TeamHub\Db\RosterCalendarCopyMapper;
use OCA\TeamHub\Db\RosterShift;
use OCA\TeamHub\Db\RosterShiftMapper;
use OCA\TeamHub\Db\ShiftType;
use OCA\TeamHub\Db\ShiftTypeMapper;
use OCA\TeamHub\Exception\AppNotAvailableException;
use OCA\TeamHub\Util\IcalText;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A member's published week, copied into their own calendar in one action
 * (v4.14.8, DESIGN §2.250).
 *
 * ## Why this writes into a personal calendar when §2.215 stopped doing that
 *
 * §2.215 stopped TeamHub writing presence into members' calendars because it
 * wrote **unasked** and could not reliably take the events back out; §2.246
 * deleted the writer and recorded how to find what it left. Three things make
 * this a different object rather than the same mistake again:
 *
 *   - **The member asks.** Nothing is written until they press the button,
 *     for a week they can see, into a calendar they pick.
 *   - **Every event is findable.** A fixed UID per (shift, person), the
 *     `X-TEAMHUB-ROSTER:1` marker, and a row in `teamhub_roster_cal` holding
 *     the calendar and the object's address.
 *   - **Losing a shift takes the event out.** Unassigned, handed over, the
 *     week taken back to concept, the shift deleted: `removeFor()` runs on
 *     every one of those paths in `RosterService`. Gaining a shift never
 *     writes by itself, because the member did not ask for that one.
 *
 * And the rule §2.246 kept, kept here: **never touch an event that lacks the
 * marker.**
 *
 * ## Why not a subscription feed
 *
 * Nextcloud leaves subscribed calendars out of free/busy, so the member's
 * whole choice (show these hours as busy, or not) would do nothing.
 *
 * ## Why the CalDAV backend and not `OCP\Calendar`
 *
 * The event has no attendees, so nothing needs iTIP scheduling, which is the
 * only reason `ActivityService` routes through `ICreateFromString` (§2.24).
 * And the public API has no read or delete for an object by URI on the
 * versions we support, while taking the event back out is half of this
 * feature. `OpenProjectMeetingMirrorService` keeps calendar copies the same
 * way, through the same four backend calls.
 *
 * Times are written in **UTC**, converted from the member's own timezone. A
 * shift type's hours are wall-clock hours where the person works, and a UTC
 * instant needs no VTIMEZONE block that every client then has to agree on.
 */
class RosterCalendarService {

    /** The discriminator. An event without it is never touched (§2.246). */
    public const MARKER = 'X-TEAMHUB-ROSTER:1';

    public const PRODID = '-//TeamHub//TeamHub Roster//EN';

    private const PREF_CALENDAR = 'roster_calendar_uri';
    private const PREF_TRANSP   = 'roster_calendar_transp';

    private const ISO_DATE_RE = '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/';

    public function __construct(
        private RosterShiftMapper        $shiftMapper,
        private ShiftTypeMapper          $typeMapper,
        private RosterCalendarCopyMapper $copyMapper,
        private CalendarService          $calendarService,
        private TimezoneService          $timezones,
        private IConfig                  $config,
        private IURLGenerator            $urlGenerator,
        private IDBConnection            $db,
        private IL10N                    $l,
        private ContainerInterface       $container,
        private LoggerInterface          $logger,
    ) {}

    // ---------------------------------------------------------------------
    // The dialog
    // ---------------------------------------------------------------------

    /**
     * What the dialog needs: this person's published shifts in the week, which
     * of them are already in a calendar, the calendars they may pick, and
     * what they picked last time.
     *
     * @return array<string, mixed>
     */
    public function weekFor(string $teamId, string $uid, string $anyDayOfWeek): array {
        [$from, $to] = $this->weekOf($anyDayOfWeek);
        $shifts = $this->shiftMapper->findAssignedToUser($uid, $from, $to, $teamId, true);
        $types  = $this->typesById();
        $copies = $this->copyMapper->findForUserAndShifts(
            $uid,
            array_map(static fn (RosterShift $s): int => $s->getId(), $shifts),
        );

        $calendars = $this->eligibleCalendars($uid);

        return [
            'from'        => $from,
            'to'          => $to,
            'shifts'      => array_map(function (RosterShift $s) use ($types, $copies): array {
                $type = $types[$s->getShiftTypeId()] ?? null;
                return [
                    'id'          => $s->getId(),
                    'date'        => $s->getShiftDate(),
                    'label'       => $this->label($s, $type),
                    'startTime'   => $type?->getStartTime() ?? '',
                    'endTime'     => $type?->getEndTime() ?? '',
                    'endsNextDay' => $type?->endsNextDay() ?? false,
                    'inCalendar'  => isset($copies[$s->getId()]),
                ];
            }, $shifts),
            'calendars'   => array_map(static fn (array $c): array => [
                'uri'   => $c['uri'],
                'name'  => $c['name'],
                'color' => $c['color'],
            ], $calendars),
            'calendarUri' => $this->preferredCalendarUri($uid, $calendars),
            'transp'      => $this->preferredTransp($uid),
        ];
    }

    /**
     * Copy every published shift this person has in the week into one of
     * their calendars, marked busy or free. One action for the whole week
     * (Justin, 2026-11-10).
     *
     * Running it again rewrites the same events rather than adding new ones:
     * that is how a member changes free to busy, moves the week to another
     * calendar, or picks up a week that changed since.
     *
     * @return array{added: int, updated: int, failed: int, from: string, to: string}
     * @throws \InvalidArgumentException bad week, calendar or choice; nothing to add
     * @throws AppNotAvailableException  the calendar backend is not there (422)
     */
    public function exportWeek(string $teamId, string $uid, string $anyDayOfWeek, string $calendarUri, string $transp): array {
        if (!in_array($transp, RosterCalendarCopy::TRANSPS, true)) {
            throw new \InvalidArgumentException($this->l->t('Choose busy or free.'));
        }
        [$from, $to] = $this->weekOf($anyDayOfWeek);

        $calendar = null;
        foreach ($this->eligibleCalendars($uid) as $candidate) {
            if ($candidate['uri'] === $calendarUri) {
                $calendar = $candidate;
                break;
            }
        }
        // Only a calendar this person owns. A calendar id in a request is
        // guessable, and writing into somebody else's is not a thing this
        // endpoint can be talked into (CLAUDE.md: the frontend is not a
        // boundary).
        if ($calendar === null) {
            throw new \InvalidArgumentException($this->l->t('Choose one of your own calendars.'));
        }

        $shifts = $this->shiftMapper->findAssignedToUser($uid, $from, $to, $teamId, true);
        if ($shifts === []) {
            throw new \InvalidArgumentException($this->l->t('You have no published shifts in that week.'));
        }

        $backend = $this->backend();
        if ($backend === null) {
            throw new AppNotAvailableException($this->l->t('Your calendar could not be reached.'));
        }

        $types    = $this->typesById();
        $teamName = $this->teamName($teamId);
        $zone     = $this->timezones->forUser($uid);
        // `roster` opens the team on its Roster tab (App.vue).
        $link     = $this->urlGenerator->linkToRouteAbsolute('teamhub.page.index', ['team' => $teamId, 'roster' => $from]);
        $calId    = (int)$calendar['id'];
        $now      = time();

        $added = 0;
        $updated = 0;
        $failed = 0;

        foreach ($shifts as $shift) {
            $type = $types[$shift->getShiftTypeId()] ?? null;
            $ical = self::buildEvent($shift, $type, $uid, $transp, $this->label($shift, $type), $this->description($type, $teamName), $link, $zone, $now);
            if ($ical === null) {
                // A removed or malformed shift type has no hours to write.
                $failed++;
                continue;
            }
            $uri  = self::objectUri($shift->getId(), $uid);
            $copy = $this->copyMapper->findOne($shift->getId(), $uid);

            try {
                // Moved to another calendar: take the old copy out first, so
                // the week is in one place and not two.
                if ($copy !== null && $copy->getCalendarId() !== $calId) {
                    $this->removeObject($backend, $copy->getCalendarId(), $copy->getObjectUri());
                }

                $existing = $backend->getCalendarObject($calId, $uri);
                if ($existing !== null) {
                    if (!self::isOurs($existing)) {
                        // Something else sits at our address. Not ours to
                        // overwrite; say the shift failed rather than guess.
                        $failed++;
                        continue;
                    }
                    $backend->updateCalendarObject($calId, $uri, $ical);
                    $updated++;
                } else {
                    $backend->createCalendarObject($calId, $uri, $ical);
                    $copy === null ? $added++ : $updated++;
                }

                $row = $copy ?? new RosterCalendarCopy();
                $row->setShiftId($shift->getId());
                $row->setUserId($uid);
                $row->setTeamId($teamId);
                $row->setCalendarId($calId);
                $row->setObjectUri($uri);
                $row->setTransp($transp);
                $row->setUpdatedAt($now);
                if ($copy === null) {
                    $row->setCreatedAt($now);
                    $this->copyMapper->insert($row);
                } else {
                    $this->copyMapper->update($row);
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->logger->warning('[TeamHub][RosterCalendarService] a shift could not be written to the calendar', [
                    'shiftId' => $shift->getId(), 'exception' => get_class($e), 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        // Remembered so the next week's dialog opens on the same answers.
        try {
            $this->config->setUserValue($uid, Application::APP_ID, self::PREF_CALENDAR, $calendarUri);
            $this->config->setUserValue($uid, Application::APP_ID, self::PREF_TRANSP, $transp);
        } catch (\Throwable) {
            // A preference that did not stick costs one click next week.
        }

        return ['added' => $added, 'updated' => $updated, 'failed' => $failed, 'from' => $from, 'to' => $to];
    }

    // ---------------------------------------------------------------------
    // Taking copies back out
    // ---------------------------------------------------------------------

    /**
     * This person no longer works this shift: take it out of their calendar.
     *
     * Best effort and never throws. It runs after the roster change has been
     * committed, and a calendar that cannot be reached must not undo an
     * unassignment or a hand-over both people have already agreed to.
     */
    public function removeFor(int $shiftId, string $uid): void {
        try {
            $copy = $this->copyMapper->findOne($shiftId, $uid);
            if ($copy !== null) {
                $this->removeCopy($copy);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][RosterCalendarService] removeFor failed', [
                'shiftId' => $shiftId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    /** The shift is gone or back in concept: out of every calendar it is in. */
    public function removeAllFor(int $shiftId): void {
        try {
            foreach ($this->copyMapper->findByShift($shiftId) as $copy) {
                $this->removeCopy($copy);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][RosterCalendarService] removeAllFor failed', [
                'shiftId' => $shiftId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * The team is deleted. Its shifts from today on will not be worked, so
     * they come out of calendars; the past ones stay where they are, as the
     * member's record of shifts they did work, and only the rows go.
     */
    public function forgetTeam(string $teamId): void {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        foreach ($this->copyMapper->findByTeam($teamId) as $copy) {
            try {
                $shift = $this->shiftMapper->findById($copy->getShiftId());
                if ($shift === null || $shift->getShiftDate() >= $today) {
                    $this->removeCopy($copy);
                } else {
                    $this->copyMapper->delete($copy);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][RosterCalendarService] forgetTeam step failed', [
                    'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }
    }

    private function removeCopy(RosterCalendarCopy $copy): void {
        $backend = $this->backend();
        if ($backend !== null) {
            $this->removeObject($backend, $copy->getCalendarId(), $copy->getObjectUri());
        }
        $this->copyMapper->delete($copy);
    }

    /**
     * Delete one calendar object, if it is still ours.
     *
     * Gone already means the member deleted it, which is their right. Present
     * without the marker means it is not ours at all. Either way nothing is
     * deleted, and the caller still forgets the row. Deleting goes through
     * the backend's own path, so on an instance with the calendar trash bin
     * the event lands there rather than vanishing.
     */
    private function removeObject(object $backend, int $calendarId, string $objectUri): void {
        $existing = $backend->getCalendarObject($calendarId, $objectUri);
        if ($existing === null || !self::isOurs($existing)) {
            return;
        }
        $backend->deleteCalendarObject($calendarId, $objectUri);
    }

    // ---------------------------------------------------------------------
    // The event
    // ---------------------------------------------------------------------

    /**
     * The VCALENDAR for one shift, or null when the type has no hours.
     *
     * Pure, so it is tested without a calendar.
     */
    public static function buildEvent(
        RosterShift $shift,
        ?ShiftType $type,
        string $uid,
        string $transp,
        string $summary,
        string $description,
        string $link,
        \DateTimeZone $zone,
        int $now,
    ): ?string {
        if ($type === null || $type->startMinutes() === null || $type->lengthMinutes() <= 0) {
            return null;
        }
        // Both ends as wall-clock times where the person works, then to UTC.
        // Adding the length to the start instead would be an hour out on the
        // night the clocks change: a Night of 22:00 to 06:00 ends at 06:00 on
        // the wall, however many hours that turned out to be.
        try {
            $start   = new \DateTimeImmutable($shift->getShiftDate() . ' ' . $type->getStartTime() . ':00', $zone);
            $endDate = $type->endsNextDay()
                ? (new \DateTimeImmutable($shift->getShiftDate() . ' 12:00:00'))->modify('+1 day')->format('Y-m-d')
                : $shift->getShiftDate();
            $end     = new \DateTimeImmutable($endDate . ' ' . $type->getEndTime() . ':00', $zone);
        } catch (\Throwable) {
            return null;
        }
        $utc = new \DateTimeZone('UTC');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . self::PRODID,
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:' . self::eventUid($shift->getId(), $uid),
            'DTSTAMP:' . gmdate('Ymd\THis\Z', $now),
            'LAST-MODIFIED:' . gmdate('Ymd\THis\Z', $now),
            'DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z'),
            'DTEND:' . $end->setTimezone($utc)->format('Ymd\THis\Z'),
            'SUMMARY:' . IcalText::escape($summary),
            'DESCRIPTION:' . IcalText::escape(trim($description . "\n" . $link)),
            'URL:' . $link,
            'TRANSP:' . ($transp === RosterCalendarCopy::TRANSP_FREE ? 'TRANSPARENT' : 'OPAQUE'),
            self::MARKER,
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * `teamhub-shift-{shiftId}-{userId}@teamhub-roster`, with every character
     * of the user id outside `[a-zA-Z0-9._-]` replaced by `_`: the shape
     * §2.246 recorded for the presence events, so one cleanup rule reads both.
     */
    public static function eventUid(int $shiftId, string $uid): string {
        return 'teamhub-shift-' . $shiftId . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $uid) . '@teamhub-roster';
    }

    /** The UID lowercased, plus `.ics`. */
    public static function objectUri(int $shiftId, string $uid): string {
        return strtolower(self::eventUid($shiftId, $uid)) . '.ics';
    }

    /** @param array<string, mixed> $object a CalDavBackend::getCalendarObject() row */
    public static function isOurs(array $object): bool {
        $data = $object['calendardata'] ?? '';
        if (is_resource($data)) {
            $data = (string)stream_get_contents($data);
        }
        return is_string($data) && str_contains($data, self::MARKER);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** Role where one was given, else the shift type: the card's own rule (§2.236). */
    private function label(RosterShift $shift, ?ShiftType $type): string {
        $role = trim($shift->getNotes());
        if ($role !== '') {
            return $role;
        }
        return $type?->getLabel() ?? $this->l->t('Shift');
    }

    private function description(?ShiftType $type, string $teamName): string {
        if ($type === null) {
            return $teamName;
        }
        // TRANSLATORS: second line of a shift copied into a personal calendar. %1$s is the shift type (Early), %2$s and %3$s its hours, %4$s the team
        return $this->l->t('%1$s, %2$s to %3$s. %4$s', [
            $type->getLabel(), $type->getStartTime(), $type->getEndTime(), $teamName,
        ]);
    }

    /**
     * The calendars this person owns that can hold an event. A tasks-only
     * calendar is left out: an event written there is invisible in Calendar.
     *
     * @return list<array{id: int, uri: string, name: string, color: string, components?: string}>
     */
    private function eligibleCalendars(string $uid): array {
        return array_values(array_filter(
            $this->calendarService->listOwnedCalendars($uid),
            static function (array $c): bool {
                $components = strtoupper((string)($c['components'] ?? ''));
                return $components === '' || str_contains($components, 'VEVENT');
            },
        ));
    }

    /** @param list<array{uri: string}> $calendars */
    private function preferredCalendarUri(string $uid, array $calendars): string {
        $uris = array_column($calendars, 'uri');
        foreach ([
            (string)$this->config->getUserValue($uid, Application::APP_ID, self::PREF_CALENDAR, ''),
            // Nextcloud's own "default calendar" preference.
            (string)$this->config->getUserValue($uid, 'dav', 'defaultCalendar', ''),
            'personal',
        ] as $candidate) {
            if ($candidate !== '' && in_array($candidate, $uris, true)) {
                return $candidate;
            }
        }
        return (string)($uris[0] ?? '');
    }

    /** Busy unless they chose free before: a shift is working time. */
    private function preferredTransp(string $uid): string {
        $saved = (string)$this->config->getUserValue($uid, Application::APP_ID, self::PREF_TRANSP, '');
        return in_array($saved, RosterCalendarCopy::TRANSPS, true) ? $saved : RosterCalendarCopy::TRANSP_BUSY;
    }

    /**
     * Monday to Sunday of the week a date falls in, as floating dates.
     *
     * @return array{0: string, 1: string}
     */
    private function weekOf(string $isoDate): array {
        if (preg_match(self::ISO_DATE_RE, $isoDate) !== 1) {
            throw new \InvalidArgumentException($this->l->t('Dates must be in YYYY-MM-DD form.'));
        }
        $day    = new \DateTimeImmutable($isoDate . ' 12:00:00');
        $monday = $day->modify('-' . ((int)$day->format('N') - 1) . ' days');
        return [$monday->format('Y-m-d'), $monday->modify('+6 days')->format('Y-m-d')];
    }

    /** @return array<int, ShiftType> */
    private function typesById(): array {
        $out = [];
        foreach ($this->typeMapper->findAll() as $type) {
            $out[$type->getId()] = $type;
        }
        return $out;
    }

    private function teamName(string $teamId): string {
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('name', 'display_name')
                ->from('circles_circle')
                ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();
            if ($row === false) {
                return '';
            }
            $display = trim((string)($row['display_name'] ?? ''));
            return $display !== '' ? $display : (string)($row['name'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    private function backend(): ?object {
        try {
            return $this->container->get('OCA\\DAV\\CalDAV\\CalDavBackend');
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][RosterCalendarService] CalDAV backend unavailable', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
    }
}
