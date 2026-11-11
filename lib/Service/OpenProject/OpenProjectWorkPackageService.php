<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject;

use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Exception\ValidationException;

/**
 * Work packages of one project, read as one user (v4.9.3; reshaped v4.9.5).
 *
 * Two readers, each one bounded OpenProject query as the current user, so
 * a list can never contain a work package the user could not open in
 * OpenProject — OpenProject applies its own visibility before answering.
 *
 *   section('upcoming')  the project's open work packages **of every
 *                        assignee** that carry a due date, soonest first —
 *                        overdue at the top. Feeds the team home's Upcoming
 *                        tasks widget, which is the team's view of what is
 *                        due, not the viewer's.
 *   assignedDueBy()      the viewer's own open work packages due on or
 *                        before a date — My Work's provider reads this per
 *                        linked team. Personal work belongs in the personal
 *                        queue (Justin, 2026-09-12), which is why the
 *                        "My OpenProject work" widget of 4.9.3 is gone.
 *   workPackage()        one work package, uncached — the authorisation
 *                        re-read My Work does before any action.
 *
 * And, since v4.9.15, one writer beside them:
 *
 *   createForm()         OpenProject's own create form for the project —
 *                        the types and assignees the viewer may pick.
 *   create()             one new work package in the project, as the
 *                        viewer, through the official app's POST. OpenProject
 *                        validates; a 422 arrives as `validation_failed`
 *                        with OpenProject's sentence. The team's cache
 *                        generation is bumped afterwards so every member's
 *                        next widget load lists it.
 *
 * ## Why "has a due date" is an explicit range
 *
 * Both readers want only dated work packages: an undated one has no place
 * in a list ordered by due date, and OpenProject does not document where
 * `sortBy dueDate` puts nulls. `dueDate <>d [1970-01-01, …]` is the
 * documented between-two-dates operator, inclusive at both ends; a work
 * package without a due date cannot match it. That is the same reasoning
 * `OpenProjectProjectService` gives for "overdue": where a wrong answer
 * would be silent, the form that cannot be misread wins.
 *
 * Pages are OpenProject's: `offset` is a 1-based page number, `pageSize` the
 * page length, and `total` is the collection's own count. Nothing here ever
 * asks for an unbounded list.
 */
class OpenProjectWorkPackageService {

    public const SECTIONS = ['upcoming'];

    public const DEFAULT_PAGE_SIZE = 10;
    public const MAX_PAGE_SIZE     = 25;
    /**
     * Most rows My Work takes from one project. Above OpenProject's usual
     * per-page cap of 100 nothing is gained; `truncated` says when it hit.
     */
    public const MY_WORK_LIMIT = 100;
    /**
     * v4.9.7 — the smaller reads Phase 3 adds per project: recently updated
     * assigned work, recently completed assigned work, upcoming milestones,
     * the viewer's own unassigned creations, and the What's new activity
     * window. Each is one bounded request.
     */
    public const RECENT_LIMIT     = 50;
    public const MILESTONE_LIMIT  = 10;
    public const ACTIVITY_LIMIT   = 25;
    /** The far bound of "has a due date" — see the class docblock. */
    private const FAR_FUTURE = '2999-12-31';
    private const EPOCH      = '1970-01-01';

    /** Longest subject OpenProject accepts (its own column limit). */
    public const SUBJECT_MAX_LENGTH     = 255;
    /** Our own cap on a description written from the widget's form. */
    public const DESCRIPTION_MAX_LENGTH = 5000;
    /** Most assignees offered in the form — one bounded read. */
    private const ASSIGNEE_PAGE_SIZE    = 200;

    public function __construct(
        private OpenProjectClient         $client,
        private OpenProjectCache          $cache,
        private OpenProjectProjectService $projects,
    ) {
    }

