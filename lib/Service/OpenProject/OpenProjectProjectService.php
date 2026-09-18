<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject;

use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\TimezoneService;

/**
 * Projects: search, read, and the overview the widget shows (v4.9.3).
 *
 * Every method runs as one user and answers with what OpenProject lets that
 * user see. The overview is assembled from several reads; only the project
 * itself is required — every other block is best-effort and reports `null`
 * (with the reason in `warnings`) rather than failing the widget, because a
 * user who may see the project but not its types should still get the
 * counts they are entitled to.
 *
 * ## The filters, and why they are written the way they are
 *
 * OpenProject's filter grammar is documented at
 * https://www.openproject.org/docs/api/filters/ . Only documented operators
 * are used here:
 *
 *   `o` / `c`            open / closed status (work packages only)
 *   `<>d` [from, to]     between two ISO dates, inclusive
 *   `>t-` [n]            less than n days in the past
 *   `=`                  equals one of the values
 *
 * "Overdue" is expressed as `<>d` from the epoch to yesterday rather than
 * with the relative `<t-` operator: the relative form's inclusion of "today"
 * is not documented either way, and a wrong guess there would be a silently
 * wrong number on every project. An explicit range cannot be misread.
 */
class OpenProjectProjectService {

    /** Most projects a search returns. OpenProject's own cap is higher. */
    public const SEARCH_LIMIT = 25;
    /** Recently completed work packages shown in the overview. */
    private const COMPLETED_LIMIT = 5;
    /** "Recently" for completed work: this many days. */
    private const COMPLETED_DAYS = 14;
    /** "Due soon" horizon for the overview count, in days. */
    public const DUE_SOON_DAYS = 7;
    /** Milestone candidates fetched to find the next one. */
    private const MILESTONE_LIMIT = 10;

