<?php

declare(strict_types=1);

namespace OCA\TeamHub\Service\Suggestion;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\ActivityService;
use Psr\Log\LoggerInterface;

/**
 * v1 busy-interval source: the team calendar.
 *
 * Every team member shares the team calendar, so an event on it occupies all
 * members for its duration. v1 treats team-calendar events as the conflict
 * source for everyone — it does not yet distinguish attendees from
 * non-attendees on a given event (the team calendar has no per-member
 * attendance model in TeamHub today). Personal-calendar free/busy, which is
 * per-user, is the next session's provider.
 *
 * Reuses ActivityService::getTeamCalendarEventsForWeek — despite the name it
 * is a plain [startTs, endTs) range query returning events with ISO 'start'
 * and 'end' fields.
 */
class TeamCalendarBusyProvider implements BusyProviderInterface {

    public function __construct(
        private ActivityService $activityService,
        private LoggerInterface $logger,
    ) {
    }

    public function getBusyIntervals(string $teamId, array $userIds, int $startTs, int $endTs): array {
        // The team calendar is shared, so one set of intervals applies to all
        // requested users. Compute once, then fan out to each userId.
        $intervals = [];
        try {
            $events = $this->activityService->getTeamCalendarEventsForWeek($teamId, $startTs, $endTs);
            foreach ($events as $ev) {
                // All-day events block the whole day(s); a meeting can still be
                // scheduled around them in principle, but for v1 we treat an
                // all-day event as a busy block too (conservative — better to
                // under-suggest a conflicting slot than over-suggest).
                $evStart = isset($ev['start']) ? strtotime((string)$ev['start']) : false;
                $evEnd   = isset($ev['end']) && $ev['end'] !== null ? strtotime((string)$ev['end']) : false;
                if ($evStart === false) {
                    continue;
                }
                if ($evEnd === false || $evEnd <= $evStart) {
                    // Zero-length or missing end — treat as a 30-minute block so
                    // it still registers as a conflict around its start.
                    $evEnd = $evStart + 1800;
                }
                $intervals[] = ['start' => $evStart, 'end' => $evEnd];
            }
        } catch (\Throwable $e) {
            // Resilience: a calendar read failure must not sink suggestions.
            // Return no intervals (everyone free w.r.t. this provider) rather
            // than throwing.
            $this->logger->warning('[TeamHub][TeamCalendarBusyProvider] event read failed', [
                'teamId' => $teamId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            return [];
        }

        if ($intervals === []) {
            return [];
        }

        $byUser = [];
        foreach ($userIds as $uid) {
            $byUser[$uid] = $intervals;
        }
        return $byUser;
    }
}
