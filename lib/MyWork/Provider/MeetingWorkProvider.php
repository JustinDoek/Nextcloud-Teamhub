<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork\Provider;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\MyWork\ActionResult;
use OCA\TeamHub\MyWork\ActionType;
use OCA\TeamHub\MyWork\Category;
use OCA\TeamHub\MyWork\IWorkProvider;
use OCA\TeamHub\MyWork\OpenTarget;
use OCA\TeamHub\MyWork\Priority;
use OCA\TeamHub\MyWork\WorkItem;
use OCA\TeamHub\MyWork\WorkItemPage;
use OCA\TeamHub\MyWork\WorkQuery;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * My Work provider for team meetings (v4.5.25).
 *
 * A "meeting" here is a calendar event on a calendar shared with the team's
 * circle, on which the current user is an attendee or the organiser. That
 * single definition covers both things Justin asked for: a plain team calendar
 * entry, and a Talk meeting — because TeamHub's own meeting wizard writes the
 * Talk room URL into the event's LOCATION (`ActivityService::createCalendarEvent`,
 * which does it that way so Talk's own scheduled-meetings panel picks it up).
 * A Talk meeting is therefore not a separate source; it is a calendar event
 * that happens to carry a call link, and this provider flags it as one.
 *
 * ## What it shows
 *
 *  - an invitation you have **not responded to** (`PARTSTAT=NEEDS-ACTION`) →
 *    **Action required**, whenever it is. A reply is owed now, not on the day;
 *  - a meeting you **accepted** or marked tentative, or one you **organised** →
 *    **Upcoming**, with the start time as the deadline. Near-term ones are
 *    promoted to Action required by the shared lead-time rule in
 *    `MyWorkService::finaliseCategory`, exactly as a Deck card is — meetings do
 *    not get their own urgency rules;
 *  - a meeting you **declined** → nothing. You said no; it is not your work.
 *
 * Meetings that have already finished never appear, in any category. A past
 * meeting is not something you completed, and putting it under Completed would
 * inflate a number that is supposed to mean "you got this done".
 *
 * ## Read-only, deliberately
 *
 * The obvious next feature is Accept/Decline from the row, and it is
 * deliberately absent. Responding to an invitation means writing an iTIP REPLY
 * through the scheduling stack, and HANDOFF's Issue #41 is a live record of how
 * badly that path behaves when it is driven from outside NC Calendar's own UI.
 * Until that is settled, the row says a reply is owed and Open takes you to the
 * place where replying works. Saying "you have not responded" and then failing
 * to record the response would be worse than not offering it.
 *
 * ## The window
 *
 * One window, `upcomingDays`, for everything — including unanswered
 * invitations. A second, wider window just for invitations was considered and
 * dropped: two windows are two things to explain, and an administrator who
 * wants to see further ahead can already say so in one place.
 */
class MeetingWorkProvider implements IWorkProvider {

    public const ID = 'meetings';

    public const RESOURCE_TYPE = 'meeting';

    // Source statuses. Administrators remap these to categories, so they are
    // part of the stored contract — do not rename without migrating the map.
    public const STATUS_INVITED   = 'meeting_invited';
    public const STATUS_TENTATIVE = 'meeting_tentative';
    public const STATUS_ACCEPTED  = 'meeting_accepted';
    public const STATUS_ORGANISER = 'meeting_organiser';
    /**
     * v4.5.25 — on the team's calendar with no attendee list at all.
     *
     * TeamHub only writes ORGANIZER/ATTENDEE when the event has invitees or a
     * room (`ActivityService::createCalendarEvent`, `$useScheduling`), so an
     * ordinary team event has neither and matched nobody. But a calendar shared
     * with the team's circle is the team's diary: membership *is* the
     * invitation, and Justin's report that team events never appeared was this
     * rule being too literal about what an attendee is.
     */
    public const STATUS_TEAM_EVENT = 'meeting_team_event';

    /** Talk call links are `…/call/{token}`; that substring is the marker. */
    private const TALK_URL_MARKER = '/call/';

    private ?string $unavailableReason = null;

    /** @var array<string,string[]> lower-cased addresses that mean "me", per uid */
    private array $selfAddressCache = [];

