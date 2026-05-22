<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\BackgroundJob\PresenceCalendarSyncJob;
use OCA\TeamHub\Db\PresenceSlot;
use OCA\TeamHub\Db\PresenceSlotMapper;
use OCA\TeamHub\Db\PresenceTemplateMapper;
use OCP\BackgroundJob\IJobList;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Slot materialisation — expands each user's week template into concrete
 * rows in teamhub_presence_slots for a rolling window.
 *
 * Rolling window (§2.44):
 *   Ensure every user with at least one template cell has slots from
 *   today through the end of (current year + 1). The nightly cron just
 *   calls materialiseAll(); template saves call rematerialiseForUser()
 *   so the user sees their change immediately.
 *
 * Idempotency:
 *   We check which dates already have at least one slot for the user and
 *   skip them. This means re-running for the same date is a no-op — safe
 *   for the cron to run every night regardless.
 *   Exception: when a template is SAVED, we delete all source='template'
 *   slots from today onward first (via PresenceSlotMapper) and then
 *   re-create — this ensures stale cells don't linger after a cell is cleared.
 *   source='override' and source='holiday' slots are always preserved.
 *
 * B4 addition: after slot writes, syncs VEVENTs to the user's default calendar
 * via PresenceCalendarService. On-save uses syncAllSlotsForUser() (one bulk
 * pass per user). Nightly cron uses syncSlot() per newly-created slot to avoid
 * touching already-correct events.
 *
 * AM/PM boundary: hard-coded noon (§2.40 / §C). half_day=0 → Morning,
 * half_day=1 → Afternoon. day_of_week follows the template: 0=Mon … 6=Sun.
 */
class PresenceMaterialisationService {

