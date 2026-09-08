<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork\Provider;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\FileReview;
use OCA\TeamHub\Db\FileReviewer;
use OCA\TeamHub\Db\FileReviewMapper;
use OCA\TeamHub\Db\FileReviewerMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\MyWork\ActionResult;
use OCA\TeamHub\MyWork\ActionType;
use OCA\TeamHub\MyWork\Category;
use OCA\TeamHub\MyWork\IWorkProvider;
use OCA\TeamHub\MyWork\OpenTarget;
use OCA\TeamHub\MyWork\Priority;
use OCA\TeamHub\MyWork\WorkItem;
use OCA\TeamHub\MyWork\WorkItemPage;
use OCA\TeamHub\MyWork\WorkQuery;
use OCA\TeamHub\Service\FileReviewService;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * My Work provider for TeamHub file reviews (v4.8.18).
 *
 * ## The five rows
 *
 *  - a reviewer who has not completed, on an open review → **Action required**;
 *  - the requester of an open review somebody still owes → **Waiting for
 *    others**, and **Action required** once the review's due date is within
 *    the action window or past (v4.8.21). `MyWorkService` will not make that
 *    promotion itself — it exempts Waiting for others from due-date escalation,
 *    on the sound general rule that a deadline on somebody else's step is not
 *    your task. File reviews are the exception that proves it: the requester
 *    has a step of their own the entire time, and a review that has run out of
 *    time with people still silent is precisely when they need to decide
 *    whether to chase or to close. A review with no due date never escalates;
 *  - the requester's reason line carries **how far it has got and who it is
 *    waiting on**, because a row that says only "waiting for others" does not
 *    answer the question it raises;
 *  - the requester of an open review **everybody has completed** → **Action
 *    required**, because they are now the only person who can move it on. Same
 *    reasoning DecisionWorkProvider applies to an open proposal: filing it
 *    under a heading that means "somebody else owes you something" would say
 *    the opposite of the truth;
 *  - a reviewer who has completed, on a still-open review → **Completed**;
 *  - anybody on a recently closed review → **Completed** — with the exception
 *    below, which is the whole reason this docblock is long.
 *
 * ## A reviewer who never completed sees nothing once it is closed
 *
 * When the requester closes a review, every outstanding reviewer's item leaves
 * their queue. It does not move to Completed, and it does not linger as
 * actionable: the obligation ended without them acting, so there is nothing to
 * do and nothing they did.
 *
 * Putting the row under Completed instead would put their name against work
 * they never performed, which is the one thing a work queue must never do.
 * Leaving it under Action required would be worse — a button that cannot work,
 * on a review that is over.
 *
 * Two things follow, and both are load-bearing:
 *
 *  1. **`getItem()` enforces it too, not just `fetchItems()`.** `getItem()` is
 *     the path every action authorises against, so a browser tab still showing
 *     the row must not be able to complete it. It returns null, the service
 *     layer answers `conflict`, and the row goes away on refresh.
 *  2. **The reviewer row is still in the database.** This is a visibility rule,
 *     not a delete — the requester has to be able to see who never responded,
 *     and `FileReviewer` is the only record of it.
 *
 * ## Dates
 *
 * A review may carry a due date, and when it does it is a real one the
 * requester typed rather than something derived here. `dueAt` is passed
 * straight through, which is what makes My Work's overdue promotion work
 * without this provider knowing anything about it. Reviews without one sort by
 * priority and title, exactly like decisions.
 */
class FileReviewWorkProvider implements IWorkProvider {

    public const ID = 'file_review';

    public const RESOURCE_TYPE = 'file';

    // Source statuses. Administrators remap these to categories, so they are
    // part of the stored contract — do not rename without migrating the map.
    public const STATUS_REQUESTED      = 'file_review_requested';
    public const STATUS_AWAITING       = 'file_review_awaiting';
    public const STATUS_READY_TO_CLOSE = 'file_review_ready_to_close';
    public const STATUS_COMPLETED      = 'file_review_completed';
    public const STATUS_CLOSED         = 'file_review_closed';
    /**
     * v4.8.21 — the requester's row on a review that has reached its due date
     * with reviewers still outstanding. Its own status rather than a reuse of
     * `AWAITING`, so an administrator can map "this is running out of time" to
     * a different category from "this is simply in progress" — the two are
     * opposite ends of the same wait, the same argument
     * `DecisionWorkProvider` makes for its two open-decision statuses.
     */
    public const STATUS_DUE            = 'file_review_due';

    private ?string $unavailableReason = null;