    public function __construct(
        private IDBConnection $db,
        private IAppManager $appManager,
        private IUserManager $userManager,
        private IUserSession $userSession,
        private IURLGenerator $urlGenerator,
        private IL10N $l,
        private LoggerInterface $logger,
    ) {
    }

    // ---------------------------------------------------------------------
    // Identity + capabilities
    // ---------------------------------------------------------------------

    public function getId(): string {
        return self::ID;
    }

    public function getName(): string {
        return $this->l->t('Meetings');
    }

    public function getIcon(): string {
        return 'calendar';
    }

    public function getCapabilities(): array {
        return [
            'actions'       => [ActionType::OPEN, ActionType::JOIN, ActionType::AGENDA],
            'resourceTypes' => [self::RESOURCE_TYPE],
            'statuses'      => [
                self::STATUS_INVITED,
                self::STATUS_TENTATIVE,
                self::STATUS_ACCEPTED,
                self::STATUS_ORGANISER,
                self::STATUS_TEAM_EVENT,
            ],
            'categories' => [
                Category::ACTION_REQUIRED,
                Category::UPCOMING,
            ],
            'pagination'  => false,
            'incremental' => false,
        ];
    }

    public function isAvailable(): bool {
        $this->unavailableReason = null;

        if (!$this->appManager->isInstalled('calendar')) {
            $this->unavailableReason = $this->l->t('The Calendar app is not installed.');
            return false;
        }

        // Probe the three tables this provider reads. A missing column here is
        // the difference between "you have no meetings" and "TeamHub cannot
        // see your meetings", and only one of those is safe to show silently.
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('co.id', 'co.firstoccurence', 'co.lastoccurence', 'co.componenttype')
                ->from('calendarobjects', 'co')
                ->setMaxResults(1)
                ->executeQuery()
                ->closeCursor();
        } catch (\Throwable $e) {
            $this->unavailableReason = $this->l->t(
                'The calendar tables are not in the expected shape: {error}',
                ['error' => $e->getMessage()],
            );
            return false;
        }

