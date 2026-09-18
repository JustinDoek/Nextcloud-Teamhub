<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject;

use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\TimezoneService;

/**
 * The linked project's upcoming OpenProject meetings, for the team home's
 * Upcoming events widget (v4.9.7, Phase 3 — Justin's review, 2026-09-14:
 * "in upcoming events I don't see the scheduled meeting").
 *
 * Read live as the viewer from OpenProject 17's Meetings API (`GET
 * /api/v3/meetings`, `project = [id]`, `datesInterval <>d [today,
 * today+30]` — `Queries::Meetings::Filters::ProjectFilter` and
 * `DatesIntervalFilter` in the meeting module's source; the collection
 * has only its default order, so the rows are sorted here). OpenProject
 * applies the viewer's visibility before answering, so a meeting the
 * viewer could not open there never reaches the widget. This service
 * writes nothing; `OpenProjectMeetingMirrorService` copies what it reads
 * into the team calendar, one way (v4.9.10 — Justin, 2026-09-14: "A one
 * way sync is fine"), and the rows here carry what that needs: the open
 * meetings with their times, and the ids of the cancelled ones in the
 * same window so a copy can be taken away again.
 *
 * Cached per user, team, project and host for TTL_MY_WORK like the other
 * personal reads; `refresh` bypasses it under the usual cooldown.
 */
class OpenProjectMeetingService {

    public const DEFAULT_LIMIT = 10;
    public const MAX_LIMIT     = 25;
    /** How far ahead the widget looks, in days — the calendar widget's own horizon. */
    public const HORIZON_DAYS  = 30;

    public function __construct(
        private OpenProjectClient $client,
        private OpenProjectCache  $cache,
        private TimezoneService   $timezoneService,
    ) {
    }

    /**
     * Upcoming meetings of one project as the viewer, soonest first.
     *
     * @return array{items: list<array<string, mixed>>, cancelledIds: list<int>, total: ?int, retrievedAt: int, fromCache: bool}
     * @throws OpenProjectException
     */
    public function upcoming(string $userId, string $teamId, int $projectId, string $host, int $limit = self::DEFAULT_LIMIT, bool $refresh = false): array {
        if ($projectId <= 0) {
            throw new ValidationException('Invalid project id');
        }
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $key = $this->cache->key('meetings:' . $limit, $userId, $teamId, $projectId, $host);
        if (!$refresh || !$this->cache->allowRefresh($key)) {
            $cached = $this->cache->get($key);
            if (is_array($cached)) {
                $cached['fromCache'] = true;
                return $cached;
            }
        }

        $now   = time();
        $from  = $this->timezoneService->today($userId, $now);
        $to    = $this->timezoneService->today($userId, $now + self::HORIZON_DAYS * 86400);

        $raw = $this->client->get($userId, 'meetings', [
            'filters'  => json_encode([
                ['project'       => ['operator' => '=',   'values' => [(string)$projectId]]],
                ['datesInterval' => ['operator' => '<>d', 'values' => [$from, $to]]],
            ], JSON_THROW_ON_ERROR),
            'pageSize' => $limit,
        ]);
        $collection = OpenProjectNormalizer::collection($raw);
        if ($collection === null) {
            throw new OpenProjectException(OpenProjectException::UNSUPPORTED_RESPONSE, 'meetings did not return a collection');
        }

        $items     = [];
        $cancelled = [];
        foreach ($collection['elements'] as $element) {
            if (($element['_type'] ?? null) !== 'Meeting') {
                continue;
            }
            $m = OpenProjectNormalizer::meeting($element);
            if ($m['id'] <= 0 || $m['startTime'] === null) {
                continue;
            }
            // Templates are not meetings at all.
            if ((bool)($element['template'] ?? false)) {
                continue;
            }
            // The belt on the project filter.
            if (($m['project']['id'] ?? $projectId) !== $projectId) {
                continue;
            }
            $start = (int)strtotime((string)$m['startTime']);
            if ($start <= 0 || $start < $now - 3600) {
                continue;
            }
            // Cancelled meetings are not upcoming events — but the sync
            // needs to know they were, so their copy can go.
            if ($m['state'] === 'cancelled') {
                $cancelled[] = $m['id'];
                continue;
            }
            $end = $m['endTime'] !== null ? (int)strtotime((string)$m['endTime']) : null;
            if (($end === null || $end <= 0) && $m['durationHours'] !== null) {
                $end = $start + (int)round($m['durationHours'] * 3600);
            }
            $items[] = [
                'id'       => $m['id'],
                'title'    => $m['title'],
                'location' => $m['location'],
                'start'    => gmdate('c', $start),
                'end'      => $end !== null && $end > 0 ? gmdate('c', $end) : null,
                'state'    => $m['state'],
                'author'   => $m['author'],
                'url'      => $this->client->meetingUrl($m['id']),
            ];
        }
        usort($items, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']) ?: $a['id'] <=> $b['id']);
        $items = array_slice($items, 0, $limit);

        $payload = [
            'items'        => $items,
            'cancelledIds' => array_values(array_unique($cancelled)),
            'total'        => $collection['total'],
            'retrievedAt'  => $now,
        ];
        $this->cache->set($key, $payload, OpenProjectCache::TTL_MY_WORK);
        $payload['fromCache'] = false;
        return $payload;
    }
}
