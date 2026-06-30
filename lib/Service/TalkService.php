<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * TalkService — Talk room creation and deletion for TeamHub teams.
 *
 * Extracted from ResourceService in v3.2.0.
 * Uses three strategies in order: RoomService API (Talk 17+),
 * Manager API (Talk 13-16), direct DB insert (fallback).
 */
class TalkService {

    public function __construct(
        private IUserSession $userSession,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private DbIntrospectionService $dbIntrospection,
    ) {}

    public function createTalkRoom(string $teamId, string $teamName, string $uid): array {

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
            // addCircle() requires a \OCA\Circles\Model\Circle object (Talk 17+), not the
            // circle ID string. resolveCircle() handles the FederatedUserService setup.
            //
            // DESIGN: when addCircle() succeeds via Talk's own API, Talk registers the
            // circle with its internal participant system. getRoomsForUser() then resolves
            // circle membership natively — no individual talk_attendees rows are needed,
            // and new team members see the room automatically as they join the circle.
            //
            // When addCircle() fails, we fall back to a direct DB insert. In that path,
            // Talk's event system was never involved, so circle membership is NOT resolved
            // for the conversation list. expandCircleMembersToTalk() is called as a
            // safety net only on that fallback path.
            $circleLinked    = false;
            $usedTalkApi     = false;
            try {
                $participantService = $this->container->get(\OCA\Talk\Service\ParticipantService::class);
                $circle = $this->resolveCircle($teamId, $uid);
                if ($circle === null) {
                    throw new \Exception('Circle could not be resolved — using direct DB fallback');
                }
                $participantService->addCircle($room, $circle);
                $circleLinked = true;
                $usedTalkApi  = true;
                error_log('[TeamHub][TalkService] Talk S1: addCircle succeeded via API — circle membership resolved natively by Talk');
            } catch (\Throwable $e) {
                $this->logger->warning('[TalkService] Talk S1: ParticipantService::addCircle failed — using direct DB fallback', [
                    'error' => $e->getMessage(),
                    'app'   => Application::APP_ID,
                ]);
            }

            if (!$circleLinked && $roomId !== null) {
                $circleLinked = $this->insertTalkCircleAttendee($roomId, $teamId, $teamName, $db);
            }

            if ($roomId !== null && $circleLinked) {
                $this->promoteTalkCircleToModerator($roomId, $teamId, $db);

                if (!$usedTalkApi) {
                    // Direct DB fallback path: Talk was not notified via its API so it
                    // will not resolve circle membership for the conversation list.
                    // Expand individual attendee rows as a safety net so users can at
                    // least find the room. New members added later will be in the same
                    // situation until the room is reconnected.
                    error_log('[TeamHub][TalkService] Talk S1: using fallback expansion (API path failed)');
                    $this->expandCircleMembersToTalk($roomId, $teamId, $db);
                }
            }

            return ['token' => $token, 'name' => $teamName, 'circle_added' => $circleLinked];

        } catch (\Throwable $e) {
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

            // Add circle via ParticipantService; fall back to direct DB insert on failure.
            // Same API-vs-fallback distinction as Strategy 1 — see comments there.
            $circleLinked = false;
            $usedTalkApi  = false;
            try {
                $participantService = $this->container->get(\OCA\Talk\Service\ParticipantService::class);
                $circle = $this->resolveCircle($teamId, $uid);
                if ($circle === null) {
                    throw new \Exception('Circle could not be resolved — using direct DB fallback');
                }
                $participantService->addCircle($room, $circle);
                $circleLinked = true;
                $usedTalkApi  = true;
                error_log('[TeamHub][TalkService] Talk S2: addCircle succeeded via API');
            } catch (\Throwable $e) {
                $this->logger->warning('[TalkService] Talk S2: Manager addCircle failed — using direct DB fallback', [
                    'error' => $e->getMessage(),
                    'app'   => Application::APP_ID,
                ]);
            }

            if (!$circleLinked && $roomId !== null) {
                $circleLinked = $this->insertTalkCircleAttendee($roomId, $teamId, $teamName, $db);
            }

            if ($roomId !== null && $circleLinked) {
                $this->promoteTalkCircleToModerator($roomId, $teamId, $db);
                if (!$usedTalkApi) {
                    error_log('[TeamHub][TalkService] Talk S2: using fallback expansion (API path failed)');
                    $this->expandCircleMembersToTalk($roomId, $teamId, $db);
                }
            }

            return ['token' => $token, 'name' => $teamName, 'circle_added' => true];

        } catch (\Throwable $e) {
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
            $roomCols = $this->dbIntrospection->getTableColumns('talk_rooms');
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
            $attendeeCols = $this->dbIntrospection->getTableColumns('talk_attendees');
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

            // Strategy 3 bypasses Talk's API entirely — Talk will not resolve circle
            // membership for the conversation list. Expand individual attendee rows as a
            // safety net so users can find the room. New members added later won't be
            // automatically synced; the room should be reconnected after Talk API support
            // is confirmed (Strategies 1/2).
            error_log('[TeamHub][TalkService] Talk S3: direct DB path — expanding individual members as fallback');
            $this->expandCircleMembersToTalk($roomId, $teamId, $db);

            return ['token' => $token, 'name' => $teamName, 'circle_added' => true];

        } catch (\Throwable $e) {
            $this->logger->error('[TalkService] Talk: all strategies failed', [
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
    /**
     * List Talk rooms where $uid is owner or moderator and which are eligible
     * to be connected to a team. Excludes one-on-one chats and changelog rooms.
     *
     * Caller should pass the current NC user's UID — this method does no auth itself.
     *
     * @return array<int, array{id:int, token:string, name:string, type:int}>
     */
    public function listOwnedRooms(string $uid): array {
        if (!$this->appManager->isInstalled('spreed')) {
            return [];
        }

        try {
            $db = $this->container->get(\OCP\IDBConnection::class);
            $qb = $db->getQueryBuilder();

            // Talk participant_type: 1=OWNER, 2=MODERATOR, 6=GUEST_MODERATOR.
            // We want rooms the user can administer.
            // Talk room type: 1=ONE_TO_ONE, 2=GROUP, 3=PUBLIC, 4=CHANGELOG, 5=ONE_TO_ONE_FORMER, 6=NOTE_TO_SELF.
            // We exclude 1 (one-to-one), 4 (changelog), 5, 6.
            $qb->select('r.id', 'r.token', 'r.name', 'r.type')
                ->from('talk_rooms', 'r')
                ->innerJoin('r', 'talk_attendees', 'a',
                    $qb->expr()->andX(
                        $qb->expr()->eq('a.room_id', 'r.id'),
                        $qb->expr()->eq('a.actor_type', $qb->createNamedParameter('users')),
                        $qb->expr()->eq('a.actor_id', $qb->createNamedParameter($uid)),
                        $qb->expr()->in('a.participant_type', $qb->createNamedParameter(
                            [1, 2, 6],
                            \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY
                        ))
                    )
                )
                ->where($qb->expr()->in('r.type', $qb->createNamedParameter(
                    [2, 3],
                    \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY
                )))
                ->orderBy('r.name', 'ASC');

            $res = $qb->executeQuery();
            $rows = $res->fetchAll();
            $res->closeCursor();

            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'id'    => (int)$row['id'],
                    'token' => (string)($row['token'] ?? ''),
                    'name'  => (string)($row['name'] ?? ''),
                    'type'  => (int)($row['type'] ?? 0),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            $this->logger->error('[TalkService] listOwnedRooms failed', [
                'uid' => $uid, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Connect an existing Talk room to a team by adding the team's circle as
     * a moderator-level attendee.
     *
     * SECURITY: Caller MUST verify the user has team-admin level. This method
     * additionally verifies that $uid actually owns/moderates the room —
     * preventing forged roomId attacks.
     *
     * Refuses to connect if the team's circle is already an attendee anywhere.
     *
     * @return array{success:bool, room_id?:int, token?:string, name?:string, error?:string}
     */
    public function connectExistingRoom(string $teamId, int $roomId, string $uid): array {
        if (!$this->appManager->isInstalled('spreed')) {
            return ['success' => false, 'error' => 'Talk (spreed) app not installed'];
        }

        try {
            $db = $this->container->get(\OCP\IDBConnection::class);

            // SECURITY: verify the room exists and the user owns/moderates it.
            $qb = $db->getQueryBuilder();
            $res = $qb->select('r.id', 'r.token', 'r.name')
                ->from('talk_rooms', 'r')
                ->innerJoin('r', 'talk_attendees', 'a',
                    $qb->expr()->andX(
                        $qb->expr()->eq('a.room_id', 'r.id'),
                        $qb->expr()->eq('a.actor_type', $qb->createNamedParameter('users')),
                        $qb->expr()->eq('a.actor_id', $qb->createNamedParameter($uid)),
                        $qb->expr()->in('a.participant_type', $qb->createNamedParameter(
                            [1, 2, 6],
                            \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY
                        ))
                    )
                )
                ->where($qb->expr()->eq('r.id', $qb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if (!$row) {
                return ['success' => false, 'error' => 'Room not found or user is not owner/moderator'];
            }

            $token    = (string)($row['token'] ?? '');
            $roomName = (string)($row['name'] ?? '');

            // Refuse if this circle is already an attendee in any room — TeamHub
            // model is one Talk room per team.
            $chk = $db->getQueryBuilder();
            $cres = $chk->select('room_id')
                ->from('talk_attendees')
                ->where($chk->expr()->eq('actor_type', $chk->createNamedParameter('circles')))
                ->andWhere($chk->expr()->eq('actor_id', $chk->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $existing = $cres->fetch();
            $cres->closeCursor();

            if ($existing) {
                return ['success' => false, 'error' => 'Team already has a Talk room — disable the current one first'];
            }

            // Strategy 1: ParticipantService::addCircle (Talk 17+)
            // addCircle() requires a Circle object — resolve it first.
            // When this succeeds, Talk manages circle membership natively.
            $circleAdded = false;
            $usedTalkApi = false;
            try {
                $manager = $this->container->get(\OCA\Talk\Manager::class);
                $room    = $manager->getRoomById($roomId);
                $participantService = $this->container->get(\OCA\Talk\Service\ParticipantService::class);
                $circle = $this->resolveCircle($teamId, $uid);
                if ($circle === null) {
                    throw new \Exception('Circle could not be resolved — using direct DB insert');
                }
                $participantService->addCircle($room, $circle);
                $circleAdded = true;
                $usedTalkApi = true;
                error_log('[TeamHub][TalkService] connectExistingRoom: addCircle succeeded via API — Talk resolves membership natively');
            } catch (\Throwable $e) {
                $this->logger->warning('[TalkService] connectExistingRoom: ParticipantService::addCircle failed — using direct DB insert', [
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            // Strategy 2: direct DB insert (fallback when API unavailable)
            if (!$circleAdded) {
                $circleAdded = $this->insertTalkCircleAttendee($roomId, $teamId, $roomName, $db);
            }

            if (!$circleAdded) {
                return ['success' => false, 'error' => 'Failed to add team circle as room attendee'];
            }

            // Promote circle attendee to MODERATOR.
            $this->promoteTalkCircleToModerator($roomId, $teamId, $db);

            if (!$usedTalkApi) {
                // Fallback path: Talk was not notified via its API, so it won't resolve
                // circle membership for the conversation list. Expand individual attendee
                // rows as a safety net. New members added to the team after this point
                // will need a room reconnect to gain Talk sidebar visibility.
                error_log('[TeamHub][TalkService] connectExistingRoom: using fallback expansion (API path failed)');
                $this->expandCircleMembersToTalk($roomId, $teamId, $db);
            }

            return [
                'success'      => true,
                'room_id'      => $roomId,
                'token'        => $token,
                'name'         => $roomName,
                'via_talk_api' => $usedTalkApi,
            ];

        } catch (\Throwable $e) {
            $this->logger->error('[TalkService] connectExistingRoom failed', [
                'teamId' => $teamId, 'roomId' => $roomId,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['success' => false, 'error' => 'Operation failed — see server log for details'];
        }
    }

    public function insertTalkCircleAttendee(int $roomId, string $teamId, string $teamName, \OCP\IDBConnection $db): bool {
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
                return true;
            }

            $attendeeCols = $this->dbIntrospection->getTableColumns('talk_attendees');
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

            return true;

        } catch (\Throwable $e) {
            $this->logger->error('[TalkService] insertTalkCircleAttendee failed', [
                'roomId' => $roomId, 'teamId' => $teamId,
                'error'  => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    public function promoteTalkCircleToModerator(int $roomId, string $teamId, \OCP\IDBConnection $db): void {
        try {
            $uqb = $db->getQueryBuilder();
            $affected = $uqb->update('talk_attendees')
                ->set('participant_type', $uqb->createNamedParameter(2)) // MODERATOR
                ->where($uqb->expr()->eq('room_id',    $uqb->createNamedParameter($roomId)))
                ->andWhere($uqb->expr()->eq('actor_type', $uqb->createNamedParameter('circles')))
                ->andWhere($uqb->expr()->eq('actor_id',   $uqb->createNamedParameter($teamId)))
                ->executeStatement();

        } catch (\Throwable $e) {
            // Non-fatal: room still works, but circle members won't have mod rights
            $this->logger->warning('[TalkService] Talk: promoteTalkCircleToModerator failed', [
                'roomId' => $roomId,
                'teamId' => $teamId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
        }
    }

    /**
     * Suspend team access to the Talk room by removing the circle attendee row.
     * The room and all messages remain intact. Only the circle entry is removed,
     * so individual user attendee rows are untouched.
     *
     * Returns the room_id for storage in suspended_resources, or null if no
     * Talk room exists for this team.
     */
    public function suspendTalkAccess(string $teamId, \OCP\IDBConnection $db): ?int {
        if (!$this->appManager->isInstalled('spreed')) {
            return null;
        }
        try {
            $qb  = $db->getQueryBuilder();
            $res = $qb->select('room_id')
                ->from('talk_attendees')
                ->where($qb->expr()->eq('actor_type', $qb->createNamedParameter('circles')))
                ->andWhere($qb->expr()->eq('actor_id',   $qb->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if (!$row) {
                return null;
            }

            $roomId = (int)$row['room_id'];

            // Remove only the circle attendee row — individual users keep their rows.
            $dqb = $db->getQueryBuilder();
            $dqb->delete('talk_attendees')
                ->where($dqb->expr()->eq('actor_type', $dqb->createNamedParameter('circles')))
                ->andWhere($dqb->expr()->eq('actor_id',   $dqb->createNamedParameter($teamId)))
                ->andWhere($dqb->expr()->eq('room_id',    $dqb->createNamedParameter($roomId)))
                ->executeStatement();

            $this->logger->debug('[TalkService] suspendTalkAccess: circle attendee removed', [
                'teamId' => $teamId, 'roomId' => $roomId, 'app' => Application::APP_ID,
            ]);

            return $roomId;
        } catch (\Throwable $e) {
            $this->logger->error('[TalkService] suspendTalkAccess failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
    }

    /**
     * Resume team access to the Talk room by re-inserting the circle attendee row.
     * Uses the existing insertTalkCircleAttendee() which is idempotent.
     */
    public function resumeTalkAccess(int $roomId, string $teamId, string $teamName, \OCP\IDBConnection $db): bool {
        if (!$this->appManager->isInstalled('spreed')) {
            return false;
        }
        try {
            return $this->insertTalkCircleAttendee($roomId, $teamId, $teamName, $db);
        } catch (\Throwable $e) {
            $this->logger->error('[TalkService] resumeTalkAccess failed', [
                'teamId' => $teamId, 'roomId' => $roomId, 'error' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * Remove the team's circle access from a specific Talk room (by token).
     * Deletes only the circle attendee row — individual user rows are untouched.
     * The room itself is preserved; the team loses visibility in TeamHub.
     */
    public function removeRoomAccess(string $teamId, string $token, \OCP\IDBConnection $db): bool {
        try {
            // Find the room_id for this token.
            $qb  = $db->getQueryBuilder();
            $res = $qb->select('id')
                ->from('talk_rooms')
                ->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if (!$row) {
                $this->logger->warning('[TalkService] removeRoomAccess: room not found', [
                    'token' => $token, 'app' => Application::APP_ID,
                ]);
                return false;
            }
            $roomId = (int)$row['id'];

            $dqb = $db->getQueryBuilder();
            $affected = $dqb->delete('talk_attendees')
                ->where($dqb->expr()->eq('room_id',    $dqb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($dqb->expr()->eq('actor_type', $dqb->createNamedParameter('circles')))
                ->andWhere($dqb->expr()->eq('actor_id',   $dqb->createNamedParameter($teamId)))
                ->executeStatement();

            $this->logger->debug('[TalkService] removeRoomAccess: circle attendee removed', [
                'teamId' => $teamId, 'token' => $token, 'roomId' => $roomId,
                'affected' => $affected, 'app' => Application::APP_ID,
            ]);
            return $affected > 0;
        } catch (\Throwable $e) {
            $this->logger->error('[TalkService] removeRoomAccess failed', [
                'teamId' => $teamId, 'token' => $token,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * Create a folder in the user's files and share it with the circle.
     */
    /**
     * Delete a specific Talk room by token (multi-resource-aware).
     * Looks up the room_id from the token then deletes attendees + room.
     */
    public function deleteRoomById(string $token, \OCP\IDBConnection $db): array {
        try {
            $qb  = $db->getQueryBuilder();
            $res = $qb->select('id')->from('talk_rooms')
                ->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
                ->setMaxResults(1)->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if (!$row) {
                return ['deleted' => false, 'detail' => "Room with token {$token} not found"];
            }
            $roomId = (int)$row['id'];

            $db->getQueryBuilder()->delete('talk_attendees')
                ->where($db->getQueryBuilder()->expr()->eq('room_id', $db->getQueryBuilder()->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $db->getQueryBuilder()->delete('talk_rooms')
                ->where($db->getQueryBuilder()->expr()->eq('id', $db->getQueryBuilder()->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $this->logger->info('[TalkService] deleteRoomById: room deleted', [
                'token' => $token, 'roomId' => $roomId, 'app' => Application::APP_ID,
            ]);
            return ['deleted' => true, 'token' => $token];
        } catch (\Throwable $e) {
            $this->logger->error('[TalkService] deleteRoomById failed', [
                'token' => $token, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['deleted' => false, 'detail' => $e->getMessage()];
        }
    }

    public function deleteTalkRoom(string $teamId, \OCP\IDBConnection $db): array {
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

            return ['deleted' => true, 'detail' => "Talk room {$roomId} deleted"];

        } catch (\Throwable $e) {
            $this->logger->error('[TalkService] deleteTalkRoom failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['deleted' => false, 'detail' => 'Operation failed — see server log for details'];
        }
    }

    /**
     * Post a plain-text chat message to a Talk room on behalf of a user.
     *
     * @param string $token  Talk room token
     * @param string $uid    User ID of the sender
     * @param string $message  Message text (plain text, max ~32000 chars)
     * @return bool  True on success, false on any failure (non-fatal)
     */
    public function postChatMessage(string $token, string $uid, string $message): bool {
        $this->logger->debug('[TalkService] postChatMessage — start', [
            'token' => $token, 'uid' => $uid, 'app' => Application::APP_ID,
        ]);

        if (!$this->appManager->isInstalled('spreed')) {
            $this->logger->warning('[TalkService] postChatMessage — spreed not installed', [
                'app' => Application::APP_ID,
            ]);
            return false;
        }

        try {
            $manager     = $this->container->get(\OCA\Talk\Manager::class);
            $room        = $manager->getRoomByToken($token);

            if (!$room) {
                $this->logger->warning('[TalkService] postChatMessage — room not found', [
                    'token' => $token, 'app' => Application::APP_ID,
                ]);
                return false;
            }

            $participantService = $this->container->get(\OCA\Talk\Service\ParticipantService::class);
            // getParticipant(Room $room, ?string $userId) — pass UID string, not User object
            $participant = $participantService->getParticipant($room, $uid);

            $chatManager = $this->container->get(\OCA\Talk\Chat\ChatManager::class);
            $chatManager->sendMessage(
                $room,
                $participant,
                'users',
                $uid,
                $message,
                new \DateTime(),
                null,   // replyTo
                '',     // referenceId
                false   // silent
            );

            $this->logger->debug('[TalkService] postChatMessage — success', [
                'token' => $token, 'app' => Application::APP_ID,
            ]);

            return true;

        } catch (\Throwable $e) {
            $this->logger->warning('[TalkService] postChatMessage failed', [
                'token' => $token, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * Delete the shared Files folder for this team.
     * Removes the IShare record AND deletes the folder node itself.
     */

    // =========================================================================
    // Per-member Talk sync
    // =========================================================================

    /**
     * Add a single user to the Talk room connected to $teamId.
     *
     * Called from MemberService whenever a new member becomes active:
     *  - direct invite accepted (inviteMembers, user_type=1)
     *  - join request approved  (approveRequest)
     *
     * The room is identified by the circle attendee row already present in
     * talk_attendees (actor_type='circles', actor_id=$teamId). If no such row
     * exists the team has no Talk room — call is a no-op.
     *
     * Idempotent: if the user already has an attendee row, nothing is written.
     *
     * Non-fatal: any failure is logged but does not bubble up. The user can
     * still access the room via the TeamHub tab (direct token link); they just
     * won't see it in their own Talk sidebar until the next room reconnect.
     *
     * Note on groups: when an admin adds a *group* to a team (user_type=2/16),
     * there is no single UID available at call time. Those members are covered
     * by expandCircleMembersToTalk() at room-connection time, and will be
     * synced on the next room reconnect. A dedicated group-expansion path is
     * noted in HANDOFF open issues.
     */
    public function syncUserToTeamTalkRoom(string $teamId, string $uid): void {
        if (!$this->appManager->isInstalled('spreed')) {
            return;
        }

        error_log('[TeamHub][TalkService] syncUserToTeamTalkRoom: teamId=' . $teamId . ' uid=' . $uid);

        try {
            $db = $this->container->get(\OCP\IDBConnection::class);

            // ── 1. Find the Talk room connected to this team ──────────────────
            $qb  = $db->getQueryBuilder();
            $res = $qb->select('room_id')
                ->from('talk_attendees')
                ->where($qb->expr()->eq('actor_type', $qb->createNamedParameter('circles')))
                ->andWhere($qb->expr()->eq('actor_id',   $qb->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if (!$row) {
                error_log('[TeamHub][TalkService] syncUserToTeamTalkRoom: no Talk room for team — skip');
                return;
            }
            $roomId = (int)$row['room_id'];

            // ── 2. Skip if the user already has an attendee row ───────────────
            $ckQb  = $db->getQueryBuilder();
            $ckRes = $ckQb->select('id')
                ->from('talk_attendees')
                ->where($ckQb->expr()->eq('room_id',
                    $ckQb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($ckQb->expr()->eq('actor_type', $ckQb->createNamedParameter('users')))
                ->andWhere($ckQb->expr()->eq('actor_id',   $ckQb->createNamedParameter($uid)))
                ->setMaxResults(1)
                ->executeQuery();
            $existing = $ckRes->fetch();
            $ckRes->closeCursor();

            if ($existing) {
                error_log('[TeamHub][TalkService] syncUserToTeamTalkRoom: uid=' . $uid . ' already has attendee row — skip');
                return;
            }

            // ── 3. Insert individual user attendee row ────────────────────────
            $attendeeCols = $this->dbIntrospection->getTableColumns('talk_attendees');
            $aqb = $db->getQueryBuilder();
            $aqb->insert('talk_attendees')
                ->setValue('room_id',          $aqb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->setValue('actor_type',       $aqb->createNamedParameter('users'))
                ->setValue('actor_id',         $aqb->createNamedParameter($uid))
                ->setValue('display_name',     $aqb->createNamedParameter(''))
                ->setValue('participant_type', $aqb->createNamedParameter(3, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)); // PARTICIPANT

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

            $this->logger->info('[TalkService] syncUserToTeamTalkRoom: user added to Talk room', [
                'teamId' => $teamId, 'uid' => $uid, 'roomId' => $roomId,
                'app'    => Application::APP_ID,
            ]);
            error_log('[TeamHub][TalkService] syncUserToTeamTalkRoom: inserted attendee uid=' . $uid . ' roomId=' . $roomId);

        } catch (\Throwable $e) {
            // Non-fatal — user can still reach the room via the TeamHub tab token link.
            $this->logger->warning('[TalkService] syncUserToTeamTalkRoom failed', [
                'teamId' => $teamId, 'uid' => $uid,
                'error'  => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            error_log('[TeamHub][TalkService] syncUserToTeamTalkRoom FAILED: ' . $e->getMessage());
        }
    }

    /**
     * Remove a single user's attendee row from the Talk room connected to $teamId.
     *
     * Called when a direct user member (user_type=1) leaves or is removed from the
     * team. Talk does not watch for Circles membership changes, so TeamHub must
     * explicitly remove the row to revoke access.
     *
     * Room OWNER rows (participant_type=1) are intentionally preserved to prevent
     * orphaning a Talk room without an owner.
     *
     * Non-fatal: failure is logged but does not propagate.
     */
    public function removeUserFromTeamTalkRoom(string $teamId, string $uid): void {
        if (!$this->appManager->isInstalled('spreed')) {
            return;
        }

        error_log('[TeamHub][TalkService] removeUserFromTeamTalkRoom: teamId=' . $teamId . ' uid=' . $uid);

        try {
            $db = $this->container->get(\OCP\IDBConnection::class);

            // Find the Talk room connected to this team.
            $qb  = $db->getQueryBuilder();
            $res = $qb->select('room_id')
                ->from('talk_attendees')
                ->where($qb->expr()->eq('actor_type', $qb->createNamedParameter('circles')))
                ->andWhere($qb->expr()->eq('actor_id',   $qb->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if (!$row) {
                error_log('[TeamHub][TalkService] removeUserFromTeamTalkRoom: no Talk room — skip');
                return;
            }
            $roomId = (int)$row['room_id'];

            $dqb      = $db->getQueryBuilder();
            $affected = $dqb->delete('talk_attendees')
                ->where($dqb->expr()->eq('room_id',
                    $dqb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($dqb->expr()->eq('actor_type', $dqb->createNamedParameter('users')))
                ->andWhere($dqb->expr()->eq('actor_id',   $dqb->createNamedParameter($uid)))
                ->andWhere($dqb->expr()->neq('participant_type',
                    $dqb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))) // preserve OWNER
                ->executeStatement();

            $this->logger->info('[TalkService] removeUserFromTeamTalkRoom: done', [
                'teamId' => $teamId, 'uid' => $uid, 'roomId' => $roomId,
                'affected' => $affected, 'app' => Application::APP_ID,
            ]);
            error_log('[TeamHub][TalkService] removeUserFromTeamTalkRoom: deleted ' . $affected . ' row(s) uid=' . $uid);

        } catch (\Throwable $e) {
            $this->logger->warning('[TalkService] removeUserFromTeamTalkRoom failed', [
                'teamId' => $teamId, 'uid' => $uid,
                'error'  => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            error_log('[TeamHub][TalkService] removeUserFromTeamTalkRoom FAILED: ' . $e->getMessage());
        }
    }

    /**
     * Reconcile the Talk room attendee list against current team membership.
     *
     * Called when a group or nested circle is removed from the team. In that case
     * the set of affected users is not known at call time (no single UID), so we
     * iterate all existing user attendees and evict anyone no longer a direct team
     * member.
     *
     * Must be called AFTER MembershipService::onUpdate() so that
     * circles_membership reflects the post-removal state.
     *
     * Direct membership only (circles_member user_type=1, status='Member').
     * Room OWNER rows (participant_type=1) are preserved.
     *
     * Non-fatal: failure is logged but does not propagate.
     */
    public function reconcileTalkRoomMembers(string $teamId): void {
        if (!$this->appManager->isInstalled('spreed')) {
            return;
        }

        error_log('[TeamHub][TalkService] reconcileTalkRoomMembers: teamId=' . $teamId);

        try {
            $db = $this->container->get(\OCP\IDBConnection::class);

            // ── 1. Find the Talk room ─────────────────────────────────────────
            $qb  = $db->getQueryBuilder();
            $res = $qb->select('room_id')
                ->from('talk_attendees')
                ->where($qb->expr()->eq('actor_type', $qb->createNamedParameter('circles')))
                ->andWhere($qb->expr()->eq('actor_id',   $qb->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if (!$row) {
                error_log('[TeamHub][TalkService] reconcileTalkRoomMembers: no Talk room — skip');
                return;
            }
            $roomId = (int)$row['room_id'];

            // ── 2. Get all user attendees currently in the room ───────────────
            $atQb  = $db->getQueryBuilder();
            $atRes = $atQb->select('actor_id', 'participant_type')
                ->from('talk_attendees')
                ->where($atQb->expr()->eq('room_id',
                    $atQb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($atQb->expr()->eq('actor_type', $atQb->createNamedParameter('users')))
                ->executeQuery();
            $attendees = $atRes->fetchAll();
            $atRes->closeCursor();

            if (empty($attendees)) {
                return;
            }

            // ── 3. Build the set of current direct team members ───────────────
            $mQb  = $db->getQueryBuilder();
            $mRes = $mQb->select('user_id')
                ->from('circles_member')
                ->where($mQb->expr()->eq('circle_id', $mQb->createNamedParameter($teamId)))
                ->andWhere($mQb->expr()->eq('user_type',
                    $mQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($mQb->expr()->eq('status', $mQb->createNamedParameter('Member')))
                ->executeQuery();

            $currentMembers = [];
            while ($mRow = $mRes->fetch()) {
                $currentMembers[(string)$mRow['user_id']] = true;
            }
            $mRes->closeCursor();

            error_log('[TeamHub][TalkService] reconcileTalkRoomMembers: attendees=' . count($attendees) . ' currentMembers=' . count($currentMembers));

            // ── 4. Evict attendees no longer in the team ──────────────────────
            $removed = 0;
            foreach ($attendees as $attendee) {
                $uid  = (string)($attendee['actor_id']        ?? '');
                $type = (int)   ($attendee['participant_type'] ?? 0);

                if ($uid === '' || $type === 1) {
                    continue; // skip empty rows and room OWNERs
                }

                if (!isset($currentMembers[$uid])) {
                    $dqb = $db->getQueryBuilder();
                    $dqb->delete('talk_attendees')
                        ->where($dqb->expr()->eq('room_id',
                            $dqb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->andWhere($dqb->expr()->eq('actor_type', $dqb->createNamedParameter('users')))
                        ->andWhere($dqb->expr()->eq('actor_id',   $dqb->createNamedParameter($uid)))
                        ->andWhere($dqb->expr()->neq('participant_type',
                            $dqb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->executeStatement();
                    $removed++;
                    error_log('[TeamHub][TalkService] reconcileTalkRoomMembers: evicted uid=' . $uid);
                }
            }

            $this->logger->info('[TalkService] reconcileTalkRoomMembers: complete', [
                'teamId' => $teamId, 'roomId' => $roomId,
                'checked' => count($attendees), 'removed' => $removed,
                'app'    => Application::APP_ID,
            ]);
            error_log('[TeamHub][TalkService] reconcileTalkRoomMembers: done removed=' . $removed);

        } catch (\Throwable $e) {
            $this->logger->warning('[TalkService] reconcileTalkRoomMembers failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            error_log('[TeamHub][TalkService] reconcileTalkRoomMembers FAILED: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // Member expansion helpers
    // =========================================================================

    /**
     * Resolve a team's circle unique_id string to a Circles\Model\Circle object.
     *
     * ParticipantService::addCircle() in Talk 17+ requires a Circle object,
     * not a circle ID string. This helper handles the FederatedUserService
     * session setup that CirclesManager::getCircle() needs.
     *
     * Returns null if Circles is unavailable or the circle cannot be found —
     * callers must fall back to the direct-DB path in that case.
     *
     * @param string $teamId Circle unique_id (= TeamHub team_id)
     * @param string $uid    Acting NC user UID (needed for Circles session context)
     */
    private function resolveCircle(string $teamId, string $uid): ?\OCA\Circles\Model\Circle {
        try {
            error_log('[TeamHub][TalkService] resolveCircle: resolving teamId=' . $teamId . ' for uid=' . $uid);

            $userManager = $this->container->get(\OCP\IUserManager::class);
            $userObj     = $userManager->get($uid);
            if ($userObj === null) {
                $this->logger->warning('[TalkService] resolveCircle: user not found', [
                    'uid' => $uid, 'app' => Application::APP_ID,
                ]);
                return null;
            }

            // FederatedUserService::setLocalCurrentUser() sets up the Circles
            // session context so getCircle() can see the circle regardless of
            // whether its config bitmask hides it from unauthenticated lookups.
            $fedUserSvc = $this->container->get(\OCA\Circles\Service\FederatedUserService::class);
            $fedUserSvc->setLocalCurrentUser($userObj);

            $circlesMgr = $this->container->get(\OCA\Circles\CirclesManager::class);
            $circle     = $circlesMgr->getCircle($teamId);

            error_log('[TeamHub][TalkService] resolveCircle: resolved circle name=' . $circle->getName());
            return $circle;

        } catch (\Throwable $e) {
            $this->logger->warning('[TalkService] resolveCircle: could not resolve Circle object — will fall back to direct DB', [
                'teamId' => $teamId,
                'uid'    => $uid,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            error_log('[TeamHub][TalkService] resolveCircle: FAILED ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Expand the team's circle members into individual talk_attendees rows.
     *
     * WHY THIS IS NEEDED
     * ------------------
     * NC Talk's conversation list is built from talk_attendees rows where
     * actor_type = 'users'. Having only a circle-type attendee row gives users
     * *access* when they know the room token (and TeamHub opens it by token),
     * but the conversation never appears in their own Talk sidebar because Talk
     * only queries for direct user rows when building the list.
     *
     * When Talk's own UI adds a circle/group to a room it internally expands
     * members into individual rows. TeamHub bypasses that UI path and must
     * perform the expansion itself.
     *
     * SCOPE
     * -----
     * This method expands direct user members (circles_member.user_type = 1,
     * status = 'Member') only. Members that reach the team indirectly via a
     * nested group or sub-circle are a future improvement (see HANDOFF open
     * issues). Direct membership covers the large majority of real teams.
     *
     * IDEMPOTENCY
     * -----------
     * Each user is checked for an existing attendee row before inserting.
     * Re-running on an already-expanded room is safe — duplicates are skipped.
     *
     * PARTICIPANT TYPE
     * ----------------
     * Expanded members are inserted as PARTICIPANT (3). Their effective rights
     * in the room are determined by Talk as the union of:
     *   - their individual row (PARTICIPANT)
     *   - the circle row (MODERATOR)
     * so they inherit moderator rights from the circle. No separate promotion
     * needed.
     *
     * @return int Number of new attendee rows actually inserted.
     */
    public function expandCircleMembersToTalk(int $roomId, string $teamId, \OCP\IDBConnection $db): int {
        error_log('[TeamHub][TalkService] expandCircleMembersToTalk: start roomId=' . $roomId . ' teamId=' . $teamId);

        try {
            // ── 1. Fetch direct user members of the team circle ───────────────
            $qb  = $db->getQueryBuilder();
            $res = $qb->select('user_id')
                ->from('circles_member')
                ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('user_type',
                    $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))) // 1 = NC user
                ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('Member')))
                ->executeQuery();

            $uids = [];
            while ($row = $res->fetch()) {
                $uid = (string)($row['user_id'] ?? '');
                if ($uid !== '') {
                    $uids[] = $uid;
                }
            }
            $res->closeCursor();

            error_log('[TeamHub][TalkService] expandCircleMembersToTalk: found ' . count($uids) . ' direct members');

            if (empty($uids)) {
                return 0;
            }

            // ── 2. Detect which attendee columns exist on this Talk version ────
            $attendeeCols = $this->dbIntrospection->getTableColumns('talk_attendees');

            // ── 3. Insert a user-level attendee row for each member ───────────
            $added = 0;
            foreach ($uids as $uid) {
                // Idempotency: skip if row already exists.
                $ckQb  = $db->getQueryBuilder();
                $ckRes = $ckQb->select('id')
                    ->from('talk_attendees')
                    ->where($ckQb->expr()->eq('room_id',
                        $ckQb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->andWhere($ckQb->expr()->eq('actor_type', $ckQb->createNamedParameter('users')))
                    ->andWhere($ckQb->expr()->eq('actor_id',   $ckQb->createNamedParameter($uid)))
                    ->setMaxResults(1)
                    ->executeQuery();
                $exists = $ckRes->fetch();
                $ckRes->closeCursor();

                if ($exists) {
                    error_log('[TeamHub][TalkService] expandCircleMembersToTalk: uid=' . $uid . ' already has attendee row — skip');
                    continue;
                }

                $aqb = $db->getQueryBuilder();
                $aqb->insert('talk_attendees')
                    ->setValue('room_id',          $aqb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    ->setValue('actor_type',       $aqb->createNamedParameter('users'))
                    ->setValue('actor_id',         $aqb->createNamedParameter($uid))
                    ->setValue('display_name',     $aqb->createNamedParameter(''))   // Talk resolves display name at runtime
                    ->setValue('participant_type', $aqb->createNamedParameter(3, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)); // PARTICIPANT

                // Optional columns — vary by Talk version, so only set when present.
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
                    'access_token'           => '',  // not used for actor_type='users'
                    'remote_id'              => '',
                    'phone_number'           => '',
                    'phone_states'           => '',
                ] as $col => $val) {
                    if (in_array($col, $attendeeCols, true)) {
                        $aqb->setValue($col, $aqb->createNamedParameter($val));
                    }
                }

                try {
                    $aqb->executeStatement();
                    $added++;
                    error_log('[TeamHub][TalkService] expandCircleMembersToTalk: inserted attendee for uid=' . $uid);
                } catch (\Throwable $e) {
                    // Non-fatal: log and continue for remaining members.
                    $this->logger->warning('[TalkService] expandCircleMembersToTalk: failed to insert attendee', [
                        'uid'    => $uid,
                        'roomId' => $roomId,
                        'error'  => $e->getMessage(),
                        'app'    => Application::APP_ID,
                    ]);
                }
            }

            $this->logger->info('[TalkService] expandCircleMembersToTalk: complete', [
                'teamId'    => $teamId,
                'roomId'    => $roomId,
                'expanded'  => $added,
                'skipped'   => count($uids) - $added,
                'app'       => Application::APP_ID,
            ]);
            error_log('[TeamHub][TalkService] expandCircleMembersToTalk: done — added=' . $added);

            return $added;

        } catch (\Throwable $e) {
            $this->logger->error('[TalkService] expandCircleMembersToTalk failed', [
                'teamId' => $teamId,
                'roomId' => $roomId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            error_log('[TeamHub][TalkService] expandCircleMembersToTalk: EXCEPTION ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Reconcile the Talk room for $teamId against the team's EFFECTIVE membership.
     *
     * Unlike reconcileTalkRoomMembers (which considers direct members only) this
     * walks circles_membership — Circles' denormalised cache — so users reaching
     * the team via an attached group or sub-team are treated as members.
     *
     * Behaviour:
     *   - Adds a talk_attendees row for every effective member missing one.
     *   - Deletes the row for every user attendee no longer in the effective set.
     *   - Skips room OWNER rows (participant_type=1) so the room cannot be
     *     orphaned.
     *   - Idempotent — safe to run on any cadence; the hourly cron job is the
     *     primary caller.
     *
     * Returns an array of counts for logging / observability:
     *   { added: int, removed: int }
     *
     * Non-fatal — any failure is logged and an empty result returned. The
     * caller (background job) keeps iterating over remaining teams.
     *
     * @return array{added: int, removed: int}
     */
    public function reconcileEffectiveTalkRoomMembers(string $teamId): array {
        if (!$this->appManager->isInstalled('spreed')) {
            return ['added' => 0, 'removed' => 0];
        }

        try {
            $db = $this->container->get(\OCP\IDBConnection::class);

            // ── 1. Find the Talk room connected to this team ──────────────────
            $qb  = $db->getQueryBuilder();
            $res = $qb->select('room_id')
                ->from('talk_attendees')
                ->where($qb->expr()->eq('actor_type', $qb->createNamedParameter('circles')))
                ->andWhere($qb->expr()->eq('actor_id',   $qb->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if (!$row) {
                // No Talk room connected — nothing to reconcile.
                return ['added' => 0, 'removed' => 0];
            }
            $roomId = (int)$row['room_id'];

            // ── 2. Compute effective member set from circles_membership ──────
            // circles_membership.single_id resolves to a NC uid by joining
            // circles_member where m.circle_id = single_id AND m.user_type=1
            // (the single_id IS the unique_id of the user's personal circle).
            $eQb  = $db->getQueryBuilder();
            $eRes = $eQb->select('m.user_id')
                ->from('circles_membership', 'ms')
                ->innerJoin('ms', 'circles_member', 'm', $eQb->expr()->andX(
                    $eQb->expr()->eq('m.circle_id',  'ms.single_id'),
                    $eQb->expr()->eq('m.user_type',  $eQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)),
                ))
                ->where($eQb->expr()->eq('ms.circle_id', $eQb->createNamedParameter($teamId)))
                ->executeQuery();
            $effective = [];
            while ($eRow = $eRes->fetch()) {
                $uid = (string)($eRow['user_id'] ?? '');
                if ($uid !== '') {
                    $effective[$uid] = true;
                }
            }
            $eRes->closeCursor();

            // Safety net: circles_membership can lag for very freshly added
            // direct members. Also fold in the direct membership rows.
            $dQb  = $db->getQueryBuilder();
            $dRes = $dQb->select('user_id')
                ->from('circles_member')
                ->where($dQb->expr()->eq('circle_id', $dQb->createNamedParameter($teamId)))
                ->andWhere($dQb->expr()->eq('user_type', $dQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($dQb->expr()->eq('status',    $dQb->createNamedParameter('Member')))
                ->executeQuery();
            while ($dRow = $dRes->fetch()) {
                $uid = (string)($dRow['user_id'] ?? '');
                if ($uid !== '') {
                    $effective[$uid] = true;
                }
            }
            $dRes->closeCursor();

            // ── 3. Current talk_attendees user rows for this room ────────────
            $aQb  = $db->getQueryBuilder();
            $aRes = $aQb->select('actor_id', 'participant_type')
                ->from('talk_attendees')
                ->where($aQb->expr()->eq('room_id',
                    $aQb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($aQb->expr()->eq('actor_type', $aQb->createNamedParameter('users')))
                ->executeQuery();
            $currentByUid = [];
            while ($aRow = $aRes->fetch()) {
                $uid = (string)($aRow['actor_id'] ?? '');
                if ($uid !== '') {
                    $currentByUid[$uid] = (int)($aRow['participant_type'] ?? 0);
                }
            }
            $aRes->closeCursor();

            // ── 4. Add missing attendees ─────────────────────────────────────
            $attendeeCols = $this->dbIntrospection->getTableColumns('talk_attendees');
            $added = 0;
            foreach (array_keys($effective) as $uid) {
                if (isset($currentByUid[$uid])) {
                    continue;
                }
                $iQb = $db->getQueryBuilder();
                $iQb->insert('talk_attendees')
                    ->setValue('room_id',          $iQb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    ->setValue('actor_type',       $iQb->createNamedParameter('users'))
                    ->setValue('actor_id',         $iQb->createNamedParameter($uid))
                    ->setValue('display_name',     $iQb->createNamedParameter(''))
                    ->setValue('participant_type', $iQb->createNamedParameter(3, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT));

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
                        $iQb->setValue($col, $iQb->createNamedParameter($val));
                    }
                }
                try {
                    $iQb->executeStatement();
                    $added++;
                } catch (\Throwable $e) {
                    $this->logger->warning('[TalkService] reconcileEffectiveTalkRoomMembers: insert failed', [
                        'teamId' => $teamId, 'roomId' => $roomId, 'uid' => $uid,
                        'error'  => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }
            }

            // ── 5. Remove orphans (skip room owners) ─────────────────────────
            $removed = 0;
            foreach ($currentByUid as $uid => $pType) {
                if ($pType === 1) {
                    continue; // never evict a room owner
                }
                if (isset($effective[$uid])) {
                    continue; // still a member
                }
                try {
                    $rQb = $db->getQueryBuilder();
                    $rQb->delete('talk_attendees')
                        ->where($rQb->expr()->eq('room_id',
                            $rQb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->andWhere($rQb->expr()->eq('actor_type', $rQb->createNamedParameter('users')))
                        ->andWhere($rQb->expr()->eq('actor_id',   $rQb->createNamedParameter($uid)))
                        ->andWhere($rQb->expr()->neq('participant_type',
                            $rQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->executeStatement();
                    $removed++;
                } catch (\Throwable $e) {
                    $this->logger->warning('[TalkService] reconcileEffectiveTalkRoomMembers: delete failed', [
                        'teamId' => $teamId, 'roomId' => $roomId, 'uid' => $uid,
                        'error'  => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }
            }

            if ($added > 0 || $removed > 0) {
                $this->logger->info('[TalkService] reconcileEffectiveTalkRoomMembers: drift reconciled', [
                    'teamId' => $teamId, 'roomId' => $roomId,
                    'added'  => $added, 'removed' => $removed,
                    'effective' => count($effective), 'before' => count($currentByUid),
                    'app' => Application::APP_ID,
                ]);
            }

            return ['added' => $added, 'removed' => $removed];

        } catch (\Throwable $e) {
            $this->logger->warning('[TalkService] reconcileEffectiveTalkRoomMembers failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
            return ['added' => 0, 'removed' => 0];
        }
    }

}