    /** @var array<string,string> */
    private array $nameCache = [];

    public function __construct(
        private FileReviewMapper $reviewMapper,
        private FileReviewerMapper $reviewerMapper,
        private FileReviewService $reviewService,
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
        // TRANSLATORS: name of the My Work source that lists file review requests
        return $this->l->t('File reviews');
    }

    public function getIcon(): string {
        return 'file_review';
    }

    public function getCapabilities(): array {
        return [
            'actions' => [
                ActionType::OPEN,
                ActionType::COMPLETE,
                // v4.8.18 — the requester's verb. See ActionType::CLOSE for
                // why it is not COMPLETE.
                ActionType::CLOSE,
            ],
            'resourceTypes' => [self::RESOURCE_TYPE],
            'statuses'      => [
                self::STATUS_REQUESTED,
                self::STATUS_AWAITING,
                self::STATUS_DUE,
                self::STATUS_READY_TO_CLOSE,
                self::STATUS_COMPLETED,
                self::STATUS_CLOSED,
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
     * File reviews are a TeamHub module, so "available" is the global switch.
     * The per-team switch is applied during the fetch — one team using reviews
     * out of twenty is the normal case, not the exception.
     */
    public function isAvailable(): bool {
        if (!$this->reviewService->isEnabledGlobally()) {
            $this->unavailableReason = $this->l->t('File reviews are disabled in TeamHub administration settings.');
            return false;
        }

        $this->unavailableReason = null;
        return true;
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

        $teamIds = array_values(array_filter(
            $query->teamIds,
            fn (string $teamId): bool => $this->reviewService->isEnabledForTeam($teamId),
        ));
        if ($teamIds === []) {
            return WorkItemPage::empty();
        }

        try {
            $reviews = $this->relevantReviews($query->userId, $teamIds);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWork][FileReview] could not load reviews', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            throw $e;
        }
        if ($reviews === []) {
            return WorkItemPage::empty();
        }

        $rosters = $this->reviewerMapper->findByReviewIds(array_keys($reviews));
        $items   = [];

        foreach ($reviews as $reviewId => $review) {
            $item = $this->buildFor($query->userId, $review, $rosters[$reviewId] ?? [], $query);
            if ($item === null) {
                continue;
            }
            if ($item->category === Category::COMPLETED) {
                if (!$query->includeCompleted) {
                    continue;
                }
                if (($item->completedAt ?? 0) < $query->completedSince()) {
                    continue;
                }
            }
            $items[] = $item;
        }

        return new WorkItemPage($items, count($items), false);
    }

    public function getItem(string $userId, string $providerItemId, array $allowedTeamIds): ?WorkItem {
        if (!$this->isAvailable() || $allowedTeamIds === []) {
            return null;
        }

        $reviewId = (int)$providerItemId;
        if ($reviewId <= 0) {
            return null;
        }

        try {
            $review = $this->reviewMapper->findById($reviewId);
        } catch (\Throwable) {
            return null;
        }

        if (!in_array($review->getTeamId(), $allowedTeamIds, true)
            || !$this->reviewService->isEnabledForTeam($review->getTeamId())
        ) {
            return null;
        }

        // The same rules as the list, deliberately through the same method.
        // This is the authorisation path for every action, so a rule that
        // existed in one and not the other would be a permission bug rather
        // than a display inconsistency.
        return $this->buildFor(
            $userId,
            $review,
            $this->reviewerMapper->findByReview($reviewId),
            new WorkQuery(userId: $userId, teamIds: $allowedTeamIds, now: time()),
        );
    }

    // ---------------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------------

    /**
     * What this user may do to this review right now.
     *
     * **Keyed off the permissions, not off a list of statuses** (v4.8.22).
     * The permission is the thing that actually decides — `buildFor()` sets
     * `canClose` on every open row the requester owns and `canComplete` only
     * on a reviewer's outstanding one — and a status list is a second copy of
     * that rule which has to be kept in step by hand.
     *
     * It was not kept in step: `file_review_due` shipped in 4.8.21 and was not
     * added to the list, so a review that had reached its due date became a
     * task in the requester's queue with **no way to close it** — the one row
     * that most needed the action was the only one without it. Justin found it
     * the same day. Keying off the permission cannot fail that way, because
     * there is nothing left to forget.
     *
     * This is also the authorisation path: `MyWorkService` re-reads the item
     * through `getItem()` before executing anything, so these permissions are
     * always server-derived and never a client's word.
     */
    public function getAvailableActions(string $userId, WorkItem $item): array {
        $actions = [ActionType::OPEN];

        if ($item->permissions['canComplete'] ?? false) {
            $actions[] = ActionType::COMPLETE;
        }

        // Every open review the requester owns offers the close, whatever
        // category it is filed under. Closing early is how a request is
        // cancelled, and how a requester gets out from under reviewers who are
        // ill, away, or simply not going to answer — so hiding it until
        // everybody has replied would leave a request nobody can end.
        if ($item->permissions['canClose'] ?? false) {
            $actions[] = ActionType::CLOSE;
        }

        return $actions;
    }

    public function executeAction(string $userId, WorkItem $item, string $action, array $params): ActionResult {
        if (!in_array($action, $this->getAvailableActions($userId, $item), true)) {
            return ActionResult::forbidden(
                $this->l->t('You cannot do that to this review.'),
            );
        }

        $reviewId = (int)$item->providerItemId;

        try {
            if ($action === ActionType::COMPLETE) {
                // The remark is optional. `reason` is what My Work's confirm
                // dialog posts — the same field name `requiresReason` uses —
                // and `remark` / `message` are accepted too so a direct API
                // caller can use the name the record itself uses.
                $remark = trim((string)($params['reason'] ?? $params['remark'] ?? $params['message'] ?? ''));
                $result = $this->reviewService->complete(
                    $reviewId,
                    $userId,
                    $remark === '' ? null : $remark,
                );

                if ($result['status'] === 'already') {
                    return ActionResult::conflict($this->l->t('This review has already moved on.'));
                }

                return ActionResult::success(
                    $this->l->t('Review completed.'),
                    null,
                    true,
                );
            }

            $result = $this->reviewService->close($reviewId, $userId);
            if ($result['status'] === 'already') {
                return ActionResult::conflict($this->l->t('This review is already closed.'));
            }

            return ActionResult::success(
                $this->l->t('Review closed.'),
                null,
                true,
            );
        } catch (NotFoundException) {
            return ActionResult::gone($this->l->t('This review no longer exists.'));
        } catch (AccessDeniedException $e) {
            return ActionResult::forbidden($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][FileReview] action failed', [
                'action' => $action, 'reviewId' => $reviewId,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);

            return ActionResult::failure($this->l->t('That did not work. Please try again.'));
        }
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Every review this user has a stake in, keyed by id.
     *
     * Two directions, because there are two ways to be involved: the reviews
     * you asked for, and the reviews you were asked to do. Merging by id keeps
     * the requester-who-is-also-somehow-a-reviewer case single-rowed, which
     * `requestReview` prevents but this does not rely on.
     *
     * @param string[] $teamIds
     * @return array<int, FileReview>
     */
    private function relevantReviews(string $userId, array $teamIds): array {
        $out = [];

        foreach ($this->reviewMapper->findByRequester($userId, $teamIds) as $review) {
            $out[$review->getId()] = $review;
        }

        $asReviewer = $this->reviewerMapper->findReviewIdsForUser($userId);
        if ($asReviewer !== []) {
            foreach ($this->reviewMapper->findByIds($asReviewer) as $review) {
                // The team check is here rather than in the query because the
                // reviewer index is keyed by user, not by team: a row from a
                // team this user has since left must not come back.
                if (in_array($review->getTeamId(), $teamIds, true)) {
                    $out[$review->getId()] = $review;
                }
            }
        }

        return $out;
    }

    /**
     * The one place the five rows — and the one deliberate absence — are
     * decided.
     *
     * @param FileReviewer[] $roster
     */
    private function buildFor(string $userId, FileReview $review, array $roster, WorkQuery $query): ?WorkItem {
        $isRequester = $review->getRequestedBy() === $userId;
        $mine        = null;
        $outstanding = 0;

        foreach ($roster as $row) {
            if (!$row->hasCompleted()) {
                $outstanding++;
            }
            if ($row->getUserId() === $userId) {
                $mine = $row;
            }
        }

        if (!$isRequester && $mine === null) {
            // Neither asked nor asking. Being able to read the file is not the
            // same as owing a step on it.
            return null;
        }

        if ($review->isOpen()) {
            if ($mine !== null && !$mine->hasCompleted()) {
                return $this->buildItem(
                    $query, $review, $roster,
                    Category::ACTION_REQUIRED,
                    self::STATUS_REQUESTED,
                    Priority::HIGH,
                    $this->l->t('%s asked you to review this file', [$this->displayName($review->getRequestedBy())]),
                    null,
                    ['canComplete' => true],
                );
            }

            if ($isRequester && $outstanding === 0) {
                return $this->buildItem(
                    $query, $review, $roster,
                    Category::ACTION_REQUIRED,
                    self::STATUS_READY_TO_CLOSE,
                    Priority::NORMAL,
                    $this->l->t('Everyone has reviewed this — only you can close it'),
                    null,
                    ['canClose' => true],
                );
            }

            if ($isRequester) {
                // v4.8.21 — the due date turns the requester's row into a task.
                //
                // `MyWorkService::finaliseCategory()` will not do this for us:
                // it exempts WAITING_FOR_OTHERS from due-date promotion, on the
                // sound general rule that a deadline on somebody else's step is
                // not your task. File reviews are the exception that proves it,
                // because the requester **does** have a step of their own the
                // whole time — closing — and a review that has reached its date
                // with people still silent is exactly when they need to decide
                // whether to chase or to close.
                //
                // So the provider makes the call rather than the framework, and
                // only when a date was actually set. A review with no due date
                // stays in Waiting for others, where it belongs.
                $due = $review->getDueAt();
                if ($due !== null && $due <= $query->actionRequiredBy()) {
                    return $this->buildItem(
                        $query, $review, $roster,
                        Category::ACTION_REQUIRED,
                        self::STATUS_DUE,
                        $due < $query->now ? Priority::URGENT : Priority::HIGH,
                        $this->waitingReason($roster, $outstanding),
                        null,
                        ['canClose' => true],
                    );
                }

                return $this->buildItem(
                    $query, $review, $roster,
                    Category::WAITING_FOR_OTHERS,
                    self::STATUS_AWAITING,
                    Priority::NORMAL,
                    $this->waitingReason($roster, $outstanding),
                    null,
                    ['canClose' => true],
                );
            }

            // A reviewer who has finished, on a review still running. Their
            // own part is complete even though the review is not.
            return $this->buildItem(
                $query, $review, $roster,
                Category::COMPLETED,
                self::STATUS_COMPLETED,
                Priority::LOW,
                $this->l->t('You completed this review'),
                $mine?->getCompletedAt(),
                [],
            );
        }

        // ── Closed ───────────────────────────────────────────────────────
        //
        // The absence promised in the class docblock. A reviewer who never
        // completed gets no row at all: not Action required, because there is
        // nothing left to do, and not Completed, because they completed
        // nothing. They were told the review closed by notification; the queue
        // simply no longer has anything of theirs in it.
        if (!$isRequester && ($mine === null || !$mine->hasCompleted())) {
            return null;
        }

        return $this->buildItem(
            $query, $review, $roster,
            Category::COMPLETED,
            self::STATUS_CLOSED,
            Priority::LOW,
            $isRequester
                ? $this->l->t('You closed this review')
                : $this->l->t('You completed this review'),
            $review->getClosedAt(),
            [],
        );
    }

    /**
     * @param FileReviewer[]      $roster
     * @param array<string,bool>  $permissions
     */
    private function buildItem(
        WorkQuery $query,
        FileReview $review,
        array $roster,
        string $category,
        string $status,
        string $priority,
        string $reason,
        ?int $completedAt,
        array $permissions,
    ): WorkItem {
        $teamId  = $review->getTeamId();
        $waiting = null;

        // Both of the requester's waiting states, not just the calm one: a
        // review that has reached its due date is still waiting on the same
        // people, and the avatar beside the reason is how the row says who.
        // (v4.8.22 — `STATUS_DUE` was missed here the same way it was missed
        // in getAvailableActions().)
        if ($status === self::STATUS_AWAITING || $status === self::STATUS_DUE) {
            foreach ($roster as $row) {
                if (!$row->hasCompleted()) {
                    $waiting = [
                        'uid'         => $row->getUserId(),
                        'displayName' => $this->displayName($row->getUserId()),
                    ];
                    break;
                }
            }
        }

        return WorkItem::make([
            'providerId'     => self::ID,
            'providerItemId' => (string)$review->getId(),
            'teamId'         => $teamId,
            'teamName'       => $query->teamName($teamId),
            'category'       => $category,
            // The title is the ask, the subtitle is the document — the same
            // layout ApprovalWorkProvider uses for the same kind of row.
            'title'          => $status === self::STATUS_REQUESTED
                ? $this->l->t('Review %s', [$review->getFileName()])
                : $this->l->t('Review of %s', [$review->getFileName()]),
            'subtitle'       => $review->getFileName(),
            'resourceType'   => self::RESOURCE_TYPE,
            'resourceId'     => (string)$review->getFileId(),
            'resourceUrl'    => '/f/' . $review->getFileId(),
            // The row opens inside the team's own Files tab, at the file.
            'openTarget'     => OpenTarget::file($review->getFileId()),
            'priority'       => $priority,
            'status'         => $status,
            'reason'         => $reason,
            'createdAt'      => $review->getCreatedAt(),
            'updatedAt'      => $review->getClosedAt() ?? $review->getCreatedAt(),
            // A real deadline when the requester set one, and null when they
            // did not. Nothing is derived — see the class docblock.
            'dueAt'          => $review->getDueAt(),
            'completedAt'    => $completedAt,
            'assignee'       => $status === self::STATUS_REQUESTED
                ? ['uid' => $query->userId, 'displayName' => $this->displayName($query->userId)]
                : null,
            'waitingFor'     => $waiting,
            'availableActions' => [],
            'metadata'       => [
                'reviewId'    => $review->getId(),
                'fileId'      => $review->getFileId(),
                'fileName'    => $review->getFileName(),
                'talkToken'   => $review->getTalkToken(),
                'requestNote' => $review->getMessage(),
                'requester'   => [
                    'uid'         => $review->getRequestedBy(),
                    'displayName' => $this->displayName($review->getRequestedBy()),
                ],
                // The roster, with who finished and when. This is what makes
                // "who completed the review and when" answerable from the row
                // itself rather than from a second screen.
                'reviewers'   => array_map(
                    fn (FileReviewer $row): array => [
                        'uid'         => $row->getUserId(),
                        'displayName' => $this->displayName($row->getUserId()),
                        'completedAt' => $row->getCompletedAt(),
                        'remark'      => $row->getRemark(),
                    ],
                    array_values($roster),
                ),
                'outstanding' => count(array_filter(
                    $roster,
                    static fn (FileReviewer $row): bool => !$row->hasCompleted(),
                )),
                // Offer a text field when completing, and do not insist on it.
                // The generic sibling of `requiresReason`. A remark typed here
                // is recorded on the review *and* posted into the file's own
                // chat, so it reaches the rest of the team beside the document
                // rather than only the requester in a queue row.
                'allowsReason' => ($permissions['canComplete'] ?? false),
            ],
            'permissions'    => array_merge(
                ['canOpen' => true, 'canComplete' => false, 'canClose' => false],
                $permissions,
            ),
        ]);
    }

    /**
     * The requester's reason line: how far the review has got, and who it is
     * still on (v4.8.21).
     *
     * Justin's report was that "Waiting for others" did not say **who** had
     * reviewed and who had not. The roster panel added in 4.8.19 answers that,
     * but only once expanded — and the disclosure is one more thing to find.
     * The counts and the outstanding names belong in the reason column, which
     * every layout renders and nothing hides.
     *
     * Two names then "+N", rather than a full list: the column is ~200px and a
     * reason that ellipses in the middle of the third name answers nothing.
     * The complete roster, with dates and remarks, stays one click away.
     *
     * @param FileReviewer[] $roster
     */
    private function waitingReason(array $roster, int $outstanding): string {
        $done  = count($roster) - $outstanding;
        $names = [];

        foreach ($roster as $row) {
            if (!$row->hasCompleted()) {
                $names[] = $this->displayName($row->getUserId());
            }
        }

        // TRANSLATORS: progress of a file review; %1$s is a number completed, %2$s the total
        $progress = $this->l->t('%1$s of %2$s reviewed', [(string)$done, (string)count($roster)]);

        if ($names === []) {
            return $progress;
        }

        if (count($names) <= 2) {
            // TRANSLATORS: %1$s is "2 of 4 reviewed", %2$s is a list of people who have not answered yet
            return $this->l->t('%1$s — waiting for %2$s', [$progress, implode(', ', $names)]);
        }

        $shown = array_slice($names, 0, 2);
        $rest  = count($names) - 2;

        // TRANSLATORS: %1$s is "1 of 5 reviewed", %2$s is two names, %3$s is how many more people are outstanding
        return $this->l->t('%1$s — waiting for %2$s and %3$s more', [
            $progress, implode(', ', $shown), (string)$rest,
        ]);
    }

    private function displayName(string $uid): string {
        if (!isset($this->nameCache[$uid])) {
            try {
                $user = $this->userManager->get($uid);
                $this->nameCache[$uid] = $user !== null ? (string)$user->getDisplayName() : $uid;
            } catch (\Throwable) {
                $this->nameCache[$uid] = $uid;
            }
        }

        return $this->nameCache[$uid];
    }

}
