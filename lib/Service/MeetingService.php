<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * MeetingService — orchestrates the "Team meeting" action.
 *
 * Responsibilities (in order of execution):
 *   1. Enforce the per-team meeting_min_level permission.
 *   2. Ensure Meetings/ subfolder exists in the team's shared files folder.
 *   3. Ensure template.md exists in Meetings/ (written from PHP constant if missing).
 *   4. Render and write a named meeting-notes .md file from the template.
 *   5. Create a public share link for the notes file.
 *   6. Resolve the team Talk URL (existing room → fallback: create new room).
 *   7. Fetch team member display names for ATTENDEE lines.
 *   8. Create a VEVENT in the team calendar with all fields populated.
 *   9. Return structured result to the controller.
 *
 * Per-team meeting_min_level is stored in teamhub_team_apps:
 *   app_id = 'meeting', config = {"minLevel": <1|4|8>}
 *   1 = any member (default), 4 = moderator+, 8 = admin+
 */
class MeetingService {

    /** Meeting notes template. Placeholders use {key} syntax. */
    private const TEMPLATE_CONTENT = "# {title}\n\n**Date:** {date}  \n**Time:** {startTime} – {endTime}  \n**Location:** {location}  \n**Talk:** {talkUrl}  \n\n## Attendees\n{attendees}\n\n## Agenda\n\n## Notes\n\n## Action items\n";

