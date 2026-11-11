<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\Building;
use OCA\TeamHub\Db\BuildingMapper;
use OCA\TeamHub\Db\Floor;
use OCA\TeamHub\Db\FloorMapper;
use OCP\Calendar\IMetadataProvider;
use OCP\Calendar\Room\IManager as IRoomManager;
use OCP\Calendar\Room\IRoomMetadata;
use Psr\Log\LoggerInterface;

/**
 * Keeps the People module's buildings and floors in step with whatever room
 * backend this instance has (v4.13.53).
 *
 * ## What this is for
 *
 * The People module plans where somebody sits: buildings → floors → rooms,
 * maintained by an administrator in Admin → TeamHub → Locations. An instance
 * running the Calendar resource app has already typed the same buildings and
 * floors in once, to hang its meeting rooms off. Asking for them a second time
 * is how the two drift.
 *
 * So the top two levels are taken from the backend when one exists, and the
 * administrator fills in only the rooms — which are a different thing on each
 * side anyway: a resource-app room is a bookable meeting room, a People room
 * is a place a person sits for half a day.
 *
 * ## Why the public room API and not the resource app's tables
 *
 * Nextcloud publishes a room's building and floor as metadata on the room
 * itself, and core's own `ResourcesRoomsUpdater` reads them exactly this way:
 * `getBackends()` → `listAllRooms()` → `getRoom()` → `getMetadataForKey()`.
 * Going through that API means this works for **any** backend — the Calendar
 * resource app, RoomVox, anything written later — and that we never read
 * another app's private schema.
 *
 * The cost is that the API publishes *names*, not ids. There is no building id
 * anywhere in it. So a building is matched on its published name, and a rename
 * over there reads here as one building gone and another arrived. That trade
 * was made deliberately; see DESIGN §2.221.
 *
 * ## The rules this follows
 *
 *   - **A synced row is read-only here.** `source` carries the backend
 *     identifier, and `PresenceLocationService` refuses to rename, re-sort or
 *     delete a row that has one. One source of truth, no drift.
 *   - **A local row with the matching name is adopted, not duplicated.** An
 *     administrator who typed "Corperate HQ" in before the resource app was
 *     installed keeps their floors and rooms; the building simply becomes
 *     managed. Without this, enabling the app would hand them two of
 *     everything.
 *   - **Nothing is ever deleted.** A building that disappears from the backend
 *     — or whose backend is uninstalled — is *released*: `source` is cleared
 *     and it becomes an ordinary local row the administrator owns again. Rooms
 *     and the presence history hanging off it are untouched, and if the
 *     backend comes back the adopt-by-name step picks it up again. Deleting
 *     would destroy slots and templates that reference those rooms, which is
 *     the one thing this module must never do on its own.
 *   - **Rooms are never touched.** Not created, not renamed, not deleted.
 */
class PresenceLocationSyncService {

    /**
     * The name of the building a room sits in.
     *
     * Written as a literal rather than `IRoomMetadata::BUILDING_NAME` on
     * purpose: that constant is `@since 35.0.0` and we support Nextcloud 33
     * upwards, so referencing it would fatal on an older instance. The two
     * keys below have existed since 17.0.0 and are safe as constants.
     */
    private const BUILDING_NAME = '{http://nextcloud.com/ns}room-building-name';

    public function __construct(
        private IRoomManager   $roomManager,
        private BuildingMapper $buildings,
        private FloorMapper    $floors,
        private LoggerInterface $logger,
    ) {}

