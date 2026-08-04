<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork\Provider;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\Decision;
use OCA\TeamHub\Db\DecisionMapper;
use OCA\TeamHub\MyWork\ActionResult;
use OCA\TeamHub\MyWork\ActionType;
use OCA\TeamHub\MyWork\Category;
use OCA\TeamHub\MyWork\IWorkProvider;
use OCA\TeamHub\MyWork\OpenTarget;
use OCA\TeamHub\MyWork\Priority;
use OCA\TeamHub\MyWork\WorkItem;
use OCA\TeamHub\MyWork\WorkItemPage;
use OCA\TeamHub\MyWork\WorkQuery;
use OCA\TeamHub\Service\DecisionCategoryService;
use OCA\TeamHub\Service\DecisionService;
use OCA\TeamHub\Service\MemberService;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * My Work provider for TeamHub Decisions (v4.5.23).
 *
 * The third provider, and the one that demonstrates the point of the contract:
 * Decisions is TeamHub's own module, so there is no foreign schema to probe and
 * no other app's service to call — and yet nothing outside this file changed to
 * add it. No new endpoint, no new frontend branch, no new column. One class and
 * one line in `Application::register`.
 *
 * ## What it shows
 *
 *  - a **finalized** decision the user is an approver for → **Action required**;
 *  - an **open** decision the user proposed → **Action required** (v4.5.25).
 *    `DecisionService::finalize` is proposer-only, so the proposer is the only
 *    person who can move it on. Filing it under "waiting for others" said the
 *    opposite of the truth and is what made open proposals invisible as work;
 *  - an **open** decision the user is an approver for → **Waiting for others**
 *    (v4.5.25), waiting on the proposer to finalize. Not actionable yet — only
 *    a finalized decision can be approved — but an approver should be able to
 *    see what is heading their way;
 *  - a **finalized** decision the user proposed → **Waiting for others** (the
 *    approvers now owe the next step);
 *  - a decision **approved / denied** recently that the user proposed or
 *    resolved → **Completed**.
 *
 * A decision in `withdrawn` never appears: nobody owes anything on it. A
 * decision nobody in this list owes anything on never appears either — being
 * able to comment is not the same as owing a step.
 *
 * ## Dates
 *
 * Decisions have no due date. Rather than invent one, `dueAt` stays null and
 * the rows sort by priority then title. That is why this provider does **not**
 * benefit from the action-required lead time — there is no deadline to lead.
 * An approval waiting on you is Action required from the moment it is
 * finalized, which is the correct reading anyway.
 *
 * ## Reasons are mandatory
 *
 * `DecisionService::approve()` and `deny()` both require a rationale, and that
 * is a deliberate property of the module rather than an inconvenience. The
 * items therefore carry `metadata.requiresReason = true`, and the frontend
 * prompts for text before executing — one generic mechanism, so a future
 * provider needing the same gets it for free.
 */
class DecisionWorkProvider implements IWorkProvider {

    public const ID = 'decisions';

    public const RESOURCE_TYPE = 'decision';

    // Source statuses. Administrators remap these to categories, so they are
    // part of the stored contract — do not rename without migrating the map.
    public const STATUS_AWAITING_APPROVAL = 'decision_awaiting_approval';
    public const STATUS_PROPOSED          = 'decision_proposed';
    public const STATUS_APPROVED          = 'decision_approved';
    public const STATUS_DENIED            = 'decision_denied';
    // v4.5.25 — the two open-decision states. Added rather than folded into
    // STATUS_PROPOSED because an administrator must be able to remap "I have
    // to finalize this" separately from "I am waiting on an approver": they
    // are opposite ends of the same decision.
    public const STATUS_AWAITING_FINALIZE = 'decision_awaiting_finalize';
    public const STATUS_OPEN_FOR_APPROVER = 'decision_open_for_approver';

    private ?string $unavailableReason = null;

    public function __construct(
        private IDBConnection $db,
        private DecisionMapper $decisionMapper,
        private DecisionService $decisionService,
        private DecisionCategoryService $categoryService,
        private MemberService $memberService,
        private IUserManager $userManager,
        private IL10N $l,
        private LoggerInterface $logger,
    ) {
    }

    // ---------------------------------------------------------------------
    // Identity + capabilities
    // ---------------------------------------------------------------------

    public function getId(): string {
        return self::ID;
    }

