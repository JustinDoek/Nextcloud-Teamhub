<?php

declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Calendar\Room\IManager as IRoomManager;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Lists bookable rooms and resources for the room/location picker.
 *
 * Source logic:
 *
 *   | calendar_resource_management | RoomVox (installed + token) | Result                        |
 *   |------------------------------|-----------------------------|---------------------------------|
 *   | no                           | no                          | [] — free-text location shown   |
 *   | yes                          | no                          | CRM rooms only                  |
 *   | no                           | yes                         | RoomVox rooms only              |
 *   | yes                          | yes                         | Union of both, deduplicated     |
 *
 * Why we query the DB directly for CRM rooms:
 *   NC's IResourceManager / IRoomManager PHP APIs call getAllResources() /
 *   getAllRooms() on each backend — but the CRM backend returns empty from
 *   those methods. CRM rooms are exposed through CalDAV principal search
 *   (PROPFIND), not through the PHP manager enumeration API. The actual
 *   data lives in oc_calendar_rooms (rooms) and oc_calendar_resources
 *   (generic resources), which NC's cron job populates from calresources_*.
 *   Querying those tables directly is the correct approach, and is what
 *   NC's own CalDAV layer does internally.
 *
 * RoomVox rooms come from IRoomManager, which RoomVox populates correctly.
 *
 * Each entry carries a `source` field ('roomvox' | 'crm') so the booking
 * layer knows whether to call RoomVoxClient::createBooking. CRM rooms are
 * added as NEEDS-ACTION ATTENDEEs without a booking API call.
 */
class RoomDiscoveryService {

    public function __construct(
        private IAppManager    $appManager,
        private IRoomManager   $roomManager,
        private IDBConnection  $db,
        private \OCP\IConfig   $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<int, array{id: string, displayName: string, email: string, source: string}>
     */
    public function listAvailableRooms(): array {
        $crmInstalled = $this->appManager->isInstalled('calendar_resource_management');
        $roomvoxInstalled = $this->appManager->isInstalled('roomvox');
        $roomvoxToken = $roomvoxInstalled
            ? $this->config->getAppValue(Application::APP_ID, RoomVoxClient::APP_VALUE_TOKEN, '')
            : '';
        $roomvoxReady = $roomvoxInstalled && $roomvoxToken !== '';

        $this->logger->debug('[TeamHub][RoomDiscoveryService] listAvailableRooms — gates', [
            'crmInstalled' => $crmInstalled,
            'roomvoxReady' => $roomvoxReady,
            'app'          => Application::APP_ID,
        ]);

        if (!$crmInstalled && !$roomvoxReady) {
            return [];
        }

        $seen  = [];
        $rooms = [];

        // 1. RoomVox — uses IRoomManager which RoomVox populates correctly.
        if ($roomvoxReady) {
            $this->harvestRoomVox($rooms, $seen);
        }

        // 2. CRM — query oc_calendar_rooms and oc_calendar_resources directly.
        //    The IResourceManager/IRoomManager PHP APIs return empty for CRM
        //    because CRM exposes rooms via CalDAV principals, not getAllRooms().
        //    NC's cron syncs calresources_* → oc_calendar_rooms/resources.
        if ($crmInstalled) {
            $this->harvestCrmRooms($rooms, $seen);
            $this->harvestCrmResources($rooms, $seen);
        }

        usort($rooms, static fn($a, $b) => strcasecmp($a['displayName'], $b['displayName']));

        $this->logger->debug('[TeamHub][RoomDiscoveryService] discovery complete', [
            'count' => count($rooms),
            'app'   => Application::APP_ID,
        ]);

        return $rooms;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Harvest rooms from RoomVox via IRoomManager.
     * RoomVox registers its backend correctly and getAllRooms() works.
     */
    private function harvestRoomVox(array &$rooms, array &$seen): void {
        try {
            $backends = $this->roomManager->getBackends();
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][RoomDiscoveryService] IRoomManager getBackends failed', [
                'err' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return;
        }

        foreach ($backends as $backend) {
            // Only process the RoomVox backend here — CRM's room backend
            // is also registered on IRoomManager but returns empty.
            $backendId = method_exists($backend, 'getBackendIdentifier')
                ? $backend->getBackendIdentifier() : '';
            if ($backendId !== 'roomvox') {
                continue;
            }

            try {
                $entries = $backend->getAllRooms();
            } catch (\Throwable $e) {
                $this->logger->debug('[TeamHub][RoomDiscoveryService] RoomVox getAllRooms failed', [
                    'err' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                continue;
            }

            foreach ($entries as $entry) {
                try {
                    $email = method_exists($entry, 'getEMail') ? (string)$entry->getEMail() : '';
                    if ($email === '') {
                        continue;
                    }
                    $id   = (string)$entry->getId();
                    // Strip the " (Roomvox)" suffix that the RoomVox app appends
                    // to every room name — the source is irrelevant to the user.
                    $name = trim(preg_replace('/\s*\(Roomvox\)\s*$/i', '', (string)$entry->getDisplayName()));
                    $key  = $id . '|' . strtolower($email);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $rooms[] = ['id' => $id, 'displayName' => $name, 'email' => $email, 'source' => 'roomvox'];
                } catch (\Throwable $e) {
                    $this->logger->debug('[TeamHub][RoomDiscoveryService] RoomVox entry parse failed', [
                        'err' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }
            }
        }
    }

    /**
     * Query oc_calendar_rooms directly — populated by NC cron from CRM's
     * calresources_rooms table. This is how NC's CalDAV layer finds CRM rooms.
     * Schema: backend_id, resource_id, email, displayname, group_restrictions
     */
    private function harvestCrmRooms(array &$rooms, array &$seen): void {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('resource_id', 'email', 'displayname')
               ->from('calendar_rooms')
               ->where($qb->expr()->eq(
                   'backend_id',
                   $qb->createNamedParameter('calendar_resource_management')
               ));

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $email = (string)($row['email'] ?? '');
                if ($email === '') {
                    continue;
                }
                $id   = (string)($row['resource_id'] ?? '');
                $name = (string)($row['displayname'] ?? $id);
                $key  = $id . '|' . strtolower($email);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $rooms[] = ['id' => $id, 'displayName' => $name, 'email' => $email, 'source' => 'crm'];
            }
            $result->closeCursor();

            $this->logger->debug('[TeamHub][RoomDiscoveryService] harvestCrmRooms complete', [
                'count' => count(array_filter($rooms, fn($r) => $r['source'] === 'crm')),
                'app'   => Application::APP_ID,
            ]);
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][RoomDiscoveryService] harvestCrmRooms failed', [
                'err' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * Query oc_calendar_resources directly — populated by NC cron from CRM's
     * calresources_resources table. Covers general bookable resources
     * (e.g. created via calendar-resource:resource:create).
     * Same schema as oc_calendar_rooms.
     */
    private function harvestCrmResources(array &$rooms, array &$seen): void {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('resource_id', 'email', 'displayname')
               ->from('calendar_resources')
               ->where($qb->expr()->eq(
                   'backend_id',
                   $qb->createNamedParameter('calendar_resource_management')
               ));

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $email = (string)($row['email'] ?? '');
                if ($email === '') {
                    continue;
                }
                $id   = (string)($row['resource_id'] ?? '');
                $name = (string)($row['displayname'] ?? $id);
                $key  = $id . '|' . strtolower($email);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $rooms[] = ['id' => $id, 'displayName' => $name, 'email' => $email, 'source' => 'crm'];
            }
            $result->closeCursor();
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][RoomDiscoveryService] harvestCrmResources failed', [
                'err' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }
}
