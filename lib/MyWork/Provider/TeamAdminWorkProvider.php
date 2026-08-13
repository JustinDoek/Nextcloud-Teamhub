<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork\Provider;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\TeamAppResource;
use OCA\TeamHub\Db\TeamAppResourceMapper;
use OCA\TeamHub\MyWork\ActionResult;
use OCA\TeamHub\MyWork\ActionType;
use OCA\TeamHub\MyWork\Category;
use OCA\TeamHub\MyWork\IWorkProvider;
use OCA\TeamHub\MyWork\OpenTarget;
use OCA\TeamHub\MyWork\Priority;
use OCA\TeamHub\MyWork\WorkItem;
use OCA\TeamHub\MyWork\WorkItemPage;
use OCA\TeamHub\MyWork\WorkQuery;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\ResourceDiscoveryService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * My Work provider for team-administration housekeeping (v4.5.45).
 *
 * The first — and so far only — thing it surfaces is **resources pending
 * review**: someone connected a Deck board, calendar, folder or conversation
 * to a team, and a team admin has to accept or ignore it. That queue already
 * existed, on the Team info widget and in Manage team → Integrations; what it
 * did not have was a place in the one list a person actually works from.
 *
 * ## Why this is its own provider and its own category
 *
 * Every other My Work item is the viewer's own work: assigned to them, waiting
 * on them, proposed by them. This is not — it is work they have because of a
 * *role* they hold in a team. Filing it under Action required would have put
 * "someone connected a folder" beside "this approval is overdue", and the two
 * are not the same kind of urgent. `Category::TEAM_ADMIN` ranks below Waiting
 * for others precisely so it never outranks a deadline.
 *
 * ## No due dates
 *
 * A pending resource has no deadline — nothing expires, nothing escalates. So
 * every item carries `dueAt = null`, which also keeps these rows out of the
 * Today lens and out of any date-bounded query (`applyFilters` drops undated
 * items whenever either bound is set). That is the correct behaviour: a date
 * filter is a question about deadlines, and these have none.
 *
 * ## Admin-only, checked per team
 *
 * `ResourceStateController` requires team admin for accept/ignore/dismiss, so
 * a non-admin seeing these rows would be looking at buttons that 403. The team
 * list is therefore narrowed to teams where the viewer is an admin, in the
 * provider rather than in the UI — SKILLS.md § Permissions, and the same
 * reason `DecisionWorkProvider` resolves approver sets server-side.
 */
class TeamAdminWorkProvider implements IWorkProvider {

    public const ID = 'teamadmin';

    /** A resource awaiting an admin's accept/ignore. */
    private const RESOURCE_TYPE = 'team_resource';

    /** A person waiting to be let into the team (v4.6.17). */
    private const TYPE_JOIN_REQUEST = 'team_join_request';

    /** Source status for a resource awaiting an admin's accept/ignore. */
    public const STATUS_PENDING_REVIEW = 'resource_pending_review';

    /** Source status for a person's pending request to join (v4.6.17). */
    public const STATUS_JOIN_REQUESTED = 'join_requested';

    /**
     * First segment of a join request's `providerItemId`.
     *
     * A resource's id is `{teamId}:{appId}:{resourceId}`, and a join request's
     * is `joinreq:{teamId}:{uid}` — same three-segment split, told apart by this
     * marker rather than by counting. `joinreq` cannot collide with a team id:
     * Circles ids are 31 characters.
     */
    private const JOIN_PREFIX = 'joinreq';

    /**
     * Rows per request across all teams.
     *
     * A team with fifty unreviewed resources has a discovery problem, not a
     * My Work problem, and listing all fifty would bury every other category.
     */
    private const MAX_ITEMS = 50;

    private ?string $unavailableReason = null;

    public function __construct(
        private TeamAppResourceMapper    $resourceMapper,
        private ResourceDiscoveryService $discoveryService,
        private MemberService            $memberService,
        private IL10N                    $l,
        private LoggerInterface          $logger,
    ) {
    }

    // ---------------------------------------------------------------------
    // Identity + capabilities
    // ---------------------------------------------------------------------

    public function getId(): string {
        return self::ID;
    }

