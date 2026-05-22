<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\PresenceSlotQueryMapper;
use OCA\TeamHub\Db\PresenceTemplateQueryMapper;
use OCA\TeamHub\Db\PresenceType;
use OCA\TeamHub\Db\PresenceTypeMapper;
use OCA\TeamHub\Exception\PresenceConflictException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Admin CRUD for teamhub_presence_types — the org's status catalogue.
 *
 * Built-in types (is_builtin=1, seeded by the SeedPresenceTypes repair step)
 * cannot be deleted. They CAN have label/icon/color/sort_order updated by an
 * admin — useful for branding ("Office" → "HQ"). Structural flags
 * (requires_location, is_busy, selectable_by_user, is_builtin) are immutable
 * for builtins to keep B2/B3/B4 behaviour predictable.
 *
 * The service layer maps entity objects to plain arrays at every boundary
 * per DESIGN.md "fat services, dumb mappers" — controllers see arrays only.
 */
class PresenceTypeService {

    /**
     * Fields a regular admin may set on a custom (non-builtin) type.
     * Used by createType to filter the input.
     */
    private const CUSTOM_WRITABLE_FIELDS = [
        'label', 'icon', 'color',
        'requires_location', 'is_busy', 'selectable_by_user',
        'sort_order',
    ];

    /**
     * Fields a regular admin may update on a built-in type.
     * Structural flags + is_builtin + slug are excluded.
     */
    private const BUILTIN_UPDATABLE_FIELDS = [
        'label', 'icon', 'color', 'sort_order',
    ];

    /**
     * Fields a regular admin may update on a custom type.
     * Everything except slug and is_builtin (which is a per-row constant).
     */
    private const CUSTOM_UPDATABLE_FIELDS = [
        'label', 'icon', 'color',
        'requires_location', 'is_busy', 'selectable_by_user',
        'sort_order',
    ];

