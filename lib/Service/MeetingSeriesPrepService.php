<?php

declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\MeetingSeriesMapper;
use OCP\Calendar\IManager as ICalendarManager;
use Psr\Log\LoggerInterface;

/**
 * Notes and a chat reminder before each date of a recurring meeting
 * (v4.20.10, DESIGN §2.264).
 *
 * Justin: *"When i insert a serie it should create meeting notes for every
 * event and notify users a few days in front in chat."* He chose: each date's
 * notes are made a few days before it rather than all at once (a series may
 * never end), the lead time is chosen per series (1 to 7 days, default 2), and
 * the reminder goes to the team's Talk chat with the invited people mentioned.
 *
 * The series is read **from the calendar** every time, through the public
 * calendar search as the organiser: a date moved, cancelled or deleted in
 * Calendar is what counts, not what the wizard once wrote. The table only
 * holds what the calendar cannot: who organised it, the lead time, whom to
 * mention and how far it has got (`prepared_until`, so a date is never
 * prepared twice).
 *
 * A series whose event can no longer be found anywhere in the next 400 days
 * (deleted, ended, its team gone) is forgotten, checked once a day.
 */
class MeetingSeriesPrepService {

    /** How far ahead a forgotten series is looked for before it is dropped. */
    private const FORGET_HORIZON = 400 * 86400;

    public function __construct(
        private MeetingSeriesMapper $series,
        private ICalendarManager $calendarManager,
        private MeetingService $meetings,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{scanned: int, prepared: int, forgotten: int, errors: int}
     */
    public function sweep(?int $now = null): array {
        $now ??= time();
        $stats = ['scanned' => 0, 'prepared' => 0, 'forgotten' => 0, 'errors' => 0];

        foreach ($this->series->findAll() as $row) {
            $stats['scanned']++;
            try {
                $lead = max(1, min(7, $row['lead_days'])) * 86400;
                $occurrences = $this->occurrences($row, $now, $now + $lead);
                if ($occurrences === null) {
                    $stats['errors']++;
                    continue;
                }
                $due = array_filter($occurrences, static fn (array $o): bool => $o['start'] > $row['prepared_until']);
                usort($due, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

                foreach ($due as $occurrence) {
                    if ($occurrence['cancelled']) {
                        // Nothing to prepare, but do not look at it again.
                        $this->series->setPreparedUntil($row['id'], $occurrence['start']);
                        continue;
                    }
                    $ok = $this->meetings->prepareSeriesOccurrence(
                        $row['team_id'],
                        $row['organiser'],
                        $occurrence['start'],
                        $occurrence['end'],
                        $occurrence['title'],
                        $occurrence['location'],
                        $row['attendees'],
                    );
                    if (!$ok) {
                        // Leave prepared_until where it is: next hour tries again.
                        $stats['errors']++;
                        break;
                    }
                    $this->series->setPreparedUntil($row['id'], $occurrence['start']);
                    $row['prepared_until'] = $occurrence['start'];
                    $stats['prepared']++;
                }

                // Once a day, in the first hour after midnight UTC: a series
                // with no date left in the next 400 days is forgotten.
                if ($occurrences === [] && $now % 86400 < 3600) {
                    $ahead = $this->occurrences($row, $now, $now + self::FORGET_HORIZON);
                    if ($ahead === []) {
                        $this->series->delete($row['id']);
                        $stats['forgotten']++;
                    }
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->logger->warning('[TeamHub][MeetingSeriesPrepService] series could not be prepared', [
                    'seriesId' => $row['id'], 'exception' => get_class($e), 'app' => Application::APP_ID,
                ]);
            }
        }
        return $stats;
    }

    /**
     * The series' occurrences starting in [$from, $to], read as the organiser
     * (whose calendars include the team calendar, owned or shared), or null
     * when the calendar could not be searched.
     *
     * @param array{event_uid: string, organiser: string} $row
     * @return list<array{start: int, end: int, title: string, location: string, cancelled: bool}>|null
     */
    private function occurrences(array $row, int $from, int $to): ?array {
        try {
            $query = $this->calendarManager->newQuery('principals/users/' . $row['organiser']);
            $query->addType('VEVENT');
            $query->setTimerangeStart(new \DateTimeImmutable('@' . $from));
            $query->setTimerangeEnd(new \DateTimeImmutable('@' . $to));
            $results = $this->calendarManager->searchForPrincipal($query);
        } catch (\Throwable $e) {
            $this->logger->info('[TeamHub][MeetingSeriesPrepService] organiser calendar could not be searched', [
                'exception' => get_class($e), 'app' => Application::APP_ID,
            ]);
            return null;
        }

        $out = [];
        $seen = [];
        foreach ($results as $result) {
            foreach (($result['objects'] ?? []) as $object) {
                if ((string)($object['UID'][0] ?? '') !== $row['event_uid']) {
                    continue;
                }
                $start = $object['DTSTART'][0] ?? null;
                if (!$start instanceof \DateTimeInterface) {
                    continue;
                }
                $startTs = $start->getTimestamp();
                if ($startTs < $from || $startTs > $to || isset($seen[$startTs])) {
                    continue;
                }
                $seen[$startTs] = true;
                $end = $object['DTEND'][0] ?? null;
                $out[] = [
                    'start'     => $startTs,
                    'end'       => $end instanceof \DateTimeInterface ? $end->getTimestamp() : $startTs + 3600,
                    'title'     => trim((string)($object['SUMMARY'][0] ?? '')),
                    'location'  => trim((string)($object['LOCATION'][0] ?? '')),
                    'cancelled' => strtoupper(trim((string)($object['STATUS'][0] ?? ''))) === 'CANCELLED',
                ];
            }
        }
        return $out;
    }
}