    public function __construct(
        private OpenProjectClient $client,
        private OpenProjectCache  $cache,
        private TimezoneService   $timezoneService,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────
    // Search and read
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Active projects this user may **link**, optionally narrowed by a name or
     * identifier fragment. Sorted by name; capped at SEARCH_LIMIT.
     *
     * "May link" is OpenProject's answer, per project: the user administers
     * it (the resource carries an `update` link) or it is public
     * (`OpenProjectNormalizer::isLinkable`). Projects the user can merely see
     * are dropped here rather than greyed out in the picker — an option a
     * person cannot take is hidden, not disabled (CLAUDE.md § Permissions) —
     * and the picker says what the list contains.
     *
     * @return list<array{id: int, identifier: string, name: string, active: bool, public: bool, canEditProject: bool, linkable: bool}>
     * @throws OpenProjectException
     */
    public function search(string $userId, string $query, int $limit = self::SEARCH_LIMIT): array {
        $query = trim($query);
        if (mb_strlen($query) > 200) {
            throw new ValidationException('Search query too long');
        }
        $limit = max(1, min($limit, self::SEARCH_LIMIT));

        $filters = [
            ['active' => ['operator' => '=', 'values' => ['t']]],
        ];
        if ($query !== '') {
            $filters[] = ['name_and_identifier' => ['operator' => '~', 'values' => [$query]]];
        }

        $raw = $this->client->get($userId, 'projects', [
            'filters'  => json_encode($filters, JSON_THROW_ON_ERROR),
            'sortBy'   => json_encode([['name', 'asc']], JSON_THROW_ON_ERROR),
            'pageSize' => $limit,
        ]);

        $collection = OpenProjectNormalizer::collection($raw);
        if ($collection === null) {
            throw new OpenProjectException(OpenProjectException::UNSUPPORTED_RESPONSE, 'projects did not return a collection');
        }

        $out = [];
        foreach ($collection['elements'] as $element) {
            if (($element['_type'] ?? null) !== 'Project') {
                continue;
            }
            $summary = OpenProjectNormalizer::projectSummary($element);
            if ($summary['id'] > 0 && $summary['linkable']) {
                $out[] = $summary;
            }
        }
        return $out;
    }

    /**
     * One project, normalised. 404 from OpenProject is `project_not_found`.
     *
     * @return array<string, mixed>
     * @throws OpenProjectException
     */
    public function getProject(string $userId, int $projectId): array {
        if ($projectId <= 0) {
            throw new ValidationException('Invalid project id');
        }
        $raw = $this->client->get($userId, 'projects/' . $projectId);
        if (($raw['_type'] ?? null) !== 'Project' || (int)($raw['id'] ?? 0) !== $projectId) {
            throw new OpenProjectException(OpenProjectException::UNSUPPORTED_RESPONSE, 'projects/{id} did not return that Project');
        }
        return OpenProjectNormalizer::project($raw);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Overview
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The overview widget's payload for one user's view of one project.
     *
     * Served from cache for TTL_OVERVIEW unless `$refresh`, and a refresh
     * inside the cooldown is served from cache too. `retrievedAt` is when
     * OpenProject was actually asked, so the widget can say how old the
     * numbers are.
     *
     * @return array<string, mixed>
     * @throws OpenProjectException when the project itself cannot be read
     */
    public function overview(string $userId, string $teamId, int $projectId, string $host, bool $refresh = false): array {
        $key = $this->cache->key('overview', $userId, $teamId, $projectId, $host);

        if (!$refresh || !$this->cache->allowRefresh($key)) {
            $cached = $this->cache->get($key);
            if (is_array($cached)) {
                $cached['fromCache'] = true;
                return $cached;
            }
        }

        $payload = $this->buildOverview($userId, $projectId);
        $this->cache->set($key, $payload, OpenProjectCache::TTL_OVERVIEW);
        $payload['fromCache'] = false;
        return $payload;
    }

    /**
     * @return array<string, mixed>
     * @throws OpenProjectException
     */
    private function buildOverview(string $userId, int $projectId): array {
        // Required: without the project there is no overview.
        $project    = $this->getProject($userId, $projectId);
        $projectRef = $project['identifier'] !== '' ? $project['identifier'] : (string)$projectId;
        $warnings   = [];

        $project['url']              = $this->client->projectUrl($projectRef);
        $project['workPackagesUrl']  = $this->client->workPackagesUrl($projectRef);
        $project['newWorkPackageUrl'] = $project['canCreateWorkPackage']
            ? $this->client->newWorkPackageUrl($projectRef)
            : null;

        // "Today" is the viewer's today (TimezoneService), not the server's.
        $today     = $this->timezoneService->today($userId);
        $todayDate = new \DateTimeImmutable($today, new \DateTimeZone('UTC'));
        $yesterday = $todayDate->modify('-1 day')->format('Y-m-d');
        $horizon   = $todayDate->modify('+' . self::DUE_SOON_DAYS . ' days')->format('Y-m-d');

        // Best-effort blocks. Each failure is recorded by code, not swallowed.
        $counts = [
            'open'    => $this->count($userId, $projectId, [self::openFilter()], $warnings, 'open'),
            'overdue' => $this->count($userId, $projectId, [
                self::openFilter(),
                ['dueDate' => ['operator' => '<>d', 'values' => ['1970-01-01', $yesterday]]],
            ], $warnings, 'overdue'),
            'dueSoon' => $this->count($userId, $projectId, [
                self::openFilter(),
                ['dueDate' => ['operator' => '<>d', 'values' => [$today, $horizon]]],
            ], $warnings, 'dueSoon'),
        ];

        return [
            'project'           => $project,
            'counts'            => $counts,
            'dueSoonDays'       => self::DUE_SOON_DAYS,
            'nextMilestone'     => $this->nextMilestone($userId, $projectId, $today, $warnings),
            'recentlyCompleted' => $this->recentlyCompleted($userId, $projectId, $warnings),
            'files'             => $this->projectFiles($userId, $projectId, $warnings),
            'warnings'          => array_values(array_unique($warnings)),
            'retrievedAt'       => time(),
        ];
    }

    /**
     * A work-package count via the collection's `total` with `pageSize=1`.
     * Null when the read fails; the reason lands in `$warnings`.
     *
     * @param list<array<string, mixed>> $filters
     * @param list<string> $warnings
     */
    private function count(string $userId, int $projectId, array $filters, array &$warnings, string $label): ?int {
        try {
            $raw = $this->client->get($userId, 'projects/' . $projectId . '/work_packages', [
                'filters'  => json_encode($filters, JSON_THROW_ON_ERROR),
                'pageSize' => 1,
            ]);
            $collection = OpenProjectNormalizer::collection($raw);
            if ($collection === null || $collection['total'] === null) {
                $warnings[] = 'counts:' . $label . ':' . OpenProjectException::UNSUPPORTED_RESPONSE;
                return null;
            }
            return $collection['total'];
        } catch (OpenProjectException $e) {
            $this->rethrowIfFatal($e);
            $warnings[] = 'counts:' . $label . ':' . $e->getErrorCode();
            return null;
        }
    }

    /**
     * The earliest open milestone dated today or later, or null.
     *
     * Milestones are work packages whose *type* is a milestone, so the
     * project's types are read first (cached — they change rarely) and the
     * work packages filtered by those ids. A project without a milestone type
     * has no milestone, which is a null and not a warning.
     *
     * @param list<string> $warnings
     * @return ?array{id: int, subject: string, date: ?string, status: ?string, url: ?string, overdue: bool}
     */
    private function nextMilestone(string $userId, int $projectId, string $today, array &$warnings): ?array {
        try {
            $typeIds = $this->milestoneTypeIds($userId, $projectId);
            if ($typeIds === []) {
                return null;
            }
            $raw = $this->client->get($userId, 'projects/' . $projectId . '/work_packages', [
                'filters'  => json_encode([
                    self::openFilter(),
                    ['type' => ['operator' => '=', 'values' => array_map('strval', $typeIds)]],
                ], JSON_THROW_ON_ERROR),
                'sortBy'   => json_encode([['dueDate', 'asc']], JSON_THROW_ON_ERROR),
                'pageSize' => self::MILESTONE_LIMIT,
            ]);
            $collection = OpenProjectNormalizer::collection($raw);
            if ($collection === null) {
                $warnings[] = 'milestone:' . OpenProjectException::UNSUPPORTED_RESPONSE;
                return null;
            }

            $candidates = [];
            foreach ($collection['elements'] as $element) {
                $wp   = OpenProjectNormalizer::workPackage($element);
                $date = $wp['date'] ?? $wp['dueDate'] ?? $wp['startDate'];
                if ($wp['id'] <= 0) {
                    continue;
                }
                $candidates[] = [
                    'id'      => $wp['id'],
                    'subject' => $wp['subject'],
                    'date'    => $date,
                    'status'  => $wp['status'],
                    'url'     => $this->client->workPackageUrl($wp['id']),
                    'overdue' => $date !== null && $date < $today,
                ];
            }
            if ($candidates === []) {
                return null;
            }
            // Earliest dated on/after today; otherwise the earliest overdue
            // one, flagged — a slipping milestone is the one to show.
            foreach ($candidates as $c) {
                if ($c['date'] !== null && $c['date'] >= $today) {
                    return $c;
                }
            }
            return $candidates[0];
        } catch (OpenProjectException $e) {
            $this->rethrowIfFatal($e);
            $warnings[] = 'milestone:' . $e->getErrorCode();
            return null;
        }
    }

    /**
     * Ids of the project's milestone types. Cached per user and project for
     * TTL_TYPES — types are administrator configuration and change rarely.
     *
     * @return list<int>
     * @throws OpenProjectException
     */
    private function milestoneTypeIds(string $userId, int $projectId): array {
        $host = $this->client->getHost();
        $key  = $this->cache->key('types', $userId, '-', $projectId, $host);
        $ids  = $this->cache->get($key);
        if (is_array($ids)) {
            return array_map('intval', $ids);
        }

        $raw        = $this->client->get($userId, 'projects/' . $projectId . '/types');
        $collection = OpenProjectNormalizer::collection($raw);
        if ($collection === null) {
            throw new OpenProjectException(OpenProjectException::UNSUPPORTED_RESPONSE, 'projects/{id}/types did not return a collection');
        }
        $ids = [];
        foreach ($collection['elements'] as $type) {
            if (($type['isMilestone'] ?? false) === true && isset($type['id']) && is_numeric($type['id'])) {
                $ids[] = (int)$type['id'];
            }
        }
        $this->cache->set($key, $ids, OpenProjectCache::TTL_TYPES);
        return $ids;
    }

    /**
     * Work packages closed in the last COMPLETED_DAYS, newest change first.
     *
     * @param list<string> $warnings
     * @return list<array{id: int, subject: string, type: ?string, status: ?string, updatedAt: ?string, url: ?string}>
     */
    private function recentlyCompleted(string $userId, int $projectId, array &$warnings): array {
        try {
            $raw = $this->client->get($userId, 'projects/' . $projectId . '/work_packages', [
                'filters'  => json_encode([
                    ['status'    => ['operator' => 'c', 'values' => []]],
                    ['updatedAt' => ['operator' => '>t-', 'values' => [(string)self::COMPLETED_DAYS]]],
                ], JSON_THROW_ON_ERROR),
                'sortBy'   => json_encode([['updatedAt', 'desc']], JSON_THROW_ON_ERROR),
                'pageSize' => self::COMPLETED_LIMIT,
            ]);
            $collection = OpenProjectNormalizer::collection($raw);
            if ($collection === null) {
                $warnings[] = 'completed:' . OpenProjectException::UNSUPPORTED_RESPONSE;
                return [];
            }
            $out = [];
            foreach ($collection['elements'] as $element) {
                $wp = OpenProjectNormalizer::workPackage($element);
                if ($wp['id'] <= 0) {
                    continue;
                }
                $out[] = [
                    'id'        => $wp['id'],
                    'subject'   => $wp['subject'],
                    'type'      => $wp['type'],
                    'status'    => $wp['status'],
                    'updatedAt' => $wp['updatedAt'],
                    'url'       => $this->client->workPackageUrl($wp['id']),
                ];
            }
            return $out;
        } catch (OpenProjectException $e) {
            $this->rethrowIfFatal($e);
            $warnings[] = 'completed:' . $e->getErrorCode();
            return [];
        }
    }

    /**
     * The project's file storage, when OpenProject has one configured for it:
     * the `open` link OpenProject itself hands out for the project folder.
     * Null when there is none, when the storage is not set up (OpenProject
     * then omits the link), or when the link would leave the configured host.
     *
     * @param list<string> $warnings
     * @return ?array{url: string}
     */
    private function projectFiles(string $userId, int $projectId, array &$warnings): ?array {
        try {
            $raw = $this->client->get($userId, 'project_storages', [
                'filters' => json_encode([
                    ['projectId' => ['operator' => '=', 'values' => [(string)$projectId]]],
                ], JSON_THROW_ON_ERROR),
            ]);
            $collection = OpenProjectNormalizer::collection($raw);
            if ($collection === null) {
                return null;
            }
            foreach ($collection['elements'] as $storage) {
                $url = $this->client->absoluteUrl(OpenProjectNormalizer::linkHref($storage, 'open'));
                if ($url !== null) {
                    return ['url' => $url];
                }
            }
            return null;
        } catch (OpenProjectException $e) {
            $this->rethrowIfFatal($e);
            // A user without the storages permission simply has no Files link.
            if ($e->getErrorCode() !== OpenProjectException::PERMISSION_DENIED
                && $e->getErrorCode() !== OpenProjectException::PROJECT_NOT_FOUND) {
                $warnings[] = 'files:' . $e->getErrorCode();
            }
            return null;
        }
    }

    /**
     * A failure that means every following read would fail the same way is
     * not a partial result — it is the overview's failure.
     */
    private function rethrowIfFatal(OpenProjectException $e): void {
        if ($e->isConfigurationProblem()
            || in_array($e->getErrorCode(), [
                OpenProjectException::USER_NOT_CONNECTED,
                OpenProjectException::AUTH_FAILED,
                OpenProjectException::API_UNAVAILABLE,
                OpenProjectException::RATE_LIMITED,
            ], true)) {
            throw $e;
        }
    }

    /** @return array<string, array{operator: string, values: list<string>}> */
    private static function openFilter(): array {
        return ['status' => ['operator' => 'o', 'values' => []]];
    }
}
