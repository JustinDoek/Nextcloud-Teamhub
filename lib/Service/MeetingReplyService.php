<?php

declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCP\Calendar\IHandleImipMessage;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * Answer a meeting invitation from TeamHub (v4.20.10, licensed).
 *
 * ## Why it was missing, and what changed
 *
 * `MeetingWorkProvider` deliberately offered no Accept or Decline: replying
 * means an iTIP REPLY, and HANDOFF's Issue #41 records how badly hand-made
 * scheduling writes behaved. Justin's report (2026-11-16) is the cost of that
 * caution: an invitation sits in My Work as owed, and there is no clear way to
 * pay it.
 *
 * ## How the reply is recorded
 *
 * Through the one public door Nextcloud has for "an attendee answered":
 * `IHandleImipMessage::handleIMipMessage()`, the call the Mail app makes when a
 * reply arrives by email. A METHOD:REPLY naming the attendee and their PARTSTAT
 * is handed to the **organiser's** calendar, and Nextcloud's own iTIP broker
 * updates the organiser's copy of the event, which for a TeamHub meeting is
 * the team calendar copy everyone reads. TeamHub writes no calendar data
 * itself.
 *
 * Three guards, because that door trusts its message:
 *
 *   - the reply only ever names **the signed-in user**, matched against the
 *     event's attendees by every address that means them
 *     (`CalendarIdentityService`); nobody can answer for somebody else;
 *   - the event must be on a calendar shared with **this team**;
 *   - afterwards the event is read back, and if the user's answer is not on it
 *     the call fails. A reply that silently went nowhere would be worse than
 *     no button, which is the docblock's original point.
 *
 * A reply without RECURRENCE-ID answers the whole series, as Calendar's own
 * buttons do. Occurrences someone moved keep their own answer.
 *
 * DESIGN §2.264.
 */
class MeetingReplyService {

    /** Client status → PARTSTAT. */
    public const STATUSES = [
        'accepted'  => 'ACCEPTED',
        'tentative' => 'TENTATIVE',
        'declined'  => 'DECLINED',
    ];

