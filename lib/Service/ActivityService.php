<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\TeamHub\Service\AuditService;

/**
 * ActivityService — team activity feed and calendar event read/write.
 *
 * Extracted from TeamService in v2.25.0.
 * Responsibilities:
 *   - getTeamActivity()       query NC activity table for all team resources
 *   - getTeamCalendarEvents() read upcoming VEVENT objects from calendarobjects
 *   - createCalendarEvent()   write a new VEVENT via CalDavBackend
 */
class ActivityService {

    public function __construct(
        private ResourceService $resourceService,
        private IUserSession $userSession,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private AuditService $auditService,
    ) {
    }

    // -------------------------------------------------------------------------
    // Activity feed
    // -------------------------------------------------------------------------

    /**
     * Get aggregated activity for a team by querying NC's activity table directly.
     *
     * Rather than fetching all user activity and filtering client-side (which misses
     * events and produces false positives), we query the activity table with precise
     * object_type/object_id conditions derived from the team's actual resource IDs:
     *   - circles:  object_type='circle',       object_id=teamId (member changes)
     *   - files:    object_type='files',         object_id=folder_id (numeric file ID)
     *   - deck:     object_type='deck_board',    object_id=board_id
     *   - calendar: object_type='calendar',      object_id=calendar_id
     *   - spreed:   object_type='chat'/'call',   object_id=talk_token
     *
     * Returns up to $limit items sorted newest first, with a normalised shape:
     *   { id, app, type, user, subject, message, datetime, icon, link, object_type, object_id }
     *
     * @throws \Exception if user is not authenticated
     */
    public function getTeamActivity(string $teamId, int $limit = 25, int $since = 0): array {

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $db        = $this->container->get(\OCP\IDBConnection::class);
        $resources = $this->resourceService->getTeamResources($teamId);

        // Build OR conditions: each resource type adds one clause
        $conditions = [];

        // Circles membership events — object_type varies by NC version
        foreach (['circle', 'circles'] as $objType) {
            $conditions[] = ['object_type' => $objType, 'object_id' => $teamId];
        }

        // Files — match both the folder itself (by node ID) and children (by path prefix)
        if (!empty($resources['files']['folder_id'])) {
            $folderId = (string)$resources['files']['folder_id'];
            $conditions[] = ['object_type' => 'files', 'object_id' => $folderId];
        }

        // Deck — two object_type values are used in oc_activity:
        //   'deck_board' + boardId  → board-level events (board create/update, stack events)
        //   'deck_card'  + cardId   → card-level events (card create/update/assign etc.)
        // We match the board event directly, and look up all card IDs on the board
        // so we can match card events too.
        if (!empty($resources['deck']['board_id'])) {
            $boardId = (int)$resources['deck']['board_id'];
            // Board-level events
            $conditions[] = ['object_type' => 'deck_board', 'object_id' => (string)$boardId];

            // Card-level events: fetch all card IDs that belong to this board
            try {
                $cardQb  = $db->getQueryBuilder();
                $cardRes = $cardQb->select('c.id')
                    ->from('deck_cards', 'c')
                    ->innerJoin('c', 'deck_stacks', 's', $cardQb->expr()->eq('c.stack_id', 's.id'))
                    ->where($cardQb->expr()->eq('s.board_id', $cardQb->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->executeQuery();
                $cardIds = array_column($cardRes->fetchAll(), 'id');
                $cardRes->closeCursor();

                foreach ($cardIds as $cardId) {
                    $conditions[] = ['object_type' => 'deck_card', 'object_id' => (string)$cardId];
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[ActivityService] deck card ID lookup failed', [
                    'boardId' => $boardId,
                    'error'   => $e->getMessage(),
                    'app'     => Application::APP_ID,
                ]);
            }
        }

        // Calendar
        if (!empty($resources['calendar']['id'])) {
            $calId = (string)$resources['calendar']['id'];
            foreach (['calendar', 'calendar_event'] as $objType) {
                $conditions[] = ['object_type' => $objType, 'object_id' => $calId];
            }
        }

        // Talk / Spreed — object_type='room', object_id=numeric room ID (from talk_rooms.id)
        // The token string is NOT stored in object_id — it is a bigint column with the room's
        // primary key. Matching on room_id scopes activity precisely to the team's room.
        if (!empty($resources['talk']['room_id'])) {
            $roomId = (string)$resources['talk']['room_id'];
            $conditions[] = ['object_type' => 'room', 'object_id' => $roomId];
        }

        // Build the query with OR clauses
        // PostgreSQL: activity.object_id is bigint — comparing to non-numeric strings
        // (circle IDs, talk tokens) causes "invalid input syntax for type bigint".
        // For numeric IDs use PARAM_INT; for non-numeric use object_type match only.
        try {
            $platform   = $db->getDatabasePlatform();
            $isPostgres = $platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform
                       || $platform instanceof \Doctrine\DBAL\Platforms\PostgreSQL100Platform
                       || str_contains(get_class($platform), 'PostgreSQL');

            $qb = $db->getQueryBuilder();
            $qb->select('activity_id', 'app', 'type', 'user', 'affecteduser',
                        'subject', 'subjectparams', 'message', 'messageparams',
                        'file', 'link', 'object_type', 'object_id', 'timestamp')
               ->from('activity')
               ->orderBy('timestamp', 'DESC')
               ->setMaxResults(max(75, $limit * 2));

            if ($since > 0) {
                $qb->andWhere($qb->expr()->gte('timestamp', $qb->createNamedParameter($since, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
            }

            $orClauses = [];
            foreach ($conditions as $cond) {
                // app_only: match any row from this app (used for deck card events)
                if (isset($cond['app_only'])) {
                    $orClauses[] = $qb->expr()->eq('app', $qb->createNamedParameter($cond['app_only']));
                    continue;
                }
                $objId = $cond['object_id'];
                if (is_numeric($objId)) {
                    // Numeric IDs — safe to compare directly as bigint
                    $orClauses[] = $qb->expr()->andX(
                        $qb->expr()->eq('object_type', $qb->createNamedParameter($cond['object_type'])),
                        $qb->expr()->eq('object_id',   $qb->createNamedParameter((int)$objId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    );
                } else {
                    // Non-numeric IDs (circle ID, talk token) — only match on object_type
                    // since we can't safely cast bigint on all DB platforms via QB.
                    $orClauses[] = $qb->expr()->eq('object_type', $qb->createNamedParameter($cond['object_type']));
                }
            }

            // Files: match any file whose path starts with the team folder path.
            // The activity `file` column stores the full path e.g. /Shared/Vechters/report.docx
            if (!empty($resources['files']['path'])) {
                $folderPath = rtrim($resources['files']['path'], '/');
                $appFiles   = $qb->createNamedParameter('files');
                $appSharing = $qb->createNamedParameter('files_sharing');
                $appFilter  = $qb->expr()->orX(
                    $qb->expr()->eq('app', $appFiles),
                    $qb->expr()->eq('app', $appSharing)
                );
                // Exact match (the folder share event itself)
                $orClauses[] = $qb->expr()->andX(
                    $appFilter,
                    $qb->expr()->eq('file', $qb->createNamedParameter($folderPath))
                );
                // Prefix match (files inside the folder)
                $orClauses[] = $qb->expr()->andX(
                    $appFilter,
                    $qb->expr()->like('file', $qb->createNamedParameter(
                        $db->escapeLikeParameter($folderPath) . '/%'
                    ))
                );
            }

            if (empty($orClauses)) {
                return [];
            }

            $qb->where($qb->expr()->orX(...$orClauses));

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

        } catch (\Throwable $e) {
            $this->logger->error('[ActivityService] getTeamActivity query failed', [
                'teamId' => $teamId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            return [];
        }

        // Normalise rows into a consistent shape.
        // NC logs each file event multiple times:
        //   subject=*_self  → the actor's own activity log
        //   subject=*_by    → notification copies sent to other affected users
        // Keep only *_self rows (or non-file rows), then deduplicate by
        // (object_id, type, timestamp) to collapse any remaining duplicates.
        $seen  = [];
        $items = [];
        foreach ($rows as $row) {
            $subject = $row['subject'] ?? '';

            // Skip *_by duplicates — these are observer-copies of the same event
            if (str_ends_with($subject, '_by')) {
                continue;
            }

            // Deduplicate by content fingerprint
            $fp = $row['object_id'] . '|' . $row['type'] . '|' . $row['timestamp'];
            if (isset($seen[$fp])) {
                continue;
            }
            $seen[$fp] = true;

            $items[] = [
                'activity_id' => (int)$row['activity_id'],
                'app'         => $row['app'],
                'type'        => $row['type'],
                'user'        => $row['user'] ?: $row['affecteduser'],
                'subject'     => $subject,
                'message'     => $row['message'] ?? '',
                'datetime'    => (new \DateTime('@' . (int)$row['timestamp']))->format(\DateTime::ATOM),
                'icon'        => $this->activityIcon($row['app'], $row['type']),
                'link'        => $row['link'] ?? '',
                'object_type' => $row['object_type'],
                'object_id'   => $row['object_id'],
                'file'        => $row['file'] ?? '',
            ];

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /** Map app+type to a Material Design icon name for the frontend. */
    private function activityIcon(string $app, string $type): string {
        return match(true) {
            $app === 'circles'                                  => 'AccountMultiple',
            $app === 'files' && str_contains($type, 'created') => 'FilePlus',
            $app === 'files' && str_contains($type, 'changed') => 'FileEdit',
            $app === 'files' && str_contains($type, 'deleted') => 'FileRemove',
            $app === 'files' && str_contains($type, 'restored')=> 'FileRestore',
            $app === 'files'                                    => 'File',
            $app === 'deck'                                     => 'CardText',
            str_contains($app, 'calendar')                     => 'Calendar',
            str_contains($app, 'spreed')                       => 'Chat',
            default                                            => 'Bell',
        };
    }

    // -------------------------------------------------------------------------
    // Calendar events
    // -------------------------------------------------------------------------

    /**
     * Get upcoming calendar events for a team.
     * Reads VEVENT objects from calendarobjects via Sabre VObject.
     * Returns events starting between now and 30 days from now, sorted ascending.
     */
    public function getTeamCalendarEvents(string $teamId, int $limit = 10): array {

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        if (!$this->appManager->isInstalled('calendar')) {
            return [];
        }

        try {
            $db  = $this->container->get(\OCP\IDBConnection::class);
            $now         = time();
            $futureLimit = $now + (30 * 24 * 60 * 60);

            // Fetch ALL calendar IDs connected to this team's circle.
            $qb = $db->getQueryBuilder();
            $result = $qb->select('resourceid')
                ->from('dav_shares')
                ->where($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
                ->andWhere($qb->expr()->eq('principaluri', $qb->createNamedParameter('principals/circles/' . $teamId)))
                ->executeQuery();

            $calendarIds = [];
            while ($row = $result->fetch()) {
                $calendarIds[] = (int)$row['resourceid'];
            }
            $result->closeCursor();

            if (empty($calendarIds)) {
                return [];
            }

            $events = [];

            foreach ($calendarIds as $calendarId) {
                // Fetch calendar owner + slug for building edit URLs.
                $calQb  = $db->getQueryBuilder();
                $calRes = $calQb->select('principaluri', 'uri', 'displayname')
                    ->from('calendars')
                    ->where($calQb->expr()->eq('id', $calQb->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->setMaxResults(1)
                    ->executeQuery();
                $calRow = $calRes->fetch();
                $calRes->closeCursor();

                $calOwner = '';
                $calSlug  = '';
                $calName  = '';
                if ($calRow) {
                    $parts    = explode('/', $calRow['principaluri']);
                    $calOwner = end($parts);
                    $calSlug  = $calRow['uri'];
                    $calName  = $calRow['displayname'] ?: $calRow['uri'];
                }

                $evQb = $db->getQueryBuilder();
                $evResult = $evQb->select('co.id', 'co.uri', 'co.calendardata', 'co.lastmodified')
                    ->from('calendarobjects', 'co')
                    ->where($evQb->expr()->eq('co.calendarid', $evQb->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->andWhere($evQb->expr()->eq('co.componenttype', $evQb->createNamedParameter('VEVENT')))
                    ->andWhere($evQb->expr()->notLike('co.uri', $evQb->createNamedParameter('%-deleted.ics')))
                    ->orderBy('co.lastmodified', 'DESC')
                    ->setMaxResults($limit * 3)
                    ->executeQuery();

                while ($row = $evResult->fetch()) {
                    try {
                        $vcalendar = \Sabre\VObject\Reader::read($row['calendardata']);
                        if (!isset($vcalendar->VEVENT)) {
                            continue;
                        }
                        $vevent = $vcalendar->VEVENT;
                        if (!isset($vevent->DTSTART)) {
                            continue;
                        }

                        $dtstart        = $vevent->DTSTART;
                        $startTime      = $dtstart->getDateTime();
                        $startTimestamp = $startTime->getTimestamp();

                        if ($startTimestamp < $now || $startTimestamp > $futureLimit) {
                            continue;
                        }

                        $endTime = null;
                        if (isset($vevent->DTEND)) {
                            $endTime = $vevent->DTEND->getDateTime();
                        } elseif (isset($vevent->DURATION)) {
                            $endTime = clone $startTime;
                            $endTime->add($vevent->DURATION->getDateInterval());
                        }

                        $editUrl = null;
                        if ($calOwner !== '' && $calSlug !== '') {
                            $davPath  = '/remote.php/dav/calendars/' . $calOwner . '/' . $calSlug . '/' . $row['uri'];
                            $objectId = rtrim(strtr(base64_encode($davPath), '+/', '-_'), '=');
                            $editUrl  = '/apps/calendar/timeGridWeek/now/edit/sidebar/' . $objectId . '/' . $startTimestamp;
                        }

                        $events[] = [
                            'id'           => (string)($vevent->UID ?? $row['uri']),
                            'title'        => (string)($vevent->SUMMARY ?? 'Untitled'),
                            'start'        => $startTime->format('c'),
                            'end'          => $endTime?->format('c'),
                            'location'     => isset($vevent->LOCATION)    ? (string)$vevent->LOCATION    : null,
                            'description'  => isset($vevent->DESCRIPTION) ? (string)$vevent->DESCRIPTION : null,
                            'allDay'       => !$dtstart->hasTime(),
                            'editUrl'      => $editUrl,
                            'calendarId'   => $calendarId,
                            'calendarName' => $calName,
                        ];
                    } catch (\Exception $e) {
                        $this->logger->warning('[ActivityService] Error parsing calendar event', [
                            'exception' => $e,
                            'app'       => Application::APP_ID,
                        ]);
                    }
                }
                $evResult->closeCursor();
            }

            usort($events, fn($a, $b) => strcmp($a['start'], $b['start']));
            return array_slice($events, 0, $limit);

        } catch (\Exception $e) {
            $this->logger->error('[ActivityService] Error getting calendar events', [
                'teamId'    => $teamId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Create a calendar event on the team calendar via CalDAV.
     *
     * @throws \Exception if user not authenticated, calendar app not installed,
     *                    or no calendar is connected to the team
     */
    public function createCalendarEvent(
        string $teamId,
        string $title,
        string $start,
        string $end,
        string $location = '',
        string $description = '',
        ?int   $calendarId = null
    ): void {

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        if (!$this->appManager->isInstalled('calendar')) {
            throw new \Exception('Calendar app is not installed');
        }

        $db = $this->container->get(\OCP\IDBConnection::class);

        // Use provided calendarId, or fall back to the first connected calendar.
        if ($calendarId === null) {
            $qb = $db->getQueryBuilder();
            $result = $qb->select('resourceid')
                ->from('dav_shares')
                ->where($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
                ->andWhere($qb->expr()->eq('principaluri', $qb->createNamedParameter('principals/circles/' . $teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();

            if (!$row) {
                throw new \Exception('No calendar connected to this team');
            }
            $calendarId = (int)$row['resourceid'];
        }

        // Find the calendar owner's principaluri (needed for CalDavBackend)
        $ownerQb  = $db->getQueryBuilder();
        $ownerRes = $ownerQb->select('principaluri')
            ->from('calendars')
            ->where($ownerQb->expr()->eq('id', $ownerQb->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $ownerRow = $ownerRes->fetch();
        $ownerRes->closeCursor();

        if (!$ownerRow) {
            throw new \Exception('Calendar not found');
        }

        // Resolve team Talk room URL — write to LOCATION so Talk's scheduled
        // meetings panel picks it up (Talk reads the LOCATION field, not DESCRIPTION).
        $talkUrl = null;
        if ($this->appManager->isInstalled('spreed')) {
            try {
                $talkQb  = $db->getQueryBuilder();
                $talkRes = $talkQb->select('a.room_id')
                    ->from('talk_attendees', 'a')
                    ->where($talkQb->expr()->eq('a.actor_type', $talkQb->createNamedParameter('circles')))
                    ->andWhere($talkQb->expr()->eq('a.actor_id', $talkQb->createNamedParameter($teamId)))
                    ->setMaxResults(1)
                    ->executeQuery();
                $talkRow = $talkRes->fetch();
                $talkRes->closeCursor();

                if ($talkRow) {
                    $roomQb  = $db->getQueryBuilder();
                    $roomRes = $roomQb->select('token')
                        ->from('talk_rooms')
                        ->where($roomQb->expr()->eq('id', $roomQb->createNamedParameter((int)$talkRow['room_id'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->setMaxResults(1)
                        ->executeQuery();
                    $roomRow = $roomRes->fetch();
                    $roomRes->closeCursor();

                    if ($roomRow) {
                        $urlGenerator = $this->container->get(\OCP\IURLGenerator::class);
                        $talkUrl = $urlGenerator->linkToRouteAbsolute('spreed.Page.showCall', ['token' => $roomRow['token']]);
                    }
                }
            } catch (\Throwable $e) {
                // Talk lookup failure is non-fatal — continue without Talk URL
            }
        }

        // Build iCalendar VEVENT string
        $uid     = strtoupper(bin2hex(random_bytes(16)));
        $startDt = new \DateTime($start);
        $endDt   = new \DateTime($end);
        $now     = new \DateTime();
        $dtStamp = $now->format('Ymd\\THis\\Z');
        $dtStart = $startDt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z');
        $dtEnd   = $endDt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z');

        $ical  = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//TeamHub//TeamHub//EN\r\n";
        $ical .= "BEGIN:VEVENT\r\n";
        $ical .= "UID:{$uid}@teamhub\r\n";
        $ical .= "DTSTAMP:{$dtStamp}\r\n";
        $ical .= "DTSTART:{$dtStart}\r\n";
        $ical .= "DTEND:{$dtEnd}\r\n";
        $ical .= "SUMMARY:" . $this->escapeIcalText($title) . "\r\n";
        if ($talkUrl !== null) {
            // Talk reads LOCATION to detect scheduled meetings and show them in the room panel.
            // Combine with any user-supplied physical location if present.
            if ($location !== '') {
                $ical .= "LOCATION:" . $this->escapeIcalText($location . ' | ' . $talkUrl) . "\r\n";
            } else {
                $ical .= "LOCATION:" . $this->escapeIcalText($talkUrl) . "\r\n";
            }
            // URL field for calendar clients that render a dedicated Join button
            $ical .= "URL:{$talkUrl}\r\n";
        } elseif ($location !== '') {
            $ical .= "LOCATION:" . $this->escapeIcalText($location) . "\r\n";
        }
        if ($description !== '') {
            $ical .= "DESCRIPTION:" . $this->escapeIcalText($description) . "\r\n";
        }
        $ical .= "END:VEVENT\r\n";
        $ical .= "END:VCALENDAR\r\n";

        $caldav = $this->container->get(\OCA\DAV\CalDAV\CalDavBackend::class);
        $objUri = strtolower($uid) . '.ics';

        $caldav->createCalendarObject($calendarId, $objUri, $ical);
    }

    /** Escape special characters in iCalendar text property values. */
    private function escapeIcalText(string $text): string {
        return str_replace(
            ["\r\n", "\n", "\r", ',',  ';',  '\\'],
            ['\\n',  '\\n', '\\n', '\\,', '\\;', '\\\\'],
            $text
        );
    }

    // -------------------------------------------------------------------------
    // Week-scoped calendar events (for the delete-events modal in the iframe bar)
    // -------------------------------------------------------------------------

    /**
     * Return VEVENT objects that fall within a specific week for all calendars
     * connected to the team. An event is included when its DTSTART falls within
     * [weekStart, weekEnd).
     *
     * @param string $teamId
     * @param int    $weekStart  Unix timestamp — Monday 00:00:00 local
     * @param int    $weekEnd    Unix timestamp — Sunday 23:59:59 local (exclusive upper)
     * @return array<int, array{id: string, uri: string, calendarId: int, title: string,
     *                          start: string, end: string|null, allDay: bool, calendarName: string}>
     */
    public function getTeamCalendarEventsForWeek(string $teamId, int $weekStart, int $weekEnd): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        if (!$this->appManager->isInstalled('calendar')) {
            return [];
        }

        try {
            $db = $this->container->get(\OCP\IDBConnection::class);

            // All calendar IDs connected to this team's circle.
            $qb     = $db->getQueryBuilder();
            $result = $qb->select('resourceid')
                ->from('dav_shares')
                ->where($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
                ->andWhere($qb->expr()->eq('principaluri', $qb->createNamedParameter('principals/circles/' . $teamId)))
                ->executeQuery();

            $calendarIds = [];
            while ($row = $result->fetch()) {
                $calendarIds[] = (int)$row['resourceid'];
            }
            $result->closeCursor();

            if (empty($calendarIds)) {
                return [];
            }

            $events = [];

            foreach ($calendarIds as $calendarId) {
                // Resolve calendar name.
                $calQb  = $db->getQueryBuilder();
                $calRes = $calQb->select('displayname', 'uri')
                    ->from('calendars')
                    ->where($calQb->expr()->eq('id', $calQb->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->setMaxResults(1)
                    ->executeQuery();
                $calRow  = $calRes->fetch();
                $calRes->closeCursor();
                $calName = $calRow ? ($calRow['displayname'] ?: $calRow['uri']) : (string)$calendarId;

                // All VEVENT rows for this calendar; we filter by week in PHP
                // after parsing (DTSTART may be a DATE or DATE-TIME).
                $evQb  = $db->getQueryBuilder();
                $evRes = $evQb->select('co.id', 'co.uri', 'co.calendardata')
                    ->from('calendarobjects', 'co')
                    ->where($evQb->expr()->eq('co.calendarid', $evQb->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->andWhere($evQb->expr()->eq('co.componenttype', $evQb->createNamedParameter('VEVENT')))
                    ->andWhere($evQb->expr()->notLike('co.uri', $evQb->createNamedParameter('%-deleted.ics')))
                    ->executeQuery();

                while ($row = $evRes->fetch()) {
                    try {
                        $vcalendar = \Sabre\VObject\Reader::read($row['calendardata']);
                        if (!isset($vcalendar->VEVENT)) {
                            continue;
                        }
                        $vevent = $vcalendar->VEVENT;
                        if (!isset($vevent->DTSTART)) {
                            continue;
                        }

                        $dtstart   = $vevent->DTSTART;
                        $startTime = $dtstart->getDateTime();
                        $startTs   = $startTime->getTimestamp();

                        // Only include events whose start falls within the requested week.
                        if ($startTs < $weekStart || $startTs >= $weekEnd) {
                            continue;
                        }

                        $endTime = null;
                        if (isset($vevent->DTEND)) {
                            $endTime = $vevent->DTEND->getDateTime();
                        } elseif (isset($vevent->DURATION)) {
                            $endTime = clone $startTime;
                            $endTime->add($vevent->DURATION->getDateInterval());
                        }

                        $events[] = [
                            'id'           => (string)($vevent->UID ?? $row['uri']),
                            'uri'          => (string)$row['uri'],
                            'calendarId'   => $calendarId,
                            'title'        => (string)($vevent->SUMMARY ?? 'Untitled'),
                            'start'        => $startTime->format('c'),
                            'end'          => $endTime?->format('c'),
                            'allDay'       => !$dtstart->hasTime(),
                            'calendarName' => $calName,
                        ];
                    } catch (\Throwable $e) {
                        $this->logger->warning('[ActivityService] Error parsing VEVENT in week query', [
                            'uri'       => $row['uri'] ?? '',
                            'exception' => $e,
                            'app'       => Application::APP_ID,
                        ]);
                    }
                }
                $evRes->closeCursor();
            }

            usort($events, fn($a, $b) => strcmp($a['start'], $b['start']));
            return $events;

        } catch (\Exception $e) {
            $this->logger->error('[ActivityService] getTeamCalendarEventsForWeek failed', [
                'teamId'    => $teamId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Delete calendar events
    // -------------------------------------------------------------------------

    /**
     * Delete one or more calendar events identified by their (calendarId, uri) pairs.
     *
     * Membership check is the caller's responsibility (controller layer).
     * Each deletion is written to the audit log as `calendar.event_deleted`.
     *
     * @param string $teamId
     * @param array<int, array{calendarId: int, uri: string, title: string}> $events
     * @return array{deleted: int, errors: int}
     */
    public function deleteCalendarEvents(string $teamId, array $events): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        if (!$this->appManager->isInstalled('calendar')) {
            throw new \Exception('Calendar app is not installed');
        }

        $caldav  = $this->container->get(\OCA\DAV\CalDAV\CalDavBackend::class);
        $deleted = 0;
        $errors  = 0;

        foreach ($events as $event) {
            $calendarId = (int)($event['calendarId'] ?? 0);
            $uri        = trim((string)($event['uri'] ?? ''));
            $title      = trim((string)($event['title'] ?? ''));

            if ($calendarId <= 0 || $uri === '') {
                $errors++;
                continue;
            }

            try {
                $caldav->deleteCalendarObject($calendarId, $uri);
                $deleted++;

                $this->logger->debug('[ActivityService] Deleted calendar event', [
                    'teamId'     => $teamId,
                    'calendarId' => $calendarId,
                    'uri'        => $uri,
                    'app'        => Application::APP_ID,
                ]);

                $this->auditService->log(
                    $teamId,
                    'calendar.event_deleted',
                    $user->getUID(),
                    'calendar',
                    (string)$calendarId,
                    ['uri' => $uri, 'title' => $title],
                );
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->warning('[ActivityService] Failed to delete calendar event', [
                    'teamId'     => $teamId,
                    'calendarId' => $calendarId,
                    'uri'        => $uri,
                    'exception'  => $e,
                    'app'        => Application::APP_ID,
                ]);
            }
        }

        return ['deleted' => $deleted, 'errors' => $errors];
    }
}
