<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork\Provider;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\MyWork\ActionResult;
use OCA\TeamHub\MyWork\ActionType;
use OCA\TeamHub\MyWork\Category;
use OCA\TeamHub\MyWork\IWorkProvider;
use OCA\TeamHub\MyWork\OpenTarget;
use OCA\TeamHub\MyWork\Priority;
use OCA\TeamHub\MyWork\WorkItem;
use OCA\TeamHub\MyWork\WorkItemPage;
use OCA\TeamHub\MyWork\WorkQuery;
use OCA\TeamHub\Service\TeamExpiryService;
use OCP\IGroupManager;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * My Work provider for the Nextcloud administrator's side of team expiration
 * (v4.6.13).
 *
 * Two kinds of item, both filed under `Category::TEAM_ADMIN`:
 *
 *   - **A team is approaching its expiration date.** Surfaces once the date is
 *     inside the configured warning window (7 days by default) and keeps
 *     surfacing after it passes, because nothing happens automatically on
 *     expiry and an item nobody acted on is the item that most needs to stay
 *     visible.
 *   - **A team has asked for an extension.** Carries the proposed date and the
 *     reason, and offers Approve and Deny inline.
 *
 * ## Instance-scoped — and what that costs this class
 *
 * This is the first provider to declare `isInstanceScoped()`. It returns items
 * for teams the viewer is not a member of, which every other provider is
 * forbidden to do, because the entire point is to reach an administrator who is
 * *not* in the team. MyWorkService therefore stops filtering on this provider's
 * behalf (see WorkQuery's docblock), and the check moves here: **every entry
 * point below starts by establishing `IGroupManager::isAdmin()`**, and returns
 * nothing at all when it does not hold.
 *
 * A non-admin who reaches these methods — through a stale page, a crafted
 * request, or a future refactor that forgets the exemption — gets an empty page
 * and a failed action, not somebody else's team.
 *
 * ## Why a separate provider from the team-admin one
 *
 * `TeamExpiryTeamWorkProvider` shows the same expiring team to the team's own
 * admins, and it is deliberately a different class rather than a role branch
 * inside this one. The instance-scope exemption is granted per provider id, so
 * a single class serving both audiences would carry the exemption while
 * emitting team-scoped rows as well. Two classes keep the widened boundary
 * around exactly the rows that need it.
 *
 * ## Ranking
 *
 * `Priority::HIGH` once the window opens and `URGENT` once the date has passed
 * — but still `Category::TEAM_ADMIN`, which sits below Waiting for others. The
 * category says what kind of work this is; the priority says how pressing it is
 * within that kind. An expiring team should not outrank the viewer's own
 * overdue approval, and a category is how that is expressed here.
 */
class TeamExpiryAdminWorkProvider implements IWorkProvider {

    public const ID = 'teamexpiry_admin';

    /** Resource types this provider emits. */
    private const TYPE_EXPIRY  = 'team_expiry';
    private const TYPE_REQUEST = 'team_expiry_request';

    /** Source statuses, exposed through getCapabilities for the admin filter. */
    public const STATUS_EXPIRING = 'expiring_soon';
    public const STATUS_EXPIRED  = 'expired';
    public const STATUS_REQUEST  = 'extension_requested';
    /** v4.6.22 — how a request the admin decided is reported afterwards. */
    public const STATUS_APPROVED   = 'extension_approved';
    public const STATUS_DENIED     = 'extension_denied';
    public const STATUS_SUPERSEDED = 'extension_superseded';

    /**
     * Rows per request. An instance with more than this many expiring teams at
     * once has a rollout to plan, not a queue to work, and burying every other
     * category under it would help nobody.
     */
    private const MAX_ITEMS = 50;

    private ?string $unavailableReason = null;

    public function __construct(
        private TeamExpiryService $expiryService,
        private IGroupManager     $groupManager,
        private IL10N             $l,
        private LoggerInterface   $logger,
    ) {
        // v4.6.17 — IAppManager and IURLGenerator were dropped: building the
        // compose URL moved to TeamExpiryService, which the All teams table
        // needs too.
    }

    // ---------------------------------------------------------------------
    // Identity + capabilities
    // ---------------------------------------------------------------------

    public function getId(): string {
        return self::ID;
    }

    public function getName(): string {
        // TRANSLATORS: My Work source name — team expiration dates, seen by
        // Nextcloud administrators. "Team lifecycle" rather than "Team expiry"
        // because the queue also carries extension requests.
        return $this->l->t('Team lifecycle');
    }

    public function getIcon(): string {
        return 'teamexpiry';
    }

    /**
     * Declared outside IWorkProvider and found by `method_exists`. See the
     * class docblock, and IWorkProvider's, for what it obliges this class to do.
     */
    public function isInstanceScoped(): bool {
        return true;
    }

    public function getCapabilities(): array {
        return [
            'actions' => [
                ActionType::OPEN,
                ActionType::APPROVE,
                ActionType::REJECT,
                // Navigation only — see getAvailableActions and ActionType::EMAIL.
                ActionType::EMAIL,
            ],
            'resourceTypes' => [self::TYPE_EXPIRY, self::TYPE_REQUEST],
            'statuses'      => [
                self::STATUS_EXPIRING, self::STATUS_EXPIRED, self::STATUS_REQUEST,
                self::STATUS_APPROVED, self::STATUS_DENIED, self::STATUS_SUPERSEDED,
            ],
            // v4.6.22 — COMPLETED joins TEAM_ADMIN. There is no
            // WAITING_FOR_OTHERS here on purpose: once a request reaches this
            // queue the administrator *is* the other party, so there is nobody
            // for them to wait on. The team's own provider carries that half.
            'categories'    => [Category::TEAM_ADMIN, Category::COMPLETED],
            'pagination'    => false,
            'incremental'   => false,
        ];
    }

    /**
     * Always available: both tables are TeamHub's own and have no optional
     * dependency behind them. A viewer who is not an administrator gets an
     * empty page, which is a different thing from the source being down and
     * must not be reported as one.
     */
    public function isAvailable(): bool {
        $this->unavailableReason = null;
        return true;
    }

    public function getUnavailableReason(): ?string {
        return $this->unavailableReason;
    }

    public function getSupportedFilters(): array {
        // Not teamIds: an active team filter switches the instance-scope bypass
        // off in MyWorkService, so narrowing never reaches this provider in a
        // form it could honour.
        return [];
    }

    public function getConfigSchema(): array {
        return [];
    }

    // ---------------------------------------------------------------------
    // Fetch
    // ---------------------------------------------------------------------

    public function fetchItems(WorkQuery $query): WorkItemPage {
        if (!$this->isNcAdmin($query->userId)) {
            return WorkItemPage::empty();
        }

        $items     = [];
        $truncated = false;

        // ── Pending extension requests ────────────────────────────────────
        // First, because a request is a decision somebody is waiting on, while
        // an approaching date is a decision nobody has been asked to make yet.
        try {
            foreach ($this->expiryService->listPendingRequests(self::MAX_ITEMS) as $request) {
                if (count($items) >= self::MAX_ITEMS) {
                    $truncated = true;
                    break;
                }
                $items[] = $this->buildRequestItem($request, $query->now);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][TeamExpiryAdmin] request lookup failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // Teams already represented by a request do not also get a countdown
        // row: the request is the same subject with more information on it, and
        // two rows for one team is how a queue starts being ignored.
        $withRequest = [];
        foreach ($items as $item) {
            $withRequest[$item->teamId] = true;
        }

        // ── Teams inside the warning window ───────────────────────────────
        try {
            foreach ($this->expiryService->findExpiringSoon($query->now) as $row) {
                if (count($items) >= self::MAX_ITEMS) {
                    $truncated = true;
                    break;
                }
                if (isset($withRequest[$row['teamId']])) {
                    continue;
                }
                $items[] = $this->buildExpiryItem($row, $query->now);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][TeamExpiryAdmin] expiry sweep failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // ── Requests decided recently → Completed ─────────────────────────
        //
        // v4.6.22. Approving or denying dropped the row out of
        // listPendingRequests() and nothing replaced it, so the administrator
        // got no confirmation their decision landed — and on approval the team
        // also left findExpiringSoon(), so the whole subject disappeared from
        // the queue in one step. Last, because a decision already made is the
        // least pressing thing on the page.
        try {
            foreach ($this->expiryService->listRecentlyDecided($query->completedSince(), self::MAX_ITEMS) as $request) {
                if (count($items) >= self::MAX_ITEMS) {
                    $truncated = true;
                    break;
                }
                $items[] = $this->buildDecidedItem($request);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][TeamExpiryAdmin] decided lookup failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        return new WorkItemPage($items, count($items), $truncated);
    }

    /**
     * `$allowedTeamIds` is deliberately unused — see the class docblock. This
     * provider's boundary is administrator-or-not, and widening the caller's
     * team list to match would be the wrong shape: it would have to be widened
     * for every provider, not just this one.
     */
    public function getItem(string $userId, string $itemId, array $allowedTeamIds): ?WorkItem {
        if (!$this->isNcAdmin($userId)) {
            return null;
        }

        // "req:{id}" or "exp:{teamId}" — see buildRequestItem/buildExpiryItem.
        $parts = explode(':', $itemId, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$kind, $ref] = $parts;

        try {
            if ($kind === 'req') {
                foreach ($this->expiryService->listPendingRequests() as $request) {
                    if ((string)$request['id'] === $ref) {
                        return $this->buildRequestItem($request, time());
                    }
                }
                // v4.6.22 — not pending is not the same as gone. A request
                // decided while the row was on screen still has to resolve, or
                // the refresh that follows Approve/Deny reports the item as
                // vanished instead of completed.
                $since = time() - (WorkQuery::DEFAULT_COMPLETED_DAYS * 86400);
                foreach ($this->expiryService->listRecentlyDecided($since) as $request) {
                    if ((string)$request['id'] === $ref) {
                        return $this->buildDecidedItem($request);
                    }
                }
                return null;
            }

            if ($kind === 'exp') {
                foreach ($this->expiryService->findExpiringSoon() as $row) {
                    if ($row['teamId'] === $ref) {
                        return $this->buildExpiryItem($row, time());
                    }
                }
                return null;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][TeamExpiryAdmin] item re-read failed', [
                'itemId' => $itemId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------------

    public function getAvailableActions(string $userId, WorkItem $item): array {
        if (!$this->isNcAdmin($userId)) {
            return [];
        }
        // A countdown row has nothing to approve — there is no request behind
        // it. Extending it is a date decision, which belongs in the All teams
        // table where a picker exists.
        //
        // v4.6.16 — but it is not actionless either: nobody has asked for more
        // time, and the administrator looking at the row is usually not in the
        // team, so the real next step is asking the owner whether it is still
        // needed. Email owner is offered whenever an address was resolved, and
        // withheld when it was not — an owner-less or address-less team must
        // not get a button that goes nowhere (SKILLS.md § Permissions applied
        // to an affordance rather than a role).
        if ($item->resourceType !== self::TYPE_REQUEST) {
            return ($item->metadata['mailtoUrl'] ?? null) !== null
                ? [ActionType::OPEN, ActionType::EMAIL]
                : [ActionType::OPEN];
        }
        // v4.6.22 — a decided request is a record. Approve and Deny would be
        // offered on a decision already made; `loadPendingRequest()` refuses
        // them anyway, but a button that always errors is the affordance
        // SKILLS.md § Permissions says to withhold rather than to let fail.
        if ($item->category === Category::COMPLETED) {
            return [ActionType::OPEN];
        }
        return [ActionType::OPEN, ActionType::APPROVE, ActionType::REJECT];
    }

    public function executeAction(string $userId, WorkItem $item, string $action, array $params): ActionResult {
        if (!$this->isNcAdmin($userId)) {
            return ActionResult::forbidden(
                $this->l->t('Nextcloud administrator privileges are required.'),
            );
        }

        if ($item->resourceType !== self::TYPE_REQUEST) {
            return ActionResult::unsupported(
                $this->l->t('Open the team in Admin settings to change its expiration date.'),
            );
        }

        // v4.6.22 — belt and braces with getAvailableActions above. A stale
        // page can still post Approve for a request somebody else has since
        // decided; `conflict` tells the client to refresh, which is what the
        // reader needs, rather than a generic failure.
        if ($item->category === Category::COMPLETED) {
            return ActionResult::conflict(
                $this->l->t('That request has already been decided.'),
            );
        }

        $requestId = (int)($item->metadata['requestId'] ?? 0);
        if ($requestId <= 0) {
            return ActionResult::failure($this->l->t('That request could not be identified.'), 'failed');
        }

        try {
            if ($action === ActionType::APPROVE) {
                // No date in `$params` means "grant exactly what was asked for".
                // Granting something else is a considered decision and belongs
                // in the admin panel, where the date picker and the requester's
                // reason are on screen together.
                $this->expiryService->approveRequest($requestId, null, (string)($params['note'] ?? ''));
                return ActionResult::success(
                    $this->l->t('Extension approved. The team keeps its place until the new date.'),
                    null,
                    true,
                );
            }

            if ($action === ActionType::REJECT) {
                $this->expiryService->denyRequest($requestId, (string)($params['note'] ?? ''));
                return ActionResult::success(
                    $this->l->t('Extension denied. The team admins have been notified.'),
                    null,
                    true,
                );
            }
        } catch (\OCA\TeamHub\Exception\ValidationException $e) {
            // Somebody else decided it while this row was on screen, or the
            // proposed date went stale in the queue.
            return ActionResult::conflict($e->getMessage());
        } catch (\OCA\TeamHub\Exception\NotFoundException $e) {
            return ActionResult::gone($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWork][TeamExpiryAdmin] action failed', [
                'action' => $action, 'requestId' => $requestId,
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return ActionResult::failure($this->l->t('That could not be saved.'), 'failed');
        }

        return ActionResult::unsupported($this->l->t('Unknown action.'));
    }

    // ---------------------------------------------------------------------
    // Item builders
    // ---------------------------------------------------------------------

    /**
     * A request this administrator (or another) has already decided (v4.6.22).
     *
     * Reports who decided it and what they granted, because on an instance
     * with several administrators the useful fact is often that somebody else
     * already handled it.
     *
     * @param array<string,mixed> $request
     */
    private function buildDecidedItem(array $request): WorkItem {
        $teamId    = (string)$request['teamId'];
        $teamName  = (string)($request['teamName'] ?? $teamId);
        $decidedAt = (int)($request['decidedAt'] ?? 0);
        $decidedBy = (string)($request['decidedName'] ?? $request['decidedBy'] ?? '');

        switch ((string)$request['status']) {
            case 'approved':
                $status = self::STATUS_APPROVED;
                $title  = $this->l->t('Extension approved for %s', [$teamName]);
                $reason = $decidedBy !== ''
                    ? $this->l->t('%1$s extended this team until %2$s.', [$decidedBy, (string)($request['grantedOn'] ?? '')])
                    : $this->l->t('Extended until %s.', [(string)($request['grantedOn'] ?? '')]);
                break;
            case 'denied':
                $status = self::STATUS_DENIED;
                $title  = $this->l->t('Extension denied for %s', [$teamName]);
                $reason = $decidedBy !== ''
                    ? $this->l->t('%s declined the request. The expiration date is unchanged.', [$decidedBy])
                    : $this->l->t('The request was declined. The expiration date is unchanged.');
                break;
            default:
                $status = self::STATUS_SUPERSEDED;
                $title  = $this->l->t('Extension request closed for %s', [$teamName]);
                $reason = $this->l->t('Withdrawn by the team, or answered by changing the expiration date directly.');
                break;
        }

        return WorkItem::make([
            'providerId'     => self::ID,
            // Same "req:{id}" shape the pending row uses — it is the same
            // request, further along. getItem() resolves both from one branch.
            'providerItemId' => 'req:' . (int)$request['id'],
            'teamId'         => $teamId,
            'teamName'       => $teamName,
            'category'       => Category::COMPLETED,
            'title'          => $title,
            'subtitle'       => $this->l->t('Requested until %s', [(string)($request['proposedOn'] ?? '')]),
            'resourceType'   => self::TYPE_REQUEST,
            'resourceId'     => (string)$request['id'],
            'resourceUrl'    => '/settings/admin/teamhub',
            'openTarget'     => OpenTarget::external(),
            'priority'       => Priority::LOW,
            'status'         => $status,
            'reason'         => $reason,
            'createdAt'      => (int)($request['requestedAt'] ?? $decidedAt),
            'updatedAt'      => $decidedAt,
            // No dueAt — the decision it was waiting for has been made. The
            // pending row carries the team's expiry as its deadline precisely
            // because somebody still had to act by then; nobody does now.
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
                'decidedBy'    => (string)($request['decidedBy'] ?? ''),
            ],
            'permissions'    => ['canApprove' => false],
        ]);
    }

    /** @param array<string,mixed> $request */
    private function buildRequestItem(array $request, int $now): WorkItem {
        $teamId   = (string)$request['teamId'];
        $teamName = (string)($request['teamName'] ?? $teamId);
        $expiry   = $request['expiry'] ?? null;

        return WorkItem::make([
            'providerId'     => self::ID,
            'providerItemId' => 'req:' . $request['id'],
            'teamId'         => $teamId,
            'teamName'       => $teamName,
            'category'       => Category::TEAM_ADMIN,
            'title'          => $this->l->t('%s asked to extend its expiration date', [$teamName]),
            'subtitle'       => $this->l->t('Requested until %s', [(string)$request['proposedOn']]),
            'resourceType'   => self::TYPE_REQUEST,
            'resourceId'     => (string)$request['id'],
            'resourceUrl'    => '/settings/admin/teamhub',
            // Admin settings is the only place both the reason and a date
            // picker exist together, so that is where "Open" should land even
            // though Approve and Deny work from the row itself.
            'openTarget'     => OpenTarget::external(),
            'priority'       => Priority::HIGH,
            'status'         => self::STATUS_REQUEST,
            'reason'         => $request['reason'] !== null && $request['reason'] !== ''
                ? $this->l->t('%1$s asked for more time: %2$s', [
                    (string)$request['requestedName'],
                    (string)$request['reason'],
                ])
                : $this->l->t('%s asked for more time', [(string)$request['requestedName']]),
            'createdAt'      => (int)$request['requestedAt'],
            'updatedAt'      => (int)$request['requestedAt'],
            // The deadline on this item is the team's *current* expiry, not the
            // date being asked for: that is the moment by which somebody has to
            // have decided. Null when the expiry vanished under the request.
            'dueAt'          => is_array($expiry) ? (int)$expiry['expiresAt'] : null,
            'completedAt'    => null,
            'assignee'       => null,
            'waitingFor'     => null,
            'availableActions' => [],
            'metadata'       => [
                'requestId'     => (int)$request['id'],
                'proposedOn'    => (string)$request['proposedOn'],
                'requestedBy'   => (string)$request['requestedBy'],
                'currentOn'     => is_array($expiry) ? (string)$expiry['expiresOn'] : null,
                'daysRemaining' => is_array($expiry) ? (int)$expiry['daysRemaining'] : null,
            ],
            'permissions'    => ['canApprove' => true],
        ]);
    }

    /** @param array<string,mixed> $row */
    private function buildExpiryItem(array $row, int $now): WorkItem {
        $teamId   = (string)$row['teamId'];
        $teamName = $this->expiryService->resolveTeamName($teamId);
        $days     = (int)$row['daysRemaining'];
        $expired  = (bool)$row['expired'];

        // One extra lookup per row, on a list already capped at MAX_ITEMS and
        // rare by construction — the same per-row shape resolveTeamName above
        // already has. Null for an orphan team or an account with no address,
        // and getAvailableActions withholds the action in that case.
        $owner     = $this->expiryService->resolveTeamOwner($teamId);
        $mailtoUrl = $owner !== null && $owner['email'] !== null
            ? $this->composeUrl($owner['email'], $teamName, (string)$row['expiresOn'], $expired)
            : null;

        return WorkItem::make([
            'providerId'     => self::ID,
            'providerItemId' => 'exp:' . $teamId,
            'teamId'         => $teamId,
            'teamName'       => $teamName,
            'category'       => Category::TEAM_ADMIN,
            'title'          => $expired
                ? $this->l->t('%s has passed its expiration date', [$teamName])
                : $this->l->t('%s expires soon', [$teamName]),
            // IL10N::n, not ::t — the count drives plural selection and `%n` is
            // the placeholder NC substitutes it into.
            'subtitle'       => $expired
                // TRANSLATORS: %n is a whole number of days since a team's
                // expiration date passed.
                ? $this->l->n('Expired %n day ago', 'Expired %n days ago', abs($days))
                // TRANSLATORS: %n is a whole number of days until a team's
                // expiration date.
                : $this->l->n('Expires in %n day', 'Expires in %n days', max(0, $days)),
            'resourceType'   => self::TYPE_EXPIRY,
            'resourceId'     => $teamId,
            'resourceUrl'    => '/settings/admin/teamhub',
            'openTarget'     => OpenTarget::external(),
            'priority'       => $expired ? Priority::URGENT : Priority::HIGH,
            'status'         => $expired ? self::STATUS_EXPIRED : self::STATUS_EXPIRING,
            'reason'         => $this->l->t('Extend it from Admin settings → TeamHub → Maintenance, or leave it to lapse. Nothing is deleted automatically.'),
            'createdAt'      => (int)$row['setAt'],
            'updatedAt'      => (int)($row['lastExtendedAt'] ?? $row['setAt']),
            'dueAt'          => (int)$row['expiresAt'],
            'completedAt'    => null,
            'assignee'       => null,
            'waitingFor'     => null,
            'availableActions' => [],
            'metadata'       => [
                'expiresOn'     => (string)$row['expiresOn'],
                'daysRemaining' => $days,
                'expired'       => $expired,
                // v4.6.16 — the Email owner action's target. The uid and name
                // ride along so the row can say who it would write to without
                // parsing a URL for it.
                'ownerUid'      => $owner['uid'] ?? null,
                'ownerName'     => $owner['displayName'] ?? null,
                'mailtoUrl'     => $mailtoUrl,
            ],
            'permissions'    => ['canApprove' => false],
        ]);
    }

    /**
     * Where "Email owner" should send the administrator (v4.6.16).
     *
     * The subject and the opening line are written here; **which mail client
     * opens is `TeamExpiryService::mailComposeUrl()`'s decision**, shared with
     * the All teams table so the two cannot disagree. v4.6.17 moved it there
     * along with the fix to what it was testing — see that method.
     *
     * Either way the message is composed, read and sent by the administrator.
     * TeamHub fills in an address, a subject and an opening line, and nothing
     * is sent on anybody's behalf.
     */
    private function composeUrl(string $email, string $teamName, string $expiresOn, bool $expired): ?string {
        $subject = $expired
            ? $this->l->t('%1$s passed its expiration date on %2$s', [$teamName, $expiresOn])
            : $this->l->t('%1$s expires on %2$s', [$teamName, $expiresOn]);

        $body = $expired
            ? $this->l->t("The team \"%1\$s\" passed its expiration date on %2\$s. Nothing has been deleted and the team still works, but could you let me know whether it is still needed?", [$teamName, $expiresOn])
            : $this->l->t("The team \"%1\$s\" is set to expire on %2\$s. Nothing is deleted when it does and the team keeps working, but could you let me know whether it is still needed?", [$teamName, $expiresOn]);

        return $this->expiryService->mailComposeUrl($email, $subject, $body);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * The gate this whole class rests on. Fails closed: an error establishing
     * group membership means "not an administrator", never the reverse.
     */
    private function isNcAdmin(string $userId): bool {
        if ($userId === '') {
            return false;
        }
        try {
            return $this->groupManager->isAdmin($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][TeamExpiryAdmin] admin check failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }
}
