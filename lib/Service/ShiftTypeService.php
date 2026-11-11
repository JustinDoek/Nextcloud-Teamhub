<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\RosterTemplateRowMapper;
use OCA\TeamHub\Db\ShiftType;
use OCA\TeamHub\Db\ShiftTypeMapper;
use OCP\IL10N;

/**
 * The shift vocabulary, for the Nextcloud administrator (v4.13.68).
 *
 * Instance-wide and administrator-managed, the same contract as presence
 * types. Three are seeded by `RepairSteps\SeedShiftTypes`; this is how an
 * organisation that works eight to four says so.
 *
 * **A built-in may be renamed, retimed, recoloured and switched off, but not
 * deleted.** The seed is insert-when-absent, so a deleted built-in would come
 * back on the next upgrade and look like a bug; switching it off is the
 * durable way to say "we do not work nights".
 */
class ShiftTypeService {

    private const TIME_RE = '/^([01]\d|2[0-3]):([0-5]\d)$/';

    public function __construct(
        private ShiftTypeMapper          $typeMapper,
        private RosterTemplateRowMapper  $templateRowMapper,
        private IL10N                    $l,
    ) {}

    /** @return list<array<string, mixed>> */
    public function listAll(): array {
        return array_map(
            fn (ShiftType $type): array => $this->serialize($type),
            $this->typeMapper->findAll(),
        );
    }

    /**
     * Create or update one type.
     *
     * @throws \InvalidArgumentException
     * @return array<string, mixed>
     */
    public function save(
        ?int $id,
        string $label,
        string $startTime,
        string $endTime,
        string $color,
        string $icon,
        bool $isActive,
    ): array {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 128) {
            throw new \InvalidArgumentException($this->l->t('A shift needs a name of up to 128 characters.'));
        }
        if (preg_match(self::TIME_RE, $startTime) !== 1 || preg_match(self::TIME_RE, $endTime) !== 1) {
            throw new \InvalidArgumentException($this->l->t('Times are 24-hour, as HH:MM.'));
        }
        // A colour reaches a style attribute, so it is an allow-list rather
        // than a trim: anything else is a CSS injection waiting for a theme.
        if ($color !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
            throw new \InvalidArgumentException($this->l->t('A colour is a hex value such as #0082C9.'));
        }

        $now = time();
        if ($id === null) {
            $type = new ShiftType();
            $type->setSlug($this->freeSlug($label));
            $type->setIsBuiltin(0);
            $type->setSortOrder($this->nextSortOrder());
            $type->setCreatedAt($now);
        } else {
            $type = $this->typeMapper->findById($id);
            if ($type === null) {
                throw new \InvalidArgumentException($this->l->t('That shift type does not exist.'));
            }
        }

        $type->setLabel($label);
        $type->setStartTime($startTime);
        $type->setEndTime($endTime);
        $type->setColor($color);
        $type->setIcon(mb_substr(trim($icon), 0, 64));
        $type->setIsActive($isActive ? 1 : 0);
        $type->setUpdatedAt($now);

        /** @var ShiftType $saved */
        $saved = $id === null ? $this->typeMapper->insert($type) : $this->typeMapper->update($type);

        return $this->serialize($saved);
    }

    /**
     * Delete a type an administrator added.
     *
     * Refused for a built-in (the seed would restore it on the next upgrade)
     * and for one a team's week template still uses, because the template
     * would silently lose a line and that team would find its drafted week one
     * shift short with nothing on screen to say why.
     *
     * v4.14.4 — until this release the question was asked of the instance-wide
     * schedules of §2.234, which no longer exist. A team's template is what
     * depends on the vocabulary now, and it was never checked at all, so a
     * type a team was rostering from could be deleted without a word.
     */
    public function delete(int $id): void {
        $type = $this->typeMapper->findById($id);
        if ($type === null) {
            throw new \InvalidArgumentException($this->l->t('That shift type does not exist.'));
        }
        if ($type->getIsBuiltin() === 1) {
            throw new \InvalidArgumentException(
                $this->l->t('A built-in shift cannot be deleted. Switch it off instead.'),
            );
        }
        if ($this->templateRowMapper->usesShiftType($id)) {
            throw new \InvalidArgumentException(
                $this->l->t('A team roster template still uses this shift. Take it out of the template first.'),
            );
        }

        $this->typeMapper->delete($type);
    }

    /** A slug nothing else is using, derived from the name. */
    private function freeSlug(string $label): string {
        $base = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($label)) ?: 'shift';
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'shift';
        }
        $base = mb_substr($base, 0, 56);

        $slug = $base;
        $n    = 2;
        while ($this->typeMapper->findBySlug($slug) !== null) {
            $slug = $base . '_' . $n;
            $n++;
        }
        return $slug;
    }

    private function nextSortOrder(): int {
        $max = 0;
        foreach ($this->typeMapper->findAll() as $type) {
            $max = max($max, $type->getSortOrder());
        }
        return $max + 10;
    }

    /** @return array<string, mixed> */
    private function serialize(ShiftType $type): array {
        return [
            'id'          => $type->getId(),
            'slug'        => $type->getSlug(),
            'label'       => $type->getLabel(),
            'startTime'   => $type->getStartTime(),
            'endTime'     => $type->getEndTime(),
            'endsNextDay' => $type->endsNextDay(),
            'lengthHours' => round($type->lengthMinutes() / 60, 2),
            'color'       => $type->getColor(),
            'icon'        => $type->getIcon(),
            'isActive'    => $type->getIsActive() === 1,
            'isBuiltin'   => $type->getIsBuiltin() === 1,
        ];
    }
}
