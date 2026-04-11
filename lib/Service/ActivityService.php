<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

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

        // Deck board — match the board itself by ID, plus any deck app event
        // (card events use card ID as object_id, not board ID)
        if (!empty($resources['deck']['board_id'])) {
            $boardId = (string)$resources['deck']['board_id'];
            foreach (['deck_board', 'deck_card', 'deck'] as $objType) {
                $conditions[] = ['object_type' => $objType, 'object_id' => $boardId];
            }
            // Also include all deck app events — card IDs differ from board ID
            $conditions[] = ['app_only' => 'deck'];
        }

        // Calendar
        if (!empty($resources['calendar']['id'])) {
            $calId = (string)$resources['calendar']['id'];
            foreach (['calendar', 'calendar_event'] as $objType) {
                $conditions[] = ['object_type' => $objType, 'object_id' => $calId];
            }
        }

        // Talk / Spreed — object_id is the room token
        if (!empty($resources['talk']['token'])) {
            $token = $resources['talk']['token'];
            foreach (['call', 'chat', 'room'] as $objType) {
                $conditions[] = ['object_type' => $objType, 'object_id' => $token];
            }
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
            $db = $this->container->get(\OCP\IDBConnection::class);

            $qb = $db->getQueryBuilder();
            $result = $qb->select('resourceid')
                ->from('dav_shares')
                ->where($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
                ->andWhere($qb->expr()->eq('principaluri', $qb->createNamedParameter('principals/circles/' . $teamId)))
                ->executeQuery();

            $row = $result->fetch();
            $result->closeCursor();

            if (!$row) {
                return [];
            }

            $calendarId  = (int)$row['resourceid'];
            $now         = time();
            $futureLimit = $now + (30 * 24 * 60 * 60);

            $qb = $db->getQueryBuilder();
            $result = $qb->select('co.id', 'co.uri', 'co.calendardata', 'co.lastmodified')
                ->from('calendarobjects', 'co')
                ->where($qb->expr()->eq('co.calendarid', $qb->createNamedParameter($calendarId)))
                ->andWhere($qb->expr()->eq('co.componenttype', $qb->createNamedParameter('VEVENT')))
                ->orderBy('co.lastmodified', 'DESC')
                ->setMaxResults($limit * 3)
                ->executeQuery();

            $events = [];
            while ($row = $result->fetch()) {
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

                    $events[] = [
                        'id'          => (string)($vevent->UID ?? $row['uri']),
                        'title'       => (string)($vevent->SUMMARY ?? 'Untitled'),
                        'start'       => $startTime->format('c'),
                        'end'         => $endTime?->format('c'),
                        'location'    => isset($vevent->LOCATION)    ? (string)$vevent->LOCATION    : null,
                        'description' => isset($vevent->DESCRIPTION) ? (string)$vevent->DESCRIPTION : null,
                        'allDay'      => !$dtstart->hasTime(),
                    ];
                } catch (\Exception $e) {
                    $this->logger->warning('[ActivityService] Error parsing calendar event', [
                        'exception' => $e,
                        'app'       => Application::APP_ID,
                    ]);
                }
            }
            $result->closeCursor();

            usort($events, fn($a, $b) => strcmp($a['start'], $b['start']));
            $events = array_slice($events, 0, $limit);

            return $events;

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
     * Looks up the calendar by dav_shares principal, then writes a VEVENT
     * via CalDavBackend::createCalendarObject().
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
        string $description = ''
    ): void {

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        if (!$this->appManager->isInstalled('calendar')) {
            throw new \Exception('Calendar app is not installed');
        }

        $db = $this->container->get(\OCP\IDBConnection::class);

        // Find the calendar ID for this team
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
        if ($location !== '') {
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
}
