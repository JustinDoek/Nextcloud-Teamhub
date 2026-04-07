<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * ResourceService — team resource lookup and provisioning.
 *
 * Extracted from TeamService in v2.25.0.
 * Responsibilities:
 *   - getTeamResources()    resolve Talk/Files/Calendar/Deck IDs from DB
 *   - createTeamResources() provision Talk room, Files folder, Calendar, Deck board
 *   - checkInstalledApps()  report which optional apps are installed
 *   - getTableColumns()     DB introspection utility (used here and by MemberService)
 */
class ResourceService {

    public function __construct(
        private IUserSession $userSession,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        $this->logger->debug('[ResourceService] constructed', ['app' => Application::APP_ID]);
    }

    // -------------------------------------------------------------------------
    // Resource lookup
    // -------------------------------------------------------------------------

    /**
     * Get shared resources for a team (Talk, Files, Calendar, Deck).
     *
     * Look-up strategy per app:
     *  Talk     — talk_attendees WHERE actor_type='circles' AND actor_id=teamId → join talk_rooms
     *  Files    — share WHERE share_with=teamId AND share_type=7 AND item_type='folder'
     *  Calendar — dav_shares WHERE principaluri='principals/circles/{teamId}' AND type='calendar'
     *  Deck     — deck_board_acl WHERE participant=teamId AND type=7 → join deck_boards for name
     */
    public function getTeamResources(string $teamId): array {
        $this->logger->debug('[ResourceService] getTeamResources', [
            'teamId' => $teamId,
            'app'    => Application::APP_ID,
        ]);

        // Verify the current user is actually a member of this team before
        // returning Talk tokens, file paths, or calendar IDs.
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }
        $db          = $this->container->get(\OCP\IDBConnection::class);
        $memberLevel = $this->getMemberLevelFromDb($db, $teamId, $user->getUID());
        if ($memberLevel === 0) {
            $this->logger->warning('[ResourceService] getTeamResources — non-member access attempt', [
                'teamId' => $teamId,
                'userId' => $user->getUID(),
                'app'    => Application::APP_ID,
            ]);
            throw new \Exception('Access denied');
        }

        $resources = ['talk' => null, 'files' => null, 'calendar' => null, 'deck' => null];

        try {
            $db = $this->container->get(\OCP\IDBConnection::class);

            // ── Talk ─────────────────────────────────────────────────────────
            // Find rooms where the circle is an attendee (actor_type=circles, actor_id=teamId)
            if ($this->appManager->isInstalled('spreed')) {
                try {
                    $qb = $db->getQueryBuilder();
                    $result = $qb->select('a.room_id')
                        ->from('talk_attendees', 'a')
                        ->where($qb->expr()->eq('a.actor_type', $qb->createNamedParameter('circles')))
                        ->andWhere($qb->expr()->eq('a.actor_id', $qb->createNamedParameter($teamId)))
                        ->setMaxResults(1)
                        ->executeQuery();

                    if ($row = $result->fetch()) {
                        $roomId = (int)$row['room_id'];
                        $result->closeCursor();

                        $roomQb     = $db->getQueryBuilder();
                        $roomResult = $roomQb->select('token', 'name')
                            ->from('talk_rooms')
                            ->where($roomQb->expr()->eq('id', $roomQb->createNamedParameter($roomId)))
                            ->executeQuery();
                        if ($roomRow = $roomResult->fetch()) {
                            $resources['talk'] = [
                                'token' => $roomRow['token'],
                                'name'  => $roomRow['name'],
                            ];
                        }
                        $roomResult->closeCursor();
                    } else {
                        $result->closeCursor();
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('[ResourceService] Talk resource query failed', [
                        'teamId' => $teamId,
                        'error'  => $e->getMessage(),
                        'app'    => Application::APP_ID,
                    ]);
                }
            }

            // ── Files ────────────────────────────────────────────────────────
            try {
                $qb = $db->getQueryBuilder();
                $result = $qb->select('file_source', 'file_target')
                    ->from('share')
                    ->where($qb->expr()->eq('share_with', $qb->createNamedParameter($teamId)))
                    ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(7)))
                    ->setMaxResults(1)
                    ->executeQuery();

                if ($row = $result->fetch()) {
                    $resources['files'] = [
                        'folder_id' => $row['file_source'],
                        'path'      => $row['file_target'],
                    ];
                }
                $result->closeCursor();
            } catch (\Throwable $e) {
                $this->logger->warning('[ResourceService] Files resource query failed', [
                    'teamId' => $teamId,
                    'error'  => $e->getMessage(),
                    'app'    => Application::APP_ID,
                ]);
            }

