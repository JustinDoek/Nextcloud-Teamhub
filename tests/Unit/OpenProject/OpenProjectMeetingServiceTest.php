<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\OpenProject;

use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Service\OpenProject\OpenProjectMeetingService;
use OCA\TeamHub\Service\TimezoneService;

/**
 * The linked project's upcoming meetings (v4.9.7): the filter grammar as
 * the viewer's dates, what is dropped, the order, the deep link, and the
 * per-viewer cache.
 */
class OpenProjectMeetingServiceTest extends OpenProjectTestCase {

    /** @var list<array{0: string, 1: array}> */
    private array $requests = [];

    private function service(?callable $handler = null, ?string $timezone = null): OpenProjectMeetingService {
        $this->withHost();
        $this->withConnectedUser('alice');
        $this->withConnectedUser('bob', 'Bob');
        if ($timezone !== null) {
            $this->userValues['alice']['core']['timezone'] = $timezone;
        }
        $this->requests = [];
        $op = $this->opService(function (string $uid, string $endpoint, array $params) use ($handler) {
            $this->requests[] = [$endpoint, $params];
            return $handler ? $handler($endpoint, $params) : self::collectionResponse([], 0, 'Collection');
        });
        $client = $this->client($op);
        return new OpenProjectMeetingService($client, $this->cache(), new TimezoneService($this->config()));
    }

    private function meeting(int $id, string $start, array $overrides = []): array {
        return array_replace_recursive([
            '_type'     => 'Meeting',
            'id'        => $id,
            'title'     => 'Meeting ' . $id,
            'location'  => 'Room A',
            'startTime' => $start,
            'endTime'   => null,
            'duration'  => 'PT1H',
            'state'     => 'open',
            'template'  => false,
            '_links'    => [
                'author'  => ['href' => '/api/v3/users/9', 'title' => 'Carol'],
                'project' => ['href' => '/api/v3/projects/12', 'title' => 'Demo'],
            ],
        ], $overrides);
    }

    public function testTheReadIsFilteredByProjectAndTheViewersThirtyDayWindow(): void {
        // Pacific/Auckland is a day ahead of UTC in the evening; the window
        // starts on the viewer's today, not the server's.
        $s = $this->service(timezone: 'Pacific/Auckland');
        $s->upcoming('alice', 'team-a', 12, self::HOST);

        [$endpoint, $params] = $this->requests[0];
        $this->assertSame('meetings', $endpoint);
        $filters = json_decode($params['filters'], true);
        $this->assertSame(['project' => ['operator' => '=', 'values' => ['12']]], $filters[0]);
        $this->assertSame('<>d', $filters[1]['datesInterval']['operator']);
        $tz = new \DateTimeZone('Pacific/Auckland');
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $this->assertSame($today, $filters[1]['datesInterval']['values'][0]);
        // Thirty days of seconds rendered in the zone — not thirty calendar
        // days: across a DST change near local midnight the two differ by a day.
        $horizon = (new \DateTimeImmutable('@' . (time() + 30 * 86400)))->setTimezone($tz)->format('Y-m-d');
        $this->assertSame($horizon, $filters[1]['datesInterval']['values'][1]);
        $this->assertSame(OpenProjectMeetingService::DEFAULT_LIMIT, $params['pageSize']);
    }

    public function testRowsAreUpcomingSortedWithAnEndFromTheDuration(): void {
        $soon  = gmdate('Y-m-d\TH:i:s\Z', time() + 2 * 86400);
        $later = gmdate('Y-m-d\TH:i:s\Z', time() + 5 * 86400);
        $s = $this->service(fn () => self::collectionResponse([
            $this->meeting(2, $later, ['endTime' => gmdate('Y-m-d\TH:i:s\Z', time() + 5 * 86400 + 1800), 'duration' => 'PT3H']),
            $this->meeting(1, $soon, ['title' => 'Kick-off <b>now</b>', 'location' => '']),
            $this->meeting(3, gmdate('Y-m-d\TH:i:s\Z', time() - 3 * 86400)),                    // in the past
            $this->meeting(4, $soon, ['state' => 'cancelled']),                                 // cancelled
            $this->meeting(5, $soon, ['template' => true]),                                     // a template
            $this->meeting(6, $soon, ['_links' => ['project' => ['href' => '/api/v3/projects/99', 'title' => 'Other']]]),
            $this->meeting(7, ''),                                                              // no start
            ['_type' => 'Junk'],
        ], 8, 'Collection'));

        $r = $s->upcoming('alice', 'team-a', 12, self::HOST);

        $this->assertSame([1, 2], array_column($r['items'], 'id'), 'soonest first; past, cancelled, template, foreign and undated dropped');
        // v4.9.10 — the cancelled one is named, so the calendar sync can
        // take its copy away; only the one inside the window, in the project.
        $this->assertSame([4], $r['cancelledIds']);
        $first = $r['items'][0];
        $this->assertSame('Kick-off now', $first['title']);
        $this->assertNull($first['location']);
        $this->assertSame(strtotime($soon) + 3600, strtotime($first['end']), 'end = start + duration when OpenProject sends none');
        $this->assertSame(self::HOST . '/meetings/1', $first['url']);
        $this->assertSame('Carol', $first['author']);
        $this->assertSame(strtotime($later) + 1800, strtotime($r['items'][1]['end']), 'an explicit end wins over the duration');
        $this->assertFalse($r['fromCache']);
    }

    public function testCachedPerViewerAndRefreshedUnderTheCooldown(): void {
        $s = $this->service(fn () => self::collectionResponse([$this->meeting(1, gmdate('Y-m-d\TH:i:s\Z', time() + 86400))], 1, 'Collection'));

        $s->upcoming('alice', 'team-a', 12, self::HOST);
        $this->assertTrue($s->upcoming('alice', 'team-a', 12, self::HOST)['fromCache']);
        $this->assertCount(1, $this->requests);

        $s->upcoming('bob', 'team-a', 12, self::HOST);
        $this->assertCount(2, $this->requests, 'never Alice\'s copy');

        $this->assertFalse($s->upcoming('alice', 'team-a', 12, self::HOST, refresh: true)['fromCache']);
        $this->assertTrue($s->upcoming('alice', 'team-a', 12, self::HOST, refresh: true)['fromCache'], 'a second refresh inside the cooldown is served from cache');
    }

    public function testANonCollectionIsUnsupportedAndABadProjectIsRefused(): void {
        $s = $this->service(fn () => ['_type' => 'Error']);
        try {
            $s->upcoming('alice', 'team-a', 12, self::HOST);
            $this->fail('expected an exception');
        } catch (OpenProjectException $e) {
            $this->assertSame(OpenProjectException::UNSUPPORTED_RESPONSE, $e->getErrorCode());
        }
        $this->expectException(\OCA\TeamHub\Exception\ValidationException::class);
        $s->upcoming('alice', 'team-a', 0, self::HOST);
    }
}
