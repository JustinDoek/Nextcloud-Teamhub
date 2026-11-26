<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\PresenceSlot;
use OCA\TeamHub\Db\PresenceTemplate;
use OCA\TeamHub\Db\PresenceTemplateMapper;
use OCA\TeamHub\Db\PresenceTypeMapper;
use OCP\IConfig;

/**
 * The workplace somebody has before they set one: the office, Monday to
 * Friday, all day (v4.20.12, DESIGN §2.265).
 *
 * ## A rule, not rows
 *
 * The default is never written. A user with no template rows who has never
 * saved their template reads as Monday to Friday at the office, and every
 * surface that reads presence asks this class for it: the personal grid
 * (`PresenceTemplateService::getTemplate()`), the person's own calendar
 * (`PresenceSlotService::getSlotsForUser()`), the team grid
 * (`PresenceTeamService`) and the meeting suggestions
 * (`MeetingSuggestionService`). Writing fourteen template rows and a year of
 * slots for every account on the instance would have been the alternative,
 * for people who may never open TeamHub.
 *
 * ## Saved once means saved
 *
 * The first save of a template sets the user value
 * `presence_template_saved`. From then on the user's rows are the whole
 * truth, including none at all: a person who clears every day has said they
 * have no usual workplace, and the default must not come back. Without the
 * flag that person would be indistinguishable from somebody who never looked.
 * A user with template rows from before this release counts as saved without
 * the flag, so nothing an existing user set is reinterpreted.
 *
 * ## Overrides and holidays still win
 *
 * The default only fills a half-day that has no slot row. A temporary change
 * (home on Tuesday, a week of vacation) is a row and is read as it is; a
 * public holiday is overlaid by the read paths from the holiday table, as it
 * already was for every member with no template.
 */
class PresenceDefaultService {

    /** User value (app `teamhub`) set by the first template save. */
    public const PREF_TEMPLATE_SAVED = 'presence_template_saved';

    /** The seeded presence type the default uses (SeedPresenceTypes). */
    public const DEFAULT_TYPE_SLUG = 'office';

    /** 0 = Monday … 4 = Friday, matching day_of_week in the template. */
    public const DEFAULT_DAYS = [0, 1, 2, 3, 4];

    /** Upper bound on a filled range, so a malformed request cannot loop for ever. */
    private const MAX_FILL_DAYS = 800;

    private bool $typeResolved = false;
    private ?int $typeId = null;

    public function __construct(
        private PresenceTemplateMapper $templateMapper,
        private PresenceTypeMapper     $typeMapper,
        private IConfig                $config,
    ) {}

    /**
     * Has this user's own template taken over from the default?
     *
     * @param PresenceTemplate[]|null $rows The user's template rows, when the
     *        caller already has them; read here otherwise.
     */
    public function isConfigured(string $userId, ?array $rows = null): bool {
        if ($userId === '') {
            return true;
        }
        if ($this->config->getUserValue($userId, 'teamhub', self::PREF_TEMPLATE_SAVED, '') === '1') {
            return true;
        }
        $rows ??= $this->templateMapper->findByUser($userId);
        return count($rows) > 0;
    }

    /** Record that this user has saved their template, whatever it holds. */
    public function markConfigured(string $userId): void {
        if ($userId === '') {
            return;
        }
        $this->config->setUserValue($userId, 'teamhub', self::PREF_TEMPLATE_SAVED, '1');
    }

    /**
     * The id of the presence type the default uses, or null when the
     * instance has none a user could choose (renamed slugs are not followed:
     * the seeded type is slug-keyed and re-seeded on every upgrade).
     */
    public function defaultTypeId(): ?int {
        if ($this->typeResolved) {
            return $this->typeId;
        }
        $this->typeResolved = true;
        $type = $this->typeMapper->findBySlug(self::DEFAULT_TYPE_SLUG);
        if ($type !== null && $type->getIsBusy() === 0 && $type->getSelectableByUser() === 1) {
            $this->typeId = $type->getId();
        }
        return $this->typeId;
    }

    /**
     * The fourteen template cells of the default, in getTemplate()'s shape,
     * each marked `is_default`. Empty when there is no usable office type.
     *
     * @return list<array<string, mixed>>
     */
    public function defaultCells(): array {
        $typeId = $this->defaultTypeId();
        if ($typeId === null) {
            return [];
        }
        $out = [];
        for ($day = 0; $day <= 6; $day++) {
            foreach ([0, 1] as $half) {
                $working = in_array($day, self::DEFAULT_DAYS, true);
                $out[] = [
                    'day_of_week'      => $day,
                    'half_day'         => $half,
                    'presence_type_id' => $working ? $typeId : null,
                    'location_room_id' => null,
                    'updated_at'       => null,
                    'is_default'       => true,
                ];
            }
        }
        return $out;
    }

    /**
     * Add the default's slots to what the database returned for this user,
     * when the user has not saved a template. A configured user's slots come
     * back untouched.
     *
     * The added slots are unsaved entities with source `template`: to every
     * reader they are what a saved Monday-to-Friday template would have
     * materialised, which is the point.
     *
     * @param PresenceSlot[] $slots
     * @return PresenceSlot[]
     */
    public function withDefaultSlots(string $userId, array $slots, string $fromDate, string $toDate): array {
        if ($this->isConfigured($userId)) {
            return $slots;
        }
        $typeId = $this->defaultTypeId();
        if ($typeId === null) {
            return $slots;
        }
        $taken = [];
        foreach ($slots as $slot) {
            $taken[$slot->getSlotDate() . '_' . $slot->getHalfDay()] = true;
        }
        foreach (self::defaultSlotKeys($fromDate, $toDate, $taken) as [$date, $half]) {
            $slot = new PresenceSlot();
            $slot->setUserId($userId);
            $slot->setSlotDate($date);
            $slot->setHalfDay($half);
            $slot->setPresenceTypeId($typeId);
            $slot->setLocationRoomId(null);
            $slot->setSource('template');
            $slot->setCreatedAt(0);
            $slot->setUpdatedAt(0);
            $slots[] = $slot;
        }
        return $slots;
    }

    /**
     * Which (date, half) pairs the default fills in an inclusive ISO range:
     * every Monday to Friday half-day not already in `$taken`.
     *
     * @param array<string, bool> $taken "Y-m-d_half" => true
     * @return list<array{0: string, 1: int}>
     */
    public static function defaultSlotKeys(string $fromDate, string $toDate, array $taken = []): array {
        try {
            $day  = new \DateTimeImmutable($fromDate . ' 12:00:00');
            $last = new \DateTimeImmutable($toDate . ' 12:00:00');
        } catch (\Exception) {
            return [];
        }
        $out = [];
        for ($i = 0; $i < self::MAX_FILL_DAYS && $day <= $last; $i++, $day = $day->modify('+1 day')) {
            $dow = (int)$day->format('N') - 1;
            if (!in_array($dow, self::DEFAULT_DAYS, true)) {
                continue;
            }
            $date = $day->format('Y-m-d');
            foreach ([0, 1] as $half) {
                if (!isset($taken[$date . '_' . $half])) {
                    $out[] = [$date, $half];
                }
            }
        }
        return $out;
    }
}
