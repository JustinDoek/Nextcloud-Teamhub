<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\Building;
use OCA\TeamHub\Db\BuildingMapper;
use OCA\TeamHub\Db\Floor;
use OCA\TeamHub\Db\FloorMapper;
use OCA\TeamHub\Db\PresenceSlotQueryMapper;
use OCA\TeamHub\Db\PresenceTemplateQueryMapper;
use OCA\TeamHub\Db\Room;
use OCA\TeamHub\Db\RoomMapper;
use OCA\TeamHub\Exception\PresenceConflictException;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Admin CRUD for the location hierarchy: buildings → floors → rooms.
 *
 * Referential integrity is enforced HERE rather than at the DB level
 * (NC convention per DESIGN.md §3 / pre-3.42 lessons —
 * ISchemaWrapper migrations are awkward with FKs across DB engines).
 *
 * Cascading delete:
 *   - deleteBuilding → deletes all rooms in the subtree, then all floors,
 *     then the building. Rejected if any room in the subtree is referenced
 *     by an active template or slot row.
 *   - deleteFloor    → deletes all rooms on the floor, then the floor.
 *     Rejected if any room is referenced.
 *   - deleteRoom     → deletes the room. Rejected if the room is referenced.
 *
 * Tree-read:
 *   getBuildings() pulls all three tables in three queries and assembles the
 *   tree in PHP rather than issuing one query per building. The catalogue is
 *   small (org-wide; typically dozens of rooms total) so the simpler shape
 *   is appropriate.
 */
class PresenceLocationService {