    public function __construct(
        private IUserSession     $userSession,
        private IDBConnection    $db,
        private MemberService    $memberService,
        private TalkService      $talkService,
        private ContainerInterface $container,
        private LoggerInterface  $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Execute the full team meeting workflow.
     *
     * @param string $teamId    Circle/team unique_id
     * @param string $title     Meeting title
     * @param string $date      Date string YYYY-MM-DD
     * @param string $startTime Time string HH:MM
     * @param string $endTime   Time string HH:MM
     * @param string $location  Optional free-text location
     * @param string $filename  Desired base filename (without .md extension)
     * @return array{notesUrl:string,talkUrl:string|null,calendarEventCreated:bool}
     * @throws \Exception on permission failure or unrecoverable error
     */
    public function createTeamMeeting(
        string $teamId,
        string $title,
        string $date,
        string $startTime,
        string $endTime,
        string $location,
        string $filename,
        bool   $includeTalk = true,
        string $talkToken   = '',
        bool   $askAgenda   = false
    ): array {
        $this->logger->debug('[TeamHub][MeetingService] createTeamMeeting — start', [
            'teamId' => $teamId, 'title' => $title, 'date' => $date, 'app' => Application::APP_ID,
        ]);

        // 1. Permission check
        $this->enforceMinLevel($teamId);

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }
        $uid = $user->getUID();

        // 2–5. Notes file
        $notesUrl = $this->provisionNotesFile($teamId, $uid, $title, $date, $startTime, $endTime, $location, $filename);

        // 6. Talk URL — only if user opted in
        $talkUrl = null;
        if ($includeTalk) {
            $talkUrl = $this->resolveTalkUrl($teamId, $talkToken);
        }

        // 7. Attendees
        $attendees = $this->resolveAttendees($teamId);

        // 8. Calendar event
        $calendarCreated = false;
        try {
            $this->createCalendarEvent(
                teamId:    $teamId,
                uid:       $uid,
                title:     $title,
                date:      $date,
                startTime: $startTime,
                endTime:   $endTime,
                location:  $location,
                talkUrl:   $talkUrl,
                notesUrl:  $notesUrl,
                attendees: $attendees,
            );
            $calendarCreated = true;
        } catch (\Throwable $e) {
            // Calendar failure is non-fatal — notes file already exists
            $this->logger->warning('[TeamHub][MeetingService] createCalendarEvent failed — continuing without calendar', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // 9. Optional: post agenda request to Talk chat
        if ($includeTalk && $askAgenda && $talkUrl !== null && $talkToken !== '') {
            $this->postAgendaRequest($talkToken, $title, $date, $startTime, $notesUrl, $uid);
        }

        $this->logger->debug('[TeamHub][MeetingService] createTeamMeeting — complete', [
            'teamId'          => $teamId,
            'calendarCreated' => $calendarCreated,
            'hasTalkUrl'      => $talkUrl !== null,
            'app'             => Application::APP_ID,
        ]);

        return [
            'notesUrl'            => $notesUrl,
            'talkUrl'             => $talkUrl,
            'calendarEventCreated' => $calendarCreated,
        ];
    }

    /**
     * Get the meeting_min_level for this team.
     * Returns 1 (any member) when no row exists yet.
     */
    public function getMeetingMinLevel(string $teamId): int {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('config')
            ->from('teamhub_team_apps')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('app_id',  $qb->createNamedParameter('meeting')))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();

        if (!$row || empty($row['config'])) {
            return 1;
        }

        $config = json_decode($row['config'], true);
        $level  = (int)($config['minLevel'] ?? 1);

        $this->logger->debug('[TeamHub][MeetingService] getMeetingMinLevel', [
            'teamId' => $teamId, 'level' => $level, 'app' => Application::APP_ID,
        ]);

        return in_array($level, [1, 4, 8], true) ? $level : 1;
    }

    /**
     * Persist the meeting_min_level for this team (admin only — caller must pre-authorise).
     */
    public function saveMeetingMinLevel(string $teamId, int $level): void {
        $validLevels = [1, 4, 8];
        if (!in_array($level, $validLevels, true)) {
            throw new \InvalidArgumentException("Invalid level {$level}. Must be 1, 4, or 8.");
        }

        $config = json_encode(['minLevel' => $level]);

        // Check for existing row
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('id')
            ->from('teamhub_team_apps')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('app_id',  $qb->createNamedParameter('meeting')))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();

        if ($row) {
            $uqb = $this->db->getQueryBuilder();
            $uqb->update('teamhub_team_apps')
                ->set('config', $uqb->createNamedParameter($config))
                ->where($uqb->expr()->eq('id', $uqb->createNamedParameter((int)$row['id'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();
        } else {
            $iqb = $this->db->getQueryBuilder();
            $iqb->insert('teamhub_team_apps')
                ->values([
                    'team_id' => $iqb->createNamedParameter($teamId),
                    'app_id'  => $iqb->createNamedParameter('meeting'),
                    'enabled' => $iqb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                    'config'  => $iqb->createNamedParameter($config),
                ])
                ->executeStatement();
        }

        $this->logger->debug('[TeamHub][MeetingService] saveMeetingMinLevel', [
            'teamId' => $teamId, 'level' => $level, 'app' => Application::APP_ID,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Enforce that the current user meets the team's meeting_min_level.
     * Falls back to requireMemberLevel (level 1) when minLevel is 1.
     */
    private function enforceMinLevel(string $teamId): void {
        $minLevel = $this->getMeetingMinLevel($teamId);

        $this->logger->debug('[TeamHub][MeetingService] enforceMinLevel', [
            'teamId' => $teamId, 'minLevel' => $minLevel, 'app' => Application::APP_ID,
        ]);

        if ($minLevel >= 8) {
            $this->memberService->requireAdminLevel($teamId);
        } elseif ($minLevel >= 4) {
            $this->memberService->requireModeratorLevel($teamId);
        } else {
            $this->memberService->requireMemberLevel($teamId);
        }
    }

    /**
     * Ensures Meetings/ folder and template.md exist, then writes the meeting notes file.
     * Returns a public share URL for the notes file.
     */
    private function provisionNotesFile(
        string $teamId,
        string $uid,
        string $title,
        string $date,
        string $startTime,
        string $endTime,
        string $location,
        string $filename
    ): string {
        $rootFolder = $this->container->get(IRootFolder::class);
        $userFolder = $rootFolder->getUserFolder($uid);

        $this->logger->debug('[TeamHub][MeetingService] provisionNotesFile — resolving team folder', [
            'teamId' => $teamId, 'uid' => $uid, 'app' => Application::APP_ID,
        ]);

        // Resolve team folder ID from oc_share (the share with the circle)
        $teamFolderNode = $this->resolveTeamFolder($teamId, $userFolder);

        if ($teamFolderNode === null) {
            throw new \Exception('Team files folder not found — please set up Files for this team first.');
        }

        // Ensure Meetings/ subfolder
        $meetingsFolder = $this->ensureSubfolder($teamFolderNode, 'Meetings');

        $this->logger->debug('[TeamHub][MeetingService] provisionNotesFile — Meetings/ folder ready', [
            'path' => $meetingsFolder->getPath(), 'app' => Application::APP_ID,
        ]);

        // Ensure template.md
        $this->ensureTemplate($meetingsFolder);

        // Render and write notes file
        $safeFilename = $this->sanitizeFilename($filename) ?: $this->defaultFilename($title, $date);
        $notesPath    = $safeFilename . '.md';

        // Avoid overwriting — append a counter if name already exists
        $finalPath = $notesPath;
        $counter   = 1;
        while ($meetingsFolder->nodeExists($finalPath)) {
            $finalPath = $safeFilename . '-' . $counter . '.md';
            $counter++;
        }

        $content = $this->renderTemplate($title, $date, $startTime, $endTime, $location);

        $this->logger->debug('[TeamHub][MeetingService] provisionNotesFile — writing notes file', [
            'path' => $finalPath, 'app' => Application::APP_ID,
        ]);

        $notesNode = $meetingsFolder->newFile($finalPath);
        $notesNode->putContent($content);

        // Create public share link
        return $this->createShareLink($notesNode, $uid);
    }

    /**
     * Find the team's shared folder node via oc_share → file_source.
     * Returns null if no share is found.
     */
    private function resolveTeamFolder(string $teamId, \OCP\Files\Folder $userFolder): ?\OCP\Files\Folder {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('file_source')
            ->from('share')
            ->where($qb->expr()->eq('share_type',  $qb->createNamedParameter(7)))  // TYPE_CIRCLE
            ->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('item_type',  $qb->createNamedParameter('folder')))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();

        if (!$row) {
            $this->logger->warning('[TeamHub][MeetingService] resolveTeamFolder — no share found', [
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
            return null;
        }

        $nodes = $userFolder->getById((int)$row['file_source']);
        if (empty($nodes)) {
            $this->logger->warning('[TeamHub][MeetingService] resolveTeamFolder — file_source not found', [
                'fileSource' => $row['file_source'], 'app' => Application::APP_ID,
            ]);
            return null;
        }

        $node = $nodes[0];
        if (!($node instanceof \OCP\Files\Folder)) {
            return null;
        }

        return $node;
    }

    /**
     * Ensure a named subfolder exists inside a parent folder.
     * Creates it if missing; returns the Folder node.
     */
    private function ensureSubfolder(\OCP\Files\Folder $parent, string $name): \OCP\Files\Folder {
        if ($parent->nodeExists($name)) {
            $node = $parent->get($name);
            if ($node instanceof \OCP\Files\Folder) {
                return $node;
            }
            // Name exists but is a file — append _folder suffix
            $name = $name . '_folder';
        }

        $this->logger->debug('[TeamHub][MeetingService] ensureSubfolder — creating', [
            'name' => $name, 'app' => Application::APP_ID,
        ]);

        return $parent->newFolder($name);
    }

    /**
     * Write template.md into the Meetings folder if it does not exist yet.
     * This file is TeamHub-owned and safe to overwrite only on creation.
     */
    private function ensureTemplate(\OCP\Files\Folder $meetingsFolder): void {
        if ($meetingsFolder->nodeExists('template.md')) {
            $this->logger->debug('[TeamHub][MeetingService] ensureTemplate — already exists', [
                'app' => Application::APP_ID,
            ]);
            return;
        }

        $this->logger->debug('[TeamHub][MeetingService] ensureTemplate — creating template.md', [
            'app' => Application::APP_ID,
        ]);

        $node = $meetingsFolder->newFile('template.md');
        $node->putContent(self::TEMPLATE_CONTENT);
    }

    /**
     * Render the meeting notes template with actual values.
     * Talk URL is added later in the calendar event step — left as placeholder for now.
     */
    private function renderTemplate(
        string $title,
        string $date,
        string $startTime,
        string $endTime,
        string $location
    ): string {
        // Render without talkUrl — Talk URL is resolved after file creation.
        // A second pass fills it in if available; otherwise the placeholder remains editable.
        return strtr(self::TEMPLATE_CONTENT, [
            '{title}'     => $title,
            '{date}'      => $date,
            '{startTime}' => $startTime,
            '{endTime}'   => $endTime,
            '{location}'  => $location !== '' ? $location : '—',
            '{talkUrl}'   => '_(will be added to calendar event)_',
            '{attendees}' => '_(see calendar event attendees)_',
        ]);
    }

    /**
     * Create a public share link (TYPE_LINK, read+write) for a file node.
     * Returns the absolute URL.
     */
    private function createShareLink(\OCP\Files\File $node, string $uid): string {
        $shareManager = $this->container->get(IShareManager::class);

        $share = $shareManager->newShare();
        $share->setNode($node);
        $share->setShareType(IShare::TYPE_LINK);
        $share->setPermissions(\OCP\Constants::PERMISSION_READ | \OCP\Constants::PERMISSION_UPDATE);
        $share->setSharedBy($uid);

        $createdShare = $shareManager->createShare($share);
        $token        = $createdShare->getToken();

        $urlGenerator = $this->container->get(\OCP\IURLGenerator::class);
        $url          = $urlGenerator->linkToRouteAbsolute('files_sharing.sharecontroller.showShare', ['token' => $token]);

        $this->logger->debug('[TeamHub][MeetingService] createShareLink — created', [
            'token' => $token, 'app' => Application::APP_ID,
        ]);

        return $url;
    }

    /**
     * Resolve the Talk URL for this team.
     * Uses the existing provisioned Talk room token; falls back to creating a new room.
     */
    private function resolveTalkUrl(string $teamId, string $suppliedToken = ''): ?string {
        // Fast path: frontend passed us the token from resources.talk.token
        if ($suppliedToken !== '') {
            $this->logger->debug('[TeamHub][MeetingService] resolveTalkUrl — using supplied token', [
                'token' => $suppliedToken, 'app' => Application::APP_ID,
            ]);
            return $this->buildTalkUrl($suppliedToken);
        }

        $this->logger->debug('[TeamHub][MeetingService] resolveTalkUrl — looking up existing room in DB', [
            'teamId' => $teamId, 'app' => Application::APP_ID,
        ]);

        // DB lookup: find room where the circle is an attendee (mirrors ResourceService pattern)
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('a.room_id')
            ->from('talk_attendees', 'a')
            ->where($qb->expr()->eq('a.actor_type', $qb->createNamedParameter('circles')))
            ->andWhere($qb->expr()->eq('a.actor_id',   $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();

        if ($row) {
            $roomId  = (int)$row['room_id'];
            $roomQb  = $this->db->getQueryBuilder();
            $roomRes = $roomQb->select('token')
                ->from('talk_rooms')
                ->where($roomQb->expr()->eq('id', $roomQb->createNamedParameter($roomId)))
                ->setMaxResults(1)
                ->executeQuery();
            $roomRow = $roomRes->fetch();
            $roomRes->closeCursor();

            if ($roomRow) {
                $this->logger->debug('[TeamHub][MeetingService] resolveTalkUrl — found existing room', [
                    'token' => $roomRow['token'], 'app' => Application::APP_ID,
                ]);
                return $this->buildTalkUrl($roomRow['token']);
            }
        }

        // Fallback: create a new Talk room
        $this->logger->debug('[TeamHub][MeetingService] resolveTalkUrl — no room found, creating', [
            'teamId' => $teamId, 'app' => Application::APP_ID,
        ]);

        $user = $this->userSession->getUser();
        if (!$user) {
            return null;
        }

        $teamName = $this->resolveTeamName($teamId);
        $result   = $this->talkService->createTalkRoom($teamId, $teamName, $user->getUID());

        if (isset($result['error'])) {
            $this->logger->warning('[TeamHub][MeetingService] resolveTalkUrl — fallback room creation failed', [
                'error' => $result['error'], 'app' => Application::APP_ID,
            ]);
            return null;
        }

        return $this->buildTalkUrl($result['token']);
    }

    /** Build a full Talk conversation URL from a room token. */
    private function buildTalkUrl(string $token): string {
        $urlGenerator = $this->container->get(\OCP\IURLGenerator::class);
        return $urlGenerator->linkToRouteAbsolute('spreed.Page.showCall', ['token' => $token]);
    }

    /**
     * Resolve team display name from circles_circle.
     */
    private function resolveTeamName(string $teamId): string {
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('name')
                ->from('circles_circle')
                ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();
            return $row ? $row['name'] : $teamId;
        } catch (\Throwable $e) {
            return $teamId;
        }
    }

    /**
     * Fetch display names of all direct team members for ATTENDEE lines.
     * Returns array of ['displayName' => string, 'email' => string|null].
     */
    private function resolveAttendees(string $teamId): array {
        $this->logger->debug('[TeamHub][MeetingService] resolveAttendees — start', [
            'teamId' => $teamId, 'app' => Application::APP_ID,
        ]);

        try {
            // Mirror MemberService: join oc_users for displayname (not oc_accounts)
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('m.user_id', 'u.displayname')
                ->from('circles_member', 'm')
                ->leftJoin('m', 'users', 'u', 'm.user_id = u.uid')
                ->where($qb->expr()->eq('m.circle_id',  $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('m.status',   $qb->createNamedParameter('Member')))
                ->andWhere($qb->expr()->eq('m.user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeQuery();

            $attendees = [];
            while ($row = $res->fetch()) {
                $uid         = (string)$row['user_id'];
                $displayName = !empty($row['displayname']) ? $row['displayname'] : $uid;
                $attendees[] = [
                    'displayName' => $displayName,
                    'uid'         => $uid,
                    'email'       => $this->resolveUserEmail($uid),
                ];
            }
            $res->closeCursor();

            $this->logger->debug('[TeamHub][MeetingService] resolveAttendees — found ' . count($attendees), [
                'app' => Application::APP_ID,
            ]);

            return $attendees;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MeetingService] resolveAttendees failed — using empty list', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Build and write a VEVENT to the team calendar via CalDavBackend.
     * Includes ATTACH (notes share link), LOCATION, DESCRIPTION (Talk URL), and ATTENDEE lines.
     */
    private function createCalendarEvent(
        string  $teamId,
        string  $uid,
        string  $title,
        string  $date,
        string  $startTime,
        string  $endTime,
        string  $location,
        ?string $talkUrl,
        string  $notesUrl,
        array   $attendees
    ): void {
        $this->logger->debug('[TeamHub][MeetingService] createCalendarEvent — start', [
            'teamId' => $teamId, 'app' => Application::APP_ID,
        ]);

        // Resolve calendar ID via dav_shares
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('resourceid')
            ->from('dav_shares')
            ->where($qb->expr()->eq('type',         $qb->createNamedParameter('calendar')))
            ->andWhere($qb->expr()->eq('principaluri', $qb->createNamedParameter('principals/circles/' . $teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();

        if (!$row) {
            throw new \Exception('No calendar connected to this team');
        }
        $calendarId = (int)$row['resourceid'];

        // Build iCalendar VEVENT
        $eventUid = strtoupper(bin2hex(random_bytes(16)));
        $startDt  = new \DateTime("{$date}T{$startTime}:00");
        $endDt    = new \DateTime("{$date}T{$endTime}:00");
        $now      = new \DateTime();

        $dtStamp = $now->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $dtStart = (clone $startDt)->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $dtEnd   = (clone $endDt)->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');

        // Build description: Talk URL + notes link
        $descParts = [];
        if ($talkUrl !== null) {
            $descParts[] = 'Talk: ' . $talkUrl;
        }
        $descParts[] = 'Meeting notes: ' . $notesUrl;
        $description = implode('\n', $descParts);

        $ical  = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//TeamHub//TeamHub//EN\r\n";
        $ical .= "BEGIN:VEVENT\r\n";
        $ical .= "UID:{$eventUid}@teamhub\r\n";
        $ical .= "DTSTAMP:{$dtStamp}\r\n";
        $ical .= "DTSTART:{$dtStart}\r\n";
        $ical .= "DTEND:{$dtEnd}\r\n";
        $ical .= "SUMMARY:" . $this->escapeIcal($title) . "\r\n";

        if ($talkUrl !== null) {
            // Talk's calendar integration detects scheduled meetings by reading LOCATION.
            // If user also provided a physical location, combine them.
            if ($location !== '') {
                $ical .= "LOCATION:" . $this->escapeIcal($location . ' | ' . $talkUrl) . "\r\n";
            } else {
                $ical .= "LOCATION:" . $this->escapeIcal($talkUrl) . "\r\n";
            }
            // URL field for calendar apps that render a dedicated Join button
            $ical .= "URL:{$talkUrl}\r\n";
        } elseif ($location !== '') {
            $ical .= "LOCATION:" . $this->escapeIcal($location) . "\r\n";
        }

        $ical .= "DESCRIPTION:" . $this->escapeIcal(implode("\n", array_map(
            fn(string $p) => str_replace('\n', "\n", $p), $descParts
        ))) . "\r\n";

        // ATTACH — notes file share link
        $ical .= "ATTACH;VALUE=URI:{$notesUrl}\r\n";

        // ORGANIZER — current user
        $organiserEmail = $this->resolveUserEmail($uid);
        if ($organiserEmail !== null) {
            $ical .= "ORGANIZER;CN=" . $this->escapeIcal($uid) . ":MAILTO:{$organiserEmail}\r\n";
        }

        // ATTENDEE lines — one per team member
        foreach ($attendees as $attendee) {
            $cn    = $this->escapeIcal($attendee['displayName']);
            $email = $attendee['email'] ?? ($attendee['uid'] . '@noreply.local');
            $ical .= "ATTENDEE;CN={$cn};PARTSTAT=NEEDS-ACTION;RSVP=TRUE:MAILTO:{$email}\r\n";
        }

        $ical .= "END:VEVENT\r\n";
        $ical .= "END:VCALENDAR\r\n";

        $caldav = $this->container->get(\OCA\DAV\CalDAV\CalDavBackend::class);
        $objUri = strtolower($eventUid) . '.ics';
        $caldav->createCalendarObject($calendarId, $objUri, $ical);

        $this->logger->debug('[TeamHub][MeetingService] createCalendarEvent — done', [
            'calendarId' => $calendarId, 'objUri' => $objUri, 'app' => Application::APP_ID,
        ]);
    }

    /** Resolve email address for a user ID from oc_accounts. */
    private function resolveUserEmail(string $uid): ?string {
        try {
            $config = $this->container->get(\OCP\IConfig::class);
            $email  = $config->getUserValue($uid, 'settings', 'email', '');
            return $email !== '' ? $email : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Sanitize a user-provided filename: strip path separators and dangerous chars. */
    private function sanitizeFilename(string $raw): string {
        $clean = preg_replace('/[\/\\\\.]+/', '-', $raw);
        $clean = preg_replace('/[^a-zA-Z0-9\-_\s]/', '', $clean ?? '');
        $clean = trim(preg_replace('/\s+/', '-', $clean) ?? '');
        return substr($clean, 0, 100);
    }

    /** Generate a default filename from title and date. */
    private function defaultFilename(string $title, string $date): string {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title) ?? 'meeting');
        $slug = trim($slug, '-');
        return $date . '-' . substr($slug, 0, 60);
    }

    /**
     * Post an agenda request message to the Talk room chat.
     * Uses Talk's internal ChatManager — no loopback HTTP.
     */
    private function postAgendaRequest(
        string $talkToken,
        string $title,
        string $date,
        string $startTime,
        string $notesUrl,
        string $uid
    ): void {
        $this->logger->debug('[TeamHub][MeetingService] postAgendaRequest — start', [
            'token' => $talkToken, 'app' => Application::APP_ID,
        ]);

        $message = sprintf(
            "📅 Team meeting scheduled: **%s** on %s at %s.\n" .
            "Please add your agenda items to the meeting notes: %s",
            $title,
            $date,
            $startTime,
            $notesUrl
        );

        $posted = $this->talkService->postChatMessage($talkToken, $uid, $message);

        if (!$posted) {
            $this->logger->warning('[TeamHub][MeetingService] postAgendaRequest — message not posted (see TalkService log)', [
                'app' => Application::APP_ID,
            ]);
        }
    }

    /** Escape special characters in iCalendar text property values. */
    private function escapeIcal(string $text): string {
        return str_replace(
            ["\r\n", "\n",  "\r",  ',',   ';',   '\\'],
            ['\\n',  '\\n', '\\n', '\\,', '\\;', '\\\\'],
            $text
        );
    }
}
