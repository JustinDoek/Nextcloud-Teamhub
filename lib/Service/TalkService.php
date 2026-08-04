<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Mentions\MentionParser;
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
                $this->logger->debug('[TeamHub][TalkService] Talk S1: addCircle succeeded via API — circle membership resolved natively by Talk', ['app' => Application::APP_ID]);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][TalkService] Talk S1: ParticipantService::addCircle failed — using direct DB fallback', [
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
                    $this->logger->debug('[TeamHub][TalkService] Talk S1: using fallback expansion (API path failed)', ['app' => Application::APP_ID]);
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
                $this->logger->debug('[TeamHub][TalkService] Talk S2: addCircle succeeded via API', ['app' => Application::APP_ID]);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][TalkService] Talk S2: Manager addCircle failed — using direct DB fallback', [
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
                    $this->logger->debug('[TeamHub][TalkService] Talk S2: using fallback expansion (API path failed)', ['app' => Application::APP_ID]);
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
            $this->logger->debug('[TeamHub][TalkService] Talk S3: direct DB path — expanding individual members as fallback', ['app' => Application::APP_ID]);
            $this->expandCircleMembersToTalk($roomId, $teamId, $db);

            return ['token' => $token, 'name' => $teamName, 'circle_added' => true];

        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][TalkService] Talk: all strategies failed', [
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
            $this->logger->error('[TeamHub][TalkService] listOwnedRooms failed', [
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
                $this->logger->debug('[TeamHub][TalkService] connectExistingRoom: addCircle succeeded via API — Talk resolves membership natively', ['app' => Application::APP_ID]);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][TalkService] connectExistingRoom: ParticipantService::addCircle failed — using direct DB insert', [
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
                $this->logger->debug('[TeamHub][TalkService] connectExistingRoom: using fallback expansion (API path failed)', ['app' => Application::APP_ID]);
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
            $this->logger->error('[TeamHub][TalkService] connectExistingRoom failed', [
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
            $this->logger->error('[TeamHub][TalkService] insertTalkCircleAttendee failed', [
                'roomId' => $roomId, 'teamId' => $teamId,
                'error'  => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    public function promoteTalkCircleToModerator(int $roomId, string $teamId, \OCP\IDBConnection $db): void {
        // v3.100.8 (apps.md W-5) — try ParticipantService first, fall back
        // to raw UPDATE. Gains system chat message on promotion.
        $promotedViaApi = false;
        try {
            $room = $this->container->get(\OCA\Talk\Manager::class)->getRoomById($roomId);
            $participantService = $this->container->get(\OCA\Talk\Service\ParticipantService::class);
            $participant = $participantService->getParticipantByActor($room, 'circles', $teamId);
            if ($participant !== null) {
                $participantService->updateParticipantType(
                    $room,
                    $participant,
                    \OCA\Talk\Participant::MODERATOR,
                );
                $promotedViaApi = true;
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][TalkService] promoteTalkCircleToModerator: ParticipantService path unavailable — using DB fallback', [
                'roomId' => $roomId, 'teamId' => $teamId,
                'reason' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        if ($promotedViaApi) {
            return;
        }

        try {
            $uqb = $db->getQueryBuilder();
            $uqb->update('talk_attendees')
                ->set('participant_type', $uqb->createNamedParameter(2)) // MODERATOR
                ->where($uqb->expr()->eq('room_id',    $uqb->createNamedParameter($roomId)))
                ->andWhere($uqb->expr()->eq('actor_type', $uqb->createNamedParameter('circles')))
                ->andWhere($uqb->expr()->eq('actor_id',   $uqb->createNamedParameter($teamId)))
                ->executeStatement();

        } catch (\Throwable $e) {
            // Non-fatal: room still works, but circle members won't have mod rights
            $this->logger->warning('[TeamHub][TalkService] Talk: promoteTalkCircleToModerator failed', [
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

            $this->logger->debug('[TeamHub][TalkService] suspendTalkAccess: circle attendee removed', [
                'teamId' => $teamId, 'roomId' => $roomId, 'app' => Application::APP_ID,
            ]);

            return $roomId;
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][TalkService] suspendTalkAccess failed', [
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
            $this->logger->error('[TeamHub][TalkService] resumeTalkAccess failed', [
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
                $this->logger->warning('[TeamHub][TalkService] removeRoomAccess: room not found', [
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

            $this->logger->debug('[TeamHub][TalkService] removeRoomAccess: circle attendee removed', [
                'teamId' => $teamId, 'token' => $token, 'roomId' => $roomId,
                'affected' => $affected, 'app' => Application::APP_ID,
            ]);
            return $affected > 0;
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][TalkService] removeRoomAccess failed', [
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

            // v4.0.7 — each of the three chained getQueryBuilder() calls
            // returns a DIFFERENT QueryBuilder instance, and createNamedParameter
            // registers the placeholder on the QB it was called on. The where()
            // then executes on yet another QB where the placeholder was never
            // registered, so the raw `:dcValue1` string ends up in the SQL and
            // MariaDB returns 42000 syntax error. Reuse a single QB per statement.
            $daq = $db->getQueryBuilder();
            $daq->delete('talk_attendees')
                ->where($daq->expr()->eq('room_id', $daq->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $drq = $db->getQueryBuilder();
            $drq->delete('talk_rooms')
                ->where($drq->expr()->eq('id', $drq->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $this->logger->info('[TeamHub][TalkService] deleteRoomById: room deleted', [
                'token' => $token, 'roomId' => $roomId, 'app' => Application::APP_ID,
            ]);
            return ['deleted' => true, 'token' => $token];
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][TalkService] deleteRoomById failed', [
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
            $this->logger->error('[TeamHub][TalkService] deleteTalkRoom failed', [
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
        $this->logger->debug('[TeamHub][TalkService] postChatMessage — start', [
            'token' => $token, 'uid' => $uid, 'app' => Application::APP_ID,
        ]);

        if (!$this->appManager->isInstalled('spreed')) {
            $this->logger->warning('[TeamHub][TalkService] postChatMessage — spreed not installed', [
                'app' => Application::APP_ID,
            ]);
            return false;
        }

        try {
            $manager     = $this->container->get(\OCA\Talk\Manager::class);
            $room        = $manager->getRoomByToken($token);

            if (!$room) {
                $this->logger->warning('[TeamHub][TalkService] postChatMessage — room not found', [
                    'token' => $token, 'app' => Application::APP_ID,
                ]);
                return false;
            }

            // v4.5.42 — was a bare getParticipant(), which fails for a member
            // whose team membership is indirect: they reach the room through
            // the circle attendee row and have none of their own. See
            // resolveParticipant().
            $participant = $this->resolveParticipant($room, $token, $uid);
            if ($participant === null) {
                $this->logger->warning('[TeamHub][TalkService] postChatMessage — no participant record', [
                    'token' => $token, 'uid' => $uid, 'app' => Application::APP_ID,
                ]);
                return false;
            }

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

            $this->logger->debug('[TeamHub][TalkService] postChatMessage — success', [
                'token' => $token, 'app' => Application::APP_ID,
            ]);

            return true;

        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TalkService] postChatMessage failed', [
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

        $this->logger->debug('[TeamHub][TalkService] syncUserToTeamTalkRoom: enter', [
            'teamId' => $teamId, 'uid' => $uid, 'app' => Application::APP_ID,
        ]);

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
                $this->logger->debug('[TeamHub][TalkService] syncUserToTeamTalkRoom: no Talk room for team — skip', [
                    'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
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
                $this->logger->debug('[TeamHub][TalkService] syncUserToTeamTalkRoom: attendee row already present — skip', [
                    'uid' => $uid, 'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
                return;
            }

            // ── 3. Insert individual user attendee row ────────────────────────
            // v3.100.8 (apps.md W-5) — Talk ParticipantService::addUsers
            // first so system chat message + push notifications fire; fall
            // back to raw INSERT when the Talk API refuses (typically
            // circle-scoped rooms where the acting user isn't a moderator).
            $addedViaApi = false;
            try {
                $roomManager = $this->container->get(\OCA\Talk\Manager::class);
                $room = $roomManager->getRoomById($roomId);
                $participantService = $this->container->get(\OCA\Talk\Service\ParticipantService::class);
                $userManager = $this->container->get(\OCP\IUserManager::class);
                $userObj = $userManager->get($uid);
                if ($userObj !== null) {
                    $participantService->addUsers($room, [[
                        'actorType' => 'users',
                        'actorId'   => $uid,
                        'displayName' => $userObj->getDisplayName(),
                    ]]);
                    $addedViaApi = true;
                }
            } catch (\Throwable $e) {
                $this->logger->debug('[TeamHub][TalkService] syncUserToTeamTalkRoom: ParticipantService::addUsers failed — using DB fallback', [
                    'uid' => $uid, 'roomId' => $roomId,
                    'reason' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            if (!$addedViaApi) {
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
            }

            $this->logger->info('[TeamHub][TalkService] syncUserToTeamTalkRoom: user added to Talk room', [
                'teamId' => $teamId, 'uid' => $uid, 'roomId' => $roomId,
                'viaApi' => $addedViaApi,
                'app'    => Application::APP_ID,
            ]);
            $this->logger->debug('[TeamHub][TalkService] syncUserToTeamTalkRoom: inserted attendee', [
                'uid' => $uid, 'roomId' => $roomId, 'app' => Application::APP_ID,
            ]);

        } catch (\Throwable $e) {
            // Non-fatal — user can still reach the room via the TeamHub tab token link.
            $this->logger->warning('[TeamHub][TalkService] syncUserToTeamTalkRoom failed', [
                'teamId' => $teamId, 'uid' => $uid,
                'error'  => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            // The $this->logger->warning above already carries the full context;
            // no separate error_log needed.
        }
    }

    /**
     * Remove a single member's attendee row(s) from the Talk room connected
     * to $teamId.
     *
     * Called when a direct member (user_type=1 local OR user_type=4 federated)
     * leaves or is removed from the team. Talk does not watch for Circles
     * membership changes, so TeamHub must explicitly remove the row to revoke
     * access.
     *
     * The same identifier can occur under more than one actor_type — a local
     * user's UID and a federated UID happen to look different, but Talk's
     * circle-expansion may have created an attendee under either type
     * depending on how the member was first reached. We therefore delete from
     * both 'users' AND 'federated_users' for the given actor_id; the
     * non-matching row is a no-op.
     *
     * Room OWNER rows (participant_type=1) are intentionally preserved to
     * prevent orphaning a Talk room without an owner.
     *
     * Non-fatal: failure is logged but does not propagate.
     */
    public function removeUserFromTeamTalkRoom(string $teamId, string $uid): void {
        if (!$this->appManager->isInstalled('spreed')) {
            return;
        }

        $this->logger->debug('[TeamHub][TalkService] removeUserFromTeamTalkRoom: enter', [
            'teamId' => $teamId, 'uid' => $uid, 'app' => Application::APP_ID,
        ]);

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
                $this->logger->debug('[TeamHub][TalkService] removeUserFromTeamTalkRoom: no Talk room — skip', [
                    'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
                return;
            }
            $roomId = (int)$row['room_id'];

            // v3.100.8 (apps.md W-5) — ParticipantService::removeAttendee
            // first so system leave-message fires; fall back to raw DELETE
            // when the Talk API refuses.
            $removedViaApi = false;
            try {
                $roomManager = $this->container->get(\OCA\Talk\Manager::class);
                $room = $roomManager->getRoomById($roomId);
                $participantService = $this->container->get(\OCA\Talk\Service\ParticipantService::class);
                $participant = $participantService->getParticipantByActor(
                    $room, 'users', $uid,
                );
                if ($participant !== null
                    && (int)$participant->getAttendee()->getParticipantType() !== 1 /* preserve OWNER */
                ) {
                    $participantService->removeAttendee(
                        $room,
                        $participant->getAttendee(),
                        \OCA\Talk\Room::PARTICIPANT_REMOVED,
                    );
                    $removedViaApi = true;
                }
            } catch (\Throwable $e) {
                $this->logger->debug('[TeamHub][TalkService] removeUserFromTeamTalkRoom: ParticipantService::removeAttendee failed — using DB fallback', [
                    'uid' => $uid, 'roomId' => $roomId,
                    'reason' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            $affected = 0;
            if (!$removedViaApi) {
                $dqb      = $db->getQueryBuilder();
                $affected = $dqb->delete('talk_attendees')
                    ->where($dqb->expr()->eq('room_id',
                        $dqb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->andWhere($dqb->expr()->in('actor_type', $dqb->createNamedParameter(
                        ['users', 'federated_users'],
                        \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY
                    )))
                    ->andWhere($dqb->expr()->eq('actor_id',   $dqb->createNamedParameter($uid)))
                    ->andWhere($dqb->expr()->neq('participant_type',
                        $dqb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))) // preserve OWNER
                    ->executeStatement();
            }

            $this->logger->info('[TeamHub][TalkService] removeUserFromTeamTalkRoom: done', [
                'teamId' => $teamId, 'uid' => $uid, 'roomId' => $roomId,
                'viaApi' => $removedViaApi, 'affected' => $affected,
                'app' => Application::APP_ID,
            ]);

        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TalkService] removeUserFromTeamTalkRoom failed', [
                'teamId' => $teamId, 'uid' => $uid,
                'error'  => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            // logger->warning above already carries the failure detail.
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

        $this->logger->debug('[TeamHub][TalkService] reconcileTalkRoomMembers: enter', [
            'teamId' => $teamId, 'app' => Application::APP_ID,
        ]);

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
                $this->logger->debug('[TeamHub][TalkService] reconcileTalkRoomMembers: no Talk room — skip', [
                    'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
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

            $this->logger->debug('[TeamHub][TalkService] reconcileTalkRoomMembers: sets loaded', [
                'attendees' => count($attendees), 'currentMembers' => count($currentMembers),
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);

            // ── 4. Evict attendees no longer in the team ──────────────────────
            // v3.100.8 (apps.md W-5) — per-attendee: try ParticipantService
            // first (fires system leave-message + push), fall back to raw
            // DELETE if the API refuses.
            $room = null;
            $participantService = null;
            try {
                $room = $this->container->get(\OCA\Talk\Manager::class)->getRoomById($roomId);
                $participantService = $this->container->get(\OCA\Talk\Service\ParticipantService::class);
            } catch (\Throwable $e) {
                $this->logger->debug('[TeamHub][TalkService] reconcileTalkRoomMembers: Talk API path unavailable — using DB fallback for all evictions', [
                    'roomId' => $roomId, 'reason' => $e->getMessage(),
                    'app' => Application::APP_ID,
                ]);
            }

            $removed = 0;
            foreach ($attendees as $attendee) {
                $uid  = (string)($attendee['actor_id']        ?? '');
                $type = (int)   ($attendee['participant_type'] ?? 0);

                if ($uid === '' || $type === 1) {
                    continue; // skip empty rows and room OWNERs
                }

                if (isset($currentMembers[$uid])) {
                    continue;
                }

                $viaApi = false;
                if ($room !== null && $participantService !== null) {
                    try {
                        $participant = $participantService->getParticipantByActor($room, 'users', $uid);
                        if ($participant !== null) {
                            $participantService->removeAttendee(
                                $room,
                                $participant->getAttendee(),
                                \OCA\Talk\Room::PARTICIPANT_REMOVED,
                            );
                            $viaApi = true;
                        }
                    } catch (\Throwable $e) {
                        $this->logger->debug('[TeamHub][TalkService] reconcileTalkRoomMembers: per-attendee API remove failed — falling back for this uid', [
                            'uid' => $uid, 'roomId' => $roomId,
                            'reason' => $e->getMessage(),
                            'app' => Application::APP_ID,
                        ]);
                    }
                }
                if (!$viaApi) {
                    $dqb = $db->getQueryBuilder();
                    $dqb->delete('talk_attendees')
                        ->where($dqb->expr()->eq('room_id',
                            $dqb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->andWhere($dqb->expr()->eq('actor_type', $dqb->createNamedParameter('users')))
                        ->andWhere($dqb->expr()->eq('actor_id',   $dqb->createNamedParameter($uid)))
                        ->andWhere($dqb->expr()->neq('participant_type',
                            $dqb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->executeStatement();
                }
                $removed++;
                $this->logger->debug('[TeamHub][TalkService] reconcileTalkRoomMembers: evicted attendee', [
                    'uid' => $uid, 'roomId' => $roomId, 'viaApi' => $viaApi,
                    'app' => Application::APP_ID,
                ]);
            }

            $this->logger->info('[TeamHub][TalkService] reconcileTalkRoomMembers: complete', [
                'teamId' => $teamId, 'roomId' => $roomId,
                'checked' => count($attendees), 'removed' => $removed,
                'app'    => Application::APP_ID,
            ]);
            // logger->info above already carries the removed count.

        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TalkService] reconcileTalkRoomMembers failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            // logger->warning above already carries the failure detail.
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
            $this->logger->debug('[TeamHub][TalkService] resolveCircle: enter', [
                'teamId' => $teamId, 'uid' => $uid, 'app' => Application::APP_ID,
            ]);

            $userManager = $this->container->get(\OCP\IUserManager::class);
            $userObj     = $userManager->get($uid);
            if ($userObj === null) {
                $this->logger->warning('[TeamHub][TalkService] resolveCircle: user not found', [
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

            $this->logger->debug('[TeamHub][TalkService] resolveCircle: resolved', [
                'teamId' => $teamId, 'circleName' => $circle->getName(),
                'app' => Application::APP_ID,
            ]);
            return $circle;

        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TalkService] resolveCircle: could not resolve Circle object — will fall back to direct DB', [
                'teamId' => $teamId,
                'uid'    => $uid,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            // logger->warning above already carries the failure detail.
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
        $this->logger->debug('[TeamHub][TalkService] expandCircleMembersToTalk: enter', [
            'roomId' => $roomId, 'teamId' => $teamId, 'app' => Application::APP_ID,
        ]);

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

            $this->logger->debug('[TeamHub][TalkService] expandCircleMembersToTalk: fetched direct members', [
                'count' => count($uids), 'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);

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
                    $this->logger->debug('[TeamHub][TalkService] expandCircleMembersToTalk: attendee row already present — skip', [
                        'uid' => $uid, 'roomId' => $roomId, 'app' => Application::APP_ID,
                    ]);
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
                    $this->logger->debug('[TeamHub][TalkService] expandCircleMembersToTalk: inserted attendee', [
                        'uid' => $uid, 'roomId' => $roomId, 'app' => Application::APP_ID,
                    ]);
                } catch (\Throwable $e) {
                    // Non-fatal: log and continue for remaining members.
                    $this->logger->warning('[TeamHub][TalkService] expandCircleMembersToTalk: failed to insert attendee', [
                        'uid'    => $uid,
                        'roomId' => $roomId,
                        'error'  => $e->getMessage(),
                        'app'    => Application::APP_ID,
                    ]);
                }
            }

            $this->logger->info('[TeamHub][TalkService] expandCircleMembersToTalk: complete', [
                'teamId'    => $teamId,
                'roomId'    => $roomId,
                'expanded'  => $added,
                'skipped'   => count($uids) - $added,
                'app'       => Application::APP_ID,
            ]);
            // logger->info above already carries the added count.

            return $added;

        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][TalkService] expandCircleMembersToTalk failed', [
                'teamId' => $teamId,
                'roomId' => $roomId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            // logger->error above already carries the failure detail.
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

        // Map Circles user_type → Talk actor_type for every member type that
        // corresponds to a per-person attendee row in talk_attendees.
        //   1 (local user)  → 'users'
        //   4 (federated)   → 'federated_users'
        // 'circles' attendee rows represent the team itself and must never be
        // touched; group / sub-team / email member types do not produce per-
        // person attendee rows from the circle-expansion side, so they're
        // intentionally outside this map.
        $userTypeToActorType = [
            1 => 'users',
            4 => 'federated_users',
        ];
        $managedActorTypes = array_values($userTypeToActorType);

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
            // circles_member where m.circle_id = single_id AND m.user_type IN
            // (the managed types above). single_id IS the unique_id of the
            // person's personal circle, regardless of whether that person is
            // local or federated.
            //
            // Keyed by "actor_type|actor_id" so a local 'alice' and a
            // federated 'alice@host.com' never collide.
            $effective = [];

            $eQb  = $db->getQueryBuilder();
            $eRes = $eQb->select('m.user_id', 'm.user_type')
                ->from('circles_membership', 'ms')
                ->innerJoin('ms', 'circles_member', 'm', $eQb->expr()->andX(
                    $eQb->expr()->eq('m.circle_id', 'ms.single_id'),
                    $eQb->expr()->in('m.user_type', $eQb->createNamedParameter(
                        array_keys($userTypeToActorType),
                        \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY
                    )),
                ))
                ->where($eQb->expr()->eq('ms.circle_id', $eQb->createNamedParameter($teamId)))
                ->executeQuery();
            while ($eRow = $eRes->fetch()) {
                $uid       = (string)($eRow['user_id'] ?? '');
                $userType  = (int)($eRow['user_type'] ?? 0);
                $actorType = $userTypeToActorType[$userType] ?? null;
                if ($uid !== '' && $actorType !== null) {
                    $effective[$actorType . '|' . $uid] = ['actor_type' => $actorType, 'actor_id' => $uid];
                }
            }
            $eRes->closeCursor();

            // Safety net: circles_membership can lag for very freshly added
            // direct members. Also fold in the direct membership rows.
            $dQb  = $db->getQueryBuilder();
            $dRes = $dQb->select('user_id', 'user_type')
                ->from('circles_member')
                ->where($dQb->expr()->eq('circle_id', $dQb->createNamedParameter($teamId)))
                ->andWhere($dQb->expr()->in('user_type', $dQb->createNamedParameter(
                    array_keys($userTypeToActorType),
                    \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY
                )))
                ->andWhere($dQb->expr()->eq('status', $dQb->createNamedParameter('Member')))
                ->executeQuery();
            while ($dRow = $dRes->fetch()) {
                $uid       = (string)($dRow['user_id'] ?? '');
                $userType  = (int)($dRow['user_type'] ?? 0);
                $actorType = $userTypeToActorType[$userType] ?? null;
                if ($uid !== '' && $actorType !== null) {
                    $effective[$actorType . '|' . $uid] = ['actor_type' => $actorType, 'actor_id' => $uid];
                }
            }
            $dRes->closeCursor();

            // ── 3. Current talk_attendees rows for this room (managed types) ─
            $aQb  = $db->getQueryBuilder();
            $aRes = $aQb->select('actor_type', 'actor_id', 'participant_type')
                ->from('talk_attendees')
                ->where($aQb->expr()->eq('room_id',
                    $aQb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($aQb->expr()->in('actor_type', $aQb->createNamedParameter(
                    $managedActorTypes,
                    \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY
                )))
                ->executeQuery();
            $current = [];
            while ($aRow = $aRes->fetch()) {
                $actorType = (string)($aRow['actor_type'] ?? '');
                $actorId   = (string)($aRow['actor_id']   ?? '');
                if ($actorType !== '' && $actorId !== '') {
                    $current[$actorType . '|' . $actorId] = [
                        'actor_type'       => $actorType,
                        'actor_id'         => $actorId,
                        'participant_type' => (int)($aRow['participant_type'] ?? 0),
                    ];
                }
            }
            $aRes->closeCursor();

            // ── 4. Add missing attendees ─────────────────────────────────────
            $attendeeCols = $this->dbIntrospection->getTableColumns('talk_attendees');
            $added = 0;
            foreach ($effective as $key => $member) {
                if (isset($current[$key])) {
                    continue;
                }
                $iQb = $db->getQueryBuilder();
                $iQb->insert('talk_attendees')
                    ->setValue('room_id',          $iQb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    ->setValue('actor_type',       $iQb->createNamedParameter($member['actor_type']))
                    ->setValue('actor_id',         $iQb->createNamedParameter($member['actor_id']))
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
                    $this->logger->warning('[TeamHub][TalkService] reconcileEffectiveTalkRoomMembers: insert failed', [
                        'teamId' => $teamId, 'roomId' => $roomId,
                        'actorType' => $member['actor_type'], 'actorId' => $member['actor_id'],
                        'error'  => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }
            }

            // ── 5. Remove orphans (skip room owners) ─────────────────────────
            $removed = 0;
            foreach ($current as $key => $attendee) {
                if ($attendee['participant_type'] === 1) {
                    continue; // never evict a room owner
                }
                if (isset($effective[$key])) {
                    continue; // still a member
                }
                try {
                    $rQb = $db->getQueryBuilder();
                    $rQb->delete('talk_attendees')
                        ->where($rQb->expr()->eq('room_id',
                            $rQb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->andWhere($rQb->expr()->eq('actor_type', $rQb->createNamedParameter($attendee['actor_type'])))
                        ->andWhere($rQb->expr()->eq('actor_id',   $rQb->createNamedParameter($attendee['actor_id'])))
                        ->andWhere($rQb->expr()->neq('participant_type',
                            $rQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->executeStatement();
                    $removed++;
                } catch (\Throwable $e) {
                    $this->logger->warning('[TeamHub][TalkService] reconcileEffectiveTalkRoomMembers: delete failed', [
                        'teamId' => $teamId, 'roomId' => $roomId,
                        'actorType' => $attendee['actor_type'], 'actorId' => $attendee['actor_id'],
                        'error'  => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }
            }

            if ($added > 0 || $removed > 0) {
                $this->logger->info('[TeamHub][TalkService] reconcileEffectiveTalkRoomMembers: drift reconciled', [
                    'teamId' => $teamId, 'roomId' => $roomId,
                    'added'  => $added, 'removed' => $removed,
                    'effective' => count($effective), 'before' => count($current),
                    'app' => Application::APP_ID,
                ]);
            }

            return ['added' => $added, 'removed' => $removed];

        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TalkService] reconcileEffectiveTalkRoomMembers failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
            return ['added' => 0, 'removed' => 0];
        }
    }

    // =========================================================================
    // v4.2.14 — "What’s new" feed integration: polls + threads reader
    // =========================================================================

    /**
     * v4.2.18 — Talk stores certain comments as JSON system messages, e.g.
     * `{"message":"file_shared","parameters":{"metaData":{"caption":"…"}}}`.
     * Extract a human-readable string from that shape; regular chat
     * messages (which don't start with `{`) pass through untouched.
     *
     * Not exhaustive — the verbs we don't know about return the verb
     * humanised (`shared_by_current_user` → `Shared by current user`),
     * which is not perfect but better than a wall of JSON in the feed.
     */
    private function decodeTalkMessage(string $raw): string {
        if ($raw === '' || $raw[0] !== '{') {
            return $raw;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['message'])) {
            return $raw;
        }
        $verb   = (string)$decoded['message'];
        $params = is_array($decoded['parameters'] ?? null) ? $decoded['parameters'] : [];

        // File share: prefer the caption the user typed, else the filename.
        if ($verb === 'file_shared') {
            foreach (['metaData', 'file', 'share'] as $key) {
                $meta = $params[$key] ?? null;
                if (!is_array($meta)) continue;
                $caption = trim((string)($meta['caption'] ?? ''));
                if ($caption !== '') return $caption;
                $name = trim((string)($meta['name'] ?? ''));
                if ($name !== '') return $name;
            }
            return '📎 file';
        }
        // Object share (poll, geo, deck card…): use the object's name.
        if ($verb === 'object_shared') {
            $obj = $params['object'] ?? null;
            if (is_array($obj)) {
                $name = trim((string)($obj['name'] ?? ''));
                if ($name !== '') return $name;
            }
            return '🔗 shared object';
        }
        // Fallback: humanise the verb so at least it's not raw JSON.
        return ucfirst(str_replace('_', ' ', $verb));
    }

    /**
     * Return the Talk rooms accessible to the current user via TeamHub team
     * membership: every talk_rooms row whose circle attendee (actor_type=
     * 'circles', actor_id=<team>) is one of the supplied team ids.
     *
     * Caller is trusted to pass $userTeamIds already validated as the
     * viewer's own memberships.
     *
     * @return array<int, array{id:int, token:string, name:string}>
     */
    public function listRoomsForTeams(array $userTeamIds): array {
        if (empty($userTeamIds) || !$this->appManager->isInstalled('spreed')) {
            return [];
        }
        try {
            $db = $this->container->get(\OCP\IDBConnection::class);
            $qb = $db->getQueryBuilder();
            $qb->select('r.id', 'r.token', 'r.name')
                ->from('talk_rooms', 'r')
                ->innerJoin('r', 'talk_attendees', 'a',
                    $qb->expr()->andX(
                        $qb->expr()->eq('a.room_id',    'r.id'),
                        $qb->expr()->eq('a.actor_type', $qb->createNamedParameter('circles')),
                        $qb->expr()->in('a.actor_id',
                            $qb->createNamedParameter($userTeamIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY),
                        ),
                    ),
                );
            $res  = $qb->executeQuery();
            $rows = [];
            while ($row = $res->fetch()) {
                $rows[] = [
                    'id'    => (int)$row['id'],
                    'token' => (string)($row['token'] ?? ''),
                    'name'  => (string)($row['name'] ?? ''),
                ];
            }
            $res->closeCursor();
            return $rows;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TalkService] listRoomsForTeams failed', [
                'teams' => count($userTeamIds), 'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Resolve Talk rooms by token, in the same row shape as
     * listRoomsForTeams() (v4.5.45).
     *
     * Used for rooms the feed cannot reach through team membership: a decision
     * proposal shared with a selected audience gets its own conversation,
     * which is deliberately **not** registered as a team resource — doing that
     * would put every proposal room into the team's own resource-review queue.
     * So the feed is told about them by token instead, and the caller is
     * responsible for having decided the viewer may see each one.
     *
     * @param string[] $tokens
     * @return array<int, array{id:int, token:string, name:string}>
     */
    public function listRoomsByTokens(array $tokens): array {
        $tokens = array_values(array_unique(array_filter(
            $tokens,
            static fn ($t): bool => is_string($t) && $t !== '',
        )));
        if ($tokens === [] || !$this->appManager->isInstalled('spreed')) {
            return [];
        }

        try {
            $db = $this->container->get(\OCP\IDBConnection::class);
            $qb = $db->getQueryBuilder();
            $qb->select('id', 'token', 'name')
                ->from('talk_rooms')
                ->where($qb->expr()->in(
                    'token',
                    $qb->createNamedParameter($tokens, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY),
                ));

            $res  = $qb->executeQuery();
            $rows = [];
            while ($row = $res->fetch()) {
                $rows[] = [
                    'id'    => (int)$row['id'],
                    'token' => (string)($row['token'] ?? ''),
                    'name'  => (string)($row['name'] ?? ''),
                ];
            }
            $res->closeCursor();
            return $rows;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TalkService] listRoomsByTokens failed', [
                'count' => count($tokens), 'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Recent polls from a set of Talk room ids. Each row is shaped for the
     * "What’s new" feed (source='talk-poll', team-mapping applied by the
     * caller since a poll's room may serve multiple teams).
     *
     * The talk_polls schema shifts between Talk versions. Timestamp column
     * (`created_at` vs `created`) is detected via DbIntrospectionService;
     * when absent, rows are ordered by id DESC and carry created_at=0 —
     * still useful, just sorted by insertion order.
     *
     * @return array<int, array{source:string, id:int, room_id:int, question:string, options:array, votes:array, num_voters:int, status:int, actor_id:string, created_at:int}>
     */
    public function findRecentPolls(array $roomIds, int $limit, int $offset): array {
        if (empty($roomIds) || !$this->appManager->isInstalled('spreed')) {
            return [];
        }
        // v4.2.16 — no more DbIntrospection dependency. Justin's log showed
        // introspection returning `cols: []` even though talk_polls exists,
        // which made the previous defensive-select branch return early. We
        // now try the modern-Talk column set with SELECT *; MariaDB and
        // Postgres both return whatever columns the table has, and the
        // consumer only reads keys it knows about. Any SQL failure is
        // caught + logged with the actual error message.
        $db = $this->container->get(\OCP\IDBConnection::class);
        try {
            $qb = $db->getQueryBuilder();
            $qb->select('*')
                ->from('talk_polls')
                ->where($qb->expr()->in(
                    'room_id',
                    $qb->createNamedParameter($roomIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY),
                ))
                // id is monotonic in every Talk version; safe universal order.
                ->orderBy('id', 'DESC')
                ->setMaxResults($limit)
                ->setFirstResult($offset);

            // v4.5.27 — the announcement message is where the date lives on
            // this schema. One query for the whole room set, before the loop.
            $announcedAt = $this->findPollCreationTimes($roomIds);

            $res  = $qb->executeQuery();
            $rows = [];
            $seenColumns = null;
            while ($r = $res->fetch()) {
                if ($seenColumns === null) {
                    $seenColumns = array_keys($r);
                }
                $options = json_decode((string)($r['options'] ?? '[]'), true) ?: [];
                $votes   = json_decode((string)($r['votes']   ?? '{}'), true) ?: [];

                // v4.5.26 — **no more synthesised timestamps.**
                //
                // This used to fall back to `time() - $rank * 60` so polls
                // would sort near the top of the merged feed instead of
                // collapsing to 0. That is a lie with consequences: every poll
                // rendered as "created a few minutes ago", and once the feed
                // grew a Period filter, "Today" returned polls from weeks back
                // because the fabricated date really was today. Justin found
                // both symptoms at once.
                //
                // A date we do not have is now absent, and the row says so with
                // `date_unknown`. Callers exclude such rows from date-bounded
                // queries rather than guessing whether they fall in the window.
                $ts = 0;
                foreach (['created_at', 'created', 'timestamp', 'creation_timestamp', 'time', 'last_activity'] as $col) {
                    if (empty($r[$col])) {
                        continue;
                    }
                    $raw = $r[$col];
                    if (is_numeric($raw)) {
                        $ts = (int)$raw;
                    } elseif (is_string($raw)) {
                        // Talk stores some of its timestamps as datetime
                        // strings ("2026-07-20 12:12:14") — talk_threads does.
                        $parsed = strtotime($raw);
                        if ($parsed !== false) {
                            $ts = $parsed;
                        }
                    }
                    if ($ts > 0) {
                        break;
                    }
                }

                // No column on the poll itself — fall back to when it was
                // announced in the chat. That is the poll's creation moment on
                // every Talk version we have seen, and it is a real recorded
                // timestamp rather than a guess.
                if ($ts <= 0) {
                    $ts = $announcedAt[(int)$r['id']] ?? 0;
                }

                $rows[] = [
                    'source'       => 'talk-poll',
                    'id'           => (int)$r['id'],
                    'room_id'      => (int)$r['room_id'],
                    'question'     => (string)($r['question'] ?? ''),
                    'options'      => $options,
                    'votes'        => $votes,
                    'num_voters'   => (int)($r['num_voters'] ?? 0),
                    'status'       => (int)($r['status'] ?? 0),
                    'actor_id'     => (string)($r['actor_id'] ?? ''),
                    'created_at'   => $ts,
                    'date_unknown' => $ts <= 0,
                ];
            }
            $res->closeCursor();

            // Warning, not debug, and only when it actually happened: this is
            // the one diagnostic that turns "polls have no date" into a
            // one-line fix, and it is no use to anyone sitting behind a log
            // level nobody runs in production. Column *names* only — no
            // question text, no votes, no actor ids.
            if ($seenColumns !== null && !empty($rows) && ($rows[0]['date_unknown'] ?? false)) {
                $this->logger->warning('[TeamHub][TalkService] a poll has no date from either talk_polls or its chat announcement — it will show without one and is excluded from period filters. talk_polls columns: {cols}', [
                    'cols' => implode(', ', $seenColumns),
                    'app'  => Application::APP_ID,
                ]);
            }

            return $rows;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TalkService] findRecentPolls failed', [
                'rooms' => count($roomIds),
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'app'   => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Recent Talk thread starters from a set of Talk room ids. Threads
     * are a Talk 20+ (NC 30+) feature — the table may not exist on older
     * installs, in which case we return [] without erroring.
     *
     * A thread carries a `first_message_id` (or `last_message_id` and
     * `num_replies`) that points into NC core `comments`. The starter's
     * text + creation_timestamp comes from that comment; the reply count
     * from the thread row itself.
     *
     * @return array<int, array{source:string, id:int, room_id:int, subject:string, message:string, num_replies:int, actor_id:string, created_at:int}>
     */
    public function findRecentThreads(array $roomIds, int $limit, int $offset): array {
        if (empty($roomIds) || !$this->appManager->isInstalled('spreed')) {
            return [];
        }
        // v4.2.16 — same rationale as findRecentPolls: DbIntrospection
        // wasn't reliably returning columns even when the table exists.
        // Try SELECT * directly; if the table itself is missing (older
        // Talk that predates threads) the query throws and we skip. The
        // consumer only reads well-known keys and defaults the rest.
        $db = $this->container->get(\OCP\IDBConnection::class);
        try {
            $qb = $db->getQueryBuilder();
            $qb->select('*')
                ->from('talk_threads')
                ->where($qb->expr()->in(
                    'room_id',
                    $qb->createNamedParameter($roomIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY),
                ))
                ->orderBy('id', 'DESC')
                ->setMaxResults($limit)
                ->setFirstResult($offset);
            $res  = $qb->executeQuery();
            $rawThreads = [];
            while ($r = $res->fetch()) {
                $rawThreads[] = $r;
            }
            $res->closeCursor();
            if (empty($rawThreads)) {
                return [];
            }

            // Hydrate the first-message comment for each thread. Convention
            // on this schema (verified via the v4.2.20 debug log): the
            // comment with id = talk_threads.id IS the first message posted
            // in the thread. `talk_threads.name` caches the thread's title
            // (the topic — the message being replied to). We use `name` as
            // the subject and the comment.message as the body.
            $threadIds = array_map(static fn($r) => (int)$r['id'], $rawThreads);
            $commentMap = [];
            try {
                $cQb  = $this->container->get(\OCP\IDBConnection::class)->getQueryBuilder();
                $cRes = $cQb->select('id', 'message', 'actor_id')
                    ->from('comments')
                    ->where($cQb->expr()->in(
                        'id',
                        $cQb->createNamedParameter($threadIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY),
                    ))
                    ->executeQuery();
                while ($cr = $cRes->fetch()) {
                    $commentMap[(int)$cr['id']] = [
                        'message'  => (string)($cr['message'] ?? ''),
                        'actor_id' => (string)($cr['actor_id'] ?? ''),
                    ];
                }
                $cRes->closeCursor();
            } catch (\Throwable $ce) {
                // Non-fatal — cards degrade to subject-only.
                $this->logger->warning('[TeamHub][TalkService] findRecentThreads: comment hydration failed', [
                    'error' => $ce->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            $out  = [];
            $now  = time();
            $rank = 0;
            foreach ($rawThreads as $r) {
                $title = trim((string)($r['name'] ?? ''));
                if ($title === '') {
                    foreach (['title', 'subject', 'topic'] as $altCol) {
                        if (!empty($r[$altCol])) {
                            $title = trim((string)$r[$altCol]);
                            break;
                        }
                    }
                }
                if ($title === '') {
                    continue;
                }

                // `last_activity` is a datetime STRING on this schema
                // (`"2026-07-20 12:12:14"`), not a Unix int. Parse both
                // shapes so we work across Talk versions.
                $tsRaw = $r['last_activity'] ?? ($r['last_message_at'] ?? ($r['updated_at'] ?? 0));
                $ts = 0;
                if (is_numeric($tsRaw)) {
                    $ts = (int)$tsRaw;
                } elseif (is_string($tsRaw) && $tsRaw !== '') {
                    $parsed = strtotime($tsRaw);
                    if ($parsed !== false) $ts = $parsed;
                }
                if ($ts <= 0) {
                    $ts = $now - $rank * 60;
                }

                $numReplies = 0;
                foreach (['num_replies', 'reply_count', 'replies'] as $rc) {
                    if (isset($r[$rc])) { $numReplies = (int)$r[$rc]; break; }
                }

                // Body = first message in the thread. decodeTalkMessage
                // unwraps Talk system-message JSON (file_shared → caption)
                // so a file-share thread doesn't render raw JSON. Falls
                // back to the title if the comment lookup came up empty.
                $comment = $commentMap[(int)$r['id']] ?? null;
                $body    = $comment
                    ? $this->decodeTalkMessage($comment['message'])
                    : $title;
                $author  = $comment['actor_id'] ?? '';

                $out[] = [
                    'source'      => 'talk-thread',
                    'id'          => (int)$r['id'],
                    'room_id'     => (int)$r['room_id'],
                    'subject'     => mb_strlen($title) > 120 ? (mb_substr($title, 0, 120) . '…') : $title,
                    'message'     => $body,
                    'num_replies' => $numReplies,
                    'actor_id'    => $author,
                    'created_at'  => $ts,
                ];
                $rank++;
            }
            return $out;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TalkService] findRecentThreads failed', [
                'rooms' => count($roomIds),
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
            return [];
        }
    }

    // =========================================================================
    // v4.5.26 — "What's new" interaction: thread replies and poll votes
    //
    // Reads come from Talk's tables directly, which is the pattern the feed
    // already established (DESIGN §2.68 — read what's actually there, because
    // the schema shifts between Talk versions).
    //
    // Writes never do. Every write below goes through Talk's own service
    // objects so its participant checks, activity, notifications and read
    // markers all fire; SKILLS.md § "If a TeamHub or NC API does not work,
    // report it" rules out reaching around them. Because those signatures move
    // between Talk versions, arguments are matched by **reflection against the
    // real method**, exactly as ApprovalWorkProvider does for Approval's
    // approve/reject after v4.5.22 shipped a guessed argument list and failed
    // in Justin's install. A parameter we cannot fill throws by name rather
    // than failing somewhere downstream with a mystery.
    // =========================================================================

    /**
     * Replies inside a Talk thread, oldest first.
     *
     * Thread identity follows the convention `findRecentThreads` documents and
     * the v4.2.20 debug log confirmed: `talk_threads.id` **is** the id of the
     * thread's first `comments` row. Replies therefore point back at it —
     * through `topmost_parent_id` on schemas that have it, `parent_id` on those
     * that don't. Both are tried rather than one being assumed.
     *
     * System messages (joins, calls, shares) are dropped: the feed shows a
     * conversation, and Talk's own UI renders those as chrome rather than as
     * replies.
     *
     * @return array<int, array{id:int, actor_id:string, message:string, created_at:int}>
     */
    public function findThreadReplies(int $roomId, int $threadId, int $limit = 50): array {
        if ($threadId <= 0 || !$this->appManager->isInstalled('spreed')) {
            return [];
        }
        $limit = max(1, min(200, $limit));

        // Ordered: the modern column first. A schema without it throws on the
        // first query and the second shape answers instead.
        //
        // $parentColumn reaches the QueryBuilder as an identifier rather than a
        // bound value, so it is worth being explicit: both values are literals
        // in this foreach and nothing here is reachable from a request. Every
        // *value* below is bound with createNamedParameter.
        foreach (['topmost_parent_id', 'parent_id'] as $parentColumn) {
            try {
                $db = $this->container->get(\OCP\IDBConnection::class);
                $qb = $db->getQueryBuilder();
                $qb->select('id', 'actor_id', 'actor_type', 'message', 'verb', 'creation_timestamp')
                    ->from('comments')
                    ->where($qb->expr()->eq('object_type', $qb->createNamedParameter('chat')))
                    // object_id is a string column in core comments even though
                    // it holds a room id.
                    ->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter((string)$roomId)))
                    ->andWhere($qb->expr()->eq($parentColumn, $qb->createNamedParameter($threadId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    // The thread starter is already the card's body.
                    ->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($threadId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->orderBy('creation_timestamp', 'ASC')
                    ->addOrderBy('id', 'ASC')
                    ->setMaxResults($limit);

                $res = $qb->executeQuery();
                $out = [];
                while ($r = $res->fetch()) {
                    if ((string)($r['verb'] ?? '') === 'system') {
                        continue;
                    }
                    $ts = 0;
                    $raw = $r['creation_timestamp'] ?? null;
                    if (is_numeric($raw)) {
                        $ts = (int)$raw;
                    } elseif (is_string($raw) && $raw !== '') {
                        $parsed = strtotime($raw);
                        if ($parsed !== false) {
                            $ts = $parsed;
                        }
                    }
                    $out[] = [
                        'id'         => (int)$r['id'],
                        // Federated actors are 'federated_users'; keeping the raw
                        // actor_id means the frontend renders the id rather than
                        // silently attributing the reply to a local user of the
                        // same name.
                        'actor_id'   => (string)($r['actor_id'] ?? ''),
                        'actor_type' => (string)($r['actor_type'] ?? ''),
                        'message'    => $this->decodeTalkMessage((string)($r['message'] ?? '')),
                        'created_at' => $ts,
                    ];
                }
                $res->closeCursor();
                return $out;
            } catch (\Throwable $e) {
                $this->logger->debug('[TeamHub][TalkService] findThreadReplies — {col} shape did not answer', [
                    'col' => $parentColumn, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        $this->logger->warning('[TeamHub][TalkService] findThreadReplies — no known thread-parent column', [
            'app' => Application::APP_ID,
        ]);
        return [];
    }

    /**
     * Numeric room id for a Talk token, or 0 when there is no such room.
     *
     * Read through Talk's own manager rather than a `talk_rooms` SELECT: this
     * feeds an authorisation decision (MessageService::resolveFeedRoomTeam),
     * and Talk's manager is the thing that knows what a valid, non-deleted room
     * is on this version.
     */
    public function findRoomIdByToken(string $token): int {
        if (trim($token) === '' || !$this->appManager->isInstalled('spreed')) {
            return 0;
        }
        try {
            $room = $this->container->get(\OCA\Talk\Manager::class)->getRoomByToken($token);
            return $room ? (int)$room->getId() : 0;
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][TalkService] findRoomIdByToken — no room', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return 0;
        }
    }

    /**
     * Whether $uid can post into the room behind $token right now.
     *
     * Asked before the feed offers a reply box or a vote control, so a member
     * of a read-only room is not invited to write into it. This is a
     * presentation gate — the write paths below re-derive the participant
     * through Talk regardless, and Talk refuses on its own terms.
     */
    public function canPostToRoom(string $token, string $uid): bool {
        if ($token === '' || !$this->appManager->isInstalled('spreed')) {
            return false;
        }
        try {
            $room = $this->container->get(\OCA\Talk\Manager::class)->getRoomByToken($token);
            if (!$room) {
                return false;
            }
            // Throws when $uid is not a participant, which is the answer.
            $this->container->get(\OCA\Talk\Service\ParticipantService::class)->getParticipant($room, $uid);

            if (method_exists($room, 'getReadOnly') && (int)$room->getReadOnly() !== 0) {
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][TalkService] canPostToRoom — refused', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * Post a reply into a Talk thread on behalf of $uid.
     *
     * @return array{ok:bool, error:string}
     */
    public function replyToThread(string $token, int $threadId, string $uid, string $message, bool $asThread = true): array {
        if (!$this->appManager->isInstalled('spreed')) {
            return ['ok' => false, 'error' => 'Talk is not installed'];
        }
        try {
            $room = $this->container->get(\OCA\Talk\Manager::class)->getRoomByToken($token);
            if (!$room) {
                return ['ok' => false, 'error' => 'Conversation not found'];
            }
            $participant = $this->container->get(\OCA\Talk\Service\ParticipantService::class)
                ->getParticipant($room, $uid);

            // The thread's first message, which is what Talk threads a reply
            // onto. Resolved through core's comments API rather than by hand —
            // ChatManager wants the IComment, not its id.
            $replyTo = null;
            try {
                $replyTo = $this->container->get(\OCP\Comments\ICommentsManager::class)->get((string)$threadId);
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'The message being replied to no longer exists'];
            }
            if ($replyTo === null || (string)$replyTo->getObjectId() !== (string)$room->getId()) {
                // Refuses a threadId from another room — without this, a
                // caller could thread a reply into a conversation they can
                // reach by naming a token they can.
                return ['ok' => false, 'error' => 'That message is not in this conversation'];
            }

            $chatManager = $this->container->get(\OCA\Talk\Chat\ChatManager::class);
            $candidates = [
                'room'        => $room,
                'participant' => $participant,
                'actortype'   => 'users',
                'actorid'     => $uid,
                'message'     => $message,
                'datetime'    => new \DateTime(),
                'replyto'     => $replyTo,
                'referenceid' => '',
                'silent'      => false,
            ];
            // v4.5.33 — `threadId` is offered only when the target really is a
            // thread. A Talk *mention* is an ordinary chat message that has no
            // thread yet, and handing its comment id to a `$threadId` parameter
            // would assert an association that does not exist. Without the
            // candidate the parameter takes its own default and Talk derives
            // whatever threading it wants from `replyTo`, which is the
            // mechanism replies have always used.
            if ($asThread) {
                $candidates['threadid'] = $threadId;
            }
            $chatManager->sendMessage(...$this->matchTalkArguments($chatManager, 'sendMessage', $candidates));

            return ['ok' => true, 'error' => ''];
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TalkService] replyToThread failed', [
                'threadId' => $threadId, 'error' => $e->getMessage(),
                'class' => get_class($e), 'app' => Application::APP_ID,
            ]);
            // The underlying message is surfaced rather than swallowed — that
            // is what turned finding 22 in 4.5.22 from a mystery into a
            // one-line fix.
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Decision proposals — discussion surfaces (v4.5.42)
    // =========================================================================

    /**
     * Create a group conversation for a decision proposal and invite people.
     *
     * Used by share mode `selected`: the proposer names a set of colleagues and
     * the proposal gets its own room to be argued in. The room is a plain Talk
     * group conversation owned by the proposer — TeamHub records the token on
     * the decision row and otherwise does not manage it. Deleting the room
     * later is Talk's business; the decision keeps a dead token, which reads
     * as "the discussion is gone", not as an error.
     *
     * Participants are added Talk's way first and by direct attendee insert
     * only if that fails — the same two-strategy shape `createTalkRoom()` uses
     * for the circle, and for the same reason: when Talk's own API runs, Talk's
     * event system runs with it and the room appears in each person's list
     * natively.
     *
     * @param string[] $userIds people to invite; the proposer is already the owner
     * @return array{ok:bool, token:?string, invited:int, error:string}
     */
    public function createProposalRoom(
        string $roomName,
        array  $userIds,
        string $uid,
        string $openingMessage,
    ): array {
        if (!$this->appManager->isInstalled('spreed')) {
            return ['ok' => false, 'token' => null, 'invited' => 0, 'error' => 'Talk is not installed'];
        }

        try {
            $userManager = $this->container->get(\OCP\IUserManager::class);
            $owner       = $userManager->get($uid);
            if ($owner === null) {
                return ['ok' => false, 'token' => null, 'invited' => 0, 'error' => 'Proposer not found'];
            }

            // Type 2 = TYPE_GROUP, same constant createTalkRoom() uses.
            $roomService = $this->container->get(\OCA\Talk\Service\RoomService::class);
            $room        = $roomService->createConversation(2, $roomName, $owner);
            $token       = $room->getToken();

            $invited = $this->addUsersToRoom($room, $userIds, $owner);

            // Best-effort: a room with the right people in it and no opening
            // post is still a usable discussion, so a failed post does not
            // fail the share.
            if ($openingMessage !== '') {
                $this->postChatMessage($token, $uid, $openingMessage);
            }

            $this->logger->info('[TeamHub][TalkService] createProposalRoom', [
                'token' => $token, 'invited' => $invited,
                'requested' => count($userIds), 'app' => Application::APP_ID,
            ]);

            return ['ok' => true, 'token' => $token, 'invited' => $invited, 'error' => ''];
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TalkService] createProposalRoom failed', [
                'error' => $e->getMessage(), 'class' => get_class($e),
                'app' => Application::APP_ID,
            ]);
            return ['ok' => false, 'token' => null, 'invited' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Add users to a room, Talk's API first and a direct attendee insert after.
     *
     * `ParticipantService::addUsers()` takes an array of participant
     * descriptors whose exact keys have moved between Talk versions, so the
     * call is attempted and its failure treated as ordinary. The fallback is
     * the row shape `expandCircleMembersToTalk()` has been writing since
     * v3.x — proven on this codebase's supported Talk range.
     *
     * @param string[] $userIds
     * @return int how many were added
     */
    private function addUsersToRoom(object $room, array $userIds, object $addedBy): int {
        $userIds = array_values(array_unique(array_filter(
            $userIds,
            static fn ($u): bool => is_string($u) && $u !== '',
        )));
        if ($userIds === []) {
            return 0;
        }

        $userManager = $this->container->get(\OCP\IUserManager::class);

        // ── Strategy 1: Talk's own ParticipantService ─────────────────────
        try {
            $participantService = $this->container->get(\OCA\Talk\Service\ParticipantService::class);
            $descriptors = [];
            foreach ($userIds as $memberUid) {
                $user = $userManager->get($memberUid);
                if ($user === null) {
                    continue;
                }
                $descriptors[] = [
                    'actorType'   => 'users',
                    'actorId'     => $memberUid,
                    'displayName' => $user->getDisplayName(),
                ];
            }
            if ($descriptors === []) {
                return 0;
            }

            $participantService->addUsers(...$this->matchTalkArguments(
                $participantService,
                'addUsers',
                [
                    'room'         => $room,
                    'participants' => $descriptors,
                    'addedby'      => $addedBy,
                ],
            ));

            $this->logger->debug('[TeamHub][TalkService] addUsersToRoom — Talk API path', [
                'count' => count($descriptors), 'app' => Application::APP_ID,
            ]);
            return count($descriptors);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TalkService] addUsersToRoom — Talk API failed, falling back to direct insert', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // ── Strategy 2: direct attendee rows ──────────────────────────────
        $db     = $this->container->get(\OCP\IDBConnection::class);
        $roomId = (int)$room->getId();
        $cols   = $this->dbIntrospection->getTableColumns('talk_attendees');
        $added  = 0;

        foreach ($userIds as $memberUid) {
            if ($userManager->get($memberUid) === null) {
                continue;
            }
            try {
                $qb = $db->getQueryBuilder();
                $qb->insert('talk_attendees')
                    ->setValue('room_id',          $qb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    ->setValue('actor_type',       $qb->createNamedParameter('users'))
                    ->setValue('actor_id',         $qb->createNamedParameter($memberUid))
                    ->setValue('display_name',     $qb->createNamedParameter(''))
                    ->setValue('participant_type', $qb->createNamedParameter(3, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT));

                foreach ([
                    'favorite' => 0, 'notification_level' => 0, 'notification_calls' => 0,
                    'last_joined_call' => 0, 'last_read_message' => 0,
                    'last_mention_message' => 0, 'last_mention_direct' => 0,
                    'in_call' => 0, 'permissions' => 0, 'publishing_permissions' => 0,
                    'access_token' => '', 'remote_id' => '', 'phone_number' => '', 'phone_states' => '',
                ] as $col => $val) {
                    if (in_array($col, $cols, true)) {
                        $qb->setValue($col, $qb->createNamedParameter($val));
                    }
                }

                $qb->executeStatement();
                $added++;
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][TalkService] addUsersToRoom — attendee insert failed', [
                    'uid' => $memberUid, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        return $added;
    }

    /**
     * Post a proposal into a room and make it a thread if this Talk can.
     *
     * Used by share mode `team`: the proposal goes into the team conversation
     * so every member can respond in one place.
     *
     * **The title is what makes it a thread — but not in the shape v4.5.47
     * assumed.** That version passed a flat `threadtitle` string candidate, on
     * the strength of the field name in Talk's *HTTP* chat API, and
     * `matchTalkArguments` silently drops any candidate the method does not
     * declare. So on a `sendMessage()` without that parameter the title went
     * nowhere and nothing said so — v4.5.44's silence with only the false
     * error message taken out.
     *
     * This version reads the real parameter list instead of assuming either
     * shape (`placeThreadTitle()`) and reports which one it found. The two
     * candidates are a **metadata array** (`['threadTitle' => …]`) and a
     * dedicated **`$threadTitle` string**.
     *
     * When the method declares neither, the post still succeeds as a plain
     * message and the full signature is logged at **warning** — so the next
     * version is a one-line change rather than a fourth guess. That
     * degradation is sound on its own terms: a thread is a message somebody
     * replied to, and `talk_threads.id` is the root message's id either way
     * (verified in v4.2.20, relied on by `findRecentThreads`), so the
     * discussion works regardless.
     *
     * v4.5.44 got this worse: it tried to force a thread through an
     * unverified `ThreadService`, and when nothing happened it told the user
     * "this version of Talk does not support threads" — false on every
     * instance, including ones where threads work perfectly.
     *
     * `threadId` is the posted message's own id — the id the thread has, or
     * takes on the first reply. Recording it is what lets the proposal link
     * straight to the discussion. `threaded` is **checked, not inferred**: a
     * `talk_threads` row under that id either exists or it does not.
     *
     * @param string $threadTitle thread subject; empty posts a plain message
     * @return array{ok:bool, threadId:?int, messageId:?int, threaded:bool, titlePlacement:string, error:string}
     */
    public function startProposalThread(string $token, string $uid, string $message, string $threadTitle = ''): array {
        $failed = ['ok' => false, 'threadId' => null, 'messageId' => null, 'threaded' => false, 'titlePlacement' => 'none'];

        if (!$this->appManager->isInstalled('spreed')) {
            return $failed + ['error' => 'Talk is not installed'];
        }

        try {
            $room = $this->container->get(\OCA\Talk\Manager::class)->getRoomByToken($token);
            if (!$room) {
                return $failed + ['error' => 'Conversation not found'];
            }

            // Circle-only members have no direct attendee row — see
            // resolveParticipant(). A bare getParticipant() here is what made
            // "discuss with the whole team" fail for every indirect member.
            $participant = $this->resolveParticipant($room, $token, $uid);
            if ($participant === null) {
                return $failed + ['error' => 'You are not a participant in this conversation'];
            }

            $candidates = [
                'room'        => $room,
                'participant' => $participant,
                'actortype'   => 'users',
                'actorid'     => $uid,
                'message'     => $message,
                'datetime'    => new \DateTime(),
                'replyto'     => null,
                'referenceid' => '',
                'silent'      => false,
            ];
            $chatManager = $this->container->get(\OCA\Talk\Chat\ChatManager::class);

            // Placed only when there is a title. An empty one on a Talk that
            // *does* accept it would create a nameless thread, which is worse
            // than a plain message.
            $placement = $threadTitle !== ''
                ? $this->placeThreadTitle($chatManager, $threadTitle, $candidates)
                : 'none';

            // Warning, not debug: a title that had nowhere to go is the
            // difference between a discussion thread and a chat message lost
            // in the day's scroll, and this line is the only place that can
            // say so. It carries the signature because *which* parameters
            // exist is the fact three versions have now guessed at.
            if ($placement === 'none' && $threadTitle !== '') {
                $this->logger->warning('[TeamHub][TalkService] startProposalThread — this Talk declares no place for a thread title', [
                    'signature' => $this->describeMethodSignature($chatManager, 'sendMessage'),
                    'app' => Application::APP_ID,
                ]);
            }

            $comment = $chatManager->sendMessage(...$this->matchTalkArguments($chatManager, 'sendMessage', $candidates));

            // sendMessage returns the IComment on every Talk version we have
            // seen, but the return type has not always been declared, so this
            // reads defensively rather than assuming.
            $messageId = null;
            if (is_object($comment) && method_exists($comment, 'getId')) {
                $messageId = (int)$comment->getId();
            }

            // Asked, not assumed. The whole reason this method has been
            // rewritten three times is that nobody checked whether the thread
            // it claimed to create existed.
            $threaded = $this->threadRowExists($messageId);

            $this->logger->info('[TeamHub][TalkService] startProposalThread', [
                'token' => $token, 'messageId' => $messageId,
                'titlePlacement' => $placement, 'threaded' => $threaded,
                'app' => Application::APP_ID,
            ]);

            return [
                'ok'        => true,
                // The message id IS the id the thread takes once anyone
                // replies, so storing it now is not a claim that a thread
                // already exists — it is the handle for the one that will.
                'threadId'  => $messageId,
                'messageId' => $messageId,
                'threaded'  => $threaded,
                'titlePlacement' => $placement,
                'error'     => '',
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TalkService] startProposalThread failed', [
                'token' => $token, 'error' => $e->getMessage(),
                'class' => get_class($e), 'app' => Application::APP_ID,
            ]);
            return $failed + ['error' => $e->getMessage()];
        }
    }

    /**
     * Put the thread title where this Talk's `sendMessage()` will accept it.
     *
     * Two shapes exist and neither can be tested from the dev environment, so
     * neither is assumed — the declared parameter list decides:
     *
     * 1. **Inside a metadata array** — `['threadTitle' => …]` on an array
     *    parameter whose name contains `metadata` (`$metaData`,
     *    `$talkMetaData`). Justin's reading of Talk's chat API, and the shape
     *    v4.5.47 did not implement.
     * 2. **As its own string parameter** — `$threadTitle`, which is the field
     *    name the HTTP API documents and what v4.5.47 assumed the internal
     *    method took as well.
     *
     * **The dedicated parameter wins when both exist.** A method that names a
     * parameter after exactly this value is telling you where the value goes;
     * a metadata bag is a general-purpose container, so putting it there while
     * leaving the specific parameter empty would be choosing the vaguer of two
     * declared answers.
     *
     * An existing metadata candidate is merged rather than replaced, so this
     * can never quietly discard something a caller set.
     *
     * @param array<string,mixed> $candidates keyed by lower-case parameter name; modified in place
     * @return string 'metadata' | 'parameter' | 'none'
     */
    private function placeThreadTitle(object $chatManager, string $threadTitle, array &$candidates): string {
        try {
            $params = (new \ReflectionMethod($chatManager, 'sendMessage'))->getParameters();
        } catch (\Throwable $e) {
            // sendMessage() not being reflectable is not this method's problem
            // to report — matchTalkArguments throws on it a few lines later,
            // with a message about the method rather than about the title.
            return 'none';
        }

        $metadataKey = null;

        foreach ($params as $param) {
            $name = strtolower($param->getName());
            $type = $param->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';

            if (str_contains($name, 'threadtitle') && ($typeName === 'string' || $typeName === '')) {
                $candidates[$name] = $threadTitle;
                return 'parameter';
            }
            // Remembered, not used yet — the loop has to finish before we know
            // whether a dedicated parameter also exists further along the list.
            if ($metadataKey === null && str_contains($name, 'metadata') && ($typeName === 'array' || $typeName === '')) {
                $metadataKey = $name;
            }
        }

        if ($metadataKey !== null) {
            $existing = is_array($candidates[$metadataKey] ?? null) ? $candidates[$metadataKey] : [];
            $candidates[$metadataKey] = array_merge($existing, ['threadTitle' => $threadTitle]);
            return 'metadata';
        }

        return 'none';
    }

    /**
     * Does Talk hold a thread rooted at this message?
     *
     * `talk_threads.id` is the root message's id (verified in v4.2.20 and
     * relied on by `findRecentThreads`), so this is a primary-key lookup.
     *
     * False on any failure, including a Talk too old to have the table. The
     * caller reports it as "no thread", which is the truth in every one of
     * those cases — the message was still posted.
     */
    private function threadRowExists(?int $messageId): bool {
        if ($messageId === null) {
            return false;
        }
        try {
            $qb = $this->container->get(\OCP\IDBConnection::class)->getQueryBuilder();
            $res = $qb->select('id')
                ->from('talk_threads')
                ->where($qb->expr()->eq(
                    'id',
                    $qb->createNamedParameter($messageId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                ))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();
            return $row !== false;
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][TalkService] threadRowExists — lookup failed', [
                'messageId' => $messageId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * A method's real parameter list, as a readable string.
     *
     * Exists so a log line and the admin diagnostic can both say what this
     * Talk actually declares instead of what TeamHub hoped it would.
     *
     * @param object|class-string $service an instance, or a class name for a
     *                                     service we have no reason to build
     */
    private function describeMethodSignature(object|string $service, string $method): string {
        try {
            $params = array_map(
                static fn (\ReflectionParameter $p): string =>
                    ($p->getType() instanceof \ReflectionNamedType ? $p->getType()->getName() . ' ' : '')
                    . '$' . $p->getName()
                    . ($p->isDefaultValueAvailable() ? ' = …' : ''),
                (new \ReflectionMethod($service, $method))->getParameters(),
            );
            return $method . '(' . implode(', ', $params) . ')';
        } catch (\Throwable $e) {
            return $method . '(— not reflectable: ' . $e->getMessage() . ')';
        }
    }

    /**
     * Resolve $uid's participant record in a room, including circle-only members.
     *
     * **`ParticipantService::getParticipant()` is a direct attendee lookup.**
     * It reads a `talk_attendees` row with `actor_type = 'users'`, and a member
     * who reaches the team conversation through the *circle* attendee row —
     * anyone whose team membership is indirect, via a group or a nested team —
     * does not have one. They can read and write the conversation in Talk's own
     * UI, because Talk resolves circle membership when it opens a room for a
     * user; they simply have no row for a bare lookup to find.
     *
     * So: ask Talk to open the room *for that user* first, which is the call
     * that performs the circle resolution, and only then ask for the
     * participant. Guarded by `method_exists` because the room-for-user
     * accessor has been renamed upstream more than once.
     *
     * Returns null when the user genuinely has no access — the caller decides
     * whether that is an error or an answer.
     *
     * @return object|null the Talk Participant
     */
    private function resolveParticipant(object $room, string $token, string $uid): ?object {
        $participantService = $this->container->get(\OCA\Talk\Service\ParticipantService::class);

        // 1. Direct attendee row — the common case, and unchanged behaviour.
        try {
            return $participantService->getParticipant($room, $uid);
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][TalkService] resolveParticipant — no direct attendee row, trying circle resolution', [
                'uid' => $uid, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // 2. Let Talk open the room for this user, which resolves circles, then
        //    look again. On versions that materialise an attendee row at that
        //    point the second lookup succeeds; on versions that return a room
        //    carrying the participant, we read it off the room.
        try {
            $manager = $this->container->get(\OCA\Talk\Manager::class);
            foreach (['getRoomForUserByToken', 'getRoomByToken'] as $method) {
                if (!method_exists($manager, $method)) {
                    continue;
                }
                try {
                    $userRoom = $manager->$method($token, $uid);
                } catch (\Throwable) {
                    continue;
                }
                if (!is_object($userRoom)) {
                    continue;
                }
                try {
                    return $participantService->getParticipant($userRoom, $uid);
                } catch (\Throwable) {
                    // Keep trying the next accessor rather than giving up on
                    // the first one that does not resolve.
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][TalkService] resolveParticipant — circle resolution failed', [
                'uid' => $uid, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        return null;
    }

    /**
     * What TeamHub can tell about this Talk's threading support.
     *
     * Ships with the integration because the integration was written against
     * an API that could not be tested here — the principle recorded in
     * DESIGN.md for ApprovalWorkProvider: when you integrate against something
     * you cannot verify, ship the means to diagnose it.
     *
     * @return array<string,mixed>
     */
    public function getThreadingDiagnostics(): array {
        $out = [
            'talkInstalled'        => $this->appManager->isInstalled('spreed'),
            'sendMessageSignature' => '',
            'threadTitlePlacement' => 'none',
            'threadServiceExists'  => false,
            'threadServiceMethods' => [],
            'talkThreadsTable'     => false,
        ];

        if (!$out['talkInstalled']) {
            return $out;
        }

        // **The line worth reading.** `startProposalThread()` has been written
        // three times against an assumed `ChatManager::sendMessage()`; this
        // reports the one that is actually installed, and where a thread title
        // would land on it. Reflection only — nothing is sent.
        try {
            $chatManager = $this->container->get(\OCA\Talk\Chat\ChatManager::class);
            $out['sendMessageSignature'] = $this->describeMethodSignature($chatManager, 'sendMessage');

            $probe = [];
            $out['threadTitlePlacement'] = $this->placeThreadTitle($chatManager, 'probe', $probe);
        } catch (\Throwable $e) {
            $out['sendMessageError'] = $e->getMessage();
        }

        try {
            if (class_exists(\OCA\Talk\Service\ThreadService::class)) {
                $out['threadServiceExists'] = true;
                foreach ((new \ReflectionClass(\OCA\Talk\Service\ThreadService::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                    $out['threadServiceMethods'][] = $this->describeMethodSignature(
                        \OCA\Talk\Service\ThreadService::class, $m->getName(),
                    );
                }
            }
        } catch (\Throwable $e) {
            $out['threadServiceError'] = $e->getMessage();
        }

        try {
            $out['talkThreadsTable'] = $this->dbIntrospection->getTableColumns('talk_threads') !== [];
        } catch (\Throwable $e) {
            $out['talkThreadsError'] = $e->getMessage();
        }

        return $out;
    }

    /**
     * Cast $uid's vote on a Talk poll.
     *
     * @param int[] $optionIds indices into the poll's option list
     * @return array{ok:bool, error:string}
     */
    public function votePoll(string $token, int $pollId, array $optionIds, string $uid): array {
        if (!$this->appManager->isInstalled('spreed')) {
            return ['ok' => false, 'error' => 'Talk is not installed'];
        }
        try {
            $room = $this->container->get(\OCA\Talk\Manager::class)->getRoomByToken($token);
            if (!$room) {
                return ['ok' => false, 'error' => 'Conversation not found'];
            }
            $participant = $this->container->get(\OCA\Talk\Service\ParticipantService::class)
                ->getParticipant($room, $uid);

            $pollService = $this->container->get(\OCA\Talk\Service\PollService::class);

            // The poll must belong to this room. Talk's own service enforces
            // this too, but doing it here means a mismatch reads as a refusal
            // rather than as whatever exception Talk happens to throw.
            $poll = null;
            foreach (['getPoll', 'getPollById'] as $getter) {
                if (!method_exists($pollService, $getter)) {
                    continue;
                }
                try {
                    // Reflection here too — `(roomId, pollId)` is the obvious
                    // order and obvious is not the same as verified.
                    $poll = $pollService->$getter(...$this->matchTalkArguments($pollService, $getter, [
                        'roomid' => $room->getId(),
                        'pollid' => $pollId,
                        'room'   => $room,
                    ]));
                    break;
                } catch (\Throwable) {
                    // Try the next shape; a genuine mismatch falls through to
                    // the null check below.
                }
            }
            if ($poll === null) {
                return ['ok' => false, 'error' => 'Poll not found in this conversation'];
            }

            $args = $this->matchTalkArguments($pollService, 'votePoll', [
                'participant' => $participant,
                'poll'        => $poll,
                'pollid'      => $pollId,
                'option'      => array_values($optionIds),
                'room'        => $room,
            ]);
            $pollService->votePoll(...$args);

            return ['ok' => true, 'error' => ''];
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TalkService] votePoll failed', [
                'pollId' => $pollId, 'error' => $e->getMessage(),
                'class' => get_class($e), 'app' => Application::APP_ID,
            ]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * v4.5.33 — Talk chat messages that mention $uid.
     *
     * **Why this exists.** The feed carried Talk polls and thread starters, so
     * a plain chat message saying "@you check over here" reached nobody — and
     * Justin's diagnostic showed that on his instance every real mention was
     * exactly that. Mentions of you are the one slice of chat history a feed
     * can usefully carry: everything else in a room is the room's business.
     *
     * Scoped to rooms already resolved from the caller's team memberships, so
     * this cannot reach a conversation the feed would not otherwise show. The
     * `LIKE` narrows to plausible rows and `MentionParser` decides — the same
     * two-stage arrangement the message side uses, and for the same reason:
     * SQL has no word boundary.
     *
     * @param int[] $roomIds
     * @return array<int, array{source:string,id:int,room_id:int,message:string,actor_id:string,created_at:int}>
     */
    public function findRecentMentions(array $roomIds, string $uid, int $limit): array {
        if (empty($roomIds) || $uid === '' || !$this->appManager->isInstalled('spreed')) {
            return [];
        }
        try {
            $db = $this->container->get(\OCP\IDBConnection::class);
            $qb = $db->getQueryBuilder();

            // LOWER() on both sides: MySQL's collation makes LIKE
            // case-insensitive and Postgres's does not, and the PHP check that
            // follows is case-insensitive — so a case-sensitive pre-filter
            // would drop rows PHP would have accepted.
            $escaped = mb_strtolower($db->escapeLikeParameter($uid));
            $bare   = $qb->createNamedParameter('%@' . $escaped . '%');
            $quoted = $qb->createNamedParameter('%@"' . $escaped . '"%');

            $qb->select('id', 'actor_id', 'actor_type', 'message', 'creation_timestamp', 'object_id')
                ->from('comments')
                ->where($qb->expr()->eq('object_type', $qb->createNamedParameter('chat')))
                // object_id is a string column in core comments, even though
                // Talk stores a room id in it.
                ->andWhere($qb->expr()->in(
                    'object_id',
                    $qb->createNamedParameter(
                        array_map('strval', $roomIds),
                        \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY,
                    ),
                ))
                // Real messages only — system rows (joins, calls, shares) carry
                // their own verbs and are chrome, not conversation.
                ->andWhere($qb->expr()->eq('verb', $qb->createNamedParameter('comment')))
                ->andWhere('(LOWER(message) LIKE ' . $bare . ' OR LOWER(message) LIKE ' . $quoted . ')')
                ->orderBy('creation_timestamp', 'DESC')
                ->addOrderBy('id', 'DESC')
                ->setMaxResults(max(1, min(200, $limit)));

            $res = $qb->executeQuery();
            $out = [];
            while ($r = $res->fetch()) {
                $body = $this->decodeTalkMessage((string)($r['message'] ?? ''));
                if (!MentionParser::mentions($body, $uid)) {
                    continue;
                }

                $ts  = 0;
                $raw = $r['creation_timestamp'] ?? null;
                if (is_numeric($raw)) {
                    $ts = (int)$raw;
                } elseif (is_string($raw) && $raw !== '') {
                    // Core comments store this as a UTC datetime string.
                    $parsed = strtotime($raw . ' UTC');
                    if ($parsed !== false) {
                        $ts = $parsed;
                    }
                }

                $out[] = [
                    'source'     => 'talk-mention',
                    'id'         => (int)$r['id'],
                    'room_id'    => (int)$r['object_id'],
                    'message'    => $body,
                    // Federated and guest actors keep their raw id rather than
                    // being resolved as a local user of the same name.
                    'actor_id'   => (string)($r['actor_id'] ?? ''),
                    'actor_type' => (string)($r['actor_type'] ?? ''),
                    'created_at' => $ts,
                ];
            }
            $res->closeCursor();
            return $out;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TalkService] findRecentMentions failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * v4.5.27 — creation times for Talk polls, read from the chat message that
     * announced each one.
     *
     * `talk_polls` carries no timestamp on this Talk version (4.5.26 stopped
     * fabricating one and logged the column list; Justin sent it back). The
     * date does exist, one table over: creating a poll posts a chat message
     * into `oc_comments` with `verb = 'object_shared'` and a JSON body naming
     * the poll —
     *
     *   {"message":"object_shared","parameters":{"objectType":"talk-poll",
     *    "objectId":1,"metaData":{"type":"talk-poll","id":1,"name":"…"}}}
     *
     * — and that row has a real `creation_timestamp`. `metaData.id` is the
     * link, with `parameters.objectId` read as a fallback because the two say
     * the same thing and neither is documented.
     *
     * Scoped by room and verb so the scan stays narrow: this reads only the
     * share announcements of rooms already in the caller's feed, not chat
     * history. Best-effort — anything unexpected leaves the poll undated,
     * which is the state it was already in.
     *
     * @param int[] $roomIds
     * @return array<int,int> poll id → unix creation time
     */
    public function findPollCreationTimes(array $roomIds): array {
        if (empty($roomIds) || !$this->appManager->isInstalled('spreed')) {
            return [];
        }
        try {
            $db = $this->container->get(\OCP\IDBConnection::class);
            $qb = $db->getQueryBuilder();
            $qb->select('message', 'creation_timestamp')
                ->from('comments')
                ->where($qb->expr()->eq('object_type', $qb->createNamedParameter('chat')))
                // object_id is a string column in core comments, even though
                // Talk stores a room id in it.
                ->andWhere($qb->expr()->in(
                    'object_id',
                    $qb->createNamedParameter(
                        array_map('strval', $roomIds),
                        \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY,
                    ),
                ))
                ->andWhere($qb->expr()->eq('verb', $qb->createNamedParameter('object_shared')))
                // Narrows the JSON decoding to rows that can possibly be a
                // poll. escapeLikeParameter is unnecessary — the needle is a
                // literal with no wildcards — but the value is still bound.
                ->andWhere($qb->expr()->like('message', $qb->createNamedParameter('%talk-poll%')));

            $res = $qb->executeQuery();
            $out = [];
            while ($r = $res->fetch()) {
                $decoded = json_decode((string)($r['message'] ?? ''), true);
                if (!is_array($decoded)) {
                    continue;
                }
                $params = $decoded['parameters'] ?? [];
                if (!is_array($params)) {
                    continue;
                }
                $pollId = 0;
                if (isset($params['metaData']['id'])) {
                    $pollId = (int)$params['metaData']['id'];
                } elseif (isset($params['objectId'])) {
                    $pollId = (int)$params['objectId'];
                }
                if ($pollId <= 0) {
                    continue;
                }

                $raw = $r['creation_timestamp'] ?? null;
                $ts = 0;
                if (is_numeric($raw)) {
                    $ts = (int)$raw;
                } elseif (is_string($raw) && $raw !== '') {
                    // A datetime string on this schema ("2026-01-08 13:43:46"),
                    // stored in UTC as core comments always are.
                    $parsed = strtotime($raw . ' UTC');
                    if ($parsed !== false) {
                        $ts = $parsed;
                    }
                }
                if ($ts <= 0) {
                    continue;
                }

                // A poll can be shared more than once; the earliest mention is
                // when it was created.
                if (!isset($out[$pollId]) || $ts < $out[$pollId]) {
                    $out[$pollId] = $ts;
                }
            }
            $res->closeCursor();
            return $out;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TalkService] findPollCreationTimes failed — polls stay undated', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Current tallies for one poll, so a vote cast from the feed can update
     * the bars in place instead of forcing a whole-page refetch (which would
     * collapse every expanded thread on the page to move one percentage).
     *
     * Read-only and best-effort — a shape we don't recognise returns null and
     * the caller leaves the tallies as they were.
     *
     * @return array{votes:array,num_voters:int,status:int}|null
     */
    public function findPollTallies(int $roomId, int $pollId): ?array {
        if ($pollId <= 0 || !$this->appManager->isInstalled('spreed')) {
            return null;
        }
        try {
            $db = $this->container->get(\OCP\IDBConnection::class);
            $qb = $db->getQueryBuilder();
            $qb->select('*')
                ->from('talk_polls')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($pollId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                // Scoped to the room the caller was already authorised for, so
                // this cannot be used to read a poll from another conversation.
                ->andWhere($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1);

            $res = $qb->executeQuery();
            $r = $res->fetch();
            $res->closeCursor();
            if (!$r) {
                return null;
            }
            return [
                'votes'      => json_decode((string)($r['votes'] ?? '{}'), true) ?: [],
                'num_voters' => (int)($r['num_voters'] ?? 0),
                'status'     => (int)($r['status'] ?? 0),
            ];
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][TalkService] findPollTallies — schema did not answer', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
    }

    /**
     * Which options $uid has already picked, per poll.
     *
     * Read-only and best-effort: a schema that does not answer means the feed
     * renders the poll without a "you voted for this" marker, which is a
     * missing hint rather than a wrong one.
     *
     * @param int[] $pollIds
     * @return array<int, int[]> poll id → option ids
     */
    public function findOwnPollVotes(array $pollIds, string $uid): array {
        if (empty($pollIds) || $uid === '' || !$this->appManager->isInstalled('spreed')) {
            return [];
        }
        try {
            $db = $this->container->get(\OCP\IDBConnection::class);
            $qb = $db->getQueryBuilder();
            $qb->select('poll_id', 'option_id')
                ->from('talk_poll_votes')
                ->where($qb->expr()->in(
                    'poll_id',
                    $qb->createNamedParameter($pollIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY),
                ))
                ->andWhere($qb->expr()->eq('actor_type', $qb->createNamedParameter('users')))
                ->andWhere($qb->expr()->eq('actor_id', $qb->createNamedParameter($uid)));

            $res = $qb->executeQuery();
            $out = [];
            while ($r = $res->fetch()) {
                $out[(int)$r['poll_id']][] = (int)$r['option_id'];
            }
            $res->closeCursor();
            return $out;
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][TalkService] findOwnPollVotes — schema did not answer', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Positional arguments for a Talk service method, matched by reflection
     * against its declared parameters.
     *
     * Talk's chat and poll signatures have gained parameters across versions
     * (threads, silent sends, proxy caches). Guessing an argument list is how
     * v4.5.21's Approval integration broke twice; this reads the real
     * parameter list and fills it by name, then by type, then by default.
     *
     * A required parameter we have no candidate for **throws with its name**,
     * because that message is what makes the next Talk version's change a
     * one-line fix instead of an investigation.
     *
     * @param array<string,mixed> $candidates lower-case parameter name → value
     * @return array<int,mixed>
     */
    /**
     * Would PHP accept $value for a parameter declared as $type?
     *
     * Deliberately conservative — an untyped or union-typed parameter returns
     * true (we cannot reason about it, and the old behaviour was to pass
     * anything), but a mismatch on a plain declared type returns false so the
     * caller keeps looking instead of handing over something that will
     * TypeError two frames down.
     */
    private function valueFitsType($value, ?\ReflectionType $type): bool {
        if (!$type instanceof \ReflectionNamedType) {
            return true;
        }
        if ($value === null) {
            return $type->allowsNull();
        }

        $name = $type->getName();
        if (!$type->isBuiltin()) {
            return $value instanceof $name;
        }

        return match ($name) {
            'bool'   => is_bool($value),
            'int'    => is_int($value),
            'float'  => is_float($value) || is_int($value),
            'string' => is_string($value),
            'array'  => is_array($value),
            // 'mixed', 'iterable', 'object', 'callable' — not worth reasoning
            // about here; let them through and let PHP decide.
            default  => true,
        };
    }

    private function matchTalkArguments(object $service, string $method, array $candidates): array {
        if (!method_exists($service, $method)) {
            throw new \RuntimeException(sprintf(
                'Talk\'s %s has no %s() — this Talk version is not supported by TeamHub\'s feed.',
                get_class($service),
                $method,
            ));
        }

        $args = [];
        foreach ((new \ReflectionMethod($service, $method))->getParameters() as $param) {
            $name = strtolower($param->getName());
            $type = $param->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';

            // 1. Exact name — still type-checked, and falls through rather
            //    than aborting if the type has changed under the same name.
            if (array_key_exists($name, $candidates) && $this->valueFitsType($candidates[$name], $type)) {
                $args[] = $candidates[$name];
                continue;
            }

            // 2. Name contains a candidate key, or vice versa — covers
            //    $replyTo vs $replyToId, $creationDateTime vs $dateTime.
            //
            //    **The type must also fit.** v4.5.26 matched on the name alone
            //    with a four-character guard, and Talk 21's
            //    `bool $fromScheduledMessage` matched the 'message' candidate
            //    because the name *ends with* it — so a string went into a bool
            //    parameter and every reply died with a TypeError. A name
            //    coincidence is not evidence; a name coincidence plus a
            //    compatible type is.
            $matched = false;
            foreach ($candidates as $key => $value) {
                if (strlen($name) < 4 || strlen((string)$key) < 4) {
                    continue;
                }
                if (!str_contains($name, (string)$key) && !str_contains((string)$key, $name)) {
                    continue;
                }
                if (!$this->valueFitsType($value, $type)) {
                    continue;
                }
                $args[] = $value;
                $matched = true;
                break;
            }
            if ($matched) {
                continue;
            }

            // 3. Type match against an object candidate.
            if ($typeName !== '' && !$type?->isBuiltin()) {
                foreach ($candidates as $value) {
                    if ($value instanceof $typeName) {
                        $args[] = $value;
                        $matched = true;
                        break;
                    }
                }
                if ($matched) {
                    continue;
                }
            }

            // 4. Anything the method is happy to default.
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }
            if ($param->allowsNull()) {
                $args[] = null;
                continue;
            }

            throw new \RuntimeException(sprintf(
                '%s::%s() expects a parameter "$%s" that TeamHub cannot supply.',
                get_class($service),
                $method,
                $param->getName(),
            ));
        }

        return $args;
    }

}