    public function getName(): string {
        return $this->l->t('Decisions');
    }

    public function getIcon(): string {
        return 'decisions';
    }

    public function getCapabilities(): array {
        return [
            'actions' => [
                ActionType::OPEN,
                ActionType::APPROVE,
                ActionType::REJECT,
                // v4.5.42 — the proposer closing their own drafting phase.
                ActionType::FINALIZE,
            ],
            'resourceTypes' => [self::RESOURCE_TYPE],
            'statuses'      => [
                self::STATUS_AWAITING_APPROVAL,
                self::STATUS_AWAITING_FINALIZE,
                self::STATUS_OPEN_FOR_APPROVER,
                self::STATUS_PROPOSED,
                self::STATUS_APPROVED,
                self::STATUS_DENIED,
            ],
            'categories' => [
                Category::ACTION_REQUIRED,
                Category::WAITING_FOR_OTHERS,
                Category::COMPLETED,
            ],
            'pagination'  => false,
            'incremental' => false,
        ];
    }

    /**
     * Decisions is a TeamHub module, not a separate app, so "available" means
     * the module is switched on somewhere. The per-team check happens during
     * the fetch — a user can easily be in one team using Decisions and three
     * that are not.
     */
    public function isAvailable(): bool {
        try {
            $this->decisionService->assertModuleEnabledGlobally();
            $this->unavailableReason = null;
            return true;
        } catch (\Throwable) {
            $this->unavailableReason = $this->l->t('The Decisions module is disabled in TeamHub administration settings.');
            return false;
        }
    }

    public function getUnavailableReason(): ?string {
        return $this->unavailableReason;
    }

    public function getSupportedFilters(): array {
        return ['teamIds', 'completedSince'];
    }

    public function getConfigSchema(): array {
        return [];
    }

    // ---------------------------------------------------------------------
    // Fetch
    // ---------------------------------------------------------------------

    public function fetchItems(WorkQuery $query): WorkItemPage {
        if (!$this->isAvailable() || $query->teamIds === []) {
            return WorkItemPage::empty();
        }

        // Only the teams that actually have the module on. Checking here
        // rather than filtering rows afterwards keeps the query small on
        // instances where one team out of twenty uses Decisions.
        $teamIds = array_values(array_filter(
            $query->teamIds,
            fn (string $teamId): bool => $this->isModuleOn($teamId),
        ));
        if ($teamIds === []) {
            return WorkItemPage::empty();
        }

        $truncated = false;
        $rows      = $this->loadRelevantDecisions($teamIds, $query, $truncated);
        if ($rows === []) {
            return WorkItemPage::empty();
        }

        // Approver sets are per (team, category) and shared by many rows, so
        // resolve each once rather than per decision.
        $approverCache = [];
        $items         = [];

        foreach ($rows as $decision) {
            $teamId    = $decision->getTeamId();
            $status    = $decision->getStatus();
            $proposer  = $decision->getProposedBy();
            $isMine    = $proposer === $query->userId;
            $isApprover = $this->isApprover($teamId, $decision, $query->userId, $approverCache);

            if ($status === 'finalized' && $isApprover) {
                $items[] = $this->buildItem(
                    $query, $decision,
                    Category::ACTION_REQUIRED, self::STATUS_AWAITING_APPROVAL, Priority::HIGH,
                    $this->l->t('You are an approver for this decision'),
                    null, null, true,
                );
                continue;
            }

            // v4.5.25 — an OPEN decision you proposed is Action required, not
            // "waiting for others". `DecisionService::finalize` is proposer-
            // only: nobody else on the team is able to move this forward, so
            // filing it under a heading that means "someone else owes you
            // something" was the wrong way round, and it is why open proposals
            // read as parked rather than as owed.
            if ($status === 'open' && $isMine) {
                $items[] = $this->buildItem(
                    $query, $decision,
                    Category::ACTION_REQUIRED, self::STATUS_AWAITING_FINALIZE, Priority::NORMAL,
                    $this->l->t('You proposed this — only you can finalize it'),
                    null, null, false,
                );
                continue;
            }

            // v4.5.25 — an OPEN decision you will have to approve. Not
            // actionable yet (only finalized decisions can be approved), so it
            // is genuinely Waiting for others — the proposer is the one who
            // owes the next step. It appears so that an approver can see what
            // is coming rather than being surprised by it at finalize time.
            if ($status === 'open' && $isApprover) {
                $items[] = $this->buildItem(
                    $query, $decision,
                    Category::WAITING_FOR_OTHERS, self::STATUS_OPEN_FOR_APPROVER, Priority::LOW,
                    $this->l->t('Open for discussion — it will come to you for approval'),
                    $this->proposerParty($decision),
                    null, false,
                );
                continue;
            }

            if ($status === 'finalized' && $isMine) {
                $items[] = $this->buildItem(
                    $query, $decision,
                    Category::WAITING_FOR_OTHERS, self::STATUS_PROPOSED, Priority::NORMAL,
                    $this->l->t('You proposed this and it is waiting for an approver'),
                    $this->firstApproverParty($teamId, $decision, $approverCache),
                    null, false,
                );
                continue;
            }

            if (($status === 'approved' || $status === 'denied')
                && $query->includeCompleted
                && ($isMine || $decision->getResolvedBy() === $query->userId)
            ) {
                // v4.5.25 — when it was *resolved*, not when it was finalized.
                // See the migration: decided_at is the finalize moment and is
                // deliberately never overwritten, so a proposal finalized weeks
                // ago and approved this morning looked weeks old and fell out
                // of the Completed window entirely.
                $decidedAt = $decision->getResolvedAt() ?? $decision->getDecidedAt();
                if ($decidedAt === null || $decidedAt < $query->completedSince()) {
                    continue;
                }
                $items[] = $this->buildItem(
                    $query, $decision,
                    Category::COMPLETED,
                    $status === 'approved' ? self::STATUS_APPROVED : self::STATUS_DENIED,
                    Priority::LOW,
                    $decision->getResolvedBy() === $query->userId
                        ? ($status === 'approved'
                            ? $this->l->t('You approved this decision')
                            : $this->l->t('You denied this decision'))
                        : $this->l->t('You proposed this decision'),
                    null, $decidedAt, false,
                );
            }
        }

        return new WorkItemPage($items, count($items), $truncated);
    }

