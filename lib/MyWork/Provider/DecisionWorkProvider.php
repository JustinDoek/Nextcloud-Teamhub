<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork\Provider;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\Decision;
use OCA\TeamHub\Db\DecisionMapper;
use OCA\TeamHub\Db\DecisionReview;
use OCA\TeamHub\Db\DecisionReviewMapper;
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
use OCA\TeamHub\Service\DecisionReviewService;
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
 * ## The review cycle (v4.11.18)
 *
 * An approved decision can be put back in front of its approvers — by a team
 * member who says circumstances changed, or by its category's review period
 * falling due. Those produce rows of their own, from
 * `teamhub_dec_reviews` rather than from the decision:
 *
 *  - an **open review** the user is an approver for → **Action required**.
 *    What the row offers depends on what was asked (v4.11.26): a **periodic**
 *    review carries *Keep decision* and *Archive decision*, both answerable
 *    from the row; a **requested** one carries *Deny request* only. Accepting
 *    a request means rewriting the decision's wording, and a form is not a
 *    queue verb — the row's *Open* takes the approver to the decision, where
 *    the Review block holds both answers.
 *  - an **open review the user asked for** → **Waiting for others**, waiting
 *    on the approvers.
 *
 * A completed review produces no row. The decision it kept, denied or
 * revised is still in force and the decision it archived is retired, and
 * none of those is work.
 *
 * **A review row's `providerItemId` is prefixed** — see `REVIEW_PREFIX`.
 * `superseded` and `archived` decisions never produce a decision row at all:
 * they are terminal and nobody owes anything on them.
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
    // v4.11.18 — the review cycle of an approved decision. Three states and
    // not one, for the same reason as above: an administrator must be able to
    // remap "a colleague is asking me to look at this again" separately from
    // "the calendar is" and from "I asked and am waiting".
    public const STATUS_REVIEW_REQUESTED = 'decision_review_requested';
    public const STATUS_REVIEW_DUE       = 'decision_review_due';
    public const STATUS_REVIEW_WAITING   = 'decision_review_waiting';

    /**
     * v4.11.18 — the prefix on a review row's `providerItemId`.
     *
     * A review row and a decision row can both exist for one decision at
     * once (the proposer's *Completed* row, and their *Waiting* row for the
     * review they asked for), and two items sharing an id would share their
     * snooze state and route each other's actions. The marker-in-front shape
     * is the one `/mywork-workflows` names: `review:{reviewId}`, told apart
     * from a bare decision id by the prefix.
     */
    public const REVIEW_PREFIX = 'review:';

    private ?string $unavailableReason = null;

    public function __construct(
        private IDBConnection $db,
        private DecisionMapper $decisionMapper,
        private DecisionService $decisionService,
        // v4.11.18 — the review rows. The mapper for the single-row read the
        // action endpoint makes, the service for the list and the write.
        private DecisionReviewMapper $reviewMapper,
        private DecisionReviewService $reviewService,
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
                self::STATUS_REVIEW_REQUESTED,
                self::STATUS_REVIEW_DUE,
                self::STATUS_REVIEW_WAITING,
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

        // Approver sets are per (team, category) and shared by many rows, so
        // resolve each once rather than per decision.
        $approverCache = [];
        // v4.11.18 — the review rows come from their own table and are
        // independent of the decision rows: an approved decision under review
        // produces no decision row for an approver at all, which is why
        // folding them into the loop below would have missed every one.
        $items = $this->reviewItems($teamIds, $query, $approverCache);

        if ($rows === []) {
            return $items === []
                ? WorkItemPage::empty()
                : new WorkItemPage($items, count($items), $truncated);
        }

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

    /**
     * v4.11.18 — the rows that come from an open review rather than from a
     * decision.
     *
     * Two audiences, and a person can only be in one of them for a given
     * review: an approver who asked for the review themselves gets the
     * approver's row, because being able to answer outranks waiting for an
     * answer. Everybody else sees nothing — a review is between the person
     * who asked and the people who can answer.
     *
     * @param string[]                $teamIds
     * @param array<string, string[]> $approverCache shared with the decision loop
     * @return WorkItem[]
     */
    private function reviewItems(array $teamIds, WorkQuery $query, array &$approverCache): array {
        try {
            $reviews = $this->reviewService->openReviewsForTeams($teamIds);
        } catch (\Throwable $e) {
            // Unlike the decision load, this one does not rethrow. The
            // decision rows are the provider's reason to exist and a silent
            // empty there would be a lie; a review row missing is a feature
            // degrading, and marking the whole provider errored would take
            // the queue down with it.
            $this->logger->warning('[TeamHub][MyWork][Decisions] Review load failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return [];
        }
        if ($reviews === []) {
            return [];
        }

        $decisions = $this->decisionMapper->findByIds(
            array_map(static fn (DecisionReview $r): int => $r->getDecisionId(), $reviews),
        );

        $items = [];
        foreach ($reviews as $review) {
            $decision = $decisions[$review->getDecisionId()] ?? null;
            // A decision that was deleted, or retired by another road while
            // this review was open. Neither is work: `retire()` closes an
            // open review, so a retired one here means a row older than that
            // rule, and acting on it would fail at the service anyway.
            if ($decision === null || $decision->getStatus() !== 'approved') {
                continue;
            }

            $teamId     = $decision->getTeamId();
            $isApprover = $this->isApprover($teamId, $decision, $query->userId, $approverCache);

            if ($isApprover) {
                $items[] = $this->buildReviewItem($query, $review, $decision, true);
                continue;
            }
            if ($review->getRequestedBy() === $query->userId) {
                $items[] = $this->buildReviewItem($query, $review, $decision, false);
            }
        }

        return $items;
    }

    public function getItem(string $userId, string $providerItemId, array $allowedTeamIds): ?WorkItem {
        if (!$this->isAvailable() || $allowedTeamIds === []) {
            return null;
        }

        // v4.11.18 — a review row, told apart by its prefix. Resolved before
        // the decision path, because `(int)'review:12'` is 0 and would fall
        // through to "no such decision" instead of to the review.
        if (str_starts_with($providerItemId, self::REVIEW_PREFIX)) {
            return $this->getReviewItem($userId, $providerItemId, $allowedTeamIds);
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

    /**
     * One review, for the action endpoint and for a refresh of a single row.
     *
     * The same gate as `reviewItems()`, and that is the point: this is the
     * path every action authorises against, so a rule that exists in one and
     * not the other is a permission bug waiting to happen (the note
     * v4.5.25 left on the two decision paths, which still holds).
     */
    private function getReviewItem(string $userId, string $providerItemId, array $allowedTeamIds): ?WorkItem {
        $reviewId = (int)substr($providerItemId, strlen(self::REVIEW_PREFIX));
        if ($reviewId <= 0) {
            return null;
        }

        $review = $this->reviewMapper->findById($reviewId);
        if ($review === null
            || !$review->isOpen()
            || !in_array($review->getTeamId(), $allowedTeamIds, true)
        ) {
            return null;
        }
        if (!$this->isModuleOn($review->getTeamId())) {
            return null;
        }

        $decision = $this->decisionMapper->findById($review->getDecisionId());
        if ($decision === null || $decision->getStatus() !== 'approved') {
            return null;
        }

        $query = new WorkQuery(userId: $userId, teamIds: $allowedTeamIds, now: time());
        $cache = [];

        $isApprover = $this->isApprover($review->getTeamId(), $decision, $userId, $cache);
        if (!$isApprover && $review->getRequestedBy() !== $userId) {
            return null;
        }

        return $this->buildReviewItem($query, $review, $decision, $isApprover);
    }

    // ---------------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------------

    public function getAvailableActions(string $userId, WorkItem $item): array {
        $actions = [ActionType::OPEN];

        // v4.11.18 — a review the user can answer. Two verbs, both already
        // in the provider's capabilities: COMPLETE is "I looked and it
        // stands", REJECT is "the other one". Their generic labels are wrong
        // for this row, so the row carries its own in `metadata.actionLabels`
        // — the mechanism `/mywork-workflows` prescribes, rather than a new
        // ActionType for a workflow verb.
        //
        // Not APPROVE/REJECT: approving again would claim a second approval
        // the record does not have and the audit stream would show two.
        //
        // **v4.11.26 — a periodic review has two one-click answers and a
        // requested one has only the refusal.** The 4.11.24 split (periodic
        // → keep/archive, requested → accept/deny) never reached this file,
        // so every review answered from My Work was recorded as `kept` or
        // `archived` whatever had been asked — review #8 of Marketing #11 on
        // the test instance is one of those. Accepting now rewrites the
        // decision's wording, which is a form and not a verb, so the row
        // sends the approver to the decision for it: COMPLETE is dropped on
        // a requested review rather than quietly meaning something else.
        if ($item->status === self::STATUS_REVIEW_DUE
            && ($item->permissions['canApprove'] ?? false)
        ) {
            return [ActionType::OPEN, ActionType::COMPLETE, ActionType::REJECT];
        }
        if ($item->status === self::STATUS_REVIEW_REQUESTED
            && ($item->permissions['canApprove'] ?? false)
        ) {
            return [ActionType::OPEN, ActionType::REJECT];
        }

        // The requester's waiting row. Open and nothing else: they asked, and
        // taking it back is not a thing the spec gives them.
        if ($item->status === self::STATUS_REVIEW_WAITING) {
            return $actions;
        }

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

        // v4.11.18 — a review's two verbs, before the decision paths: the
        // item id is prefixed, so `(int)` on it is 0 and every branch below
        // would act on the wrong thing.
        if ($item->status === self::STATUS_REVIEW_REQUESTED || $item->status === self::STATUS_REVIEW_DUE) {
            return $this->executeReviewAction($userId, $item, $action, $params);
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

    /**
     * Answer a review from its row (v4.11.18).
     *
     * v4.11.23 — the reason is **required**, as it is for approve and deny.
     * The row sets `metadata.requiresReason`, so the generic dialog refuses
     * an empty one before it posts; the service refuses it again.
     *
     * @param array<string, mixed> $params
     */
    private function executeReviewAction(string $userId, WorkItem $item, string $action, array $params): ActionResult {
        $decisionId = (int)($item->metadata['decisionId'] ?? 0);
        if ($decisionId <= 0) {
            return ActionResult::gone($this->l->t('This review no longer exists.'));
        }

        $reason = trim((string)($params['reason'] ?? $params['message'] ?? ''));

        // v4.11.26 — the outcome depends on what was asked, not only on
        // which button was pressed. A periodic review asks "is this still
        // right" and is answered keep/archive; a requested one is a request
        // and is answered accept/deny, of which only *deny* is a one-click
        // answer. `getAvailableActions()` does not offer COMPLETE on a
        // requested review, so the `kept` arm below is unreachable from it —
        // the match is written out in full anyway, because the next person to
        // add a status here will read this and not the gate.
        $requested = $item->status === self::STATUS_REVIEW_REQUESTED;
        $outcome   = $action === ActionType::REJECT
            ? ($requested ? 'denied' : 'archived')
            : ($requested ? 'accepted' : 'kept');

        // Refused here rather than letting the service throw, so the row
        // shows a sentence the person can act on instead of a 502 — the same
        // treatment approve and deny get a few lines down.
        if ($reason === '') {
            return ActionResult::failure(
                $this->l->t('A review needs a short reason. Add one and try again.'),
                'failed',
            );
        }

        try {
            $this->reviewService->complete(
                $item->teamId,
                $decisionId,
                $outcome,
                $reason,
                $userId,
            );

            // `denied` and `kept` share one sentence on purpose: from the
            // row's point of view they are the same news, which is that the
            // decision did not move.
            return ActionResult::success(
                $outcome === 'archived'
                    ? $this->l->t('Decision archived.')
                    : $this->l->t('Review completed. The decision stands.'),
                null,
                true,
            );
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
            // Somebody else answered it first, or the team has no archive
            // category yet — both read as a RuntimeException from the
            // service, and both are states the row should refresh out of.
            if ($e instanceof \RuntimeException) {
                return ActionResult::conflict($e->getMessage());
            }

            $this->logger->error('[TeamHub][MyWork][Decisions] Review action failed', [
                'action' => $action, 'decisionId' => $decisionId,
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return ActionResult::failure($this->l->t('That review could not be completed.'));
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

    /**
     * A row for one open review (v4.11.18).
     *
     * @param bool $canAnswer true for an approver, false for the member who
     *                        asked — the one flag that decides the category,
     *                        the priority, the reason sentence and the
     *                        buttons, because they are four readings of one
     *                        fact.
     */
    private function buildReviewItem(
        WorkQuery      $query,
        DecisionReview $review,
        Decision       $decision,
        bool           $canAnswer,
    ): WorkItem {
        $teamId    = $decision->getTeamId();
        $requested = $review->isRequested();
        $asker     = $review->getRequestedBy();
        // Declared, not inlined: `firstApproverParty()` takes it by
        // reference, and PHP refuses an assignment in that position.
        $cache     = [];

        if ($canAnswer) {
            $status   = $requested ? self::STATUS_REVIEW_REQUESTED : self::STATUS_REVIEW_DUE;
            $category = Category::ACTION_REQUIRED;
            $priority = Priority::HIGH;
            $reason   = $requested
                ? $this->l->t('%s asked you to review this decision', [$this->displayName($asker)])
                : $this->l->t('This decision has reached its review date');
            $waiting  = null;
        } else {
            $status   = self::STATUS_REVIEW_WAITING;
            $category = Category::WAITING_FOR_OTHERS;
            $priority = Priority::NORMAL;
            $reason   = $this->l->t('You asked for a review — it is with the approvers');
            $waiting  = $this->firstApproverParty($teamId, $decision, $cache);
        }

        return WorkItem::make([
            'providerId'     => self::ID,
            'providerItemId' => self::REVIEW_PREFIX . $review->getId(),
            'teamId'         => $teamId,
            'teamName'       => $query->teamName($teamId),
            'category'       => $category,
            'title'          => $decision->getQuestion(),
            // Says what the row is about before it says which decision — a
            // queue with both a decision row and a review row for the same
            // subject needs the difference on the row, not in the detail.
            'subtitle'       => $decision->getCategory()
                ? $this->l->t('Review · %s', [(string)$decision->getCategory()])
                : $this->l->t('Review'),
            'resourceType'   => self::RESOURCE_TYPE,
            'resourceId'     => (string)$decision->getId(),
            'resourceUrl'    => '/apps/teamhub?team=' . rawurlencode($teamId)
                . '&decision=' . $decision->getId(),
            'openTarget'     => OpenTarget::teamHubView('decisions', $decision->getMessageId()),
            'priority'       => $priority,
            'status'         => $status,
            'reason'         => $reason,
            'createdAt'      => (int)$review->getCreatedAt(),
            'updatedAt'      => (int)$review->getCreatedAt(),
            // The due date **is** a deadline, unlike everything else this
            // provider shows: a periodic review has one by construction, and
            // a requested review inherits whatever the decision carried. The
            // class docblock's "decisions have no due date" is still true of
            // decisions and was never true of reviews.
            'dueAt'          => $decision->getReviewDueAt(),
            'completedAt'    => null,
            'assignee'       => null,
            'waitingFor'     => $waiting,
            'availableActions' => [],
            'metadata'       => [
                'decisionId'     => (int)$decision->getId(),
                'reviewId'       => (int)$review->getId(),
                'messageId'      => $decision->getMessageId(),
                'workflowStatus' => $decision->getStatus(),
                'reviewKind'     => $review->getKind(),
                'impact'         => $decision->getImpact(),
                'level'          => $decision->getLevel(),
                'motivation'     => $review->getMotivation(),
                'proposer'       => [
                    'uid'         => $decision->getProposedBy(),
                    'displayName' => $this->displayName($decision->getProposedBy()),
                ],
                // v4.11.23 — required, like approve and deny. The generic
                // dialog prompts for it and will not submit it empty.
                'requiresReason' => $canAnswer,
                // The row's own words. The generic labels (*Complete*,
                // *Reject*) describe a task and a refusal; this row offers
                // outcomes for a decision — and which ones depends on what
                // was asked (v4.11.26). A requested review carries only
                // *Deny*: accepting rewrites the wording, and that needs the
                // decision panel rather than a confirm dialog.
                'actionLabels'   => $this->reviewActionLabels($canAnswer, $requested),
                'confirm'        => $this->reviewConfirmations($canAnswer, $requested),
                'workflow'       => $this->reviewWorkflow($review, $requested, $asker),
            ],
            'permissions'    => [
                'canOpen'    => true,
                // `canApprove` is what `getAvailableActions()` reads. Named
                // for the capability and not for the verb, which is why it
                // can gate a review's two outcomes as well as approve/deny.
                'canApprove' => $canAnswer,
                'canReject'  => $canAnswer,
            ],
        ]);
    }

    /**
     * The verbs this review row offers, in its own words (v4.11.26).
     *
     * @return array<string, string>
     */
    private function reviewActionLabels(bool $canAnswer, bool $requested): array {
        if (!$canAnswer) {
            return [];
        }
        if ($requested) {
            return [
                // TRANSLATORS: My Work row action — the approver refuses the review request; the decision stands unchanged
                ActionType::REJECT => $this->l->t('Deny request'),
            ];
        }
        return [
            // TRANSLATORS: My Work row action — the reviewer found nothing to change; the decision stands and its review clock restarts
            ActionType::COMPLETE => $this->l->t('Keep decision'),
            // TRANSLATORS: My Work row action — the reviewer retires the decision into the team's archive
            ActionType::REJECT   => $this->l->t('Archive decision'),
        ];
    }

    /**
     * What the confirm dialog says before each of them (v4.11.26).
     *
     * @return array<string, array<string, string>>
     */
    private function reviewConfirmations(bool $canAnswer, bool $requested): array {
        if (!$canAnswer) {
            return [];
        }
        if ($requested) {
            return [
                ActionType::REJECT => [
                    'title' => $this->l->t('Deny this review request?'),
                    // Says what deny *is*, because the word on its own reads
                    // as a refusal of the decision rather than of the
                    // request, and that is the misreading this whole version
                    // exists to clear up.
                    'body'  => $this->l->t('The decision stays in force exactly as it is, and its review period starts again from today. To change its wording instead, open the decision and accept the request there.'),
                    'reasonLabel' => $this->l->t('Reason'),
                ],
            ];
        }
        return [
            ActionType::COMPLETE => [
                'title' => $this->l->t('Keep this decision?'),
                'body'  => $this->l->t('The decision stays in force and its review period starts again from today.'),
                'reasonLabel' => $this->l->t('Reason'),
            ],
            ActionType::REJECT   => [
                'title' => $this->l->t('Archive this decision?'),
                'body'  => $this->l->t('It moves to the team archive and becomes read-only. The record and its audit trail are kept.'),
                'reasonLabel' => $this->l->t('Reason'),
            ],
        ];
    }

    /**
     * The whole review, on every row of it — `/mywork-workflows` § the
     * tracker. Computed here rather than per audience, so the approver and
     * the requester cannot be shown two different stories.
     *
     * Three steps for a requested review and two for a periodic one: nobody
     * asked for the periodic one, and a first step reading *Requested* with
     * no actor would be the tracker inventing a person.
     *
     * @return array{steps: array<int, array<string, mixed>>, current: int}
     */
    private function reviewWorkflow(DecisionReview $review, bool $requested, string $asker): array {
        $steps = [];

        if ($requested) {
            $steps[] = [
                'label' => $this->l->t('Review requested'),
                'state' => 'done',
                'actor' => $this->displayName($asker),
                'at'    => (int)$review->getCreatedAt(),
            ];
        } else {
            $steps[] = [
                'label' => $this->l->t('Review date reached'),
                'state' => 'done',
                'actor' => null,
                'at'    => (int)$review->getCreatedAt(),
            ];
        }

        // v4.11.26 — the last step says which pair of answers is actually on
        // offer. A requested review is accepted or denied; a periodic one is
        // kept or archived. One label for both told half the readers the
        // wrong story.
        $steps[] = [
            'label' => $requested
                // TRANSLATORS: workflow tracker step — the approver will either accept the review request (and reword the decision) or deny it
                ? $this->l->t('Approver accepts or denies')
                // TRANSLATORS: workflow tracker step — the approver will either let the decision stand or retire it into the archive
                : $this->l->t('Approver keeps or archives'),
            'state' => 'current',
            'actor' => null,
            'at'    => null,
        ];

        return ['steps' => $steps, 'current' => count($steps)];
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
