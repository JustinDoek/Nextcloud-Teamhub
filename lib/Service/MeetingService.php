<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\DecisionMapper;
use OCA\TeamHub\Db\TeamAppResourceMapper;
use OCA\TeamHub\Exception\NotFoundException;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
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

    /**
     * Meeting notes template. Placeholders use {key} syntax.
     *
     * {tasksSection} and {proposalsSection} are filled with rendered markdown
     * when the corresponding wizard checkboxes are set, or with an empty
     * string when not. They sit between Agenda and Notes so they read as
     * pre-populated agenda items.
     */
    private const TEMPLATE_CONTENT = "# {title}\n\n**Date:** {date}  \n**Time:** {startTime} – {endTime}  \n**Location:** {location}  \n**Talk:** {talkUrl}  \n\n## Attendees\n{attendees}\n\n## Agenda\n{tasksSection}{proposalsSection}\n## Notes\n\n## Action items\n";

    public function __construct(
        private IUserSession            $userSession,
        private IDBConnection           $db,
        private MemberService           $memberService,
        private TalkService             $talkService,
        private ActivityService         $activityService,
        private ResourceService         $resourceService,
        private DecisionMapper          $decisionMapper,
        private TeamAppResourceMapper   $resourceMapper,
        private IAppManager             $appManager,
        private ContainerInterface      $container,
        private LoggerInterface         $logger,
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
     * @param string $location  Free-text location (used when no room booked)
     * @param string $filename  Desired base filename (without .md extension)
     * @param bool   $includeTalk Add Talk meeting and link in calendar event
     * @param string $talkToken Pre-resolved Talk token (skips lookup)
     * @param bool   $askAgenda Post an agenda-request message to Talk after creation
     * @param string[] $attendeeUids User ids to invite as ATTENDEEs. Empty
     *                              means "all team members" (legacy default).
     * @param string $description Body text — included in the calendar event
     *                            description AND the meeting notes preamble
     * @param string $categories  Comma-separated CATEGORIES for the calendar event
     * @param string $roomEmail   Picked room's mail address (CalDAV ATTENDEE) or ''
     * @param string $roomName    Picked room's display name (LOCATION) or ''
     * @param string $roomId      RoomVox-internal id (booking call) or ''
     * @param bool   $includeOverdueTasks Render overdue Deck cards in Tasks section
     * @param bool   $includeUnscheduledTasks Render undated Deck cards in Tasks section
     * @param bool   $includeProposals Render open/finalized decisions in Proposals section
     * @param string[] $proposalCategories When `$includeProposals` is true and
     *                 this array is non-empty, only proposals whose category
     *                 is in the list are included. Empty = no filter.
     * @return array{notesUrl:string,talkUrl:string|null,calendarEventCreated:bool,eventUid:string}
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
        bool   $includeTalk             = true,
        string $talkToken               = '',
        bool   $askAgenda               = false,
        array  $attendeeUids            = [],
        string $description             = '',
        string $categories              = '',
        string $roomEmail               = '',
        string $roomName                = '',
        string $roomId                  = '',
        bool   $includeOverdueTasks     = false,
        bool   $includeUnscheduledTasks = false,
        bool   $includeProposals        = false,
        array  $proposalCategories      = []
    ): array {
        $this->logger->debug('[TeamHub][MeetingService] createTeamMeeting — start', [
            'teamId' => $teamId, 'title' => $title, 'date' => $date,
            'attendeeCount' => count($attendeeUids),
            'includeOverdueTasks' => $includeOverdueTasks,
            'includeUnscheduledTasks' => $includeUnscheduledTasks,
            'includeProposals' => $includeProposals,
            'app' => Application::APP_ID,
        ]);

        // 1. Permission check
        $this->enforceMinLevel($teamId);

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }
        $uid = $user->getUID();

        // Resolve agenda content first — it's woven into the notes file.
        // Meeting start datetime is the cut-off for "overdue" so a card
        // due *during* the meeting still counts as scheduled, not overdue.
        $meetingStartTs = strtotime("{$date}T{$startTime}:00") ?: time();
        $tasksSection = '';
        if ($includeOverdueTasks || $includeUnscheduledTasks) {
            $tasksSection = $this->renderTasksSection(
                $teamId, $meetingStartTs,
                $includeOverdueTasks, $includeUnscheduledTasks
            );
        }
        $proposalsSection = '';
        if ($includeProposals) {
            $proposalsSection = $this->renderProposalsSection($teamId, $proposalCategories);
        }

        // 2–5. Notes file
        $notesUrl = $this->provisionNotesFile(
            $teamId, $uid, $title, $date, $startTime, $endTime,
            $location, $filename, $description, $tasksSection, $proposalsSection
        );

        // 6. Talk URL — only if user opted in
        $talkUrl = null;
        if ($includeTalk) {
            $talkUrl = $this->resolveTalkUrl($teamId, $talkToken);
        }

        // 7. Calendar event — delegate to ActivityService.createCalendarEvent
        // so room booking, per-attendee invites, ORGANIZER/ATTENDEE iTIP shape,
        // and RoomVox booking are all handled by the canonical implementation.
        $calendarCreated = false;
        $eventUid = '';
        try {
            $startIso = (new \DateTime("{$date}T{$startTime}:00"))
                ->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
            $endIso = (new \DateTime("{$date}T{$endTime}:00"))
                ->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);

            // Fold the notes URL into the calendar event description so
            // attendees can reach the file from their calendar client.
            $eventDescription = $description;
            $eventDescription = ($eventDescription !== '' ? $eventDescription . "\n\n" : '')
                . 'Meeting notes: ' . $notesUrl;

            $eventUid = $this->activityService->createCalendarEvent(
                $teamId, $title, $startIso, $endIso, $location, $eventDescription,
                null, $includeTalk, $categories,
                $roomEmail, $roomName, $roomId, $attendeeUids
            );
            $calendarCreated = true;
        } catch (\Throwable $e) {
            // Calendar failure is non-fatal — notes file already exists
            $this->logger->warning('[TeamHub][MeetingService] createCalendarEvent failed — continuing without calendar', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // 8. Optional: post agenda request to Talk chat
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
            'notesUrl'             => $notesUrl,
            'talkUrl'              => $talkUrl,
            'calendarEventCreated' => $calendarCreated,
            'eventUid'             => $eventUid,
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
        string $filename,
        string $description      = '',
        string $tasksSection     = '',
        string $proposalsSection = ''
    ): string {
        $this->logger->debug('[TeamHub][MeetingService] provisionNotesFile — resolving team folder', [
            'teamId' => $teamId, 'uid' => $uid, 'app' => Application::APP_ID,
        ]);

        // Use the canonical team-folder resolution path so both
        // shared-folder-backed and Group Folder-backed teams work the same
        // way (DESIGN.md §2.19). The resolution returns the filecache ID for
        // GF resources too; IRootFolder::getById() then resolves it through
        // the caller's mountpoints exactly like a normal share.
        $teamFolderNode = $this->resolveTeamFolder($teamId);

        if ($teamFolderNode === null) {
            throw new NotFoundException('Team files folder not found — please set up Files for this team first.');
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

        $content = $this->renderTemplate(
            $title, $date, $startTime, $endTime, $location,
            $description, $tasksSection, $proposalsSection
        );

        $this->logger->debug('[TeamHub][MeetingService] provisionNotesFile — writing notes file', [
            'path' => $finalPath, 'app' => Application::APP_ID,
        ]);

        $notesNode = $meetingsFolder->newFile($finalPath);
        $notesNode->putContent($content);

        // Create public share link
        return $this->createShareLink($notesNode, $uid);
    }

    /**
     * Resolve the team's files-folder node for the current user. Handles
     * both Group Folder-backed and legacy shared-folder-backed teams by
     * delegating folder-id resolution to ResourceService and then loading
     * the node via IRootFolder::getById on the caller's mountpoints —
     * the same pattern DecisionService::writeProposalDocument uses.
     *
     * Returns null when this team has no Files resource registered.
     */
    private function resolveTeamFolder(string $teamId): ?\OCP\Files\Folder {
        try {
            $resources = $this->resourceService->getTeamResources($teamId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MeetingService] resolveTeamFolder — getTeamResources failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }

        if (empty($resources['files']) || empty($resources['files']['folder_id'])) {
            $this->logger->warning('[TeamHub][MeetingService] resolveTeamFolder — team has no Files resource', [
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
            return null;
        }

        $folderId   = (int)$resources['files']['folder_id'];
        $folderType = (string)($resources['files']['folder_type'] ?? '');

        $rootFolder = $this->container->get(IRootFolder::class);
        $nodes      = $rootFolder->getById($folderId);

        if (empty($nodes)) {
            $this->logger->warning('[TeamHub][MeetingService] resolveTeamFolder — folder id not visible to user', [
                'teamId' => $teamId, 'folderId' => $folderId, 'folderType' => $folderType,
                'app' => Application::APP_ID,
            ]);
            return null;
        }

        $node = $nodes[0];
        if (!($node instanceof \OCP\Files\Folder)) {
            return null;
        }

        $this->logger->debug('[TeamHub][MeetingService] resolveTeamFolder — resolved', [
            'teamId' => $teamId, 'folderId' => $folderId, 'folderType' => $folderType,
            'path' => $node->getPath(), 'app' => Application::APP_ID,
        ]);

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
     *
     * tasksSection / proposalsSection are pre-rendered markdown blocks (or
     * empty strings) inserted between the Agenda heading and the Notes
     * heading. The template constant already includes a leading newline
     * before the section blocks so empty sections collapse cleanly.
     */
    private function renderTemplate(
        string $title,
        string $date,
        string $startTime,
        string $endTime,
        string $location,
        string $description      = '',
        string $tasksSection     = '',
        string $proposalsSection = ''
    ): string {
        // Render without talkUrl — Talk URL is resolved after file creation.
        // A second pass fills it in if available; otherwise the placeholder remains editable.
        $rendered = strtr(self::TEMPLATE_CONTENT, [
            '{title}'            => $title,
            '{date}'             => $date,
            '{startTime}'        => $startTime,
            '{endTime}'          => $endTime,
            '{location}'         => $location !== '' ? $location : '—',
            '{talkUrl}'          => '_(will be added to calendar event)_',
            '{attendees}'        => '_(see calendar event attendees)_',
            '{tasksSection}'     => $tasksSection,
            '{proposalsSection}' => $proposalsSection,
        ]);

        // Prepend an organiser-supplied description as a quick context block
        // ahead of the structured sections. Kept out of the template constant
        // so existing template.md files on disk stay unchanged.
        if ($description !== '') {
            $rendered = "# {$title}\n\n> " . str_replace("\n", "\n> ", $description) . "\n\n"
                . substr($rendered, strlen("# {$title}\n\n"));
        }

        return $rendered;
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
            'tokenLen' => strlen($token), 'app' => Application::APP_ID,
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
                'tokenLen' => strlen($suppliedToken), 'app' => Application::APP_ID,
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
                    'roomId' => $roomId, 'app' => Application::APP_ID,
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

    // -------------------------------------------------------------------------
    // Agenda content — Tasks and Proposals sections
    // -------------------------------------------------------------------------

    /**
     * Render the `## Tasks` markdown block for the meeting notes.
     *
     * Pulls Deck cards across all boards connected to this team and groups
     * them as Overdue (duedate < meeting start, not done, not archived) and
     * Unscheduled (no duedate, not done, not archived). Returns an empty
     * string when neither toggle is requested or no cards match — callers
     * pass the result verbatim into the template's {tasksSection} slot.
     */
    private function renderTasksSection(
        string $teamId,
        int    $meetingStartTs,
        bool   $includeOverdue,
        bool   $includeUnscheduled
    ): string {
        if (!$this->appManager->isInstalled('deck')) {
            return '';
        }
        try {
            [$overdue, $unscheduled] = $this->fetchAgendaCards($teamId, $meetingStartTs);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MeetingService] renderTasksSection — fetch failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return '';
        }

        $lines = [];
        if ($includeOverdue && !empty($overdue)) {
            foreach ($overdue as $c) {
                $due = $c['duedate']
                    ? date('Y-m-d', $c['duedate'])
                    : '—';
                $lines[] = sprintf('- [%s](%s) — due %s', $this->mdEscape($c['title']), $c['url'], $due);
            }
        }
        if ($includeUnscheduled && !empty($unscheduled)) {
            foreach ($unscheduled as $c) {
                $lines[] = sprintf('- [%s](%s) — no due date', $this->mdEscape($c['title']), $c['url']);
            }
        }
        if (empty($lines)) {
            return '';
        }
        return "\n## Tasks\n" . implode("\n", $lines) . "\n\n";
    }

    /**
     * Fetch Deck cards for this team's boards, split into [overdue, unscheduled].
     * Mirrors TimelineService's board lookup (registry primary, ACL fallback).
     *
     * @return array{0:list<array{title:string,duedate:int,url:string}>, 1:list<array{title:string,duedate:int,url:string}>}
     */
    private function fetchAgendaCards(string $teamId, int $meetingStartTs): array {
        // 1. Find board IDs — registry primary, deck_board_acl/deck_acl fallback
        $boardIds = [];
        try {
            foreach ($this->resourceMapper->findActiveByTeamAndApp($teamId, 'deck') as $r) {
                $boardIds[(int)$r->getResourceId()] = true;
            }
        } catch (\Throwable) {}
        foreach (['deck_board_acl', 'deck_acl'] as $aclTable) {
            try {
                $aqb = $this->db->getQueryBuilder();
                $ares = $aqb->select('board_id')
                    ->from($aclTable)
                    ->where($aqb->expr()->eq('type', $aqb->createNamedParameter(7, IQueryBuilder::PARAM_INT)))
                    ->andWhere($aqb->expr()->eq('participant', $aqb->createNamedParameter($teamId)))
                    ->executeQuery();
                while ($arow = $ares->fetch()) {
                    $boardIds[(int)$arow['board_id']] = true;
                }
                $ares->closeCursor();
            } catch (\Throwable) {}
        }
        if (empty($boardIds)) {
            return [[], []];
        }

        // 2. Fetch stacks → board_id mapping for URL construction
        $sqb = $this->db->getQueryBuilder();
        $sres = $sqb->select('id', 'board_id')
            ->from('deck_stacks')
            ->where($sqb->expr()->in('board_id',
                $sqb->createNamedParameter(array_keys($boardIds), IQueryBuilder::PARAM_INT_ARRAY)))
            ->executeQuery();
        $stackToBoard = [];
        while ($srow = $sres->fetch()) {
            $stackToBoard[(int)$srow['id']] = (int)$srow['board_id'];
        }
        $sres->closeCursor();
        if (empty($stackToBoard)) {
            return [[], []];
        }

        // 3. Fetch cards (SELECT * to bypass column introspection — see TimelineService.php
        // for context on why deck_cards introspection has burned us before)
        $cqb = $this->db->getQueryBuilder();
        $cres = $cqb->select('*')
            ->from('deck_cards')
            ->where($cqb->expr()->in('stack_id',
                $cqb->createNamedParameter(array_keys($stackToBoard), IQueryBuilder::PARAM_INT_ARRAY)))
            ->executeQuery();

        $overdue = [];
        $unscheduled = [];
        while ($crow = $cres->fetch()) {
            // Skip deleted / archived / done cards
            if (!empty($crow['deleted_at'])) continue;
            if (!empty($crow['archived']) && (int)$crow['archived'] === 1) continue;
            $done = $crow['done'] ?? null;
            if ($done !== null && $done !== '' && $done !== 0 && $done !== '0') continue;

            $cardId  = (int)$crow['id'];
            $stackId = (int)$crow['stack_id'];
            $boardId = $stackToBoard[$stackId] ?? 0;
            $title   = (string)($crow['title'] ?? '');
            $url     = '/apps/deck/board/' . $boardId . '/card/' . $cardId;

            $dueRaw = $crow['duedate'] ?? null;
            $dueTs  = 0;
            if ($dueRaw !== null && $dueRaw !== '' && $dueRaw !== 0 && $dueRaw !== '0') {
                // Deck stores duedate as Unix int or datetime string. Parse both.
                $dueTs = is_numeric($dueRaw) ? (int)$dueRaw : (int)strtotime((string)$dueRaw);
            }

            if ($dueTs > 0) {
                if ($dueTs < $meetingStartTs) {
                    $overdue[] = ['title' => $title, 'duedate' => $dueTs, 'url' => $url];
                }
                // Cards with a future due date are not flagged at all.
            } else {
                $unscheduled[] = ['title' => $title, 'duedate' => 0, 'url' => $url];
            }
        }
        $cres->closeCursor();

        // Stable order: overdue → most-overdue first; unscheduled → alpha by title
        usort($overdue, fn($a, $b) => $a['duedate'] <=> $b['duedate']);
        usort($unscheduled, fn($a, $b) => strcasecmp($a['title'], $b['title']));

        return [$overdue, $unscheduled];
    }

    /**
     * Render the `## Proposals` markdown block — decisions still awaiting a
     * decision (status open or finalized) for this team. Returns '' when
     * none match.
     *
     * $proposalCategories — when non-empty, only proposals whose category
     * name is in this list are included. Empty means "no filter" (all
     * categories, including null/uncategorised).
     *
     * @param string[] $proposalCategories
     */
    private function renderProposalsSection(string $teamId, array $proposalCategories = []): string {
        $filters = ['status' => ['open', 'finalized']];
        if (!empty($proposalCategories)) {
            // DecisionMapper::list uses an IN(...) filter on category, which
            // excludes NULL rows. That's deliberate here: the user picked
            // specific named categories, so uncategorised proposals would not
            // belong in the result set.
            $filters['category'] = array_values(array_map('strval', $proposalCategories));
        }

        try {
            $decisions = $this->decisionMapper->list(
                $teamId,
                $filters,
                'recent',
                null,
                100
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MeetingService] renderProposalsSection — fetch failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return '';
        }
        if (empty($decisions)) {
            return '';
        }

        $urlGenerator = $this->container->get(\OCP\IURLGenerator::class);
        $base = $urlGenerator->linkToRouteAbsolute('teamhub.page.index');

        $lines = [];
        foreach ($decisions as $d) {
            $category = $d->getCategory() ?: '—';
            $url = $base . '?team=' . rawurlencode($teamId) . '&decision=' . $d->getId();
            $lines[] = sprintf(
                '- [%s](%s) — %s',
                $this->mdEscape($d->getQuestion()),
                $url,
                $this->mdEscape($category)
            );
        }
        return "\n## Proposals\n" . implode("\n", $lines) . "\n\n";
    }

    /**
     * Escape markdown-significant characters in inline text. Keeps content
     * inside `[...]` link labels from breaking the link, and prevents user
     * input like `[abc]` or `_x_` from rendering unexpectedly.
     */
    private function mdEscape(string $s): string {
        return str_replace(
            ['\\', '[', ']', '(', ')', '`', '*', '_', '<', '>', "\r", "\n"],
            ['\\\\', '\\[', '\\]', '\\(', '\\)', '\\`', '\\*', '\\_', '\\<', '\\>', ' ', ' '],
            $s
        );
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
     *
     * Language: a Talk channel has many members with potentially different
     * UI languages, so we can't render the message "for each recipient"
     * (single message, single string). We use the **organizer's** language —
     * the user who triggered the meeting creation — matching what they'd
     * have typed themselves. Falls back to the instance default language,
     * then to English, when no preference is set.
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
            'tokenLen' => strlen($talkToken), 'app' => Application::APP_ID,
        ]);

        $config       = $this->container->get(\OCP\IConfig::class);
        $l10nFactory  = $this->container->get(\OCP\L10N\IFactory::class);
        $language     = $config->getUserValue($uid, 'core', 'lang', '');
        if ($language === '') {
            $language = $config->getSystemValue('default_language', 'en');
        }
        $l = $l10nFactory->get(Application::APP_ID, $language);

        // One translation unit so the rendered Markdown structure stays
        // intact across languages. Numbered placeholders let translators
        // reorder if their grammar needs to (e.g. "on %2$s at %3$s the
        // meeting **%1$s** is scheduled" reads naturally in some locales).
        $message = $l->t(
            "📅 Team meeting scheduled: **%1\$s** on %2\$s at %3\$s.\nPlease add your agenda items to the meeting notes: %4\$s",
            [$title, $date, $startTime, $notesUrl]
        );

        $posted = $this->talkService->postChatMessage($talkToken, $uid, $message);

        if (!$posted) {
            $this->logger->warning('[TeamHub][MeetingService] postAgendaRequest — message not posted (see TalkService log)', [
                'app' => Application::APP_ID,
            ]);
        }
    }
}
