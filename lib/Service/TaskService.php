<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\AppNotAvailableException;
use OCA\TeamHub\Exception\NotFoundException;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * TaskService — reads and creates VTODO tasks in the team calendar.
 *
 * Tasks are stored as iCal VTODO objects in the `calendarobjects` table,
 * exactly like VEVENTs. Sabre/VObject (bundled with every NC install) is used
 * for parsing and generation. No CalDAV HTTP calls are made.
 *
 * Responsibilities:
 *   - Verify the Tasks app is installed before any operation.
 *   - Retrieve upcoming (≤14 days) non-completed VTODOs for a team calendar.
 *   - Create a new VTODO in a team calendar.
 */
class TaskService {

    public function __construct(
        private IAppManager $appManager,
        private IDBConnection $db,
        private IUserSession $userSession,
        private ResourceService $resourceService,
        // ContainerInterface is used to lazily resolve the CalDavBackend
        // — importing OCA\DAV\CalDAV\CalDavBackend directly would create
        // a hard dependency on the DAV app being installed at compile time.
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
     * @return array{uri:string,title:string}
     * @throws \Exception when Tasks app missing, calendar not found, or insert fails
     */
    public function createTeamTask(string $teamId, string $title, ?string $duedate, ?string $description, ?int $calendarId = null): array {
        $this->assertTasksApp();

        if ($calendarId === null) {
            $calendarId = $this->resolveCalendarId($teamId);
        }
        if ($calendarId === null) {
            throw new NotFoundException('No calendar found for this team.');
        }

        $uid     = $this->generateUid();
        $icsData = $this->buildVtodoIcs($uid, $title, $duedate, $description);
        $uri     = $uid . '.ics';

        // v3.100.8 (apps.md W-6) — persist via the fully public
        // ICalendarManager API. The team calendar is bound to the circle
        // principal `principals/circles/{teamId}`; we walk that principal's
        // calendars for the matching ID and call createFromString on it.
        // Failure surfaces as an exception the caller can handle — no raw
        // QB fallback is needed (the previous CalDavBackend + QB dual path
        // was hedging against version drift that ICalendar sits above).
        try {
            $calendarManager = $this->container->get(\OCP\Calendar\ICalendarManager::class);
            $principalUri = 'principals/circles/' . $teamId;
            $calendars = $calendarManager->getCalendarsForPrincipal($principalUri);
            $target = null;
            foreach ($calendars as $cal) {
                if ((int)$cal->getKey() === (int)$calendarId
                    && $cal instanceof \OCP\Calendar\ICreateFromString
                ) {
                    $target = $cal;
                    break;
                }
            }
            if ($target === null) {
                throw new NotFoundException(
                    'Calendar ' . $calendarId . ' not writable from principal ' . $principalUri,
                );
            }
            $target->createFromString($uri, $icsData);
        } catch (NotFoundException $e) {
            throw $e;
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

        return ['uri' => $uri, 'title' => $title];
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
     * ICalendarManager only hands out per-principal. Reverse-looking-up
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
     * Parse a single VTODO calendar object row into a normalised task array.
     *
     * Returns null when the task should be excluded (completed, cancelled,
     * no due date within the upcoming window).
     *
     * @param array{uri:string,calendardata:string} $row
     * @return array{id:string,title:string,duedate:string|null,priority:int,status:string,url:string}|null
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

        $title    = (string)($vtodo->SUMMARY ?? $vtodo->DESCRIPTION ?? t('teamhub', 'Untitled task'));
        $priority = (int)(string)($vtodo->PRIORITY ?? '0');
        $uid      = (string)($vtodo->UID ?? $row['uri']);

        return [
            'id'       => $uid,
            'title'    => $title,
            'duedate'  => $dueDateString,
            'priority' => $priority,
            'status'   => $status ?: 'NEEDS-ACTION',
            'url'      => '/apps/tasks',
        ];
    }

    /**
     * Build the iCalendar VCALENDAR string containing a single VTODO component.
     */
    private function buildVtodoIcs(string $uid, string $title, ?string $duedate, ?string $description): string {
        $vcalendar = new \Sabre\VObject\Component\VCalendar([
            'PRODID'  => '-//TeamHub//TeamHub Tasks//EN',
            'VERSION' => '2.0',
        ]);

        $vtodo = $vcalendar->createComponent('VTODO');
        $vtodo->add('UID',       $uid);
        $vtodo->add('SUMMARY',   $title);
        $vtodo->add('STATUS',    'NEEDS-ACTION');
        $vtodo->add('CREATED',   new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $vtodo->add('DTSTAMP',   new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $vtodo->add('LAST-MODIFIED', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        if ($description !== null && trim($description) !== '') {
            $vtodo->add('DESCRIPTION', trim($description));
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
