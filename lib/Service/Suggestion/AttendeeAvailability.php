<?php

declare(strict_types=1);

namespace OCA\TeamHub\Service\Suggestion;

use OCA\TeamHub\Db\PresenceSlot;
use OCA\TeamHub\Db\PresenceSlotMapper;
use OCA\TeamHub\Db\PresenceTypeMapper;
use OCA\TeamHub\Service\PresenceDefaultService;
use OCA\TeamHub\Service\TimezoneService;
use OCP\IUserManager;

/**
 * Who cannot come to a meeting, and why: the one rule both meeting suggesters
 * use (v4.20.14, DESIGN §2.266).
 *
 * Three reasons, checked in this order:
 *
 *   - `off`        Presence says the person is not working then. Either their
 *                  weekly template leaves that half-day empty (Team presence
 *                  shows it as "Not working") or a one-off entry of a busy type
 *                  (vacation, sick, non-working day) covers it. Someone who
 *                  never saved a template has a default office week
 *                  (PresenceDefaultService), so a missing row only means "off"
 *                  for a person who did.
 *   - `unchecked`  Nextcloud gave no answer for their calendar, usually because
 *                  the account has no email address. Never read as free.
 *   - `calendar`   Nextcloud's availability check says busy: an event, their
 *                  working hours or an absence.
 *
 * Before 4.20.14 a missing presence row counted as "available, workplace
 * unknown", and the time slot list did not read presence at all, so a person
 * on their day off still read as available.
 */
class AttendeeAvailability {

    public const OFF       = 'off';
    public const UNCHECKED = 'unchecked';
    public const CALENDAR  = 'calendar';

    /** @var array<int, array{isBusy: bool, requiresLocation: bool}>|null */
    private ?array $typeFlags = null;

    /** @var array<string, array{tz: \DateTimeZone, configured: bool, slots: array<string, PresenceSlot>, loaded: array<string, bool>}> */
    private array $ctx = [];

    public function __construct(
        private PresenceSlotMapper     $slotMapper,
        private PresenceTypeMapper     $typeMapper,
        private PresenceDefaultService $presenceDefaults,
        private TimezoneService        $timezoneService,
        private IUserManager           $userManager,
    ) {
    }

    /**
     * The presence rule on its own. A busy type only counts when somebody set
     * it for that day (`override`); busy types in old weekly templates are
     * ignored, as they always were (DESIGN §2.230).
     *
     * @param array{isBusy: bool, requiresLocation: bool}|null $flags
     */
    public static function isOff(?PresenceSlot $slot, ?array $flags, bool $configured): bool {
        if ($slot === null) {
            return $configured;
        }
        return $slot->getSource() === 'override' && ($flags['isBusy'] ?? false);
    }

    /** @return array<int, array{isBusy: bool, requiresLocation: bool}> */
    public function typeFlags(): array {
        if ($this->typeFlags === null) {
            $this->typeFlags = [];
            foreach ($this->typeMapper->findAll() as $type) {
                $this->typeFlags[$type->getId()] = [
                    'isBusy'           => $type->getIsBusy() === 1,
                    'requiresLocation' => $type->getRequiresLocation() === 1,
                ];
            }
        }
        return $this->typeFlags;
    }

    /**
     * Whether a person who never saved a template is filled with the default
     * week. Exposed so the half-day scorer, which loads its own slots, applies
     * the same `configured` test.
     */
    public function isConfigured(string $uid): bool {
        return $this->presenceDefaults->isConfigured($uid);
    }

    /**
     * Is each attendee off at this instant, in their own timezone?
     *
     * @param list<string> $uids
     * @return array<string, bool>
     */
    public function offAt(array $uids, int $instantTs): array {
        $flags = $this->typeFlags();
        $out = [];
        foreach ($uids as $uid) {
            $ctx = $this->context($uid, $instantTs);
            $local = (new \DateTimeImmutable('@' . $instantTs))->setTimezone($ctx['tz']);
            $key = $local->format('Y-m-d') . '|' . ((int)$local->format('G') < 12 ? 0 : 1);
            $slot = $ctx['slots'][$key] ?? null;
            $typeId = $slot?->getPresenceTypeId();
            $out[$uid] = self::isOff($slot, $typeId !== null ? ($flags[$typeId] ?? null) : null, $ctx['configured']);
        }
        return $out;
    }

    /**
     * The people who cannot come, with a reason, for the wizard to name.
     * Only names and a reason code: never an event title or a presence type.
     *
     * @param list<string>              $uids
     * @param array<string, bool|null>  $native Nextcloud's verdicts
     * @param array<string, bool>       $off    from offAt() or the scorer
     * @return list<array{userId: string, displayName: string, reason: string}>
     */
    public function unavailable(array $uids, array $native, array $off): array {
        $out = [];
        foreach ($uids as $uid) {
            if ($off[$uid] ?? false) {
                $reason = self::OFF;
            } elseif (($native[$uid] ?? null) === null) {
                $reason = self::UNCHECKED;
            } elseif ($native[$uid] === false) {
                $reason = self::CALENDAR;
            } else {
                continue;
            }
            $out[] = [
                'userId'      => $uid,
                'displayName' => $this->userManager->getDisplayName($uid) ?? $uid,
                'reason'      => $reason,
            ];
        }
        return $out;
    }

    /**
     * Slots for one attendee around an instant, loaded once per request and
     * widened when a later instant falls outside what was loaded.
     *
     * @return array{tz: \DateTimeZone, configured: bool, slots: array<string, PresenceSlot>, loaded: array<string, bool>}
     */
    private function context(string $uid, int $instantTs): array {
        $date = gmdate('Y-m-d', $instantTs);
        if (isset($this->ctx[$uid]) && isset($this->ctx[$uid]['loaded'][$date])) {
            return $this->ctx[$uid];
        }
        // A day either side: an attendee in a far timezone can map the
        // organiser's instant onto an adjacent local date.
        $from = gmdate('Y-m-d', $instantTs - 86400);
        $to   = gmdate('Y-m-d', $instantTs + 86400);
        $slots = $this->slotMapper->findByUserAndRange($uid, $from, $to);
        $slots = $this->presenceDefaults->withDefaultSlots($uid, $slots, $from, $to);
        $ctx = $this->ctx[$uid] ?? [
            'tz'         => $this->timezoneService->forUser($uid),
            'configured' => $this->presenceDefaults->isConfigured($uid),
            'slots'      => [],
            'loaded'     => [],
        ];
        foreach ($slots as $slot) {
            $ctx['slots'][$slot->getSlotDate() . '|' . $slot->getHalfDay()] = $slot;
        }
        $ctx['loaded'][$date] = true;
        return $this->ctx[$uid] = $ctx;
    }
}