    public function getItem(string $userId, string $providerItemId, array $allowedTeamIds): ?WorkItem {
        if (!$this->isAvailable() || $allowedTeamIds === []) {
            return null;
        }
        $decisionId = (int)$providerItemId;
        if ($decisionId <= 0) {
            return null;
        }

        $decision = $this->decisionMapper->findById($decisionId);
        if ($decision === null || !in_array($decision->getTeamId(), $allowedTeamIds, true)) {
            return null;
        }
        if (!$this->isModuleOn($decision->getTeamId())) {
            return null;
        }

        $query = new WorkQuery(userId: $userId, teamIds: $allowedTeamIds, now: time());
        $cache = [];

        $isApprover = $this->isApprover($decision->getTeamId(), $decision, $userId, $cache);
        $isMine     = $decision->getProposedBy() === $userId;

        // Same gate as the list: a decision that would never have appeared in
        // this user's queue cannot be acted on through it either.
        if (!$isApprover && !$isMine && $decision->getResolvedBy() !== $userId) {
            return null;
        }

        $status = $decision->getStatus();
        if ($status === 'finalized' && $isApprover) {
            return $this->buildItem($query, $decision, Category::ACTION_REQUIRED,
                self::STATUS_AWAITING_APPROVAL, Priority::HIGH,
                $this->l->t('You are an approver for this decision'), null, null, true);
        }
        if ($status === 'approved' || $status === 'denied') {
            return $this->buildItem($query, $decision, Category::COMPLETED,
                $status === 'approved' ? self::STATUS_APPROVED : self::STATUS_DENIED,
                Priority::LOW, $this->l->t('You proposed this decision'),
                null, $decision->getResolvedAt() ?? $decision->getDecidedAt(), false);
        }
        // v4.5.25 — mirrors fetchItems() for the two open states. Keep the two
        // in step: this is the path every action authorises against, so a rule
        // that exists in one and not the other is a permission bug waiting to
        // happen.
        if ($status === 'open' && $isMine) {
            return $this->buildItem($query, $decision, Category::ACTION_REQUIRED,
                self::STATUS_AWAITING_FINALIZE, Priority::NORMAL,
                $this->l->t('You proposed this — only you can finalize it'),
                null, null, false);
        }
        if ($status === 'open' && $isApprover) {
            return $this->buildItem($query, $decision, Category::WAITING_FOR_OTHERS,
                self::STATUS_OPEN_FOR_APPROVER, Priority::LOW,
                $this->l->t('Open for discussion — it will come to you for approval'),
                $this->proposerParty($decision), null, false);
        }
        return $this->buildItem($query, $decision, Category::WAITING_FOR_OTHERS,
            self::STATUS_PROPOSED, Priority::NORMAL,
            $this->l->t('You proposed this and it is waiting for an approver'),
            $this->firstApproverParty($decision->getTeamId(), $decision, $cache), null, false);
    }

