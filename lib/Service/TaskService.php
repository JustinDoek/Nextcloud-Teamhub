<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\AppNotAvailableException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Util\CalendarBlob;
use OCP\App\IAppManager;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * TaskService — reads, creates and completes VTODO tasks in a team calendar.
 *
 * Tasks are stored as iCal VTODO objects in the `calendarobjects` table,
 * exactly like VEVENTs. Sabre/VObject (bundled with every NC install) is used
 * for parsing and generation. No CalDAV HTTP calls are made.
 *
 * ## Who a task is for (v4.11.15)
 *
 * Until now a task named nobody — no ORGANIZER, no ATTENDEE — so nothing could
 * tell a to-do somebody wrote for themselves from one the whole team owes.
 * Justin, 2026-10-01: *we have no way of knowing if the user intended it for
 * himself or a team member if I see the interface.* Right — and the answer is
 * to ask at the point where the person knows, not to guess at the point where
 * they do not. So creation takes an assignee, and writes the standard
 * iCalendar properties for it:
 *
 *   - **for one person** (themselves or a colleague) → `ORGANIZER` = creator,
 *     one `ATTENDEE` = that person. It is theirs, and nobody else's queue.
 *   - **for the whole team** → `ORGANIZER` = creator, no `ATTENDEE`. The
 *     team's own task list, which every member sees.
 *
 * Every task written before this version names nobody, which is exactly the
 * second case — so old tasks keep working and read as the team's. Nothing had
 * to be migrated.
 *
 * `TaskWorkProvider` is the reader of all this; `CalendarIdentityService` is
 * the one place that decides whether an address means a given user.
 */
class TaskService {

    /** `assignee` meaning "the whole team" — no ATTENDEE is written. */
    public const ASSIGN_TEAM = 'team';

    /** `assignee` meaning the caller themselves. */
    public const ASSIGN_SELF = 'self';