    public function __construct(
        private IDBConnection $db,
        private ICalendarManager $calendarManager,
        private IUserManager $userManager,
        private CalendarIdentityService $identity,
        private TeamCalendarLocator $calendars,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{status: string}
     * @throws \InvalidArgumentException on an unknown status, or when the user organised the meeting
     * @throws NotFoundException when the event is not on this team's calendar
     * @throws AccessDeniedException when the user is not invited
     * @throws \RuntimeException when Nextcloud could not record the reply
     */
    public function reply(string $teamId, string $userId, string $eventUid, string $status): array {
        $partstat = self::STATUSES[$status] ?? null;
        if ($partstat === null) {
            throw new \InvalidArgumentException('Unknown reply');
        }
        $eventUid = trim($eventUid);
        if ($eventUid === '' || strlen($eventUid) > 255) {
            throw new \InvalidArgumentException('Unknown meeting');
        }

        $found = $this->findTeamEvent($teamId, $eventUid);
        if ($found === null) {
            throw new NotFoundException('The meeting is not on this team’s calendar');
        }
        $master = self::masterOf($found['vcalendar']);
        if ($master === null) {
            throw new NotFoundException('The meeting is not on this team’s calendar');
        }

        if (isset($master->ORGANIZER) && $this->identity->propertyIsUser($master->ORGANIZER, $userId)) {
            throw new \InvalidArgumentException('You organised this meeting');
        }

        $attendee = null;
        foreach ($master->select('ATTENDEE') as $candidate) {
            $cutype = strtoupper((string)($candidate['CUTYPE'] ?? 'INDIVIDUAL'));
            if ($cutype === 'ROOM' || $cutype === 'RESOURCE') {
                continue;
            }
            if ($this->identity->propertyIsUser($candidate, $userId)) {
                $attendee = $candidate;
                break;
            }
        }
        if ($attendee === null) {
            throw new AccessDeniedException('You are not invited to this meeting');
        }
        if (!self::canReplyAs($attendee)) {
            // Sending would add a second attendee instead of answering; see canReplyAs().
            throw new \OCA\TeamHub\Exception\ValidationException('This invitation can only be answered in Calendar');
        }
        if (!isset($master->ORGANIZER)) {
            throw new \RuntimeException('The meeting has no organiser to reply to');
        }

        $organiserUid = $this->organiserUid($master->ORGANIZER);
        if ($organiserUid === null) {
            throw new \RuntimeException('The organiser is not a user of this Nextcloud');
        }
        $calendar = $this->replyTarget($organiserUid, (int)$found['calendarId']);
        if ($calendar === null) {
            throw new \RuntimeException('The organiser’s calendar cannot take replies');
        }

        $calendar->handleIMipMessage((string)$found['uri'], self::buildReply($master, $attendee, $partstat));

        // Read it back: the answer must be on the event, or the reply went nowhere.
        $after = $this->findTeamEvent($teamId, $eventUid);
        $afterMaster = $after === null ? null : self::masterOf($after['vcalendar']);
        if ($afterMaster === null || self::partstatOf($afterMaster, fn ($p) => $this->identity->propertyIsUser($p, $userId)) !== $partstat) {
            $this->logger->warning('[TeamHub][MeetingReplyService] reply was not recorded on the team calendar copy', [
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
            throw new \RuntimeException('Nextcloud did not record the reply');
        }

        return ['status' => $status];
    }

    /**
     * Whether a reply from TeamHub would land on this attendee (v4.20.10).
     *
     * `IHandleImipMessage` lower-cases the replying address before Nextcloud's
     * broker sees it, and the broker matches attendees case-sensitively. An
     * attendee written with capitals (a profile without email gets the
     * synthetic `Display Name@host`, as TeamHub wrote it before 4.20.10) is
     * then not found, and the broker adds a second attendee carrying the
     * answer. Found on claudius on 2026-11-16 with Cath Stevens. TeamHub now
     * writes attendee addresses lower-cased; older invitations with a
     * capitalised address are answered in Calendar instead.
     */
    public static function canReplyAs(\Sabre\VObject\Property $attendee): bool {
        $value = (string)$attendee;
        return $value === strtolower($value);
    }

    /**
     * The METHOD:REPLY for one attendee: the event's identity and the
     * attendee's own property with the new PARTSTAT.
     */
    public static function buildReply(\Sabre\VObject\Component $master, \Sabre\VObject\Property $attendee, string $partstat): string {
        $reply = new VCalendar();
        $reply->METHOD = 'REPLY';
        $reply->PRODID = '-//TeamHub//TeamHub//EN';
        $event = $reply->add('VEVENT', [
            'UID'      => (string)$master->UID,
            'DTSTAMP'  => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'SEQUENCE' => isset($master->SEQUENCE) ? (int)(string)$master->SEQUENCE : 0,
        ]);
        if (isset($master->DTSTART)) {
            $event->add(clone $master->DTSTART);
        }
        $event->add(clone $master->ORGANIZER);
        $answer = clone $attendee;
        $answer['PARTSTAT'] = $partstat;
        unset($answer['RSVP']);
        $event->add($answer);
        return $reply->serialize();
    }

    /**
     * The event with this UID on one of the team's calendars.
     *
     * @return array{calendarId: int, uri: string, vcalendar: VCalendar}|null
     */
    private function findTeamEvent(string $teamId, string $eventUid): ?array {
        $ids = array_map(static fn (array $c): int => (int)$c['id'], $this->calendars->forTeams([$teamId]));
        if ($ids === []) {
            return null;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('calendarid', 'uri', 'calendardata')
            ->from('calendarobjects')
            ->where($qb->expr()->in('calendarid', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($eventUid)))
            ->andWhere($qb->expr()->eq('componenttype', $qb->createNamedParameter('VEVENT')))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        if (!$row) {
            return null;
        }
        $data = $row['calendardata'];
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }
        try {
            $vcalendar = Reader::read((string)$data);
        } catch (\Throwable) {
            return null;
        }
        if (!$vcalendar instanceof VCalendar) {
            return null;
        }
        return ['calendarId' => (int)$row['calendarid'], 'uri' => (string)$row['uri'], 'vcalendar' => $vcalendar];
    }

    /** The series master, or the only VEVENT: the one without RECURRENCE-ID. */
    private static function masterOf(VCalendar $vcalendar): ?\Sabre\VObject\Component {
        foreach ($vcalendar->select('VEVENT') as $vevent) {
            if (!isset($vevent->{'RECURRENCE-ID'})) {
                return $vevent;
            }
        }
        return null;
    }

    /** @param callable(\Sabre\VObject\Property): bool $isUser */
    private static function partstatOf(\Sabre\VObject\Component $vevent, callable $isUser): ?string {
        foreach ($vevent->select('ATTENDEE') as $attendee) {
            if ($isUser($attendee)) {
                return strtoupper((string)($attendee['PARTSTAT'] ?? 'NEEDS-ACTION'));
            }
        }
        return null;
    }

    /** The organiser's uid: stamped by TeamHub, by their email, or the synthetic `uid@host`. */
    private function organiserUid(\Sabre\VObject\Property $organizer): ?string {
        $stamped = $this->identity->uidFromProperty($organizer);
        if ($stamped !== null && $this->userManager->userExists($stamped)) {
            return $stamped;
        }
        $address = CalendarIdentityService::normalise((string)$organizer);
        if ($address === '') {
            return null;
        }
        $users = $this->userManager->getByEmail($address);
        if (count($users) === 1) {
            return $users[0]->getUID();
        }
        // The synthetic address TeamHub writes for a profile without email.
        $at = strrpos($address, '@');
        if ($at !== false) {
            $candidate = substr($address, 0, $at);
            if ($candidate !== '' && $this->userManager->userExists($candidate)
                && in_array($address, $this->identity->addressesFor($candidate), true)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * One of the organiser's calendars that takes iTIP messages, the team
     * calendar itself when the organiser owns it.
     */
    private function replyTarget(string $organiserUid, int $teamCalendarId): ?IHandleImipMessage {
        $fallback = null;
        foreach ($this->calendarManager->getCalendarsForPrincipal('principals/users/' . $organiserUid) as $calendar) {
            if (!$calendar instanceof IHandleImipMessage) {
                continue;
            }
            if ($calendar->getKey() === (string)$teamCalendarId) {
                return $calendar;
            }
            $fallback ??= $calendar;
        }
        return $fallback;
    }
}