    /**
     * Bring the buildings and floors in line with the room backends.
     *
     * Idempotent, and safe to call on every read of the location tree — the
     * catalogue is an org-wide handful of rooms. Never throws: a backend that
     * is unavailable leaves the tree exactly as it was, because a locations
     * page that cannot render is worse than one that is a few minutes stale.
     *
     * @return bool true when anything changed, so the caller knows to re-read
     */
    public function sync(): bool {
        try {
            $catalogue = $this->readCatalogue();
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][PresenceLocationSyncService] could not read the room catalogue; locations left as they are', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'app'       => Application::APP_ID,
            ]);
            return false;
        }

        $changed  = $this->applyCatalogue($catalogue);
        $changed  = $this->releaseOrphans($catalogue) || $changed;

        if ($changed) {
            $this->logger->debug('[TeamHub][PresenceLocationSyncService] location tree updated from the room backends', [
                'backends' => array_keys($catalogue),
                'app'      => Application::APP_ID,
            ]);
        }
        return $changed;
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    /**
     * The buildings and floors every registered room backend publishes.
     *
     *   [ backendId => [ buildingName => ['address' => ?string,
     *                                     'floors'  => [floorName, …]] ] ]
     *
     * A backend that publishes no building name for a room contributes
     * nothing for that room: a floor with no building to hang on is not
     * something we can place in the tree, and inventing a building for it
     * would put a row in front of an administrator that exists nowhere else.
     *
     * @return array<string, array<string, array{address: ?string, floors: array<string, true>}>>
     */
    private function readCatalogue(): array {
        $catalogue = [];

        foreach ($this->roomManager->getBackends() as $backend) {
            $backendId = $backend->getBackendIdentifier();
            if ($backendId === '') {
                continue;
            }
            $catalogue[$backendId] = [];

            try {
                $uids = $backend->listAllRooms();
            } catch (\Throwable $e) {
                $this->logger->debug('[TeamHub][PresenceLocationSyncService] backend could not list its rooms', [
                    'backend'   => $backendId,
                    'exception' => get_class($e),
                    'app'       => Application::APP_ID,
                ]);
                continue;
            }

            foreach ($uids as $uid) {
                try {
                    $room = $backend->getRoom($uid);
                } catch (\Throwable $e) {
                    // One unavailable room must not cost us the whole backend.
                    $this->logger->debug('[TeamHub][PresenceLocationSyncService] room unavailable, skipped', [
                        'backend'   => $backendId,
                        'exception' => get_class($e),
                        'app'       => Application::APP_ID,
                    ]);
                    continue;
                }
                if ($room === null || !$room instanceof IMetadataProvider) {
                    continue;
                }

                $buildingName = $this->readKey($room, self::BUILDING_NAME);
                $address      = $this->readKey($room, IRoomMetadata::BUILDING_ADDRESS);

                // Backends older than Nextcloud 35 publish no building name.
                // The address is what clients were told to guess from before
                // the key existed, so it is the fallback rather than nothing.
                if ($buildingName === '') {
                    $buildingName = $address;
                }
                if ($buildingName === '') {
                    continue;
                }

                if (!isset($catalogue[$backendId][$buildingName])) {
                    $catalogue[$backendId][$buildingName] = [
                        'address' => $address !== '' ? $address : null,
                        'floors'  => [],
                    ];
                }

                $floorName = $this->readKey($room, IRoomMetadata::BUILDING_STORY);
                if ($floorName !== '') {
                    $catalogue[$backendId][$buildingName]['floors'][$floorName] = true;
                }
            }
        }

        return $catalogue;
    }

    private function readKey(IMetadataProvider $room, string $key): string {
        try {
            if (!$room->hasMetadataForKey($key)) {
                return '';
            }
            return trim((string)($room->getMetadataForKey($key) ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    /**
     * Create, adopt or refresh a row for everything the catalogue holds.
     *
     * @param array<string, array<string, array{address: ?string, floors: array<string, true>}>> $catalogue
     */
    private function applyCatalogue(array $catalogue): bool {
        $changed = false;

        // Both tables are read once and carried through: the tree is an
        // org-wide handful of rows, and re-querying per building would turn a
        // cheap sync into one query per node.
        $knownBuildings = $this->buildings->findAll();
        $knownFloors    = $this->floors->findAll();

        foreach ($catalogue as $backendId => $buildings) {
            foreach ($buildings as $buildingName => $spec) {
                $building = $this->resolveBuilding(
                    $backendId,
                    (string)$buildingName,
                    $spec['address'],
                    $knownBuildings,
                    $changed
                );
                if ($building === null) {
                    continue;
                }
                foreach (array_keys($spec['floors']) as $floorName) {
                    $this->resolveFloor(
                        $backendId,
                        (int)$building->getId(),
                        (string)$floorName,
                        $knownFloors,
                        $changed
                    );
                }
            }
        }

        return $changed;
    }

    /**
     * The building row for one published name: the managed one if it exists,
     * otherwise a local one of the same name adopted, otherwise a new row.
     *
     * @param Building[] $all every building known so far, appended to on insert
     */
    private function resolveBuilding(
        string $backendId,
        string $name,
        ?string $address,
        array &$all,
        bool &$changed
    ): ?Building {
        foreach ($all as $b) {
            if ($b->getSource() === $backendId && $b->getSourceRef() === $name) {
                // Managed already. The address is ours to keep current; the
                // name is the match key and cannot have moved.
                if ($b->getAddress() !== $address) {
                    $b->setAddress($address);
                    $this->buildings->update($b);
                    $changed = true;
                }
                return $b;
            }
        }

        foreach ($all as $b) {
            if (!$b->isManaged() && $b->getName() === $name) {
                $b->setSource($backendId);
                $b->setSourceRef($name);
                $b->setAddress($address);
                $this->buildings->update($b);
                $changed = true;
                $this->logger->info('[TeamHub][PresenceLocationSyncService] adopted an existing building', [
                    'backend' => $backendId,
                    'app'     => Application::APP_ID,
                ]);
                return $b;
            }
        }

        $b = new Building();
        $b->setName($name);
        $b->setAddress($address);
        $b->setSortOrder(0);
        $b->setCreatedAt(time());
        $b->setSource($backendId);
        $b->setSourceRef($name);

        try {
            /** @var Building $saved */
            $saved = $this->buildings->insert($b);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][PresenceLocationSyncService] could not create a building', [
                'backend'   => $backendId,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'app'       => Application::APP_ID,
            ]);
            return null;
        }
        $all[]   = $saved;
        $changed = true;
        return $saved;
    }

    /**
     * The floor row for one published story name within one building. Same
     * three-step resolution as a building, scoped to that building.
     *
     * @param Floor[] $allFloors every floor known so far, appended to on insert
     */
    private function resolveFloor(
        string $backendId,
        int $buildingId,
        string $name,
        array &$allFloors,
        bool &$changed
    ): void {
        $all = [];
        foreach ($allFloors as $f) {
            if ($f->getBuildingId() === $buildingId) {
                $all[] = $f;
            }
        }

        foreach ($all as $f) {
            if ($f->getSource() === $backendId && $f->getSourceRef() === $name) {
                return;
            }
        }

        foreach ($all as $f) {
            if (!$f->isManaged() && $f->getName() === $name) {
                $f->setSource($backendId);
                $f->setSourceRef($name);
                $this->floors->update($f);
                $changed = true;
                return;
            }
        }

        $f = new Floor();
        $f->setBuildingId($buildingId);
        $f->setName($name);
        $f->setSortOrder(0);
        $f->setCreatedAt(time());
        $f->setSource($backendId);
        $f->setSourceRef($name);

        try {
            /** @var Floor $saved */
            $saved = $this->floors->insert($f);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][PresenceLocationSyncService] could not create a floor', [
                'backend'   => $backendId,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'app'       => Application::APP_ID,
            ]);
            return;
        }
        $allFloors[] = $saved;
        $changed     = true;
    }

    /**
     * Release every managed row the catalogue no longer accounts for.
     *
     * Two cases, handled the same way: the backend is gone (uninstalled or
     * disabled, so it is not in the catalogue at all), or the backend is there
     * and no longer publishes that building or floor. Either way the row stops
     * being managed and becomes an ordinary local one, keeping its rooms and
     * everything booked against them.
     *
     * @param array<string, array<string, array{address: ?string, floors: array<string, true>}>> $catalogue
     */
    private function releaseOrphans(array $catalogue): bool {
        $changed = false;

        $buildingsById = [];
        foreach ($this->buildings->findAll() as $b) {
            $buildingsById[(int)$b->getId()] = $b;

            if (!$b->isManaged()) {
                continue;
            }
            $backendId = (string)$b->getSource();
            $ref       = (string)$b->getSourceRef();
            if (isset($catalogue[$backendId][$ref])) {
                continue;
            }

            $b->setSource(null);
            $b->setSourceRef(null);
            $this->buildings->update($b);
            $changed = true;
            $this->logger->info('[TeamHub][PresenceLocationSyncService] released a building the backend no longer publishes', [
                'backend' => $backendId,
                'app'     => Application::APP_ID,
            ]);
        }

        foreach ($this->floors->findAll() as $f) {
            if (!$f->isManaged()) {
                continue;
            }
            $backendId = (string)$f->getSource();
            $ref       = (string)$f->getSourceRef();

            $parent        = $buildingsById[$f->getBuildingId()] ?? null;
            $parentRef     = $parent !== null ? (string)$parent->getSourceRef() : '';
            $stillPublished = $parent !== null
                && $parent->isManaged()
                && isset($catalogue[$backendId][$parentRef]['floors'][$ref]);

            if ($stillPublished) {
                continue;
            }

            $f->setSource(null);
            $f->setSourceRef(null);
            $this->floors->update($f);
            $changed = true;
        }

        return $changed;
    }
}
