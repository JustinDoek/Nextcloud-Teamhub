<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\AuditLogMapper;
use OCA\TeamHub\Exception\AppNotAvailableException;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\BudgetService;
use OCA\TeamHub\Service\TimeService;

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
        private IUserManager $userManager,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private AuditService $auditService,
        private AuditLogMapper $auditLogMapper,
        private TimeService $timeService,
        private BudgetService $budgetService,
    ) {
    }

    /**
     * Resolve a UID to its display name, with a per-request cache so a feed
     * of 25 rows dominated by 3–4 actors only hits IUserManager 3–4 times.
     * Falls back to the UID itself when the user is unknown (deleted user).
     *
     * @var array<string, string>
     */
    private array $displayNameCache = [];

    private function resolveDisplayName(string $uid): string {
        if ($uid === '') return '';
        if (isset($this->displayNameCache[$uid])) {
            return $this->displayNameCache[$uid];
        }
        $name = $this->userManager->get($uid)?->getDisplayName();
        $resolved = ($name !== null && $name !== '') ? $name : $uid;
        $this->displayNameCache[$uid] = $resolved;
        return $resolved;
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

        // Deck — $resources['deck'] is an array of { board_id, name, color } objects
        // (0, 1, or many boards). The old single-board check ($resources['deck']['board_id'])
        // always failed for the multi-board shape introduced in 3.28.
        //
        // Two object_type values in oc_activity:
        //   'deck_board' + boardId  → board-level events (create, stack rename, share, …)
        //   'deck_card'  + cardId   → card-level events (create, assign, due date, …)
        //
        // We add one condition per board for board-level events, then fetch all card IDs
        // for ALL connected boards in a single query and add a single deck_card_ids marker
        // that becomes one IN clause in the main query (instead of one OR per card).
        $deckBoardIds = [];
        if (!empty($resources['deck']) && is_array($resources['deck'])) {
            foreach ($resources['deck'] as $deckBoard) {
                $bid = (int)($deckBoard['board_id'] ?? 0);
                if ($bid > 0) {
                    $deckBoardIds[] = $bid;
                    $conditions[]   = ['object_type' => 'deck_board', 'object_id' => (string)$bid];
                }
            }
        }

        if (!empty($deckBoardIds)) {
            // Card-level events: one query for all boards, one IN clause in the main query.
            try {
                $cardQb  = $db->getQueryBuilder();
                $cardRes = $cardQb->select('c.id')
                    ->from('deck_cards', 'c')
                    ->innerJoin('c', 'deck_stacks', 's', $cardQb->expr()->eq('c.stack_id', 's.id'))
                    ->where($cardQb->expr()->in(
                        's.board_id',
                        $cardQb->createNamedParameter($deckBoardIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)
                    ))
                    ->executeQuery();
                $allCardIds = array_map('intval', array_column($cardRes->fetchAll(), 'id'));
                $cardRes->closeCursor();

                if (!empty($allCardIds)) {
                    // Special marker: handled as a single IN clause in the OR assembly below
                    $conditions[] = ['deck_card_ids' => $allCardIds];
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][ActivityService] deck card ID lookup failed', [
                    'boardIds' => $deckBoardIds,
                    'error'    => $e->getMessage(),
                    'app'      => Application::APP_ID,
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
                // deck_card_ids: single IN clause for all deck card IDs across all boards
                if (isset($cond['deck_card_ids'])) {
                    $orClauses[] = $qb->expr()->andX(
                        $qb->expr()->eq('object_type', $qb->createNamedParameter('deck_card')),
                        $qb->expr()->in('object_id',   $qb->createNamedParameter(
                            $cond['deck_card_ids'],
                            \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY
                        ))
                    );
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
            $this->logger->error('[TeamHub][ActivityService] getTeamActivity query failed', [
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

            // Parse Deck subjectparams JSON to extract board/card names for richer display.
            // Schema (Deck writes this): {"board":{"id":N,"title":"X"},"card":{"id":N,"title":"Y"}}
            $boardName = '';
            $cardTitle  = '';
            if ($row['app'] === 'deck' && !empty($row['subjectparams'])) {
                try {
                    $sp = json_decode($row['subjectparams'], true);
                    $boardName = (string)($sp['board']['title'] ?? '');
                    $cardTitle  = (string)($sp['card']['title']  ?? '');
                } catch (\Throwable $e) { /* non-fatal */ }
            }

            $actorUid = (string)($row['user'] ?: $row['affecteduser']);
            $items[] = [
                'activity_id' => (int)$row['activity_id'],
                'app'         => $row['app'],
                'type'        => $row['type'],
                'user'        => $actorUid,
                'displayName' => $this->resolveDisplayName($actorUid),
                'subject'     => $subject,
                'message'     => $row['message'] ?? '',
                'datetime'    => (new \DateTime('@' . (int)$row['timestamp']))->format(\DateTime::ATOM),
                'icon'        => $this->activityIcon($row['app'], $row['type']),
                'link'        => $row['link'] ?? '',
                'object_type' => $row['object_type'],
                'object_id'   => $row['object_id'],
                'file'        => $row['file'] ?? '',
                'board_name'  => $boardName,
                'card_title'  => $cardTitle,
            ];

            if (count($items) >= $limit) {
                break;
            }
        }

        // TeamHub-native project events (time logs + budget expenses,
        // v3.96.0). Merged in from teamhub_audit_log with per-caller
        // role gating so a time entry only surfaces here for users who
        // can also see the Time tab, and an expense only for users who
        // can see the Budget tab. See fetchProjectAuditActivity below.
        try {
            $teamHubItems = $this->fetchProjectAuditActivity($teamId, $user->getUID(), $limit, $since);
            if (!empty($teamHubItems)) {
                $items = array_merge($items, $teamHubItems);
                // Re-sort newest-first by datetime (ISO 8601 sorts lexically).
                usort($items, static fn(array $a, array $b): int => strcmp((string)$b['datetime'], (string)$a['datetime']));
                if (count($items) > $limit) {
                    $items = array_slice($items, 0, $limit);
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal — activity feed keeps working with NC-native rows.
            $this->logger->debug('[TeamHub][ActivityService] TeamHub audit merge failed: ' . $e->getMessage(), [
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
        }

        return $items;
    }

    /**
     * Pull TeamHub project events (time logs + budget expenses) out of
     * teamhub_audit_log and shape them into activity-feed items.
     *
     * Role gating uses the same tab-visibility checks the /layout bundle
     * uses:
     *   - time events only appear if $userId can view the Time tab
     *   - expense events only appear if $userId can view the Budget tab
     * A caller who can view one but not the other sees a partial merge —
     * that mirrors the tab visibility exactly (no over-share, no
     * hidden-tab data leak into the feed).
     *
     * @return array<int, array<string, mixed>>  activity-feed item shape
     */
    private function fetchProjectAuditActivity(string $teamId, string $userId, int $limit, int $since): array {
        $wantedTypes = [];
        if ($this->timeService->canUserViewTimeTab($teamId, $userId)) {
            $wantedTypes[] = 'project.time_log_added';
            $wantedTypes[] = 'project.time_log_updated';
            $wantedTypes[] = 'project.time_log_deleted';
        }
        if ($this->budgetService->canUserViewBudgetTab($teamId, $userId)) {
            $wantedTypes[] = 'project.expense_added';
            $wantedTypes[] = 'project.expense_updated';
            $wantedTypes[] = 'project.expense_deleted';
        }
        if (empty($wantedTypes)) {
            return [];
        }

        // 30-day default window matches the frontend's `since` header. Cap at
        // $limit — anything older is out of scope for the "past 30 days" feed.
        $fromTs = $since > 0 ? $since : (time() - 30 * 86400);
        $rows = $this->auditLogMapper->findByTeam($teamId, 0, $limit, $wantedTypes, $fromTs, null);

        $out = [];
        foreach ($rows as $row) {
            $eventType = (string)($row['event_type'] ?? '');
            $meta      = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];

            $actorUid = (string)($row['actor_uid'] ?? '');
            $out[] = [
                'activity_id' => 'th-' . $row['id'],   // string key — avoids id collisions with NC's activity_id
                'app'         => 'teamhub',
                'type'        => $eventType,
                'user'        => $actorUid,
                'displayName' => $this->resolveDisplayName($actorUid),
                'subject'     => $eventType,           // frontend maps to a localized template
                'message'     => '',
                'datetime'    => (new \DateTime('@' . (int)$row['created_at']))->format(\DateTime::ATOM),
                'icon'        => $this->activityIconForTeamHub($eventType),
                'link'        => '',
                'object_type' => (string)($row['target_type'] ?? ''),
                'object_id'   => (string)($row['target_id'] ?? ''),
                'file'        => '',
                'board_name'  => '',
                'card_title'  => '',
                // subjectparams carries the structured detail so the frontend
                // can render "Alice logged 1h 30m on {card}" etc. without a
                // second fetch. Kept minimal — only fields the feed needs.
                'subjectparams' => [
                    'minutes'         => isset($meta['minutes']) ? (int)$meta['minutes'] : null,
                    'projected_minor' => isset($meta['projectedMinor']) ? (int)$meta['projectedMinor'] : null,
                    'real_minor'      => isset($meta['realMinor'])      ? (int)$meta['realMinor']      : null,
                    'for_user_id'     => (string)($meta['forUserId'] ?? ''),
                    'card_id'         => isset($meta['cardId']) ? (int)$meta['cardId'] : null,
                    'lane_id'         => isset($meta['laneId']) ? (int)$meta['laneId'] : null,
                ],
            ];
        }
        return $out;
    }

    /** Map a TeamHub audit event type to a Material Design icon name. */
    private function activityIconForTeamHub(string $eventType): string {
        if (str_starts_with($eventType, 'project.time_log_'))  return 'ClockOutline';
        if (str_starts_with($eventType, 'project.expense_'))   return 'WalletOutline';
        return 'Bell';
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
                            // Standard base64 WITH padding — the Calendar app derives this id as
                            // btoa(davUrl) and decodes it with atob(), which throws on the
                            // base64url alphabet. Emitting '-'/'_' here made the event fail
                            // to resolve ('event does not exist') whenever the encoding
                            // happened to produce a '+' or '/'. Percent-encoded because standard
                            // base64 can contain '/', which would otherwise split the URL
                            // path segment the id sits in.
                            $objectId = rawurlencode(base64_encode($davPath));
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
                        $this->logger->warning('[TeamHub][ActivityService] Error parsing calendar event', [
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
            $this->logger->error('[TeamHub][ActivityService] Error getting calendar events', [
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
        ?int   $calendarId = null,
        bool   $includeTalk = true,
        string $categories = '',
        string $roomEmail = '',
        string $roomName = '',
        string $roomId = '',
        array  $attendeeUids = []
    ): string {

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        if (!$this->appManager->isInstalled('calendar')) {
            throw new AppNotAvailableException('Calendar app is not installed');
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
        // Honour the caller's $includeTalk choice: when false, no Talk URL is
        // embedded even if a room exists. Default true preserves the prior
        // behaviour for existing callers that don't pass the flag.
        $talkUrl = null;
        if ($includeTalk && $this->appManager->isInstalled('spreed')) {
            // v3.100.8 (apps.md R-4) — resolve the circle-scoped Talk room
            // via Talk Manager first; fall back to the QB path when the API
            // is unavailable or refuses (typically hidden circles).
            $token = null;
            try {
                $roomManager = $this->container->get(\OCA\Talk\Manager::class);
                $room = $roomManager->getRoomForActor('circles', $teamId);
                if ($room !== null) {
                    $token = (string)$room->getToken();
                }
            } catch (\Throwable $e) {
                $this->logger->debug('[TeamHub][ActivityService] Talk-room resolution via Manager unavailable — using DB fallback', [
                    'teamId' => $teamId, 'reason' => $e->getMessage(),
                    'app' => Application::APP_ID,
                ]);
            }

            if ($token === null) {
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
                            $token = (string)$roomRow['token'];
                        }
                    }
                } catch (\Throwable $e) {
                    // Talk lookup failure is non-fatal — continue without Talk URL
                }
            }

            if ($token !== null) {
                $urlGenerator = $this->container->get(\OCP\IURLGenerator::class);
                $talkUrl = $urlGenerator->linkToRouteAbsolute('spreed.Page.showCall', ['token' => $token]);
            }
        }

        // Build iCalendar VEVENT string.
        //
        // UID format: RFC 4122 UUIDv4 (lowercase hex, dashed). We previously
        // emitted "<32-hex>@teamhub" with a fake @-suffix domain. NC Calendar's
        // event editor "Availability will be checked" indicator never resolved
        // for those events (issue #41) while it did for NC's own events. NC
        // Calendar uses the bare UUID format and may parse the @-suffix as a
        // scheduling host, silently failing on the unresolvable "@teamhub".
        // Switching to a bare RFC 4122 UUID matches the format NC Calendar
        // writes itself.
        $bytes   = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40); // UUID v4 (random)
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80); // variant 10xx
        $hex     = bin2hex($bytes);
        $uid     = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' .
                   substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' .
                   substr($hex, 20, 12);
        $startDt = new \DateTime($start);
        $endDt   = new \DateTime($end);
        $now     = new \DateTime();
        $dtStamp = $now->format('Ymd\\THis\\Z');

        // Timezone-aware DTSTART/DTEND emission. NC Calendar's editor may
        // not run its room-availability resolver for UTC-only events
        // (Issue #41 investigation). For EU CET/CEST zones we emit a
        // VTIMEZONE block and TZID-parameterised local-time DTSTART/DTEND
        // matching NC Calendar's own emit. Other zones fall back to the
        // pre-existing UTC `Z`-suffix form (no VTIMEZONE block).
        $userTz = '';
        try {
            $userTz = (string)$this->container->get(\OCP\IConfig::class)
                ->getUserValue($user->getUID(), 'core', 'timezone', '');
        } catch (\Throwable) {
        }
        $cetCestZones = [
            'Europe/Amsterdam', 'Europe/Andorra', 'Europe/Belgrade',
            'Europe/Berlin', 'Europe/Bratislava', 'Europe/Brussels',
            'Europe/Budapest', 'Europe/Copenhagen', 'Europe/Gibraltar',
            'Europe/Ljubljana', 'Europe/Luxembourg', 'Europe/Madrid',
            'Europe/Malta', 'Europe/Monaco', 'Europe/Oslo',
            'Europe/Paris', 'Europe/Podgorica', 'Europe/Prague',
            'Europe/Rome', 'Europe/San_Marino', 'Europe/Sarajevo',
            'Europe/Skopje', 'Europe/Stockholm', 'Europe/Tirane',
            'Europe/Vaduz', 'Europe/Vatican', 'Europe/Vienna',
            'Europe/Warsaw', 'Europe/Zagreb', 'Europe/Zurich',
        ];
        $useTzid    = $userTz !== '' && in_array($userTz, $cetCestZones, true);
        $vtimezone  = '';
        $dtStartFmt = "DTSTART:{$startDt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z')}";
        $dtEndFmt   = "DTEND:{$endDt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z')}";
        if ($useTzid) {
            try {
                $tz         = new \DateTimeZone($userTz);
                $dtStartLoc = (clone $startDt)->setTimezone($tz)->format('Ymd\\THis');
                $dtEndLoc   = (clone $endDt)->setTimezone($tz)->format('Ymd\\THis');
                $dtStartFmt = "DTSTART;TZID={$userTz}:{$dtStartLoc}";
                $dtEndFmt   = "DTEND;TZID={$userTz}:{$dtEndLoc}";
                // Standard EU DST rules — all CET/CEST zones share these
                // transitions. RFC 5545 §3.6.5 permits a single VTIMEZONE
                // per VCALENDAR component; we emit one per event.
                $vtimezone  = "BEGIN:VTIMEZONE\r\nTZID:{$userTz}\r\n"
                    . "BEGIN:DAYLIGHT\r\nTZNAME:CEST\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0200\r\n"
                    . "DTSTART:19700329T020000\r\nRRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU\r\nEND:DAYLIGHT\r\n"
                    . "BEGIN:STANDARD\r\nTZNAME:CET\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\n"
                    . "DTSTART:19701025T030000\r\nRRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\r\nEND:STANDARD\r\n"
                    . "END:VTIMEZONE\r\n";
            } catch (\Throwable) {
                // Unknown / invalid timezone — fall back to UTC emission.
                $useTzid    = false;
                $vtimezone  = '';
                $dtStartFmt = "DTSTART:{$startDt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z')}";
                $dtEndFmt   = "DTEND:{$endDt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z')}";
            }
        }

        $ical  = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//TeamHub//TeamHub//EN\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        if ($vtimezone !== '') {
            $ical .= $vtimezone;
        }
        // When a room is being booked we need iTIP scheduling to fire so
        // the resource backend (e.g. RoomVox) receives the invite and can
        // accept/decline. iTIP requires ORGANIZER + at least one ATTENDEE
        // on the event. METHOD is NOT included in stored data — that
        // belongs to iTIP transport (the email body), not to the stored
        // calendar object. NC Calendar's own emit omits METHOD here and
        // the scheduling plugin attaches it itself during delivery.
        // Including METHOD in stored data confuses RoomVox's processor
        // (3.59.x symptom: room shows "checking availability" forever).
        $bookingRoom = $roomEmail !== '';
        // Organiser email is needed for ANY scheduled event (room booking or
        // per-attendee invitations), not just room bookings.
        $organiserEmail = (string)($user->getEMailAddress() ?? '');
        if ($bookingRoom && $organiserEmail === '') {
            // No organiser email = no iTIP = no actual booking. Degrade
            // gracefully to "room name in LOCATION text only". Logged so
            // an admin can fix the user's profile if booking was expected.
            $this->logger->warning('[TeamHub][ActivityService] room picked but organiser has no email — degrading to LOCATION-only', [
                'teamId' => $teamId, 'uid' => $user->getUID(), 'app' => Application::APP_ID,
            ]);
            $bookingRoom = false;
        }

        // Resolve the invitee list — uids passed in from the wizard's
        // "selected members" step. Each becomes a CalDAV ATTENDEE on the
        // event so Sabre's scheduling plugin delivers the event into the
        // attendee's personal calendar.
        //
        // Rules:
        //   - Dedupe by lowercased email (the organiser may have selected
        //     themselves in step 1; they're already ROLE=CHAIR below, so
        //     no second line for them).
        //   - Users with no email get a synthetic mailto:{uid}@{server}
        //     address. NC's internal scheduling plugin pattern-matches on
        //     mailto: → uid for local delivery; the synthetic address keeps
        //     internal delivery working when the user simply hasn't set an
        //     email in their profile. External-mail invitations will not
        //     work for those users (no real address to send to) — that's
        //     a profile issue, not a TeamHub bug.
        //
        // We also need the per-user display name for the CN parameter.
        $userManager = $this->container->get(\OCP\IUserManager::class);
        $organiserEmailLc = strtolower($organiserEmail);
        $emailSeen = $organiserEmailLc !== '' ? [$organiserEmailLc => true] : [];
        $invitees = []; // [ { email: string, cn: string } ]
        if (!empty($attendeeUids)) {
            // Synthetic-mailto host — same shape NC uses internally.
            try {
                $urlGenerator = $this->container->get(\OCP\IURLGenerator::class);
                $host = parse_url($urlGenerator->getAbsoluteURL('/'), PHP_URL_HOST) ?: 'localhost';
            } catch (\Throwable $e) {
                $host = 'localhost';
            }
            // Loop variable name MUST NOT be $uid: at the top of this
            // function we generated the event's UID as $uid (line ~567).
            // PHP foreach doesn't scope its iterator, so reassigning $uid
            // inside the loop corrupts the event UID for the write that
            // follows. The last attendee's id ended up in $uid → the
            // event was written as `<attendee_name>.ics` with UID
            // <ATTENDEE_NAME>@teamhub, deterministic across calls →
            // every subsequent meeting with the same last-attendee
            // collided on UID. Three sessions of "Sabre is doing
            // something weird" turned out to be a variable-shadowing
            // bug introduced when I added invitee resolution.
            foreach ($attendeeUids as $attendeeUid) {
                $attendeeUid = trim((string)$attendeeUid);
                if ($attendeeUid === '') {
                    continue;
                }
                // Case-insensitive uid match: NC's IUser::getUID() returns
                // canonical casing from the users table, but the wizard's
                // selectedIds come from circles_member which has been seen
                // (rarely) to differ in casing on LDAP-backed installs. A
                // case-mismatch slips a "self-invite" past this check.
                if (strcasecmp($attendeeUid, $user->getUID()) === 0) {
                    // Organiser appears once, as CHAIR — see below.
                    continue;
                }
                $u = $userManager->get($attendeeUid);
                if ($u === null) {
                    continue; // Unknown user; skip silently.
                }
                $email = (string)($u->getEMailAddress() ?? '');
                if ($email === '') {
                    $email = $attendeeUid . '@' . $host;
                    // Note: we DO log the uid here, deliberately — an
                    // attendee with no email is an actionable admin issue
                    // (profile needs an email so iMIP delivery works).
                    // Severity is info because it's not an error, just a
                    // signal the admin should clean up the user record.
                    $this->logger->info('[TeamHub][ActivityService] attendee has no email — using synthetic address', [
                        'uid' => $attendeeUid, 'app' => Application::APP_ID,
                    ]);
                }
                $emailLc = strtolower($email);
                if (isset($emailSeen[$emailLc])) {
                    continue; // Duplicate of organiser or earlier invitee.
                }
                $emailSeen[$emailLc] = true;
                $invitees[] = [
                    'email' => $email,
                    'cn'    => $u->getDisplayName() ?: $attendeeUid,
                ];
            }
        }

        // The event needs full iTIP shape (ORGANIZER + organiser-as-attendee
        // + SEQUENCE + STATUS) whenever ANY external party will receive it.
        // That's true if we booked a room (room is on the attendee list) OR
        // if we have one or more invitees.
        $useScheduling = $bookingRoom || !empty($invitees);
        if ($useScheduling && $organiserEmail === '') {
            // We can't emit an iTIP event without an organiser email — and
            // we just established there isn't one. Log and degrade the
            // invitee path the same way the room path already degrades:
            // event still gets written, just without per-attendee delivery.
            $this->logger->warning('[TeamHub][ActivityService] cannot schedule (no organiser email) — invitees will not receive the event', [
                'teamId' => $teamId, 'uid' => $user->getUID(),
                'inviteeCount' => count($invitees),
                'app' => Application::APP_ID,
            ]);
            $useScheduling = false;
            $invitees = [];
        }

        // Book RoomVox via its public API BEFORE writing the calendar event.
        // If booking fails, we abort the whole operation
        // (option A — surface, don't degrade). This is
        // the actual booking that propagates through to RoomVox's admin
        // overview; the calendar event we write afterward is a record of
        // the same booking, not an iTIP request.
        //
        // We store the resulting booking UID as an X- property on the
        // VEVENT so the delete-time hook (CalendarObjectDeletedListener)
        // can cancel the corresponding RoomVox reservation.
        $roomvoxBookingUid = null;
        if ($bookingRoom && $roomId !== '') {
            try {
                $roomvox = $this->container->get(\OCA\TeamHub\Service\RoomVoxClient::class);
                $bookingResult = $roomvox->createBooking(
                    $roomId,
                    $title,
                    // RoomVox documents ISO 8601 with timezone offset. We
                    // pass the input as the caller provided it (the wizard
                    // sends startDt.toISOString() which is UTC Z-suffixed —
                    // valid ISO 8601, accepted by RoomVox per its docs).
                    $startDt->format(\DateTimeInterface::ATOM),
                    $endDt->format(\DateTimeInterface::ATOM),
                    $organiserEmail,
                    $description,
                );
                $roomvoxBookingUid = $bookingResult['uid'];
                $this->logger->info('[TeamHub][ActivityService] RoomVox booking accepted', [
                    'roomId' => $roomId,
                    'uid'    => $roomvoxBookingUid,
                    'status' => $bookingResult['status'],
                    'app'    => Application::APP_ID,
                ]);
            } catch (\OCA\TeamHub\Service\RoomVoxClientException $e) {
                // Abort: do NOT write a calendar event we know to be unbacked
                // by a real reservation. Surface RoomVox's message verbatim.
                throw new \Exception($e->getMessage());
            } catch (\Throwable $e) {
                // Unexpected client failure — same abort policy.
                $this->logger->warning('[TeamHub][ActivityService] RoomVox client error', [
                    'roomId' => $roomId, 'err' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                throw new \Exception('RoomVox booking failed: ' . $e->getMessage());
            }
        } elseif ($bookingRoom && $roomId === '') {
            // Non-RoomVox room — the frontend deliberately omits `roomId`
            // when the picked room is backed by NC Calendar's Resource
            // Management (CRM), not RoomVox. We do NOT call RoomVoxClient
            // for these; instead Sabre's scheduling plugin handles the
            // booking via the resource backend's auto-accept logic when
            // the iTIP REQUEST is delivered to the resource principal
            // (the ATTENDEE;CUTYPE=ROOM line written below is the trigger).
            //
            // No-op here — just informational so installs running with
            // both RoomVox and CRM rooms can be distinguished in logs.
            $this->logger->info('[TeamHub][ActivityService] non-RoomVox room — booking via CalDAV scheduling', [
                'teamId' => $teamId, 'roomEmail' => $roomEmail, 'app' => Application::APP_ID,
            ]);
        }

        $ical .= "BEGIN:VEVENT\r\n";
        $ical .= "CREATED:{$dtStamp}\r\n";
        $ical .= "DTSTAMP:{$dtStamp}\r\n";
        $ical .= "LAST-MODIFIED:{$dtStamp}\r\n";
        $ical .= "UID:{$uid}\r\n";
        $ical .= "{$dtStartFmt}\r\n";
        $ical .= "{$dtEndFmt}\r\n";
        // TRANSP:OPAQUE marks the event as time-blocking — the default per
        // RFC 5545 §3.8.2.7, but emitting it explicitly matches what NC
        // Calendar writes. Investigating #41: NC Calendar's editor may gate
        // its availability resolver on the presence of this property.
        $ical .= "TRANSP:OPAQUE\r\n";
        $ical .= "SUMMARY:" . $this->escapeIcalText($title) . "\r\n";
        if ($useScheduling) {
            // Two required compliance lines for any scheduled iTIP event:
            //   SEQUENCE:0 — RFC 5546 §3.1.4 makes this mandatory; consumers
            //     that compare revisions (which RoomVox does) reject events
            //     without it.
            //   STATUS:CONFIRMED — explicitly marks the event as live (vs.
            //     TENTATIVE / CANCELLED). RoomVox's auto-accept policy is
            //     keyed off seeing CONFIRMED. Without STATUS, behaviour is
            //     implementation-defined and RoomVox stays NEEDS-ACTION.
            $ical .= "SEQUENCE:0\r\n";
            $ical .= "STATUS:CONFIRMED\r\n";
            // Organiser line — required for the scheduling plugin to know
            // where iMIP replies should be addressed.
            $organiserCn = $this->escapeIcalParamText($user->getDisplayName() ?: $user->getUID());
            $ical .= "ORGANIZER;CN={$organiserCn}:mailto:{$organiserEmail}\r\n";
            // Organiser ALSO as an ATTENDEE. RFC 5546 §3.2.1 requires the
            // organiser to appear in the ATTENDEE list of any REQUEST.
            // NC Calendar's own emit always includes this line. Sabre's
            // scheduling plugin treats events without an organiser-attendee
            // as malformed and can refuse to deliver the iTIP message.
            //
            // SCHEDULE-AGENT note (#41 investigation): we previously emitted
            // SCHEDULE-AGENT=CLIENT here to stop Sabre re-materialising the
            // event into the organiser's calendar (because we'd already
            // written it). That was needed when writes went through direct
            // CalDavBackend. With 3.82.6's ICreateFromString path, Sabre's
            // scheduling plugin handles the organiser correctly — and the
            // CLIENT value may be confusing NC Calendar's freebusy resolver
            // (#41 reporter feedback). Defaults to SERVER (RFC 6638 §7.1)
            // when omitted.
            $ical .= "ATTENDEE;CUTYPE=INDIVIDUAL;PARTSTAT=ACCEPTED;ROLE=CHAIR;CN={$organiserCn}:mailto:{$organiserEmail}\r\n";
            // Per-invitee attendees. Standard iTIP shape: NEEDS-ACTION
            // PARTSTAT (each invitee gets to accept/decline in their own
            // calendar), RSVP=TRUE so reply emails are expected, ROLE=
            // REQ-PARTICIPANT (active participant, not optional).
            foreach ($invitees as $inv) {
                $cn = $this->escapeIcalParamText($inv['cn']);
                $ical .= "ATTENDEE;CUTYPE=INDIVIDUAL;PARTSTAT=NEEDS-ACTION;ROLE=REQ-PARTICIPANT;RSVP=TRUE;CN={$cn}:mailto:{$inv['email']}\r\n";
            }
            if ($bookingRoom) {
                // Room attendee — CUTYPE=ROOM is the canonical resource type.
                // Because we already booked the room via RoomVox's public API
                // above (and aborted if that failed), the booking is already
                // confirmed; PARTSTAT=ACCEPTED is the truthful state. RSVP=FALSE
                // because no further reply is expected — RoomVox has us in its
                // overview already.
                $roomCn = $this->escapeIcalParamText($roomName !== '' ? $roomName : $roomEmail);
                $partstat = $roomvoxBookingUid !== null ? 'ACCEPTED' : 'NEEDS-ACTION';
                $rsvp     = $roomvoxBookingUid !== null ? 'FALSE'    : 'TRUE';
                $ical .= "ATTENDEE;CUTYPE=ROOM;PARTSTAT={$partstat};ROLE=REQ-PARTICIPANT;RSVP={$rsvp};CN={$roomCn}:mailto:{$roomEmail}\r\n";
                // Tracking X- properties — the CalendarObjectDeletedListener
                // reads these to know which RoomVox booking (and on which room)
                // to cancel when this calendar event is deleted. RFC 5545
                // explicitly permits experimental X- properties; consumers
                // that don't understand them ignore silently.
                if ($roomvoxBookingUid !== null) {
                    $ical .= "X-TEAMHUB-ROOMVOX-BOOKING-UID:" . $this->escapeIcalText($roomvoxBookingUid) . "\r\n";
                    $ical .= "X-TEAMHUB-ROOMVOX-ROOM-ID:" . $this->escapeIcalText($roomId) . "\r\n";
                }
            }
        }
        // LOCATION precedence:
        //   1. If a room was picked, its display name is the location.
        //      Combined with the Talk URL when present (Talk reads the
        //      LOCATION field to surface the meeting in its panel).
        //      When a room is picked we deliberately IGNORE the user-typed
        //      $location field: it may carry stale state from a previous
        //      selection (Issue #41 — LOCATION ended up as the previously
        //      picked room's UID instead of the actually-booked room). Fall
        //      back to the room's email when $roomName is empty, matching
        //      the same fallback used for the ATTENDEE CN above.
        //   2. Otherwise the free-text location field (with Talk URL).
        //   3. Otherwise just the Talk URL.
        //   4. Otherwise nothing.
        $effectiveLocation = $bookingRoom
            ? ($roomName !== '' ? $roomName : $roomEmail)
            : $location;
        if ($talkUrl !== null) {
            if ($effectiveLocation !== '') {
                $ical .= "LOCATION:" . $this->escapeIcalText($effectiveLocation . ' | ' . $talkUrl) . "\r\n";
            } else {
                $ical .= "LOCATION:" . $this->escapeIcalText($talkUrl) . "\r\n";
            }
            // URL field for calendar clients that render a dedicated Join button
            $ical .= "URL:{$talkUrl}\r\n";
        } elseif ($effectiveLocation !== '') {
            $ical .= "LOCATION:" . $this->escapeIcalText($effectiveLocation) . "\r\n";
        }
        if ($description !== '') {
            $ical .= "DESCRIPTION:" . $this->escapeIcalText($description) . "\r\n";
        }
        // CATEGORIES is a comma-separated tag list per RFC 5545 §3.8.1.2.
        // We accept either a single category or a comma-separated string —
        // either is valid for the ical line. NC Calendar surfaces these as
        // "Categories" on the event detail panel.
        if ($categories !== '') {
            $ical .= "CATEGORIES:" . $this->escapeIcalText($categories) . "\r\n";
        }
        // 15-minute pop-up reminder. ACTION:DISPLAY is the in-calendar
        // notification type — matches NC's own default. RFC 5545 §3.6.6
        // requires a DESCRIPTION on DISPLAY alarms; we use the event title
        // so the notification has something meaningful to render.
        $ical .= "BEGIN:VALARM\r\n";
        $ical .= "ACTION:DISPLAY\r\n";
        $ical .= "TRIGGER:-PT15M\r\n";
        $ical .= "DESCRIPTION:" . $this->escapeIcalText($title) . "\r\n";
        $ical .= "END:VALARM\r\n";
        $ical .= "END:VEVENT\r\n";
        $ical .= "END:VCALENDAR\r\n";

        $caldav = $this->container->get(\OCA\DAV\CalDAV\CalDavBackend::class);
        $objUri = strtolower($uid) . '.ics';

        try {
            // Route the write through OCP\Calendar\IManager → ICreateFromString
            // when scheduling is required (any event with a room or invitees).
            // The public API uses NC's EmbeddedCalDavServer which registers
            // sabre's Schedule\Plugin — same code path a real DAV PUT takes —
            // so iTIP scheduling fires reliably. Direct CalDavBackend writes
            // bypass that middleware and bridge inconsistently via the
            // CalendarObjectCreatedEvent listener (Issue #41 investigation).
            //
            // Falls back to the direct backend write on any error so the
            // pre-3.82.6 behaviour is preserved for paths that don't need
            // scheduling (non-room, no-invitee events).
            $writtenViaPublic = false;
            if ($useScheduling) {
                try {
                    $manager   = $this->container->get(\OCP\Calendar\IManager::class);
                    $calendars = $manager->getCalendarsForPrincipal(
                        'principals/users/' . $user->getUID()
                    );
                    foreach ($calendars as $cal) {
                        if ((string)$cal->getKey() !== (string)$calendarId) {
                            continue;
                        }
                        if (!$cal instanceof \OCP\Calendar\ICreateFromString) {
                            break;
                        }
                        $cal->createFromString($objUri, $ical);
                        $writtenViaPublic = true;
                        break;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('[TeamHub][ActivityService] ICreateFromString failed; falling back to backend', [
                        'calendarId' => $calendarId,
                        'exception'  => get_class($e),
                        'message'    => $e->getMessage(),
                        'app'        => Application::APP_ID,
                    ]);
                }
            }
            if (!$writtenViaPublic) {
                $caldav->createCalendarObject($calendarId, $objUri, $ical);
            }
        } catch (\Throwable $e) {
            // Surface the exception class alongside its message so admins
            // triaging a CalDAV write failure can distinguish e.g. Sabre's
            // BadRequest (UID conflict) from a permission or backend error.
            // No PII logged.
            $this->logger->warning('[TeamHub][ActivityService] createCalendarObject failed', [
                'teamId'     => $teamId,
                'calendarId' => $calendarId,
                'exception'  => get_class($e),
                'message'    => $e->getMessage(),
                'app'        => Application::APP_ID,
            ]);
            throw $e;
        }

        // Return the iCal UID so callers (e.g. DecisionService::scheduleApproverMeeting)
        // can record a back-reference from the proposal to the event.
        return $uid;
    }

    /** Escape special characters in iCalendar text property values. */
    private function escapeIcalText(string $text): string {
        return str_replace(
            ["\r\n", "\n", "\r", ',',  ';',  '\\'],
            ['\\n',  '\\n', '\\n', '\\,', '\\;', '\\\\'],
            $text
        );
    }

    /**
     * Escape an iCalendar PARAM-VALUE per RFC 5545 §3.1. The param-value
     * grammar forbids ":", ";", and "," in unquoted values; if any are
     * present the value must be wrapped in double quotes and any embedded
     * double quotes stripped (no escape mechanism exists for them in
     * params). Used for CN= values where a room or person's display name
     * might contain punctuation.
     */
    private function escapeIcalParamText(string $text): string {
        // Strip control chars and CR/LF outright — params are single-line.
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? $text;
        // No escape exists for " inside a param-value — strip them.
        $text = str_replace('"', '', $text);
        if (preg_match('/[:;,]/', $text)) {
            return '"' . $text . '"';
        }
        return $text;
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
                        $this->logger->warning('[TeamHub][ActivityService] Error parsing VEVENT in week query', [
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
            $this->logger->error('[TeamHub][ActivityService] getTeamCalendarEventsForWeek failed', [
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
            throw new AppNotAvailableException('Calendar app is not installed');
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

                $this->logger->debug('[TeamHub][ActivityService] Deleted calendar event', [
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
                $this->logger->warning('[TeamHub][ActivityService] Failed to delete calendar event', [
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