    /**
     * One page of one section.
     *
     * @return array{
     *   section: string, items: list<array<string, mixed>>, total: ?int,
     *   page: int, pageSize: int, hasMore: bool, retrievedAt: int, fromCache: bool
     * }
     * @throws OpenProjectException
     * @throws ValidationException
     */
    public function section(
        string $userId,
        string $teamId,
        int $projectId,
        string $host,
        string $section,
        int $page = 1,
        int $pageSize = self::DEFAULT_PAGE_SIZE,
        bool $refresh = false,
    ): array {
        if (!in_array($section, self::SECTIONS, true)) {
            throw new ValidationException('Unknown section');
        }
        $page     = max(1, $page);
        $pageSize = max(1, min($pageSize, self::MAX_PAGE_SIZE));

        $key = $this->cache->key('work:' . $section . ':' . $page . ':' . $pageSize, $userId, $teamId, $projectId, $host);
        if (!$refresh || !$this->cache->allowRefresh($key)) {
            $cached = $this->cache->get($key);
            if (is_array($cached)) {
                $cached['fromCache'] = true;
                return $cached;
            }
        }

        [$filters, $sortBy] = $this->query($section);
        [$items, $total]    = $this->fetch($userId, $projectId, $filters, $sortBy, $pageSize, $page);

        $payload = [
            'section'     => $section,
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'pageSize'    => $pageSize,
            'hasMore'     => $total !== null ? ($page * $pageSize) < $total : count($items) === $pageSize,
            'retrievedAt' => time(),
            // v4.9.15 — may the viewer add a work package to this project.
            // Rides the widget's own payload so the Upcoming tasks widget
            // can show or hide its create action without depending on the
            // Project info widget, which a team admin may have hidden.
            'canCreateWorkPackage' => $this->canCreateWorkPackage($userId, $projectId),
            // v4.13.9 — can the OpenProject connection in force send
            // PATCH at all. Rides the same payload for the same reason,
            // and it is the *connection's* capability: whether a given
            // row may be changed is `canEdit` on the row, which is
            // OpenProject's answer per work package. The widget needs
            // both before it offers the action.
            'canEditWorkPackages'  => $this->client->supportsMethod('PATCH'),
        ];

        $this->cache->set($key, $payload, OpenProjectCache::TTL_MY_WORK);
        $payload['fromCache'] = false;
        return $payload;
    }

