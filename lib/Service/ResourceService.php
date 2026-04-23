<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * ResourceService — team resource lookup and provisioning orchestrator.
 *
 * As of v3.2.0 this class is the orchestrator only. Creation and deletion
 * logic lives in focused sub-services:
 *   @see TalkService, FilesService, CalendarService, DeckService
 *
 * Retains: getTeamResources(), createTeamResources(), deleteTeamResource(),
 *          checkInstalledApps(), getTableColumns() (delegates), getMemberLevelFromDb()
 */
class ResourceService {

    public function __construct(
        private IUserSession $userSession,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private DbIntrospectionService $dbIntrospection,
        private TalkService $talkService,
        private FilesService $filesService,
        private CalendarService $calendarService,
        private DeckService $deckService,
        private IntravoxService $intravoxService,
    ) {}

    // -------------------------------------------------------------------------
    // Resource lookup
    // -------------------------------------------------------------------------

    public function getTeamResources(string $teamId): array {

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

        $resources = ['talk' => null, 'files' => null, 'calendar' => null, 'deck' => null, 'intravox' => false, 'tasks' => false, 'shared_files' => false];

        try {
            $db = $this->container->get(\OCP\IDBConnection::class);

            // ── IntraVox enabled flag ─────────────────────────────────────────
            // Check if the intravox app is enabled for this team in teamhub_team_apps
            if ($this->appManager->isInstalled('intravox')) {
                $ivQb  = $db->getQueryBuilder();
                $ivRes = $ivQb->select('enabled')
                    ->from('teamhub_team_apps')
                    ->where($ivQb->expr()->eq('team_id', $ivQb->createNamedParameter($teamId)))
                    ->andWhere($ivQb->expr()->eq('app_id', $ivQb->createNamedParameter('intravox')))
                    ->setMaxResults(1)
                    ->executeQuery();
                $ivRow = $ivRes->fetch();
                $ivRes->closeCursor();
                $resources['intravox'] = $ivRow ? (bool)$ivRow['enabled'] : false;
            }

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
                                'room_id' => $roomId,
                                'token'   => $roomRow['token'],
                                'name'    => $roomRow['name'],
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
            // Filter on item_type='folder' so that individual file shares
            // (e.g. from Nextcloud Notes) are never mistaken for the team folder.
            try {
                $qb = $db->getQueryBuilder();
                $result = $qb->select('file_source', 'file_target')
                    ->from('share')
                    ->where($qb->expr()->eq('share_with', $qb->createNamedParameter($teamId)))
                    ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(7)))
                    ->andWhere($qb->expr()->eq('item_type', $qb->createNamedParameter('folder')))
                    ->setMaxResults(1)
                    ->executeQuery();

                if ($row = $result->fetch()) {
                    $resources['files'] = [
                        'folder_id' => $row['file_source'],
                        'path'      => $row['file_target'],
                    ];
                }
                $result->closeCursor();
                $this->logger->debug('[TeamHub][ResourceService] Files resource resolved', [
                    'teamId'   => $teamId,
                    'resolved' => $resources['files'] !== null,
                    'app'      => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('[ResourceService] Files resource query failed', [
                    'teamId' => $teamId,
                    'error'  => $e->getMessage(),
                    'app'    => Application::APP_ID,
                ]);
            }

            // ── Shared Files toggle ───────────────────────────────────────────
            // Independent toggle — does not require a team folder to be configured.
            try {
                $sfQb  = $db->getQueryBuilder();
                $sfRes = $sfQb->select('enabled')
                    ->from('teamhub_team_apps')
                    ->where($sfQb->expr()->eq('team_id', $sfQb->createNamedParameter($teamId)))
                    ->andWhere($sfQb->expr()->eq('app_id', $sfQb->createNamedParameter('shared_files')))
                    ->setMaxResults(1)
                    ->executeQuery();
                $sfRow = $sfRes->fetch();
                $sfRes->closeCursor();
                $resources['shared_files'] = $sfRow ? (bool)$sfRow['enabled'] : false;
                $this->logger->debug('[TeamHub][ResourceService] shared_files toggle', [
                    'teamId'  => $teamId,
                    'enabled' => $resources['shared_files'],
                    'app'     => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][ResourceService] shared_files toggle query failed', [
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

        // ── Tasks app availability ────────────────────────────────────────────
        $resources['tasks'] = $this->appManager->isInstalled('tasks');


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
    /**
     * Create app resources and share them with the circle. Returns per-app results.
     * Delegates to TalkService, FilesService, CalendarService, DeckService.
     */
    public function createTeamResources(string $teamId, array $apps, string $teamName): array {
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
                        $results['talk'] = $this->talkService->createTalkRoom($teamId, $teamName, $uid);
                        break;
                    case 'files':
                        $results['files'] = $this->filesService->createSharedFolder($teamId, $teamName, $uid);
                        break;
                    case 'calendar':
                        $results['calendar'] = $this->calendarService->createCalendar($teamId, $teamName, $uid);
                        break;
                    case 'deck':
                        $results['deck'] = $this->deckService->createDeckBoard($teamId, $teamName, $uid);
                        break;
                    case 'intravox':
                        $results['intravox'] = $this->intravoxService->createPage($teamId, $teamName);
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
                    'app_id'  => Application::APP_ID,
                ]);
            }
        }
        return $results;
    }


