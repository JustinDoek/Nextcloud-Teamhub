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

    // -------------------------------------------------------------------------
    // Private provisioning helpers
    // -------------------------------------------------------------------------

    /**
     * Create a Talk group room and add the circle as participant.
     *
     * Uses the Talk OCS REST API (POST /ocs/v2.php/apps/spreed/api/v4/room) so we
     * never touch Talk's DB directly — which avoids all schema/version issues.
     *
     * The `participants.teams` field adds the circle to the room at creation time.
     * We authenticate the loopback HTTP call with a single-use app token generated
     * from ISecureRandom.
     *
     * Fallback: if the OCS call fails we return the error — no silent partial state.
     */
    private function createTalkRoom(string $teamId, string $teamName, string $uid): array {
        $this->logger->debug('[ResourceService] createTalkRoom', [
            'teamId'   => $teamId,
            'teamName' => $teamName,
            'uid'      => $uid,
            'app'      => Application::APP_ID,
        ]);

        if (!$this->appManager->isInstalled('spreed')) {
            return ['error' => 'Talk (spreed) app not installed'];
        }

        try {
            $urlGenerator = $this->container->get(\OCP\IURLGenerator::class);
            $clientService = $this->container->get(\OCP\Http\Client\IClientService::class);
            $userManager   = $this->container->get(\OCP\IUserManager::class);

            $user = $userManager->get($uid);
            if (!$user) {
                throw new \Exception("User {$uid} not found");
            }

            $ocsUrl = rtrim($urlGenerator->getAbsoluteURL('/'), '/')
                    . '/ocs/v2.php/apps/spreed/api/v4/room';

            // Generate a temporary app password so we can authenticate the loopback call
            $tokenProvider = null;
            $loginToken    = null;
            try {
                $tokenProvider = $this->container->get(\OC\Authentication\Token\IProvider::class);
                $secureRandom  = $this->container->get(\OCP\Security\ISecureRandom::class);
                $loginToken    = $secureRandom->generate(72);
                $tokenProvider->generateToken(
                    $loginToken,
                    $uid,
                    $uid,
                    null,
                    'TeamHub Talk room creation',
                    \OC\Authentication\Token\IToken::PERMANENT_TOKEN
                );
            } catch (\Throwable $e) {
                // Token provider unavailable — try without auth
                $loginToken = null;
                $this->logger->warning('[ResourceService] Talk: could not generate app token, trying without auth', [
                    'error' => $e->getMessage(),
                    'app'   => Application::APP_ID,
                ]);
            }

            $client  = $clientService->newClient();
            $options = [
                'headers' => [
                    'OCS-APIRequest' => 'true',
                    'Accept'         => 'application/json',
                    'Content-Type'   => 'application/json',
                ],
                'json' => [
                    'roomType' => 2,          // TYPE_GROUP
                    'roomName' => $teamName,
                    'participants' => [
                        'teams' => [$teamId], // circle ID — adds circle with full membership
                    ],
                ],
                'verify' => false,            // loopback — skip TLS cert check
            ];

            if ($loginToken !== null) {
                $options['auth'] = [$uid, $loginToken];
            }

            $response = $client->post($ocsUrl, $options);
            $body     = json_decode($response->getBody(), true);
            $token    = $body['ocs']['data']['token'] ?? null;

            // Clean up the temporary token
            if ($loginToken !== null && $tokenProvider !== null) {
                try {
                    $tokens = $tokenProvider->getTokenByUser($uid);
                    foreach ($tokens as $t) {
                        if ($t->getName() === 'TeamHub Talk room creation') {
                            $tokenProvider->invalidateToken($loginToken);
                            break;
                        }
                    }
                } catch (\Throwable $e) { /* non-fatal */ }
            }

            if (!$token) {
                $ocsMsg = $body['ocs']['meta']['message'] ?? 'unknown error';
                throw new \Exception("OCS response missing token: {$ocsMsg}");
            }

            $this->logger->info('[ResourceService] Talk: created room via OCS API', [
                'token' => $token,
                'app'   => Application::APP_ID,
            ]);
            return ['token' => $token, 'name' => $teamName, 'circle_added' => true];

        } catch (\Throwable $e) {
            $this->logger->error('[ResourceService] Talk: OCS room creation failed', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
            return ['error' => 'Talk room creation failed: ' . $e->getMessage()];
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
            $acl->setPermissionRead(true);
            $acl->setPermissionEdit(true);
            $acl->setPermissionManage(false);
            $aclMapper->insert($acl);
            $circleAdded = true;
        } catch (\Throwable $e) {
            $this->logger->warning('[ResourceService] Deck AclMapper failed, using QB fallback', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
        }

        // ── Fallback: QB insert into deck_board_acl / deck_acl ───────────────
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
                       ->setValue('type',        $qb->createNamedParameter(7))        // 7 = circle
                       ->setValue('participant', $qb->createNamedParameter($teamId));
                    foreach (['permission_read' => 1, 'permission_edit' => 1, 'permission_manage' => 0] as $col => $val) {
                        if (in_array($col, $aclCols, true)) {
                            $qb->setValue($col, $qb->createNamedParameter($val));
                        }
                    }
                    $qb->executeStatement();
                    $circleAdded = true;
                    break;
                } catch (\Throwable $e) {
                    $this->logger->warning('[ResourceService] Deck ACL QB insert failed', [
                        'table' => $aclTable,
                        'error' => $e->getMessage(),
                        'app'   => Application::APP_ID,
                    ]);
                }
            }
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
}
