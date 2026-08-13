<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork\Provider;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\ValidationException;
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
use OCA\TeamHub\Service\TeamExpiryService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * My Work provider for the team's own side of expiration (v4.6.13).
 *
 * Tells a team's administrators that their team is approaching its expiration
 * date and that asking for more time is up to them. One item per team, from the
 * moment the date enters the warning window, and it stays after the date passes
 * because nothing happens automatically and an unnoticed lapse is exactly what
 * this row exists to prevent.
 *
 * ## Ordinary team scope
 *
 * Unlike `TeamExpiryAdminWorkProvider` this provider does **not** declare
 * `isInstanceScoped()`. It is a normal provider under the normal membership
 * boundary: `$query->teamIds` is the viewer's own teams and MyWorkService
 * re-filters what comes back. The two classes exist separately precisely so
 * that the widened boundary stays around the administrator's rows only.
 *
 * ## Team admins, not owners
 *
 * Narrowed to teams where the viewer holds admin level, the same gate
 * `TeamExpiryService::requestExtension()` enforces and the same one
 * `TeamAdminWorkProvider` uses. Showing an ordinary member a row whose only
 * action 403s is the failure mode SKILLS.md § Permissions exists to prevent.
 * The owner is always also an admin, so the owner sees it too.
 *
 * ## Two categories, decided per row
 *
 * `Category::TEAM_ADMIN` while there is something to do, `WAITING_FOR_OTHERS`
 * from the moment an extension request is in flight — the decision belongs to a
 * Nextcloud administrator from then on, and that second category also exempts
 * the row from urgency promotion. See `buildItem()`.
 *
 * ## Requesting from the queue (v4.6.17)
 *
 * A request carries a proposed date and a reason. Until 4.6.17 that was taken as
 * a reason to send the reader to Manage team → Maintenance, where the form
 * already existed — but "it does not fit on the row" argues for a dialog, not
 * for ejecting somebody from the queue to do the thing the queue just asked them
 * to do. `REQUEST_EXTENSION` is a source action now: the frontend collects the
 * two fields in a modal and posts them as `params`, and the reader stays in My
 * Work. A row that already has a request in flight says so and stops asking.
 */
class TeamExpiryTeamWorkProvider implements IWorkProvider {

    public const ID = 'teamexpiry_team';

    private const RESOURCE_TYPE = 'team_expiry';
    /** The decision itself, which is a different thing from the date. */
    private const RESOURCE_TYPE_REQUEST = 'team_expiry_request';

    public const STATUS_EXPIRING  = 'expiring_soon';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_REQUESTED = 'extension_pending';
    /** v4.6.22 — the three ways a request stops being open. */
    public const STATUS_APPROVED   = 'extension_approved';
    public const STATUS_DENIED     = 'extension_denied';
    public const STATUS_SUPERSEDED = 'extension_superseded';

    /** A person is not an admin of fifty expiring teams. Bound anyway. */
    private const MAX_ITEMS = 50;

    private ?string $unavailableReason = null;

    public function __construct(
        private TeamExpiryService $expiryService,
        private MemberService     $memberService,
        private IL10N             $l,
        private LoggerInterface   $logger,
    ) {
    }

    // ---------------------------------------------------------------------
    // Identity + capabilities
    // ---------------------------------------------------------------------

    public function getId(): string {
        return self::ID;
    }

    public function getName(): string {
        // TRANSLATORS: My Work source name — the team's own expiration date,
        // seen by that team's administrators.
        return $this->l->t('Team expiration');
    }

    public function getIcon(): string {
        return 'teamexpiry';
    }

    public function getCapabilities(): array {
        return [
            'actions'       => [ActionType::OPEN, ActionType::REQUEST_EXTENSION],
            'resourceTypes' => [self::RESOURCE_TYPE, self::RESOURCE_TYPE_REQUEST],
            'statuses'      => [
                self::STATUS_EXPIRING, self::STATUS_EXPIRED, self::STATUS_REQUESTED,
                self::STATUS_APPROVED, self::STATUS_DENIED, self::STATUS_SUPERSEDED,
            ],
            // All three states the workflow can be in, since v4.6.22:
            //   TEAM_ADMIN         — the team has something to do.
            //   WAITING_FOR_OTHERS — asked; a Nextcloud administrator decides (v4.6.17).
            //   COMPLETED          — decided, either way (v4.6.22).
            // See buildItem() and buildDecidedItem().
            'categories'    => [Category::TEAM_ADMIN, Category::WAITING_FOR_OTHERS, Category::COMPLETED],
            'pagination'    => false,
            'incremental'   => false,
        ];
    }