    public function __construct(
        private BuildingMapper              $buildings,
        private FloorMapper                 $floors,
        private RoomMapper                  $rooms,
        private PresenceSlotQueryMapper     $slotQuery,
        private PresenceTemplateQueryMapper $tmplQuery,
        private LoggerInterface             $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Tree read
    // -------------------------------------------------------------------------

    /**
     * Full location tree as nested plain arrays:
     *
     *   [
     *     { id, name, address, sort_order, floors: [
     *       { id, name, sort_order, rooms: [
     *         { id, name, sort_order }
     *       ] }
     *     ] }
     *   ]
     *
     * Three queries total regardless of tree size: one per level. Bucketing
     * is done in PHP.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBuildings(): array {
        $buildings = $this->buildings->findAll();
        $floors    = $this->floors->findAll();
        $rooms     = $this->rooms->findAll();

        // Group rooms by floor_id
        $roomsByFloor = [];
        foreach ($rooms as $r) {
            $roomsByFloor[$r->getFloorId()][] = $this->serializeRoom($r);
        }

        // Group floors by building_id, with rooms nested inside each floor
        $floorsByBuilding = [];
        foreach ($floors as $f) {
            $row           = $this->serializeFloor($f);
            $row['rooms']  = $roomsByFloor[$f->getId()] ?? [];
            $floorsByBuilding[$f->getBuildingId()][] = $row;
        }

        // Compose buildings with their floors nested
        $out = [];
        foreach ($buildings as $b) {
            $row           = $this->serializeBuilding($b);
            $row['floors'] = $floorsByBuilding[$b->getId()] ?? [];
            $out[]         = $row;
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Building CRUD
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createBuilding(array $data): array {
        $b = new Building();
        $b->setName($this->validateName($data['name'] ?? '', 'building'));
        $b->setAddress($this->validateAddress($data['address'] ?? null));
        $b->setSortOrder($this->validateSortOrder($data['sort_order'] ?? 0));
        $b->setCreatedAt(time());

        /** @var Building $saved */
        $saved = $this->buildings->insert($b);
        return $this->serializeBuilding($saved);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateBuilding(int $id, array $data): array {
        $b = $this->buildings->findById($id);
        if ($b === null) {
            throw new DoesNotExistException("Building {$id} not found");
        }

        if (array_key_exists('name', $data)) {
            $b->setName($this->validateName($data['name'], 'building'));
        }
        if (array_key_exists('address', $data)) {
            $b->setAddress($this->validateAddress($data['address']));
        }
        if (array_key_exists('sort_order', $data)) {
            $b->setSortOrder($this->validateSortOrder($data['sort_order']));
        }

        /** @var Building $saved */
        $saved = $this->buildings->update($b);
        return $this->serializeBuilding($saved);
    }

    /**
     * Delete a building and everything under it.
     * Rejected (PresenceConflictException, 409) if any room in the subtree
     * is referenced by an active template or slot row.
     */
    public function deleteBuilding(int $id): void {
        $b = $this->buildings->findById($id);
        if ($b === null) {
            throw new DoesNotExistException("Building {$id} not found");
        }

        $roomIds = $this->collectRoomIdsUnderBuilding($id);
        $this->assertRoomsUnreferenced($roomIds);

        // Delete rooms first, then floors, then the building itself.
        $this->rooms->deleteByBuilding($id);
        $this->floors->deleteByBuilding($id);
        $this->buildings->delete($b);
    }

    // -------------------------------------------------------------------------
    // Floor CRUD
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createFloor(array $data): array {
        $buildingId = (int)($data['building_id'] ?? 0);
        if ($buildingId <= 0) {
            throw new \InvalidArgumentException('building_id is required');
        }
        if ($this->buildings->findById($buildingId) === null) {
            throw new DoesNotExistException("Building {$buildingId} not found");
        }

        $f = new Floor();
        $f->setBuildingId($buildingId);
        $f->setName($this->validateName($data['name'] ?? '', 'floor'));
        $f->setSortOrder($this->validateSortOrder($data['sort_order'] ?? 0));
        $f->setCreatedAt(time());

        /** @var Floor $saved */
        $saved = $this->floors->insert($f);
        return $this->serializeFloor($saved);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateFloor(int $id, array $data): array {
        $f = $this->floors->findById($id);
        if ($f === null) {
            throw new DoesNotExistException("Floor {$id} not found");
        }

        if (array_key_exists('name', $data)) {
            $f->setName($this->validateName($data['name'], 'floor'));
        }
        if (array_key_exists('sort_order', $data)) {
            $f->setSortOrder($this->validateSortOrder($data['sort_order']));
        }
        // building_id is intentionally NOT updatable — moving a floor between
        // buildings is rare and would require renaming the floor anyway;
        // we'd rather an admin delete+recreate than expose accidental moves.
        if (array_key_exists('building_id', $data)) {
            $this->logger->info(sprintf(
                '[TeamHub][PresenceLocationService] updateFloor(%d): ignoring building_id (moves not supported)',
                $id
            ));
        }

        /** @var Floor $saved */
        $saved = $this->floors->update($f);
        return $this->serializeFloor($saved);
    }

    /**
     * Delete a floor and all rooms on it. Rejected if any room is referenced.
     */
    public function deleteFloor(int $id): void {
        $f = $this->floors->findById($id);
        if ($f === null) {
            throw new DoesNotExistException("Floor {$id} not found");
        }

        $rooms   = $this->rooms->findByFloor($id);
        $roomIds = array_map(fn(Room $r) => $r->getId(), $rooms);
        $this->assertRoomsUnreferenced($roomIds);

        $this->rooms->deleteByFloor($id);
        $this->floors->delete($f);
    }

    // -------------------------------------------------------------------------
    // Room CRUD
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createRoom(array $data): array {
        $floorId = (int)($data['floor_id'] ?? 0);
        if ($floorId <= 0) {
            throw new \InvalidArgumentException('floor_id is required');
        }
        if ($this->floors->findById($floorId) === null) {
            throw new DoesNotExistException("Floor {$floorId} not found");
        }

        $r = new Room();
        $r->setFloorId($floorId);
        $r->setName($this->validateName($data['name'] ?? '', 'room'));
        $r->setSortOrder($this->validateSortOrder($data['sort_order'] ?? 0));
        $r->setCreatedAt(time());

        /** @var Room $saved */
        $saved = $this->rooms->insert($r);
        return $this->serializeRoom($saved);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateRoom(int $id, array $data): array {
        $r = $this->rooms->findById($id);
        if ($r === null) {
            throw new DoesNotExistException("Room {$id} not found");
        }

        if (array_key_exists('name', $data)) {
            $r->setName($this->validateName($data['name'], 'room'));
        }
        if (array_key_exists('sort_order', $data)) {
            $r->setSortOrder($this->validateSortOrder($data['sort_order']));
        }
        // floor_id intentionally non-updatable — same reasoning as floor.building_id.
        if (array_key_exists('floor_id', $data)) {
            $this->logger->info(sprintf(
                '[TeamHub][PresenceLocationService] updateRoom(%d): ignoring floor_id (moves not supported)',
                $id
            ));
        }

        /** @var Room $saved */
        $saved = $this->rooms->update($r);
        return $this->serializeRoom($saved);
    }

    /**
     * Delete a room. Rejected if any template or slot row references it.
     */
    public function deleteRoom(int $id): void {
        $r = $this->rooms->findById($id);
        if ($r === null) {
            throw new DoesNotExistException("Room {$id} not found");
        }

        $this->assertRoomsUnreferenced([$id]);
        $this->rooms->delete($r);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns every room id under a building. Used by deleteBuilding before
     * the cascade so we can answer "is any of these in use?" in a single
     * count query.
     *
     * @return int[]
     */
    private function collectRoomIdsUnderBuilding(int $buildingId): array {
        $out    = [];
        $floors = $this->floors->findByBuilding($buildingId);
        foreach ($floors as $f) {
            $rooms = $this->rooms->findByFloor($f->getId());
            foreach ($rooms as $r) {
                $out[] = $r->getId();
            }
        }
        return $out;
    }

    /**
     * Throw a 409 if any of the given room ids is referenced by an active
     * template or slot row. The thrown exception carries the count.
     *
     * @param int[] $roomIds
     */
    private function assertRoomsUnreferenced(array $roomIds): void {
        if (count($roomIds) === 0) {
            return;
        }
        $slotCount = $this->slotQuery->countByRoomIds($roomIds);
        $tmplCount = $this->tmplQuery->countByRoomIds($roomIds);
        $total     = $slotCount + $tmplCount;

        if ($total > 0) {
            throw new PresenceConflictException(
                'Location is in use and cannot be deleted',
                $total
            );
        }
    }

    private function validateName(mixed $name, string $kind): string {
        $name = trim((string)$name);
        if ($name === '') {
            throw new \InvalidArgumentException("{$kind} name is required");
        }
        if (mb_strlen($name) > 255) {
            throw new \InvalidArgumentException("{$kind} name exceeds 255 characters");
        }
        return $name;
    }

    private function validateAddress(mixed $address): ?string {
        if ($address === null) {
            return null;
        }
        $address = trim((string)$address);
        if ($address === '') {
            return null;
        }
        if (mb_strlen($address) > 255) {
            throw new \InvalidArgumentException('address exceeds 255 characters');
        }
        return $address;
    }

    private function validateSortOrder(mixed $v): int {
        $i = (int)$v;
        if ($i < 0 || $i > 1000000) {
            throw new \InvalidArgumentException('sort_order out of range');
        }
        return $i;
    }

    /** @return array<string, mixed> */
    private function serializeBuilding(Building $b): array {
        return [
            'id'         => $b->getId(),
            'name'       => $b->getName(),
            'address'    => $b->getAddress(),
            'sort_order' => $b->getSortOrder(),
            'created_at' => $b->getCreatedAt(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeFloor(Floor $f): array {
        return [
            'id'          => $f->getId(),
            'building_id' => $f->getBuildingId(),
            'name'        => $f->getName(),
            'sort_order'  => $f->getSortOrder(),
            'created_at'  => $f->getCreatedAt(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeRoom(Room $r): array {
        return [
            'id'         => $r->getId(),
            'floor_id'   => $r->getFloorId(),
            'name'       => $r->getName(),
            'sort_order' => $r->getSortOrder(),
            'created_at' => $r->getCreatedAt(),
        ];
    }
}
