<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\OpenProject;

use OCA\TeamHub\Db\OpenProjectMeetingSyncMapper;
use OCA\TeamHub\Service\OpenProject\OpenProjectMeetingMirrorService;
use OCP\App\IAppManager;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * A fake CalDAV backend: the four calls the sync makes, over an array.
 * Duck-typed on purpose — the sync resolves the real one from the
 * container at call time, so the dav app is not a test dependency.
 */
final class FakeCalDavBackend {
    /** @var array<string, string> "calendarId/uri" → ical */
    public array $objects = [];
    /** @var list<string> */
    public array $calls = [];
    public ?string $failOn = null;

    public function getCalendarObject(int|string $calendarId, string $uri): ?array {
        $this->calls[] = 'get ' . $calendarId . '/' . $uri;
        return isset($this->objects[$calendarId . '/' . $uri]) ? ['uri' => $uri, 'calendardata' => $this->objects[$calendarId . '/' . $uri]] : null;
    }

    public function createCalendarObject(int|string $calendarId, string $uri, string $data): void {
        $this->calls[] = 'create ' . $calendarId . '/' . $uri;
        if ($this->failOn === 'create') {
            throw new \RuntimeException('write refused');
        }
        if (isset($this->objects[$calendarId . '/' . $uri])) {
            throw new \RuntimeException('duplicate uri');
        }
        $this->objects[$calendarId . '/' . $uri] = $data;
    }

    public function updateCalendarObject(int|string $calendarId, string $uri, string $data): void {
        $this->calls[] = 'update ' . $calendarId . '/' . $uri;
        $this->objects[$calendarId . '/' . $uri] = $data;
    }

    public function deleteCalendarObject(int|string $calendarId, string $uri): void {
        $this->calls[] = 'delete ' . $calendarId . '/' . $uri;
        unset($this->objects[$calendarId . '/' . $uri]);
    }
}

/**
 * OpenProject meetings copied one way into the team calendar (v4.9.10):
 * written once, rewritten on change, taken away on cancellation, adopted
 * by URI, never re-created after a member deleted the copy, and never
 * written into a calendar the team may only read.
 */
class OpenProjectMeetingMirrorServiceTest extends OpenProjectTestCase {

    private FakeCalDavBackend $backend;
    /** @var array<int, array<string, mixed>> ledger rows by meeting id */
    private array $rows = [];
    /** @var list<string> */
    private array $ledgerCalls = [];
    private ?array $calendar = ['calendarId' => 7, 'principalUri' => 'principals/users/owner'];
    private bool $anyCalendar = true;
    private bool $ledgerBroken = false;
    private int $nextLedgerId = 100;

    private function service(bool $calendarApp = true): OpenProjectMeetingMirrorService {
        $this->withHost();
        $this->backend = new FakeCalDavBackend();

        $ledger = $this->createMock(OpenProjectMeetingSyncMapper::class);
        $ledger->method('writableTeamCalendar')->willReturnCallback(fn (): ?array => $this->calendar);
        $ledger->method('anyTeamCalendar')->willReturnCallback(fn (): bool => $this->anyCalendar);
        $ledger->method('findByTeam')->willReturnCallback(function (): array {
            if ($this->ledgerBroken) {
                throw new \RuntimeException('no such table');
            }
            return $this->rows;
        });
        $ledger->method('claim')->willReturnCallback(function (string $teamId, string $connection, int $meetingId, string $by, int $startsAt): ?int {
            $this->ledgerCalls[] = 'claim ' . $meetingId;
            if (isset($this->rows[$meetingId])) {
                return null;
            }
            $id = $this->nextLedgerId++;
            $this->rows[$meetingId] = ['id' => $id, 'meetingId' => $meetingId, 'calendarId' => 0, 'objectUri' => '', 'fingerprint' => '', 'startsAt' => $startsAt, 'removedAt' => null];
            return $id;
        });
        $ledger->method('attach')->willReturnCallback(function (int $id, int $calendarId, string $uri, string $print, int $startsAt): void {
            $this->ledgerCalls[] = 'attach ' . $id;
            foreach ($this->rows as &$row) {
                if ($row['id'] === $id) {
                    $row = ['calendarId' => $calendarId, 'objectUri' => $uri, 'fingerprint' => $print, 'startsAt' => $startsAt, 'removedAt' => null] + $row;
                }
            }
            unset($row);
        });
        $ledger->method('markRemoved')->willReturnCallback(function (int $id): void {
            $this->ledgerCalls[] = 'removed ' . $id;
            foreach ($this->rows as &$row) {
                if ($row['id'] === $id) {
                    $row['removedAt'] = time();
                }
            }
            unset($row);
        });
        $ledger->method('release')->willReturnCallback(function (int $id): void {
            $this->ledgerCalls[] = 'release ' . $id;
            foreach ($this->rows as $mid => $row) {
                if ($row['id'] === $id) {
                    unset($this->rows[$mid]);
                }
            }
        });

        $apps = $this->createMock(IAppManager::class);
        $apps->method('isInstalled')->willReturnCallback(fn (string $app): bool => $app === 'calendar' ? $calendarApp : false);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn (string $s, array $p = []) => vsprintf($s, $p));

