<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork\Provider;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\MyWork\ActionResult;
use OCA\TeamHub\MyWork\ActionType;
use OCA\TeamHub\MyWork\Category;
use OCA\TeamHub\MyWork\IWorkProvider;
use OCA\TeamHub\MyWork\OpenTarget;
use OCA\TeamHub\MyWork\Priority;
use OCA\TeamHub\MyWork\WorkItem;
use OCA\TeamHub\MyWork\WorkItemPage;
use OCA\TeamHub\MyWork\WorkQuery;
use OCA\TeamHub\Service\MyWorkConfigService;
use OCA\TeamHub\Service\OpenProject\OpenProjectClient;
use OCA\TeamHub\Service\OpenProject\OpenProjectReferenceService;
use OCA\TeamHub\Service\OpenProject\OpenProjectSyncHealth;
use OCA\TeamHub\Service\OpenProject\OpenProjectWorkPackageService;
use OCA\TeamHub\Service\OpenProject\TeamOpenProjectLinkService;
use OCA\TeamHub\Service\TimezoneService;
use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * My Work provider for OpenProject work packages (v4.9.5; Phase 3 in
 * v4.9.7).
 *
 * The viewer's OpenProject work in every project their teams are linked to,
 * one row per work package under the team the link belongs to. Personal
 * work belongs in the personal queue (Justin, 2026-09-12); the team home's
 * Upcoming tasks widget carries the project's.
 *
 * ## What a row is (Phase 3)
 *
 * Five bounded reads per linked project, each one OpenProject request as
 * the viewer, each surviving the others' failure:
 *
 *   assigned, dated      open, assigned to me, due on or before the Upcoming
 *                        horizon → overdue / due today / due in n days
 *   assigned, recent     open, assigned to me, changed in the last
 *                        RECENT_DAYS → "updated since your last visit",
 *                        "updated recently", "recently assigned to you".
 *                        The one read that carries **undated** work: a work
 *                        package somebody just touched earns a row whether
 *                        or not it has a deadline; undated work nobody has
 *                        touched stays in OpenProject (the 4.9.5 rule)
 *   authored, unassigned open, created by me, assigned to nobody → the
 *                        same rules as the two reads above, reason "Created
 *                        by you, not assigned" (Justin, 2026-09-14: a work
 *                        package you made and left unassigned is yours)
 *   assigned, completed  closed, assigned to me, changed in the Completed
 *                        window → Completed
 *   milestones           the project's open milestones dated inside the
 *                        Upcoming horizon, any assignee → Upcoming,
 *                        informational (never promoted to Action required:
 *                        a milestone is the project's, not a task of yours)
 *
 * Categories, priorities and reasons are derived from the row's own dates
 * in the **viewer's** zone and from OpenProject's own lists (a status is
 * closed because OpenProject says `isClosed`, a priority is high because it
 * ranks above the instance's default — `OpenProjectReferenceService`), never
 * from a label. The OpenProject status and priority labels still travel on
 * the row (`subtitle`, `metadata`), because a universal bucket must not
 * hide the source's own words.
 *
 * ## One row per work package
 *
 * The reads overlap (a dated work package changed yesterday is in two of
 * them) and, in theory, two teams could link one project (the unique index
 * `th_opl_proj_uq` says they cannot, since 4.9.4). Rows are keyed on
 * `host | project | work package` — never on the title — and collapsed to
 * one: the most urgent category wins, reasons merge by precedence, and the
 * **primary team is the first in alphabetical order of team name, then
 * team id** — a stable rule the viewer can predict; other teams travel in
 * `metadata.additionalTeamIds`, which the row renders as "+n other teams".
 *
 * ## Bounded, as the viewer, partial over blocking
 *
 * Every read runs as the calling user, so a row is a work package
 * OpenProject itself shows them; a viewer who has not connected their
 * account gets no rows and no nagging (My Work is not the place; the team
 * home is), while a viewer whose token OpenProject refuses gets the
 * `auth_required` warning, because that is fixable and they should know.
 * Projects are read in a deterministic order under a wall-clock budget of
 * BUDGET_MS and a cap of PROJECT_CAP: a slow OpenProject costs this
 * provider its tail, never the other providers their turn (ProviderRegistry
 * spends one budget across all of them). A project that answers with an
 * error is skipped and counted (`partial`); the rest of the queue stands.
 * Nothing here is ever shared between users: every cache key names the
 * viewer, the team, the project and the host.
 *
 * ## Open only
 *
 * The one action is OPEN, a hand-off to OpenProject in a new tab. TeamHub's
 * native snooze still re-reads the work package from OpenProject first
 * (`getItem()`), so a row a viewer may no longer see cannot be acted on.
 */
class OpenProjectWorkProvider implements IWorkProvider {

    public const ID = 'openproject';

    public const RESOURCE_TYPE           = 'openproject_work_package';
    public const RESOURCE_TYPE_MILESTONE = 'openproject_milestone';

    /** Attention states — the provider's `status`, for the filter and the admin mapping. */
    public const STATUS_ASSIGNED  = 'assigned';
    public const STATUS_OVERDUE   = 'overdue';
    public const STATUS_DUE_TODAY = 'due_today';
    public const STATUS_DUE_SOON  = 'due_soon';
    public const STATUS_UPDATED   = 'updated';
    public const STATUS_MILESTONE = 'milestone';
    public const STATUS_COMPLETED = 'completed';
    /** Created by the viewer and assigned to nobody (Justin, 2026-09-14). */
    public const STATUS_AUTHORED  = 'authored';

    public const STATUSES = [
        self::STATUS_OVERDUE, self::STATUS_DUE_TODAY, self::STATUS_DUE_SOON, self::STATUS_ASSIGNED,
        self::STATUS_AUTHORED, self::STATUS_UPDATED, self::STATUS_MILESTONE, self::STATUS_COMPLETED,
    ];

    /** "Recently" for updated / assigned work, in days. */
    public const RECENT_DAYS = 3;
    /** Most linked projects one fetch reads; the rest is reported as truncated. */
    public const PROJECT_CAP = 12;
    /** This provider's own wall-clock budget, well inside the registry's. */
    public const BUDGET_MS = 2500;
    /** The "last visit" checkpoint is moved forward at most this often. */
    private const CHECKPOINT_INTERVAL = 300;
    private const PREF_LAST_SEEN = 'mywork_openproject_last_seen';

    private ?string $unavailableReason = null;

    /**
     * Whether a fetch counts as a visit to My Work. The team widget's
     * attention summary reads through the same path but is not the viewer
     * looking at their queue, so it leaves the "last visit" checkpoint alone.
     */
    private bool $countsAsVisit = true;

    public function __construct(
        private OpenProjectClient             $client,
        private OpenProjectWorkPackageService $workPackages,
        private OpenProjectReferenceService   $reference,
        private TeamOpenProjectLinkService    $links,
        private OpenProjectSyncHealth         $health,
        private MyWorkConfigService           $config,
        private TimezoneService               $timezoneService,
        private IConfig                       $userConfig,
        private IL10N                         $l,
        private LoggerInterface               $logger,
    ) {
    }

    // ---------------------------------------------------------------------
    // Identity + capabilities
    // ---------------------------------------------------------------------

    public function getId(): string {
        return self::ID;
    }

    public function getName(): string {
        // TRANSLATORS: My Work source name — work packages from OpenProject
        return $this->l->t('OpenProject');
    }

    public function getIcon(): string {
        return 'openproject';
    }

    public function getCapabilities(): array {
        return [
            'actions'       => [ActionType::OPEN],
            'resourceTypes' => [self::RESOURCE_TYPE, self::RESOURCE_TYPE_MILESTONE],
            'statuses'      => self::STATUSES,
            'categories'    => [Category::ACTION_REQUIRED, Category::UPCOMING, Category::COMPLETED],
            'pagination'    => false,
            'incremental'   => false,
            // v4.9.7 — what the filter bar may offer for this source beyond
            // the common set. Informational, like getSupportedFilters().
            'facets'        => ['projectId', 'type'],
        ];
    }

    /**
     * The cheap check, deliberately — the same one the layout bundle makes:
     * the official app is enabled and has a host. Whether the *viewer* is
     * connected is decided per fetch, because that is per user and this
     * answer is shown on the admin status page.
     */
    public function isAvailable(): bool {
        // v4.9.16 — the module first, with its own reason: an administrator
        // reading the My Work status page must not be sent to install an app
        // when the fix is a licence or a switch in TeamHub itself.
        if (!$this->client->isModuleAvailable()) {
            // TRANSLATORS: why the OpenProject source is unavailable in My Work — TeamHub's own module, not the Nextcloud app
            $this->unavailableReason = $this->l->t('The OpenProject module is not licensed or not enabled in TeamHub administration settings.');
            return false;
        }
        if (!$this->client->isIntegrationEnabled()) {
            $this->unavailableReason = $this->l->t('The OpenProject integration app is not installed or not enabled.');
            return false;
        }
        if ($this->client->getHost() === '') {
            $this->unavailableReason = $this->l->t('No OpenProject host is configured in the integration app.');
            return false;
        }
        $this->unavailableReason = null;
        return true;
    }

    public function getUnavailableReason(): ?string {
        return $this->unavailableReason;
    }

    public function getSupportedFilters(): array {
        return ['teamIds', 'dueTo', 'completedSince', 'metadataFilters'];
    }

    public function getConfigSchema(): array {
        return [];
    }

    /**
     * Administrator diagnostics (picked up by `method_exists`, like the
     * approval provider's): the environment, the strategy, and the health
     * counters — never a viewer's rows.
     *
     * @return list<array{label: string, value: string}>
     */
    public function getDiagnostics(): array {
        $rows = [
            // v4.9.16 — the module's state is its own row; the app row reads
            // the app's own state, not the module's.
            ['label' => 'TeamHub module', 'value' => $this->client->isModuleAvailable() ? 'licensed, enabled' : 'unlicensed or disabled'],
            ['label' => 'Integration app', 'value' => $this->client->isIntegrationInstalled()
                ? ($this->client->isIntegrationAppEnabled() ? 'installed, enabled' : 'installed, disabled')
                : 'not installed'],
            ['label' => 'Host', 'value' => $this->client->getHost() !== '' ? 'configured' : 'not configured'],
            ['label' => 'Auth method', 'value' => $this->client->getAuthMethod()],
            ['label' => 'Reads per project', 'value' => 'assigned+dated, assigned+recent, authored+unassigned, assigned+completed, milestones (+ types, statuses, priorities cached 15 min)'],
            ['label' => 'Limits', 'value' => sprintf('%d projects per fetch, %d ms budget, cache %d s per viewer', self::PROJECT_CAP, self::BUDGET_MS, \OCA\TeamHub\Service\OpenProject\OpenProjectCache::TTL_MY_WORK)],
        ];
        return array_merge($rows, $this->health->diagnosticRows());
    }

    // ---------------------------------------------------------------------
    // Fetch
    // ---------------------------------------------------------------------

    public function fetchItems(WorkQuery $query): WorkItemPage {
        if ($query->teamIds === [] || !$this->isAvailable()) {
            return WorkItemPage::empty();
        }
        // Not connected: nothing to read as this person, and nothing to say —
        // see the class docblock.
        if (!$this->client->isUserConnected($query->userId)) {
            return WorkItemPage::empty();
        }

        $links = $this->usableLinks($query->teamIds, $query);
        if ($links === []) {
            return WorkItemPage::empty();
        }

        $startedAt = microtime(true);
        $userId    = $query->userId;
        $lastSeen  = $this->lastSeen($userId);

        // OpenProject's own vocabulary, once per fetch (cached 15 min per
        // viewer). Unreadable lists degrade to "unknown", never to no rows.
        $closed    = $this->reference->closedStatuses($userId);
        $ranks     = $this->reference->priorityRanks($userId);

        $today     = $this->timezoneService->today($userId, $query->now);
        $horizon   = $this->timezoneService->today($userId, $query->dueHorizon());

        /** @var array<string, WorkItem> $byKey host|project|wp → row */
        $byKey     = [];
        $truncated = false;
        $warnings  = [];
        $covered   = 0;
        $skipped   = 0;
        $codes     = [];
        $budgetHit = false;

        $seen = 0;
        foreach ($links as $teamId => $link) {
            if ($seen >= self::PROJECT_CAP) {
                $truncated = true;
                break;
            }
            if ((microtime(true) - $startedAt) * 1000 >= self::BUDGET_MS) {
                $budgetHit = true;
                $truncated = true;
                break;
            }
            $seen++;
            $projectId = (int)$link['projectId'];
            $host      = (string)$link['host'];
            $context   = [
                'teamId' => (string)$teamId, 'projectId' => $projectId, 'host' => $host,
                'projectName' => (string)$link['projectName'], 'projectRef' => $this->projectRef($link),
            ];

            $projectCodes = [];
            $reads = 0;

            // 1. Assigned, dated, inside the horizon.
            $page = $this->guarded($projectCodes, fn () => $this->workPackages->assignedDueBy(
                $userId, (string)$teamId, $projectId, $host, $horizon,
            ));
            if ($page !== null) {
                $reads++;
                if ($page['truncated']) {
                    $truncated = true;
                }
                foreach ($page['items'] as $wp) {
                    $this->merge($byKey, $this->buildItem($query, $context, $wp, $ranks, $lastSeen, $today, $horizon), $truncated);
                }
            }

            // 2. Assigned, changed recently — undated work included.
            $recent = $this->guarded($projectCodes, fn () => $this->workPackages->assignedRecentlyUpdated(
                $userId, (string)$teamId, $projectId, $host, self::RECENT_DAYS,
            ));
            if ($recent !== null) {
                $reads++;
                foreach ($recent['items'] as $wp) {
                    $this->merge($byKey, $this->buildItem($query, $context, $wp, $ranks, $lastSeen, $today, $horizon), $truncated);
                }
            }

            // 2b. Created by the viewer and assigned to nobody — theirs to act
            //     on, because nobody else will (Justin, 2026-09-14). The same
            //     horizon / recently-touched rules decide which get a row.
            $authored = $this->guarded($projectCodes, fn () => $this->workPackages->authoredUnassigned(
                $userId, (string)$teamId, $projectId, $host,
            ));
            if ($authored !== null) {
                $reads++;
                foreach ($authored['items'] as $wp) {
                    $this->merge($byKey, $this->buildItem($query, $context, $wp, $ranks, $lastSeen, $today, $horizon, authored: true), $truncated);
                }
            }

            // 3. Assigned, completed inside the retention window.
            if ($query->includeCompleted && $query->wantsCategory(Category::COMPLETED)) {
                $done = $this->guarded($projectCodes, fn () => $this->workPackages->assignedCompletedSince(
                    $userId, (string)$teamId, $projectId, $host, max(1, $query->completedDays),
                ));
                if ($done !== null) {
                    $reads++;
                    foreach ($done['items'] as $wp) {
                        $this->merge($byKey, $this->buildCompleted($query, $context, $wp, $ranks), $truncated);
                    }
                }
            }

            // 4. The project's milestones inside the horizon, any assignee.
            if ($query->wantsCategory(Category::UPCOMING)) {
                $milestones = $this->guarded($projectCodes, function () use ($userId, $teamId, $projectId, $host, $today, $horizon): array {
                    $typeIds = $this->reference->milestoneTypeIds($userId, $projectId);
                    return $typeIds === [] ? [] : $this->workPackages->upcomingMilestones(
                        $userId, (string)$teamId, $projectId, $host, $today, $horizon, $typeIds,
                    );
                });
                if ($milestones !== null) {
                    $reads++;
                    foreach ($milestones as $wp) {
                        $this->merge($byKey, $this->buildMilestone($query, $context, $wp, $today), $truncated);
                    }
                }
            }

            if ($reads === 0) {
                // Nothing at all could be read for this project.
                $skipped++;
            } else {
                $covered++;
                if ($projectCodes !== []) {
                    $warnings[] = WorkItemPage::WARN_PARTIAL;
                }
            }
            foreach ($projectCodes as $code) {
                $codes[] = $code;
                if ($code === OpenProjectException::AUTH_FAILED) {
                    $warnings[] = WorkItemPage::WARN_AUTH_REQUIRED;
                } elseif ($reads === 0) {
                    $warnings[] = WorkItemPage::WARN_PARTIAL;
                }
            }
            // A refused token is refused for every project; stop asking.
            if (in_array(OpenProjectException::AUTH_FAILED, $projectCodes, true)) {
                break;
            }
        }

        if ($budgetHit) {
            $warnings[] = WorkItemPage::WARN_BUDGET;
        }

        $this->health->recordRun(OpenProjectSyncHealth::CHANNEL_MY_WORK, $covered, $skipped, $codes, $budgetHit);
        if ($this->countsAsVisit) {
            $this->moveCheckpoint($userId, $lastSeen, $query->now);
        }

        $items = array_values($byKey);
        if (count($items) > $query->perProviderCap) {
            $truncated = true;
            usort($items, static fn (WorkItem $a, WorkItem $b): int =>
                Category::rank($a->category) <=> Category::rank($b->category)
                ?: Priority::rank($a->priority) <=> Priority::rank($b->priority)
                ?: ($a->dueAt ?? PHP_INT_MAX) <=> ($b->dueAt ?? PHP_INT_MAX));
            $items = array_slice($items, 0, $query->perProviderCap);
        }

        return new WorkItemPage($items, count($items), $truncated, array_values(array_unique($warnings)));
    }

    /**
     * Re-read from source. The id is `{teamId}/{workPackageId}`; the team
     * must be one the caller may see, linked, and the work package must
     * still be in that team's project — a work package moved to another
     * project is not this team's row any more. This is the authorisation
     * step before any native action, and it never consults a cache.
     */
    public function getItem(string $userId, string $providerItemId, array $allowedTeamIds): ?WorkItem {
        [$teamId, $wpId] = array_pad(explode('/', $providerItemId, 2), 2, '');
        $wpId = (int)$wpId;
        if ($teamId === '' || $wpId <= 0 || !in_array($teamId, $allowedTeamIds, true)) {
            return null;
        }
        if (!$this->isAvailable() || !$this->client->isUserConnected($userId)) {
            return null;
        }

        try {
            $link = $this->links->linksForTeams([$teamId])[$teamId] ?? null;
            if ($link === null || $link['stale']) {
                return null;
            }
            $wp = $this->workPackages->workPackage($userId, $wpId);
        } catch (OpenProjectException $e) {
            $this->logger->debug('[TeamHub][MyWork][OpenProject] item re-read refused', [
                'teamId' => $teamId, 'code' => $e->getErrorCode(), 'app' => Application::APP_ID,
            ]);
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][OpenProject] item re-read failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
        if (($wp['project']['id'] ?? 0) !== (int)$link['projectId']) {
            return null;
        }

        $now     = time();
        $query   = new WorkQuery(
            userId: $userId, teamIds: $allowedTeamIds, now: $now,
            upcomingDays: $this->config->getUpcomingDays(), completedDays: $this->config->getCompletedDays(),
        );
        $context = [
            'teamId' => $teamId, 'projectId' => (int)$link['projectId'], 'host' => (string)$link['host'],
            'projectName' => (string)$link['projectName'], 'projectRef' => $this->projectRef($link),
        ];
        $today   = $this->timezoneService->today($userId, $now);
        $horizon = $this->timezoneService->today($userId, $query->dueHorizon());
        $closed  = $this->reference->closedStatuses($userId);
        $ranks   = $this->reference->priorityRanks($userId);

        $statusId = $wp['statusId'] ?? null;
        if ($statusId !== null && ($closed[$statusId] ?? false)) {
            return $this->buildCompleted($query, $context, $wp, $ranks);
        }
        // No horizon on a re-read: the row was listed under whatever horizon
        // the real query had, and a snoozed item may be due well beyond it.
        // Unassigned means it was listed as the viewer's own creation.
        return $this->buildItem(
            $query, $context, $wp, $ranks, $this->lastSeen($userId), $today, $horizon,
            applyHorizon: false, authored: ($wp['assigneeId'] ?? null) === null,
        );
    }

    // ---------------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------------

    public function getAvailableActions(string $userId, WorkItem $item): array {
        return [ActionType::OPEN];
    }

    /** Nothing here acts on OpenProject; the row is a hand-off. */
    public function executeAction(string $userId, WorkItem $item, string $action, array $params): ActionResult {
        return ActionResult::unsupported($this->l->t('Work packages are changed in OpenProject.'));
    }

    // ---------------------------------------------------------------------
    // Team attention summary (Phase 3, optional enhancement)
    // ---------------------------------------------------------------------

    /**
     * The compact "your attention" block the Project info widget shows for
     * one team: the same provider read, over one team, summarised — no
     * second fetching path. Membership is the caller's check.
     *
     * @return array{
     *   overdue: int, dueToday: int, dueThisWeek: int, updatedRecently: int,
     *   nextMilestone: ?array{id: int, subject: string, date: ?string, url: ?string, daysUntil: ?int},
     *   upcomingDays: int, warnings: list<string>, retrievedAt: int
     * }
     */
    public function attentionSummary(string $userId, string $teamId, string $teamName = ''): array {
        $now   = time();
        $query = new WorkQuery(
            userId: $userId, teamIds: [$teamId], teamNames: [$teamId => $teamName], now: $now,
            upcomingDays: $this->config->getUpcomingDays(),
            completedDays: $this->config->getCompletedDays(),
            actionRequiredDays: $this->config->getActionRequiredDays(),
            includeCompleted: false,
        );
        $this->countsAsVisit = false;
        try {
            $page = $this->fetchItems($query);
        } finally {
            $this->countsAsVisit = true;
        }

        $overdue = $dueToday = $dueWeek = $updated = 0;
        $nextMilestone = null;
        foreach ($page->items as $item) {
            if ($item->resourceType === self::RESOURCE_TYPE_MILESTONE) {
                $date = (string)($item->metadata['dueDate'] ?? '');
                if ($nextMilestone === null || $date < (string)$nextMilestone['date']) {
                    $nextMilestone = [
                        'id'        => (int)$item->metadata['workPackageId'],
                        'subject'   => $item->title,
                        'date'      => $date !== '' ? $date : null,
                        'url'       => $item->resourceUrl !== '' ? $item->resourceUrl : null,
                        'daysUntil' => $item->metadata['daysUntil'] ?? null,
                    ];
                }
                continue;
            }
            if ($item->category === Category::COMPLETED) {
                continue;
            }
            switch ($item->status) {
                case self::STATUS_OVERDUE:
                    $overdue++;
                    break;
                case self::STATUS_DUE_TODAY:
                    $dueToday++;
                    $dueWeek++;
                    break;
                case self::STATUS_DUE_SOON:
                    $dueWeek++;
                    break;
                default:
                    if (in_array(self::STATUS_UPDATED, (array)($item->metadata['reasons'] ?? []), true)) {
                        $updated++;
                    }
            }
        }

        $links = $this->usableLinks([$teamId], $query);
        $ref   = isset($links[$teamId]) ? $this->projectRef($links[$teamId]) : '';

        return [
            'overdue'         => $overdue,
            'dueToday'        => $dueToday,
            'dueThisWeek'     => $dueWeek,
            'updatedRecently' => $updated,
            'nextMilestone'   => $nextMilestone,
            'upcomingDays'    => $query->upcomingDays,
            'warnings'        => $page->warnings,
            'urls'            => [
                'myWorkPackages' => $ref !== '' ? $this->client->myWorkPackagesUrl($ref) : null,
                'project'        => $ref !== '' ? $this->client->projectUrl($ref) : null,
            ],
            'retrievedAt'     => $now,
        ];
    }

    // ---------------------------------------------------------------------
    // Row construction
    // ---------------------------------------------------------------------

    /**
     * One open, assigned work package as a row. Null for one this provider
     * does not carry: undated and not recently touched, or due beyond the
     * horizon and not recently touched — unless `$applyHorizon` is off,
     * which `getItem()` uses because its re-read has no query horizon.
     *
     * @param array{teamId: string, projectId: int, host: string, projectName: string, projectRef: string} $context
     * @param array<string, mixed> $wp a normalised work package with `url`
     * @param array<int, string>   $ranks
     */
    private function buildItem(
        WorkQuery $query,
        array $context,
        array $wp,
        array $ranks,
        int $lastSeen,
        string $today,
        string $horizon,
        bool $applyHorizon = true,
        bool $authored = false,
    ): ?WorkItem {
        $userId    = $query->userId;
        $dueDate   = $wp['dueDate'] ?? null;
        $dueAt     = $this->dueAt($userId, $dueDate);
        $updatedAt = $this->instant($wp['updatedAt'] ?? null);
        $createdAt = $this->instant($wp['createdAt'] ?? null);

        $recentSince    = $query->now - self::RECENT_DAYS * 86400;
        $recentlyTouched = $updatedAt !== null && $updatedAt >= $recentSince;
        $recentlyMade    = $createdAt !== null && $createdAt >= $recentSince;
        $sinceVisit      = $updatedAt !== null && $lastSeen > 0 && $updatedAt > $lastSeen;

        $overdue  = $dueAt !== null && $dueAt < $query->now;
        $dueToday = $dueDate !== null && $dueDate === $today;
        $inWindow = $dueDate !== null && $dueDate <= $horizon;

        if ($applyHorizon && !$overdue && !$inWindow && !$recentlyTouched) {
            return null;
        }

        // Reasons by precedence; the first is the row's sentence, all of them
        // ride in metadata so a merged row keeps every fact.
        $reasons = [];
        $status  = $authored ? self::STATUS_AUTHORED : self::STATUS_ASSIGNED;
        $reason  = $authored
            // TRANSLATORS: My Work reason on an OpenProject work package the viewer created and nobody is assigned to
            ? $this->l->t('Created by you, not assigned')
            : $this->l->t('Assigned to you');

        if ($overdue) {
            $days   = $this->daysBetween($dueDate ?? $today, $today);
            $status = self::STATUS_OVERDUE;
            $reason = $this->l->n('Overdue by %n day', 'Overdue by %n days', max(1, $days));
            $reasons[] = self::STATUS_OVERDUE;
        } elseif ($dueToday) {
            $status = self::STATUS_DUE_TODAY;
            $reason = $this->l->t('Due today');
            $reasons[] = self::STATUS_DUE_TODAY;
        } elseif ($inWindow) {
            $days   = $this->daysBetween($today, $dueDate ?? $today);
            $status = self::STATUS_DUE_SOON;
            $reason = $days === 1
                ? $this->l->t('Due tomorrow')
                : $this->l->n('Due in %n day', 'Due in %n days', max(1, $days));
            $reasons[] = self::STATUS_DUE_SOON;
        }

        if ($authored) {
            $reasons[] = self::STATUS_AUTHORED;
        }
        if ($recentlyMade) {
            $reasons[] = $authored ? self::STATUS_AUTHORED : self::STATUS_ASSIGNED;
            if ($status === self::STATUS_ASSIGNED) {
                $reason = $this->l->t('Recently assigned to you');
            }
        } elseif ($recentlyTouched) {
            $reasons[] = self::STATUS_UPDATED;
            if ($status === self::STATUS_ASSIGNED) {
                $status = self::STATUS_UPDATED;
                $reason = $sinceVisit
                    ? $this->l->t('Updated since your last visit')
                    : $this->l->t('Updated recently');
            }
        }
        if ($reasons === []) {
            $reasons[] = self::STATUS_ASSIGNED;
        }
        $reasons = array_values(array_unique($reasons));

        // The actionable band ("N days before its due date", Admin → My
        // Work) in whole days. A work package's deadline is a date, and
        // MyWorkService measures the band from the deadline's *instant* —
        // the end of that day in the viewer's zone — so a work package due
        // the day after tomorrow said "Due in 2 days" and sat under Upcoming
        // with the band set to 2 (Justin's review, 2026-09-14). Decided
        // here, where the deadline is still a date, the row and the setting
        // agree; the service only ever promotes, so it never undoes this.
        $inBand = !$overdue && $dueDate !== null && $query->actionRequiredDays > 0
            && $this->daysBetween($today, $dueDate) <= $query->actionRequiredDays;

        $category = ($overdue || $inBand) ? Category::ACTION_REQUIRED : Category::UPCOMING;
        $priority = $this->priority($wp, $ranks);
        if ($overdue) {
            $priority = Priority::URGENT;
        } elseif ($inBand && Priority::rank(Priority::HIGH) < Priority::rank($priority)) {
            $priority = Priority::HIGH;
        }

        return WorkItem::make(array_merge($this->common($query, $context, $wp), [
            'category'    => $category,
            'resourceType' => self::RESOURCE_TYPE,
            'priority'    => $priority,
            'status'      => $status,
            'reason'      => $reason,
            'dueAt'       => $dueAt,
            'completedAt' => null,
            'metadata'    => array_merge($this->commonMetadata($context, $wp), [
                'reasons'          => array_values(array_unique($reasons)),
                'updatedSinceVisit' => $sinceVisit,
            ]),
        ]));
    }

    /**
     * A closed, assigned work package as a Completed row. `updatedAt` stands
     * in for the completion time — the closest the resource offers.
     *
     * @param array<string, mixed> $wp
     * @param array<int, string>   $ranks
     */
    private function buildCompleted(WorkQuery $query, array $context, array $wp, array $ranks): WorkItem {
        return WorkItem::make(array_merge($this->common($query, $context, $wp), [
            'category'     => Category::COMPLETED,
            'resourceType' => self::RESOURCE_TYPE,
            'priority'     => $this->priority($wp, $ranks),
            'status'       => self::STATUS_COMPLETED,
            // TRANSLATORS: My Work reason on a closed OpenProject work package
            'reason'       => $this->l->t('Completed in OpenProject'),
            'dueAt'        => $this->dueAt($query->userId, $wp['dueDate'] ?? null),
            'completedAt'  => $this->instant($wp['updatedAt'] ?? null),
            'metadata'     => array_merge($this->commonMetadata($context, $wp), [
                'reasons' => [self::STATUS_COMPLETED],
            ]),
        ]));
    }

    /**
     * A milestone of the project as an informational Upcoming row. Never
     * promoted (MyWorkService skips `informational` rows), NORMAL priority
     * whatever OpenProject says: it is the project's date, not your task.
     *
     * @param array<string, mixed> $wp
     */
    private function buildMilestone(WorkQuery $query, array $context, array $wp, string $today): ?WorkItem {
        $date  = $wp['date'] ?? $wp['dueDate'] ?? null;
        $dueAt = $this->dueAt($query->userId, $date);
        if ($dueAt === null) {
            return null;
        }
        $days   = $this->daysBetween($today, (string)$date);
        $reason = match (true) {
            $days <= 0 => $this->l->t('Milestone today'),
            $days === 1 => $this->l->t('Milestone tomorrow'),
            default    => $this->l->n('Milestone in %n day', 'Milestone in %n days', $days),
        };
        return WorkItem::make(array_merge($this->common($query, $context, $wp), [
            'category'     => Category::UPCOMING,
            'resourceType' => self::RESOURCE_TYPE_MILESTONE,
            'priority'     => Priority::NORMAL,
            'status'       => self::STATUS_MILESTONE,
            'reason'       => $reason,
            'dueAt'        => $dueAt,
            'completedAt'  => null,
            'assignee'     => null,
            'metadata'     => array_merge($this->commonMetadata($context, $wp), [
                'reasons'       => [self::STATUS_MILESTONE],
                'informational' => true,
                'dueDate'       => (string)$date,
                'daysUntil'     => max(0, $days),
            ]),
        ]));
    }

    /**
     * The fields every OpenProject row shares.
     *
     * @return array<string, mixed>
     */
    private function common(WorkQuery $query, array $context, array $wp): array {
        $teamId   = (string)$context['teamId'];
        $subtitle = array_values(array_filter([
            $wp['type']   ?? null,
            $wp['status'] ?? null,
            $context['projectName'] !== '' ? $context['projectName'] : ($wp['project']['name'] ?? null),
        ], static fn ($v): bool => is_string($v) && $v !== ''));

        return [
            'providerId'       => self::ID,
            'providerItemId'   => $teamId . '/' . (int)$wp['id'],
            'teamId'           => $teamId,
            'teamName'         => $query->teamName($teamId),
            'title'            => (string)($wp['subject'] ?? ''),
            'subtitle'         => implode(' · ', $subtitle),
            'resourceId'       => (string)(int)$wp['id'],
            // Absolute, on the administrator's OpenProject host — built by
            // OpenProjectClient from the configured host and the numeric id,
            // never from a response field. The frontend opens an absolute URL
            // as it is.
            'resourceUrl'      => (string)($wp['url'] ?? ''),
            'openTarget'       => OpenTarget::external(),
            'createdAt'        => $this->instant($wp['createdAt'] ?? null),
            'updatedAt'        => $this->instant($wp['updatedAt'] ?? null),
            // OpenProject names the assignee; it is not a Nextcloud account,
            // so no `uid` — the frontend would try to draw an avatar for it.
            'assignee'         => null,
            'waitingFor'       => null,
            'availableActions' => [],
            'permissions'      => ['canOpen' => ($wp['url'] ?? null) !== null],
        ];
    }

    /** @return array<string, mixed> */
    private function commonMetadata(array $context, array $wp): array {
        return [
            'workPackageId'       => (int)$wp['id'],
            // The OpenProject connection this row came from — a hash of the
            // configured host, never the host itself on the wire. With the
            // project and work-package ids it is the dedupe key.
            'connection'          => substr(md5((string)$context['host']), 0, 12),
            'projectId'           => (string)(int)$context['projectId'],
            'projectName'         => (string)$context['projectName'],
            'projectUrl'          => $this->client->projectUrl((string)$context['projectRef']),
            'type'                => (string)($wp['type'] ?? ''),
            'openProjectStatus'   => (string)($wp['status'] ?? ''),
            'openProjectPriority' => (string)($wp['priority'] ?? ''),
            'assigneeName'        => (string)($wp['assignee'] ?? ''),
            'dueDate'             => (string)($wp['dueDate'] ?? ''),
            'startDate'           => (string)($wp['startDate'] ?? ''),
            'percentageDone'      => $wp['percentageDone'] ?? null,
            'opensIn'             => 'OpenProject',
            'opensInOpenProject'  => true,
        ];
    }

    /**
     * Collapse a new row into the map: same work package → one row, the
     * more urgent category winning, reasons merged. A row from another team
     * for the same work package keeps the first team (alphabetical order —
     * see the class docblock) and records the other.
     *
     * @param array<string, WorkItem> $byKey
     */
    private function merge(array &$byKey, ?WorkItem $item, bool &$truncated): void {
        if ($item === null) {
            return;
        }
        $key = $item->metadata['connection'] . '|' . $item->metadata['projectId'] . '|' . $item->metadata['workPackageId'];
        $existing = $byKey[$key] ?? null;
        if ($existing === null) {
            $byKey[$key] = $item;
            return;
        }

        $reasons = array_values(array_unique(array_merge(
            (array)($existing->metadata['reasons'] ?? []),
            (array)($item->metadata['reasons'] ?? []),
        )));
        $additional = (array)($existing->metadata['additionalTeamIds'] ?? []);
        if ($item->teamId !== $existing->teamId && !in_array($item->teamId, $additional, true)) {
            $additional[] = $item->teamId;
        }

        $winner = $existing;
        if ($item->teamId === $existing->teamId && $this->moreUrgent($item, $existing)) {
            $winner = $item;
        }
        $byKey[$key] = $winner->with(metadata: [
            'reasons'           => $reasons,
            'additionalTeamIds' => $additional,
            'updatedSinceVisit' => (bool)($existing->metadata['updatedSinceVisit'] ?? false)
                || (bool)($item->metadata['updatedSinceVisit'] ?? false),
        ]);
    }

    private function moreUrgent(WorkItem $a, WorkItem $b): bool {
        $ca = Category::rank($a->category);
        $cb = Category::rank($b->category);
        if ($ca !== $cb) {
            return $ca < $cb;
        }
        $sa = array_search($a->status, self::STATUSES, true);
        $sb = array_search($b->status, self::STATUSES, true);
        return ($sa === false ? PHP_INT_MAX : $sa) < ($sb === false ? PHP_INT_MAX : $sb);
    }

    /**
     * Only teams with a usable link cost a request, in a deterministic order
     * (team name, then id) so the budget and the primary-team rule both
     * resolve the same way on every fetch.
     *
     * @param string[] $teamIds
     * @return array<string, array<string, mixed>>
     */
    private function usableLinks(array $teamIds, WorkQuery $query): array {
        try {
            $all = $this->links->linksForTeams($teamIds);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][OpenProject] link lookup failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
        $links = [];
        foreach ($all as $teamId => $link) {
            if (!$link['stale']) {
                $links[(string)$teamId] = $link;
            }
        }
        uksort($links, static function (string $a, string $b) use ($query): int {
            return strcasecmp($query->teamName($a), $query->teamName($b)) ?: strcmp($a, $b);
        });
        return $links;
    }

    /**
     * Run one read; on failure record the code and answer null so the other
     * reads of the project — and the other projects — still happen.
     *
     * @param list<string> $codes
     * @return array<string, mixed>|null
     */
    private function guarded(array &$codes, callable $read): ?array {
        try {
            return $read();
        } catch (OpenProjectException $e) {
            // Debug, not warning: "no longer visible to this user" is a
            // normal answer, and the team home's widget reports it.
            $this->logger->debug('[TeamHub][MyWork][OpenProject] project read skipped', [
                'code' => $e->getErrorCode(), 'app' => Application::APP_ID,
            ]);
            $codes[] = $e->getErrorCode();
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][OpenProject] project read failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            $codes[] = OpenProjectException::TEMPORARY_FAILURE;
            return null;
        }
    }

    /** @param array<int, string> $ranks */
    private function priority(array $wp, array $ranks): string {
        $id = $wp['priorityId'] ?? null;
        return $id !== null && isset($ranks[(int)$id]) ? $ranks[(int)$id] : Priority::NORMAL;
    }

    private function projectRef(array $link): string {
        $identifier = (string)($link['projectIdentifier'] ?? '');
        return $identifier !== '' ? $identifier : (string)(int)($link['projectId'] ?? 0);
    }

    // ---------------------------------------------------------------------
    // The "last visit" checkpoint
    // ---------------------------------------------------------------------

    /** When this viewer's My Work last read OpenProject, or 0. */
    private function lastSeen(string $userId): int {
        return (int)$this->userConfig->getUserValue($userId, Application::APP_ID, self::PREF_LAST_SEEN, '0');
    }

    /**
     * Move the checkpoint forward, at most every CHECKPOINT_INTERVAL: two
     * loads a minute apart are one visit, and "updated since your last
     * visit" must not flip to "updated recently" between them.
     */
    private function moveCheckpoint(string $userId, int $lastSeen, int $now): void {
        if ($now - $lastSeen < self::CHECKPOINT_INTERVAL) {
            return;
        }
        try {
            $this->userConfig->setUserValue($userId, Application::APP_ID, self::PREF_LAST_SEEN, (string)$now);
        } catch (\Throwable) {
            // A checkpoint that cannot be written costs one reason's wording.
        }
    }

    // ---------------------------------------------------------------------
    // Dates
    // ---------------------------------------------------------------------

    /**
     * A due *date* as the instant its day ends in the viewer's zone — the
     * last second of that day, so a work package due today is not overdue
     * at breakfast. Deck stores an instant; OpenProject stores a date, and
     * the difference is exactly this line. Day bounds come from the zone's
     * own calendar (`TimezoneService::dayBounds`), so a DST day of 23 or 25
     * hours ends where it ends.
     */
    private function dueAt(string $userId, ?string $date): ?int {
        if ($date === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        try {
            [, $nextDayStart] = $this->timezoneService->dayBounds($date, $userId);
            return $nextDayStart - 1;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Whole calendar days from `$from` to `$to` (both `Y-m-d`); zone-free by construction. */
    private function daysBetween(string $from, string $to): int {
        try {
            $a = new \DateTimeImmutable($from, new \DateTimeZone('UTC'));
            $b = new \DateTimeImmutable($to, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return 0;
        }
        $diff = $a->diff($b);
        return (int)$diff->days * ($diff->invert === 1 ? -1 : 1);
    }

    private function instant(?string $iso): ?int {
        if ($iso === null || $iso === '') {
            return null;
        }
        $ts = strtotime($iso);
        return $ts === false || $ts <= 0 ? null : $ts;
    }
}
