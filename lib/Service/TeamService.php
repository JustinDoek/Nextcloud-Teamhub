<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\TeamAppMapper;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class TeamService {
    /** @var \OCA\Circles\CirclesManager|null */
    private $circlesManager = null;

    public function __construct(
        private TeamAppMapper $teamAppMapper,
        private IUserSession $userSession,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private IUserManager $userManager,
    ) {
    }

    private function getCirclesManager(): \OCA\Circles\CirclesManager {
        if ($this->circlesManager === null) {
            if (!$this->appManager->isInstalled('circles')) {
                throw new \Exception('Nextcloud Teams (Circles) app is not enabled. Please enable it first.');
            }
            try {
                $this->circlesManager = $this->container->get(\OCA\Circles\CirclesManager::class);
            } catch (\Exception $e) {
                throw new \Exception('Failed to load Circles Manager: ' . $e->getMessage());
            }
        }
        return $this->circlesManager;
    }

    /**
     * Get all teams the current user is a member of.
     * Reads directly from DB — probeCircles() on this instance filters out all
     * circles with non-zero config, so it cannot be used as the team list source.
     */
    public function getUserTeams(): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            $this->logger->warning('getUserTeams called without authenticated user', ['app' => Application::APP_ID]);
            return [];
        }

        if (!$this->appManager->isInstalled('circles')) {
            $this->logger->warning('Circles app is not enabled', ['app' => Application::APP_ID]);
            return [];
        }

        try {
            $db  = $this->container->get(\OCP\IDBConnection::class);
            $uid = $user->getUID();

            // Join circles_circle + circles_member to get teams this user belongs to.
            // Exclude internal Circles system circles:
            //   - name starts with 'user:' or 'group:' (auto-created by NC for contacts/groups)
            //   - member status != 1 (pending/blocked)
            $qb = $db->getQueryBuilder();
            $qb->select('c.unique_id', 'c.name', 'c.description', 'c.config', 'm.level')
               ->from('circles_circle', 'c')
               ->join('c', 'circles_member', 'm', 'c.unique_id = m.circle_id')
               ->where($qb->expr()->eq('m.user_id',   $qb->createNamedParameter($uid)))
               ->andWhere($qb->expr()->eq('m.user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->eq('m.status',  $qb->createNamedParameter('Member')))
               ->orderBy('c.name', 'ASC');

            $result = $qb->executeQuery();
            $teams  = [];

            while ($row = $result->fetch()) {
                $name = $row['name'] ?? '';

                // Skip auto-generated system circles
                if (str_starts_with($name, 'user:') || str_starts_with($name, 'group:')) {
                    continue;
                }

                // Get member count for this circle
                $countQb = $db->getQueryBuilder();
                $countRes = $countQb->select($countQb->func()->count('*', 'cnt'))
                    ->from('circles_member')
                    ->where($countQb->expr()->eq('circle_id', $countQb->createNamedParameter($row['unique_id'])))
                    ->andWhere($countQb->expr()->eq('status', $countQb->createNamedParameter('Member')))
                    ->executeQuery();
                $countRow    = $countRes->fetch();
                $memberCount = $countRow ? (int)$countRow['cnt'] : 0;
                $countRes->closeCursor();

                $teams[] = [
                    'id'          => $row['unique_id'],
                    'name'        => $name,
                    'description' => $row['description'] ?? '',
                    'members'     => $memberCount,
                    'unread'      => $this->hasUnreadMessages($db, $row['unique_id'], $uid),
                ];
            }
            $result->closeCursor();

            return $teams;

        } catch (\Exception $e) {
            $this->logger->error('Error in getUserTeams', ['exception' => $e, 'app' => Application::APP_ID]);
            return [];
        }
    }

    /**
     * True if the team has at least one message posted after the user last visited it.
     * Uses inline DB queries to avoid depending on MessageMapper here.
     */
    private function hasUnreadMessages(\OCP\IDBConnection $db, string $teamId, string $userId): bool {
        // Get user's last-seen timestamp for this team
        $qb = $db->getQueryBuilder();
        $res = $qb->select('last_seen_at')
            ->from('teamhub_last_seen')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        $lastSeen = $row ? (int)$row['last_seen_at'] : 0;

        // Get the latest message timestamp for this team
        $qb2 = $db->getQueryBuilder();
        $res2 = $qb2->select($qb2->createFunction('MAX(created_at) as latest'))
            ->from('teamhub_messages')
            ->where($qb2->expr()->eq('team_id', $qb2->createNamedParameter($teamId)))
            ->executeQuery();
        $row2 = $res2->fetch();
        $res2->closeCursor();
        $latest = (int)($row2['latest'] ?? 0);

        // Unread if there are any messages and the latest is newer than last seen
        return $latest > 0 && $latest > $lastSeen;
    }

    /**
     * Get a specific team by ID.
     */
    public function getTeam(string $teamId): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        // Verify access via Circles API (throws if user has no access)
        $circlesManager = $this->getCirclesManager();
        $federatedUser  = $circlesManager->getFederatedUser($user->getUID(), 1);
        $circlesManager->startSession($federatedUser);
        try {
            $circlesManager->getCircle($teamId);
        } catch (\Exception $e) {
            throw new \Exception('Team not found or access denied');
        } finally {
            $circlesManager->stopSession();
        }

        // Fetch the data from DB
        $db = $this->container->get(\OCP\IDBConnection::class);
        $qb = $db->getQueryBuilder();
        $res = $qb->select('c.unique_id', 'c.name', 'c.description')
            ->from('circles_circle', 'c')
            ->where($qb->expr()->eq('c.unique_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();

        if (!$row) {
            throw new \Exception('Team not found');
        }

        $countQb  = $db->getQueryBuilder();
        $countRes = $countQb->select($countQb->func()->count('*', 'cnt'))
            ->from('circles_member')
            ->where($countQb->expr()->eq('circle_id', $countQb->createNamedParameter($teamId)))
            ->andWhere($countQb->expr()->eq('status', $countQb->createNamedParameter('Member')))
            ->executeQuery();
        $countRow    = $countRes->fetch();
        $memberCount = $countRow ? (int)$countRow['cnt'] : 0;
        $countRes->closeCursor();

        return [
            'id'          => $row['unique_id'],
            'name'        => $row['name'],
            'description' => $row['description'] ?? '',
            'members'     => $memberCount,
        ];
    }

    /**
     * Create a new team with optional description and members.
     *
     * @param string   $name        Team name
     * @param string   $description Optional description
     * @param string[] $memberIds   User IDs to invite immediately
     */
    public function createTeam(string $name): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $circlesManager = $this->getCirclesManager();
        $federatedUser = $circlesManager->getFederatedUser($user->getUID(), 1);
        $circlesManager->startSession($federatedUser);

        try {
            // Create circle with name only — description is always set via updateTeamDescription separately
            $circle = $circlesManager->createCircle($name);
            return $this->circleToArray($circle);
        } catch (\Exception $e) {
            $this->logger->error('Error creating team', ['exception' => $e, 'app' => Application::APP_ID]);
            throw new \Exception('Failed to create team: ' . $e->getMessage());
        } finally {
            $circlesManager->stopSession();
        }
    }

    /**
     * Search users by display name or user ID (for member picker).
     */
    public function searchUsers(string $query, int $limit = 10): array {
        $currentUser = $this->userSession->getUser();
        $currentUid  = $currentUser ? $currentUser->getUID() : '';
        $allowedTypes = $this->getAllowedInviteTypes();
        $results = [];

        // Local NC users (Circles member type 1)
        if (in_array('user', $allowedTypes, true)) {
            $this->userManager->callForAllUsers(function ($user) use ($query, $currentUid, &$results, $limit) {
                if (count($results) >= $limit) return;
                if ($user->getUID() === $currentUid) return;
                $uid     = $user->getUID();
                $display = $user->getDisplayName();
                if (stripos($uid, $query) !== false || stripos($display, $query) !== false) {
                    $results[] = [
                        'id'          => $uid,
                        'displayName' => $display ?: $uid,
                        'type'        => 'user',
                        'icon'        => 'user',
                    ];
                }
            });
        }

        // NC Groups (Circles member type 2)
        if (in_array('group', $allowedTypes, true)) {
            try {
                $groupManager = $this->container->get(\OCP\IGroupManager::class);
                $groups = $groupManager->search($query, $limit);
                foreach ($groups as $group) {
                    if (count($results) >= $limit * 2) break;
                    $results[] = [
                        'id'          => $group->getGID(),
                        'displayName' => $group->getDisplayName() ?: $group->getGID(),
                        'type'        => 'group',
                        'icon'        => 'group',
                    ];
                }
            } catch (\Throwable $e) {
                // GroupManager not available
            }
        }

        // Email — if query looks like an email address and email type is allowed
        if (in_array('email', $allowedTypes, true) && filter_var($query, FILTER_VALIDATE_EMAIL)) {
            $results[] = [
                'id'          => $query,
                'displayName' => $query,
                'type'        => 'email',
                'icon'        => 'email',
            ];
        }

        // Federated user — if query contains '@' with domain and federated type is allowed
        // Format: user@remote.example.com
        if (in_array('federated', $allowedTypes, true) && preg_match('/^[^@]+@[^@]+\.[^@]+$/', $query) && !filter_var($query, FILTER_VALIDATE_EMAIL)) {
            $results[] = [
                'id'          => $query,
                'displayName' => $query,
                'type'        => 'federated',
                'icon'        => 'federation',
            ];
        }

        return $results;
    }

    /**
     * Get team members with roles.
     */
    public function getTeamMembers(string $teamId): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $circlesManager = $this->getCirclesManager();
        $federatedUser = $circlesManager->getFederatedUser($user->getUID(), 1);
        $circlesManager->startSession($federatedUser);

        try {
            $circle = $circlesManager->getCircle($teamId);
            if (!$circle) {
                throw new \Exception('Team not found or access denied');
            }

            $members = [];
            if (method_exists($circle, 'getMembers')) {
                foreach ($circle->getMembers() as $member) {
                    try {
                        $members[] = $this->memberToArray($member);
                    } catch (\Exception $e) {
                        $this->logger->warning('Error processing member', [
                            'exception' => $e,
                            'app' => Application::APP_ID,
                        ]);
                    }
                }
            }
            return $members;

        } finally {
            $circlesManager->stopSession();
        }
    }

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

                        $roomQb = $db->getQueryBuilder();
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
                    $this->logger->warning('Talk resource query failed', ['e' => $e->getMessage(), 'app' => Application::APP_ID]);
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
                $this->logger->warning('Files resource query failed', ['e' => $e->getMessage(), 'app' => Application::APP_ID]);
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
                        $calendarId  = $row['resourceid'];
                        $publicUri   = $row['publicuri'] ?? null;
                        $result->closeCursor();

                        $calQb = $db->getQueryBuilder();
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
                                $psQb = $db->getQueryBuilder();
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
                            } catch (\Throwable $e) {}

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
                    $this->logger->warning('Calendar resource query failed', ['e' => $e->getMessage(), 'app' => Application::APP_ID]);
                }
            }

            // ── Deck ─────────────────────────────────────────────────────────
            if ($this->appManager->isInstalled('deck')) {
                try {
                    // Try deck_board_acl first (Deck 1.x), fall back to checking deck_acl
                    $aclTable = 'deck_board_acl';
                    $boardIdCol = 'board_id';
                    $participantCol = 'participant';
                    $typeCol = 'type';

                    try {
                        // Quick column check to determine table
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

                        $boardQb = $db->getQueryBuilder();
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
                    $this->logger->warning('Deck resource query failed', ['e' => $e->getMessage(), 'app' => Application::APP_ID]);
                }
            }

        } catch (\Throwable $e) {
            $this->logger->error('getTeamResources failed', ['e' => $e->getMessage(), 'app' => Application::APP_ID]);
        }

        return $resources;
    }
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
     */
    public function getTeamActivity(string $teamId, int $limit = 25, int $since = 0): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $db        = $this->container->get(\OCP\IDBConnection::class);
        $resources = $this->getTeamResources($teamId);

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
        // For numeric IDs use PARAM_INT; for non-numeric use CAST(object_id AS VARCHAR).
        try {
            $platform = $db->getDatabasePlatform();
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
                    // For circles this is enough: circle events have a unique object_type.
                    // Files and talk are matched by path/token in the clauses below.
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
            $this->logger->error('getTeamActivity query failed', ['e' => $e->getMessage(), 'app' => Application::APP_ID]);
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
            if (isset($seen[$fp])) continue;
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

            if (count($items) >= $limit) break;
        }

        return $items;
    }

    /** Map app+type to a Material Design icon name for the frontend. */
    private function activityIcon(string $app, string $type): string {
        return match(true) {
            $app === 'circles'                              => 'AccountMultiple',
            $app === 'files' && str_contains($type, 'created') => 'FilePlus',
            $app === 'files' && str_contains($type, 'changed') => 'FileEdit',
            $app === 'files' && str_contains($type, 'deleted') => 'FileRemove',
            $app === 'files' && str_contains($type, 'restored')=> 'FileRestore',
            $app === 'files'                               => 'File',
            $app === 'deck'                                => 'CardText',
            str_contains($app, 'calendar')                 => 'Calendar',
            str_contains($app, 'spreed')                   => 'Chat',
            default                                        => 'Bell',
        };
    }

    /**
     * Get apps enabled for a team.
     */
    public function getTeamApps(string $teamId): array {
        return $this->teamAppMapper->findByTeamId($teamId);
    }

    /**
     * Update apps configuration for a team.
     */
    public function updateTeamApps(string $teamId, array $apps): void {
        foreach ($apps as $app) {
            $this->teamAppMapper->upsert($teamId, $app['app_id'], $app['enabled'], $app['config'] ?? null);
        }
    }

    /**
     * Leave a team (remove current user from circle).
     */
    public function leaveTeam(string $teamId): void {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $manager = $this->getCirclesManager();
        $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
        $manager->startSession($federatedUser);

        try {
            $circle = $manager->getCircle($teamId);
            $members = $circle->getMembers();
            $currentMember = null;

            foreach ($members as $member) {
                $userId = method_exists($member, 'getUserId') ? $member->getUserId() : null;
                if ($userId === $user->getUID()) {
                    $currentMember = $member;
                    break;
                }
            }

            if (!$currentMember) {
                throw new \Exception('You are not a member of this team');
            }

            if ($currentMember->getLevel() >= 9 && count($members) > 1) {
                throw new \Exception('Team owner cannot leave. Transfer ownership or delete the team first.');
            }

            $manager->removeMember($currentMember->getId());

        } catch (\Exception $e) {
            $this->logger->error('Error leaving team', ['teamId' => $teamId, 'exception' => $e, 'app' => Application::APP_ID]);
            throw $e;
        } finally {
            $manager->stopSession();
        }
    }

    /**
     * Get upcoming calendar events for a team.
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

            $calendarId = (int)$row['resourceid'];
            $now = time();
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

                    $dtstart = $vevent->DTSTART;
                    $startTime = $dtstart->getDateTime();
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
                        'location'    => isset($vevent->LOCATION) ? (string)$vevent->LOCATION : null,
                        'description' => isset($vevent->DESCRIPTION) ? (string)$vevent->DESCRIPTION : null,
                        'allDay'      => !$dtstart->hasTime(),
                    ];
                } catch (\Exception $e) {
                    $this->logger->warning('Error parsing calendar event', ['exception' => $e, 'app' => Application::APP_ID]);
                }
            }
            $result->closeCursor();

            usort($events, fn($a, $b) => strcmp($a['start'], $b['start']));
            return array_slice($events, 0, $limit);

        } catch (\Exception $e) {
            $this->logger->error('Error getting calendar events', ['exception' => $e, 'app' => Application::APP_ID]);
            return [];
        }
    }

    /**
     * Create a calendar event on the team calendar via CalDAV.
     */
    public function createCalendarEvent(string $teamId, string $title, string $start, string $end, string $location = '', string $description = ''): void {
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

        // Find the calendar owner's principaluri (the creator — needed for CalDavBackend)
        $ownerQb = $db->getQueryBuilder();
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
        $principalUri = $ownerRow['principaluri'];

        // Build iCalendar VEVENT string
        $uid       = strtoupper(bin2hex(random_bytes(16)));
        $startDt   = new \DateTime($start);
        $endDt     = new \DateTime($end);
        $now       = new \DateTime();
        $dtStamp   = $now->format('Ymd\\THis\\Z');
        $dtStart   = $startDt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z');
        $dtEnd     = $endDt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z');

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

        $caldav  = $this->container->get(\OCA\DAV\CalDAV\CalDavBackend::class);
        $objUri  = strtolower($uid) . '.ics';
        $caldav->createCalendarObject($calendarId, $objUri, $ical);
    }

    private function escapeIcalText(string $text): string {
        return str_replace(["\r\n", "\n", "\r", ',', ';', '\\'], ['\\n', '\\n', '\\n', '\\,', '\\;', '\\\\'], $text);
    }

    /**
     * Uses direct DB query — probeCircles() is unreliable on this instance.
     * Only returns circles with CFG_VISIBLE (bit 512) set, or where the
     * current user is already a member.
     */
    public function browseAllTeams(): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        try {
            $db  = $this->container->get(\OCP\IDBConnection::class);
            $uid = $user->getUID();

            // Get all non-system circles
            $qb = $db->getQueryBuilder();
            $qb->select('c.unique_id', 'c.name', 'c.description', 'c.config')
               ->from('circles_circle', 'c')
               ->orderBy('c.name', 'ASC');
            $result = $qb->executeQuery();

            // Get the IDs the user is already a member of
            $memberQb = $db->getQueryBuilder();
            $memRes = $memberQb->select('circle_id')
                ->from('circles_member')
                ->where($memberQb->expr()->eq('user_id',   $memberQb->createNamedParameter($uid)))
                ->andWhere($memberQb->expr()->eq('user_type', $memberQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($memberQb->expr()->eq('status',  $memberQb->createNamedParameter('Member')))
                ->executeQuery();
            $memberIds = [];
            while ($mRow = $memRes->fetch()) {
                $memberIds[$mRow['circle_id']] = true;
            }
            $memRes->closeCursor();

            $CFG_VISIBLE = 512;
            $teams = [];
            while ($row = $result->fetch()) {
                $name     = $row['name'] ?? '';
                $id       = $row['unique_id'];
                $config   = (int)$row['config'];
                $isMember = isset($memberIds[$id]);

                // Skip system circles
                if (str_starts_with($name, 'user:') || str_starts_with($name, 'group:')) {
                    continue;
                }
                // Only include if the user is a member OR the circle is publicly visible
                if (!$isMember && !($config & $CFG_VISIBLE)) {
                    continue;
                }

                $teams[] = [
                    'id'          => $id,
                    'name'        => $name,
                    'description' => $row['description'] ?? '',
                    'isMember'    => $isMember,
                ];
            }
            $result->closeCursor();

            return $teams;

        } catch (\Exception $e) {
            $this->logger->error('Error browsing teams', ['exception' => $e, 'app' => Application::APP_ID]);
            return [];
        }
    }

    /**
     * Request to join a team.
     * Uses CirclesManager::addMember() which creates a pending request.
     * Falls back to a direct DB insert if Circles can't find the circle
     * (happens when config != 0, which causes probeCircles to hide it).
     */
    public function requestJoinTeam(string $teamId): void {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        // First try via Circles API
        try {
            $manager = $this->getCirclesManager();
            $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
            $manager->startSession($federatedUser);
            try {
                $manager->addMember($teamId, $federatedUser);
                return;
            } finally {
                $manager->stopSession();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('requestJoinTeam via API failed, trying direct DB insert', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // Fallback: insert a pending member row directly.
        // status=0 (pending/requested), level=1 (member), user_type=1 (local user)
        $db  = $this->container->get(\OCP\IDBConnection::class);
        $uid = $user->getUID();

        // Check not already a member or requesting
        $checkQb = $db->getQueryBuilder();
        $checkRes = $checkQb->select('single_id')
            ->from('circles_member')
            ->where($checkQb->expr()->eq('circle_id', $checkQb->createNamedParameter($teamId)))
            ->andWhere($checkQb->expr()->eq('user_id',   $checkQb->createNamedParameter($uid)))
            ->andWhere($checkQb->expr()->eq('user_type', $checkQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $existing = $checkRes->fetch();
        $checkRes->closeCursor();
        if ($existing) {
            throw new \Exception('You are already a member or have a pending request for this team');
        }

        // Generate a single_id in Circles format (21-char alphanumeric)
        $chars    = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $singleId = '';
        for ($i = 0; $i < 21; $i++) {
            $singleId .= $chars[random_int(0, strlen($chars) - 1)];
        }

        // Build insert with only columns that exist in this NC version's circles_member table
        $qb = $db->getQueryBuilder();
        $existingCols = array_flip($this->getTableColumns('circles_member'));
        $values = [
            'circle_id'  => $qb->createNamedParameter($teamId),
            'single_id'  => $qb->createNamedParameter($singleId),
            'user_id'    => $qb->createNamedParameter($uid),
            'user_type'  => $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
            'member_id'  => $qb->createNamedParameter($uid),
            'instance'   => $qb->createNamedParameter(''),
            'level'      => $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
            'status'     => $qb->createNamedParameter('Requesting'),
            'joined'     => $qb->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        ];
        $optional = [
            'display_name' => $qb->createNamedParameter($user->getDisplayName() ?: $uid),
            'cached_name'  => $qb->createNamedParameter($user->getDisplayName() ?: $uid),
            'note'         => $qb->createNamedParameter(''),
            'contact_id'   => $qb->createNamedParameter(''),
            'contact_meta' => $qb->createNamedParameter(''),
        ];
        foreach ($optional as $col => $val) {
            if (isset($existingCols[$col])) {
                $values[$col] = $val;
            }
        }

        try {
            $qb->insert('circles_member')->values($values)->executeStatement();
        } catch (\Throwable $e) {
            $this->logger->error('requestJoinTeam DB fallback failed', ['e' => $e->getMessage(), 'app' => Application::APP_ID]);
            throw new \Exception('Failed to request team membership: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Manage Team methods (admin/owner level enforced)
    // -------------------------------------------------------------------------

    /**
     * Update the description of a team.
     * Verifies the caller is owner/admin using getCircle (not probeCircles, which has timing issues
     * immediately after createCircle). Falls back to a direct DB update since some Circles versions
     * do not expose updateCircle().
     */
    public function updateTeamDescription(string $teamId, string $description): void {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        // getCircle() throws if user doesn't have access — this IS the permission check.
        // We do NOT probe the member list (unreliable immediately after createCircle).
        $manager = $this->getCirclesManager();
        $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
        $manager->startSession($federatedUser);

        try {
            // This throws if the circle doesn't exist or user has no access
            $manager->getCircle($teamId);

            // Write description directly to DB — avoids needing updateCircle() which may not exist
            $db = $this->container->get(\OCP\IDBConnection::class);
            $affected = $db->executeStatement(
                'UPDATE `*PREFIX*circles_circle` SET `description` = ? WHERE `unique_id` = ?',
                [$description, $teamId]
            );
            if ($affected === 0) {
                // Fallback: maybe the column name differs — try 'desc'
                $db->executeStatement(
                    'UPDATE `*PREFIX*circles_circle` SET `long_desc` = ? WHERE `unique_id` = ?',
                    [$description, $teamId]
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Error updating team description', ['teamId' => $teamId, 'exception' => $e, 'app' => Application::APP_ID]);
            throw new \Exception('Failed to update description: ' . $e->getMessage());
        } finally {
            $manager->stopSession();
        }
    }

    /**
     * Remove a member from a team. Requires admin or owner level.
     */
    public function removeMember(string $teamId, string $userId): void {
        $this->requireAdminLevel($teamId);

        $manager = $this->getCirclesManager();
        $manager->startSession();

        try {
            $circle = $manager->getCircle($teamId);
            $members = $circle->getMembers();

            $targetMember = null;
            foreach ($members as $member) {
                $mId = method_exists($member, 'getUserId') ? $member->getUserId() : null;
                if ($mId === $userId) {
                    $targetMember = $member;
                    break;
                }
            }

            if (!$targetMember) {
                throw new \Exception('Member not found in this team');
            }

            if ($targetMember->getLevel() >= 9) {
                throw new \Exception('Cannot remove the team owner');
            }

            $manager->removeMember($targetMember->getId());

        } catch (\Exception $e) {
            $this->logger->error('Error removing member', [
                'teamId' => $teamId,
                'userId' => $userId,
                'exception' => $e,
                'app' => Application::APP_ID,
            ]);
            throw $e;
        } finally {
            $manager->stopSession();
        }
    }

    /**
     * Update a member's level in a team. Requires admin or owner level.
     * Valid target levels: 1 (Member), 4 (Moderator), 8 (Admin).
     * Owner (9) cannot be demoted through this endpoint.
     * The caller cannot change their own level.
     */
    public function updateMemberLevel(string $teamId, string $userId, int $newLevel): array {
        $caller = $this->userSession->getUser();
        if (!$caller) {
            throw new \Exception('User not authenticated');
        }

        if ($caller->getUID() === $userId) {
            throw new \Exception('You cannot change your own level');
        }

        if (!in_array($newLevel, [1, 4, 8], true)) {
            throw new \Exception('Invalid level. Must be 1 (Member), 4 (Moderator), or 8 (Admin)');
        }

        $db = $this->container->get(\OCP\IDBConnection::class);

        // Look up caller's own level directly — no Circles session needed
        $callerLevel = $this->getMemberLevelFromDb($db, $teamId, $caller->getUID());
        if ($callerLevel < 8) {
            throw new \Exception('Insufficient permissions. Admin or owner role required.');
        }

        // Look up target member's current level
        $targetLevel = $this->getMemberLevelFromDb($db, $teamId, $userId);
        if ($targetLevel === 0) {
            throw new \Exception('Member not found in this team');
        }
        if ($targetLevel >= 9) {
            throw new \Exception('Cannot change the level of the team owner');
        }

        // Only the owner can promote to admin
        if ($newLevel >= 8 && $callerLevel < 9) {
            throw new \Exception('Only the team owner can promote members to Admin');
        }

        // Write the new level directly to circles_member
        $qb = $db->getQueryBuilder();
        $qb->update('circles_member')
            ->set('level', $qb->createNamedParameter($newLevel, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('Member')))
            ->executeStatement();

        // Return refreshed member list (one Circles session total)
        return $this->getTeamMembers($teamId);
    }

    /**
     * Direct DB lookup for a member's level in a team (0 if not a member).
     */
    private function getMemberLevelFromDb(\OCP\IDBConnection $db, string $teamId, string $userId): int {
        $qb = $db->getQueryBuilder();
        $result = $qb->select('level')
            ->from('circles_member')
            ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('Member')))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ? (int)$row['level'] : 0;
    }

    /**
     * Check whether the current user is allowed to create teams, based on
     * the admin setting 'createTeamGroup'. If the setting is empty, everyone
     * can create. Otherwise the user must be a member of the named NC group.
     */
    public function canCurrentUserCreateTeam(): bool {
        $user = $this->userSession->getUser();
        if (!$user) {
            return false;
        }

        $config = $this->container->get(\OCP\IConfig::class);
        $requiredGroup = trim($config->getAppValue(Application::APP_ID, 'createTeamGroup', ''));

        if ($requiredGroup === '') {
            return true; // no restriction set
        }

        $groupManager = $this->container->get(\OCP\IGroupManager::class);
        return $groupManager->isInGroup($user->getUID(), $requiredGroup);
    }

    /**
     * Get pending membership requests for a team. Requires admin or owner level.
     */
    public function getPendingRequests(string $teamId): array {
        $this->requireAdminLevel($teamId);

        $manager = $this->getCirclesManager();
        $manager->startSession();

        try {
            $circle = $manager->getCircle($teamId);
            $pending = [];

            if (method_exists($circle, 'getMembers')) {
                foreach ($circle->getMembers() as $member) {
                    // Status 4 = Requesting in Circles API
                    $status = method_exists($member, 'getStatus') ? $member->getStatus() : null;
                    if ($status === 4) {
                        $pending[] = $this->memberToArray($member);
                    }
                }
            }

            return $pending;

        } catch (\Exception $e) {
            $this->logger->error('Error getting pending requests', ['teamId' => $teamId, 'exception' => $e, 'app' => Application::APP_ID]);
            throw new \Exception('Failed to get pending requests: ' . $e->getMessage());
        } finally {
            $manager->stopSession();
        }
    }

    /**
     * Approve a pending membership request. Requires admin or owner level.
     */
    public function approveRequest(string $teamId, string $userId): void {
        $this->requireAdminLevel($teamId);

        $manager = $this->getCirclesManager();
        $manager->startSession();

        try {
            $circle = $manager->getCircle($teamId);

            $targetMember = null;
            foreach ($circle->getMembers() as $member) {
                $mId = method_exists($member, 'getUserId') ? $member->getUserId() : null;
                if ($mId === $userId) {
                    $targetMember = $member;
                    break;
                }
            }

            if (!$targetMember) {
                throw new \Exception('Pending request not found');
            }

            $manager->addMember($teamId, $targetMember);

        } catch (\Exception $e) {
            $this->logger->error('Error approving request', ['teamId' => $teamId, 'userId' => $userId, 'exception' => $e, 'app' => Application::APP_ID]);
            throw new \Exception('Failed to approve request: ' . $e->getMessage());
        } finally {
            $manager->stopSession();
        }
    }

    /**
     * Reject a pending membership request. Requires admin or owner level.
     */
    public function rejectRequest(string $teamId, string $userId): void {
        $this->requireAdminLevel($teamId);

        $manager = $this->getCirclesManager();
        $manager->startSession();

        try {
            $circle = $manager->getCircle($teamId);

            $targetMember = null;
            foreach ($circle->getMembers() as $member) {
                $mId = method_exists($member, 'getUserId') ? $member->getUserId() : null;
                if ($mId === $userId) {
                    $targetMember = $member;
                    break;
                }
            }

            if (!$targetMember) {
                throw new \Exception('Pending request not found');
            }

            $manager->removeMember($targetMember->getId());

        } catch (\Exception $e) {
            $this->logger->error('Error rejecting request', ['teamId' => $teamId, 'userId' => $userId, 'exception' => $e, 'app' => Application::APP_ID]);
            throw new \Exception('Failed to reject request: ' . $e->getMessage());
        } finally {
            $manager->stopSession();
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Asserts the current user has admin (level >= 8) or owner (level 9) in the team.
     */
    private function requireAdminLevel(string $teamId): void {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $members = $this->getTeamMembers($teamId);
        foreach ($members as $member) {
            if ($member['userId'] === $user->getUID()) {
                if ($member['level'] >= 8) {
                    return;
                }
                throw new \Exception('Insufficient permissions. Admin or owner role required.');
            }
        }

        throw new \Exception('You are not a member of this team');
    }

    /**
     * Probe all circles and return them as an array.
     */
    private function probeCircles(\OCA\Circles\CirclesManager $manager): array {
        if (!method_exists($manager, 'probeCircles')) {
            return $manager->getCircles();
        }

        $probeResult = $manager->probeCircles();

        if (is_array($probeResult)) {
            return $probeResult;
        }

        if (is_object($probeResult) && method_exists($probeResult, 'getCircles')) {
            return $probeResult->getCircles();
        }

        return [];
    }

    /**
     * Find a single circle by ID from the current session's probed circles.
     */
    private function findCircle(\OCA\Circles\CirclesManager $manager, string $teamId): mixed {
        $circles = $this->probeCircles($manager);
        foreach ($circles as $circle) {
            $id = method_exists($circle, 'getSingleId') ? $circle->getSingleId() : $circle->getId();
            if ($id === $teamId) {
                return $circle;
            }
        }
        return null;
    }

    /** Convert a Circle to the array shape used by the API. */
    private function circleToArray(mixed $circle): array {
        $memberCount = 0;
        if (method_exists($circle, 'getMembers')) {
            $members = $circle->getMembers();
            $memberCount = is_array($members) ? count($members) : 0;
        }

        return [
            'id'          => method_exists($circle, 'getSingleId') ? $circle->getSingleId() : $circle->getId(),
            'name'        => method_exists($circle, 'getDisplayName') ? $circle->getDisplayName() : $circle->getName(),
            'description' => method_exists($circle, 'getDescription') ? ($circle->getDescription() ?? '') : '',
            'members'     => $memberCount,
        ];
    }

    /** Convert a Circle Member to the array shape used by the API. */
    private function memberToArray(mixed $member): array {
        $userId = method_exists($member, 'getUserId')
            ? $member->getUserId()
            : (method_exists($member, 'getId') ? $member->getId() : null);

        // Skip non-user members (circles-as-members, external, etc.) that have no userId
        $userId = ($userId && $userId !== '') ? $userId : null;

        $displayName = $userId
            ? (method_exists($member, 'getDisplayName') ? $member->getDisplayName() : $userId)
            : (method_exists($member, 'getDisplayName') ? $member->getDisplayName() : 'Unknown');
        $level = method_exists($member, 'getLevel') ? $member->getLevel() : 1;

        $role = match (true) {
            $level >= 9 => 'Owner',
            $level >= 8 => 'Admin',
            $level >= 4 => 'Moderator',
            default     => 'Member',
        };

        return [
            'userId'      => $userId,
            'displayName' => $displayName,
            'role'        => $role,
            'level'       => $level,
        ];
    }

    /**
     * Invite a list of users/groups to a team. Requires admin or owner level.
     * Each entry can be a string (userId, type=user) or array {id, type}.
     * Types: 'user' = local NC user (Circles type 1), 'group' = NC group (Circles type 2).
     * Non-fatal per entry — returns per-id results.
     */
    public function inviteMembers(string $teamId, array $members): array {
        $this->requireAdminLevel($teamId);

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $manager = $this->getCirclesManager();
        $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
        $manager->startSession($federatedUser);

        $results = [];
        try {
            foreach ($members as $entry) {
                // Support both plain string (legacy) and {id, type} object
                if (is_string($entry)) {
                    $memberId   = $entry;
                    $memberType = 1; // user
                } else {
                    $memberId   = $entry['id'] ?? '';
                    $typeStr    = $entry['type'] ?? 'user';
                    $memberType = match($typeStr) {
                        'group'     => 2,  // NC group
                        'federated' => 4,  // federated NC user
                        'email'     => 7,  // email (requires Circles federation)
                        default     => 1,  // local NC user
                    };
                }

                if (!$memberId || ($memberType === 1 && $memberId === $user->getUID())) continue;

                try {
                    $invitee = $manager->getFederatedUser($memberId, $memberType);
                    $manager->addMember($teamId, $invitee);
                    $results[$memberId] = 'invited';
                } catch (\Exception $e) {
                    $results[$memberId] = 'failed: ' . $e->getMessage();
                    $this->logger->warning('Could not invite member', [
                        'id'   => $memberId,
                        'type' => $memberType,
                        'exception' => $e,
                        'app'  => Application::APP_ID,
                    ]);
                }
            }
        } finally {
            $manager->stopSession();
        }
        return $results;
    }


    /**
     * Create app resources (Talk conversation, Files folder, Calendar, Deck board)
     * and share them with the circle. Returns per-app results.
     *
     * @param string   $teamId   Circle single ID
     * @param string[] $apps     Array of app IDs to create: 'talk', 'files', 'calendar', 'deck'
     * @param string   $teamName Display name to use for created resources
     */
    public function createTeamResources(string $teamId, array $apps, string $teamName): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $uid  = $user->getUID();
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
                $this->logger->error("Failed to create resource for {$app}", [
                    'teamId'    => $teamId,
                    'app'       => $app,
                    'message'   => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                    'exception' => Application::APP_ID,
                ]);
            }
        }

        // Always return 200 with per-app results — never 500 from this endpoint.
        // Caller should inspect each result for 'error' key.
        return $results;
    }

    /**
     * Create a Talk group room and add the circle as participant.
     *
     * Uses the Talk OCS REST API (POST /ocs/v2.php/apps/spreed/api/v4/room) so we
     * never touch Talk's DB directly — which avoids all schema/version issues.
     *
     * The `participants.teams` field (introduced alongside the `conversation-creation-all`
     * capability) adds the circle to the room at creation time with proper permissions.
     * We make the request as the creating user via a loopback HTTP call authenticated
     * with a single-use token generated from ISecureRandom.
     *
     * Fallback: if the OCS call fails we return the error — no silent partial state.
     */
    private function createTalkRoom(string $teamId, string $teamName, string $uid): array {
        if (!$this->appManager->isInstalled('spreed')) {
            return ['error' => 'Talk (spreed) app not installed'];
        }

        try {
            $urlGenerator = $this->container->get(\OCP\IURLGenerator::class);
            $clientService = $this->container->get(\OCP\Http\Client\IClientService::class);
            $userSession   = $this->container->get(\OCP\IUserSession::class);

            // Build the internal OCS endpoint URL
            $ocsUrl = rtrim($urlGenerator->getAbsoluteURL('/'), '/')
                    . '/ocs/v2.php/apps/spreed/api/v4/room';

            // Talk OCS API requires user credentials or a login token.
            // We use the IAppPassword / CSRF-free internal request pattern:
            // send as the current user via Basic auth with an app token.
            // Simpler: use the OCS internal HTTP client with the user's session cookie.
            // Most reliable on NC32: POST with requesttoken + user basic auth.
            $userManager = $this->container->get(\OCP\IUserManager::class);
            $user = $userManager->get($uid);
            if (!$user) {
                throw new \Exception("User {$uid} not found");
            }

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
                // Token provider unavailable — fall back to no-auth internal call
                $loginToken = null;
                $this->logger->warning('Talk: could not generate app token, trying without auth', [
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            $client = $clientService->newClient();
            $options = [
                'headers' => [
                    'OCS-APIRequest' => 'true',
                    'Accept'         => 'application/json',
                    'Content-Type'   => 'application/json',
                ],
                'json' => [
                    'roomType' => 2,        // TYPE_GROUP
                    'roomName' => $teamName,
                    'participants' => [
                        'teams' => [$teamId],  // circle ID — adds circle with full membership
                    ],
                ],
                'verify' => false,          // loopback — skip TLS cert check
            ];

            if ($loginToken !== null) {
                $options['auth'] = [$uid, $loginToken];
            }

            $response     = $client->post($ocsUrl, $options);
            $body         = json_decode($response->getBody(), true);
            $token        = $body['ocs']['data']['token'] ?? null;

            // Clean up the temporary token
            if ($loginToken !== null && $tokenProvider !== null) {
                try {
                    // Find and delete the token we just created
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

            $this->logger->info('Talk: created room via OCS API', ['token' => $token, 'app' => Application::APP_ID]);
            return ['token' => $token, 'name' => $teamName, 'circle_added' => true];

        } catch (\Throwable $e) {
            $this->logger->error('Talk: OCS room creation failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['error' => 'Talk room creation failed: ' . $e->getMessage()];
        }
    }

    /**
     * Create a folder in the user's files and share it with the circle.
     */
    private function createSharedFolder(string $teamId, string $teamName, string $uid): array {
        $userFolder = $this->container->get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
        $folderName = $teamName;
        $counter = 1;
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
        return ['folder_id' => $folder->getId(), 'path' => $folder->getPath(), 'share_id' => $share->getId()];
    }

    /**
     * Create a calendar and share it with the circle via the dav_shares table.
     * The circle principal is principals/circles/{teamId}.
     */
    private function createCalendar(string $teamId, string $teamName, string $uid): array {
        if (!$this->appManager->isInstalled('calendar')) {
            return ['error' => 'Calendar app not installed'];
        }

        $caldav = $this->container->get(\OCA\DAV\CalDAV\CalDavBackend::class);
        $principalUri = 'principals/users/' . $uid;
        $calendarUri  = strtolower(preg_replace('/[^a-z0-9]+/', '-', $teamName))
                       . '-' . substr(md5(uniqid()), 0, 6);

        $calendarId = $caldav->createCalendar($principalUri, $calendarUri, [
            '{DAV:}displayname' => $teamName,
            '{http://apple.com/ns/ical/}calendar-color' => '#0082c9',
        ]);

        $db = $this->container->get(\OCP\IDBConnection::class);

        // Share with the circle via dav_shares (read-write)
        $circlePublicUri = 'teamhub-' . substr($teamId, 0, 8) . '-' . $calendarId;
        $db->insertIfNotExist('*PREFIX*dav_shares', [
            'principaluri' => 'principals/circles/' . $teamId,
            'type'         => 'calendar',
            'access'       => 2,   // 2=read-write
            'resourceid'   => (int)$calendarId,
            'publicuri'    => $circlePublicUri,
        ], ['principaluri', 'resourceid']);

        // Create a public share so the calendar can be embedded via /apps/calendar/p/{token}
        // NC calendar public shares use a random token as publicuri with a special principaluri
        $publicToken = bin2hex(random_bytes(16)); // 32-char hex token
        $db->insertIfNotExist('*PREFIX*dav_shares', [
            'principaluri' => 'principals/users/' . $uid,
            'type'         => 'calendar',
            'access'       => 4,   // 4 = public read-only
            'resourceid'   => (int)$calendarId,
            'publicuri'    => $publicToken,
        ], ['publicuri']);

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
            $board = $boardMapper->insert($board);
            $boardId = $board->getId();
        } catch (\Throwable $e) {
            $this->logger->warning('Deck BoardMapper failed, using QB fallback', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // ── Fallback: QB insert into deck_boards ──────────────────────────────
        if ($boardId === null) {
            $boardCols = $this->getTableColumns('deck_boards');
            $qb = $db->getQueryBuilder();
            $qb->insert('deck_boards')->values(['title' => $qb->createNamedParameter($teamName), 'owner' => $qb->createNamedParameter($uid), 'color' => $qb->createNamedParameter('0082c9')]);
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
                $this->logger->warning('Deck stack insert failed', ['stack' => $stackTitle, 'error' => $e->getMessage(), 'app' => Application::APP_ID]);
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
            $this->logger->warning('Deck AclMapper failed, using QB fallback', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // ── Fallback: QB insert into deck_board_acl / deck_acl ───────────────
        if (!$circleAdded) {
            // Try deck_board_acl first (Deck 1.x), fall back to deck_acl
            foreach (['deck_board_acl', 'deck_acl'] as $aclTable) {
                $aclCols = $this->getTableColumns($aclTable);
                if (empty($aclCols)) {
                    continue;
                }
                try {
                    $qb = $db->getQueryBuilder();
                    $qb->insert($aclTable)
                       ->setValue('board_id',    $qb->createNamedParameter($boardId))
                       ->setValue('type',        $qb->createNamedParameter(7))         // 7 = circle
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
                    $this->logger->warning('Deck ACL QB insert failed', [
                        'table' => $aclTable, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }
            }
        }

        $this->logger->info('Deck: created board', ['boardId' => $boardId, 'circleAdded' => $circleAdded, 'app' => Application::APP_ID]);
        return ['board_id' => $boardId, 'name' => $teamName, 'circle_added' => $circleAdded];
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
     * Delete a team (circle). Only the owner can do this.
     * Also cleans up TeamHub app data (app resources meta) for this circle.
     */
    public function deleteTeam(string $teamId): void {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $manager = $this->getCirclesManager();
        $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
        $manager->startSession($federatedUser);

        try {
            $circle = $manager->getCircle($teamId);

            // Only owner (level 9) can delete
            $isOwner = false;
            if (method_exists($circle, 'getOwner')) {
                $owner = $circle->getOwner();
                if ($owner && method_exists($owner, 'getUserId') && $owner->getUserId() === $user->getUID()) {
                    $isOwner = true;
                }
            }
            if (!$isOwner && method_exists($circle, 'getMembers')) {
                foreach ($circle->getMembers() as $member) {
                    $mUid  = method_exists($member, 'getUserId') ? $member->getUserId() : null;
                    $level = method_exists($member, 'getLevel')  ? $member->getLevel()  : 0;
                    if ($mUid === $user->getUID() && $level >= 9) {
                        $isOwner = true;
                        break;
                    }
                }
            }
            if (!$isOwner) {
                throw new \Exception('Only the team owner can delete a team.');
            }

            // Delete the circle
            $manager->destroyCircle($teamId);

            // Clean up TeamHub app data for this team
            try {
                $db = $this->container->get(\OCP\IDBConnection::class);
                $db->executeStatement('DELETE FROM `*PREFIX*teamhub_team_apps` WHERE `team_id` = ?', [$teamId]);
            } catch (\Throwable $e) {
                // Not fatal — table may not exist yet
            }
        } finally {
            $manager->stopSession();
        }
    }

    /**
     * Get admin-configured settings.
     * inviteTypes: comma-separated list of allowed types ('user','group','email','federated').
     * Defaults to 'user,group' if not set.
     */
    public function getAdminSettings(): array {
        $config = $this->container->get(\OCP\IConfig::class);
        return [
            'wizardDescription' => $config->getAppValue(Application::APP_ID, 'wizardDescription', ''),
            'inviteTypes'       => $config->getAppValue(Application::APP_ID, 'inviteTypes', 'user,group'),
            'pinMinLevel'       => $config->getAppValue(Application::APP_ID, 'pinMinLevel', 'moderator'),
            'createTeamGroup'   => $config->getAppValue(Application::APP_ID, 'createTeamGroup', ''),
        ];
    }

    /**
     * Save admin settings. Requires server admin.
     */
    public function saveAdminSettings(array $settings): void {
        $config = $this->container->get(\OCP\IConfig::class);
        if (isset($settings['wizardDescription'])) {
            $config->setAppValue(Application::APP_ID, 'wizardDescription', (string)$settings['wizardDescription']);
        }
        if (isset($settings['inviteTypes'])) {
            $allowed = ['user', 'group', 'email', 'federated'];
            $types = array_filter(
                array_map('trim', explode(',', (string)$settings['inviteTypes'])),
                fn($t) => in_array($t, $allowed, true)
            );
            if (empty($types)) {
                $types = ['user'];
            }
            $config->setAppValue(Application::APP_ID, 'inviteTypes', implode(',', $types));
        }
        if (isset($settings['pinMinLevel'])) {
            $validLevels = ['member', 'moderator', 'admin'];
            $level = in_array($settings['pinMinLevel'], $validLevels, true)
                ? $settings['pinMinLevel']
                : 'moderator';
            $config->setAppValue(Application::APP_ID, 'pinMinLevel', $level);
        }
        if (array_key_exists('createTeamGroup', $settings)) {
            // Allow empty string (meaning no restriction)
            $config->setAppValue(Application::APP_ID, 'createTeamGroup', trim((string)$settings['createTeamGroup']));
        }
    }

    /**
     * Return the currently allowed invite types as an array.
     * Used by searchUsers() to filter results.
     */
    public function getAllowedInviteTypes(): array {
        $config = $this->container->get(\OCP\IConfig::class);
        $raw = $config->getAppValue(Application::APP_ID, 'inviteTypes', 'user,group');
        return array_filter(array_map('trim', explode(',', $raw)));
    }


    /**
     * Update user-facing config flags on a circle.
     *
     * We write via raw SQL (preserving unmanaged bits), then force Circles to reload
     * the circle from DB by calling getCircle() in a fresh session. This flushes the
     * in-process object cache that Circles holds per-session, so subsequent calls to
     * probeCircles() / getCircle() in the same PHP process return fresh data.
     *
     * Without the reload, the in-process cache retains the pre-write circle object,
     * and any getCircle() call in the same process returns "Circle not found" or the
     * old config — causing the team to appear missing on the next page load.
     *
     * Bits managed by TeamHub:
     *   1    = CFG_OPEN, 2 = CFG_INVITE, 4 = CFG_REQUEST,
     *   16   = CFG_PROTECTED, 512 = CFG_VISIBLE, 1024 = CFG_SINGLE
     * All other bits (e.g. 256 = CFG_PERSONAL, 32768 = CFG_ROOT) are preserved.
     */
    public function updateTeamConfig(string $teamId, int $config): void {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $MANAGED_BITS = 1 | 2 | 4 | 16 | 512 | 1024;

        $db = $this->container->get(\OCP\IDBConnection::class);

        // Read current raw config from DB to preserve unmanaged/internal bits
        $qb = $db->getQueryBuilder();
        $result = $qb->select('config')
            ->from('circles_circle')
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            throw new \Exception('Circle not found in database: ' . $teamId);
        }

        $currentConfig = (int)$row['config'];
        $newConfig = ($currentConfig & ~$MANAGED_BITS) | ($config & $MANAGED_BITS);

        $this->logger->info('updateTeamConfig', [
            'teamId'     => $teamId,
            'before'     => $currentConfig,
            'before_bin' => decbin($currentConfig),
            'incoming'   => $config,
            'after'      => $newConfig,
            'after_bin'  => decbin($newConfig),
            'app'        => Application::APP_ID,
        ]);

        // Write config
        $db->executeStatement(
            'UPDATE `*PREFIX*circles_circle` SET `config` = ? WHERE `unique_id` = ?',
            [$newConfig, $teamId]
        );

        // Flush Circles' in-process object cache by opening a new session and calling
        // getCircle(). Circles re-fetches from DB when the session doesn't have the
        // circle cached yet, which updates the in-process state for this PHP worker.
        try {
            $manager = $this->getCirclesManager();
            $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
            $manager->startSession($federatedUser);
            try {
                $manager->getCircle($teamId);
            } finally {
                $manager->stopSession();
            }
        } catch (\Throwable $e) {
            // Non-fatal — the SQL write succeeded; Circles will reload on next request
            $this->logger->info('updateTeamConfig: cache flush via getCircle failed (non-fatal): ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }

        // Also bust APCu cache
        if (function_exists('apcu_delete') && class_exists('APCUIterator')) {
            try {
                foreach (new \APCUIterator('/^(circles|NC__circles)/') as $item) {
                    apcu_delete($item['key']);
                }
            } catch (\Throwable $e) { /* non-fatal */ }
        }
    }

    /**
     * Get the raw config bitmask for a circle.
     * Reads directly from DB — never from the Circles API object cache,
     * which can return 0 for a stale session and cause the frontend to
     * display all checkboxes unchecked, then wipe bits on the next save.
     */
    public function getTeamConfig(string $teamId): int {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }
        $db = $this->container->get(\OCP\IDBConnection::class);
        $qb = $db->getQueryBuilder();
        $result = $qb->select('config')
            ->from('circles_circle')
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ? (int)$row['config'] : 0;
    }


    /**
     * DEBUG: Show DB config vs probeCircles config for a circle.
     * Call GET /api/v1/debug/circle-config/{teamId} before and after changing config.
     */
    public function debugCircleConfig(string $teamId): array {
        $user = $this->userSession->getUser();
        $db   = $this->container->get(\OCP\IDBConnection::class);

        // 1. Raw DB value
        $qb = $db->getQueryBuilder();
        $res = $qb->select('config', 'unique_id', 'name', 'description')
            ->from('circles_circle')
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $dbRow = $res->fetch();
        $res->closeCursor();

        // 2. What probeCircles returns
        $probeInfo = null;
        $getCircleInfo = null;
        try {
            $manager = $this->getCirclesManager();
            $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
            $manager->startSession($federatedUser);
            try {
                // Via probeCircles
                $circles = $this->probeCircles($manager);
                foreach ($circles as $c) {
                    $id = method_exists($c, 'getSingleId') ? $c->getSingleId() : $c->getId();
                    if ($id === $teamId) {
                        $probeInfo = [
                            'found'      => true,
                            'config'     => method_exists($c, 'getConfig') ? $c->getConfig() : 'no getConfig()',
                            'config_bin' => method_exists($c, 'getConfig') ? decbin((int)$c->getConfig()) : 'n/a',
                            'name'       => method_exists($c, 'getName') ? $c->getName() : 'n/a',
                        ];
                        break;
                    }
                }
                if ($probeInfo === null) {
                    $probeInfo = ['found' => false, 'total_circles_returned' => count($circles)];
                }

                // Via direct getCircle
                try {
                    $circle = $manager->getCircle($teamId);
                    $getCircleInfo = [
                        'found'      => true,
                        'config'     => method_exists($circle, 'getConfig') ? $circle->getConfig() : 'no getConfig()',
                        'config_bin' => method_exists($circle, 'getConfig') ? decbin((int)$circle->getConfig()) : 'n/a',
                    ];
                } catch (\Exception $e) {
                    $getCircleInfo = ['found' => false, 'error' => $e->getMessage()];
                }
            } finally {
                $manager->stopSession();
            }
        } catch (\Exception $e) {
            $probeInfo = ['error' => $e->getMessage()];
        }

        // 3. Raw member rows from DB for this circle
        $qb3 = $db->getQueryBuilder();
        $mres = $qb3->select('*')
            ->from('circles_member')
            ->where($qb3->expr()->eq('circle_id', $qb3->createNamedParameter($teamId)))
            ->executeQuery();
        $memberRows = $mres->fetchAll();
        $mres->closeCursor();

        // 4. Is the current user in the member rows?
        $uid = $user->getUID();
        $currentUserRows = array_filter($memberRows, fn($r) => ($r['user_id'] ?? '') === $uid);

        return [
            'teamId'         => $teamId,
            'currentUser'    => $uid,
            'db'             => $dbRow ? [
                'config'     => (int)$dbRow['config'],
                'config_bin' => decbin((int)$dbRow['config']),
                'name'       => $dbRow['name'],
            ] : ['found' => false],
            'probeCircles'   => $probeInfo,
            'getCircle'      => $getCircleInfo,
            'db_members'     => array_map(fn($r) => [
                'user_id'     => $r['user_id']     ?? '?',
                'user_type'   => $r['user_type']   ?? '?',
                'level'       => $r['level']        ?? '?',
                'status'      => $r['status']       ?? '?',
                'instance'    => $r['instance']     ?? '',
                'single_id'   => $r['single_id']   ?? '',
            ], $memberRows),
            'currentUserInMembers' => count($currentUserRows) > 0,
            'currentUserMemberRows' => array_values($currentUserRows),
        ];
    }

    /**
     * DEBUG: Dump all circles from DB and from probeCircles side by side.
     * Lets us see exactly which config values probeCircles filters out.
     * GET /api/v1/debug/all-circles
     */
    public function debugAllCircles(): array {
        $user = $this->userSession->getUser();
        $db   = $this->container->get(\OCP\IDBConnection::class);

        // All circles_circle rows in DB where current user is a member
        $qb = $db->getQueryBuilder();
        $res = $qb->select('c.unique_id', 'c.name', 'c.config', 'm.level', 'm.status', 'm.user_type')
            ->from('circles_circle', 'c')
            ->join('c', 'circles_member', 'm', 'c.unique_id = m.circle_id')
            ->where($qb->expr()->eq('m.user_id', $qb->createNamedParameter($user->getUID())))
            ->andWhere($qb->expr()->eq('m.user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        $dbCircles = [];
        while ($row = $res->fetch()) {
            $cfg = (int)$row['config'];
            $dbCircles[$row['unique_id']] = [
                'name'       => $row['name'],
                'config'     => $cfg,
                'config_bin' => decbin($cfg),
                'member_level'  => $row['level'],
                'member_status' => $row['status'],
                'in_probe'   => false, // filled below
            ];
        }
        $res->closeCursor();

        // What probeCircles returns
        $probeIds = [];
        try {
            $manager = $this->getCirclesManager();
            $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
            $manager->startSession($federatedUser);
            try {
                $circles = $this->probeCircles($manager);
                foreach ($circles as $c) {
                    $id = method_exists($c, 'getSingleId') ? $c->getSingleId() : $c->getId();
                    $probeIds[] = $id;
                    if (isset($dbCircles[$id])) {
                        $dbCircles[$id]['in_probe'] = true;
                    }
                }
            } finally {
                $manager->stopSession();
            }
        } catch (\Throwable $e) {
            // non-fatal
        }

        return [
            'user'           => $user->getUID(),
            'probe_count'    => count($probeIds),
            'db_count'       => count($dbCircles),
            'circles'        => array_values(array_map(
                fn($id, $c) => array_merge(['id' => $id], $c),
                array_keys($dbCircles), $dbCircles
            )),
        ];
    }

    /**
     * in DB but is invisible to the Circles API (symptom: probeCircles and getCircle
     * both return "not found" even though circles_circle row exists).
     *
     * Root cause: circles_member row is missing, has status=0, or points to wrong instance.
     * Fix: insert/update a member row with level=9 (owner), status=1 (member), user_type=1 (local).
     *
     * The single_id for the member row is a separate Circles-internal ID — we generate
     * a new one using the same format Circles uses (21-char alphanumeric).
     */
    public function repairCircleMembership(string $teamId): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }
        $uid = $user->getUID();
        $db  = $this->container->get(\OCP\IDBConnection::class);

        // 1. Confirm circle exists
        $qb = $db->getQueryBuilder();
        $res = $qb->select('unique_id', 'name', 'config')
            ->from('circles_circle')
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $circleRow = $res->fetch();
        $res->closeCursor();
        if (!$circleRow) {
            return ['error' => 'Circle not found in DB'];
        }

        // 2. Read existing member rows
        $qb2 = $db->getQueryBuilder();
        $mres = $qb2->select('*')
            ->from('circles_member')
            ->where($qb2->expr()->eq('circle_id', $qb2->createNamedParameter($teamId)))
            ->executeQuery();
        $existingMembers = $mres->fetchAll();
        $mres->closeCursor();

        // 3. Find current user's row
        $myRow = null;
        foreach ($existingMembers as $r) {
            if (($r['user_id'] ?? '') === $uid && (int)($r['user_type'] ?? 0) === 1) {
                $myRow = $r;
                break;
            }
        }

        $action = 'none';

        if ($myRow === null) {
            // Insert a new owner member row
            // Generate a Circles-style single_id: 21 alphanumeric chars
            $chars   = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $singleId = '';
            for ($i = 0; $i < 21; $i++) {
                $singleId .= $chars[random_int(0, strlen($chars) - 1)];
            }

            // Get NC instance id for the instance field
            $instanceId = '';
            try {
                $instanceId = $this->container->get(\OCP\IConfig::class)
                    ->getSystemValue('instanceid', '');
            } catch (\Throwable $e) {}

            $repairCols  = array_flip($this->getTableColumns('circles_member'));
            $qbI = $db->getQueryBuilder();
            $repairValues = [
                'single_id'  => $qbI->createNamedParameter($singleId),
                'circle_id'  => $qbI->createNamedParameter($teamId),
                'user_id'    => $qbI->createNamedParameter($uid),
                'user_type'  => $qbI->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                'member_id'  => $qbI->createNamedParameter($uid),
                'instance'   => $qbI->createNamedParameter(''),
                'level'      => $qbI->createNamedParameter(9, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                'status'     => $qbI->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                'joined'     => $qbI->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
            ];
            $repairOptional = [
                'display_name' => $qbI->createNamedParameter($user->getDisplayName() ?: $uid),
                'cached_name'  => $qbI->createNamedParameter($user->getDisplayName() ?: $uid),
                'note'         => $qbI->createNamedParameter(''),
                'contact_id'   => $qbI->createNamedParameter(''),
                'contact_meta' => $qbI->createNamedParameter(''),
            ];
            foreach ($repairOptional as $col => $val) {
                if (isset($repairCols[$col])) {
                    $repairValues[$col] = $val;
                }
            }
            $qbI->insert('circles_member')->values($repairValues)->executeStatement();
            $action = 'inserted_owner_row';
        } elseif ((int)($myRow['level'] ?? 0) < 9 || (int)($myRow['status'] ?? 0) !== 1) {
            // Fix existing row: set level=9 (owner), status=1 (active member)
            $db->executeStatement(
                'UPDATE `*PREFIX*circles_member` SET `level` = 9, `status` = 1 WHERE `circle_id` = ? AND `user_id` = ? AND `user_type` = 1',
                [$teamId, $uid]
            );
            $action = 'repaired_existing_row (was level=' . $myRow['level'] . ' status=' . $myRow['status'] . ')';
        } else {
            $action = 'row_ok_level=' . $myRow['level'] . '_status=' . $myRow['status'] . ' — circle may be broken for another reason';
        }

        // 4. Bust APCu cache
        if (function_exists('apcu_delete') && class_exists('APCUIterator')) {
            try {
                foreach (new \APCUIterator('/^(circles|NC__circles)/') as $item) {
                    apcu_delete($item['key']);
                }
            } catch (\Throwable $e) {}
        }

        // 5. Verify via Circles API now
        $circlesVisible = false;
        try {
            $manager = $this->getCirclesManager();
            $federatedUser = $manager->getFederatedUser($uid, 1);
            $manager->startSession($federatedUser);
            try {
                $circle = $manager->getCircle($teamId);
                $circlesVisible = $circle !== null;
            } finally {
                $manager->stopSession();
            }
        } catch (\Throwable $e) {
            // still not visible
        }

    }

    /**
     * DEBUG: Show raw activity table rows relevant to a team's resources.
     * Dumps what object_type/object_id/file values NC actually stores so we
     * can tune the getTeamActivity() query to match them.
     * GET /api/v1/debug/activity/{teamId}
     */
    public function debugActivity(string $teamId): array {
        $db        = $this->container->get(\OCP\IDBConnection::class);
        $resources = $this->getTeamResources($teamId);

        // Show what resource IDs we resolved
        $resolved = [
            'circle_id'  => $teamId,
            'folder_id'  => $resources['files']['folder_id'] ?? null,
            'folder_path'=> $resources['files']['path'] ?? null,
            'deck_board' => $resources['deck']['board_id'] ?? null,
            'calendar_id'=> $resources['calendar']['id'] ?? null,
            'talk_token' => $resources['talk']['token'] ?? null,
        ];

        // Dump raw share rows for this team so we can diagnose null folder_id
        $shareQb = $db->getQueryBuilder();
        $shareRes = $shareQb->select('id', 'share_type', 'share_with', 'item_type', 'item_source', 'file_source', 'file_target', 'uid_owner')
            ->from('share')
            ->where($shareQb->expr()->eq('share_with', $shareQb->createNamedParameter($teamId)))
            ->setMaxResults(10)
            ->executeQuery();
        $shareRows = $shareRes->fetchAll();
        $shareRes->closeCursor();

        // Grab the 30 most recent activity rows for any of the user's files app events
        // so we can see exactly what object_type/object_id/file NC uses
        $qb = $db->getQueryBuilder();
        $res = $qb->select('activity_id', 'app', 'type', 'user', 'object_type', 'object_id', 'file', 'subject', 'timestamp')
            ->from('activity')
            ->where($qb->expr()->orX(
                $qb->expr()->eq('app', $qb->createNamedParameter('files')),
                $qb->expr()->eq('app', $qb->createNamedParameter('files_sharing')),
                $qb->expr()->eq('app', $qb->createNamedParameter('deck')),
                $qb->expr()->eq('app', $qb->createNamedParameter('calendar')),
                $qb->expr()->eq('app', $qb->createNamedParameter('dav')),
                $qb->expr()->eq('app', $qb->createNamedParameter('spreed')),
                $qb->expr()->eq('app', $qb->createNamedParameter('circles'))
            ))
            ->orderBy('timestamp', 'DESC')
            ->setMaxResults(30)
            ->executeQuery();
        $recentRows = $res->fetchAll();
        $res->closeCursor();

        // Also check filecache to confirm folder_id is correct
        $folderInfo = null;
        if (!empty($resources['files']['folder_id'])) {
            $fcQb = $db->getQueryBuilder();
            $fcRes = $fcQb->select('fileid', 'path', 'name', 'parent')
                ->from('filecache')
                ->where($fcQb->expr()->eq('fileid', $fcQb->createNamedParameter(
                    (int)$resources['files']['folder_id'],
                    \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT
                )))
                ->setMaxResults(1)
                ->executeQuery();
            $folderInfo = $fcRes->fetch() ?: null;
            $fcRes->closeCursor();
        }

        return [
            'resolved_resources'  => $resolved,
            'share_rows_for_team' => $shareRows,
            'folder_in_filecache' => $folderInfo,
            'recent_activity_rows' => array_map(fn($r) => [
                'activity_id' => $r['activity_id'],
                'app'         => $r['app'],
                'type'        => $r['type'],
                'user'        => $r['user'],
                'object_type' => $r['object_type'],
                'object_id'   => $r['object_id'],
                'file'        => $r['file'],
                'subject'     => substr($r['subject'] ?? '', 0, 80),
                'timestamp'   => date('Y-m-d H:i:s', (int)$r['timestamp']),
            ], $recentRows),
        ];
    }

    /**
     */
    public function debugResourceTables(): array {
        $db = $this->container->get(\OCP\IDBConnection::class);
        $tables = ['talk_rooms', 'talk_attendees', 'deck_boards', 'deck_stacks', 'deck_board_acl', 'circles_circle', 'circles_member'];
        $result = [];
        foreach ($tables as $table) {
            try {
                $cols = $this->getTableColumns($table);
                $result[$table] = !empty($cols) ? $cols : ['(no columns found — table may not exist)'];
            } catch (\Exception $e) {
                $result[$table] = ['error' => $e->getMessage()];
            }
        }
        return $result;
    }


    /**
     * Return the column names for a (un-prefixed) table name.
     *
     * Uses Doctrine DBAL AbstractSchemaManager::listTableColumns() first (NC32 compatible).
     * Falls back to an INFORMATION_SCHEMA query on platforms that don't expose the schema manager.
     * Returns an empty array when the table doesn't exist — callers skip optional columns gracefully.
     *
     * @param string $table Un-prefixed table name, e.g. 'talk_rooms'
     * @return string[]
     */
    /**
     * Return the column names for a (un-prefixed) table name.
     *
     * NC32 IDBConnection (OC\DB\ConnectionAdapter) does not expose getPrefix().
     * We resolve the prefix by fetching one row from the table via a SELECT *
     * LIMIT 0 query and introspecting the result with the DBAL SchemaManager,
     * OR falling back to INFORMATION_SCHEMA on MySQL/MariaDB/PostgreSQL.
     *
     * The QueryBuilder's ->from() already applies the prefix automatically,
     * so we use that to introspect columns — we never need the raw prefix here.
     *
     * @param string $table Un-prefixed table name, e.g. 'talk_rooms'
     * @return string[]
     */
    private function getTableColumns(string $table): array {
        $db = $this->container->get(\OCP\IDBConnection::class);

        // ── Strategy 1: DBAL SchemaManager via inner connection ───────────────
        // IDBConnection wraps a real Doctrine\DBAL\Connection — get it via
        // getInner() (NC26+) or by casting. Works on NC32.
        try {
            $inner = method_exists($db, 'getInner') ? $db->getInner() : null;
            if ($inner instanceof \Doctrine\DBAL\Connection) {
                $sm = $inner->createSchemaManager();
                // Resolve the actual prefixed table name using the QB (safe, platform-agnostic)
                $qb = $db->getQueryBuilder();
                // getSQL() on a SELECT lets us extract the real table name the QB uses
                $testSql = $qb->select('*')->from($table)->setMaxResults(0)->getSQL();
                // Extract the first FROM token: FROM `oc_talk_rooms` or FROM "oc_talk_rooms"
                if (preg_match('/FROM\s+["`]?(\S+?)["`]?\s/i', $testSql, $m)) {
                    $fullTable = trim($m[1], '"`');
                    $columns = $sm->listTableColumns($fullTable);
                    return array_keys($columns);
                }
            }
        } catch (\Throwable $e) {
            // Fall through — SchemaManager path failed
        }

        // ── Strategy 2: SELECT * LIMIT 0 + PDO getColumnMeta alternative ─────
        // Fetch column names directly from the result set metadata via
        // iterateAssociative() — the first row keys ARE the column names.
        // (Works on any DB since it's just a zero-row SELECT.)
        try {
            $qb = $db->getQueryBuilder();
            $result = $qb->select('*')->from($table)->setMaxResults(1)->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            if (is_array($row)) {
                return array_keys($row);
            }
            // Table exists but is empty — we can't infer columns this way
        } catch (\Throwable $e) {
            // Table likely doesn't exist; fall through
        }

        // ── Strategy 3: INFORMATION_SCHEMA (MySQL/MariaDB/PostgreSQL) ─────────
        try {
            // Get the real prefixed table name from the QB SQL
            $qb = $db->getQueryBuilder();
            $sql = $qb->select('id')->from($table)->setMaxResults(0)->getSQL();
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
            $this->logger->warning('getTableColumns: all strategies failed', [
                'table' => $table,
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
        }

        return [];
    }

    /**
     * DEBUG — returns all public methods on CirclesManager plus Circle class.
     * Also attempts createCircle so we can see what Circle methods exist.
     */
    public function debugCirclesMethods(): array {
        $manager = $this->getCirclesManager();
        $managerMethods = get_class_methods($manager);
        sort($managerMethods);

        $user = $this->userSession->getUser();
        $federatedUser = $manager->getFederatedUser($user->getUID(), 1);
        $manager->startSession($federatedUser);

        $circleMethods = [];
        $circleClass = null;
        try {
            $circle = $manager->createCircle('__debug_delete_me__');
            $circleClass = get_class($circle);
            $circleMethods = get_class_methods($circle);
            sort($circleMethods);
            // Clean up
            try { $manager->destroyCircle($circle->getSingleId()); } catch (\Exception $e) {}
        } catch (\Exception $e) {
            $circleMethods = ['error: ' . $e->getMessage()];
        } finally {
            $manager->stopSession();
        }

        return [
            'circlesManagerClass' => get_class($manager),
            'circlesManagerMethods' => $managerMethods,
            'circleClass' => $circleClass,
            'circleMethods' => $circleMethods,
        ];
    }
}
