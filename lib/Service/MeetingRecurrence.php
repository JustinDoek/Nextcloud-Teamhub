<?php

declare(strict_types=1);

namespace OCA\TeamHub\Service;

use Sabre\VObject\Recur\RRuleIterator;

/**
 * Recurring meetings, as Nextcloud Calendar offers them (v4.20.8).
 *
 * The meeting wizard and Add event offer the same choices as Calendar's own
 * Repeat section, so a series made in TeamHub opens in Calendar as an
 * ordinary, editable recurring event:
 *
 *   - Daily, weekly, monthly or yearly, every N of them;
 *   - weekly on chosen weekdays;
 *   - monthly on the same date, or on the same weekday position ("the second
 *     Tuesday"), written the way Calendar writes it, `BYDAY` + `BYSETPOS`;
 *   - ending never, on a date, or after a number of times.
 *
 * Pure functions, no state: `MeetingSeriesService` adds the licence gate, the
 * organiser's time zone and the room check. DESIGN §2.263.
 */
final class MeetingRecurrence {

    public const FREQUENCIES = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];
    public const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
    public const MAX_INTERVAL = 99;
    public const MAX_COUNT = 500;

    /**
     * The client's recurrence, validated and normalised, or null for a
     * meeting that does not repeat.
     *
     * Accepts the JSON string a form post carries, or the decoded array.
     *
     * @return array{freq: string, interval: int, byDay: list<string>, monthlyMode: string, endType: string, until: string, count: int}|null
     * @throws \InvalidArgumentException on a malformed recurrence
     */
    public static function parse(mixed $raw): ?array {
        if ($raw === null || $raw === '' || $raw === 'none') {
            return null;
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
            if (!is_array($raw)) {
                throw new \InvalidArgumentException('The repeat settings could not be read');
            }
        }
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('The repeat settings could not be read');
        }

        $freq = strtoupper(trim((string)($raw['freq'] ?? '')));
        if ($freq === '' || $freq === 'NONE') {
            return null;
        }
        if (!in_array($freq, self::FREQUENCIES, true)) {
            throw new \InvalidArgumentException('Unknown repeat frequency');
        }

        $interval = (int)($raw['interval'] ?? 1);
        if ($interval < 1 || $interval > self::MAX_INTERVAL) {
            throw new \InvalidArgumentException('The repeat interval must be between 1 and ' . self::MAX_INTERVAL);
        }

        $byDay = [];
        if ($freq === 'WEEKLY') {
            $wanted = array_map(static fn ($d): string => strtoupper(trim((string)$d)), (array)($raw['byDay'] ?? []));
            // Week order, not click order, and each day once.
            $byDay = array_values(array_filter(self::WEEKDAYS, static fn (string $d): bool => in_array($d, $wanted, true)));
        }

        $monthlyMode = ($raw['monthlyMode'] ?? 'date') === 'weekday' ? 'weekday' : 'date';

        $end = is_array($raw['end'] ?? null) ? $raw['end'] : [];
        $endType = (string)($end['type'] ?? 'never');
        if (!in_array($endType, ['never', 'until', 'count'], true)) {
            throw new \InvalidArgumentException('Unknown repeat end');
        }
        $until = '';
        $count = 0;
        if ($endType === 'until') {
            $until = trim((string)($end['until'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || \DateTimeImmutable::createFromFormat('!Y-m-d', $until) === false) {
                throw new \InvalidArgumentException('The repeat end date is not a date');
            }
        } elseif ($endType === 'count') {
            $count = (int)($end['count'] ?? 0);
            if ($count < 1 || $count > self::MAX_COUNT) {
                throw new \InvalidArgumentException('The number of repeats must be between 1 and ' . self::MAX_COUNT);
            }
        }

        return [
            'freq'        => $freq,
            'interval'    => $interval,
            'byDay'       => $byDay,
            'monthlyMode' => $monthlyMode,
            'endType'     => $endType,
            'until'       => $until,
            'count'       => $count,
        ];
    }

    /**
     * The RRULE value for a series that starts at `$localStart`, read in the
     * organiser's zone (the weekday and the day of the month depend on it).
     *
     * @param array{freq: string, interval: int, byDay: list<string>, monthlyMode: string, endType: string, until: string, count: int} $rec
     * @throws \InvalidArgumentException when the end date lies before the first meeting
     */
    public static function toRrule(array $rec, \DateTimeImmutable $localStart): string {
        $parts = ['FREQ=' . $rec['freq']];
        if ($rec['interval'] > 1) {
            $parts[] = 'INTERVAL=' . $rec['interval'];
        }

        $weekday = self::WEEKDAYS[(int)$localStart->format('N') - 1];
        $day = (int)$localStart->format('j');

        switch ($rec['freq']) {
            case 'WEEKLY':
                $days = $rec['byDay'] !== [] ? $rec['byDay'] : [$weekday];
                $parts[] = 'BYDAY=' . implode(',', $days);
                break;
            case 'MONTHLY':
                if ($rec['monthlyMode'] === 'weekday') {
                    // "The fifth Monday" exists in few months; Calendar offers
                    // "last" for it, and so do we.
                    $position = intdiv($day - 1, 7) + 1;
                    $parts[] = 'BYDAY=' . $weekday;
                    $parts[] = 'BYSETPOS=' . ($position >= 5 ? -1 : $position);
                } else {
                    $parts[] = 'BYMONTHDAY=' . $day;
                }
                break;
            case 'YEARLY':
                $parts[] = 'BYMONTH=' . (int)$localStart->format('n');
                $parts[] = 'BYMONTHDAY=' . $day;
                break;
        }

        if ($rec['endType'] === 'count') {
            $parts[] = 'COUNT=' . $rec['count'];
        } elseif ($rec['endType'] === 'until') {
            // The whole end day counts. With a TZID'd DTSTART, UNTIL must be UTC
            // (RFC 5545 §3.3.10).
            $endOfDay = new \DateTimeImmutable($rec['until'] . ' 23:59:59', $localStart->getTimezone());
            if ($endOfDay < $localStart) {
                throw new \InvalidArgumentException('The repeat end date is before the first meeting');
            }
            $parts[] = 'UNTIL=' . $endOfDay->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        }

        return implode(';', $parts);
    }

    /**
     * The occurrences of a series as [start, end] Unix timestamps, the first
     * meeting included, up to `$horizonEnd` and at most `$max` of them.
     *
     * Iterated in the organiser's zone, so a weekly 10:00 stays 10:00 across a
     * daylight saving change.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function occurrences(string $rrule, \DateTimeImmutable $localStart, int $durationSeconds, int $horizonEnd, int $max): array {
        $out = [];
        $iterator = new RRuleIterator($rrule, $localStart);
        while ($iterator->valid() && count($out) < $max) {
            $current = $iterator->current();
            if ($current === null) {
                break;
            }
            $start = $current->getTimestamp();
            if ($start > $horizonEnd) {
                break;
            }
            $out[] = [$start, $start + $durationSeconds];
            $iterator->next();
        }
        return $out;
    }
}