    /** TeamHub's own tables, no optional dependency behind them. */
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

        // The instance-wide sweep is one indexed range scan, and intersecting
        // its result with the viewer's teams costs less than asking for each
        // team's expiry in a loop. The membership check is what narrows it, and
        // it runs only for teams that actually have a date coming up.
        try {
            $expiring = $this->expiryService->findExpiringSoon($query->now);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][TeamExpiry] sweep failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return WorkItemPage::empty();
        }

        $mine      = array_flip($query->teamIds);
        $items     = [];
        $truncated = false;
        // Memoised because the decided-request pass asks about the same teams
        // again, and requireAdminLevel() is a membership lookup each time.
        $adminOf = [];
        $isAdmin = function (string $teamId) use (&$adminOf): bool {
            return $adminOf[$teamId] ??= $this->isTeamAdmin($teamId);
        };

        foreach ($expiring as $row) {
            if (count($items) >= self::MAX_ITEMS) {
                $truncated = true;
                break;
            }
            $teamId = (string)$row['teamId'];
            if (!isset($mine[$teamId]) || !$isAdmin($teamId)) {
                continue;
            }
            $items[] = $this->buildItem($query, $teamId, $row);
        }

        // ── Decided requests → Completed ──────────────────────────────────
        //
        // v4.6.22. Without this the workflow had no ending. An approval moves
        // the team's date past the warning window, so the team drops out of
        // findExpiringSoon() at the same moment its request stops being
        // pending — the row vanished from the queue entirely and the admin who
        // asked was never told the answer. A denial was as bad in the other
        // direction: the Waiting for others row disappeared and an unchanged
        // "expires soon" row took its place, which reads as the request having
        // been forgotten rather than refused.
        //
        // Keyed on the request, not the team, and carrying its own resource
        // type: the decision is a different object from the date, and a team
        // that is still expiring after a denial legitimately has both a
        // Completed row for the answer and a Team admin row for what to do
        // next.
        try {
            $decided = $this->expiryService->listRecentlyDecidedForTeams(
                array_values(array_filter($query->teamIds, $isAdmin)),
                $query->completedSince(),
                self::MAX_ITEMS,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][TeamExpiry] decided lookup failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            $decided = [];
        }

        foreach ($decided as $request) {
            if (count($items) >= self::MAX_ITEMS) {
                $truncated = true;
                break;
            }
            $items[] = $this->buildDecidedItem($query, $request);
        }

        return new WorkItemPage($items, count($items), $truncated);
    }

    public function getItem(string $userId, string $itemId, array $allowedTeamIds): ?WorkItem {
        // v4.6.22 — two id shapes: a bare team id for the countdown row, and
        // "req:{id}" for a decided request. See buildDecidedItem().
        if (str_starts_with($itemId, 'req:')) {
            return $this->getDecidedItem($userId, (int)substr($itemId, 4), $allowedTeamIds);
        }

        $teamId = $itemId;
        if (!in_array($teamId, $allowedTeamIds, true) || !$this->isTeamAdmin($teamId)) {
            return null;
        }

        try {
            $expiry = $this->expiryService->getExpiry($teamId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][TeamExpiry] item re-read failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
        if ($expiry === null) {
            return null;
        }

        return $this->buildItem(
            new WorkQuery(userId: $userId, teamIds: $allowedTeamIds, now: time()),
            $teamId,
            ['teamId' => $teamId] + $expiry,
        );
    }

    // ---------------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------------

    /**
     * v4.6.16 — Request extension joins Open, but only while there is nothing
     * in flight. A team that has already asked is waiting on an administrator,
     * and offering to ask again would produce a second request the endpoint
     * rejects anyway.
     */
    public function getAvailableActions(string $userId, WorkItem $item): array {
        // v4.6.22 — a Completed row is a record, not a task. Offering Request
        // extension on it would ask about a request that has already been
        // answered, and act on the wrong object: these rows are keyed on the
        // request, so `$item->teamId` is the only thing the action could use.
        if ($item->category === Category::COMPLETED) {
            return [ActionType::OPEN];
        }
        if (($item->metadata['requestPending'] ?? false) === true) {
            return [ActionType::OPEN];
        }
        return [ActionType::OPEN, ActionType::REQUEST_EXTENSION];
    }

    /**
     * v4.6.17 — `REQUEST_EXTENSION` is executed here rather than followed as a
     * link. The date and reason arrive in `$params` from the queue's own modal.
     *
     * No gate of its own: `TeamExpiryService::requestExtension()` calls
     * `requireAdminLevel()` first thing, and re-checks eligibility, the absence
     * of a request already in flight, and that the proposed date is actually
     * later than the current one. Repeating any of that here would be a second
     * copy of a rule with one owner.
     */
    public function executeAction(string $userId, WorkItem $item, string $action, array $params): ActionResult {
        if ($action !== ActionType::REQUEST_EXTENSION) {
            return ActionResult::unsupported($this->l->t('Unknown action.'));
        }

        $proposedOn = trim((string)($params['proposedOn'] ?? ''));
        if ($proposedOn === '') {
            return ActionResult::failure(
                $this->l->t('Pick the date you are asking to extend until.'),
                'failed',
            );
        }

        try {
            $this->expiryService->requestExtension(
                $item->teamId,
                $proposedOn,
                trim((string)($params['reason'] ?? '')),
            );
        } catch (\Throwable $e) {
            // The service's ValidationExceptions carry messages written for the
            // person reading them — "the requested date must be later than the
            // current expiration date" is the whole answer — so they are passed
            // through as a conflict rather than replaced with a generic failure.
            if ($e instanceof ValidationException) {
                return ActionResult::conflict($e->getMessage());
            }
            $this->logger->error('[TeamHub][MyWork][TeamExpiryTeam] extension request failed', [
                'teamId' => $item->teamId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return ActionResult::failure($this->l->t('That request could not be submitted.'), 'failed');
        }

        // `removed: true` — the row is rebuilt on the next fetch with
        // `requestPending`, which changes its title, its reason and its action
        // set. Refreshing is the only way the reader sees that.
        return ActionResult::success(
            $this->l->t('Extension requested. A Nextcloud administrator will decide.'),
            null,
            true,
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @param array<string,mixed> $row */
    private function buildItem(WorkQuery $query, string $teamId, array $row): WorkItem {
        $days     = (int)$row['daysRemaining'];
        $expired  = (bool)$row['expired'];
        $teamName = $query->teamName($teamId);

        // A request already in flight changes what this row is for: the team
        // has done its part and is waiting on an administrator, so the row
        // reports that rather than asking again.
        $pending = $this->pendingRequestFor($teamId);

        if ($pending !== null) {
            $status   = self::STATUS_REQUESTED;
            $title    = $this->l->t('Extension requested for %s', [$teamName]);
            $reason   = $this->l->t('Waiting for a Nextcloud administrator to decide. Requested until %s.', [
                (string)$pending['proposedOn'],
            ]);
            $priority = Priority::NORMAL;
            // v4.6.17 — **and it leaves Team admin.** Once the request is in,
            // the team admin has nothing to do: the decision is a Nextcloud
            // administrator's, and the row is there to say so. Team admin means
            // "housekeeping you owe your team"; a row you cannot act on does not
            // belong in it, and leaving it there is how a category stops being
            // read at all. Waiting for others is the category that already means
            // exactly this, for every other source.
            //
            // It also fixes a worse symptom than the wrong heading. These rows
            // carry `dueAt = expiresAt`, and `MyWorkService::applyUrgency()`
            // promotes anything dated into ACTION_REQUIRED as its deadline
            // approaches — with one exception, WAITING_FOR_OTHERS, "because the
            // whole point of that section is that the next step is not yours".
            // So a team that had already asked was being escalated to the most
            // urgent category in the queue, every day, for a decision it could
            // not make. This category is what stops that.
            $category = Category::WAITING_FOR_OTHERS;
        } else {
            $status = $expired ? self::STATUS_EXPIRED : self::STATUS_EXPIRING;
            $title  = $expired
                ? $this->l->t('%s has passed its expiration date', [$teamName])
                : $this->l->t('%s expires soon', [$teamName]);
            // v4.6.17 — no longer points at Manage team: Request extension on
            // this row opens the form here.
            $reason = $this->l->t('Ask for an extension if this team is still needed. Nothing is deleted when the date passes.');
            // Higher once the date has gone by, but never above TEAM_ADMIN's
            // place in the category order — see Category's docblock.
            $priority = $expired ? Priority::HIGH : Priority::NORMAL;
            $category = Category::TEAM_ADMIN;
        }

        return WorkItem::make([
            'providerId'     => self::ID,
            // The team id alone is enough: a team has at most one expiry.
            'providerItemId' => $teamId,
            'teamId'         => $teamId,
            'teamName'       => $teamName,
            'category'       => $category,
            'title'          => $title,
            // IL10N::n, not ::t — the count drives plural selection and `%n` is
            // the placeholder NC substitutes it into.
            'subtitle'       => $expired
                // TRANSLATORS: %n is a whole number of days since the team's
                // expiration date passed.
                ? $this->l->n('Expired %n day ago', 'Expired %n days ago', abs($days))
                // TRANSLATORS: %n is a whole number of days until the team's
                // expiration date.
                : $this->l->n('Expires in %n day', 'Expires in %n days', max(0, $days)),
            'resourceType'   => self::RESOURCE_TYPE,
            'resourceId'     => $teamId,
            'resourceUrl'    => '/apps/teamhub?team=' . rawurlencode($teamId),
            // The Maintenance tab, where the expiry panel and the request form
            // live — the same destination the Team info banner leads to.
            //
            // v4.6.16 — with the section, not just the tab. Landing on the tab
            // and leaving the reader to find the panel is what made this row
            // feel like it had no way to ask for more time.
            'openTarget'     => OpenTarget::manageTeam('danger', 'expiry'),
            'priority'       => $priority,
            'status'         => $status,
            'reason'         => $reason,
            'createdAt'      => (int)$row['setAt'],
            'updatedAt'      => (int)($row['lastExtendedAt'] ?? $row['setAt']),
            'dueAt'          => (int)$row['expiresAt'],
            'completedAt'    => null,
            'assignee'       => null,
            // v4.6.17 — who the row is waiting on, set only while a request is
            // in flight. The Waiting for others category is what it is because
            // of this field; a row sitting there with nobody named reads as an
            // empty promise. `group:admin` is Nextcloud's own administrators
            // group — the people who actually decide this — rather than an
            // individual, because any of them can.
            'waitingFor'     => $pending !== null
                ? ['type' => 'group', 'id' => 'admin', 'displayName' => $this->l->t('Nextcloud administrators')]
                : null,
            'availableActions' => [],
            'metadata'       => [
                'expiresOn'      => (string)$row['expiresOn'],
                'daysRemaining'  => $days,
                'expired'        => $expired,
                'requestPending' => $pending !== null,
                'proposedOn'     => $pending !== null ? (string)$pending['proposedOn'] : null,
            ],
            'permissions'    => ['canRequest' => $pending === null],
        ]);
    }

    /**
     * Re-read one decided request. Same team-admin gate as every other row
     * here, applied to the team the request belongs to.
     *
     * Searches the same window `fetchItems` uses rather than fetching by id,
     * because there is no by-id read that carries the team-admin check and
     * adding one to reach a row that has already scrolled out of the window
     * would widen the boundary for no gain.
     */
    private function getDecidedItem(string $userId, int $requestId, array $allowedTeamIds): ?WorkItem {
        if ($requestId <= 0 || $allowedTeamIds === []) {
            return null;
        }

        $mine = array_values(array_filter($allowedTeamIds, fn (string $t): bool => $this->isTeamAdmin($t)));
        if ($mine === []) {
            return null;
        }

        $query = new WorkQuery(userId: $userId, teamIds: $mine, now: time());
        try {
            $decided = $this->expiryService->listRecentlyDecidedForTeams(
                $mine, $query->completedSince(), self::MAX_ITEMS,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][TeamExpiry] decided re-read failed', [
                'requestId' => $requestId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }

        foreach ($decided as $request) {
            if ((int)$request['id'] === $requestId) {
                return $this->buildDecidedItem($query, $request);
            }
        }
        return null;
    }

    /**
     * A decided request, as the team side reads it (v4.6.22).
     *
     * Three outcomes, three sentences. `superseded` covers both the team
     * withdrawing its own request and an administrator changing the date by
     * another route; from the team's side those are the same fact — the
     * question is closed and the date is whatever it now is — so they share a
     * row rather than guessing from `decidedBy` which one happened.
     *
     * @param array<string,mixed> $request
     */
    private function buildDecidedItem(WorkQuery $query, array $request): WorkItem {
        $teamId   = (string)$request['teamId'];
        $teamName = $query->teamName($teamId);
        if ($teamName === $teamId) {
            // teamName() echoes the id back when the query did not carry a
            // name; the service resolved one on the way out, so prefer it.
            $teamName = (string)($request['teamName'] ?? $teamId);
        }
        $decidedAt = (int)($request['decidedAt'] ?? 0);

        switch ((string)$request['status']) {
            case 'approved':
                $status = self::STATUS_APPROVED;
                $title  = $this->l->t('Extension granted for %s', [$teamName]);
                $reason = $this->l->t('Extended until %s.', [(string)($request['grantedOn'] ?? '')]);
                break;
            case 'denied':
                $status = self::STATUS_DENIED;
                $title  = $this->l->t('Extension denied for %s', [$teamName]);
                // The administrator's note is the useful half of a denial, so
                // it is shown when there is one rather than left in Manage team.
                $note   = trim((string)($request['decisionNote'] ?? ''));
                $reason = $note !== ''
                    ? $this->l->t('A Nextcloud administrator declined the request: %s', [$note])
                    : $this->l->t('A Nextcloud administrator declined the request.');
                break;
            default:
                $status = self::STATUS_SUPERSEDED;
                $title  = $this->l->t('Extension request closed for %s', [$teamName]);
                $reason = $this->l->t('The request was withdrawn or the expiration date was changed another way.');
                break;
        }

        return WorkItem::make([
            'providerId'     => self::ID,
            // "req:{id}" — distinct from the countdown row's bare team id, so a
            // team can carry both at once. getItem() reads both shapes.
            'providerItemId' => 'req:' . (int)$request['id'],
            'teamId'         => $teamId,
            'teamName'       => $teamName,
            'category'       => Category::COMPLETED,
            'title'          => $title,
            'subtitle'       => $this->l->t('Requested until %s', [(string)($request['proposedOn'] ?? '')]),
            'resourceType'   => self::RESOURCE_TYPE_REQUEST,
            'resourceId'     => (string)$request['id'],
            'resourceUrl'    => '/apps/teamhub?team=' . rawurlencode($teamId),
            'openTarget'     => OpenTarget::manageTeam('danger', 'expiry'),
            // Completed work never competes for attention. MyWorkService
            // exempts this category from urgency promotion, so the priority
            // only affects ordering inside the section.
            'priority'       => Priority::LOW,
            'status'         => $status,
            'reason'         => $reason,
            'createdAt'      => (int)($request['requestedAt'] ?? $decidedAt),
            'updatedAt'      => $decidedAt,
            // **No dueAt.** The date this row is about has already been
            // settled; carrying the team's expiry here would put a completed
            // row back in the running for a deadline it no longer has.
            'dueAt'          => null,
            'completedAt'    => $decidedAt,
            'assignee'       => null,
            'waitingFor'     => null,
            'availableActions' => [],
            'metadata'       => [
                'requestId'    => (int)$request['id'],
                'requestState' => (string)$request['status'],
                'proposedOn'   => (string)($request['proposedOn'] ?? ''),
                'grantedOn'    => (string)($request['grantedOn'] ?? ''),
            ],
            'permissions'    => ['canRequest' => false],
        ]);
    }

    /**
     * The team's open request, if any.
     *
     * Reads through `getTeamStatus()` rather than the mapper so the team-admin
     * gate is applied on this path too — the caller has already checked, and a
     * second check that agrees costs one membership lookup on a row that is
     * rare by construction.
     *
     * @return array<string,mixed>|null
     */
    private function pendingRequestFor(string $teamId): ?array {
        try {
            $status  = $this->expiryService->getTeamStatus($teamId);
            $request = $status['request'] ?? null;
            if (is_array($request) && ($request['status'] ?? '') === 'pending') {
                return $request;
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][MyWork][TeamExpiry] request lookup failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
        return null;
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
