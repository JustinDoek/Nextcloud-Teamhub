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
            // When this fails — common on some Talk versions — fall back to a direct DB insert
            // so the circle attendee row always exists before we promote it to MODERATOR.
            $circleLinked = false;
            try {
                $participantService = $this->container->get(\OCA\Talk\Service\ParticipantService::class);
                $participantService->addCircle($room, $teamId);
                $circleLinked = true;
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

            // Add circle via ParticipantService; fall back to direct DB insert on failure
            $circleLinked = false;
            try {
                $participantService = $this->container->get(\OCA\Talk\Service\ParticipantService::class);
                $participantService->addCircle($room, $teamId);
                $circleLinked = true;
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
     * Create a folder in the user's files and share it with the circle.
     */
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
     * Delete the shared Files folder for this team.
     * Removes the IShare record AND deletes the folder node itself.
     */

}