    public function __construct(
        private PresenceTypeMapper          $types,
        private PresenceSlotQueryMapper     $slotQuery,
        private PresenceTemplateQueryMapper $tmplQuery,
        private IL10N                       $l,
        private LoggerInterface             $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Return all types as plain arrays, ordered by sort_order then label.
     *
     * Built-in labels are NOT translated here — the admin UI does the
     * translation client-side via t('teamhub', label) on the slug-keyed
     * canonical label, so the same row renders correctly in every locale
     * regardless of who created it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTypes(): array {
        $rows = $this->types->findAll();
        return array_map(fn(PresenceType $t) => $this->serialize($t), $rows);
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    /**
     * Create a custom type. Always is_builtin=0; the slug is auto-generated
     * from the label and post-fixed with a numeric suffix if it would collide.
     *
     * Returns the created row as a plain array.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createType(array $data): array {
        // Validate required label
        $label = isset($data['label']) ? trim((string)$data['label']) : '';
        if ($label === '') {
            throw new \InvalidArgumentException('label is required');
        }
        if (mb_strlen($label) > 128) {
            throw new \InvalidArgumentException('label exceeds 128 characters');
        }

        // Generate a unique slug from the label.
        $slug = $this->generateUniqueSlug($label);

        $type = new PresenceType();
        $type->setSlug($slug);
        $type->setLabel($label);
        $type->setIcon($this->validateIcon($data['icon'] ?? ''));
        $type->setColor($this->validateColor($data['color'] ?? ''));
        $type->setRequiresLocation((int)!!($data['requires_location'] ?? 0));
        $type->setIsBusy((int)!!($data['is_busy'] ?? 1));
        $type->setSelectableByUser((int)!!($data['selectable_by_user'] ?? 1));
        $type->setIsBuiltin(0);
        $type->setSortOrder($this->validateSortOrder($data['sort_order'] ?? 0));
        $type->setCreatedAt(time());

        // Filter ignored fields out of inbound payload — defensive, even though
        // the build above only reads known keys. Logged so unexpected client
        // payloads surface in dev.
        $unknown = array_diff(array_keys($data), array_merge(self::CUSTOM_WRITABLE_FIELDS, ['label']));
        if (!empty($unknown)) {
            $this->logger->debug(
                '[TeamHub][PresenceTypeService] createType: ignored unknown fields: '
                . implode(',', $unknown)
            );
        }

        /** @var PresenceType $saved */
        $saved = $this->types->insert($type);
        return $this->serialize($saved);
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    /**
     * Update an existing type. Built-ins accept only label/icon/color/sort_order.
     * Custom types accept everything except slug and is_builtin.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateType(int $id, array $data): array {
        $type = $this->types->findById($id);
        if ($type === null) {
            throw new DoesNotExistException("Presence type {$id} not found");
        }

        $isBuiltin    = $type->getIsBuiltin() === 1;
        $allowed      = $isBuiltin ? self::BUILTIN_UPDATABLE_FIELDS : self::CUSTOM_UPDATABLE_FIELDS;
        $rejected     = array_diff(array_keys($data), $allowed);

        // Reject silently in production but log — protects against
        // copy-paste bugs in the admin UI without surfacing as an error to
        // the operator.
        if (!empty($rejected)) {
            $this->logger->info(sprintf(
                '[TeamHub][PresenceTypeService] updateType(%d): ignoring fields %s on %s type',
                $id,
                implode(',', $rejected),
                $isBuiltin ? 'builtin' : 'custom'
            ));
        }

        if (array_key_exists('label', $data)) {
            $label = trim((string)$data['label']);
            if ($label === '') {
                throw new \InvalidArgumentException('label cannot be empty');
            }
            if (mb_strlen($label) > 128) {
                throw new \InvalidArgumentException('label exceeds 128 characters');
            }
            $type->setLabel($label);
        }

        if (array_key_exists('icon', $data)) {
            $type->setIcon($this->validateIcon($data['icon']));
        }

        if (array_key_exists('color', $data)) {
            $type->setColor($this->validateColor($data['color']));
        }

        if (array_key_exists('sort_order', $data)) {
            $type->setSortOrder($this->validateSortOrder($data['sort_order']));
        }

        if (!$isBuiltin) {
            if (array_key_exists('requires_location', $data)) {
                $type->setRequiresLocation((int)!!$data['requires_location']);
            }
            if (array_key_exists('is_busy', $data)) {
                $type->setIsBusy((int)!!$data['is_busy']);
            }
            if (array_key_exists('selectable_by_user', $data)) {
                $type->setSelectableByUser((int)!!$data['selectable_by_user']);
            }
        }

        /** @var PresenceType $saved */
        $saved = $this->types->update($type);
        return $this->serialize($saved);
    }

    // -------------------------------------------------------------------------
    // Delete
    // -------------------------------------------------------------------------

    /**
     * Delete a custom type. Rejects with PresenceConflictException if:
     *   - The type is built-in (is_builtin=1).
     *   - Any template or slot row still references this type. The exception
     *     carries the count for surfacing in the admin error toast.
     */
    public function deleteType(int $id): void {
        $type = $this->types->findById($id);
        if ($type === null) {
            throw new DoesNotExistException("Presence type {$id} not found");
        }

        if ($type->getIsBuiltin() === 1) {
            throw new PresenceConflictException(
                'Built-in presence types cannot be deleted'
            );
        }

        $slotCount = $this->slotQuery->countByPresenceType($id);
        $tmplCount = $this->tmplQuery->countByPresenceType($id);
        $total     = $slotCount + $tmplCount;

        if ($total > 0) {
            throw new PresenceConflictException(
                'Presence type is in use and cannot be deleted',
                $total
            );
        }

        $this->types->delete($type);
    }

    // -------------------------------------------------------------------------
    // Serialisation + validation helpers
    // -------------------------------------------------------------------------

    /**
     * Map a PresenceType entity to the plain-array shape returned by the
     * controller. SMALLINT-stored booleans are exposed as JSON booleans
     * for the frontend — the DB layer doesn't lie about its type, but the
     * API surface does the natural thing.
     *
     * @return array<string, mixed>
     */
    private function serialize(PresenceType $t): array {
        return [
            'id'                 => $t->getId(),
            'slug'               => $t->getSlug(),
            'label'              => $t->getLabel(),
            'icon'               => $t->getIcon(),
            'color'              => $t->getColor(),
            'requires_location'  => $t->getRequiresLocation() === 1,
            'is_busy'            => $t->getIsBusy() === 1,
            'selectable_by_user' => $t->getSelectableByUser() === 1,
            'is_builtin'         => $t->getIsBuiltin() === 1,
            'sort_order'         => $t->getSortOrder(),
            'created_at'         => $t->getCreatedAt(),
        ];
    }

    /**
     * Generate a slug for a custom type. Strips down to lowercase
     * a-z/0-9/underscore, prefixes 'custom_' to avoid colliding with future
     * built-in slugs (which use bare nouns like 'office', 'home'), and
     * appends a numeric suffix if the candidate is already taken.
     */
    private function generateUniqueSlug(string $label): string {
        // Lowercase, replace non-alphanumerics with underscores, collapse runs.
        $base = strtolower($label);
        $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?? '';
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'type';
        }
        $base = 'custom_' . substr($base, 0, 50);

        $candidate = $base;
        $suffix    = 2;
        while ($this->types->findBySlug($candidate) !== null) {
            $candidate = $base . '_' . $suffix;
            $suffix++;
            if ($suffix > 1000) {
                // Practically impossible — bail rather than loop forever.
                throw new \RuntimeException('Could not generate a unique slug');
            }
        }
        return $candidate;
    }

    /**
     * mdi icon name — alphanumeric and dashes only, max 64 chars. Empty is OK.
     */
    private function validateIcon(mixed $icon): string {
        $icon = trim((string)$icon);
        if ($icon === '') {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9\-]{1,64}$/', $icon)) {
            throw new \InvalidArgumentException(
                'icon must be alphanumeric/dash, max 64 characters'
            );
        }
        return $icon;
    }

    /**
     * Hex colour — '#RRGGBB' or '#RGB'. Empty is OK.
     */
    private function validateColor(mixed $color): string {
        $color = trim((string)$color);
        if ($color === '') {
            return '';
        }
        if (!preg_match('/^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $color)) {
            throw new \InvalidArgumentException(
                'color must be #RGB or #RRGGBB'
            );
        }
        return $color;
    }

    private function validateSortOrder(mixed $v): int {
        $i = (int)$v;
        if ($i < 0 || $i > 1000000) {
            throw new \InvalidArgumentException('sort_order out of range');
        }
        return $i;
    }
}