    public function getName(): string {
        // TRANSLATORS: My Work source name — team administration housekeeping
        return $this->l->t('Team admin');
    }

    public function getIcon(): string {
        return 'teamadmin';
    }

    public function getCapabilities(): array {
        return [
            // Accept and Ignore are the two real verbs. They are mapped onto
            // APPROVE/REJECT rather than given new ActionTypes: the vocabulary
            // is meant to be shared across providers, and "accept this into
            // the team" / "keep it out" is exactly what those two already mean
            // everywhere else. The row's own labels come from the item.
            'actions' => [
                ActionType::OPEN,
                ActionType::APPROVE,
                ActionType::REJECT,
            ],
            'resourceTypes' => [self::RESOURCE_TYPE, self::TYPE_JOIN_REQUEST],
            'statuses'      => [self::STATUS_PENDING_REVIEW, self::STATUS_JOIN_REQUESTED],
            'categories'    => [Category::TEAM_ADMIN],
            'pagination'    => false,
            'incremental'   => false,
        ];
    }

    /**
     * Always available: the resource registry is TeamHub's own table and has
     * no optional dependency behind it. A team with no pending rows simply
     * returns nothing, which is not the same as being unavailable.
     */
    public function isAvailable(): bool {
        $this->unavailableReason = null;
        return true;
    }

    public function getUnavailableReason(): ?string {
        return $this->unavailableReason;
    }

    public function getSupportedFilters(): array {
        return ['teamIds'];
    }

    public function getConfigSchema(): array {
        return [];
    }

    // ---------------------------------------------------------------------
    // Fetch
    // ---------------------------------------------------------------------

