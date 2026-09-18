<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\TeamOpenProjectLinkMapper;
use OCA\TeamHub\Exception\OpenProjectException;
use Psr\Log\LoggerInterface;

/**
 * OpenProject work-package activity, read live as the viewer (v4.9.7,
 * Phase 3).
 *
 * The first draft put one card per changed work package into What's new.
 * Justin's review (2026-09-14) kept the feed for news and messages — a
 * work-package edit is not something a team reads about — so this service
 * now feeds one number: the Project info widget's "n work packages changed
 * in the last 7 days" (`OpenProjectController::attention()`). The feed's
 * OpenProject source is `OpenProjectNewsService`.
 *
 * ## The strategy, and why it is this one
 *
 * OpenProject is read **live, as the viewer, at the moment it is asked**
 * — one bounded request per linked project, `updatedAt` between the
 * period bounds, newest first — and nothing is stored. The
 * alternatives were weighed (OPENPROJECT.md §6.3): a background poller
 * would have to read as *somebody*, and TeamHub deliberately holds no
 * token and impersonates nobody for reads (§3.2); webhooks would need a
 * public endpoint, a shared secret and a replay ledger, and would still
 * have to re-check every viewer's visibility before showing anything. The
 * live read has none of those problems: OpenProject applies the viewer's
 * own permissions before answering, a lost permission means the next load
 * simply omits the project, and the only state is a per-viewer cache of
 * at most two minutes.
 *
 * ## Noise control by construction
 *
 * The unit of activity is **the work package, not the field change**.
 * OpenProject collapses every edit into one `updatedAt`, so a work package
 * whose status, assignee and due date changed this morning is one row
 * saying it was updated, with its current status, assignee and due date
 * on the row. Comment bodies and descriptions never leave OpenProject.
 * TeamHub writes nothing to OpenProject from here, so its own reads cannot
 * produce activity.
 *
 * ## What an item says
 *
 * `created` (the work package is new inside the window: created and
 * updated within a minute of each other), `completed` (its status is one
 * OpenProject marks closed), `milestone_completed` / `milestone_updated`
 * (its type is a milestone), otherwise `updated`. The classification uses
 * OpenProject's own status and type lists (`OpenProjectReferenceService`),
 * never a label. The actor is known for `created` (the author link on the
 * resource) and otherwise not stated — the collection does not say who
 * made the last change, and guessing would be worse than silence.
 *
 * ## Bounded and partial
 *
 * At most PROJECT_CAP projects per load in a deterministic order, under a
 * wall-clock budget; a project that refuses is skipped and counted; the
 * whole source degrades to a status the card can explain
 * (`not_connected`, `auth_required`, `unavailable`, `partial`) and never
 * to an exception — the feed's messages and Talk rows are not this
 * source's to break.
 */
class OpenProjectActivityService {

    public const SOURCE = 'openproject';

    public const TYPE_CREATED             = 'created';
    public const TYPE_UPDATED             = 'updated';
    public const TYPE_COMPLETED           = 'completed';
    public const TYPE_MILESTONE_UPDATED   = 'milestone_updated';
    public const TYPE_MILESTONE_COMPLETED = 'milestone_completed';

    public const TYPES = [
        self::TYPE_CREATED, self::TYPE_UPDATED, self::TYPE_COMPLETED,
        self::TYPE_MILESTONE_UPDATED, self::TYPE_MILESTONE_COMPLETED,
    ];

    /** Most linked projects one feed load reads. */
    public const PROJECT_CAP = 10;
    /** This source's own wall-clock budget per feed load, in milliseconds. */
    public const BUDGET_MS = 2500;
    /** "All time" in the feed means this many days for OpenProject. */
    public const MAX_WINDOW_DAYS = 30;
    /** A work package created and updated within this many seconds is "created". */
    private const CREATED_TOLERANCE = 60;

    public function __construct(
        private OpenProjectClient             $client,
        private OpenProjectWorkPackageService $workPackages,
        private OpenProjectReferenceService   $reference,
        private TeamOpenProjectLinkMapper     $linkMapper,
        private OpenProjectSyncHealth         $health,
        private OpenProjectMessages           $messages,
        private LoggerInterface               $logger,
    ) {
    }

    /**
     * Is the source worth asking at all for this viewer — cheap, no HTTP.
     * `null` when it is; otherwise the status block the feed reports.
     *
     * @return ?array{state: string, code: ?string, message: ?string}
     */
    public function availability(string $userId): ?array {
        if (!$this->client->isIntegrationEnabled() || $this->client->getHost() === '') {
            return ['state' => 'unavailable', 'code' => null, 'message' => null];
        }
        if (!$this->client->isUserConnected($userId)) {
            return ['state' => 'not_connected', 'code' => OpenProjectException::USER_NOT_CONNECTED, 'message' => null];
        }
        return null;
    }

