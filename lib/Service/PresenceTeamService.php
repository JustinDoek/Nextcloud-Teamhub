<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\PresenceSlotMapper;
use OCA\TeamHub\Db\HolidayMapper;
use OCA\TeamHub\Db\PresenceTeamConfig;
use OCA\TeamHub\Db\PresenceTeamConfigMapper;
use OCA\TeamHub\Db\PresenceTypeMapper;
use OCP\UserStatus\IManager as IUserStatusManager;
use OCP\UserStatus\IUserStatus;
use Psr\Log\LoggerInterface;

/**
 * Team-facing presence data: grid payload and per-team configuration.
 *
 * Grid payload:
 *   Fetches slots for every effective team member across a date range,
 *   applies the hide_reasons privacy filter, and returns a flat structure
 *   the frontend can render without further requests.
 *
 * Privacy filter (§3.10 / §C, B3 plan):
 *   When hide_reasons=1, cells are replaced with a 3-tone representation:
 *     busy  → colour #EF5350 (red),  label null
 *     free  → colour #66BB6A (green), label null
 *     off   → colour #BDBDBD (grey),  label null
 *   The specific status type (label, icon, slug) is withheld. Only the team
 *   admin who turned hide_reasons on sees the full detail (via their own
 *   MyPresencePanel, which is always unfiltered).
 *
 * Config:
 *   Reads/writes teamhub_presence_team rows. Absence of a row = all defaults
 *   (presence_enabled=1, hide_reasons=0). Rows are created on first write. The v4.13.41 data migration
 * retains disabled settings for pre-existing teams.
 */
class PresenceTeamService {

    /** 3-tone palette used when hide_reasons=1 */
    private const PALETTE_BUSY = '#EF5350';
    private const PALETTE_FREE = '#66BB6A';
    private const PALETTE_OFF  = '#BDBDBD';