        return true;
    }

    public function getUnavailableReason(): ?string {
        return $this->unavailableReason;
    }

    public function getSupportedFilters(): array {
        // Team narrowing happens in SQL; everything else is applied after the
        // VEVENTs are parsed, so claiming otherwise would be a lie.
        return ['teamIds'];
    }

    public function getConfigSchema(): array {
        return [];
    }

    /**
     * Admin-only structural diagnostics, picked up by ProviderRegistry via
     * `method_exists`. Table and column facts only — never an event title, a
     * calendar name, or anybody's address.
     *
     * @return array<int,array{label:string,value:string}>
     */
    public function getDiagnostics(): array {
        $rows = [];

        $rows[] = [
            'label' => $this->l->t('Calendar app installed'),
            'value' => $this->appManager->isInstalled('calendar') ? 'yes' : 'no',
        ];

        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select($qb->func()->count('*', 'c'))
                ->from('dav_shares')
                ->where($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
                ->andWhere($qb->expr()->like('principaluri',
                    $qb->createNamedParameter('principals/circles/%')))
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();
            $rows[] = [
                'label' => $this->l->t('Calendars shared with a team'),
                'value' => (string)(int)($row['c'] ?? 0),
            ];
        } catch (\Throwable $e) {
            $rows[] = [
                'label' => $this->l->t('Calendars shared with a team'),
                'value' => 'error: ' . $e->getMessage(),
            ];
        }

        $addresses = $this->selfAddresses($this->currentUserId());
        $rows[] = [
            'label' => $this->l->t('Addresses matched as you'),
            // The COUNT, not the addresses. An admin needs to know whether the
            // match can work at all; they do not need somebody's email.
            'value' => (string)count($addresses),
        ];

        return $rows;
    }

    // ---------------------------------------------------------------------
    // Fetch
    // ---------------------------------------------------------------------

    public function fetchItems(WorkQuery $query): WorkItemPage {
        if (!$this->isAvailable() || $query->teamIds === []) {
            return WorkItemPage::empty();
        }

        $windowStart = $query->now;
        $windowEnd   = $query->now + (max(1, $query->upcomingDays) * 86400);

        $items     = [];
        $truncated = false;
        $budget    = max(1, $query->perProviderCap);

        foreach ($this->teamCalendars($query->teamIds) as $calendar) {
            if (count($items) >= $budget) {
                $truncated = true;
                break;
            }
            try {
                foreach ($this->eventsInWindow($calendar, $windowStart, $windowEnd, $budget) as $event) {
                    if (count($items) >= $budget) {
                        $truncated = true;
                        break;
                    }
                    $item = $this->buildItem($query, $calendar, $event);
                    if ($item !== null) {
                        $items[] = $item;
                    }
                }
            } catch (\Throwable $e) {
                // One unreadable calendar must not cost the others. Logged
                // without the calendar's name — an id is enough to find it.
                $this->logger->warning('[TeamHub][MyWork][Meetings] Calendar read failed', [
                    'calendarId' => $calendar['id'], 'error' => $e->getMessage(),
                    'app' => Application::APP_ID,
                ]);
            }
        }

        return new WorkItemPage($items, count($items), $truncated);
    }

    public function getItem(string $userId, string $providerItemId, array $allowedTeamIds): ?WorkItem {
        if (!$this->isAvailable() || $allowedTeamIds === []) {
            return null;
        }

        // "{calendarObjectId}:{occurrenceStart}" — the occurrence is part of
        // the identity because a recurring meeting is many rows, and each one
        // has to be snoozeable on its own.
        [$objectId, $startTs] = array_pad(explode(':', $providerItemId, 2), 2, '');
        $objectId = (int)$objectId;
        $startTs  = (int)$startTs;
        if ($objectId <= 0) {
            return null;
        }

        $query = new WorkQuery(
            userId: $userId,
            teamIds: $allowedTeamIds,
            now: time(),
            // Widened so re-reading an item near the edge of the window still
            // finds it — this path authorises an action, and "not found"
            // because of a horizon would read as "someone deleted it".
            upcomingDays: 366,
        );

        foreach ($this->teamCalendars($allowedTeamIds) as $calendar) {
            try {
                $events = $this->eventsInWindow(
                    $calendar,
                    $startTs > 0 ? $startTs - 86400 : $query->now,
                    $startTs > 0 ? $startTs + 86400 : $query->now + (366 * 86400),
                    500,
                    $objectId,
                );
            } catch (\Throwable) {
                continue;
            }
            foreach ($events as $event) {
                if ((int)$event['objectId'] !== $objectId) {
                    continue;
                }
                if ($startTs > 0 && (int)$event['start'] !== $startTs) {
                    continue;
                }
                return $this->buildItem($query, $calendar, $event);
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------------

    /**
     * Open, plus whichever navigation links this meeting actually carries.
     *
     * Responding to the invitation is still absent, for the reason in the
     * class docblock. But a meeting row that offers nothing at all is the
     * complaint Justin raised, and it was a fair one — an invitation you have
     * not answered was landing in Action required with no button on it. Join
     * and Agenda are the two things a person actually does with a meeting
     * before it starts, and both are links this event already carries.
     */
    public function getAvailableActions(string $userId, WorkItem $item): array {
        $actions = [ActionType::OPEN];

        if (($item->metadata['talkUrl'] ?? null) !== null) {
            $actions[] = ActionType::JOIN;
        }
        if (($item->metadata['agendaUrl'] ?? null) !== null) {
            $actions[] = ActionType::AGENDA;
        }

        return $actions;
    }

    public function executeAction(string $userId, WorkItem $item, string $action, array $params): ActionResult {
        if ($action === ActionType::OPEN) {
            return ActionResult::success($this->l->t('Opening the meeting.'));
        }
        // Join and Agenda never reach here — the frontend opens their URLs
        // directly. If one does arrive it means a client posted a navigation
        // action, and answering "unsupported" is correct: there is nothing to
        // execute.
        return ActionResult::unsupported(
            $this->l->t('Meetings can only be opened from here. Respond to the invitation in Calendar.'),
        );
    }

    // ---------------------------------------------------------------------
    // Calendars
    // ---------------------------------------------------------------------

    /**
     * Calendars shared with any of these teams' circles.
     *
     * @param string[] $teamIds
     * @return array<int,array{id:int,teamId:string,name:string,owner:string,slug:string}>
     */
    private function teamCalendars(array $teamIds): array {
        $principals = [];
        foreach ($teamIds as $teamId) {
            $principals['principals/circles/' . $teamId] = $teamId;
        }
        if ($principals === []) {
            return [];
        }

        $shared = [];
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('resourceid', 'principaluri')
                ->from('dav_shares')
                ->where($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
                ->andWhere($qb->expr()->in('principaluri', $qb->createNamedParameter(
                    array_keys($principals), IQueryBuilder::PARAM_STR_ARRAY)))
                ->executeQuery();
            while ($row = $res->fetch()) {
                $shared[(int)$row['resourceid']] = $principals[(string)$row['principaluri']] ?? '';
            }
            $res->closeCursor();
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][Meetings] Share lookup failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }

        if ($shared === []) {
            return [];
        }

        $calendars = [];
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('id', 'principaluri', 'uri', 'displayname')
                ->from('calendars')
                ->where($qb->expr()->in('id', $qb->createNamedParameter(
                    array_keys($shared), IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeQuery();
            while ($row = $res->fetch()) {
                $id    = (int)$row['id'];
                $parts = explode('/', (string)$row['principaluri']);
                $calendars[] = [
                    'id'     => $id,
                    'teamId' => $shared[$id] ?? '',
                    'name'   => (string)($row['displayname'] ?: $row['uri']),
                    'owner'  => (string)end($parts),
                    'slug'   => (string)$row['uri'],
                ];
            }
            $res->closeCursor();
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][Meetings] Calendar lookup failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }

        return $calendars;
    }

    /**
     * Occurrences of this calendar's events that overlap [$from, $to].
     *
     * The date filter is pushed into SQL on `firstoccurence`/`lastoccurence`,
     * the columns NC maintains for exactly this purpose. TimelineService reads
     * every object on the calendar and filters in PHP; that is affordable for a
     * view the user opens deliberately, and not for one fetched on every visit
     * to My Work.
     *
     * @param array{id:int,teamId:string,name:string,owner:string,slug:string} $calendar
     * @return array<int,array<string,mixed>>
     */
    private function eventsInWindow(array $calendar, int $from, int $to, int $budget, int $onlyObjectId = 0): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('co.id', 'co.uri', 'co.calendardata')
            ->from('calendarobjects', 'co')
            ->where($qb->expr()->eq('co.calendarid',
                $qb->createNamedParameter($calendar['id'], IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('co.componenttype',
                $qb->createNamedParameter('VEVENT')))
            ->andWhere($qb->expr()->notLike('co.uri',
                $qb->createNamedParameter('%-deleted.ics')))
            ->andWhere($qb->expr()->gte('co.lastoccurence',
                $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lte('co.firstoccurence',
                $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(max(1, $budget));

        if ($onlyObjectId > 0) {
            $qb->andWhere($qb->expr()->eq('co.id',
                $qb->createNamedParameter($onlyObjectId, IQueryBuilder::PARAM_INT)));
        }

        $res  = $qb->executeQuery();
        $out  = [];
        while ($row = $res->fetch()) {
            foreach ($this->occurrences($row, $from, $to) as $occurrence) {
                $out[] = $occurrence;
            }
        }
        $res->closeCursor();

        return $out;
    }

    /**
     * Expand one calendar object into the occurrences that fall in the window.
     *
     * Recurring meetings are the common case for a team — a standup or a weekly
     * review is exactly the sort of thing this feature should surface — so the
     * VCALENDAR is expanded rather than having its DTSTART read. If expansion
     * throws (a malformed RRULE, an unsupported timezone) the single unexpanded
     * event is used instead: a meeting shown at its original time beats a
     * meeting that vanishes.
     *
     * @param array<string,mixed> $row
     * @return array<int,array<string,mixed>>
     */
    private function occurrences(array $row, int $from, int $to): array {
        try {
            $vcalendar = \Sabre\VObject\Reader::read((string)$row['calendardata']);
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][MyWork][Meetings] Unreadable calendar object', [
                'objectId' => $row['id'], 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
        if (!isset($vcalendar->VEVENT)) {
            return [];
        }

        $isRecurring = false;
        foreach ($vcalendar->VEVENT as $candidate) {
            if (isset($candidate->RRULE) || isset($candidate->RDATE)) {
                $isRecurring = true;
                break;
            }
        }

        if ($isRecurring) {
            try {
                $expanded = $vcalendar->expand(
                    (new \DateTimeImmutable())->setTimestamp($from),
                    (new \DateTimeImmutable())->setTimestamp($to),
                );
                $vcalendar = $expanded ?? $vcalendar;
            } catch (\Throwable $e) {
                $this->logger->debug('[TeamHub][MyWork][Meetings] Recurrence expansion failed', [
                    'objectId' => $row['id'], 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        $out = [];
        foreach ($vcalendar->VEVENT as $vevent) {
            $parsed = $this->parseEvent($vevent, $row, $from, $to);
            if ($parsed !== null) {
                $out[] = $parsed;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function parseEvent(mixed $vevent, array $row, int $from, int $to): ?array {
        if (!isset($vevent->DTSTART)) {
            return null;
        }
        // A cancelled event is not a meeting anybody owes anything on.
        if (isset($vevent->STATUS) && strtoupper((string)$vevent->STATUS) === 'CANCELLED') {
            return null;
        }

        $start = $vevent->DTSTART->getDateTime()->getTimestamp();

        $end = $start;
        if (isset($vevent->DTEND)) {
            $end = $vevent->DTEND->getDateTime()->getTimestamp();
        } elseif (isset($vevent->DURATION)) {
            $endDt = $vevent->DTSTART->getDateTime();
            $end   = $endDt->add($vevent->DURATION->getDateInterval())->getTimestamp();
        }

        // Overlap, not containment: a meeting that started an hour ago and runs
        // for another hour is still on.
        if ($end < $from || $start > $to) {
            return null;
        }

        return [
            'objectId'  => (int)$row['id'],
            'uri'       => (string)$row['uri'],
            'start'     => $start,
            'end'       => $end,
            'allDay'    => !$vevent->DTSTART->hasTime(),
            'title'     => trim((string)($vevent->SUMMARY ?? '')),
            'location'  => isset($vevent->LOCATION) ? (string)$vevent->LOCATION : '',
            'description' => isset($vevent->DESCRIPTION) ? (string)$vevent->DESCRIPTION : '',
            'organizer' => isset($vevent->ORGANIZER) ? (string)$vevent->ORGANIZER : '',
            'organizerName' => isset($vevent->ORGANIZER)
                ? (string)($vevent->ORGANIZER['CN'] ?? '')
                : '',
            'attendees' => $this->attendees($vevent),
        ];
    }

    /**
     * @return array<int,array{address:string,partstat:string,cn:string,cutype:string}>
     */
    private function attendees(mixed $vevent): array {
        $out = [];
        if (!isset($vevent->ATTENDEE)) {
            return $out;
        }
        foreach ($vevent->ATTENDEE as $attendee) {
            $out[] = [
                'address'  => (string)$attendee,
                'partstat' => strtoupper((string)($attendee['PARTSTAT'] ?? 'NEEDS-ACTION')),
                'cn'       => (string)($attendee['CN'] ?? ''),
                'cutype'   => strtoupper((string)($attendee['CUTYPE'] ?? 'INDIVIDUAL')),
            ];
        }
        return $out;
    }

    // ---------------------------------------------------------------------
    // Identity matching
    // ---------------------------------------------------------------------

    /**
     * Every address that means "this user" in a VEVENT.
     *
     * Three shapes, because NC writes all three depending on the path an event
     * arrived by: the user's own email, the synthetic `uid@host` address
     * `ActivityService` falls back to when a profile has no email, and the
     * `principal:principals/users/uid` form the DAV layer uses internally.
     *
     * @return string[] lower-cased, `mailto:` stripped
     */
    private function selfAddresses(string $userId): array {
        if (isset($this->selfAddressCache[$userId])) {
            return $this->selfAddressCache[$userId];
        }

        $addresses = [];
        $user      = $this->userManager->get($userId);

        if ($user !== null) {
            $email = (string)($user->getEMailAddress() ?? '');
            if ($email !== '') {
                $addresses[] = strtolower($email);
            }
        }

        // Same synthetic host ActivityService uses, so an event this app wrote
        // for a user with no email still matches back to them.
        try {
            $host = parse_url($this->urlGenerator->getAbsoluteURL('/'), PHP_URL_HOST) ?: 'localhost';
        } catch (\Throwable) {
            $host = 'localhost';
        }
        $addresses[] = strtolower($userId . '@' . $host);
        $addresses[] = strtolower('principal:principals/users/' . $userId);

        $this->selfAddressCache[$userId] = array_values(array_unique($addresses));
        return $this->selfAddressCache[$userId];
    }

    private static function normaliseAddress(string $raw): string {
        return strtolower(preg_replace('/^mailto:/i', '', trim($raw)) ?? '');
    }

    private function currentUserId(): string {
        return $this->userSession->getUser()?->getUID() ?? '';
    }

    // ---------------------------------------------------------------------
    // Item construction
    // ---------------------------------------------------------------------

    /**
     * @param array{id:int,teamId:string,name:string,owner:string,slug:string} $calendar
     * @param array<string,mixed> $event
     */
    private function buildItem(WorkQuery $query, array $calendar, array $event): ?WorkItem {
        $self = $this->selfAddresses($query->userId);

        $isOrganiser = $event['organizer'] !== ''
            && in_array(self::normaliseAddress((string)$event['organizer']), $self, true);

        $partstat = null;
        foreach ($event['attendees'] as $attendee) {
            if ($attendee['cutype'] === 'ROOM' || $attendee['cutype'] === 'RESOURCE') {
                continue;
            }
            if (in_array(self::normaliseAddress($attendee['address']), $self, true)) {
                $partstat = $attendee['partstat'];
                break;
            }
        }

        if ($partstat === 'DECLINED') {
            return null;
        }

        // Someone else's invitation on a shared calendar — an event with a
        // named attendee list that does not name you. That one is genuinely
        // not yours. An event with NO attendee list at all is different: it is
        // the team's own diary entry, and falls through to STATUS_TEAM_EVENT.
        $hasIndividualAttendees = false;
        foreach ($event['attendees'] as $attendee) {
            if ($attendee['cutype'] !== 'ROOM' && $attendee['cutype'] !== 'RESOURCE') {
                $hasIndividualAttendees = true;
                break;
            }
        }
        if ($partstat === null && !$isOrganiser && $hasIndividualAttendees) {
            return null;
        }

        [$category, $status, $priority, $reason] = $this->classify($partstat, $isOrganiser, $event);

        $talkUrl   = $this->talkUrl((string)$event['location']);
        $agendaUrl = $this->agendaUrl((string)($event['description'] ?? ''));
        $title     = $event['title'] !== '' ? (string)$event['title'] : $this->l->t('Untitled meeting');

        $editUrl = $this->eventUrl($calendar, $event);

        return WorkItem::make([
            'providerId'     => self::ID,
            'providerItemId' => $event['objectId'] . ':' . $event['start'],
            'teamId'         => $calendar['teamId'],
            'teamName'       => $query->teamName((string)$calendar['teamId']),
            'category'       => $category,
            'title'          => $title,
            // The second line answers "which meeting is this" the way the other
            // providers do — where it lives, not what it is about.
            'subtitle'       => $talkUrl !== null
                ? $this->l->t('%1$s · Talk meeting', [$calendar['name']])
                : (string)$calendar['name'],
            'resourceType'   => self::RESOURCE_TYPE,
            'resourceId'     => (string)$event['objectId'],
            'resourceUrl'    => $editUrl,
            'openTarget'     => OpenTarget::calendarEvent($editUrl, (int)$calendar['id']),
            'priority'       => $priority,
            'status'         => $status,
            'reason'         => $reason,
            'createdAt'      => null,
            'updatedAt'      => null,
            // The start time is the deadline. That is what makes a meeting sort
            // and group with everything else, and what lets the shared
            // lead-time rule promote it without meetings needing their own.
            'dueAt'          => (int)$event['start'],
            'completedAt'    => null,
            'assignee'       => null,
            'waitingFor'     => null,
            'availableActions' => [],
            'metadata'       => [
                'calendarId'    => (int)$calendar['id'],
                'calendarName'  => (string)$calendar['name'],
                'startsAt'      => (int)$event['start'],
                'endsAt'        => (int)$event['end'],
                'allDay'        => (bool)$event['allDay'],
                'isOrganiser'   => $isOrganiser,
                'partstat'      => $partstat,
                // A team diary entry is something that is happening, not
                // something owed — so the shared lead-time rule leaves it in
                // Upcoming instead of promoting it to Action required as the
                // hour approaches. Meetings you were actually invited to are
                // not marked, and are promoted like everything else.
                'informational' => $status === self::STATUS_TEAM_EVENT,
                'isTalkMeeting' => $talkUrl !== null,
                // Read by the frontend to open these; they are never posted
                // back, so nothing here is a capability the server grants.
                'talkUrl'       => $talkUrl,
                'agendaUrl'     => $agendaUrl,
                'organiserName' => (string)$event['organizerName'],
                'attendeeCount' => count($event['attendees']),
            ],
            'permissions'    => [
                'canOpen' => true,
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $event
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function classify(?string $partstat, bool $isOrganiser, array $event): array {
        if ($partstat === 'NEEDS-ACTION') {
            return [
                Category::ACTION_REQUIRED,
                self::STATUS_INVITED,
                Priority::HIGH,
                $this->l->t('You have not responded to this invitation'),
            ];
        }
        if ($isOrganiser) {
            return [
                Category::UPCOMING,
                self::STATUS_ORGANISER,
                Priority::NORMAL,
                $this->l->t('You organised this meeting'),
            ];
        }
        if ($partstat === 'TENTATIVE') {
            return [
                Category::UPCOMING,
                self::STATUS_TENTATIVE,
                Priority::NORMAL,
                $this->l->t('You marked this as tentative'),
            ];
        }
        if ($partstat === null) {
            return [
                Category::UPCOMING,
                self::STATUS_TEAM_EVENT,
                Priority::NORMAL,
                $this->l->t('On your team’s calendar'),
            ];
        }
        return [
            Category::UPCOMING,
            self::STATUS_ACCEPTED,
            Priority::NORMAL,
            $this->l->t('You are a participant'),
        ];
    }

    /**
     * The Talk call link, when the event carries one.
     *
     * LOCATION is where it lives — `ActivityService::createCalendarEvent` puts
     * it there on purpose so Talk's own scheduled-meetings panel finds it. Only
     * an absolute http(s) URL containing `/call/` counts; a LOCATION reading
     * "Meeting room 3" is a place, not a call.
     */
    /**
     * The meeting-notes link, when TeamHub's own wizard created this event.
     *
     * `MeetingService::createTeamMeeting` folds the notes file's share link
     * into the event DESCRIPTION as `Meeting notes: <url>`. That is the only
     * place it survives — the notes file is not referenced from the VEVENT in
     * any structured way — so it is read back out of the description.
     *
     * Deliberately narrow: the first http(s) URL on a line that mentions the
     * notes. A description full of prose should not produce an "Open agenda"
     * button that lands somewhere arbitrary.
     */
    private function agendaUrl(string $description): ?string {
        if ($description === '' || !preg_match('/(https?:\/\/\S+)/i', $description, $m)) {
            return null;
        }
        if (!str_contains(strtolower($description), 'meeting notes')) {
            return null;
        }
        $url = rtrim($m[1], ".,);\"'");
        $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?: ''));
        return ($scheme === 'http' || $scheme === 'https') ? $url : null;
    }

    private function talkUrl(string $location): ?string {
        $location = trim($location);
        if ($location === '' || !str_contains($location, self::TALK_URL_MARKER)) {
            return null;
        }
        $scheme = strtolower((string)(parse_url($location, PHP_URL_SCHEME) ?: ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        return $location;
    }

    /**
     * The event's URL in the Calendar app.
     *
     * Same construction as `TimelineService::fetchCalendarEvents` — the route
     * id is a base64 of the DAV path, *with* padding, and the trailing segment
     * is the occurrence start so a recurring meeting opens on the right day.
     *
     * @param array{id:int,teamId:string,name:string,owner:string,slug:string} $calendar
     * @param array<string,mixed> $event
     */
    private function eventUrl(array $calendar, array $event): string {
        if ($calendar['owner'] === '' || $calendar['slug'] === '') {
            return '';
        }
        $davPath  = '/remote.php/dav/calendars/' . $calendar['owner'] . '/' . $calendar['slug'] . '/' . $event['uri'];
        $objectId = rawurlencode(base64_encode($davPath));

        return '/apps/calendar/timeGridWeek/now/edit/sidebar/' . $objectId . '/' . (int)$event['start'];
    }
}