    /**
     * The viewer's OpenProject activity across their teams, inside
     * `[$from, $to]` (unix seconds; 0 = unbounded on that side, which for
     * OpenProject means the last MAX_WINDOW_DAYS).
     *
     * `$teamIds` is the viewer's membership set, resolved by the caller —
     * the authorisation boundary on the TeamHub side; OpenProject is the
     * boundary on its side, because every request runs as the viewer.
     *
     * @param string[] $teamIds
     * @param string[] $types      restrict to these activity types ([] = all)
     * @param string[] $projectIds restrict to these OpenProject project ids ([] = all)
     * @return array{
     *   items: list<array<string, mixed>>,
     *   status: array{state: string, code: ?string, message: ?string, covered: int, skipped: int},
     *   projects: list<array{id: string, name: string, teamId: string, count: int}>
     * }
     */
    public function feed(string $userId, array $teamIds, int $from, int $to, int $limit, array $types = [], array $projectIds = []): array {
        $empty = static fn (array $status): array => ['items' => [], 'status' => $status + ['covered' => 0, 'skipped' => 0], 'projects' => []];

        if ($teamIds === []) {
            return $empty(['state' => 'skipped', 'code' => null, 'message' => null]);
        }
        $unavailable = $this->availability($userId);
        if ($unavailable !== null) {
            return $empty($unavailable);
        }

        $now   = time();
        $floor = $now - self::MAX_WINDOW_DAYS * 86400;
        $from  = max($from, $floor);
        if ($to > 0 && $to < $from) {
            return $empty(['state' => 'ok', 'code' => null, 'message' => null]);
        }
        $limit = max(1, min($limit, OpenProjectWorkPackageService::MY_WORK_LIMIT));

        $links = $this->usableLinks($teamIds);
        if ($links === []) {
            return $empty(['state' => 'ok', 'code' => null, 'message' => null]);
        }

        $startedAt = microtime(true);
        $closed    = $this->reference->closedStatuses($userId);

        $items     = [];
        $covered   = 0;
        $skipped   = 0;
        $codes     = [];
        $budgetHit = false;
        $seen      = 0;

        foreach ($links as $teamId => $link) {
            if ($seen >= self::PROJECT_CAP) {
                break;
            }
            if ((microtime(true) - $startedAt) * 1000 >= self::BUDGET_MS) {
                $budgetHit = true;
                break;
            }
            $seen++;
            $projectId = (int)$link['projectId'];
            $host      = (string)$link['host'];

            try {
                $page = $this->workPackages->updatedBetween($userId, (string)$teamId, $projectId, $host, $from, $to, $limit);
            } catch (OpenProjectException $e) {
                $codes[] = $e->getErrorCode();
                $skipped++;
                $this->logger->debug('[TeamHub][WhatsNew][OpenProject] project read skipped', [
                    'code' => $e->getErrorCode(), 'app' => Application::APP_ID,
                ]);
                if ($e->getErrorCode() === OpenProjectException::AUTH_FAILED) {
                    break;
                }
                continue;
            } catch (\Throwable $e) {
                $codes[] = OpenProjectException::TEMPORARY_FAILURE;
                $skipped++;
                $this->logger->warning('[TeamHub][WhatsNew][OpenProject] project read failed', [
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                continue;
            }
            $covered++;

            // Milestone types per project — cached; an unreadable list means
            // "no milestones", which only costs a label.
            $milestoneTypes = [];
            try {
                $milestoneTypes = $this->reference->milestoneTypeIds($userId, $projectId);
            } catch (\Throwable) {
                $milestoneTypes = [];
            }

            foreach ($page['items'] as $wp) {
                $row = $this->row((string)$teamId, $link, $wp, $closed, $milestoneTypes, $from, $to);
                if ($row !== null) {
                    $items[$row['id']] = $row;
                }
            }
        }

        $this->health->recordRun(OpenProjectSyncHealth::CHANNEL_ACTIVITY, $covered, $skipped, $codes, $budgetHit);

        // Facets before the type / project narrowing, so the rail's project
        // list still offers a project the reader has just filtered out.
        $projects = [];
        foreach ($items as $row) {
            $pid = (string)$row['project']['id'];
            if (!isset($projects[$pid])) {
                $projects[$pid] = ['id' => $pid, 'name' => (string)$row['project']['name'], 'teamId' => (string)$row['team_id'], 'count' => 0];
            }
            $projects[$pid]['count']++;
        }
        usort($projects, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        $items = array_values(array_filter($items, static function (array $row) use ($types, $projectIds): bool {
            if ($types !== [] && !in_array($row['activityType'], $types, true)) {
                return false;
            }
            if ($projectIds !== [] && !in_array((string)$row['project']['id'], $projectIds, true)) {
                return false;
            }
            return true;
        }));
        usort($items, static fn (array $a, array $b): int => ($b['created_at'] <=> $a['created_at']) ?: strcmp($a['id'], $b['id']));

        $state = 'ok';
        $code  = null;
        if (in_array(OpenProjectException::AUTH_FAILED, $codes, true)) {
            $state = 'auth_required';
            $code  = OpenProjectException::AUTH_FAILED;
        } elseif ($covered === 0 && $skipped > 0) {
            $state = 'error';
            $code  = (string)end($codes);
        } elseif ($skipped > 0 || $budgetHit) {
            $state = 'partial';
            $code  = $codes !== [] ? (string)end($codes) : null;
        }

        return [
            'items'    => $items,
            'status'   => [
                'state'   => $state,
                'code'    => $code,
                'message' => $code !== null ? $this->messages->userMessage($code) : null,
                'covered' => $covered,
                'skipped' => $skipped,
            ],
            'projects' => array_values($projects),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Row construction
    // ─────────────────────────────────────────────────────────────────────

    /**
     * One work package as a feed row, or null when it says nothing (no
     * usable timestamp).
     *
     * @param array<string, mixed> $link
     * @param array<string, mixed> $wp   normalised, with `url`
     * @param array<int, bool>     $closed
     * @param list<int>            $milestoneTypes
     * @return ?array<string, mixed>
     */
    private function row(string $teamId, array $link, array $wp, array $closed, array $milestoneTypes, int $from, int $to): ?array {
        $updatedAt = $this->instant($wp['updatedAt'] ?? null);
        $createdAt = $this->instant($wp['createdAt'] ?? null);
        if ($updatedAt === null) {
            return null;
        }
        // The server-side window is bucketed to five minutes in the cache;
        // the exact bounds are applied here.
        if ($updatedAt < $from || ($to > 0 && $updatedAt > $to)) {
            return null;
        }

        $isClosed    = ($wp['statusId'] ?? null) !== null && ($closed[(int)$wp['statusId']] ?? false);
        $isMilestone = ($wp['typeId'] ?? null) !== null && in_array((int)$wp['typeId'], $milestoneTypes, true);
        $isNew       = $createdAt !== null && $createdAt >= $from && abs($updatedAt - $createdAt) <= self::CREATED_TOLERANCE;

        $type = match (true) {
            $isMilestone && $isClosed => self::TYPE_MILESTONE_COMPLETED,
            $isMilestone              => self::TYPE_MILESTONE_UPDATED,
            $isClosed                 => self::TYPE_COMPLETED,
            $isNew                    => self::TYPE_CREATED,
            default                   => self::TYPE_UPDATED,
        };

        $projectRef = (string)($link['projectIdentifier'] ?? '') !== ''
            ? (string)$link['projectIdentifier']
            : (string)(int)$link['projectId'];
        $connection = substr(md5((string)$link['host']), 0, 12);

        return [
            // Stable across loads and unique across connections; the
            // frontend keys the card on it.
            'id'           => 'op:' . $connection . ':' . (int)$link['projectId'] . ':' . (int)$wp['id'],
            'source'       => self::SOURCE,
            'activityType' => $type,
            'subject'      => (string)($wp['subject'] ?? ''),
            // Never a body: no description, no comment.
            'message'      => '',
            'created_at'   => $updatedAt,
            'team_id'      => $teamId,
            // Not a Nextcloud account — the card must not draw an avatar.
            'author_id'    => '',
            'actor_name'   => $type === self::TYPE_CREATED ? ($wp['author'] ?? null) : null,
            'project'      => [
                'id'   => (string)(int)$link['projectId'],
                'name' => (string)($link['projectName'] ?? ($wp['project']['name'] ?? '')),
                'url'  => $this->client->projectUrl($projectRef),
            ],
            'workPackage'  => [
                'id'             => (int)$wp['id'],
                'type'           => $wp['type'] ?? null,
                'status'         => $wp['status'] ?? null,
                'priority'       => $wp['priority'] ?? null,
                'assignee'       => $wp['assignee'] ?? null,
                'dueDate'        => $wp['dueDate'] ?? null,
                'date'           => $wp['date'] ?? null,
                'startDate'      => $wp['startDate'] ?? null,
                'percentageDone' => $wp['percentageDone'] ?? null,
                'isMilestone'    => $isMilestone,
                'isClosed'       => $isClosed,
                'createdAt'      => $createdAt,
                // Built from the configured host and the numeric id, never
                // from a response field.
                'url'            => $this->client->workPackageUrl((int)$wp['id']),
            ],
            'opens_externally' => true,
        ];
    }

    /**
     * The links of the viewer's teams that point at the configured host, in
     * a deterministic order (team id), so the cap and the budget cut the
     * same tail every time.
     *
     * @param string[] $teamIds
     * @return array<string, array{projectId: int, projectIdentifier: string, projectName: string, host: string}>
     */
    private function usableLinks(array $teamIds): array {
        $host = $this->client->getHost();
        $out  = [];
        try {
            foreach ($this->linkMapper->findByTeams($teamIds) as $teamId => $row) {
                if ($host === '' || rtrim($row->getHost(), '/') !== $host) {
                    continue;
                }
                $out[(string)$teamId] = [
                    'projectId'         => $row->getProjectId(),
                    'projectIdentifier' => $row->getProjectIdentifier(),
                    'projectName'       => $row->getProjectName(),
                    'host'              => $row->getHost(),
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][WhatsNew][OpenProject] link lookup failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    private function instant(?string $iso): ?int {
        if ($iso === null || $iso === '') {
            return null;
        }
        $ts = strtotime($iso);
        return $ts === false || $ts <= 0 ? null : $ts;
    }
}