    /**
     * The viewer's open work packages in the project due on or before
     * `$dueTo` (`Y-m-d`, inclusive), soonest first — overdue included, since
     * a past due date is before any horizon. Cached per user for
     * TTL_MY_WORK like a section, so a My Work reload inside two minutes
     * costs OpenProject nothing.
     *
     * @return array{items: list<array<string, mixed>>, total: ?int, truncated: bool}
     * @throws OpenProjectException
     */
    public function assignedDueBy(string $userId, string $teamId, int $projectId, string $host, string $dueTo, int $limit = self::MY_WORK_LIMIT): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueTo)) {
            throw new ValidationException('Invalid horizon date');
        }
        $limit = max(1, min($limit, self::MY_WORK_LIMIT));

        $key = $this->cache->key('mywork:' . $dueTo . ':' . $limit, $userId, $teamId, $projectId, $host);
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $filters = [
            ['status'   => ['operator' => 'o',   'values' => []]],
            ['assignee' => ['operator' => '=',   'values' => ['me']]],
            ['dueDate'  => ['operator' => '<>d', 'values' => [self::EPOCH, $dueTo]]],
        ];
        [$items, $total] = $this->fetch($userId, $projectId, $filters, [['dueDate', 'asc'], ['updatedAt', 'desc']], $limit, 1);

        $result = ['items' => $items, 'total' => $total, 'truncated' => $this->truncated($total, $items, $limit)];
        $this->cache->set($key, $result, OpenProjectCache::TTL_MY_WORK);
        return $result;
    }

    /** Did a bounded read stop before the collection ran out. */
    private function truncated(?int $total, array $items, int $limit): bool {
        return $total !== null ? $total > count($items) : count($items) === $limit;
    }

    /**
     * The viewer's open work packages in the project touched in the last
     * `$days` days, newest change first (v4.9.7) — the "updated recently"
     * category, and the one read that carries **undated** assigned work into
     * My Work: a work package somebody just changed is worth a row whether
     * or not it has a deadline. Cached per user for TTL_MY_WORK.
     *
     * @return array{items: list<array<string, mixed>>, total: ?int, truncated: bool}
     * @throws OpenProjectException
     */
    public function assignedRecentlyUpdated(string $userId, string $teamId, int $projectId, string $host, int $days, int $limit = self::RECENT_LIMIT): array {
        $days  = max(1, min($days, 90));
        $limit = max(1, min($limit, self::MY_WORK_LIMIT));

        $key    = $this->cache->key('mywork-recent:' . $days . ':' . $limit, $userId, $teamId, $projectId, $host);
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $filters = [
            ['status'    => ['operator' => 'o',   'values' => []]],
            ['assignee'  => ['operator' => '=',   'values' => ['me']]],
            ['updatedAt' => ['operator' => '>t-', 'values' => [(string)$days]]],
        ];
        [$items, $total] = $this->fetch($userId, $projectId, $filters, [['updatedAt', 'desc']], $limit, 1);

        $result = ['items' => $items, 'total' => $total, 'truncated' => $this->truncated($total, $items, $limit)];
        $this->cache->set($key, $result, OpenProjectCache::TTL_MY_WORK);
        return $result;
    }

    /**
     * The viewer's **closed** work packages in the project touched in the
     * last `$days` days, newest change first (v4.9.7) — My Work's Completed
     * section. `updatedAt` is the closest thing the resource offers to a
     * completion time; a work package edited after closing reports the edit
     * (documented in OPENPROJECT.md §6). Cached per user for TTL_MY_WORK.
     *
     * @return array{items: list<array<string, mixed>>, total: ?int, truncated: bool}
     * @throws OpenProjectException
     */
    public function assignedCompletedSince(string $userId, string $teamId, int $projectId, string $host, int $days, int $limit = self::RECENT_LIMIT): array {
        $days  = max(1, min($days, 90));
        $limit = max(1, min($limit, self::MY_WORK_LIMIT));

        $key    = $this->cache->key('mywork-done:' . $days . ':' . $limit, $userId, $teamId, $projectId, $host);
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $filters = [
            ['status'    => ['operator' => 'c',   'values' => []]],
            ['assignee'  => ['operator' => '=',   'values' => ['me']]],
            ['updatedAt' => ['operator' => '>t-', 'values' => [(string)$days]]],
        ];
        [$items, $total] = $this->fetch($userId, $projectId, $filters, [['updatedAt', 'desc']], $limit, 1);

        $result = ['items' => $items, 'total' => $total, 'truncated' => $this->truncated($total, $items, $limit)];
        $this->cache->set($key, $result, OpenProjectCache::TTL_MY_WORK);
        return $result;
    }

    /**
     * The project's open milestones dated between `$from` and `$to`
     * (`Y-m-d`, inclusive), soonest first (v4.9.7) — every assignee's,
     * because a milestone is the project's, not a person's. `$typeIds` are
     * the project's milestone types (`OpenProjectReferenceService`); an
     * empty list means the project has none and costs no request. A
     * milestone's single `date` is stored as its due date in OpenProject, so
     * the `dueDate` filter is the right one — the same reasoning as the
     * overview's next-milestone read.
     *
     * @param list<int> $typeIds
     * @return list<array<string, mixed>>
     * @throws OpenProjectException
     */
    public function upcomingMilestones(string $userId, string $teamId, int $projectId, string $host, string $from, string $to, array $typeIds, int $limit = self::MILESTONE_LIMIT): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            throw new ValidationException('Invalid milestone window');
        }
        $typeIds = array_values(array_filter(array_map('intval', $typeIds), static fn (int $id): bool => $id > 0));
        if ($typeIds === []) {
            return [];
        }
        $limit = max(1, min($limit, self::MAX_PAGE_SIZE));

        $key    = $this->cache->key('mywork-ms:' . $from . ':' . $to . ':' . $limit, $userId, $teamId, $projectId, $host);
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $filters = [
            ['status'  => ['operator' => 'o',   'values' => []]],
            ['type'    => ['operator' => '=',   'values' => array_map('strval', $typeIds)]],
            ['dueDate' => ['operator' => '<>d', 'values' => [$from, $to]]],
        ];
        [$items] = $this->fetch($userId, $projectId, $filters, [['dueDate', 'asc']], $limit, 1);

        $this->cache->set($key, $items, OpenProjectCache::TTL_MY_WORK);
        return $items;
    }

    /**
     * Every work package in the project — any assignee, open or closed —
     * changed between two instants, newest change first (v4.9.7): the
     * Project info widget's "n work packages changed this week" count. One
     * request per project, one row per work package whatever happened to
     * it. (The first draft put these rows in What's new; Justin's review of
     * 2026-09-14 kept the feed for news and messages.)
     *
     * `updatedAt <>d [from, to]` is the documented between-two-datetimes
     * form (verified against OpenProject 17's `DateTimePast` strategy:
     * ISO datetimes, an empty upper bound meaning "now"). The window is
     * bucketed to five minutes in the cache key so two loads a minute apart
     * share one entry.
     *
     * @return array{items: list<array<string, mixed>>, total: ?int, truncated: bool}
     * @throws OpenProjectException
     */
    public function updatedBetween(string $userId, string $teamId, int $projectId, string $host, int $fromTs, int $toTs, int $limit = self::ACTIVITY_LIMIT): array {
        $limit  = max(1, min($limit, self::MY_WORK_LIMIT));
        $fromTs = max(0, $fromTs);
        $toTs   = max(0, $toTs);
        $bucket = 300;
        $fromB  = (int)(floor($fromTs / $bucket) * $bucket);
        $toB    = $toTs > 0 ? (int)(ceil($toTs / $bucket) * $bucket) : 0;

        $key    = $this->cache->key('activity:' . $fromB . ':' . $toB . ':' . $limit, $userId, $teamId, $projectId, $host);
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $values = [gmdate('Y-m-d\TH:i:s\Z', $fromB), $toB > 0 ? gmdate('Y-m-d\TH:i:s\Z', $toB) : ''];
        $filters = [
            ['updatedAt' => ['operator' => '<>d', 'values' => $values]],
        ];
        [$items, $total] = $this->fetch($userId, $projectId, $filters, [['updatedAt', 'desc']], $limit, 1);

        $result = ['items' => $items, 'total' => $total, 'truncated' => $this->truncated($total, $items, $limit)];
        $this->cache->set($key, $result, OpenProjectCache::TTL_MY_WORK);
        return $result;
    }

    /**
     * The viewer's open work packages in the project that they **created
     * and nobody is assigned to** (v4.9.7 — Justin, 2026-09-14: "I created
     * new work packages … in My Work I don't see them. I want them there").
     * A work package you made and left unassigned is yours to act on —
     * nobody else will. `author = me` and `assignee !*` (the documented
     * "none" operator); the same horizon / recently-touched rules as the
     * assigned reads apply afterwards. Cached per user for TTL_MY_WORK.
     *
     * @return array{items: list<array<string, mixed>>, total: ?int, truncated: bool}
     * @throws OpenProjectException
     */
    public function authoredUnassigned(string $userId, string $teamId, int $projectId, string $host, int $limit = self::RECENT_LIMIT): array {
        $limit = max(1, min($limit, self::MY_WORK_LIMIT));

        $key    = $this->cache->key('mywork-authored:' . $limit, $userId, $teamId, $projectId, $host);
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $filters = [
            ['status'   => ['operator' => 'o',  'values' => []]],
            ['author'   => ['operator' => '=',  'values' => ['me']]],
            ['assignee' => ['operator' => '!*', 'values' => []]],
        ];
        [$items, $total] = $this->fetch($userId, $projectId, $filters, [['dueDate', 'asc'], ['updatedAt', 'desc']], $limit, 1);

        $result = ['items' => $items, 'total' => $total, 'truncated' => $this->truncated($total, $items, $limit)];
        $this->cache->set($key, $result, OpenProjectCache::TTL_MY_WORK);
        return $result;
    }

    /**
     * One work package as this user, fresh from OpenProject. 404 from
     * OpenProject — deleted, or no longer visible — is `project_not_found`
     * (the client's classification of a 404 with a message), which the
     * caller reads as "gone".
     *
     * @return array<string, mixed> normalised, with `url`
     * @throws OpenProjectException
     */
    public function workPackage(string $userId, int $workPackageId): array {
        if ($workPackageId <= 0) {
            throw new ValidationException('Invalid work package id');
        }
        $raw = $this->client->get($userId, 'work_packages/' . $workPackageId);
        if (($raw['_type'] ?? null) !== 'WorkPackage' || (int)($raw['id'] ?? 0) !== $workPackageId) {
            throw new OpenProjectException(OpenProjectException::UNSUPPORTED_RESPONSE, 'work_packages/{id} did not return that WorkPackage');
        }
        $wp        = OpenProjectNormalizer::workPackage($raw);
        $wp['url'] = $this->client->workPackageUrl($wp['id']);
        return $wp;
    }

    /**
     * Does OpenProject let this user add work packages to the project — the
     * `createWorkPackage` link on the project resource, which OpenProject
     * includes only for users with that permission. Guarded: a permission
     * read must never cost the widget its rows.
     */
    private function canCreateWorkPackage(string $userId, int $projectId): bool {
        try {
            return (bool)($this->projects->getProject($userId, $projectId)['canCreateWorkPackage'] ?? false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * OpenProject's create form for the project, as this user (v4.9.15):
     * the types the viewer may pick (an inline list on the form's schema),
     * the default type, and the assignees (the schema names a collection;
     * it is followed only when it is an API v3 path). A failed assignee read
     * costs the list, never the form — the work package is then created
     * unassigned.
     *
     * OpenProject 17 marks `projects/{id}/work_packages/form` deprecated;
     * the generic form with the project in `_links` is the one that stays.
     *
     * @return array{
     *   types: list<array{id: int, name: string}>, defaultTypeId: ?int,
     *   assignees: list<array{id: int, name: string}>, assigneesUnavailable: bool
     * }
     * @throws OpenProjectException
     */
    public function createForm(string $userId, int $projectId): array {
        if ($projectId <= 0) {
            throw new ValidationException('Invalid project id');
        }
        $raw = $this->client->post($userId, 'work_packages/form', [
            '_links' => ['project' => ['href' => '/api/v3/projects/' . $projectId]],
        ]);
        if (($raw['_type'] ?? null) !== 'Form') {
            throw new OpenProjectException(OpenProjectException::UNSUPPORTED_RESPONSE, 'work_packages/form did not return a Form');
        }
        $schema  = $raw['_embedded']['schema'] ?? [];
        $payload = $raw['_embedded']['payload'] ?? [];

        $types = [];
        foreach ((array)($schema['type']['_links']['allowedValues'] ?? []) as $link) {
            if (!is_array($link) || !is_string($link['href'] ?? null)) {
                continue;
            }
            $segment = OpenProjectNormalizer::trailingSegment($link['href']);
            $title   = $link['title'] ?? null;
            if (!ctype_digit($segment) || !is_string($title) || trim($title) === '') {
                continue;
            }
            $types[] = ['id' => (int)$segment, 'name' => OpenProjectNormalizer::text($title)];
        }

        $defaultTypeId = is_array($payload) ? OpenProjectNormalizer::linkId($payload, 'type') : null;
        if ($defaultTypeId !== null && !in_array($defaultTypeId, array_column($types, 'id'), true)) {
            $defaultTypeId = null;
        }

        $assignees   = [];
        $unavailable = false;
        $href        = $schema['assignee']['_links']['allowedValues']['href'] ?? null;
        if (is_string($href) && str_starts_with($href, '/api/v3/')) {
            try {
                $assignees = $this->availableAssignees($userId, substr($href, strlen('/api/v3/')));
            } catch (\Throwable $e) {
                $unavailable = true;
            }
        } else {
            $unavailable = true;
        }

        return [
            'types'                => $types,
            'defaultTypeId'        => $defaultTypeId,
            'assignees'            => $assignees,
            'assigneesUnavailable' => $unavailable,
        ];
    }

    /**
     * The users OpenProject offers as assignees, from the collection the
     * form's schema pointed at. Groups and placeholder users are left out:
     * the widget's form hands work to a person.
     *
     * @return list<array{id: int, name: string}>
     * @throws OpenProjectException
     */
    private function availableAssignees(string $userId, string $endpoint): array {
        $raw = $this->client->get($userId, $endpoint, ['pageSize' => self::ASSIGNEE_PAGE_SIZE]);
        $collection = OpenProjectNormalizer::collection($raw);
        if ($collection === null) {
            throw new OpenProjectException(OpenProjectException::UNSUPPORTED_RESPONSE, 'available_assignees did not return a collection');
        }
        $out = [];
        foreach ($collection['elements'] as $element) {
            if (($element['_type'] ?? null) !== 'User') {
                continue;
            }
            $id   = (int)($element['id'] ?? 0);
            $name = $element['name'] ?? null;
            if ($id <= 0 || !is_string($name) || trim($name) === '') {
                continue;
            }
            $out[] = ['id' => $id, 'name' => OpenProjectNormalizer::text($name)];
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * Create one work package in the project, as this user (v4.9.15).
     *
     * One POST, no pre-validation form call: OpenProject validates the body
     * itself and answers 422 with its own sentence, which the client
     * classifies as `validation_failed` carrying that sentence — the same
     * path a project creation takes. The team's cache generation is bumped
     * afterwards so the widget page and the overview counts are fresh for
     * every member's next load.
     *
     * @param array{subject?: mixed, typeId?: mixed, assigneeId?: mixed, dueDate?: mixed, description?: mixed} $input
     * @return array<string, mixed> the created work package, normalised, with `url`
     * @throws OpenProjectException
     * @throws ValidationException
     */
    public function create(string $userId, string $teamId, int $projectId, array $input): array {
        if ($projectId <= 0) {
            throw new ValidationException('Invalid project id');
        }
        $subject = trim((string)($input['subject'] ?? ''));
        if ($subject === '' || mb_strlen($subject) > self::SUBJECT_MAX_LENGTH) {
            throw new ValidationException('A subject of 1 to ' . self::SUBJECT_MAX_LENGTH . ' characters is required');
        }
        $typeId = (int)($input['typeId'] ?? 0);
        if ($typeId <= 0) {
            throw new ValidationException('A type is required');
        }
        $assigneeId = $input['assigneeId'] ?? null;
        $assigneeId = ($assigneeId === null || $assigneeId === '') ? null : (int)$assigneeId;
        if ($assigneeId !== null && $assigneeId <= 0) {
            throw new ValidationException('Invalid assignee');
        }
        $dueDate = $input['dueDate'] ?? null;
        $dueDate = ($dueDate === null || $dueDate === '') ? null : (string)$dueDate;
        if ($dueDate !== null) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate, new \DateTimeZone('UTC'));
            if ($parsed === false || $parsed->format('Y-m-d') !== $dueDate) {
                throw new ValidationException('Invalid due date');
            }
        }
        $description = trim((string)($input['description'] ?? ''));
        if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            throw new ValidationException('The description is too long');
        }

        $body = [
            'subject' => $subject,
            '_links'  => [
                'project' => ['href' => '/api/v3/projects/' . $projectId],
                'type'    => ['href' => '/api/v3/types/' . $typeId],
            ],
        ];
        if ($assigneeId !== null) {
            $body['_links']['assignee'] = ['href' => '/api/v3/users/' . $assigneeId];
        }
        if ($dueDate !== null) {
            $body['dueDate'] = $dueDate;
        }
        if ($description !== '') {
            $body['description'] = ['format' => 'markdown', 'raw' => $description];
        }

        $raw = $this->client->post($userId, 'work_packages', $body);
        if (($raw['_type'] ?? null) !== 'WorkPackage' || (int)($raw['id'] ?? 0) <= 0) {
            throw new OpenProjectException(OpenProjectException::UNSUPPORTED_RESPONSE, 'work_packages did not return a WorkPackage');
        }
        $wp        = OpenProjectNormalizer::workPackage($raw);
        $wp['url'] = $this->client->workPackageUrl($wp['id']);

        // Every member's cached widget page and overview counts are now one
        // work package behind; the generation bump is how the cache forgets
        // a whole team at once.
        $this->cache->invalidateTeam($teamId);
        return $wp;
    }

    /**
     * OpenProject's **edit** form for one work package, as this user
     * (v4.13.9).
     *
     * `POST work_packages/{id}/form`, carrying the work package's current
     * `lockVersion` and nothing else (v4.13.11 — see the body): OpenProject
     * answers with the schema as it applies to *this* work package and
     * *this* user, which is the only way to learn two things we must not
     * guess:
     *
     *  - **which status transitions are legal.** A workflow restricts the
     *    statuses a given type may move to, per role. Offering the full
     *    status list would put choices in front of people that OpenProject
     *    will refuse.
     *  - **the current `lockVersion`**, which the PATCH has to carry.
     *
     * The form is also OpenProject's permission answer: a user who may not
     * edit gets 403, which the caller surfaces rather than interpreting.
     *
     * @return array{
     *   workPackage: array<string, mixed>,
     *   types: list<array{id: int, name: string}>,
     *   statuses: list<array{id: int, name: string}>,
     *   assignees: list<array{id: int, name: string}>,
     *   assigneesUnavailable: bool
     * }
     * @throws OpenProjectException
     */
    public function editForm(string $userId, int $workPackageId): array {
        if ($workPackageId <= 0) {
            throw new ValidationException('Invalid work package id');
        }
        // v4.13.11 — the form POST has to carry the current `lockVersion`.
        // OpenProject runs `WorkPackages::UpdateContract` on the *form* and
        // not only on the PATCH, and that contract adds `error_conflict`
        // when the payload's lock version is absent or does not match:
        //
        //     attribute :lock_version, permission: %i[edit_work_packages …] do
        //       if model.lock_version.nil? || model.lock_version_changed?
        //         errors.add :base, :error_conflict
        //
        // which is also the only thing that makes "lockVersion is required
        // for an update" true. An empty body therefore read as a conflict:
        // 409 *on opening the editor*, for every work package. The create
        // form is unaffected — `CreateContract` has no such check — which
        // is why `createForm()` has always worked with no body at all.
        //
        // The version is read fresh here and deliberately **not** taken
        // from the widget row the editor was opened from: those rows are
        // cached, so a stale one would come back as a conflict that looks
        // like somebody else's edit when nobody edited anything.
        $current = $this->client->get($userId, 'work_packages/' . $workPackageId);
        $lockVersion = OpenProjectNormalizer::workPackage($current)['lockVersion'];
        if ($lockVersion === null) {
            throw new OpenProjectException(
                OpenProjectException::UNSUPPORTED_RESPONSE,
                'work_packages/{id} carried no lockVersion',
            );
        }

        $raw = $this->client->post($userId, 'work_packages/' . $workPackageId . '/form', [
            'lockVersion' => $lockVersion,
        ]);
        if (($raw['_type'] ?? null) !== 'Form') {
            throw new OpenProjectException(
                OpenProjectException::UNSUPPORTED_RESPONSE,
                'work_packages/{id}/form did not return a Form',
            );
        }
        $schema  = $raw['_embedded']['schema'] ?? [];
        $payload = $raw['_embedded']['payload'] ?? [];

        // The payload is the work package as the form sees it, and the
        // `lockVersion` the editor sends back with its PATCH is the one the
        // form answered with — not the one read above — so the version
        // belongs to the same moment as the allowed values.
        $wp = OpenProjectNormalizer::workPackage(is_array($payload) ? $payload : []);
        if ($wp['lockVersion'] === null) {
            // Without it a PATCH cannot be sent safely at all.
            throw new OpenProjectException(
                OpenProjectException::UNSUPPORTED_RESPONSE,
                'the edit form carried no lockVersion',
            );
        }
        $wp['url'] = $this->client->workPackageUrl($workPackageId);

        $assignees   = [];
        $unavailable = false;
        $href        = $schema['assignee']['_links']['allowedValues']['href'] ?? null;
        if (is_string($href) && str_starts_with($href, '/api/v3/')) {
            try {
                $assignees = $this->availableAssignees($userId, substr($href, strlen('/api/v3/')));
            } catch (\Throwable) {
                // Same rule as the create form: a failed assignee read
                // costs the list, never the form.
                $unavailable = true;
            }
        } else {
            $unavailable = true;
        }

        return [
            'workPackage'          => $wp,
            'types'                => $this->allowedValues($schema, 'type'),
            'statuses'             => $this->allowedValues($schema, 'status'),
            'assignees'            => $assignees,
            'assigneesUnavailable' => $unavailable,
        ];
    }

    /**
     * The inline `allowedValues` of one schema field, as id/name pairs
     * (v4.13.9).
     *
     * Extracted from `createForm()`'s type handling, which did this for
     * `type` alone; the edit form needs the same for `status`. Anything
     * without a numeric id or a readable title is dropped rather than
     * guessed at — a half-read option in a picker is worse than a missing
     * one.
     *
     * @param array<string, mixed> $schema
     * @return list<array{id: int, name: string}>
     */
    private function allowedValues(array $schema, string $field): array {
        $out = [];
        foreach ((array)($schema[$field]['_links']['allowedValues'] ?? []) as $link) {
            if (!is_array($link) || !is_string($link['href'] ?? null)) {
                continue;
            }
            $segment = OpenProjectNormalizer::trailingSegment($link['href']);
            $title   = $link['title'] ?? null;
            if (!ctype_digit($segment) || !is_string($title) || trim($title) === '') {
                continue;
            }
            $out[] = ['id' => (int)$segment, 'name' => OpenProjectNormalizer::text($title)];
        }
        return $out;
    }

    /**
     * Change one work package, as the viewer (v4.13.9).
     *
     * **The write §2.124 and §2.126 recorded as impossible.** It needs
     * HTTP PATCH, which the official integration app's request layer does
     * not send; `OpenProjectClient::patch()` refuses before sending on a
     * transport that cannot, with `method_unsupported`.
     *
     * Only the fields given are sent. `lockVersion` is **required** and is
     * the one the caller read from the edit form: OpenProject compares it
     * with the stored one and answers 409 (`edit_conflict`) if somebody
     * else wrote first, which is the whole point of sending it. A caller
     * that sent a freshly-read version instead would turn a conflict into
     * a silent overwrite.
     *
     * `null` for `assigneeId` or `dueDate` means **clear it**, which is
     * why they are distinguished from absent: unassigning somebody and
     * leaving the assignee alone are different edits.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> the updated work package
     * @throws OpenProjectException
     */
    public function update(string $userId, string $teamId, int $workPackageId, array $input): array {
        if ($workPackageId <= 0) {
            throw new ValidationException('Invalid work package id');
        }
        $lockVersion = $input['lockVersion'] ?? null;
        if (!is_numeric($lockVersion) || (int)$lockVersion < 0) {
            throw new ValidationException('A lockVersion is required to change a work package');
        }

        $body = ['lockVersion' => (int)$lockVersion];
        $links = [];

        if (array_key_exists('subject', $input)) {
            $subject = trim((string)$input['subject']);
            if ($subject === '' || mb_strlen($subject) > self::SUBJECT_MAX_LENGTH) {
                throw new ValidationException('A subject of 1 to ' . self::SUBJECT_MAX_LENGTH . ' characters is required');
            }
            $body['subject'] = $subject;
        }

        if (array_key_exists('typeId', $input)) {
            $typeId = (int)$input['typeId'];
            if ($typeId <= 0) {
                throw new ValidationException('Invalid type');
            }
            $links['type'] = ['href' => '/api/v3/types/' . $typeId];
        }

        if (array_key_exists('statusId', $input)) {
            $statusId = (int)$input['statusId'];
            if ($statusId <= 0) {
                throw new ValidationException('Invalid status');
            }
            $links['status'] = ['href' => '/api/v3/statuses/' . $statusId];
        }

        if (array_key_exists('assigneeId', $input)) {
            $assigneeId = $input['assigneeId'];
            if ($assigneeId === null || $assigneeId === '') {
                // Explicitly unassigned.
                $links['assignee'] = ['href' => null];
            } else {
                $assigneeId = (int)$assigneeId;
                if ($assigneeId <= 0) {
                    throw new ValidationException('Invalid assignee');
                }
                $links['assignee'] = ['href' => '/api/v3/users/' . $assigneeId];
            }
        }

        if (array_key_exists('dueDate', $input)) {
            $dueDate = $input['dueDate'];
            if ($dueDate === null || $dueDate === '') {
                $body['dueDate'] = null;
            } else {
                $dueDate = (string)$dueDate;
                $parsed  = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate, new \DateTimeZone('UTC'));
                if ($parsed === false || $parsed->format('Y-m-d') !== $dueDate) {
                    throw new ValidationException('Invalid due date');
                }
                $body['dueDate'] = $dueDate;
            }
        }

        if (array_key_exists('percentageDone', $input)) {
            $percent = $input['percentageDone'];
            if ($percent === null || $percent === '') {
                $body['percentageDone'] = null;
            } else {
                if (!is_numeric($percent) || (int)$percent < 0 || (int)$percent > 100) {
                    throw new ValidationException('Percentage complete must be between 0 and 100');
                }
                $body['percentageDone'] = (int)$percent;
            }
        }

        if (array_key_exists('description', $input)) {
            $description = trim((string)$input['description']);
            if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
                throw new ValidationException('The description is too long');
            }
            $body['description'] = ['format' => 'markdown', 'raw' => $description];
        }

        if ($links !== []) {
            $body['_links'] = $links;
        }
        if (count($body) === 1) {
            // `lockVersion` alone: nothing to change. Refused here rather
            // than sent, because OpenProject would accept it and bump the
            // version, which looks like an edit in its activity log.
            throw new ValidationException('Nothing to change');
        }

        $raw = $this->client->patch($userId, 'work_packages/' . $workPackageId, $body);
        if (($raw['_type'] ?? null) !== 'WorkPackage' || (int)($raw['id'] ?? 0) <= 0) {
            throw new OpenProjectException(
                OpenProjectException::UNSUPPORTED_RESPONSE,
                'the PATCH did not return a WorkPackage',
            );
        }
        $wp        = OpenProjectNormalizer::workPackage($raw);
        $wp['url'] = $this->client->workPackageUrl($wp['id']);

        // Every member's cached widget page, overview counts and My Work
        // rows are now one edit behind.
        $this->cache->invalidateTeam($teamId);
        return $wp;
    }

    /**
     * The filter and sort for a section. Public through the tests: the
     * grammar is the part most worth pinning.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array{0: string, 1: string}>}
     */
    public function query(string $section): array {
        $open  = ['status'  => ['operator' => 'o',   'values' => []]];
        $dated = ['dueDate' => ['operator' => '<>d', 'values' => [self::EPOCH, self::FAR_FUTURE]]];

        switch ($section) {
        case 'upcoming':
        default:
            // Every assignee, on purpose: the team home's Upcoming tasks
            // widget is the team's list, and the viewer's own share of it
            // is in My Work.
            return [[$open, $dated], [['dueDate', 'asc'], ['updatedAt', 'desc']]];
        }
    }

    /**
     * One bounded read of the project's work packages, normalised.
     *
     * @param list<array<string, mixed>>        $filters
     * @param list<array{0: string, 1: string}> $sortBy
     * @return array{0: list<array<string, mixed>>, 1: ?int} items and the collection's total
     * @throws OpenProjectException
     */
    private function fetch(string $userId, int $projectId, array $filters, array $sortBy, int $pageSize, int $page): array {
        $raw = $this->client->get($userId, 'projects/' . $projectId . '/work_packages', [
            'filters'  => json_encode($filters, JSON_THROW_ON_ERROR),
            'sortBy'   => json_encode($sortBy, JSON_THROW_ON_ERROR),
            'pageSize' => $pageSize,
            'offset'   => $page,
        ]);
        $collection = OpenProjectNormalizer::collection($raw);
        if ($collection === null) {
            throw new OpenProjectException(OpenProjectException::UNSUPPORTED_RESPONSE, 'work_packages did not return a collection');
        }

        $items = [];
        foreach ($collection['elements'] as $element) {
            if (($element['_type'] ?? null) !== 'WorkPackage') {
                continue;
            }
            $wp = OpenProjectNormalizer::workPackage($element);
            if ($wp['id'] <= 0) {
                continue;
            }
            $wp['url'] = $this->client->workPackageUrl($wp['id']);
            $items[]   = $wp;
        }
        return [$items, $collection['total']];
    }
}
