<?php

declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Calendar\Resource\IManager as IResourceManager;
use OCP\Calendar\Room\IManager as IRoomManager;
use Psr\Log\LoggerInterface;

/**
 * Lists bookable rooms ("meeting rooms") for the meeting wizard's room
 * picker.
 *
 * Discovery model:
 *   NC has TWO parallel manager APIs for CalDAV principal resources:
 *     - OCP\Calendar\Room\IManager      — meeting rooms specifically
 *     - OCP\Calendar\Resource\IManager  — generic resources (vehicles,
 *                                         equipment, projectors, etc.)
 *   Most rooms apps (RoomVox, the official calendar_resource_management)
 *   register with the Room manager. Some apps register on both. We query
 *   both and union the results (filtering the resource manager to
 *   type='room'), so the picker surfaces every bookable room regardless
 *   of which manager its source app chose.
 *
 *   Earlier versions of this service queried only Resource\IManager —
 *   which is why a RoomVox-installed instance saw an empty list (RoomVox
 *   only publishes via the Room manager). 3.59.6 fixes that.
 *
 * "Is RoomVox enabled" gate:
 *   Justin's session brief asked specifically for an `isInstalled('roomvox')`
 *   gate. The gate is enforced literally: no RoomVox = empty list, even
 *   if some other room-providing app is installed. This is conservative
 *   and avoids surprise rooms appearing in installs that don't expect
 *   them.
 *
 * Filtering:
 *   - Rooms without an email are skipped: a CalDAV ATTENDEE line requires
 *     a mailto: address. Without one we can't book; surfacing such a
 *     room as pickable would be a dead-end UX.
 *   - The picker is alphabetical for stable UX.
 */
class RoomDiscoveryService {

    public function __construct(
        private IAppManager       $appManager,
        private IRoomManager      $roomManager,
        private IResourceManager  $resourceManager,
        private \OCP\IConfig      $config,
        private LoggerInterface   $logger,
    ) {
    }

    /**
     * @return array<int, array{id: string, displayName: string, email: string}>
     */
    public function listAvailableRooms(): array {
        // Hard gate: if RoomVox isn't installed, no rooms regardless of
        // whether any other resource backend exists.
        $roomvoxInstalled = $this->appManager->isInstalled('roomvox');
        if (!$roomvoxInstalled) {
            $this->logger->debug('[TeamHub][RoomDiscoveryService] RoomVox not installed — empty list', [
                'app' => Application::APP_ID,
            ]);
            return [];
        }

        // Second gate: if there's no API token configured, picking a room
        // would dead-end at booking time (the createBooking call requires
        // a token). Don't surface a picker the user can't action.
        // The admin settings page lets the NC admin configure one.
        $token = $this->config->getAppValue(Application::APP_ID, \OCA\TeamHub\Service\RoomVoxClient::APP_VALUE_TOKEN, '');
        if ($token === '') {
            $this->logger->debug('[TeamHub][RoomDiscoveryService] no RoomVox API token configured — hiding picker', [
                'app' => Application::APP_ID,
            ]);
            return [];
        }

        // seen[id|email] → true; deduplicates across the two managers in
        // case an app registers a room with both.
        $seen  = [];
        $rooms = [];

        // 1. Primary: NC's rooms manager (Room\IManager). RoomVox lives here.
        //    Note: Room\IBackend exposes rooms via getAllRooms() — NOT
        //    getAllResources(). That's the resources-manager method.
        //    Mixing them up throws "Call to undefined method".
        $this->harvestFromManager(
            $this->roomManager,
            'getAllRooms',
            null,             // no type filter — every entry in Room\IManager is a room by definition
            $rooms,
            $seen,
            'roomManager',
        );

        // 2. Secondary: NC's generic resources manager. Filter to type=room
        //    so we don't surface vehicles/projectors. Most installs have
        //    nothing here, but calendar_resource_management does.
        $this->harvestFromManager(
            $this->resourceManager,
            'getAllResources',
            'room',
            $rooms,
            $seen,
            'resourceManager',
        );

        // Sort alphabetically for stable picker UX.
        usort($rooms, static fn ($a, $b) => strcasecmp($a['displayName'], $b['displayName']));

        $this->logger->debug('[TeamHub][RoomDiscoveryService] discovery complete', [
            'count' => count($rooms),
            'app'   => Application::APP_ID,
        ]);

        return $rooms;
    }

    /**
     * Walk the backends of a manager and append qualifying rooms to $rooms.
     * Tolerates broken backends individually — one misbehaving backend
     * must not poison the rest.
     *
     * @param mixed  $manager       Either Room\IManager or Resource\IManager — both
     *                              expose getBackends() returning IBackend[].
     * @param string $enumerateMethod Method on each IBackend that returns
     *                              the room/resource list. NC's two parallel
     *                              APIs disagree on the name:
     *                              Room\IBackend::getAllRooms(),
     *                              Resource\IBackend::getAllResources().
     * @param string|null $typeFilter When non-null, skip entries whose
     *                              getResourceType() doesn't match. Used to
     *                              constrain the generic resource manager
     *                              to rooms only.
     * @param array<int, array{id: string, displayName: string, email: string}> $rooms
     * @param array<string, bool> $seen
     */
    private function harvestFromManager(
        $manager,
        string $enumerateMethod,
        ?string $typeFilter,
        array &$rooms,
        array &$seen,
        string $tag,
    ): void {
        try {
            $backends = $manager->getBackends();
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][RoomDiscoveryService] manager getBackends failed', [
                'tag' => $tag,
                'err' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
            return;
        }
        foreach ($backends as $backend) {
            try {
                // Method name varies between Room and Resource managers
                // (see signature comment above). Use dynamic dispatch so
                // a single helper serves both.
                $entries = $backend->{$enumerateMethod}();
            } catch (\Throwable $e) {
                $this->logger->debug('[TeamHub][RoomDiscoveryService] backend enumeration failed', [
                    'tag'     => $tag,
                    'method'  => $enumerateMethod,
                    'backend' => method_exists($backend, 'getBackendIdentifier')
                        ? $backend->getBackendIdentifier()
                        : get_class($backend),
                    'err'     => $e->getMessage(),
                    'app'     => Application::APP_ID,
                ]);
                continue;
            }
            foreach ($entries as $entry) {
                try {
                    if ($typeFilter !== null && method_exists($entry, 'getResourceType')) {
                        $type = strtolower((string)$entry->getResourceType());
                        if ($type !== '' && $type !== $typeFilter) {
                            continue;
                        }
                    }
                    $email = method_exists($entry, 'getEMail')
                        ? (string)$entry->getEMail()
                        : '';
                    if ($email === '') {
                        // No mailto: address means no CalDAV ATTENDEE line.
                        continue;
                    }
                    $id   = (string)$entry->getId();
                    $name = (string)$entry->getDisplayName();
                    $key  = $id . '|' . strtolower($email);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $rooms[] = [
                        'id'          => $id,
                        'displayName' => $name,
                        'email'       => $email,
                    ];
                } catch (\Throwable $e) {
                    $this->logger->debug('[TeamHub][RoomDiscoveryService] entry parse failed', [
                        'tag' => $tag,
                        'err' => $e->getMessage(),
                        'app' => Application::APP_ID,
                    ]);
                }
            }
        }
    }
}

