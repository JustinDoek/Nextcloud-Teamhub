<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IUserSession;
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

        $calendarId = $this->resolveCalendarId($teamId);
        if ($calendarId === null) {
            return [];
        }


        $rows = $this->fetchVtodoRows($calendarId);

        $now       = new \DateTimeImmutable();
        $cutoff    = $now->modify('+14 days');
        $tasks     = [];

        foreach ($rows as $row) {
            try {
                $task = $this->parseVtodo($row, $now, $cutoff);
                if ($task !== null) {
                    $tasks[] = $task;
                }
            } catch (\Throwable $e) {
            }
        }

        // Sort by due date ascending; tasks without a due date go last.
        usort($tasks, static function (array $a, array $b): int {
            if ($a['duedate'] === null && $b['duedate'] === null) return 0;
            if ($a['duedate'] === null) return 1;
            if ($b['duedate'] === null) return -1;
            return strcmp($a['duedate'], $b['duedate']);
        });

        $result = array_slice($tasks, 0, 10);
        return $result;
    }

    /**
     * Create a VTODO in the team calendar.
     *
     * @param string      $teamId   Circle/team ID
     * @param string      $title    Task title (required)
     * @param string|null $duedate  ISO 8601 datetime string or null
     * @param string|null $description Optional description
     * @return array{uri:string,title:string}
     * @throws \Exception when Tasks app missing, calendar not found, or insert fails
     */
    public function createTeamTask(string $teamId, string $title, ?string $duedate, ?string $description): array {
        $this->assertTasksApp();

        $calendarId = $this->resolveCalendarId($teamId);
        if ($calendarId === null) {
            throw new \Exception('No calendar found for this team.');
        }

        $uid     = $this->generateUid();
        $icsData = $this->buildVtodoIcs($uid, $title, $duedate, $description);
        $uri     = $uid . '.ics';


        // Persist via CalDavBackend (preferred — updates indices and caches).
        $stored = false;
        try {
            /** @var \OCA\DAV\CalDAV\CalDavBackend $backend */
            $backend = \OC::$server->get(\OCA\DAV\CalDAV\CalDavBackend::class);
            $backend->createCalendarObject(
                (int)$calendarId,
                $uri,
                $icsData,
            );
            $stored = true;
        } catch (\Throwable $e) {
            $this->logger->warning('[TaskService] createTeamTask — CalDavBackend failed, falling back to QB insert', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
        }

        // Fallback: direct QB insert (still triggers NC's DB indices).
        if (!$stored) {
            $this->insertCalendarObject($calendarId, $uri, $icsData);
        }

        return ['uri' => $uri, 'title' => $title];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function assertTasksApp(): void {
        if (!$this->isTasksAppAvailable()) {
            throw new \Exception('Tasks app is not installed.');
        }
    }

    /**
     * Resolve the team's shared calendar ID from the calendarobjects table.
     * Delegates to ResourceService to keep resource resolution centralised.
     */
    private function resolveCalendarId(string $teamId): ?int {
        try {
            $resources = $this->resourceService->getTeamResources($teamId);
            $id = $resources['calendar']['id'] ?? null;
            return $id !== null ? (int)$id : null;
        } catch (\Throwable $e) {
            $this->logger->warning('[TaskService] resolveCalendarId failed', [
                'teamId' => $teamId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            return null;
        }
    }

    /**
     * Fetch raw VTODO rows from calendarobjects for the given calendar.
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

    /**
     * Direct QB fallback insert into calendarobjects.
     * Only used when CalDavBackend::createCalendarObject() throws.
     */
    private function insertCalendarObject(int $calendarId, string $uri, string $icsData): void {
        $now  = time();
        $etag = md5($icsData);
        $size = strlen($icsData);

        $qb = $this->db->getQueryBuilder();
        $qb->insert('calendarobjects')
            ->setValue('calendarid',    $qb->createNamedParameter($calendarId))
            ->setValue('uri',           $qb->createNamedParameter($uri))
            ->setValue('calendardata',  $qb->createNamedParameter($icsData))
            ->setValue('lastmodified',  $qb->createNamedParameter($now))
            ->setValue('etag',          $qb->createNamedParameter($etag))
            ->setValue('size',          $qb->createNamedParameter($size))
            ->setValue('componenttype', $qb->createNamedParameter('VTODO'))
            ->setValue('firstoccurence',$qb->createNamedParameter(0))
            ->setValue('lastoccurence', $qb->createNamedParameter(0))
            ->setValue('uid',           $qb->createNamedParameter($uri))
            ->setValue('classification',$qb->createNamedParameter(0))
            ->setValue('calendartype',  $qb->createNamedParameter(0))
            ->executeStatement();

    }

    /** Generate a unique RFC 5545-compliant UID. */
    private function generateUid(): string {
        return strtolower(bin2hex(random_bytes(16))) . '@teamhub';
    }
}