    public function __construct(
        private PresenceTeamConfigMapper $configMapper,
        private PresenceSlotMapper       $slotMapper,
        private HolidayMapper            $holidayMapper,
        // v4.13.64 — NextcloudAvailabilityService, TimezoneService and
        // IUserSession left with the free/busy sampling above. The service
        // itself stays in the app; the meeting wizard is its only caller now.
        private PresenceTypeMapper       $typeMapper,
        private MemberService            $memberService,
        private IUserStatusManager       $userStatusManager,
        private LoggerInterface          $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Config
    // -------------------------------------------------------------------------

    /**
     * Return the per-team presence config as a plain array.
     * Absent row = defaults.
     *
     * v4.13.68 — `shifts_enabled` joins the row. Unlike `presence_enabled`,
     * an absent row reads as **off**: Shifts is licensed, so a team that has
     * never been told about it must not acquire a roster on upgrade.
     *
     * @return array{presence_enabled: bool, hide_reasons: bool, shifts_enabled: bool}
     */
    public function getConfig(string $teamId): array {
        $row = $this->configMapper->findByTeam($teamId);
        return [
            'presence_enabled' => $row === null || $row->getPresenceEnabled() === 1,
            'hide_reasons'     => $row !== null && $row->getHideReasons() === 1,
            'shifts_enabled'   => $row !== null && $row->getShiftsEnabled() === 1,
        ];
    }

    /**
     * Write one or both config flags. Only keys present in $data are changed.
     * Creates the row on first write.
     *
     * @param array<string, mixed> $data  Keys: presence_enabled (bool/int), hide_reasons (bool/int), shifts_enabled (bool/int)
     * @return array{presence_enabled: bool, hide_reasons: bool, shifts_enabled: bool}
     */
    public function saveConfig(string $teamId, array $data): array {
        $now = time();
        $row = $this->configMapper->findByTeam($teamId);

        if ($row === null) {
            $row = new PresenceTeamConfig();
            $row->setTeamId($teamId);
            $row->setPresenceEnabled(1);
            $row->setHideReasons(0);
            $row->setShiftsEnabled(0);
            $row->setCreatedAt($now);
            $row->setUpdatedAt($now);

            if (array_key_exists('presence_enabled', $data)) {
                $row->setPresenceEnabled((int)!!$data['presence_enabled']);
            }
            if (array_key_exists('hide_reasons', $data)) {
                $row->setHideReasons((int)!!$data['hide_reasons']);
            }
            if (array_key_exists('shifts_enabled', $data)) {
                $row->setShiftsEnabled((int)!!$data['shifts_enabled']);
            }

            /** @var PresenceTeamConfig $saved */
            $saved = $this->configMapper->insert($row);
        } else {
            if (array_key_exists('presence_enabled', $data)) {
                $row->setPresenceEnabled((int)!!$data['presence_enabled']);
            }
            if (array_key_exists('hide_reasons', $data)) {
                $row->setHideReasons((int)!!$data['hide_reasons']);
            }
            if (array_key_exists('shifts_enabled', $data)) {
                $row->setShiftsEnabled((int)!!$data['shifts_enabled']);
            }
            $row->setUpdatedAt($now);

            /** @var PresenceTeamConfig $saved */
            $saved = $this->configMapper->update($row);
        }

        $this->logger->info(sprintf(
            '[TeamHub][PresenceTeamService] saveConfig team=%s enabled=%d hide=%d',
            $teamId,
            $saved->getPresenceEnabled(),
            $saved->getHideReasons()
        ));

        return [
            'presence_enabled' => $saved->getPresenceEnabled() === 1,
            'hide_reasons'     => $saved->getHideReasons() === 1,
            'shifts_enabled'   => $saved->getShiftsEnabled() === 1,
        ];
    }

    // -------------------------------------------------------------------------
    // Grid
    // -------------------------------------------------------------------------

    /**
     * Return the team presence grid for a date range.
     *
     * Shape:
     * {
     *   members: [{ userId, displayName }],
     *   slots:   { "<userId>": { "<date>_<halfDay>": slotCell } },
     *   hide_reasons: bool,
     *   from: "YYYY-MM-DD",
     *   to:   "YYYY-MM-DD",
     * }
     *
     * slotCell when hide_reasons=false:
     *   { color, label, icon, slug, requires_location, location_room_id, source, is_locked }
     *
     * slotCell when hide_reasons=true:
     *   { color, label: null, icon: null, slug: null, requires_location: false,
     *     location_room_id: null, source: null, is_locked: false }
     *
     * Absent keys in slots mean no slot on that date/half.
     *
     * @return array<string, mixed>
     */
    public function getTeamGrid(string $teamId, string $fromDate, string $toDate): array {
        $this->assertIsoDate($fromDate);
        $this->assertIsoDate($toDate);

        // Config row (hide_reasons flag).
        $cfg        = $this->getConfig($teamId);
        $hideReasons = $cfg['hide_reasons'];

        // All effective members — sorted by displayName.
        $members = $this->memberService->getAllEffectiveMembers($teamId);

        if (count($members) === 0) {
            return [
                'members'      => [],
                'slots'        => (object)[],
                'nc_status'    => (object)[],
                'hide_reasons' => $hideReasons,
                'from'         => $fromDate,
                'to'           => $toDate,
            ];
        }

        // Load all type metadata once — used for enrichment and hide_reasons palette.
        $types = [];
        foreach ($this->typeMapper->findAll() as $t) {
            $types[$t->getId()] = $t;
        }

        // Fetch slots for all members in the date range.
        $userIds = array_column($members, 'userId');
        $slotsByUser = [];

        foreach ($userIds as $uid) {
            $userSlots = $this->slotMapper->findByUserAndRange($uid, $fromDate, $toDate);
            $slotsByUser[$uid] = [];
            foreach ($userSlots as $slot) {
                $key = $slot->getSlotDate() . '_' . $slot->getHalfDay();
                $type = $types[$slot->getPresenceTypeId() ?? -1] ?? null;
                if ($slot->getSource() === 'holiday' || $type === null
                    || ($type->getIsBusy() === 1 && $slot->getSource() !== 'override')) {
                    continue; // Ignore old busy templates, keep deliberate time-off overrides.
                }
                $slotsByUser[$uid][$key] = $this->serializeSlotCell(
                    $slot, $types, $hideReasons
                );
            }
        }

        // Holidays are global admin settings and must not overwrite workplace
        // records. Overlay read-time for every member, including those who
        // have never configured their workplace.
        $day = new \DateTimeImmutable($fromDate . ' 12:00:00');
        $last = new \DateTimeImmutable($toDate . ' 12:00:00');
        for ($i = 0; $i < 14 && $day <= $last; $i++, $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $holiday = $this->holidayMapper->findByDate($date);
            if ($holiday === null) { continue; }
            foreach ($userIds as $uid) {
                foreach ([0, 1] as $half) {
                    $slotsByUser[$uid][$date . '_' . $half] = $hideReasons
                        ? ['color' => self::PALETTE_OFF, 'label' => null, 'slug' => null, 'is_busy' => true]
                        : ['color' => '#C2185B', 'label' => $holiday->getName(), 'slug' => 'holiday', 'is_busy' => true, 'is_locked' => true];
                }
            }
        }

        // v4.13.64 — the grid no longer samples free/busy. §2.215 added two
        // samples per day (10:00-11:00, 14:00-15:00) as a by-product of
        // building NextcloudAvailabilityService for the meeting wizard, and
        // said in as many words that if a dot were ever read as "free all day"
        // the honest fix was to label the samples. It was labelled, and the
        // label is the finding: a signal that needs a sentence above the table
        // to avoid being misread was costing more than it gave. Justin's call,
        // 2026-11-01, reasoning in §2.230.
        //
        // The wizard is unaffected and is where the question is decided
        // properly, per candidate slot at exact times, including the rule that
        // a deliberate TeamHub absence beats a `free` from FreeBusy
        // (MeetingSuggestionService::scoreCandidate).

        // Fetch live NC user status for the same members. The members widget
        // merges this with the presence schedule into a single dot (the schedule
        // is the baseline; an overriding NC status wins). See mapNcStatus() for
        // the override classification.
        $ncStatusByUser = $this->fetchNcStatuses($userIds);

        return [
            'members'      => $members,
            'slots'        => $slotsByUser,
            'nc_status'    => $ncStatusByUser ?: (object)[],
            'hide_reasons' => $hideReasons,
            'from'         => $fromDate,
            'to'           => $toDate,
        ];
    }

    /**
     * Fetch and classify NC user statuses for a set of users.
     *
     * Returns a map keyed by userId. Each value:
     *   [ 'status' => string, 'overrides' => bool ]
     * where `status` is one of the IUserStatus constants (online/away/dnd/busy/
     * offline — invisible is reported by NC as offline) and `overrides` says
     * whether this status should take precedence over the TeamHub presence
     * schedule, per the agreed rule:
     *
     *   - dnd / busy / online  → overrides (explicit, or logged-in-online)
     *   - away:
     *       automatic (idle/availability automation) → does NOT override
     *       manual (no `availability` message id)     → overrides
     *   - offline / invisible / none → does NOT override
     *
     * Auto-vs-manual away is inferred from getMessageId() === MESSAGE_AVAILABILITY,
     * the signal NC's own automation sets. This is a heuristic: a hand-picked
     * "Away" preset may also carry it and would then be treated as automatic.
     * The public IUserStatus interface exposes no explicit manual/automatic flag.
     *
     * Users with no status row are omitted from the map (frontend falls back to
     * the presence schedule).
     *
     * @param string[] $userIds
     * @return array<string, array{status: string, overrides: bool}>
     */
    private function fetchNcStatuses(array $userIds): array {
        if (count($userIds) === 0) {
            return [];
        }

        try {
            $statuses = $this->userStatusManager->getUserStatuses($userIds);
        } catch (\Throwable $e) {
            // Status is non-essential — on failure the widget shows the schedule.
            $this->logger->warning('[TeamHub][PresenceTeamService] getUserStatuses failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $out = [];
        foreach ($statuses as $uid => $status) {
            if (!($status instanceof IUserStatus)) {
                continue;
            }
            $out[$uid] = [
                'status'    => $status->getStatus(),
                'overrides' => $this->ncStatusOverrides($status),
            ];
        }
        return $out;
    }

    /**
     * Decide whether an NC status overrides the TeamHub presence schedule.
     * See fetchNcStatuses() for the full rule.
     */
    private function ncStatusOverrides(IUserStatus $status): bool {
        $value = $status->getStatus();

        switch ($value) {
            case IUserStatus::DND:
            case IUserStatus::BUSY:
            case IUserStatus::ONLINE:
                return true;

            case IUserStatus::AWAY:
                // Distinguish a deliberate user-set away (overrides) from the
                // idle/availability automation (revert to schedule).
                //
                // Preferred signal: getIsUserDefined() on the concrete status
                // object — NC's own CalDAV automation uses it to tell user-set
                // apart from automatic. If a status is user-defined, it overrides.
                $isUserDefined = $this->getIsUserDefined($status);
                if ($isUserDefined !== null) {
                    return $isUserDefined;
                }
                // Fallback when the method is unavailable: automatic away carries
                // the `availability` predefined message id; anything else is
                // treated as a deliberate away and overrides.
                return $this->getMessageId($status) !== IUserStatus::MESSAGE_AVAILABILITY;

            // OFFLINE / INVISIBLE (reported as offline) / anything else.
            default:
                return false;
        }
    }

    /**
     * Read whether the status was set by the user (vs. automation), if the
     * concrete implementation exposes getIsUserDefined(). Not part of the public
     * IUserStatus interface; guard with method_exists. Returns null when the
     * signal is unavailable so the caller can fall back to the message-id check.
     */
    private function getIsUserDefined(IUserStatus $status): ?bool {
        if (method_exists($status, 'getIsUserDefined')) {
            /** @psalm-suppress UndefinedInterfaceMethod */
            $v = $status->getIsUserDefined();
            return $v === null ? null : (bool)$v;
        }
        return null;
    }

    /**
     * Read the predefined message id from a status if the implementation exposes
     * it. The public IUserStatus interface (getMessage/getIcon/getClearAt) does
     * not declare getMessageId(), but the concrete server implementation does.
     * Guard with method_exists so we degrade gracefully if it is absent.
     */
    private function getMessageId(IUserStatus $status): ?string {
        if (method_exists($status, 'getMessageId')) {
            /** @psalm-suppress UndefinedInterfaceMethod */
            return $status->getMessageId();
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<int, \OCA\TeamHub\Db\PresenceType> $types Keyed by id
     * @return array<string, mixed>
     */
    private function serializeSlotCell(
        \OCA\TeamHub\Db\PresenceSlot $slot,
        array $types,
        bool $hideReasons,
    ): array {
        $typeId  = $slot->getPresenceTypeId();
        $typeRow = $typeId !== null ? ($types[$typeId] ?? null) : null;
        $isBusy  = $typeRow !== null && $typeRow->getIsBusy() === 1;
        $isLocked = $slot->getSource() === 'holiday';

        // Health-related absence reasons are sensitive. Never expose the
        // exact category to other team members, even if hide_reasons is off.
        if ($isBusy && $slot->getSource() === 'override') {
            return [
                'color' => self::PALETTE_BUSY,
                'label' => $hideReasons ? null : 'Unavailable',
                'icon' => null, 'slug' => $hideReasons ? null : 'unavailable',
                'is_busy' => true, 'requires_location' => false,
                'location_room_id' => null, 'source' => 'override', 'is_locked' => false,
            ];
        }
        if ($hideReasons) {
            // Apply 3-tone palette; suppress all identifying information.
            if ($typeRow === null) {
                $color = self::PALETTE_OFF;
            } elseif ($isBusy) {
                $color = self::PALETTE_BUSY;
            } else {
                $color = self::PALETTE_FREE;
            }
            return [
                'color'             => $color,
                'label'             => null,
                'icon'              => null,
                'slug'              => null,
                // is_busy is the busy/free distinction the 3-tone palette already
                // reveals via colour, so exposing it here leaks nothing further. It
                // lets the members-widget presence sort rank schedule-busy vs
                // schedule-free even when reasons are hidden.
                'is_busy'           => $isBusy,
                'requires_location' => false,
                'location_room_id'  => null,
                'source'            => null,
                'is_locked'         => false, // holiday lock not revealed when hide_reasons on
            ];
        }

        return [
            'color'             => $typeRow?->getColor() ?? '',
            'label'             => $typeRow?->getLabel(),
            'icon'              => $typeRow?->getIcon(),
            'slug'              => $typeRow?->getSlug(),
            // Used by the members-widget presence sort to rank schedule-busy
            // types ahead of schedule-free types.
            'is_busy'           => $isBusy,
            'requires_location' => $typeRow !== null && $typeRow->getRequiresLocation() === 1,
            'location_room_id'  => $slot->getLocationRoomId(),
            'source'            => $slot->getSource(),
            'is_locked'         => $isLocked,
        ];
    }

    private function assertIsoDate(string $date): void {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $date)) {
            throw new \InvalidArgumentException("Invalid date: {$date}");
        }
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        if (!checkdate($m, $d, $y)) {
            throw new \InvalidArgumentException("Not a valid calendar date: {$date}");
        }
    }
}