    public function __construct(
        private PresenceTemplateMapper  $templateMapper,
        private PresenceSlotMapper      $slotMapper,
        private PresenceCalendarService $calendarService,
        private IJobList                $jobList,
        private IDBConnection           $db,
        private LoggerInterface         $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Nightly cron entry point
    // -------------------------------------------------------------------------

    /**
     * Called by PresenceMaterialisationJob. Iterates every user who has at
     * least one template row and ensures their slots exist through the horizon.
     *
     * Does NOT delete stale template slots — the cron only adds missing ones.
     * Deletion of stale slots after a template edit is handled in
     * rematerialiseForUser() (triggered on every template save).
     */
    public function materialiseAll(): void {
        [$start, $end] = $this->rollingWindow();

        // Fetch every distinct user_id that has template rows.
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('user_id')->from('teamhub_presence_template');
        $result = $qb->executeQuery();
        $users  = [];
        while ($row = $result->fetch()) {
            $users[] = $row['user_id'];
        }
        $result->closeCursor();

        $total = 0;
        foreach ($users as $userId) {
            $newSlots = $this->materialiseForUserInRange($userId, $start, $end, deleteStale: false);
            if ($newSlots > 0) {
                // Queue calendar sync for users who got new slots (B4).
                $this->jobList->add(PresenceCalendarSyncJob::class, ['userId' => $userId]);
            }
            $total += $newSlots;
        }

        $this->logger->info(sprintf(
            '[TeamHub][PresenceMaterialisationService] materialiseAll: %d users, %d new slots, window %s–%s',
            count($users), $total, $start, $end
        ));
    }

    // -------------------------------------------------------------------------
    // On-save entry point (called by PresenceTemplateService::setCell)
    // -------------------------------------------------------------------------

    /**
     * Called after a user saves their template. Wipes all source='template'
     * slots from today onward and rebuilds from the current template state,
     * so stale entries from cleared cells don't linger.
     *
     * source='override' and source='holiday' are untouched.
     */
    public function rematerialiseForUser(string $userId): void {
        [$start, $end] = $this->rollingWindow();

        // Delete template-sourced slots from today onward.
        $this->slotMapper->deleteTemplateSlotsByUserFromDate($userId, $start);

        // Rebuild.
        $count = $this->materialiseForUserInRange($userId, $start, $end, deleteStale: false);

        $this->logger->debug(sprintf(
            '[TeamHub][PresenceMaterialisationService] rematerialiseForUser %s: %d slots written, window %s–%s',
            $userId, $count, $start, $end
        ));

        // Queue background calendar sync (B4). The job runs after the HTTP
        // response is returned, so the user isn't waiting for CalDAV writes.
        $this->jobList->add(PresenceCalendarSyncJob::class, ['userId' => $userId]);
    }

    // -------------------------------------------------------------------------
    // Core materialisation
    // -------------------------------------------------------------------------

    /**
     * Ensure slots exist for $userId from $start to $end (inclusive ISO dates).
     * When $deleteStale=false (cron mode) we skip dates that already have
     * any slot. When $deleteStale=true (save mode) the caller has already
     * wiped template slots, so we just insert.
     *
     * Returns count of new slots written.
     */
    private function materialiseForUserInRange(
        string $userId,
        string $start,
        string $end,
        bool $deleteStale,
    ): int {
        $template = $this->templateMapper->findByUser($userId);
        if (count($template) === 0) {
            return 0;
        }

        // Index template by day_of_week → [half_day → row].
        $tmplByDay = [];
        foreach ($template as $t) {
            if ($t->getPresenceTypeId() === null) {
                continue; // Empty cell — skip.
            }
            $tmplByDay[$t->getDayOfWeek()][$t->getHalfDay()] = $t;
        }
        if (count($tmplByDay) === 0) {
            return 0;
        }

        // Build the list of dates in the window.
        $dates   = $this->dateRange($start, $end);
        $written = 0;
        $now     = time();

        if (!$deleteStale) {
            // Cron mode: find which dates already have at least one slot and skip them.
            $existing = $this->slotMapper->findExistingDatesForUser($userId, $dates);
            $existing = array_flip($existing);
        } else {
            $existing = [];
        }

        foreach ($dates as $isoDate) {
            if (isset($existing[$isoDate])) {
                continue; // Already materialised — skip.
            }

            $dow = $this->isoDayOfWeek($isoDate);

            if (!isset($tmplByDay[$dow])) {
                continue; // No template entry for this day.
            }

            foreach ($tmplByDay[$dow] as $halfDay => $tmpl) {
                $slot = new PresenceSlot();
                $slot->setUserId($userId);
                $slot->setSlotDate($isoDate);
                $slot->setHalfDay($halfDay);
                $slot->setPresenceTypeId($tmpl->getPresenceTypeId());
                $slot->setLocationRoomId($tmpl->getLocationRoomId());
                $slot->setSource('template');
                $slot->setCreatedAt($now);
                $slot->setUpdatedAt($now);
                /** @var PresenceSlot $inserted */
                $inserted = $this->slotMapper->insert($slot);
                $written++;
            }
        }

        return $written;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Rolling window: today → end of (current year + 1).
     * Returns [start ISO, end ISO].
     *
     * @return array{string, string}
     */
    private function rollingWindow(): array {
        $today     = date('Y-m-d');
        $nextYear  = (int)date('Y') + 1;
        $endOfNext = sprintf('%04d-12-31', $nextYear);
        return [$today, $endOfNext];
    }

    /**
     * All ISO dates from $start to $end inclusive.
     *
     * @return string[]
     */
    private function dateRange(string $start, string $end): array {
        $dates = [];
        $cur   = strtotime($start);
        $last  = strtotime($end);
        while ($cur <= $last) {
            $dates[] = date('Y-m-d', $cur);
            $cur     = strtotime('+1 day', $cur);
        }
        return $dates;
    }

    /** ISO date → 0=Mon … 6=Sun */
    private function isoDayOfWeek(string $isoDate): int {
        [$y, $m, $d] = array_map('intval', explode('-', $isoDate));
        return ((int)date('N', mktime(12, 0, 0, $m, $d, $y))) - 1;
    }
}
