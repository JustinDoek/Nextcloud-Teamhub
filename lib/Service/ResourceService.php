<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\TeamAppResourceMapper;
use OCA\TeamHub\Service\ResourceDiscoveryService;
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

    /**
     * Curated palette for team Deck boards and Calendars.
     *
     * Criteria: medium-to-dark saturation, legible on both white card headers
     * (Deck) and white UI backgrounds (Calendar sidebar). Excludes colours that
     * are near-white, near-black, or so desaturated they look grey.
     *
     * Each entry is a 6-character hex string without '#'.  Callers that need
     * the CalDAV format (#RRGGBB) should prefix the string with '#'.
     */
    private const TEAM_COLOUR_PALETTE = [
        '0082c9', // Nextcloud blue
        '2eb52b', // green
        'e08310', // amber
        'e53935', // red
        '9c27b0', // purple
        '00897b', // teal
        '3f51b5', // indigo
        'd81b60', // raspberry
        '795548', // warm brown
        '546e7a', // steel blue-grey
        '558b2f', // olive green
        '00838f', // dark cyan
    ];

    /**
     * Pick a random colour from the shared palette.
     * Each call is independent — board and calendar can receive different colours.
     *
     * @return string 6-char hex without '#'
     */
    private static function randomTeamColour(): string {
        return self::TEAM_COLOUR_PALETTE[array_rand(self::TEAM_COLOUR_PALETTE)];
    }

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
        private TeamAppResourceMapper $resourceMapper,
        private ResourceDiscoveryService $discoveryService,
    ) {}

    // -------------------------------------------------------------------------
    // Resource lookup
    // -------------------------------------------------------------------------

    public function getTeamResources(string $teamId): array {

        // Verify the current user is actually a member of this team before
        // returning Talk tokens, file paths, or calendar IDs.
        // We check both direct membership (user_type=1 row in circles_member) AND
        // indirect membership (user is in a group/sub-team that is in the circle,
        // tracked via circles_membership). Users added via a group have no direct
        // row in circles_member for the team, so a direct-only check incorrectly
        // denies them access and hides all built-in app tabs.
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }
        $uid = $user->getUID();
        $db  = $this->container->get(\OCP\IDBConnection::class);

        $isDirectMember   = $this->getMemberLevelFromDb($db, $teamId, $uid) > 0;
        $isEffectiveMember = $isDirectMember || $this->isEffectiveTeamMember($db, $teamId, $uid);

        if (!$isEffectiveMember) {
            $this->logger->warning('[ResourceService] getTeamResources — non-member access attempt', [
                'teamId' => $teamId,
                'userId' => $uid,
                'app'    => Application::APP_ID,
            ]);
            throw new \Exception('Access denied');
        }
        $this->logger->debug('[ResourceService] getTeamResources — membership confirmed', [
            'teamId' => $teamId, 'uid' => $uid,
            'direct' => $isDirectMember, 'indirect' => !$isDirectMember,
            'app' => Application::APP_ID,
        ]);

        // ── Render-time discovery reconciliation ──────────────────────────────
        // Sync teamhub_team_app_resources against live NC ACL tables for this team.
        // Non-fatal: if it throws, page load continues with the existing DB state.
        try {
            $this->discoveryService->reconcileTeam($teamId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ResourceService] render-time reconciliation failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        $resources = ['talk' => null, 'files' => null, 'calendar' => [], 'deck' => [], 'intravox' => false, 'tasks' => false, 'shared_files' => false];

        try {
            // ── IntraVox enabled flag ─────────────────────────────────────────
            // intravox and shared_files remain toggle-driven via teamhub_team_apps.
            // They are not resource-backed and are not tracked in teamhub_team_app_resources.
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

            // ── Shared Files toggle ───────────────────────────────────────────
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

            // ── Resource-backed apps: Talk, Files, Calendar, Deck ─────────────
            // Source of truth is now teamhub_team_app_resources.
            // For backward compatibility the single-resource shape is preserved:
            // each key returns the first active resource's detail, or null if none.
            // Multi-resource UX (tab picker, per-app list) lands in Session C.

            // ── Talk ─────────────────────────────────────────────────────────
            if ($this->appManager->isInstalled('spreed')) {
                try {
                    $talkRows = $this->resourceMapper->findActiveByTeamAndApp($teamId, 'talk');
                    $this->logger->debug('[TeamHub][ResourceService] Talk resource rows from registry', [
                        'teamId' => $teamId, 'count' => count($talkRows), 'app' => Application::APP_ID,
                    ]);
                    if (!empty($talkRows)) {
                        // resource_id for Talk is the room token.
                        $token = $talkRows[0]->getResourceId();
                        $roomQb  = $db->getQueryBuilder();
                        $roomRes = $roomQb->select('id', 'token', 'name')
                            ->from('talk_rooms')
                            ->where($roomQb->expr()->eq('token', $roomQb->createNamedParameter($token)))
                            ->setMaxResults(1)
                            ->executeQuery();
                        if ($roomRow = $roomRes->fetch()) {
                            $resources['talk'] = [
                                'room_id' => (int) $roomRow['id'],
                                'token'   => $roomRow['token'],
                                'name'    => $roomRow['name'],
                            ];
                        }
                        $roomRes->closeCursor();
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('[TeamHub][ResourceService] Talk resource lookup failed', [
                        'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }
            }

            // ── Files ────────────────────────────────────────────────────────
            try {
                $filesRows = $this->resourceMapper->findActiveByTeamAndApp($teamId, 'files');
                $this->logger->debug('[TeamHub][ResourceService] Files resource rows from registry', [
                    'teamId' => $teamId, 'count' => count($filesRows), 'app' => Application::APP_ID,
                ]);
                if (!empty($filesRows)) {
                    // resource_id for Files is the file_source integer stored as string.
                    $fileSource = (int) $filesRows[0]->getResourceId();
                    // Resolve the file_target path from the share row.
                    $shareQb  = $db->getQueryBuilder();
                    $shareRes = $shareQb->select('file_source', 'file_target')
                        ->from('share')
                        ->where($shareQb->expr()->eq('file_source', $shareQb->createNamedParameter($fileSource, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->andWhere($shareQb->expr()->eq('share_with', $shareQb->createNamedParameter($teamId)))
                        ->andWhere($shareQb->expr()->eq('share_type', $shareQb->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->setMaxResults(1)
                        ->executeQuery();
                    if ($shareRow = $shareRes->fetch()) {
                        $resources['files'] = [
                            'folder_id' => (int) $shareRow['file_source'],
                            'path'      => $shareRow['file_target'],
                        ];
                    }
                    $shareRes->closeCursor();
                }
                $this->logger->debug('[TeamHub][ResourceService] Files resource resolved', [
                    'teamId'   => $teamId,
                    'resolved' => $resources['files'] !== null,
                    'app'      => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][ResourceService] Files resource lookup failed', [
                    'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            // ── Calendar ─────────────────────────────────────────────────────
            if ($this->appManager->isInstalled('calendar')) {
                try {
                    $calRows = $this->resourceMapper->findActiveByTeamAndApp($teamId, 'calendar');
                    $this->logger->debug('[TeamHub][ResourceService] Calendar resource rows from registry', [
                        'teamId' => $teamId, 'count' => count($calRows), 'app' => Application::APP_ID,
                    ]);
                    $calendars = [];
                    foreach ($calRows as $calRow) {
                        $calendarId = (int) $calRow->getResourceId();
                        $calQb  = $db->getQueryBuilder();
                        $calRes = $calQb->select('id', 'uri', 'displayname', 'principaluri')
                            ->from('calendars')
                            ->where($calQb->expr()->eq('id', $calQb->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                            ->setMaxResults(1)
                            ->executeQuery();
                        if ($calDetail = $calRes->fetch()) {
                            $calName = $calDetail['displayname'] ?? $calDetail['uri'] ?? 'Team Calendar';

                            // Resolve the public embed token (dav_shares access=4 row).
                            $publicUri = null;
                            try {
                                $psQb  = $db->getQueryBuilder();
                                $psRes = $psQb->select('publicuri')
                                    ->from('dav_shares')
                                    ->where($psQb->expr()->eq('resourceid', $psQb->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                                    ->andWhere($psQb->expr()->eq('type', $psQb->createNamedParameter('calendar')))
                                    ->andWhere($psQb->expr()->eq('access', $psQb->createNamedParameter(4, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                                    ->setMaxResults(1)
                                    ->executeQuery();
                                if ($psRow = $psRes->fetch()) {
                                    $publicUri = $psRow['publicuri'];
                                }
                                $psRes->closeCursor();
                            } catch (\Throwable $e) {
                                // Non-fatal — public token may not exist yet.
                            }

                            $calendars[] = [
                                'id'             => $calendarId,
                                'uri'            => $calDetail['uri'],
                                'name'           => $calName,
                                'ownerPrincipal' => $calDetail['principaluri'],
                                'public_token'   => $publicUri,
                            ];
                        }
                        $calRes->closeCursor();
                    }
                    // calendar is now an array. Empty array = no calendar = tab hidden.
                    $resources['calendar'] = $calendars;
                } catch (\Throwable $e) {
                    $this->logger->warning('[TeamHub][ResourceService] Calendar resource lookup failed', [
                        'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }
            }

            // ── Deck ─────────────────────────────────────────────────────────
            if ($this->appManager->isInstalled('deck')) {
                try {
                    $deckRows = $this->resourceMapper->findActiveByTeamAndApp($teamId, 'deck');
                    $this->logger->debug('[TeamHub][ResourceService] Deck resource rows from registry', [
                        'teamId' => $teamId, 'count' => count($deckRows), 'app' => Application::APP_ID,
                    ]);
                    $boards = [];
                    foreach ($deckRows as $deckRow) {
                        $boardId = (int) $deckRow->getResourceId();
                        $boardQb  = $db->getQueryBuilder();
                        $boardRes = $boardQb->select('id', 'title', 'color')
                            ->from('deck_boards')
                            ->where($boardQb->expr()->eq('id', $boardQb->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                            ->setMaxResults(1)
                            ->executeQuery();
                        if ($boardRow = $boardRes->fetch()) {
                            $boards[] = [
                                'board_id' => $boardId,
                                'name'     => $boardRow['title'],
                                'color'    => $boardRow['color'],
                            ];
                        }
                        $boardRes->closeCursor();
                    }
                    // deck is now an array. Empty array = no board = tab hidden.
                    $resources['deck'] = $boards;
                } catch (\Throwable $e) {
                    $this->logger->warning('[TeamHub][ResourceService] Deck resource lookup failed', [
                        'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }
            }

        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][ResourceService] getTeamResources failed', [
                'teamId' => $teamId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
        }

        // ── Tasks app availability ────────────────────────────────────────────
        $resources['tasks'] = $this->appManager->isInstalled('tasks');

        // ── Warning counts for the Teaminfo widget (admin-only surface) ───────
        // Includes pending (externally discovered, awaiting accept/ignore) and
        // at-risk (owner disabled/transfer failed) counts. The frontend only
        // renders the warning block for team admins (level ≥ 8).
        try {
            $resources['_warnings'] = $this->discoveryService->getWarningCounts($teamId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ResourceService] getWarningCounts failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            $resources['_warnings'] = ['pending' => 0, 'atRisk' => 0];
        }

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
    public function createTeamResources(string $teamId, array $apps, string $teamName, array $names = []): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }
        $uid = $user->getUID();

        $teamColour = self::randomTeamColour();

        $results = [];
        foreach ($apps as $app) {
            // Use per-app name if provided, fall back to teamName.
            $resourceName = isset($names[$app]) && $names[$app] !== '' ? (string)$names[$app] : $teamName;
            try {
                switch ($app) {
                    case 'talk':
                        $result = $this->talkService->createTalkRoom($teamId, $resourceName, $uid);
                        $results['talk'] = $result;
                        if (!empty($result['token'])) {
                            $this->upsertResourceRow(
                                $teamId, 'talk', (string) $result['token'], 'teamhub_create', $uid
                            );
                        }
                        break;
                    case 'files':
                        $result = $this->filesService->createSharedFolder($teamId, $resourceName, $uid);
                        $results['files'] = $result;
                        if (!empty($result['folder_id'])) {
                            $this->upsertResourceRow(
                                $teamId, 'files', (string) $result['folder_id'], 'teamhub_create', $uid
                            );
                        }
                        break;
                    case 'calendar':
                        $result = $this->calendarService->createCalendar(
                            $teamId, $resourceName, $uid, $teamColour
                        );
                        $results['calendar'] = $result;
                        if (!empty($result['calendar_id'])) {
                            $this->upsertResourceRow(
                                $teamId, 'calendar', (string) $result['calendar_id'], 'teamhub_create', $uid
                            );
                        }
                        break;
                    case 'deck':
                        $result = $this->deckService->createDeckBoard(
                            $teamId, $resourceName, $uid, $teamColour
                        );
                        $results['deck'] = $result;
                        if (!empty($result['board_id'])) {
                            $this->upsertResourceRow(
                                $teamId, 'deck', (string) $result['board_id'], 'teamhub_create', $uid
                            );
                        }
                        break;
                    case 'intravox':
                        $results['intravox'] = $this->intravoxService->createPage($teamId, $resourceName);
                        break;
                    default:
                        $results[$app] = ['error' => 'Unknown app'];
                }
            } catch (\Throwable $e) {
                $results[$app] = ['error' => $e->getMessage(), 'trace' => substr($e->getTraceAsString(), 0, 800)];
                $this->logger->error('[TeamHub][ResourceService] Failed to create resource', [
                    'teamId'  => $teamId,
                    'app'     => $app,
                    'message' => $e->getMessage(),
                    'app_id'  => Application::APP_ID,
                ]);
            }
        }
        return $results;
    }

    /**
     * Connect an existing app resource to a team. Inserts the share/ACL row
     * granting the team's circle access to a resource the user already owns.
     *
     * Mirrors createTeamResources but uses the connect-existing methods on each
     * sub-service. Returns per-call result.
     *
     * SECURITY: Authorisation (team-admin level) is enforced at the controller
     * layer. Each sub-service additionally re-verifies the user owns the
     * specified resource ID, preventing forged resource ID attacks.
     *
     * @param string $teamId     Team / circle unique ID
     * @param string $app        'talk' | 'files' | 'calendar' | 'deck'
     * @param int    $resourceId The resource ID owned by the current user
     */
    public function connectExistingResource(string $teamId, string $app, int $resourceId): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }
        $uid = $user->getUID();

        switch ($app) {
            case 'talk':
                $result = $this->talkService->connectExistingRoom($teamId, $resourceId, $uid);
                // resource_id for Talk is the room token returned by the sub-service.
                if (!empty($result['success']) && !empty($result['token'])) {
                    $this->upsertResourceRow($teamId, 'talk', (string) $result['token'], 'teamhub_connect', $uid);
                }
                return $result;

            case 'files':
                $result = $this->filesService->connectExistingFolder($teamId, $resourceId, $uid);
                if (!empty($result['success'])) {
                    $this->upsertResourceRow($teamId, 'files', (string) $resourceId, 'teamhub_connect', $uid);
                }
                return $result;

            case 'calendar':
                $result = $this->calendarService->connectExistingCalendar($teamId, $resourceId, $uid);
                if (!empty($result['success'])) {
                    $this->upsertResourceRow($teamId, 'calendar', (string) $resourceId, 'teamhub_connect', $uid);
                }
                return $result;

            case 'deck':
                $result = $this->deckService->connectExistingBoard($teamId, $resourceId, $uid);
                if (!empty($result['success'])) {
                    $this->upsertResourceRow($teamId, 'deck', (string) $resourceId, 'teamhub_connect', $uid);
                }
                return $result;

            default:
                return ['success' => false, 'error' => 'Unknown app: ' . $app];
        }
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
     * Remove team access to a specific resource (§6.2 — strip ACL, delete row).
     * The underlying resource (room, calendar, folder, board) is preserved.
     *
     * @param string $teamId      Circle unique_id
     * @param string $app         talk | files | calendar | deck
     * @param string $resourceId  Resource identifier (token for Talk, integer ID for others)
     * @return array { success: bool, error?: string }
     */
    public function removeTeamAccess(string $teamId, string $app, string $resourceId): array {
        $db  = $this->container->get(\OCP\IDBConnection::class);
        $row = $this->resourceMapper->findByTeamAppResource($teamId, $app, $resourceId);

        if ($row === null) {
            return ['success' => false, 'error' => 'Resource row not found'];
        }

        // Count how many OTHER teams also have this resource connected.
        // This is purely informational for the audit log — it does not change behaviour.
        // The remove methods already only strip this team's ACL row.
        $otherTeamCount = $this->countOtherTeamsWithResource($db, $app, $resourceId, $teamId);

        $stripped = false;
        try {
            $stripped = match ($app) {
                'talk'     => $this->talkService->removeRoomAccess($teamId, $resourceId, $db),
                'files'    => $this->filesService->removeFilesAccess($teamId, (int)$resourceId, $db),
                'calendar' => $this->calendarService->removeCalendarAccess($teamId, (int)$resourceId, $db),
                'deck'     => $this->deckService->removeBoardAccess($teamId, (int)$resourceId, $db),
                default    => false,
            };
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ResourceService] removeTeamAccess ACL strip failed', [
                'teamId' => $teamId, 'app' => $app, 'resourceId' => $resourceId,
                'error' => $e->getMessage(), 'app_id' => Application::APP_ID,
            ]);
        }

        // Delete this team's registry row.
        $this->resourceMapper->deleteById($row->getId());

        $this->logger->info('[TeamHub][ResourceService] removeTeamAccess completed', [
            'teamId'          => $teamId,
            'app'             => $app,
            'resourceId'      => $resourceId,
            'aclStripped'     => $stripped,
            'sharedWithOther' => $otherTeamCount > 0,
            'otherTeamCount'  => $otherTeamCount,
            'app_id'          => Application::APP_ID,
        ]);

        return [
            'success'         => true,
            'aclStripped'     => $stripped,
            'sharedWithOther' => $otherTeamCount > 0,
            'otherTeamCount'  => $otherTeamCount,
        ];
    }

    /**
     * Count how many teams (other than $excludeTeamId) currently have the given
     * resource connected via teamhub_team_app_resources.
     *
     * Used purely for audit logging in removeTeamAccess — does not affect behaviour.
     */
    private function countOtherTeamsWithResource(
        \OCP\IDBConnection $db,
        string             $app,
        string             $resourceId,
        string             $excludeTeamId
    ): int {
        try {
            $qb = $db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'cnt'))
                ->from('teamhub_team_app_resources')
                ->where($qb->expr()->eq('app_id',      $qb->createNamedParameter($app)))
                ->andWhere($qb->expr()->eq('resource_id', $qb->createNamedParameter($resourceId)))
                ->andWhere($qb->expr()->neq('team_id',  $qb->createNamedParameter($excludeTeamId)))
                ->andWhere($qb->expr()->eq('status',    $qb->createNamedParameter('active')));
            $result = $qb->executeQuery();
            $count  = (int) $result->fetchOne();
            $result->closeCursor();
            return $count;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Fully delete a team resource. Delegates to the appropriate sub-service.
     * Legacy method — operates on the first resource for the given app.
     * Prefer deleteSpecificResource for multi-resource teams.
     */
    public function deleteTeamResource(string $teamId, string $app): array {
        $db = $this->container->get(\OCP\IDBConnection::class);
        switch ($app) {
            case 'talk':
                $result = $this->talkService->deleteTalkRoom($teamId, $db);
                if (!empty($result['deleted'])) {
                    $this->removeResourceRowsByTeamAndApp($teamId, 'talk');
                }
                return $result;
            case 'files':
                $result = $this->filesService->deleteSharedFolder($teamId, $db);
                if (!empty($result['deleted'])) {
                    $this->removeResourceRowsByTeamAndApp($teamId, 'files');
                }
                return $result;
            case 'calendar':
                $result = $this->calendarService->deleteCalendar($teamId, $db);
                if (!empty($result['deleted'])) {
                    $this->removeResourceRowsByTeamAndApp($teamId, 'calendar');
                }
                return $result;
            case 'deck':
                $result = $this->deckService->deleteDeckBoard($teamId, $db);
                if (!empty($result['deleted'])) {
                    $this->removeResourceRowsByTeamAndApp($teamId, 'deck');
                }
                return $result;
            case 'intravox':
                return $this->intravoxService->deletePage($teamId, $this->getTeamName($teamId, $db));
            default:
                return ['deleted' => false, 'detail' => "Unknown app: {$app}"];
        }
    }

    /**
     * Delete a specific resource by resourceId (multi-resource-aware).
     * Destroys the underlying NC resource (room, calendar, folder, board)
     * and deletes the registry row.
     *
     * @param string $teamId
     * @param string $app         talk | files | calendar | deck
     * @param string $resourceId  Resource identifier
     * @return array { success: bool, error?: string }
     */
    public function deleteSpecificResource(string $teamId, string $app, string $resourceId): array {
        $db  = $this->container->get(\OCP\IDBConnection::class);
        $row = $this->resourceMapper->findByTeamAppResource($teamId, $app, $resourceId);

        if ($row === null) {
            return ['success' => false, 'error' => 'Resource row not found'];
        }

        try {
            $result = match ($app) {
                'talk'     => $this->talkService->deleteRoomById($resourceId, $db),
                'files'    => $this->filesService->deleteFolderById((int)$resourceId, $teamId, $db),
                'calendar' => $this->calendarService->deleteCalendarById((int)$resourceId, $db),
                'deck'     => $this->deckService->deleteBoardById((int)$resourceId, $db),
                default    => ['deleted' => false, 'detail' => "Unknown app: {$app}"],
            };
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][ResourceService] deleteSpecificResource failed', [
                'teamId' => $teamId, 'app' => $app, 'resourceId' => $resourceId,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }

        // Delete the registry row whether or not the underlying delete succeeded
        // (if the resource was already gone, we still want to clean up our row).
        $this->resourceMapper->deleteById($row->getId());

        $this->logger->info('[TeamHub][ResourceService] deleteSpecificResource completed', [
            'teamId' => $teamId, 'app' => $app, 'resourceId' => $resourceId,
            'result' => $result, 'app_id' => Application::APP_ID,
        ]);

        return ['success' => true, 'detail' => $result];
    }
    /**
     * Remove all registry rows for a team + app after a hard delete.
     * Errors are logged and swallowed — the NC-side resource is already gone.
     */
    private function removeResourceRowsByTeamAndApp(string $teamId, string $appId): void {
        try {
            $rows = $this->resourceMapper->findAllByTeamAndApp($teamId, $appId);
            foreach ($rows as $row) {
                $this->resourceMapper->deleteById($row->getId());
            }
            $this->logger->debug('[TeamHub][ResourceService] removeResourceRowsByTeamAndApp — removed', [
                'teamId' => $teamId, 'appId' => $appId, 'count' => count($rows),
                'app' => Application::APP_ID,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ResourceService] removeResourceRowsByTeamAndApp failed', [
                'teamId' => $teamId, 'appId' => $appId, 'error' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
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
    // -------------------------------------------------------------------------
    // Resource registry write helpers
    // -------------------------------------------------------------------------

    /**
     * Insert or ignore a resource row in teamhub_team_app_resources.
     *
     * Used after successful create or connect operations. If a row for this
     * (team, app, resource_id) already exists (e.g. grandfathered by migration)
     * we leave it untouched — the existing origin/status is authoritative.
     *
     * @param string $teamId     Circle unique_id
     * @param string $appId      'talk' | 'files' | 'calendar' | 'deck'
     * @param string $resourceId Token, folder_id, calendar_id, or board_id as string
     * @param string $origin     'teamhub_create' | 'teamhub_connect'
     * @param string $decidedBy  UID of the acting user
     */
    private function upsertResourceRow(
        string $teamId,
        string $appId,
        string $resourceId,
        string $origin,
        string $decidedBy
    ): void {
        try {
            $existing = $this->resourceMapper->findByTeamAppResource($teamId, $appId, $resourceId);
            if ($existing !== null) {
                $this->logger->debug('[TeamHub][ResourceService] upsertResourceRow — row already exists, skipping', [
                    'teamId' => $teamId, 'appId' => $appId, 'resourceId' => $resourceId,
                    'app' => Application::APP_ID,
                ]);
                return;
            }
            $this->resourceMapper->insertResource(
                teamId:       $teamId,
                appId:        $appId,
                resourceId:   $resourceId,
                origin:       $origin,
                status:       'active',
                riskStatus:   'none',
                displayOrder: 0,
                decidedBy:    $decidedBy,
                decidedAt:    time(),
            );
            $this->logger->debug('[TeamHub][ResourceService] upsertResourceRow — inserted', [
                'teamId' => $teamId, 'appId' => $appId, 'resourceId' => $resourceId,
                'origin' => $origin, 'app' => Application::APP_ID,
            ]);
        } catch (\Throwable $e) {
            // Non-fatal: the NC-side share/ACL was already written. Log so we
            // can diagnose drift between the registry and NC reality at next reconcile.
            $this->logger->warning('[TeamHub][ResourceService] upsertResourceRow failed', [
                'teamId' => $teamId, 'appId' => $appId, 'resourceId' => $resourceId,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

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
    // Membership checks
    // -------------------------------------------------------------------------

    /**
     * Check whether a user has indirect (group/sub-team) access to a team.
     *
     * Cannot use MemberService::isEffectiveMember() directly because
     * MemberService already injects ResourceService (circular dependency).
     * This helper replicates the same two-step logic inline:
     *
     *  Step 1 — collect every circle the user belongs to directly
     *           (circles_member WHERE user_id=uid AND user_type=1).
     *           This includes their personal circle (level=9 owner) and any
     *           team circles they are a direct member of.
     *
     *  Step 2 — check whether any of those circle IDs appears as single_id
     *           in circles_membership for the target team. Circles populates
     *           this denormalised cache when a group or sub-team is added to
     *           a circle, expanding each member's personal-circle single_id
     *           into one row per team.
     *
     * This approach requires no preferences-table lookup and no bitwise
     * config-flag guessing, so it works even for users who have never
     * opened the Circles/Teams UI and have no userSingleId preference set.
     */
    private function isEffectiveTeamMember(\OCP\IDBConnection $db, string $teamId, string $uid): bool {
        try {
            // Step 1: all circles this user is directly a member of (user_type=1)
            $ucQb  = $db->getQueryBuilder();
            $ucRes = $ucQb->select('circle_id')
                ->from('circles_member')
                ->where($ucQb->expr()->eq('user_id',   $ucQb->createNamedParameter($uid)))
                ->andWhere($ucQb->expr()->eq('user_type', $ucQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeQuery();
            $circleIds = [];
            while ($row = $ucRes->fetch()) {
                if (!empty($row['circle_id'])) {
                    $circleIds[] = $row['circle_id'];
                }
            }
            $ucRes->closeCursor();

            if (empty($circleIds)) {
                $this->logger->debug('[ResourceService] isEffectiveTeamMember: user has no circle memberships', [
                    'uid' => $uid, 'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
                return false;
            }

            // Step 2: check circles_membership for an intersection
            $qb  = $db->getQueryBuilder();
            $res = $qb->select($qb->func()->count('*', 'cnt'))
                ->from('circles_membership')
                ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->in('single_id', $qb->createNamedParameter($circleIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
                ->executeQuery();
            $cnt = (int)$res->fetchOne();
            $res->closeCursor();

            $this->logger->debug('[ResourceService] isEffectiveTeamMember via circles_membership', [
                'uid' => $uid, 'teamId' => $teamId, 'found' => $cnt > 0, 'app' => Application::APP_ID,
            ]);
            return $cnt > 0;
        } catch (\Throwable $e) {
            $this->logger->warning('[ResourceService] isEffectiveTeamMember check failed', [
                'teamId' => $teamId, 'uid' => $uid, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

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