    public function __construct(
        private IAppManager $appManager,
        private IDBConnection $db,
        private IUserSession $userSession,
        private ResourceService $resourceService,
        private MemberService $memberService,
        private CalendarIdentityService $identity,
        private TaskNotificationService $taskNotifications,
        private IL10N $l,
        // OCP\Calendar\IManager is a core server service, present whether or
        // not the DAV app is enabled — only the *providers* it asks come from
        // DAV, and with DAV off it simply answers with no calendars. So it is
        // injected directly; nothing here needs a lazy container lookup.
        private ICalendarManager $calendarManager,
        // Completing a task updates an object in place, and OCP has no API for
        // that — ICreateFromString only creates. CalDavBackend::updateCalendarObject()
        // is the OCA abstraction for it, resolved lazily so the DAV app is not
        // a compile-time dependency of this class.
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Returns true when the Nextcloud Tasks app is enabled.
     */
    public function isTasksAppAvailable(): bool {
        return $this->appManager->isInstalled('tasks');
    }

    /**
     * Fetch upcoming VTODO tasks for the team calendar.
     *
     * @param string $teamId Circle/team ID
     * @return array{id:string,title:string,duedate:string|null,priority:int,status:string,url:string}[]
     * @throws \Exception when Tasks app is missing or calendar not found
     */
    public function getTeamTasks(string $teamId): array {
        $this->assertTasksApp();

        // Aggregate tasks from ALL connected calendars.
        $calendarIds = $this->resolveAllCalendarIds($teamId);
        if (empty($calendarIds)) {
            return [];
        }

        $now    = new \DateTimeImmutable();
        $cutoff = $now->modify('+14 days');
        $tasks  = [];

        foreach ($calendarIds as $calendarId) {
            $rows = $this->fetchVtodoRows($calendarId);
            foreach ($rows as $row) {
                try {
                    $task = $this->parseVtodo($row, $now, $cutoff);
                    if ($task !== null) {
                        $tasks[] = $task;
                    }
                } catch (\Throwable $e) {
                }
            }
        }

        usort($tasks, static function (array $a, array $b): int {
            if ($a['duedate'] === null && $b['duedate'] === null) return 0;
            if ($a['duedate'] === null) return 1;
            if ($b['duedate'] === null) return -1;
            return strcmp($a['duedate'], $b['duedate']);
        });

        return array_slice($tasks, 0, 10);
    }

    /**
     * Create a VTODO in the team calendar.
     *
     * @param string      $teamId      Circle/team ID
     * @param string      $title       Task title (required)
     * @param string|null $duedate     ISO 8601 datetime string or null
     * @param string|null $description Optional description
     * @param int|null    $calendarId  Target calendar ID; uses first connected calendar if null
     * @param string|null $assignee    a team member's uid, `self`, or `team` /
     *                                 null for the whole team
     * @return array{uri:string,title:string,assignee:string|null}
     * @throws \Exception when Tasks app missing, calendar not found, or insert fails
     */
    public function createTeamTask(
        string $teamId,
        string $title,
        ?string $duedate,
        ?string $description,
        ?int $calendarId = null,
        ?string $assignee = null,
    ): array {
        $this->assertTasksApp();

        if ($calendarId === null) {
            $calendarId = $this->resolveCalendarId($teamId);
        }
        if ($calendarId === null) {
            throw new NotFoundException('This team has no calendar, so there is nowhere to store the task.');
        }

        // Resolved before the ICS is built so a missing or read-only calendar
        // answers with its own sentence rather than a generic failure.
        $target      = $this->resolveWritableCalendar($calendarId);
        $creator     = $this->requireUserId();
        $assigneeUid = $this->resolveAssignee($teamId, $creator, $assignee);

        $uid     = $this->generateUid();
        $icsData = $this->buildVtodoIcs($uid, $title, $duedate, $description, $creator, $assigneeUid);
        $uri     = $uid . '.ics';

        try {
            $target->createFromString($uri, $icsData);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][TaskService] createTeamTask — ICalendar::createFromString failed', [
                'teamId' => $teamId, 'calendarId' => $calendarId,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            throw new \RuntimeException(
                'Failed to create team task: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        // A colleague now has a row in their own My Work; tell them, and make
        // their queue re-read. Nothing is sent for your own task or the team's.
        if ($assigneeUid !== null && $assigneeUid !== $creator) {
            $this->taskNotifications->taskAssigned(
                $assigneeUid,
                $creator,
                $teamId,
                $this->teamNameFor($teamId),
                $title,
                $duedate !== null ? $this->timestampOf($duedate) : null,
            );
        }

        return ['uri' => $uri, 'title' => $title, 'assignee' => $assigneeUid];
    }

    /**
     * The team's display name for a notification, or '' when it cannot be read.
     *
     * Same six lines as `FileReviewService::getTeamName()` and
     * `MessageSubscriptionService::getTeamName()`, for the same reason: reaching
     * for `TeamService` here would pull the app's heaviest service into every
     * construction of this one, to answer a question that is one indexed read.
     */
    private function teamNameFor(string $teamId): string {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('display_name')
                ->from('circles_circle')
                ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
                ->setMaxResults(1);
            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            return $row ? (string)$row['display_name'] : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function timestampOf(string $iso): ?int {
        try {
            $ts = (new \DateTimeImmutable($iso))->getTimestamp();
            return $ts > 0 ? $ts : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Mark a task complete: `STATUS:COMPLETED`, `PERCENT-COMPLETE:100` and a
     * `COMPLETED` stamp, written back over the same object.
     *
     * No iTIP, no scheduling — a VTODO's completion is a plain object update,
     * which is why this is safe where responding to a meeting invitation from
     * outside NC Calendar is not (`MeetingWorkProvider`'s docblock).
     *
     * @return bool false when the task was already complete — the caller turns
     *              that into a `conflict`, per the My Work provider contract,
     *              rather than reporting a success that changed nothing
     * @throws NotFoundException when the task or the calendar is gone
     */
    public function completeTeamTask(int $calendarId, string $uri): bool {
        $this->assertTasksApp();

        // Proves the caller can write this calendar before anything is read.
        $this->resolveWritableCalendar($calendarId);

        $row = $this->fetchVtodoRow($calendarId, $uri);
        if ($row === null) {
            throw new NotFoundException('That task no longer exists.');
        }

        $vcalendar = \Sabre\VObject\Reader::read(
            CalendarBlob::read($row['calendardata']),
            \Sabre\VObject\Reader::OPTION_FORGIVING,
        );
        $vtodo = $vcalendar->VTODO;
        if ($vtodo === null) {
            throw new NotFoundException('That task no longer exists.');
        }

        if (self::vtodoIsComplete($vtodo)) {
            return false;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $vtodo->remove('STATUS');
        $vtodo->remove('PERCENT-COMPLETE');
        $vtodo->remove('COMPLETED');
        $vtodo->remove('LAST-MODIFIED');
        $vtodo->remove('DTSTAMP');
        $vtodo->add('STATUS', 'COMPLETED');
        $vtodo->add('PERCENT-COMPLETE', '100');
        $vtodo->add('COMPLETED', $now);
        $vtodo->add('LAST-MODIFIED', $now);
        $vtodo->add('DTSTAMP', $now);

        try {
            $backend = $this->container->get(\OCA\DAV\CalDAV\CalDavBackend::class);
            $backend->updateCalendarObject($calendarId, $uri, $vcalendar->serialize());
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][TaskService] completeTeamTask — update failed', [
                'calendarId' => $calendarId, 'error' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
            throw new \RuntimeException('Failed to complete the task: ' . $e->getMessage(), 0, $e);
        }

        return true;
    }

    /**
     * Is this VTODO finished? The three ways a client can say so, any of which
     * counts — `STATUS`, `PERCENT-COMPLETE` and a `COMPLETED` stamp.
     *
     * @param mixed $vtodo a Sabre VObject VTODO component
     */
    public static function vtodoIsComplete(mixed $vtodo): bool {
        if (strtoupper((string)($vtodo->STATUS ?? '')) === 'COMPLETED') {
            return true;
        }
        if ((int)(string)($vtodo->{'PERCENT-COMPLETE'} ?? '0') >= 100) {
            return true;
        }
        return isset($vtodo->COMPLETED);
    }

    /**
     * The uid a task is for: a named team member, the caller, or null for the
     * whole team.
     *
     * A colleague must be a member of the team — otherwise a task could be
     * parked in somebody's queue by anyone who knew their uid, and they could
     * not even open the team it belongs to.
     *
     * @throws ValidationException when the named person is not in the team
     */
    private function resolveAssignee(string $teamId, string $creator, ?string $assignee): ?string {
        $assignee = trim((string)$assignee);

        if ($assignee === '' || $assignee === self::ASSIGN_TEAM) {
            return null;
        }
        if ($assignee === self::ASSIGN_SELF || $assignee === $creator) {
            return $creator;
        }
        if (!$this->memberService->isEffectiveMember($teamId, $assignee, $this->db)) {
            throw new ValidationException('That person is not a member of this team.');
        }
        return $assignee;
    }

    private function requireUserId(): string {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null || $uid === '') {
            throw new AccessDeniedException('A task can only be created for a signed-in user.');
        }
        return $uid;
    }

    /**
     * Find the team calendar in the *current user's* calendar home and return
     * it as something that can be written to.
     *
     * The principal matters. A TeamHub team calendar is owned by the member who
     * created the team (`principals/users/{uid}`, `CalendarService::createCalendar`)
     * and merely *shared* with the circle through a `dav_shares` row — no
     * calendar is ever owned by `principals/circles/{teamId}`. Asking the
     * circle principal does return the calendar, but `CalendarImpl::createFromString()`
     * then builds its CalDAV path from that principal
     * (`calendars/{teamId}/{uri}_shared_by_{owner}/…`), which resolves to no node
     * in the Sabre tree — there is no user principal named after a circle — and
     * the write fails with a bare `NotFound`.
     *
     * Asked as the current user instead, `CalDavBackend::getCalendarsForUser()`
     * hands back the same calendar through the user's circle membership, with a
     * principal whose path does resolve. The caller has already proven team
     * membership; this is the account the task is written on behalf of, so the
     * resolution doubles as the authorisation check — a member who cannot reach
     * the calendar cannot write to it.
     *
     * @throws NotFoundException     when the calendar is not in the user's calendar home
     * @throws AccessDeniedException when there is no user, or the calendar is read-only
     */
    private function resolveWritableCalendar(int $calendarId): ICreateFromString {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new AccessDeniedException('A task can only be created for a signed-in user.');
        }

        $principalUri = 'principals/users/' . $user->getUID();
        $found        = false;

        foreach ($this->calendarManager->getCalendarsForPrincipal($principalUri) as $cal) {
            if ((int)$cal->getKey() !== $calendarId) {
                continue;
            }
            $found = true;

            // ICalendarIsWritable is optional on a provider; absence means the
            // provider does not say, so only an explicit "no" blocks the write.
            if ($cal instanceof ICalendarIsWritable && !$cal->isWritable()) {
                continue;
            }
            if ($cal instanceof ICreateFromString) {
                return $cal;
            }
        }

        $this->logger->warning('[TeamHub][TaskService] resolveWritableCalendar — no writable calendar', [
            'calendarId' => $calendarId, 'found' => $found, 'app' => Application::APP_ID,
        ]);

        if ($found) {
            throw new AccessDeniedException('The team calendar is read-only for your account.');
        }
        throw new NotFoundException('The team calendar is not available to your account.');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function assertTasksApp(): void {
        if (!$this->isTasksAppAvailable()) {
            throw new AppNotAvailableException('Tasks app is not installed.');
        }
    }

    /**
     * Resolve the first connected calendar ID for a team.
     * Used as the default target for createTeamTask.
     */
    private function resolveCalendarId(string $teamId): ?int {
        $ids = $this->resolveAllCalendarIds($teamId);
        return $ids[0] ?? null;
    }

    /**
     * Resolve ALL connected calendar IDs for a team.
     * calendar is now an array in getTeamResources().
     *
     * @return int[]
     */
    private function resolveAllCalendarIds(string $teamId): array {
        try {
            $resources = $this->resourceService->getTeamResources($teamId);
            $calendars = $resources['calendar'] ?? [];
            $ids = [];
            foreach ($calendars as $cal) {
                if (isset($cal['id'])) {
                    $ids[] = (int)$cal['id'];
                }
            }
            return $ids;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TaskService] resolveAllCalendarIds failed', [
                'teamId' => $teamId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Fetch raw VTODO rows from calendarobjects for the given calendar.
     *
     * apps.md R-2 note: kept as direct SELECT. ICalendar::search() would
     * be the API path, but it operates on an ICalendar instance which
     * OCP\Calendar\IManager only hands out per-principal. Reverse-looking-up
     * the principal from a calendar ID would either require another raw
     * SELECT anyway or walking every principal on the instance. Read-only
     * with no side-effects — the API path adds cost without gain here.
     *
     * @return array<int,array{uri:string,calendardata:string}>
     */
    private function fetchVtodoRows(int $calendarId): array {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('uri', 'calendardata')
            ->from('calendarobjects')
            ->where($qb->expr()->eq('calendarid', $qb->createNamedParameter($calendarId)))
            ->andWhere($qb->expr()->eq('componenttype', $qb->createNamedParameter('VTODO')))
            ->executeQuery();

        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * One VTODO row by its object uri, or null.
     *
     * Same R-2 reasoning as `fetchVtodoRows()`: the read is by calendar id and
     * uri, which is the table's own unique key, and the public API hands out
     * calendars per principal with no way to ask for one object.
     *
     * @return array{uri:string,calendardata:string}|null
     */
    private function fetchVtodoRow(int $calendarId, string $uri): ?array {
        $qb     = $this->db->getQueryBuilder();
        $result = $qb->select('uri', 'calendardata')
            ->from('calendarobjects')
            ->where($qb->expr()->eq('calendarid', $qb->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('uri', $qb->createNamedParameter($uri)))
            ->andWhere($qb->expr()->eq('componenttype', $qb->createNamedParameter('VTODO')))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row ?: null;
    }

    /**
     * Parse a single VTODO calendar object row into a normalised task array.
     *
     * Returns null when the task should be excluded (completed, cancelled,
     * no due date within the upcoming window).
     *
     * @param array{uri:string,calendardata:string} $row
     * @return array{id:string,title:string,duedate:string|null,priority:int,status:string,url:string,assignee:string|null,assigneeName:string,mine:bool}|null
     */
    private function parseVtodo(array $row, \DateTimeImmutable $now, \DateTimeImmutable $cutoff): ?array {
        $calendarData = $row['calendardata'] ?? '';
        if ($calendarData === '') {
            return null;
        }

        // Sabre/VObject is always available in NC since it ships with NC core.
        $vcalendar = \Sabre\VObject\Reader::read($calendarData, \Sabre\VObject\Reader::OPTION_FORGIVING);
        $vtodo     = $vcalendar->VTODO;
        if ($vtodo === null) {
            return null;
        }

        // Skip completed or cancelled tasks.
        $status = strtoupper((string)($vtodo->STATUS ?? ''));
        if ($status === 'COMPLETED' || $status === 'CANCELLED') {
            return null;
        }

        // Skip tasks with PERCENT-COMPLETE = 100.
        $percent = (int)(string)($vtodo->{'PERCENT-COMPLETE'} ?? '0');
        if ($percent >= 100) {
            return null;
        }

        // Resolve due date. Tasks may use DUE or DTSTART; prefer DUE.
        $dueDateString = null;
        $dueDateTime   = null;
        foreach (['DUE', 'DTSTART'] as $prop) {
            if (isset($vtodo->$prop)) {
                try {
                    $dt           = $vtodo->$prop->getDateTime();
                    $dueDateTime  = \DateTimeImmutable::createFromInterface($dt);
                    $dueDateString = $dueDateTime->format(\DateTimeInterface::ATOM);
                    break;
                } catch (\Throwable $e) {
                    // malformed date — skip this property
                }
            }
        }

        // Include tasks that are already overdue (dueDateTime < $now) OR
        // due within the next 14 days. Tasks without a due date are always included.
        if ($dueDateTime !== null && $dueDateTime > $cutoff) {
            return null;
        }

        // v4.11.16 — was `t('teamhub', 'Untitled task')`, a call to a global
        // `t()` that PHP does not have in Nextcloud (only the JS side does).
        // It fataled the whole endpoint, and therefore the Upcoming tasks
        // widget, for any VTODO with neither SUMMARY nor DESCRIPTION.
        $title = trim((string)($vtodo->SUMMARY ?? ''));
        if ($title === '') {
            $title = trim((string)($vtodo->DESCRIPTION ?? ''));
        }
        if ($title === '') {
            $title = $this->l->t('Untitled task');
        }
        $priority = (int)(string)($vtodo->PRIORITY ?? '0');
        $uid      = (string)($vtodo->UID ?? $row['uri']);

        // v4.11.16 — who the task is for, so the widget's chip can say it
        // instead of calling every VTODO a "personal task". Same reading as
        // TaskWorkProvider: the uid stamp first, the address as the fallback.
        $viewer       = $this->userSession->getUser()?->getUID() ?? '';
        $attendee     = null;
        if (isset($vtodo->ATTENDEE)) {
            foreach ($vtodo->ATTENDEE as $candidate) {
                $cutype = strtoupper((string)($candidate['CUTYPE'] ?? 'INDIVIDUAL'));
                if ($cutype !== 'ROOM' && $cutype !== 'RESOURCE') {
                    $attendee = $candidate;
                    break;
                }
            }
        }
        $assigneeUid  = $this->identity->uidFromProperty($attendee);
        $assigneeName = $attendee !== null ? trim((string)($attendee['CN'] ?? '')) : '';
        if ($assigneeUid !== null && $assigneeName === '') {
            $assigneeName = $this->identity->displayNameFor($assigneeUid);
        }

        return [
            'id'           => $uid,
            'title'        => $title,
            'duedate'      => $dueDateString,
            'priority'     => $priority,
            'status'       => $status ?: 'NEEDS-ACTION',
            'url'          => '/apps/tasks',
            'assignee'     => $assigneeUid,
            'assigneeName' => $assigneeName,
            'mine'         => $attendee !== null
                && $viewer !== ''
                && $this->identity->propertyIsUser($attendee, $viewer),
        ];
    }

    /**
     * Build the iCalendar VCALENDAR string containing a single VTODO component.
     *
     * @param string      $creator     uid of whoever is creating the task
     * @param string|null $assigneeUid uid it is for, or null for the whole team
     */
    private function buildVtodoIcs(
        string $uid,
        string $title,
        ?string $duedate,
        ?string $description,
        string $creator = '',
        ?string $assigneeUid = null,
    ): string {
        $vcalendar = new \Sabre\VObject\Component\VCalendar([
            'PRODID'  => '-//TeamHub//TeamHub Tasks//EN',
            'VERSION' => '2.0',
        ]);

        // `defaults: false`. With Sabre's defaults on, createComponent() adds a
        // UID and a DTSTAMP of its own, and the ones added below land *beside*
        // them — so every task TeamHub has ever written carries two UID and two
        // DTSTAMP properties, which RFC 5545 §3.6.2 allows exactly one of.
        // Nothing noticed because Sabre reads forgivingly and NC stores the
        // blob verbatim, but a stricter CalDAV client is entitled to reject it
        // and "which UID is this task?" had two answers. Found by the
        // assignment tests in v4.11.15.
        $vtodo = $vcalendar->createComponent('VTODO', [], false);
        $vtodo->add('UID',       $uid);
        $vtodo->add('SUMMARY',   $title);
        $vtodo->add('STATUS',    'NEEDS-ACTION');
        $vtodo->add('CREATED',   new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $vtodo->add('DTSTAMP',   new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $vtodo->add('LAST-MODIFIED', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        if ($description !== null && trim($description) !== '') {
            $vtodo->add('DESCRIPTION', trim($description));
        }

        // Who it is from and who it is for. The ORGANIZER is written even for a
        // team task: it costs nothing, and it is what lets the creator's own
        // row read "You created this" while everybody else's reads "On your
        // team's task list".
        if ($creator !== '') {
            $vtodo->add(
                'ORGANIZER',
                $this->identity->mailtoFor($creator),
                $this->identity->propertyParamsFor($creator),
            );
        }

        if ($assigneeUid !== null) {
            // PARTSTAT is written because clients expect it, and is deliberately
            // not read back: a task is assigned, not offered, so it is yours
            // whether or not anything ever "accepted" it. Only DECLINED is
            // honoured on the way in, so a client that grows a decline button
            // does the right thing without this code changing.
            $vtodo->add(
                'ATTENDEE',
                $this->identity->mailtoFor($assigneeUid),
                $this->identity->propertyParamsFor($assigneeUid) + [
                    'CUTYPE'   => 'INDIVIDUAL',
                    'ROLE'     => 'REQ-PARTICIPANT',
                    'PARTSTAT' => $assigneeUid === $creator ? 'ACCEPTED' : 'NEEDS-ACTION',
                ],
            );
        }

        if ($duedate !== null) {
            try {
                $dt = new \DateTimeImmutable($duedate, new \DateTimeZone('UTC'));
                $vtodo->add('DUE', $dt);
            } catch (\Throwable $e) {
            }
        }

        $vcalendar->add($vtodo);
        return $vcalendar->serialize();
    }

    /** Generate a unique RFC 5545-compliant UID. */
    private function generateUid(): string {
        return strtolower(bin2hex(random_bytes(16))) . '@teamhub';
    }
}