    public function fetchItems(WorkQuery $query): WorkItemPage {
        if ($query->teamIds === []) {
            return WorkItemPage::empty();
        }

        // Admin teams only — see the class docblock.
        $teamIds = array_values(array_filter(
            $query->teamIds,
            fn (string $teamId): bool => $this->isTeamAdmin($teamId),
        ));
        if ($teamIds === []) {
            return WorkItemPage::empty();
        }

        $items     = [];
        $truncated = false;

        foreach ($teamIds as $teamId) {
            if (count($items) >= self::MAX_ITEMS) {
                $truncated = true;
                break;
            }

            try {
                $rows = $this->resourceMapper->findPendingByTeam($teamId);
            } catch (\Throwable $e) {
                // One unreadable team must not empty the whole category.
                $this->logger->warning('[TeamHub][MyWork][TeamAdmin] pending lookup failed', [
                    'teamId' => $teamId, 'error' => $e->getMessage(),
                    'app' => Application::APP_ID,
                ]);
                continue;
            }

            foreach ($rows as $row) {
                if (count($items) >= self::MAX_ITEMS) {
                    $truncated = true;
                    break;
                }

                // v4.5.41 — a pending row whose resource is gone or detached
                // is not a decision anybody should be asked to make. The panel
                // offers Dismiss for those; a queue row cannot, so they are
                // simply not listed. `unknown` still lists, same asymmetry as
                // everywhere else this verdict is used.
                $availability = $this->availabilityOf($teamId, $row);
                if ($availability === ResourceDiscoveryService::AVAILABILITY_GONE
                    || $availability === ResourceDiscoveryService::AVAILABILITY_DETACHED
                ) {
                    continue;
                }

                $items[] = $this->buildItem($query, $teamId, $row);
            }

            // v4.6.17 — people waiting to be let in, listed beside the
            // resources waiting to be reviewed. Until now a join request
            // reached its team's admins as a notification and nowhere else: a
            // notification is read once and then gone, so a request that was
            // not acted on the moment it arrived left no trace anybody worked
            // from. The person is still waiting either way.
            //
            // Second in the loop deliberately — a pending resource is quieter
            // than a pending person, but the resource rows were here first and
            // reordering them per team would make the list jump for no reason.
            // Priority is what separates them, not position.
            if (count($items) >= self::MAX_ITEMS) {
                $truncated = true;
                break;
            }

            try {
                $requests = $this->memberService->getPendingRequests($teamId);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][MyWork][TeamAdmin] join-request lookup failed', [
                    'teamId' => $teamId, 'error' => $e->getMessage(),
                    'app' => Application::APP_ID,
                ]);
                continue;
            }

            foreach ($requests as $request) {
                if (count($items) >= self::MAX_ITEMS) {
                    $truncated = true;
                    break;
                }
                $items[] = $this->buildJoinRequestItem($query, $teamId, $request);
            }
        }

        return new WorkItemPage($items, count($items), $truncated);
    }

    public function getItem(string $userId, string $itemId, array $allowedTeamIds): ?WorkItem {
        // itemId is "{teamId}:{appId}:{resourceId}" — see buildItem(). Split
        // from the right twice so a resource id containing a colon (a Talk
        // token never does, a future provider's might) survives the round trip.
        $parts = explode(':', $itemId, 3);
        if (count($parts) !== 3) {
            return null;
        }

        // v4.6.17 — a join request wears the same three-segment shape with a
        // marker in front. Told apart before the resource branch reads $parts[0]
        // as a team id.
        if ($parts[0] === self::JOIN_PREFIX) {
            [, $teamId, $uid] = $parts;
            if (!in_array($teamId, $allowedTeamIds, true) || !$this->isTeamAdmin($teamId)) {
                return null;
            }

            try {
                $requests = $this->memberService->getPendingRequests($teamId);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][MyWork][TeamAdmin] join-request re-read failed', [
                    'itemId' => $itemId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                return null;
            }

            foreach ($requests as $request) {
                if ((string)$request['userId'] === $uid) {
                    return $this->buildJoinRequestItem(
                        new WorkQuery(userId: $userId, teamIds: $allowedTeamIds, now: time()),
                        $teamId,
                        $request,
                    );
                }
            }

            // Decided by somebody else while the row was on screen.
            return null;
        }

        [$teamId, $appId, $resourceId] = $parts;

        if (!in_array($teamId, $allowedTeamIds, true) || !$this->isTeamAdmin($teamId)) {
            return null;
        }

        try {
            $row = $this->resourceMapper->findByTeamAppResource($teamId, $appId, $resourceId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][TeamAdmin] item re-read failed', [
                'itemId' => $itemId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }

        if ($row === null || $row->getStatus() !== 'pending') {
            return null;
        }

        // Same shape DecisionWorkProvider::getItem() uses — no names map here,
        // so teamName() falls back to the id and MyWorkService::stampTeamNames
        // corrects it on the list path.
        return $this->buildItem(
            new WorkQuery(userId: $userId, teamIds: $allowedTeamIds, now: time()),
            $teamId,
            $row,
        );
    }

    // ---------------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------------

    public function getAvailableActions(string $userId, WorkItem $item): array {
        return [ActionType::OPEN, ActionType::APPROVE, ActionType::REJECT];
    }

    public function executeAction(string $userId, WorkItem $item, string $action, array $params): ActionResult {
        if ($item->resourceType === self::TYPE_JOIN_REQUEST) {
            return $this->decideJoinRequest($item, $action);
        }

        $appId      = (string)($item->metadata['appId'] ?? '');
        $resourceId = (string)($item->metadata['resourceId'] ?? '');
        if ($appId === '' || $resourceId === '') {
            return ActionResult::failure($this->l->t('That resource could not be identified.'), 'failed');
        }

        try {
            if ($action === ActionType::APPROVE) {
                $this->discoveryService->acceptResource($item->teamId, $appId, $resourceId, $userId);
                return ActionResult::success(
                    $this->l->t('Resource accepted. It is now part of the team.'),
                    null,
                    true,
                );
            }

            if ($action === ActionType::REJECT) {
                $this->discoveryService->ignoreResource($item->teamId, $appId, $resourceId, $userId);
                return ActionResult::success(
                    $this->l->t('Resource ignored. The team stays connected to it in Nextcloud, but it will not appear in TeamHub.'),
                    null,
                    true,
                );
            }
        } catch (\RuntimeException $e) {
            // acceptResource() refuses when the row is no longer pending —
            // somebody else reviewed it while this row was on screen.
            return ActionResult::conflict($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWork][TeamAdmin] action failed', [
                'action' => $action, 'teamId' => $item->teamId,
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return ActionResult::failure($this->l->t('That could not be saved.'), 'failed');
        }

        return ActionResult::unsupported($this->l->t('Unknown action.'));
    }

    /**
     * Approve or reject a pending join request (v4.6.17).
     *
     * Both service calls re-check the caller's team level and re-check that the
     * row is still `Requesting`, so the authority for this decision lives where
     * it does for the Manage team → Members buttons — this is a second door onto
     * the same room, not a second lock.
     */
    private function decideJoinRequest(WorkItem $item, string $action): ActionResult {
        $uid = (string)($item->metadata['requesterUid'] ?? '');
        if ($uid === '') {
            return ActionResult::failure($this->l->t('That request could not be identified.'), 'failed');
        }

        try {
            if ($action === ActionType::APPROVE) {
                $this->memberService->approveRequest($item->teamId, $uid);
                return ActionResult::success(
                    $this->l->t('%s is now a member of the team.', [
                        (string)($item->metadata['requesterName'] ?? $uid),
                    ]),
                    null,
                    true,
                );
            }

            if ($action === ActionType::REJECT) {
                $this->memberService->rejectRequest($item->teamId, $uid);
                return ActionResult::success(
                    $this->l->t('Request rejected. They can ask again if the team is still open to requests.'),
                    null,
                    true,
                );
            }
        } catch (\Throwable $e) {
            // 'Pending request not found' is what both services throw when the
            // row has already been decided — a conflict, not a failure, so the
            // row refreshes rather than reporting an error the admin caused.
            if (str_contains($e->getMessage(), 'Pending request not found')) {
                return ActionResult::conflict(
                    $this->l->t('That request has already been decided.'),
                );
            }
            $this->logger->error('[TeamHub][MyWork][TeamAdmin] join-request action failed', [
                'action' => $action, 'teamId' => $item->teamId,
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return ActionResult::failure($this->l->t('That could not be saved.'), 'failed');
        }

        return ActionResult::unsupported($this->l->t('Unknown action.'));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function buildItem(WorkQuery $query, string $teamId, TeamAppResource $row): WorkItem {
        $appId      = $row->getAppId();
        $resourceId = $row->getResourceId();

        return WorkItem::make([
            'providerId'     => self::ID,
            // Three parts because a resource is only unique within (team, app):
            // two teams can both have a pending Deck board 42.
            'providerItemId' => $teamId . ':' . $appId . ':' . $resourceId,
            'teamId'         => $teamId,
            'teamName'       => $query->teamName($teamId),
            'category'       => Category::TEAM_ADMIN,
            'title'          => $this->titleFor($appId, $row),
            'subtitle'       => $this->appLabel($appId),
            'resourceType'   => self::RESOURCE_TYPE,
            'resourceId'     => $resourceId,
            'resourceUrl'    => '/apps/teamhub?team=' . rawurlencode($teamId),
            // Opens Manage team → Integrations, where the review panel with
            // its Accept/Ignore rows lives — the same destination the Team info
            // "N resources need review" strip leads to.
            'openTarget'     => OpenTarget::manageTeam('integrations'),
            // Nothing about an unreviewed resource is urgent, and saying it is
            // would be the fastest way to make the whole category ignorable.
            'priority'       => Priority::LOW,
            'status'         => self::STATUS_PENDING_REVIEW,
            'reason'         => $this->l->t('Connected to this team outside TeamHub — accept it or ignore it'),
            'createdAt'      => $row->getCreatedAt(),
            'updatedAt'      => $row->getUpdatedAt() ?: $row->getCreatedAt(),
            // No deadline. See the class docblock.
            'dueAt'          => null,
            'completedAt'    => null,
            'assignee'       => null,
            'waitingFor'     => null,
            'availableActions' => [],
            'metadata'       => [
                'appId'      => $appId,
                'resourceId' => $resourceId,
                'origin'     => $row->getOrigin(),
            ],
            'permissions'    => ['canApprove' => true],
        ]);
    }

    /**
     * One person's pending request to join a team (v4.6.17).
     *
     * **Ranked NORMAL, above the LOW that pending resources carry.** Somebody is
     * waiting on an answer, and until they get one they cannot reach anything
     * the team holds; an unreviewed Deck board inconveniences nobody. It stays
     * out of Action required all the same — the category ranks below Waiting for
     * others by design, so this can never outrank a deadline.
     *
     * No `dueAt`, for the same reason nothing else in this category has one:
     * a request does not expire, and a date filter is a question about
     * deadlines. See the class docblock.
     *
     * @param array{userId: string, displayName: string} $request
     */
    private function buildJoinRequestItem(WorkQuery $query, string $teamId, array $request): WorkItem {
        $uid  = (string)$request['userId'];
        $name = (string)($request['displayName'] ?: $uid);

        return WorkItem::make([
            'providerId'     => self::ID,
            'providerItemId' => self::JOIN_PREFIX . ':' . $teamId . ':' . $uid,
            'teamId'         => $teamId,
            'teamName'       => $query->teamName($teamId),
            'category'       => Category::TEAM_ADMIN,
            'title'          => $this->l->t('%s asks to join', [$name]),
            'subtitle'       => $this->l->t('Membership request'),
            'resourceType'   => self::TYPE_JOIN_REQUEST,
            'resourceId'     => $uid,
            'resourceUrl'    => '/apps/teamhub?team=' . rawurlencode($teamId),
            // Manage team → Members, where the pending-request list with its own
            // Approve/Reject rows lives.
            'openTarget'     => OpenTarget::manageTeam('members'),
            'priority'       => Priority::NORMAL,
            'status'         => self::STATUS_JOIN_REQUESTED,
            'reason'         => $this->l->t('Waiting for a team admin to approve or reject'),
            // Circles' `circles_member` row carries a `joined` timestamp but it
            // is a DATETIME string and means something different per status, so
            // it is not read here. Undated is honest; a wrong date is not.
            'createdAt'      => null,
            'updatedAt'      => null,
            'dueAt'          => null,
            'completedAt'    => null,
            // The requester is who this is *about*, not who it is assigned to —
            // the work is the admin's. waitingFor says the same thing the right
            // way round: the team is what this person is waiting on.
            'assignee'       => null,
            'waitingFor'     => null,
            'availableActions' => [],
            'metadata'       => [
                'requesterUid'  => $uid,
                'requesterName' => $name,
            ],
            'permissions'    => ['canApprove' => true],
        ]);
    }

    /**
     * The resource's own name where we have one, its id where we do not.
     *
     * Deliberately not calling ResourceDiscoveryService::resolveDisplayName()
     * — that is private, and exposing it to get a nicer title would widen its
     * contract for a label. The panel is one click away and shows the resolved
     * name; a queue row saying "Deck board 42" is honest and sufficient.
     */
    private function titleFor(string $appId, TeamAppResource $row): string {
        return $this->l->t('%1$s needs review', [$this->resourceLabel($appId, $row)]);
    }

    private function resourceLabel(string $appId, TeamAppResource $row): string {
        $id = $row->getResourceId();
        return match ($appId) {
            'files'       => $this->l->t('Folder %s', [$id]),
            'talk'        => $this->l->t('Conversation %s', [$id]),
            'calendar'    => $this->l->t('Calendar %s', [$id]),
            'deck'        => $this->l->t('Deck board %s', [$id]),
            'collectives' => $this->l->t('Wiki %s', [$id]),
            default       => $this->l->t('Resource %s', [$id]),
        };
    }

    private function appLabel(string $appId): string {
        return match ($appId) {
            'files'       => $this->l->t('Files'),
            'talk'        => $this->l->t('Talk'),
            'calendar'    => $this->l->t('Calendar'),
            'deck'        => $this->l->t('Deck'),
            'collectives' => $this->l->t('Collectives'),
            default       => $appId,
        };
    }

    /** Never throws — an unknown verdict lists the row rather than hiding it. */
    private function availabilityOf(string $teamId, TeamAppResource $row): string {
        try {
            return $this->discoveryService->availabilityFor($teamId, $row->getAppId(), $row->getResourceId());
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][MyWork][TeamAdmin] availability check failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ResourceDiscoveryService::AVAILABILITY_UNKNOWN;
        }
    }

    private function isTeamAdmin(string $teamId): bool {
        try {
            $this->memberService->requireAdminLevel($teamId);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