    // ---------------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------------

    public function getAvailableActions(string $userId, WorkItem $item): array {
        $actions = [ActionType::OPEN];

        if ($item->status === self::STATUS_AWAITING_APPROVAL
            && ($item->permissions['canApprove'] ?? false)
        ) {
            $actions[] = ActionType::APPROVE;
            $actions[] = ActionType::REJECT;
        }

        // v4.5.42 — the proposer's own open proposal. STATUS_AWAITING_FINALIZE
        // is only ever built for `$isMine`, so the status is already the
        // ownership test; the service re-checks proposer-equality anyway.
        //
        // Editing the text is deliberately NOT an action here. My Work rows
        // are one-click verbs, and a proposal body is a rich-text field with
        // attachments — Open takes the proposer to the Decisions tab where
        // that editor lives, which is also where the discussion they are
        // responding to is linked.
        if ($item->status === self::STATUS_AWAITING_FINALIZE) {
            $actions[] = ActionType::FINALIZE;
        }

        return $actions;
    }

    public function executeAction(string $userId, WorkItem $item, string $action, array $params): ActionResult {
        if (!in_array($action, $this->getAvailableActions($userId, $item), true)) {
            return ActionResult::forbidden(
                $this->l->t('You are not an approver for this decision.'),
            );
        }

        $decisionId = (int)$item->providerItemId;
        $reason     = trim((string)($params['reason'] ?? $params['message'] ?? ''));

        // v4.5.42 — finalize takes no rationale, so it is handled before the
        // reason gate below. The proposal body IS the statement; asking for a
        // second one would be asking the proposer to explain their own text.
        if ($action === ActionType::FINALIZE) {
            try {
                $this->decisionService->finalizeProposal($item->teamId, $decisionId, $userId);
                return ActionResult::success(
                    $this->l->t('Proposal finalized. It is now with the approvers.'),
                    null,
                    true,
                );
            } catch (\InvalidArgumentException $e) {
                // The one case worth its own sentence: an empty proposal body.
                return ActionResult::failure($e->getMessage(), 'failed');
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][DecisionWorkProvider] finalize failed', [
                    'decisionId' => $decisionId, 'error' => $e->getMessage(),
                    'app' => Application::APP_ID,
                ]);
                return ActionResult::failure(
                    $this->l->t('That proposal could not be finalized.'),
                    'failed',
                );
            }
        }

        // Both approval verbs require a rationale. Refusing here — rather than
        // letting the service throw InvalidArgumentException — gives the user
        // a sentence they can act on instead of a 502.
        if ($reason === '') {
            return ActionResult::failure(
                $this->l->t('A decision needs a short reason. Add one and try again.'),
                'failed',
            );
        }

        try {
            if ($action === ActionType::APPROVE) {
                $this->decisionService->approve($item->teamId, $decisionId, $userId, $reason);
                return ActionResult::success($this->l->t('Decision approved.'), null, true);
            }
            $this->decisionService->deny($item->teamId, $decisionId, $reason, $userId);
            return ActionResult::success($this->l->t('Decision denied.'), null, true);
        } catch (\InvalidArgumentException $e) {
            return ActionResult::failure($e->getMessage(), 'failed');
        } catch (\Throwable $e) {
            $cls = get_class($e);
            if (str_contains($cls, 'AccessDenied')) {
                return ActionResult::forbidden(
                    $this->l->t('You are not an approver for this decision.'),
                );
            }
            if (str_contains($cls, 'DoesNotExist') || str_contains($cls, 'NotFound')) {
                return ActionResult::gone($this->l->t('This decision no longer exists.'));
            }
            // A status conflict — somebody approved it first — reads as a
            // RuntimeException from the service.
            if ($e instanceof \RuntimeException) {
                return ActionResult::conflict($e->getMessage());
            }

            $this->logger->error('[TeamHub][MyWork][Decisions] Action failed', [
                'action' => $action, 'decisionId' => $decisionId,
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return ActionResult::failure($this->l->t('That decision could not be updated.'));
        }
    }

    // ---------------------------------------------------------------------
    // Source reads
    // ---------------------------------------------------------------------

    /**
     * Decisions in the user's teams that could plausibly belong in a queue.
     *
     * The query lives on `DecisionMapper::findForWorkQueue()` with the module's
     * other queries rather than here — `findEntities()` is protected, and
     * reaching around a mapper to hand-hydrate entities would be worse than
     * the one method it costs.
     *
     * @param string[] $teamIds
     * @return Decision[]
     */
    private function loadRelevantDecisions(array $teamIds, WorkQuery $query, ?bool &$truncated = null): array {
        try {
            return $this->decisionMapper->findForWorkQueue(
                $teamIds,
                $query->userId,
                $query->completedSince(),
                $query->perProviderCap,
                $truncated,
            );
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWork][Decisions] Decision load failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            // Rethrow so the registry marks the provider errored rather than
            // reporting an empty — but genuine-looking — work queue.
            throw $e;
        }
    }

    /** @var array<string, bool> */
    private array $moduleCache = [];

    private function isModuleOn(string $teamId): bool {
        if (!isset($this->moduleCache[$teamId])) {
            try {
                $this->moduleCache[$teamId] = $this->decisionService->isModuleActiveForTeam($teamId);
            } catch (\Throwable) {
                $this->moduleCache[$teamId] = false;
            }
        }
        return $this->moduleCache[$teamId];
    }

    /**
     * Mirrors `DecisionService::assertApproverFor` exactly: a named category
     * means its approver list decides; no category (or one that no longer
     * exists) means team admins decide.
     *
     * Kept as a read-only twin rather than calling the assert, because this is
     * a "may they?" question asked for every row and the service's version
     * answers by throwing. The write path still goes through the service, so
     * the authoritative check is never skipped — this only decides whether to
     * *offer* the buttons.
     *
     * @param array<string, string[]> $cache team|category => approver uids
     */
    private function isApprover(string $teamId, Decision $decision, string $userId, array &$cache): bool {
        $approvers = $this->approversFor($teamId, $decision, $cache);
        if ($approvers === null) {
            // No matching category: the service falls through to admin-only.
            return $this->isTeamAdmin($teamId, $userId);
        }
        return in_array($userId, $approvers, true);
    }

    /**
     * @param array<string, string[]> $cache
     * @return string[]|null null when the decision has no matching category
     */
    private function approversFor(string $teamId, Decision $decision, array &$cache): ?array {
        $categoryName = (string)$decision->getCategory();
        if ($categoryName === '') {
            return null;
        }

        $key = $teamId . '|' . $categoryName;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            foreach ($this->categoryService->listForTeam($teamId) as $cat) {
                if (($cat['name'] ?? '') === $categoryName) {
                    return $cache[$key] = array_values((array)($cat['approvers'] ?? []));
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][MyWork][Decisions] Category lookup failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        return $cache[$key] = null;
    }

    /** @var array<string, bool> */
    private array $adminCache = [];

    /**
     * v4.5.25 — delegates to `MemberService::getMemberLevelFromDb`, the same
     * lookup `requireAdminLevel` uses.
     *
     * It used to be a hand-rolled query here, and it had drifted: it filtered
     * on `user_type` and ignored `status`, where the authoritative one filters
     * on `status = 'Member'` and ignores `user_type`. A member with a pending
     * invitation therefore counted as an admin for the purpose of *showing* a
     * decision, and would then have been refused when they acted on it. Two
     * queries answering one question is how that happens, so now there is one.
     */
    private function isTeamAdmin(string $teamId, string $userId): bool {
        $key = $teamId . '|' . $userId;
        if (!isset($this->adminCache[$key])) {
            try {
                // 8 = admin, 9 = owner on the Circles level scale.
                $this->adminCache[$key] =
                    $this->memberService->getMemberLevelFromDb($this->db, $teamId, $userId) >= 8;
            } catch (\Throwable) {
                $this->adminCache[$key] = false;
            }
        }
        return $this->adminCache[$key];
    }

    /**
     * The proposer, as the party an approver is waiting on while a decision is
     * still open. They are the only one who can finalize it, so they are the
     * only honest answer to "who am I waiting for".
     *
     * @return array{type:string,id:string,displayName:string}|null
     */
    private function proposerParty(Decision $decision): ?array {
        $uid = $decision->getProposedBy();
        if ($uid === '') {
            return null;
        }
        return [
            'type'        => 'user',
            'id'          => $uid,
            'displayName' => $this->displayName($uid),
        ];
    }

    /**
     * @param array<string, string[]> $cache
     * @return array{type:string,id:string,displayName:string}|null
     */
    private function firstApproverParty(string $teamId, Decision $decision, array &$cache): ?array {
        $approvers = $this->approversFor($teamId, $decision, $cache);
        $uid       = $approvers[0] ?? null;
        if ($uid === null) {
            return null;
        }
        return [
            'type'        => 'user',
            'id'          => $uid,
            'displayName' => $this->displayName($uid),
        ];
    }

    // ---------------------------------------------------------------------
    // Item construction
    // ---------------------------------------------------------------------

    private function buildItem(
        WorkQuery $query,
        Decision $decision,
        string $category,
        string $status,
        string $priority,
        string $reason,
        ?array $waitingFor,
        ?int $completedAt,
        bool $canApprove,
    ): WorkItem {
        $teamId = $decision->getTeamId();

        return WorkItem::make([
            'providerId'     => self::ID,
            'providerItemId' => (string)$decision->getId(),
            'teamId'         => $teamId,
            'teamName'       => $query->teamName($teamId),
            'category'       => $category,
            'title'          => $decision->getQuestion(),
            // The category is the closest thing a decision has to a "document
            // name" — it is how members refer to them in practice.
            'subtitle'       => (string)($decision->getCategory() ?: $this->l->t('Decision')),
            'resourceType'   => self::RESOURCE_TYPE,
            'resourceId'     => (string)$decision->getId(),
            // TeamHub's own deep link. Only reached if a client cannot honour
            // the openTarget below — it costs a full app reload, which is why
            // it is the fallback and not the mechanism.
            'resourceUrl'    => '/apps/teamhub?team=' . rawurlencode($teamId)
                . '&decision=' . $decision->getId(),
            // v4.5.24 — a decision is not an embeddable resource, it is a
            // TeamHub tab. §2.71 recorded that this needed its own branch in
            // App.vue; declaring the mechanism is what removed the branch. The
            // target is the message id because that is what the Decisions view
            // scrolls to, the same handle the ?decision= deep link resolves to.
            'openTarget'     => OpenTarget::teamHubView('decisions', $decision->getMessageId()),
            'priority'       => $priority,
            'status'         => $status,
            'reason'         => $reason,
            'createdAt'      => $decision->getCreatedAt(),
            'updatedAt'      => $decision->getDecidedAt() ?? $decision->getCreatedAt(),
            // Decisions carry no deadline; see the class docblock.
            'dueAt'          => null,
            'completedAt'    => $completedAt,
            'assignee'       => null,
            'waitingFor'     => $waitingFor,
            'availableActions' => [],
            'metadata'       => [
                'decisionId'     => $decision->getId(),
                'messageId'      => $decision->getMessageId(),
                'workflowStatus' => $decision->getStatus(),
                'impact'         => $decision->getImpact(),
                'level'          => $decision->getLevel(),
                'proposer'       => [
                    'uid'         => $decision->getProposedBy(),
                    'displayName' => $this->displayName($decision->getProposedBy()),
                ],
                // Tells the frontend to prompt for a rationale before it
                // executes approve or reject. Generic flag, not a Decisions
                // special case — any future provider whose actions need free
                // text sets the same key.
                'requiresReason' => $canApprove,
            ],
            'permissions'    => [
                'canOpen'    => true,
                'canApprove' => $canApprove,
                'canReject'  => $canApprove,
            ],
        ]);
    }

    /** @var array<string,string> */
    private array $nameCache = [];

    private function displayName(string $uid): string {
        if (!isset($this->nameCache[$uid])) {
            $this->nameCache[$uid] = $this->userManager->get($uid)?->getDisplayName() ?? $uid;
        }
        return $this->nameCache[$uid];
    }
}
