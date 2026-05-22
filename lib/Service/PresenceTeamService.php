<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\PresenceSlotMapper;
use OCA\TeamHub\Db\PresenceTeamConfig;
use OCA\TeamHub\Db\PresenceTeamConfigMapper;
use OCA\TeamHub\Db\PresenceTypeMapper;
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
 *   (presence_enabled=0, hide_reasons=0). Rows are created on first write.
 */
class PresenceTeamService {

    /** 3-tone palette used when hide_reasons=1 */
    private const PALETTE_BUSY = '#EF5350';
    private const PALETTE_FREE = '#66BB6A';
    private const PALETTE_OFF  = '#BDBDBD';

    public function __construct(
        private PresenceTeamConfigMapper $configMapper,
        private PresenceSlotMapper       $slotMapper,
        private PresenceTypeMapper       $typeMapper,
        private MemberService            $memberService,
        private LoggerInterface          $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Config
    // -------------------------------------------------------------------------

    /**
     * Return the per-team presence config as a plain array.
     * Absent row = defaults.
     *
     * @return array{presence_enabled: bool, hide_reasons: bool}
     */
    public function getConfig(string $teamId): array {
        $row = $this->configMapper->findByTeam($teamId);
        return [
            'presence_enabled' => $row !== null && $row->getPresenceEnabled() === 1,
            'hide_reasons'     => $row !== null && $row->getHideReasons() === 1,
        ];
    }

    /**
     * Write one or both config flags. Only keys present in $data are changed.
     * Creates the row on first write.
     *
     * @param array<string, mixed> $data  Keys: presence_enabled (bool/int), hide_reasons (bool/int)
     * @return array{presence_enabled: bool, hide_reasons: bool}
     */
    public function saveConfig(string $teamId, array $data): array {
        $now = time();
        $row = $this->configMapper->findByTeam($teamId);

        if ($row === null) {
            $row = new PresenceTeamConfig();
            $row->setTeamId($teamId);
            $row->setPresenceEnabled(0);
            $row->setHideReasons(0);
            $row->setCreatedAt($now);
            $row->setUpdatedAt($now);

            if (array_key_exists('presence_enabled', $data)) {
                $row->setPresenceEnabled((int)!!$data['presence_enabled']);
            }
            if (array_key_exists('hide_reasons', $data)) {
                $row->setHideReasons((int)!!$data['hide_reasons']);
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
                $slotsByUser[$uid][$key] = $this->serializeSlotCell(
                    $slot, $types, $hideReasons
                );
            }
        }

        return [
            'members'      => $members,
            'slots'        => $slotsByUser,
            'hide_reasons' => $hideReasons,
            'from'         => $fromDate,
            'to'           => $toDate,
        ];
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