            // ── Calendar ─────────────────────────────────────────────────────
            if ($this->appManager->isInstalled('calendar')) {
                try {
                    $principalUri = 'principals/circles/' . $teamId;
                    $qb = $db->getQueryBuilder();
                    $result = $qb->select('resourceid', 'publicuri')
                        ->from('dav_shares')
                        ->where($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
                        ->andWhere($qb->expr()->eq('principaluri', $qb->createNamedParameter($principalUri)))
                        ->setMaxResults(1)
                        ->executeQuery();

                    if ($row = $result->fetch()) {
                        $calendarId = $row['resourceid'];
                        $result->closeCursor();

                        $calQb     = $db->getQueryBuilder();
                        $calResult = $calQb->select('id', 'uri', 'displayname', 'principaluri')
                            ->from('calendars')
                            ->where($calQb->expr()->eq('id', $calQb->createNamedParameter((int)$calendarId)))
                            ->executeQuery();

                        if ($calRow = $calResult->fetch()) {
                            $calName = $calRow['displayname'] ?? $calRow['uri'] ?? 'Team Calendar';

                            // The public embed token is on a separate dav_shares row with access=4
                            // (the circle share row has access=2 and a non-token publicuri)
                            $publicUri = null;
                            try {
                                $psQb  = $db->getQueryBuilder();
                                $psRes = $psQb->select('publicuri')
                                    ->from('dav_shares')
                                    ->where($psQb->expr()->eq('resourceid', $psQb->createNamedParameter((int)$calendarId)))
                                    ->andWhere($psQb->expr()->eq('type', $psQb->createNamedParameter('calendar')))
                                    ->andWhere($psQb->expr()->eq('access', $psQb->createNamedParameter(4, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                                    ->setMaxResults(1)
                                    ->executeQuery();
                                if ($psRow = $psRes->fetch()) {
                                    $publicUri = $psRow['publicuri'];
                                }
                                $psRes->closeCursor();
                            } catch (\Throwable $e) {
                                // Non-fatal — public token may not exist yet
                            }

                            $resources['calendar'] = [
                                'id'             => (int)$calendarId,
                                'uri'            => $calRow['uri'],
                                'name'           => $calName,
                                'ownerPrincipal' => $calRow['principaluri'],
                                'public_token'   => $publicUri,
                            ];
                        }
                        $calResult->closeCursor();
                    } else {
                        $result->closeCursor();
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('[ResourceService] Calendar resource query failed', [
                        'teamId' => $teamId,
                        'error'  => $e->getMessage(),
                        'app'    => Application::APP_ID,
                    ]);
                }
            }

            // ── Deck ─────────────────────────────────────────────────────────
            if ($this->appManager->isInstalled('deck')) {
                try {
                    // Try deck_board_acl first (Deck 1.x), fall back to checking deck_acl
                    $aclTable      = 'deck_board_acl';
                    $boardIdCol    = 'board_id';
                    $participantCol = 'participant';
                    $typeCol       = 'type';

                    try {
                        $test = $db->getQueryBuilder()->select($participantCol)->from($aclTable)->setMaxResults(0)->executeQuery();
                        $test->closeCursor();
                    } catch (\Throwable $e) {
                        $aclTable = 'deck_acl';
                    }

                    $qb = $db->getQueryBuilder();
                    $result = $qb->select($boardIdCol)
                        ->from($aclTable)
                        ->where($qb->expr()->eq($participantCol, $qb->createNamedParameter($teamId)))
                        ->andWhere($qb->expr()->eq($typeCol, $qb->createNamedParameter(7)))
                        ->setMaxResults(1)
                        ->executeQuery();

                    if ($row = $result->fetch()) {
                        $boardId = (int)$row[$boardIdCol];
                        $result->closeCursor();

                        $boardQb     = $db->getQueryBuilder();
                        $boardResult = $boardQb->select('id', 'title', 'color')
                            ->from('deck_boards')
                            ->where($boardQb->expr()->eq('id', $boardQb->createNamedParameter($boardId)))
                            ->executeQuery();
                        if ($boardRow = $boardResult->fetch()) {
                            $resources['deck'] = [
                                'board_id' => $boardId,
                                'name'     => $boardRow['title'],
                                'color'    => $boardRow['color'],
                            ];
                        }
                        $boardResult->closeCursor();
                    } else {
                        $result->closeCursor();
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('[ResourceService] Deck resource query failed', [
                        'teamId' => $teamId,
                        'error'  => $e->getMessage(),
                        'app'    => Application::APP_ID,
                    ]);
                }
            }

        } catch (\Throwable $e) {
            $this->logger->error('[ResourceService] getTeamResources failed', [
                'teamId' => $teamId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
        }

        $this->logger->debug('[ResourceService] getTeamResources result', [
            'teamId'   => $teamId,
            'talk'     => isset($resources['talk']['token'])    ? $resources['talk']['token']    : 'null',
            'calendar' => isset($resources['calendar']['id'])   ? $resources['calendar']['id']   : 'null',
            'deck'     => isset($resources['deck']['board_id']) ? $resources['deck']['board_id'] : 'null',
            'files'    => isset($resources['files']['folder_id']) ? $resources['files']['folder_id'] : 'null',
            'app'      => Application::APP_ID,
        ]);

        return $resources;
    }

    // -------------------------------------------------------------------------
    // Resource provisioning
    // -------------------------------------------------------------------------

    /**
     * Create app resources (Talk conversation, Files folder, Calendar, Deck board)
     * and share them with the circle. Returns per-app results.
     *
     * @param string   $teamId   Circle single ID
     * @param string[] $apps     Array of app IDs to create: 'talk', 'files', 'calendar', 'deck'
     * @param string   $teamName Display name to use for created resources
     */
    public function createTeamResources(string $teamId, array $apps, string $teamName): array {
        $this->logger->debug('[ResourceService] createTeamResources', [
            'teamId'   => $teamId,
            'teamName' => $teamName,
            'apps'     => $apps,
            'app'      => Application::APP_ID,
        ]);

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $uid     = $user->getUID();
        $results = [];

        foreach ($apps as $app) {
            try {
                switch ($app) {
                    case 'talk':
                        $results['talk'] = $this->createTalkRoom($teamId, $teamName, $uid);
                        break;
                    case 'files':
                        $results['files'] = $this->createSharedFolder($teamId, $teamName, $uid);
                        break;
                    case 'calendar':
                        $results['calendar'] = $this->createCalendar($teamId, $teamName, $uid);
                        break;
                    case 'deck':
                        $results['deck'] = $this->createDeckBoard($teamId, $teamName, $uid);
                        break;
                    case 'intravox':
                        $results['intravox'] = $this->createIntravoxPage($teamId, $teamName, $uid);
                        break;
                    default:
                        $results[$app] = ['error' => 'Unknown app'];
                }
            } catch (\Throwable $e) {
                $results[$app] = ['error' => $e->getMessage(), 'trace' => substr($e->getTraceAsString(), 0, 800)];
                $this->logger->error('[ResourceService] Failed to create resource', [
                    'teamId'  => $teamId,
                    'app'     => $app,
                    'message' => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ]);
            }
        }

        // Always return 200 with per-app results — never 500 from this endpoint.
        // Caller should inspect each result for 'error' key.
        $this->logger->debug('[ResourceService] createTeamResources complete', [
            'teamId'  => $teamId,
            'results' => array_map(fn($r) => isset($r['error']) ? 'error: '.$r['error'] : 'ok', $results),
            'app'     => Application::APP_ID,
        ]);

        return $results;
    }

    /**
     * Return which optional apps are currently installed on this NC instance.
     */
    public function checkInstalledApps(): array {
        return [
            'talk'     => $this->appManager->isInstalled('spreed'),
            'calendar' => $this->appManager->isInstalled('calendar'),
            'deck'     => $this->appManager->isInstalled('deck'),
            'intravox' => $this->appManager->isInstalled('intravox'),
        ];
    }

    /**
     * Fully delete a team resource (Option B — hard delete, all data removed).
     *
     * Per app:
     *   talk     — delete all talk_attendees rows for the room, then delete the talk_rooms row
     *   files    — delete the Files share (IShare) then delete the folder node itself
     *   calendar — delete the calendar via CalDavBackend (removes all events too)
     *   deck     — delete the board via DB (cascade removes lists, cards, ACL)
     *   intravox — remove the circle's ACL row from intravox tables (if table exists)
     *
     * Each app block is individually try/caught so one failure does not abort others.
     *
     * @param string $teamId  Circle single ID
     * @param string $app     'talk' | 'files' | 'calendar' | 'deck' | 'intravox'
     * @return array { deleted: bool, detail: string }
     */
    public function deleteTeamResource(string $teamId, string $app): array {
        $this->logger->debug('[ResourceService] deleteTeamResource start', [
            'teamId' => $teamId,
            'app'    => $app,
            'appId'  => Application::APP_ID,
        ]);

        $db = $this->container->get(\OCP\IDBConnection::class);

        switch ($app) {
            case 'talk':
                return $this->deleteTalkRoom($teamId, $db);
            case 'files':
                return $this->deleteSharedFolder($teamId, $db);
            case 'calendar':
                return $this->deleteCalendar($teamId, $db);
            case 'deck':
                return $this->deleteDeckBoard($teamId, $db);
            case 'intravox':
                return $this->deleteIntravoxAccess($teamId, $db);
            default:
                return ['deleted' => false, 'detail' => "Unknown app: {$app}"];
        }
    }

    // -------------------------------------------------------------------------
    // Private deletion helpers
    // -------------------------------------------------------------------------

    /**
     * Delete the Talk room that has this circle as an attendee.
     * Deletes all attendees first, then the room row itself.
     */
    private function deleteTalkRoom(string $teamId, \OCP\IDBConnection $db): array {
        try {
            // Find the room_id via the circle attendee row
            $qb = $db->getQueryBuilder();
            $res = $qb->select('room_id')
                ->from('talk_attendees')
                ->where($qb->expr()->eq('actor_type', $qb->createNamedParameter('circles')))
                ->andWhere($qb->expr()->eq('actor_id', $qb->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if (!$row) {
                $this->logger->info('[ResourceService] deleteTalkRoom: no room found for circle', [
                    'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
                return ['deleted' => false, 'detail' => 'No Talk room found for this team'];
            }

            $roomId = (int)$row['room_id'];

            // Delete all attendees for this room
            $daqb = $db->getQueryBuilder();
            $daqb->delete('talk_attendees')
                ->where($daqb->expr()->eq('room_id', $daqb->createNamedParameter($roomId)))
                ->executeStatement();

            // Delete the room itself
            $drqb = $db->getQueryBuilder();
            $drqb->delete('talk_rooms')
                ->where($drqb->expr()->eq('id', $drqb->createNamedParameter($roomId)))
                ->executeStatement();

            $this->logger->info('[ResourceService] deleteTalkRoom: deleted', [
                'teamId' => $teamId, 'roomId' => $roomId, 'app' => Application::APP_ID,
            ]);
            return ['deleted' => true, 'detail' => "Talk room {$roomId} deleted"];

        } catch (\Throwable $e) {
            $this->logger->error('[ResourceService] deleteTalkRoom failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['deleted' => false, 'detail' => 'Operation failed — see server log for details'];
        }
    }

    /**
     * Delete the shared Files folder for this team.
     * Removes the IShare record AND deletes the folder node itself.
     */
    private function deleteSharedFolder(string $teamId, \OCP\IDBConnection $db): array {
        try {
            // Find the share row: share_type=7 (TYPE_CIRCLE), share_with=teamId
            $qb = $db->getQueryBuilder();
            $res = $qb->select('id', 'uid_initiator', 'file_source')
                ->from('share')
                ->where($qb->expr()->eq('share_with', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(7)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if (!$row) {
                return ['deleted' => false, 'detail' => 'No Files share found for this team'];
            }

            $shareId   = (int)$row['id'];
            $ownerUid  = $row['uid_initiator'];
            $fileId    = (int)$row['file_source'];

            // Delete the share via IManager (triggers proper cleanup)
            try {
                $shareManager = $this->container->get(\OCP\Share\IManager::class);
                $share = $shareManager->getShareById('ocinternal:' . $shareId);
                $shareManager->deleteShare($share);
                $this->logger->debug('[ResourceService] deleteSharedFolder: share deleted via IManager', [
                    'shareId' => $shareId, 'app' => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                // Fallback: direct DB delete of the share row
                $this->logger->warning('[ResourceService] deleteSharedFolder: IManager delete failed, using QB', [
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                $dqb = $db->getQueryBuilder();
                $dqb->delete('share')
                    ->where($dqb->expr()->eq('id', $dqb->createNamedParameter($shareId)))
                    ->executeStatement();
            }

            // Delete the folder node itself
            try {
                $rootFolder = $this->container->get(\OCP\Files\IRootFolder::class);
                $userFolder = $rootFolder->getUserFolder($ownerUid);
                $nodes = $userFolder->getById($fileId);
                if (!empty($nodes)) {
                    $nodes[0]->delete();
                    $this->logger->info('[ResourceService] deleteSharedFolder: folder node deleted', [
                        'fileId' => $fileId, 'app' => Application::APP_ID,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[ResourceService] deleteSharedFolder: folder node delete failed', [
                    'fileId' => $fileId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            return ['deleted' => true, 'detail' => "Files folder {$fileId} and share deleted"];

        } catch (\Throwable $e) {
            $this->logger->error('[ResourceService] deleteSharedFolder failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['deleted' => false, 'detail' => 'Operation failed — see server log for details'];
        }
    }

    /**
     * Delete the calendar shared with this team circle.
     * Uses CalDavBackend::deleteCalendar() which cascades all events.
     */
    private function deleteCalendar(string $teamId, \OCP\IDBConnection $db): array {
        try {
            // Find the calendar via dav_shares: principaluri = principals/circles/{teamId}
            $principalUri = 'principals/circles/' . $teamId;
            $qb = $db->getQueryBuilder();
            $res = $qb->select('resourceid')
                ->from('dav_shares')
                ->where($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
                ->andWhere($qb->expr()->eq('principaluri', $qb->createNamedParameter($principalUri)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if (!$row) {
                return ['deleted' => false, 'detail' => 'No calendar found for this team'];
            }

            $calendarId = (int)$row['resourceid'];

            // Delete via CalDavBackend (cascades events, attendees, alarms)
            try {
                $caldav = $this->container->get(\OCA\DAV\CalDAV\CalDavBackend::class);
                $caldav->deleteCalendar($calendarId, true);
                $this->logger->info('[ResourceService] deleteCalendar: deleted via CalDavBackend', [
                    'calendarId' => $calendarId, 'app' => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                // Fallback: delete dav_shares row and calendarobjects manually
                $this->logger->warning('[ResourceService] deleteCalendar: CalDavBackend failed, using QB', [
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                foreach (['dav_shares', 'calendarobjects', 'calendars'] as $tbl) {
                    $col = ($tbl === 'dav_shares') ? 'resourceid' : 'calendarid';
                    if ($tbl === 'calendars') {
                        $col = 'id';
                    }
                    try {
                        $dqb = $db->getQueryBuilder();
                        $dqb->delete($tbl)
                            ->where($dqb->expr()->eq($col, $dqb->createNamedParameter($calendarId)))
                            ->executeStatement();
                    } catch (\Throwable $inner) {
                        $this->logger->warning('[ResourceService] deleteCalendar QB fallback failed', [
                            'table' => $tbl, 'error' => $inner->getMessage(), 'app' => Application::APP_ID,
                        ]);
                    }
                }
            }

            return ['deleted' => true, 'detail' => "Calendar {$calendarId} deleted"];

        } catch (\Throwable $e) {
            $this->logger->error('[ResourceService] deleteCalendar failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['deleted' => false, 'detail' => 'Operation failed — see server log for details'];
        }
    }

    /**
     * Delete the Deck board shared with this team circle.
     * Cascades: deck_board_acl/deck_acl, deck_cards, deck_stacks, deck_boards.
     */
    private function deleteDeckBoard(string $teamId, \OCP\IDBConnection $db): array {
        try {
            // Find board_id via the circle ACL row (type=7 = circle)
            $boardId = null;
            foreach (['deck_board_acl', 'deck_acl'] as $aclTable) {
                try {
                    $qb = $db->getQueryBuilder();
                    $res = $qb->select('board_id')
                        ->from($aclTable)
                        ->where($qb->expr()->eq('participant', $qb->createNamedParameter($teamId)))
                        ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(7)))
                        ->setMaxResults(1)
                        ->executeQuery();
                    $row = $res->fetch();
                    $res->closeCursor();
                    if ($row) {
                        $boardId = (int)$row['board_id'];
                        break;
                    }
                } catch (\Throwable $e) {
                    // Table doesn't exist — try next
                }
            }

            if ($boardId === null) {
                return ['deleted' => false, 'detail' => 'No Deck board found for this team'];
            }

            // Delete in dependency order: cards → stacks → ACL → board
            foreach ([
                ['deck_cards',     'stack_id',  'deck_stacks', 'board_id', $boardId],
            ] as [$cardTbl, $cardCol, $stackTbl, $stackBoardCol, $bid]) {
                // Get stack IDs for this board, then delete their cards
                try {
                    $sqb = $db->getQueryBuilder();
                    $sres = $sqb->select('id')->from($stackTbl)
                        ->where($sqb->expr()->eq($stackBoardCol, $sqb->createNamedParameter($bid)))
                        ->executeQuery();
                    while ($srow = $sres->fetch()) {
                        $stackId = (int)$srow['id'];
                        $cqb = $db->getQueryBuilder();
                        $cqb->delete($cardTbl)
                            ->where($cqb->expr()->eq($cardCol, $cqb->createNamedParameter($stackId)))
                            ->executeStatement();
                    }
                    $sres->closeCursor();
                } catch (\Throwable $e) {
                    $this->logger->warning('[ResourceService] deleteDeckBoard: card delete failed', [
                        'error' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }
            }

            // Delete stacks
            foreach (['deck_stacks'] as $tbl) {
                try {
                    $dqb = $db->getQueryBuilder();
                    $dqb->delete($tbl)
                        ->where($dqb->expr()->eq('board_id', $dqb->createNamedParameter($boardId)))
                        ->executeStatement();
                } catch (\Throwable $e) {
                    $this->logger->warning('[ResourceService] deleteDeckBoard: stack delete failed', [
                        'table' => $tbl, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }
            }

            // Delete ACL rows
            foreach (['deck_board_acl', 'deck_acl'] as $aclTable) {
                try {
                    $aqb = $db->getQueryBuilder();
                    $aqb->delete($aclTable)
                        ->where($aqb->expr()->eq('board_id', $aqb->createNamedParameter($boardId)))
                        ->executeStatement();
                } catch (\Throwable $e) {
                    // Table may not exist — not an error
                }
            }

            // Delete the board itself
            $bqb = $db->getQueryBuilder();
            $bqb->delete('deck_boards')
                ->where($bqb->expr()->eq('id', $bqb->createNamedParameter($boardId)))
                ->executeStatement();

            $this->logger->info('[ResourceService] deleteDeckBoard: deleted', [
                'teamId' => $teamId, 'boardId' => $boardId, 'app' => Application::APP_ID,
            ]);
            return ['deleted' => true, 'detail' => "Deck board {$boardId} deleted"];

        } catch (\Throwable $e) {
            $this->logger->error('[ResourceService] deleteDeckBoard failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['deleted' => false, 'detail' => 'Operation failed — see server log for details'];
        }
    }

    /**
     * Create an Intravox page for this team using the Intravox REST API.
     *
     * Mirrors the CreateTeamView.vue logic exactly:
     *   1. GET /apps/intravox/api/templates  — find a usable template
     *   2. POST /apps/intravox/api/pages/from-template  — create the page
     *
     * Falls back to creating a blank page if no template matches.
     * Returns ['page_created' => true] on success, ['error' => ...] on failure.
     */
    private function createIntravoxPage(string $teamId, string $teamName, string $uid): array {
        $this->logger->debug('[ResourceService] createIntravoxPage start', [
            'teamId' => $teamId, 'teamName' => $teamName, 'app' => Application::APP_ID,
        ]);

        if (!$this->appManager->isInstalled('intravox')) {
            return ['error' => 'Intravox app not installed'];
        }

        try {
            $client  = $this->container->get(\OCP\Http\Client\IClientService::class)->newClient();
            $baseUrl = rtrim(\OC::$server->getURLGenerator()->getAbsoluteURL('/'), '/');

            // 1. Fetch available templates
            $templateId = null;
            try {
                $resp      = $client->get($baseUrl . '/apps/intravox/api/templates', [
                    'headers' => ['OCS-APIREQUEST' => 'true'],
                ]);
                $templates = json_decode((string)$resp->getBody(), true);
                $tplList   = is_array($templates) ? $templates : ($templates['templates'] ?? []);

                // Prefer 'knowledge-base', then first available template
                foreach (['knowledge-base', 'project', 'department'] as $preferred) {
                    foreach ($tplList as $tpl) {
                        if (($tpl['id'] ?? '') === $preferred || ($tpl['slug'] ?? '') === $preferred) {
                            $templateId = $tpl['id'];
                            break 2;
                        }
                    }
                }
                if ($templateId === null && !empty($tplList)) {
                    $templateId = $tplList[0]['id'] ?? null;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[ResourceService] createIntravoxPage: templates fetch failed', [
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            // 2. Create page from template (or blank)
            $payload = ['pageTitle' => $teamName];
            if ($templateId !== null) {
                $payload['templateId'] = $templateId;
                $endpoint = $baseUrl . '/apps/intravox/api/pages/from-template';
            } else {
                $endpoint = $baseUrl . '/apps/intravox/api/pages';
            }

            $resp = $client->post($endpoint, [
                'headers' => [
                    'Content-Type'    => 'application/json',
                    'OCS-APIREQUEST'  => 'true',
                ],
                'body' => json_encode($payload),
            ]);

            $body = json_decode((string)$resp->getBody(), true);

            $this->logger->info('[ResourceService] createIntravoxPage: page created', [
                'teamId'     => $teamId,
                'templateId' => $templateId,
                'pageId'     => $body['id'] ?? 'unknown',
                'app'        => Application::APP_ID,
            ]);

            return ['page_created' => true, 'page_id' => $body['id'] ?? null, 'template_id' => $templateId];

        } catch (\Throwable $e) {
            $this->logger->error('[ResourceService] createIntravoxPage failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['error' => 'Intravox page creation failed: ' . $e->getMessage()];
        }
    }

    /**
     * Delete the Intravox page created for this team.
     *
     * Strategy:
     *   1. GET /apps/intravox/api/pages  — find page whose title matches the team name
     *   2. DELETE /apps/intravox/api/pages/{id}  — remove it
     *
     * If no matching page is found, returns success (nothing to delete).
     */
    private function deleteIntravoxAccess(string $teamId, \OCP\IDBConnection $db): array {
        $this->logger->debug('[ResourceService] deleteIntravoxAccess start', [
            'teamId' => $teamId, 'app' => Application::APP_ID,
        ]);

        if (!$this->appManager->isInstalled('intravox')) {
            return ['deleted' => false, 'detail' => 'Intravox not installed'];
        }

        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return ['deleted' => false, 'detail' => 'User not authenticated'];
            }

            $client  = $this->container->get(\OCP\Http\Client\IClientService::class)->newClient();
            $baseUrl = rtrim(\OC::$server->getURLGenerator()->getAbsoluteURL('/'), '/');

            // 1. List all pages to find the team's page by title
            $pageId = null;
            try {
                $resp    = $client->get($baseUrl . '/apps/intravox/api/pages', [
                    'headers' => ['OCS-APIREQUEST' => 'true'],
                ]);
                $pages   = json_decode((string)$resp->getBody(), true);
                $pageList = is_array($pages) ? $pages : ($pages['pages'] ?? []);

                // Find a page whose title matches the team ID stored in metadata or whose title == teamId
                foreach ($pageList as $page) {
                    $meta = $page['meta'] ?? [];
                    if (
                        ($meta['teamId'] ?? '') === $teamId ||
                        ($page['teamId'] ?? '') === $teamId
                    ) {
                        $pageId = $page['id'];
                        break;
                    }
                }

                $this->logger->debug('[ResourceService] deleteIntravoxAccess: page search complete', [
                    'teamId' => $teamId, 'pageId' => $pageId, 'app' => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('[ResourceService] deleteIntravoxAccess: page list failed', [
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            if ($pageId === null) {
                $this->logger->info('[ResourceService] deleteIntravoxAccess: no page found for team, nothing to delete', [
                    'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
                return ['deleted' => true, 'detail' => 'No Intravox page found for this team'];
            }

            // 2. Delete the page
            $client->delete($baseUrl . '/apps/intravox/api/pages/' . $pageId, [
                'headers' => ['OCS-APIREQUEST' => 'true'],
            ]);

            $this->logger->info('[ResourceService] deleteIntravoxAccess: page deleted', [
                'teamId' => $teamId, 'pageId' => $pageId, 'app' => Application::APP_ID,
            ]);
            return ['deleted' => true, 'detail' => "Intravox page {$pageId} deleted"];

        } catch (\Throwable $e) {
            $this->logger->error('[ResourceService] deleteIntravoxAccess failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['deleted' => false, 'detail' => 'Operation failed — see server log for details'];
        }
    }



    /**
     * Create a Talk group room and add the circle as participant.
     *
     * Strategy (in order):
     *   1. Talk RoomService PHP API — cleanest, no HTTP involved.
     *   2. Talk Manager PHP API    — older Talk versions.
     *   3. Direct DB insert        — version-stable last resort; never triggers
     *                                Nextcloud local-access-rules blocks.
     *
     * The previous implementation used a loopback HTTP call to the OCS API which
     * fails on NC28+ when the server resolves to 127.0.0.1 / a private IP, because
     * Nextcloud blocks outgoing requests to local addresses by default.
     */
    private function createTalkRoom(string $teamId, string $teamName, string $uid): array {
        $this->logger->debug('[ResourceService] createTalkRoom start', [
            'teamId'   => $teamId,
            'teamName' => $teamName,
            'uid'      => $uid,
            'app'      => Application::APP_ID,
        ]);

        if (!$this->appManager->isInstalled('spreed')) {
            return ['error' => 'Talk (spreed) app not installed'];
        }

        // ── Strategy 1: Talk RoomService (Talk 17+) ───────────────────────────
        try {
            $roomService = $this->container->get(\OCA\Talk\Service\RoomService::class);
            $userManager = $this->container->get(\OCP\IUserManager::class);
            $user        = $userManager->get($uid);
            if (!$user) {
                throw new \Exception("User {$uid} not found");
            }

            // createConversation(type, name, actor): type 2 = TYPE_GROUP
            $room = $roomService->createConversation(2, $teamName, $user);

            $token = $room->getToken();

            // Resolve room integer ID — needed for attendee insert and moderator promotion
            $db = $this->container->get(\OCP\IDBConnection::class);
            $idQb = $db->getQueryBuilder();
            $idRes = $idQb->select('id')->from('talk_rooms')
                ->where($idQb->expr()->eq('token', $idQb->createNamedParameter($token)))
                ->setMaxResults(1)->executeQuery();
            $idRow = $idRes->fetch();
            $idRes->closeCursor();
            $roomId = $idRow ? (int)$idRow['id'] : null;

            // Add the circle via ParticipantService (Talk default: participant_type=3/PARTICIPANT).
            // When this fails — common on some Talk versions — fall back to a direct DB insert
            // so the circle attendee row always exists before we promote it to MODERATOR.
            $circleLinked = false;
            try {
                $participantService = $this->container->get(\OCA\Talk\Service\ParticipantService::class);
                $participantService->addCircle($room, $teamId);
                $circleLinked = true;
                $this->logger->debug('[ResourceService] Talk S1: circle added via ParticipantService', [
                    'app' => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('[ResourceService] Talk S1: ParticipantService::addCircle failed — using direct DB fallback', [
                    'error' => $e->getMessage(),
                    'app'   => Application::APP_ID,
                ]);
            }

            if (!$circleLinked && $roomId !== null) {
                $circleLinked = $this->insertTalkCircleAttendee($roomId, $teamId, $teamName, $db);
            }

            if ($roomId !== null && $circleLinked) {
                $this->promoteTalkCircleToModerator($roomId, $teamId, $db);
            }

            $this->logger->info('[ResourceService] Talk S1: room created via RoomService', [
                'token'        => $token,
                'circleLinked' => $circleLinked,
                'app'          => Application::APP_ID,
            ]);
            return ['token' => $token, 'name' => $teamName, 'circle_added' => $circleLinked];

        } catch (\Throwable $e) {
            $this->logger->debug('[ResourceService] Talk: RoomService strategy failed, trying Manager', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
        }

        // ── Strategy 2: Talk Manager (Talk 13–16) ─────────────────────────────
        try {
            $manager = $this->container->get(\OCA\Talk\Manager::class);
            // createRoom(type, name): type 2 = TYPE_GROUP
            $room  = $manager->createRoom(2, $teamName);
            $token = $room->getToken();

            // Resolve room integer ID first so we can insert the attendee if needed
            $db = $this->container->get(\OCP\IDBConnection::class);
            $idQb = $db->getQueryBuilder();
            $idRes = $idQb->select('id')->from('talk_rooms')
                ->where($idQb->expr()->eq('token', $idQb->createNamedParameter($token)))
                ->setMaxResults(1)->executeQuery();
            $idRow = $idRes->fetch();
            $idRes->closeCursor();
            $roomId = $idRow ? (int)$idRow['id'] : null;

            // Add circle via ParticipantService; fall back to direct DB insert on failure
            $circleLinked = false;
            try {
                $participantService = $this->container->get(\OCA\Talk\Service\ParticipantService::class);
                $participantService->addCircle($room, $teamId);
                $circleLinked = true;
                $this->logger->debug('[ResourceService] Talk S2: circle added via ParticipantService', [
                    'app' => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('[ResourceService] Talk S2: Manager addCircle failed — using direct DB fallback', [
                    'error' => $e->getMessage(),
                    'app'   => Application::APP_ID,
                ]);
            }

            if (!$circleLinked && $roomId !== null) {
                $circleLinked = $this->insertTalkCircleAttendee($roomId, $teamId, $teamName, $db);
            }

            if ($roomId !== null && $circleLinked) {
                $this->promoteTalkCircleToModerator($roomId, $teamId, $db);
            }

            $this->logger->info('[ResourceService] Talk: room created via Manager', [
                'token' => $token,
                'app'   => Application::APP_ID,
            ]);
            return ['token' => $token, 'name' => $teamName, 'circle_added' => true];

        } catch (\Throwable $e) {
            $this->logger->debug('[ResourceService] Talk: Manager strategy failed, trying direct DB', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
        }

        // ── Strategy 3: Direct DB insert ──────────────────────────────────────
        // Mirrors exactly what Talk does internally. Safe because we only write
        // to talk_rooms and talk_attendees — the same tables we read in getTeamResources().
        try {
            $db           = $this->container->get(\OCP\IDBConnection::class);
            $secureRandom = $this->container->get(\OCP\Security\ISecureRandom::class);
            $token        = $secureRandom->generate(
                32,
                \OCP\Security\ISecureRandom::CHAR_HUMAN_READABLE
            );
            $now = time();

            // Insert room — detect column set for cross-version compatibility
            $roomCols = $this->getTableColumns('talk_rooms');
            $qb = $db->getQueryBuilder();
            $qb->insert('talk_rooms')
               ->setValue('token',      $qb->createNamedParameter($token))
               ->setValue('name',       $qb->createNamedParameter($teamName))
               ->setValue('type',       $qb->createNamedParameter(2));       // TYPE_GROUP

            foreach ([
                'read_only'        => 0,
                'listable'         => 0,
                'active_guests'    => 0,
                'active_since'     => null,
                'last_activity'    => $now,
                'last_message'     => 0,
                'assigned_hpb'     => '',
                'remote_server'    => '',
                'remote_token'     => '',
                'sip_enabled'      => 0,
                'permissions'      => 0,
                'default_permissions' => 0,
                'call_permissions' => 0,
                'call_flag'        => 0,
                'breakout_room_mode'  => 0,
                'breakout_room_status' => 0,
                'lobby_state'      => 0,
                'lobby_timer'      => null,
                'mention_permissions' => 0,
                'object_type'      => '',
                'object_id'        => '',
            ] as $col => $val) {
                if (in_array($col, $roomCols, true)) {
                    $qb->setValue($col, $qb->createNamedParameter($val));
                }
            }
            $qb->executeStatement();

            // Resolve the new room's integer ID
            $roomQb = $db->getQueryBuilder();
            $roomResult = $roomQb->select('id')
                ->from('talk_rooms')
                ->where($roomQb->expr()->eq('token', $roomQb->createNamedParameter($token)))
                ->setMaxResults(1)
                ->executeQuery();
            $roomRow = $roomResult->fetch();
            $roomResult->closeCursor();

            if (!$roomRow) {
                throw new \Exception('Inserted room not found after insert');
            }
            $roomId = (int)$roomRow['id'];

            // Insert circle attendee as MODERATOR (participant_type=2).
            // OWNER (1) is reserved for the human creator; circles should be MODERATOR
            // so all team members inherit moderation rights when they join via the circle.
            $attendeeCols = $this->getTableColumns('talk_attendees');
            $aqb = $db->getQueryBuilder();
            $aqb->insert('talk_attendees')
                ->setValue('room_id',          $aqb->createNamedParameter($roomId))
                ->setValue('actor_type',       $aqb->createNamedParameter('circles'))
                ->setValue('actor_id',         $aqb->createNamedParameter($teamId))
                ->setValue('display_name',     $aqb->createNamedParameter($teamName))
                ->setValue('participant_type', $aqb->createNamedParameter(2));  // MODERATOR

            foreach ([
                'favorite'               => 0,
                'notification_level'     => 0,
                'notification_calls'     => 0,
                'last_joined_call'       => 0,
                'last_read_message'      => 0,
                'last_mention_message'   => 0,
                'last_mention_direct'    => 0,
                'in_call'                => 0,
                'permissions'            => 0,
                'publishing_permissions' => 0,
                'access_token'           => '',
                'remote_id'              => '',
                'phone_number'           => '',
                'phone_states'           => '',
            ] as $col => $val) {
                if (in_array($col, $attendeeCols, true)) {
                    $aqb->setValue($col, $aqb->createNamedParameter($val));
                }
            }
            $aqb->executeStatement();

            $this->logger->info('[ResourceService] Talk: room created via direct DB insert', [
                'token'  => $token,
                'roomId' => $roomId,
                'app'    => Application::APP_ID,
            ]);
            return ['token' => $token, 'name' => $teamName, 'circle_added' => true];

        } catch (\Throwable $e) {
            $this->logger->error('[ResourceService] Talk: all strategies failed', [
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 800),
                'app'   => Application::APP_ID,
            ]);
            return ['error' => 'Talk room creation failed: ' . $e->getMessage()];
        }
    }

    /**
     * Promote a circle attendee in a Talk room to MODERATOR (participant_type=2).
     *
     * Called after addCircle() in Strategies 1 & 2, which inserts the circle
     * with Talk's default participant_type=3 (PARTICIPANT). Without this step,
     * circle members join the room but have no moderation rights — they cannot
     * rename the room, add participants, or change settings.
     *
     * participant_type values:
     *   1 = OWNER      (reserved for the human who created the room)
     *   2 = MODERATOR  (correct for a shared circle — all members inherit rights)
     *   3 = PARTICIPANT (Talk default for addCircle — too low)
     *
     * Direct DB UPDATE is intentional: there is no cross-version Talk API for
     * setting participant_type on a circle attendee without triggering
     * participant-resolved individual rows.
     */

    /**
     * Insert a circle attendee row directly into talk_attendees.
     *
     * Used as a fallback when ParticipantService::addCircle() fails (Strategy 1 & 2).
     * Inserts with participant_type=3 (PARTICIPANT) — promoteTalkCircleToModerator()
     * upgrades it to MODERATOR immediately after.
     *
     * @return bool True when the row was inserted successfully.
     */
    private function insertTalkCircleAttendee(int $roomId, string $teamId, string $teamName, \OCP\IDBConnection $db): bool {
        try {
            // Skip if a circle attendee already exists for this room (idempotent)
            $checkQb = $db->getQueryBuilder();
            $checkRes = $checkQb->select('id')
                ->from('talk_attendees')
                ->where($checkQb->expr()->eq('room_id',    $checkQb->createNamedParameter($roomId)))
                ->andWhere($checkQb->expr()->eq('actor_type', $checkQb->createNamedParameter('circles')))
                ->andWhere($checkQb->expr()->eq('actor_id',   $checkQb->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $existing = $checkRes->fetch();
            $checkRes->closeCursor();

            if ($existing) {
                $this->logger->debug('[ResourceService] insertTalkCircleAttendee: row already exists', [
                    'roomId' => $roomId, 'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
                return true;
            }

            $attendeeCols = $this->getTableColumns('talk_attendees');
            $aqb = $db->getQueryBuilder();
            $aqb->insert('talk_attendees')
                ->setValue('room_id',          $aqb->createNamedParameter($roomId))
                ->setValue('actor_type',       $aqb->createNamedParameter('circles'))
                ->setValue('actor_id',         $aqb->createNamedParameter($teamId))
                ->setValue('display_name',     $aqb->createNamedParameter($teamName))
                ->setValue('participant_type', $aqb->createNamedParameter(3)); // PARTICIPANT — promoted to MODERATOR next

            foreach ([
                'favorite'               => 0,
                'notification_level'     => 0,
                'notification_calls'     => 0,
                'last_joined_call'       => 0,
                'last_read_message'      => 0,
                'last_mention_message'   => 0,
                'last_mention_direct'    => 0,
                'in_call'                => 0,
                'permissions'            => 0,
                'publishing_permissions' => 0,
                'access_token'           => '',
                'remote_id'              => '',
                'phone_number'           => '',
                'phone_states'           => '',
            ] as $col => $val) {
                if (in_array($col, $attendeeCols, true)) {
                    $aqb->setValue($col, $aqb->createNamedParameter($val));
                }
            }
            $aqb->executeStatement();

            $this->logger->info('[ResourceService] insertTalkCircleAttendee: row inserted', [
                'roomId' => $roomId, 'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
            return true;

        } catch (\Throwable $e) {
            $this->logger->error('[ResourceService] insertTalkCircleAttendee failed', [
                'roomId' => $roomId, 'teamId' => $teamId,
                'error'  => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    private function promoteTalkCircleToModerator(int $roomId, string $teamId, \OCP\IDBConnection $db): void {
        try {
            $uqb = $db->getQueryBuilder();
            $affected = $uqb->update('talk_attendees')
                ->set('participant_type', $uqb->createNamedParameter(2)) // MODERATOR
                ->where($uqb->expr()->eq('room_id',    $uqb->createNamedParameter($roomId)))
                ->andWhere($uqb->expr()->eq('actor_type', $uqb->createNamedParameter('circles')))
                ->andWhere($uqb->expr()->eq('actor_id',   $uqb->createNamedParameter($teamId)))
                ->executeStatement();

            $this->logger->info('[ResourceService] Talk: circle promoted to moderator', [
                'roomId'   => $roomId,
                'teamId'   => $teamId,
                'affected' => $affected,
                'app'      => Application::APP_ID,
            ]);
        } catch (\Throwable $e) {
            // Non-fatal: room still works, but circle members won't have mod rights
            $this->logger->warning('[ResourceService] Talk: promoteTalkCircleToModerator failed', [
                'roomId' => $roomId,
                'teamId' => $teamId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
        }
    }

    /**
     * Create a folder in the user's files and share it with the circle.
     */
    private function createSharedFolder(string $teamId, string $teamName, string $uid): array {
        $this->logger->debug('[ResourceService] createSharedFolder', [
            'teamId'   => $teamId,
            'teamName' => $teamName,
            'uid'      => $uid,
            'app'      => Application::APP_ID,
        ]);

        $userFolder = $this->container->get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
        $folderName = $teamName;
        $counter    = 1;
        while ($userFolder->nodeExists($folderName)) {
            $folderName = $teamName . ' (' . $counter++ . ')';
        }
        $folder = $userFolder->newFolder($folderName);

        $shareManager = $this->container->get(\OCP\Share\IManager::class);
        $share = $shareManager->newShare();
        $share->setShareType(7) // IShare::TYPE_CIRCLE
              ->setSharedWith($teamId)
              ->setSharedBy($uid)
              ->setNode($folder)
              ->setPermissions(\OCP\Constants::PERMISSION_ALL);
        $share = $shareManager->createShare($share);

        $this->logger->info('[ResourceService] createSharedFolder done', [
            'teamId'   => $teamId,
            'folderId' => $folder->getId(),
            'app'      => Application::APP_ID,
        ]);

        return ['folder_id' => $folder->getId(), 'path' => $folder->getPath(), 'share_id' => $share->getId()];
    }

    /**
     * Create a calendar and share it with the circle via the dav_shares table.
     * The circle principal is principals/circles/{teamId}.
     */
    private function createCalendar(string $teamId, string $teamName, string $uid): array {
        $this->logger->debug('[ResourceService] createCalendar', [
            'teamId'   => $teamId,
            'teamName' => $teamName,
            'uid'      => $uid,
            'app'      => Application::APP_ID,
        ]);

        if (!$this->appManager->isInstalled('calendar')) {
            return ['error' => 'Calendar app not installed'];
        }

        $caldav       = $this->container->get(\OCA\DAV\CalDAV\CalDavBackend::class);
        $principalUri = 'principals/users/' . $uid;
        $calendarUri  = strtolower(preg_replace('/[^a-z0-9]+/', '-', $teamName))
                       . '-' . substr(md5(uniqid()), 0, 6);

        $calendarId = $caldav->createCalendar($principalUri, $calendarUri, [
            '{DAV:}displayname'                        => $teamName,
            '{http://apple.com/ns/ical/}calendar-color' => '#0082c9',
        ]);

        $db = $this->container->get(\OCP\IDBConnection::class);

        // Share with the circle via dav_shares (read-write, access=2)
        $circlePublicUri = 'teamhub-' . substr($teamId, 0, 8) . '-' . $calendarId;
        $db->insertIfNotExist('*PREFIX*dav_shares', [
            'principaluri' => 'principals/circles/' . $teamId,
            'type'         => 'calendar',
            'access'       => 2,   // 2 = read-write
            'resourceid'   => (int)$calendarId,
            'publicuri'    => $circlePublicUri,
        ], ['principaluri', 'resourceid']);

        // Create a public share so the calendar can be embedded via /apps/calendar/p/{token}
        // NC calendar public shares use a random token as publicuri with access=4
        $publicToken = bin2hex(random_bytes(16)); // 32-char hex token
        $db->insertIfNotExist('*PREFIX*dav_shares', [
            'principaluri' => 'principals/users/' . $uid,
            'type'         => 'calendar',
            'access'       => 4,   // 4 = public read-only
            'resourceid'   => (int)$calendarId,
            'publicuri'    => $publicToken,
        ], ['publicuri']);

        $this->logger->info('[ResourceService] createCalendar done', [
            'teamId'     => $teamId,
            'calendarId' => $calendarId,
            'app'        => Application::APP_ID,
        ]);

        return ['calendar_id' => $calendarId, 'name' => $teamName, 'public_token' => $publicToken];
    }

    /**
     * Create a Deck board and share it with the circle.
     *
     * All DB writes use the QueryBuilder (IDBConnection::insert() doesn't exist in NC32 —
     * only QueryBuilder and executeStatement are available on ConnectionAdapter).
     *
     * ACL type 7 = circle, per official Deck API docs.
     */
    private function createDeckBoard(string $teamId, string $teamName, string $uid): array {
        $this->logger->debug('[ResourceService] createDeckBoard', [
            'teamId'   => $teamId,
            'teamName' => $teamName,
            'uid'      => $uid,
            'app'      => Application::APP_ID,
        ]);

        if (!$this->appManager->isInstalled('deck')) {
            return ['error' => 'Deck app not installed'];
        }

        $db = $this->container->get(\OCP\IDBConnection::class);

        // ── Create board via Deck's ORM (preferred) ───────────────────────────
        $boardId = null;
        try {
            $boardMapper = $this->container->get(\OCA\Deck\Db\BoardMapper::class);
            $board = new \OCA\Deck\Db\Board();
            $board->setTitle($teamName);
            $board->setOwner($uid);
            $board->setColor('0082c9');
            $board   = $boardMapper->insert($board);
            $boardId = $board->getId();
        } catch (\Throwable $e) {
            $this->logger->warning('[ResourceService] Deck BoardMapper failed, using QB fallback', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
        }

        // ── Fallback: QB insert into deck_boards ──────────────────────────────
        if ($boardId === null) {
            $boardCols = $this->getTableColumns('deck_boards');
            $qb = $db->getQueryBuilder();
            $qb->insert('deck_boards')->values([
                'title' => $qb->createNamedParameter($teamName),
                'owner' => $qb->createNamedParameter($uid),
                'color' => $qb->createNamedParameter('0082c9'),
            ]);
            foreach (['archived' => 0, 'deleted_at' => 0, 'last_modified' => time(), 'settings' => ''] as $col => $val) {
                if (in_array($col, $boardCols, true)) {
                    $qb->setValue($col, $qb->createNamedParameter($val));
                }
            }
            $qb->executeStatement();
            $boardId = (int)$db->lastInsertId();
        }

        // ── Create default stacks via QB ──────────────────────────────────────
        $stackCols = $this->getTableColumns('deck_stacks');
        foreach (['To do', 'In progress', 'Done'] as $idx => $stackTitle) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->insert('deck_stacks')
                   ->setValue('title',    $qb->createNamedParameter($stackTitle))
                   ->setValue('board_id', $qb->createNamedParameter($boardId));
                foreach (['order' => $idx, 'deleted_at' => 0, 'last_modified' => time()] as $col => $val) {
                    if (in_array($col, $stackCols, true)) {
                        $qb->setValue($col, $qb->createNamedParameter($val));
                    }
                }
                $qb->executeStatement();
            } catch (\Throwable $e) {
                $this->logger->warning('[ResourceService] Deck stack insert failed', [
                    'stack' => $stackTitle,
                    'error' => $e->getMessage(),
                    'app'   => Application::APP_ID,
                ]);
            }
        }

        // ── Share board with circle via Deck's AclMapper (preferred) ─────────
        // type 7 = circle (0=user, 1=group, 7=circle per Deck API docs)
        $circleAdded = false;
        try {
            $aclMapper = $this->container->get(\OCA\Deck\Db\AclMapper::class);
            $acl = new \OCA\Deck\Db\Acl();
            $acl->setBoardId($boardId);
            $acl->setType(7);
            $acl->setParticipant($teamId);

            // Deck 1.x used separate boolean setters; Deck 2.x (NC33+) uses a bitmask.
            // Try both — setters that don't exist will throw, caught below.
            if (method_exists($acl, 'setPermissionRead')) {
                $acl->setPermissionRead(true);
                $acl->setPermissionEdit(true);
                $acl->setPermissionManage(false);
            }
            if (method_exists($acl, 'setPermissions')) {
                // Deck 2.x bitmask: read=1, edit=2, manage=4 → 3 = read+edit
                $acl->setPermissions(3);
            }

            $aclMapper->insert($acl);
            $circleAdded = true;
            $this->logger->info('[ResourceService] Deck: circle ACL added via AclMapper', [
                'boardId' => $boardId,
                'teamId'  => $teamId,
                'app'     => Application::APP_ID,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[ResourceService] Deck AclMapper failed, trying PermissionService', [
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
                'app'   => Application::APP_ID,
            ]);
        }

        // ── Strategy 2: Deck PermissionService (Deck 2.x / NC33) ─────────────
        // Deck 2.x introduced PermissionService / BoardService::addAcl() as the
        // canonical way to share boards.
        if (!$circleAdded) {
            try {
                // Try BoardService::addAcl if available (Deck 2.x)
                $boardService = $this->container->get(\OCA\Deck\Service\BoardService::class);
                if (method_exists($boardService, 'addAcl')) {
                    $boardService->addAcl($boardId, 7, $teamId, true, true, false);
                    $circleAdded = true;
                    $this->logger->info('[ResourceService] Deck: circle ACL added via BoardService::addAcl', [
                        'boardId' => $boardId,
                        'teamId'  => $teamId,
                        'app'     => Application::APP_ID,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[ResourceService] Deck BoardService::addAcl failed, trying QB fallback', [
                    'error' => $e->getMessage(),
                    'trace' => substr($e->getTraceAsString(), 0, 500),
                    'app'   => Application::APP_ID,
                ]);
            }
        }

        // ── Strategy 3: QB insert into deck_board_acl / deck_acl ─────────────
        // Handles both Deck 1.x (boolean permission columns) and
        // Deck 2.x (single `permissions` bitmask column, value 3 = read+edit).
        if (!$circleAdded) {
            foreach (['deck_board_acl', 'deck_acl'] as $aclTable) {
                $aclCols = $this->getTableColumns($aclTable);
                if (empty($aclCols)) {
                    continue;
                }

                try {
                    $qb = $db->getQueryBuilder();
                    $qb->insert($aclTable)
                       ->setValue('board_id',    $qb->createNamedParameter($boardId))
                       ->setValue('type',        $qb->createNamedParameter(7))
                       ->setValue('participant', $qb->createNamedParameter($teamId));

                    // Deck 1.x: separate boolean columns
                    foreach (['permission_read' => 1, 'permission_edit' => 1, 'permission_manage' => 0] as $col => $val) {
                        if (in_array($col, $aclCols, true)) {
                            $qb->setValue($col, $qb->createNamedParameter($val));
                        }
                    }

                    // Deck 2.x: single bitmask column (read=1, edit=2 → 3)
                    if (in_array('permissions', $aclCols, true)) {
                        $qb->setValue('permissions', $qb->createNamedParameter(3));
                    }

                    $qb->executeStatement();
                    $circleAdded = true;
                    $this->logger->info('[ResourceService] Deck: circle ACL added via QB', [
                        'table'   => $aclTable,
                        'boardId' => $boardId,
                        'teamId'  => $teamId,
                        'app'     => Application::APP_ID,
                    ]);
                    break;
                } catch (\Throwable $e) {
                    $this->logger->warning('[ResourceService] Deck ACL QB insert failed', [
                        'table' => $aclTable,
                        'error' => $e->getMessage(),
                        'trace' => substr($e->getTraceAsString(), 0, 500),
                        'app'   => Application::APP_ID,
                    ]);
                }
            }
        }

        if (!$circleAdded) {
            $this->logger->error('[ResourceService] Deck: all circle ACL strategies failed', [
                'boardId' => $boardId,
                'teamId'  => $teamId,
                'app'     => Application::APP_ID,
            ]);
        }

        $this->logger->info('[ResourceService] createDeckBoard done', [
            'teamId'      => $teamId,
            'boardId'     => $boardId,
            'circleAdded' => $circleAdded,
            'app'         => Application::APP_ID,
        ]);

        return ['board_id' => $boardId, 'name' => $teamName, 'circle_added' => $circleAdded];
    }

    // -------------------------------------------------------------------------
    // DB schema introspection utility
    // -------------------------------------------------------------------------

    /**
     * Return the column names for an un-prefixed table name.
     *
     * Used internally (Deck QB fallbacks) and by MemberService (requestJoinTeam,
     * repairCircleMembership). Three strategies in descending preference:
     *
     *   1. DBAL SchemaManager via inner Doctrine connection (NC32 compatible)
     *   2. SELECT * LIMIT 1 — column names from result set metadata
     *   3. INFORMATION_SCHEMA query (MySQL/MariaDB/PostgreSQL fallback)
     *
     * Returns an empty array when the table doesn't exist — callers skip
     * optional columns gracefully.
     *
     * @param string $table Un-prefixed table name, e.g. 'circles_member'
     * @return string[]
     */
    public function getTableColumns(string $table): array {
        $db = $this->container->get(\OCP\IDBConnection::class);

        // ── Strategy 1: DBAL SchemaManager via inner connection ───────────────
        try {
            $inner = method_exists($db, 'getInner') ? $db->getInner() : null;
            if ($inner instanceof \Doctrine\DBAL\Connection) {
                $sm = $inner->createSchemaManager();
                // Resolve the actual prefixed table name using the QB (safe, platform-agnostic)
                $qb      = $db->getQueryBuilder();
                $testSql = $qb->select('*')->from($table)->setMaxResults(0)->getSQL();
                // Extract: FROM `oc_talk_rooms` or FROM "oc_talk_rooms"
                if (preg_match('/FROM\s+["`]?(\S+?)["`]?\s/i', $testSql, $m)) {
                    $fullTable = trim($m[1], '"`');
                    $columns   = $sm->listTableColumns($fullTable);
                    return array_keys($columns);
                }
            }
        } catch (\Throwable $e) {
            // Fall through
        }

        // ── Strategy 2: SELECT * LIMIT 1 — column names from result set ───────
        try {
            $qb     = $db->getQueryBuilder();
            $result = $qb->select('*')->from($table)->setMaxResults(1)->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();
            if (is_array($row)) {
                return array_keys($row);
            }
            // Table exists but is empty — cannot infer columns this way
        } catch (\Throwable $e) {
            // Table likely doesn't exist; fall through
        }

        // ── Strategy 3: INFORMATION_SCHEMA (MySQL/MariaDB/PostgreSQL) ─────────
        try {
            $qb        = $db->getQueryBuilder();
            $sql       = $qb->select('id')->from($table)->setMaxResults(0)->getSQL();
            $fullTable = 'UNKNOWN';
            if (preg_match('/FROM\s+["`]?(\S+?)["`]?(?:\s|$)/i', $sql, $m)) {
                $fullTable = trim($m[1], '"`');
            }
            $result = $db->executeQuery(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ?',
                [$fullTable]
            );
            $cols = [];
            while ($row = $result->fetch()) {
                $col = $row['COLUMN_NAME'] ?? $row['column_name'] ?? null;
                if ($col !== null) {
                    $cols[] = $col;
                }
            }
            $result->closeCursor();
            if (!empty($cols)) {
                return $cols;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[ResourceService] getTableColumns: all strategies failed', [
                'table' => $table,
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
        }

        return [];
    }

    /**
     * Direct DB lookup for a user's member level in a team (0 = not a member).
     * Duplicated from MemberService to avoid a circular dependency
     * (MemberService injects ResourceService).
     */
    private function getMemberLevelFromDb(\OCP\IDBConnection $db, string $teamId, string $userId): int {
        $qb     = $db->getQueryBuilder();
        $result = $qb->select('level')
            ->from('circles_member')
            ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('user_id',   $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('status',    $qb->createNamedParameter('Member')))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ? (int)$row['level'] : 0;
    }
}
