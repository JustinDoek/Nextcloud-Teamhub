<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\OpenProjectMeetingSyncMapper;
use OCA\TeamHub\Util\IcalText;
use OCP\App\IAppManager;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * OpenProject meetings, copied one way into the team calendar (v4.9.10,
 * Phase 3 — Justin's review, 2026-09-14: "The upcoming meeting shows. But
 * it's not added to the team calendar. Can we add it there. A one way
 * sync is fine.").
 *
 * ## What "one way" means here
 *
 * OpenProject is the source of truth. Whatever `OpenProjectMeetingService`
 * reads for the widget — the linked project's open meetings in the next
 * thirty days, as the viewer — is written into the team calendar as a
 * plain VEVENT: title, times, location, a link to the meeting, and
 * "Scheduled in OpenProject" in the description. A meeting that changes in
 * OpenProject is rewritten (the fingerprint says when); one that is
 * cancelled or deleted there is removed from the calendar. Nothing goes
 * the other way: an edit made to the copy in the calendar is overwritten
 * by the next change in OpenProject, and a copy a member deletes from the
 * calendar stays deleted — the ledger still names it, so the sync does
 * not put it back (that would be a fight nobody wins).
 *
 * ## Who writes, and when
 *
 * The connected member whose team-home load found the meeting; there is
 * no background job (TeamHub holds no OpenProject token — OPENPROJECT.md
 * §3.2). The write goes through the CalDAV backend directly, as
 * `ActivityService::createCalendarEvent()` has since v3.x, so it happens
 * regardless of the member's own DAV rights — which is why it is gated on
 * the **team's** share instead: only a calendar shared with the team
 * read-write is written to; a read-only team calendar is left as it is
 * and the widget keeps showing the meeting beside it. Failures are the
 * widget's silent business: logged at debug, reported in the response's
 * `sync` block, never a failed read.
 *
 * No ORGANIZER, no ATTENDEE: the copy must not start iTIP scheduling —
 * nobody gets an invitation because a colleague opened the team home.
 */
class OpenProjectMeetingMirrorService {

    /** How many copies one read may write; the rest wait for the next read. */
    public const BATCH = 10;

    /** The calendar object's URI prefix — also how a copy is recognised in the widget. */
    private const URI_PREFIX = 'openproject-meeting-';

    public function __construct(
        private OpenProjectClient           $client,
        private OpenProjectMeetingSyncMapper $ledger,
        private IAppManager                 $appManager,
        private ContainerInterface          $container,
        private IL10N                       $l,
        private LoggerInterface             $logger,
    ) {
    }

    /**
     * Bring the team calendar in line with one read of the project's
     * meetings.
     *
     * @param array<string, mixed> $link the team's link (`host`, `projectId`, …)
     * @param array{items: list<array<string, mixed>>, cancelledIds?: list<int>} $read what `OpenProjectMeetingService::upcoming()` returned
     * @return array{state: string, created: int, updated: int, removed: int, skipped: int}
     *   `state`: ok | no_calendar | read_only | unavailable | error
     */
    public function syncForTeam(string $userId, string $teamId, array $link, array $read): array {
        $out = ['state' => 'ok', 'created' => 0, 'updated' => 0, 'removed' => 0, 'skipped' => 0];

        if (!$this->appManager->isInstalled('calendar')) {
            $out['state'] = 'unavailable';
            return $out;
        }

        try {
            $calendar = $this->ledger->writableTeamCalendar($teamId);
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][OpenProjectMeetings] team calendar lookup failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            $out['state'] = 'error';
            return $out;
        }
        if ($calendar === null) {
            // Distinguish "no calendar" from "not ours to write": the widget
            // says nothing either way, the admin diagnostics can.
            $out['state'] = $this->hasAnyTeamCalendar($teamId) ? 'read_only' : 'no_calendar';
            return $out;
        }

        $connection = md5((string)($link['host'] ?? ''));
        try {
            $rows = $this->ledger->findByTeam($teamId, $connection);
        } catch (\Throwable $e) {
            // A missing table (upgrade not yet applied) costs the copy, not
            // the widget.
            $this->logger->debug('[TeamHub][OpenProjectMeetings] sync ledger unreadable', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            $out['state'] = 'error';
            return $out;
        }

        $backend = $this->calendarBackend();
        if ($backend === null) {
            $out['state'] = 'unavailable';
            return $out;
        }

        $open      = [];
        foreach ($read['items'] ?? [] as $m) {
            $id = (int)($m['id'] ?? 0);
            if ($id > 0) {
                $open[$id] = $m;
            }
        }
        $cancelled = array_flip(array_map('intval', $read['cancelledIds'] ?? []));

        // ── Create and update ────────────────────────────────────────────
        $written = 0;
        foreach ($open as $meetingId => $m) {
            if ($written >= self::BATCH) {
                $out['skipped']++;
                continue;
            }
            $startsAt = (int)strtotime((string)($m['start'] ?? ''));
            $ical     = $this->vevent($m, $connection);
            $print    = $this->fingerprint($m);
            $uri      = self::URI_PREFIX . substr($connection, 0, 12) . '-' . $meetingId . '.ics';
            $row      = $rows[$meetingId] ?? null;

            if ($row !== null && $row['removedAt'] === null && $row['fingerprint'] === $print) {
                continue; // up to date
            }

            if ($row !== null && $row['removedAt'] === null && $row['calendarId'] > 0) {
                // Known and changed. Gone from the calendar means a member
                // took it out: leave it out.
                try {
                    if ($backend->getCalendarObject($row['calendarId'], $row['objectUri']) === null) {
                        continue;
                    }
                    $backend->updateCalendarObject($row['calendarId'], $row['objectUri'], $ical);
                    $this->ledger->attach($row['id'], $row['calendarId'], $row['objectUri'], $print, $startsAt);
                    $out['updated']++;
                    $written++;
                } catch (\Throwable $e) {
                    $this->logger->debug('[TeamHub][OpenProjectMeetings] copy could not be updated', [
                        'teamId' => $teamId, 'meetingId' => $meetingId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                    $out['skipped']++;
                }
                continue;
            }

            // New to the ledger, or removed by the sync and open again.
            $ledgerId = $row['id'] ?? null;
            $claimed  = false;
            try {
                if ($ledgerId === null) {
                    $ledgerId = $this->ledger->claim($teamId, $connection, $meetingId, $userId, $startsAt);
                    if ($ledgerId === null) {
                        continue; // somebody else got there first
                    }
                    $claimed = true;
                }
                // An object already at the URI — a copy from before an
                // unlink, say — is adopted rather than duplicated.
                if ($backend->getCalendarObject($calendar['calendarId'], $uri) !== null) {
                    $backend->updateCalendarObject($calendar['calendarId'], $uri, $ical);
                } else {
                    $backend->createCalendarObject($calendar['calendarId'], $uri, $ical);
                }
                $this->ledger->attach($ledgerId, $calendar['calendarId'], $uri, $print, $startsAt);
                $out['created']++;
                $written++;
            } catch (\Throwable $e) {
                if ($claimed && $ledgerId !== null) {
                    try {
                        $this->ledger->release($ledgerId);
                    } catch (\Throwable) {
                        // The slot stays claimed with calendar_id 0; the next
                        // read finds a row without an object and treats it
                        // as "removed by a member" — harmless, and visible.
                    }
                }
                $this->logger->debug('[TeamHub][OpenProjectMeetings] copy could not be written', [
                    'teamId' => $teamId, 'meetingId' => $meetingId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                $out['skipped']++;
            }
        }

        // ── Remove ───────────────────────────────────────────────────────
        // Cancelled in OpenProject, or gone from a read that should have
        // held it (deleted there, or moved out of the window): the copy
        // goes. A row already marked removed is left alone.
        $windowFrom = time() - 3600;
        $windowTo   = time() + OpenProjectMeetingService::HORIZON_DAYS * 86400;
        foreach ($rows as $meetingId => $row) {
            if ($row['removedAt'] !== null || $row['calendarId'] <= 0 || isset($open[$meetingId])) {
                continue;
            }
            $inWindow = $row['startsAt'] >= $windowFrom && $row['startsAt'] <= $windowTo;
            if (!isset($cancelled[$meetingId]) && !$inWindow) {
                continue; // outside what this read could see
            }
            try {
                $backend->deleteCalendarObject($row['calendarId'], $row['objectUri']);
                $this->ledger->markRemoved($row['id']);
                $out['removed']++;
            } catch (\Throwable $e) {
                $this->logger->debug('[TeamHub][OpenProjectMeetings] copy could not be removed', [
                    'teamId' => $teamId, 'meetingId' => $meetingId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                $out['skipped']++;
            }
        }

        return $out;
    }

    /**
     * Forget a team's copies (unlink, team delete). The calendar objects
     * stay — they are the team's record of meetings that happened — and a
     * later link to the same project adopts them by URI.
     */
    public function forgetTeam(string $teamId): void {
        try {
            $this->ledger->deleteByTeam($teamId);
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][OpenProjectMeetings] ledger cascade failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    /** Is a calendar object one of ours — by the URI convention. */
    public static function isMirrorUri(string $objectUri): bool {
        return str_starts_with($objectUri, self::URI_PREFIX);
    }

    // ─────────────────────────────────────────────────────────────────────
    // The VEVENT
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The calendar object for one meeting. UTC `Z` times (the form
     * `ActivityService` falls back to), no scheduling properties, the
     * meeting's OpenProject id and connection as X- properties so the
     * widget and a later version can tell the copy from a hand-made event.
     *
     * @param array<string, mixed> $m a row from OpenProjectMeetingService
     */
    public function vevent(array $m, string $connection): string {
        $id    = (int)$m['id'];
        $start = (int)strtotime((string)($m['start'] ?? ''));
        $end   = isset($m['end']) && $m['end'] !== null ? (int)strtotime((string)$m['end']) : 0;
        if ($end <= $start) {
            $end = $start + 3600;
        }
        $stamp = gmdate('Ymd\THis\Z');
        $uid   = self::URI_PREFIX . substr($connection, 0, 12) . '-' . $id;
        $url   = (string)($m['url'] ?? '');
        $title = trim((string)($m['title'] ?? '')) !== '' ? (string)$m['title'] : $this->l->t('OpenProject meeting');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//TeamHub//TeamHub OpenProject//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $stamp,
            'LAST-MODIFIED:' . $stamp,
            'DTSTART:' . gmdate('Ymd\THis\Z', $start),
            'DTEND:' . gmdate('Ymd\THis\Z', $end),
            'SUMMARY:' . self::escape($title),
            'STATUS:CONFIRMED',
            'TRANSP:OPAQUE',
            'CATEGORIES:OpenProject',
            'X-TEAMHUB-OPENPROJECT-MEETING:' . $id,
            'X-TEAMHUB-OPENPROJECT-CONNECTION:' . substr($connection, 0, 12),
        ];
        $location = trim((string)($m['location'] ?? ''));
        if ($location !== '') {
            $lines[] = 'LOCATION:' . self::escape($location);
        }
        if ($url !== '') {
            $lines[] = 'URL:' . $url;
            // TRANSLATORS: description of a calendar event copied from an OpenProject meeting; %s is the meeting's link
            $lines[] = 'DESCRIPTION:' . self::escape($this->l->t('Scheduled in OpenProject: %s', [$url]));
        } else {
            // TRANSLATORS: description of a calendar event copied from an OpenProject meeting
            $lines[] = 'DESCRIPTION:' . self::escape($this->l->t('Scheduled in OpenProject.'));
        }
        $lines[] = 'BEGIN:VALARM';
        $lines[] = 'ACTION:DISPLAY';
        $lines[] = 'TRIGGER:-PT15M';
        $lines[] = 'DESCRIPTION:' . self::escape($title);
        $lines[] = 'END:VALARM';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", $lines) . "\r\n";
    }

    /** What the copy is made of; a change here is a change in the calendar. */
    public function fingerprint(array $m): string {
        return sha1(json_encode([
            (string)($m['title'] ?? ''),
            (string)($m['start'] ?? ''),
            (string)($m['end'] ?? ''),
            (string)($m['location'] ?? ''),
            (string)($m['url'] ?? ''),
        ], JSON_THROW_ON_ERROR));
    }

    /** RFC 5545 §3.3.11 text escaping — the shared helper since v4.9.11. */
    private static function escape(string $text): string {
        return IcalText::escape($text);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Nextcloud's calendar
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The CalDAV backend, resolved at call time like ActivityService does:
     * the class belongs to the dav app and is not a constructor dependency
     * this service should fail to build without.
     */
    private function calendarBackend(): ?object {
        try {
            return $this->container->get('OCA\\DAV\\CalDAV\\CalDavBackend');
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][OpenProjectMeetings] CalDAV backend unavailable', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
    }

    private function hasAnyTeamCalendar(string $teamId): bool {
        try {
            return $this->ledger->anyTeamCalendar($teamId);
        } catch (\Throwable) {
            return false;
        }
    }
}