    public function checkInstalledApps(): array {
        $config = $this->container->get(\OCP\IConfig::class);
        return [
            'talk'               => $this->appManager->isInstalled('spreed'),
            'calendar'           => $this->appManager->isInstalled('calendar'),
            'deck'               => $this->appManager->isInstalled('deck'),
            'intravox'           => $this->appManager->isInstalled('intravox'),
            'intravoxParentPath' => $config->getAppValue('teamhub', 'intravoxParentPath', 'en/teamhub'),
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
     *   intravox — find and delete the IntraVox page via PageService (in-process)
     *
     * Each app block is individually try/caught so one failure does not abort others.
     *
     * @param string $teamId  Circle single ID
     * @param string $app     'talk' | 'files' | 'calendar' | 'deck' | 'intravox'
     * @return array { deleted: bool, detail: string }
     */
    /**
     * Fully delete a team resource. Delegates to the appropriate sub-service.
     */
    public function deleteTeamResource(string $teamId, string $app): array {
        $db = $this->container->get(\OCP\IDBConnection::class);
        switch ($app) {
            case 'talk':
                return $this->talkService->deleteTalkRoom($teamId, $db);
            case 'files':
                return $this->filesService->deleteSharedFolder($teamId, $db);
            case 'calendar':
                return $this->calendarService->deleteCalendar($teamId, $db);
            case 'deck':
                return $this->deckService->deleteDeckBoard($teamId, $db);
            case 'intravox':
                return $this->intravoxService->deletePage($teamId, $this->getTeamName($teamId, $db));
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

    // -------------------------------------------------------------------------
    // DB schema introspection — delegates to DbIntrospectionService
    // -------------------------------------------------------------------------

    /**
     * Look up a team's display name from circles_circle by its unique_id.
     * Used internally to resolve the name for IntraVox page matching on delete.
     */
    private function getTeamName(string $teamId, \OCP\IDBConnection $db): string {
        try {
            $qb  = $db->getQueryBuilder();
            $res = $qb->select('name')
                ->from('circles_circle')
                ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();
            return $row ? (string)($row['name'] ?? '') : '';
        } catch (\Throwable $e) {
            $this->logger->warning('[ResourceService] getTeamName failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return '';
        }
    }



    /**
     * Return the column names for an un-prefixed table name.
     * Delegates to DbIntrospectionService which holds the static cache.
     * Kept here as a public pass-through because MemberService injects
     * ResourceService and calls this method directly.
     */
    public function getTableColumns(string $table): array {
        return $this->dbIntrospection->getTableColumns($table);
    }

    // -------------------------------------------------------------------------
    // Membership check
    // -------------------------------------------------------------------------

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