        return new OpenProjectMeetingMirrorService(
            $this->client($this->opService(null)),
            $ledger,
            $apps,
            $this->container($this->backend),
            $l,
            $this->createMock(LoggerInterface::class),
        );
    }

    /** @return array<string, mixed> */
    private function meeting(int $id, int $inDays = 3, array $overrides = []): array {
        $start = time() + $inDays * 86400;
        return array_replace([
            'id'       => $id,
            'title'    => 'Meeting ' . $id,
            'location' => 'Room A',
            'start'    => gmdate('c', $start),
            'end'      => gmdate('c', $start + 3600),
            'state'    => 'open',
            'author'   => 'Carol',
            'url'      => self::HOST . '/meetings/' . $id,
        ], $overrides);
    }

    private function link(): array {
        return ['host' => self::HOST, 'projectId' => 12];
    }

    private function uri(int $id): string {
        return 'openproject-meeting-' . substr(md5(self::HOST), 0, 12) . '-' . $id . '.ics';
    }

    // ── Writing ────────────────────────────────────────────────────────

    public function testAnOpenMeetingIsCopiedOnceAsAPlainEventNamingItsSource(): void {
        $s = $this->service();
        $m = $this->meeting(3);

        $r = $s->syncForTeam('alice', 'team-a', $this->link(), ['items' => [$m], 'cancelledIds' => []]);

        $this->assertSame(['state' => 'ok', 'created' => 1, 'updated' => 0, 'removed' => 0, 'skipped' => 0], $r);
        $ical = $this->backend->objects['7/' . $this->uri(3)] ?? '';
        $this->assertStringContainsString("SUMMARY:Meeting 3\r\n", $ical);
        $this->assertStringContainsString("LOCATION:Room A\r\n", $ical);
        $this->assertStringContainsString('URL:' . self::HOST . '/meetings/3', $ical);
        $this->assertStringContainsString('DESCRIPTION:Scheduled in OpenProject: ' . self::HOST . '/meetings/3', $ical);
        $this->assertStringContainsString("X-TEAMHUB-OPENPROJECT-MEETING:3\r\n", $ical);
        $this->assertStringContainsString("STATUS:CONFIRMED\r\n", $ical);
        $this->assertStringNotContainsString('ORGANIZER', $ical, 'a copy must not start scheduling');
        $this->assertStringNotContainsString('ATTENDEE', $ical);
        $this->assertSame(7, $this->rows[3]['calendarId']);
        $this->assertSame($this->uri(3), $this->rows[3]['objectUri']);

        // The same read again: nothing to do.
        $r = $s->syncForTeam('bob', 'team-a', $this->link(), ['items' => [$m], 'cancelledIds' => []]);
        $this->assertSame(['state' => 'ok', 'created' => 0, 'updated' => 0, 'removed' => 0, 'skipped' => 0], $r);
        $this->assertCount(1, array_filter($this->backend->calls, fn (string $c) => str_starts_with($c, 'create')));
    }

    public function testAChangedMeetingRewritesItsCopy(): void {
        $s = $this->service();
        $s->syncForTeam('alice', 'team-a', $this->link(), ['items' => [$this->meeting(3)], 'cancelledIds' => []]);

        $moved = $this->meeting(3, 5, ['title' => 'Meeting 3 (moved)']);
        $r = $s->syncForTeam('alice', 'team-a', $this->link(), ['items' => [$moved], 'cancelledIds' => []]);

        $this->assertSame(1, $r['updated']);
        $this->assertSame(0, $r['created']);
        $this->assertStringContainsString('SUMMARY:Meeting 3 (moved)', $this->backend->objects['7/' . $this->uri(3)]);
        $this->assertContains('update 7/' . $this->uri(3), $this->backend->calls);
    }

    public function testACopyAMemberDeletedIsNotPutBack(): void {
        $s = $this->service();
        $s->syncForTeam('alice', 'team-a', $this->link(), ['items' => [$this->meeting(3)], 'cancelledIds' => []]);
        unset($this->backend->objects['7/' . $this->uri(3)]); // a member removed it in the calendar

        $r = $s->syncForTeam('alice', 'team-a', $this->link(), ['items' => [$this->meeting(3, 3, ['title' => 'Renamed'])], 'cancelledIds' => []]);

        $this->assertSame(0, $r['created'] + $r['updated']);
        $this->assertArrayNotHasKey('7/' . $this->uri(3), $this->backend->objects);
    }

    public function testAnExistingObjectAtTheUriIsAdoptedNotDuplicated(): void {
        // A copy from before an unlink: the ledger is gone, the object is not.
        $s = $this->service();
        $this->backend->objects['7/' . $this->uri(3)] = 'BEGIN:VCALENDAR old END:VCALENDAR';

        $r = $s->syncForTeam('alice', 'team-a', $this->link(), ['items' => [$this->meeting(3)], 'cancelledIds' => []]);

        $this->assertSame(1, $r['created']);
        $this->assertContains('update 7/' . $this->uri(3), $this->backend->calls);
        $this->assertNotContains('create 7/' . $this->uri(3), $this->backend->calls);
        $this->assertStringContainsString('SUMMARY:Meeting 3', $this->backend->objects['7/' . $this->uri(3)]);
    }

    public function testAFailedWriteReleasesTheSlot(): void {
        $s = $this->service();
        $this->backend->failOn = 'create';

        $r = $s->syncForTeam('alice', 'team-a', $this->link(), ['items' => [$this->meeting(3)], 'cancelledIds' => []]);

        $this->assertSame(1, $r['skipped']);
        $this->assertArrayNotHasKey(3, $this->rows, 'the next read tries again');
        $this->assertContains('release 100', $this->ledgerCalls);
    }

    public function testTheBatchBoundsOneRead(): void {
        $s = $this->service();
        $items = [];
        for ($i = 1; $i <= OpenProjectMeetingMirrorService::BATCH + 2; $i++) {
            $items[] = $this->meeting($i, $i);
        }
        $r = $s->syncForTeam('alice', 'team-a', $this->link(), ['items' => $items, 'cancelledIds' => []]);
        $this->assertSame(OpenProjectMeetingMirrorService::BATCH, $r['created']);
        $this->assertSame(2, $r['skipped']);
    }

    // ── Removing ───────────────────────────────────────────────────────

    public function testACancelledMeetingLosesItsCopyAndComesBackIfReopened(): void {
        $s = $this->service();
        $s->syncForTeam('alice', 'team-a', $this->link(), ['items' => [$this->meeting(3)], 'cancelledIds' => []]);

        $r = $s->syncForTeam('alice', 'team-a', $this->link(), ['items' => [], 'cancelledIds' => [3]]);
        $this->assertSame(1, $r['removed']);
        $this->assertArrayNotHasKey('7/' . $this->uri(3), $this->backend->objects);
        $this->assertNotNull($this->rows[3]['removedAt']);

        // Cancelled twice: nothing more happens.
        $r = $s->syncForTeam('alice', 'team-a', $this->link(), ['items' => [], 'cancelledIds' => [3]]);
        $this->assertSame(0, $r['removed']);

        // Reopened in OpenProject: the copy returns on the same ledger row.
        $r = $s->syncForTeam('alice', 'team-a', $this->link(), ['items' => [$this->meeting(3)], 'cancelledIds' => []]);
        $this->assertSame(1, $r['created']);
        $this->assertArrayHasKey('7/' . $this->uri(3), $this->backend->objects);
        $this->assertNull($this->rows[3]['removedAt']);
        $this->assertNotContains('claim 3', array_slice($this->ledgerCalls, 1), 'no second claim for a known meeting');
    }

    public function testAMeetingDeletedInOpenProjectLosesItsCopyOnlyWhileInsideTheWindow(): void {
        $s = $this->service();
        $s->syncForTeam('alice', 'team-a', $this->link(), ['items' => [$this->meeting(3, 3), $this->meeting(4, 3)], 'cancelledIds' => []]);

        // 3 vanished from a read that covers its date: deleted there.
        $r = $s->syncForTeam('alice', 'team-a', $this->link(), ['items' => [$this->meeting(4, 3)], 'cancelledIds' => []]);
        $this->assertSame(1, $r['removed']);
        $this->assertArrayNotHasKey('7/' . $this->uri(3), $this->backend->objects);
        $this->assertArrayHasKey('7/' . $this->uri(4), $this->backend->objects);

        // A copy whose date is beyond the window is outside what a read can
        // see, so its absence says nothing.
        $this->rows[4]['startsAt'] = time() + 60 * 86400;
        $r = $s->syncForTeam('alice', 'team-a', $this->link(), ['items' => [], 'cancelledIds' => []]);
        $this->assertSame(0, $r['removed']);
        $this->assertArrayHasKey('7/' . $this->uri(4), $this->backend->objects);
    }

    // ── Gates ──────────────────────────────────────────────────────────

    public function testAReadOnlyOrMissingTeamCalendarIsNotWrittenTo(): void {
        $s = $this->service();
        $this->calendar = null;

        $r = $s->syncForTeam('alice', 'team-a', $this->link(), ['items' => [$this->meeting(3)], 'cancelledIds' => []]);
        $this->assertSame('read_only', $r['state']);
        $this->assertSame([], $this->backend->calls);

        $this->anyCalendar = false;
        $r = $s->syncForTeam('alice', 'team-a', $this->link(), ['items' => [$this->meeting(3)], 'cancelledIds' => []]);
        $this->assertSame('no_calendar', $r['state']);
    }

    public function testWithoutTheCalendarAppOrTheLedgerNothingIsWritten(): void {
        $s = $this->service(calendarApp: false);
        $r = $s->syncForTeam('alice', 'team-a', $this->link(), ['items' => [$this->meeting(3)], 'cancelledIds' => []]);
        $this->assertSame('unavailable', $r['state']);

        $s = $this->service();
        $this->ledgerBroken = true;
        $r = $s->syncForTeam('alice', 'team-a', $this->link(), ['items' => [$this->meeting(3)], 'cancelledIds' => []]);
        $this->assertSame('error', $r['state']);
        $this->assertSame([], $this->backend->calls);
    }

    public function testTheVeventIsUtcAndEscaped(): void {
        $s = $this->service();
        $start = gmmktime(9, 30, 0, 10, 1, 2026);
        $ical  = $s->vevent([
            'id' => 5, 'title' => 'Plan; review, part 2', 'location' => '', 'url' => '',
            'start' => gmdate('c', $start), 'end' => null,
        ], md5(self::HOST));

        $this->assertStringContainsString("DTSTART:20261001T093000Z\r\n", $ical);
        $this->assertStringContainsString("DTEND:20261001T103000Z\r\n", $ical, 'an hour when the end is unknown');
        $this->assertStringContainsString("SUMMARY:Plan\\; review\\, part 2\r\n", $ical);
        $this->assertStringContainsString("DESCRIPTION:Scheduled in OpenProject.\r\n", $ical);
        $this->assertStringNotContainsString('LOCATION', $ical);
        $this->assertStringNotContainsString('URL:', $ical);
        $this->assertTrue(OpenProjectMeetingMirrorService::isMirrorUri('openproject-meeting-abc-5.ics'));
        $this->assertFalse(OpenProjectMeetingMirrorService::isMirrorUri('7f1c-…-uuid.ics'));
    }
}
