<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\FileReview;
use OCA\TeamHub\Db\FileReviewer;
use OCA\TeamHub\Db\FileReviewMapper;
use OCA\TeamHub\Db\FileReviewerMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * File review requests (v4.8.18). Design: FILE-REVIEW-PLAN.md.
 *
 * Somebody asks named teammates to look at a file. Each of them owes one
 * action — complete — and the requester owes one — close. The discussion
 * happens in **the file's own Talk conversation**, the one that opens beside
 * the file in the Files sidebar.
 *
 * ## The chat is the file's, not the review's (v4.8.20)
 *
 * The first implementation created a conversation per review and deleted it at
 * close. That was the wrong shape and Justin said so: reviewers are already
 * looking at the file's chat, because it opens with the file. A second
 * conversation about the same document is where half the discussion goes
 * missing, and deleting it destroyed the half that landed there.
 *
 * So: TeamHub **resolves** the file's conversation (creating it only if nobody
 * has opened it yet), posts the request into it, posts each completion into it
 * with the reviewer's remark, and **never deletes it**. The token stored on the
 * review is a convenience handle to somebody else's conversation.
 *
 * ## Three rules that are easy to get backwards
 *
 * **1. Closing is not completing.** A reviewer completes their own obligation;
 * the requester closes the whole request. They are different verbs with
 * different owners, and the second one silently withdraws the request from
 * everyone who had not answered — which is why it is `ActionType::CLOSE` and
 * not `COMPLETE` (see that class's docblock). Closing is allowed at any time,
 * however many people still owe: the requester decides when they have heard
 * enough, and closing with nobody finished is how a request is cancelled.
 * There is deliberately no separate withdraw verb for that.
 *
 * **2. Closing hides, it does not delete.** Reviewer rows survive a close,
 * including rows that were never completed, because "who did not respond" is a
 * fact the requester has to be able to read afterwards and this is the only
 * place it is recorded. What changes at close is what My Work returns — see
 * FileReviewWorkProvider, which stops emitting an item for a reviewer whose
 * `completed_at` is still null. Putting that row under "Completed" instead
 * would put their name against work they never did.
 *
 * **3. The reviewer set is a snapshot.** It is resolved once, at request time.
 * Somebody who joins the team the next day is not added, because the request
 * was addressed to the people who were there. The only edit is subtraction:
 * leaving the team removes the obligation, since it can no longer be met.
 *
 * ## What is verified, and where
 *
 * Every entry point re-establishes membership from `$uid` rather than trusting
 * a session or a caller's word, because two of them (the My Work provider's
 * action path, and the Files-app script) do not go through a TeamHub page. The
 * file is verified twice over: it must resolve in the requester's own file
 * tree, and it must sit inside that team's folders. The second check is what
 * makes the reviewer-access check below almost always a formality — but it is
 * still run, because "almost always" is not an authorisation.
 *
 * Reviewers who cannot read the file cause the request to be **refused, with
 * their names**. TeamHub never shares the file on the requester's behalf: a
 * silent permission grant is exactly the kind of thing a review request must
 * not do behind somebody's back.
 */
class FileReviewService {

    /**
     * The retired global kill switch (v4.8.18 – v4.8.30).
     *
     * Kept as a name so nobody reuses the key for something else. **Nothing
     * reads or writes it** since v4.8.31 — whether file reviews exist is
     * derived from the licence, because the feature is unusable without My
     * Work and My Work is licensed. A stored `0` on an instance that ran an
     * earlier version is inert; it is deliberately not deleted, since removing
     * an appconfig row is a write that buys nothing.
     */
    public const CONFIG_ENABLED = 'file_review_module_enabled';

    /**
     * Per-team switch, suffixed with the team id. Absent means on.
     *
     * Camel case, matching `MessageService::CONFIG_ALLOW_PUBLIC_PREFIX` — the
     * other per-team key of this shape.
     */
    public const CONFIG_TEAM_PREFIX = 'fileReviewEnabled_';

    /** Guards the `message` column and the Talk opening post. */
    private const MAX_MESSAGE_LENGTH = 4000;

    /** Guards the `remark` column. */
    private const MAX_REMARK_LENGTH = 2000;

    /**
     * A ceiling on the reviewer set. Team size already bounds it; this exists
     * so a malformed or hostile payload cannot ask the Talk API to build a
     * conversation with thousands of participants before the membership check
     * rejects them one at a time.
     */
    private const MAX_REVIEWERS = 200;

    /** 2100-01-01. A due date beyond this is a unit mix-up, not a deadline. */
    private const MAX_DUE_AT = 4102444800;

    public function __construct(
        private FileReviewMapper $reviewMapper,
        private FileReviewerMapper $reviewerMapper,
        private TeamFileScopeService $fileScope,
        private MemberService $memberService,
        private TalkService $talkService,
        private IDBConnection $db,
        private INotificationManager $notificationManager,
        private IUserManager $userManager,
        private IURLGenerator $urlGenerator,
        private IFactory $l10nFactory,
        private TimezoneService $timezoneService,
        private IConfig $config,
        // v4.8.31 — the global switch is the licence now. A DI leaf from this
        // service's point of view: LicenseService injects only core services
        // and TelemetryService, neither of which reaches back here.
        private LicenseService $licenseService,
        private LoggerInterface $logger,
    ) {
    }

    // ---------------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------------

    /**
     * Whether file reviews exist on this instance at all — **derived from the
     * licence, not from a stored switch** (v4.8.31).
     *
     * A review's whole working life happens in My Work: the reviewer finds the
     * request there, completes it there, and the requester closes it there. My
     * Work is licence-gated at `MyWorkController`, so on an unlicensed instance
     * a review could be *requested* and then never seen by anybody. The feature
     * was not partly available, it was broken — and it took an administrator
     * switch to reach that state, which made it look deliberate.
     *
     * So the switch is gone and the answer follows the licence. Justin,
     * 2026-09-07: file reviews are an internal integration that depends on My
     * Work, so they should appear when a licence is present and disappear when
     * it is not, rather than being separately toggleable into a broken state.
     *
     * **`hasLicenseKey()` first, and that ordering is the point.** This is
     * called from `FilesScriptsListener` on every Files-app page load for every
     * user, and that listener's contract is that an instance not using file
     * reviews pays nothing for them. An instance with no key answers from one
     * appconfig read. Only an instance that has a key pays for
     * `getEnforcementLevel()`, and it is the only kind that could have the
     * feature.
     *
     * **This is not the authorisation boundary.** It decides whether a script
     * tag is added and whether a provider offers rows. The boundary is
     * `FileReviewController::licenseGate()`, on the endpoints — SKILLS.md
     * § Security standards: never rely on the frontend not calling something.
     */
    public function isEnabledGlobally(): bool {
        if (!$this->licenseService->hasLicenseKey()) {
            return false;
        }

        $level = $this->licenseService->getEnforcementLevel();

        // Same two levels `MyWorkController::licenseGate()` admits. Grace is a
        // lapsed licence inside its 30-day window: My Work still works, so file
        // reviews still work, or a customer renewing would find half their
        // outstanding reviews unreachable.
        return $level === 'none' || $level === 'grace';
    }

    public function isEnabledForTeam(string $teamId): bool {
        if (!$this->isEnabledGlobally()) {
            return false;
        }

        return $this->config->getAppValue(
            Application::APP_ID,
            self::CONFIG_TEAM_PREFIX . $teamId,
            '1',
        ) !== '0';
    }

    public function setEnabledForTeam(string $teamId, bool $enabled): void {
        $this->config->setAppValue(
            Application::APP_ID,
            self::CONFIG_TEAM_PREFIX . $teamId,
            $enabled ? '1' : '0',
        );
    }

    // ---------------------------------------------------------------------
    // Discovery — what the Files-app script asks before it renders anything
    // ---------------------------------------------------------------------

    /**
     * The team folders this user has, as path prefixes.
     *
     * The Files app's file actions decide visibility in a **synchronous**
     * callback, so the script cannot ask the server about the file under the
     * cursor. It asks this once instead and then answers every subsequent
     * question itself with a prefix test. A user in no team gets an empty
     * array and the action never appears for them.
     *
     * Teams with the module switched off are omitted rather than returned with
     * a flag: the caller's only use for this is "may I offer the action here",
     * and an off team is indistinguishable from no team for that purpose.
     *
     * **The paths are root-relative** — `/Team Alpha`, not
     * `/alice/files/Team Alpha`. Internally TeamHub compares absolute paths,
     * because that is what `Node::getPath()` gives; the Files app's client-side
     * `Node.path` is relative to the user's files root. Converting here rather
     * than in the browser keeps the two representations from being confused in
     * the place where a wrong answer is silent: a prefix test that never
     * matches simply means the action never appears.
     *
     * @param string[] $teamIds the caller's own teams, resolved by the caller
     * @return list<array{teamId:string, teamName:string, paths:string[]}>
     */
    public function listScopes(string $uid, array $teamIds): array {
        if (!$this->isEnabledGlobally()) {
            return [];
        }

        $enabled = array_values(array_filter(
            $teamIds,
            fn ($teamId): bool => is_string($teamId) && $teamId !== '' && $this->isEnabledForTeam($teamId),
        ));
        if ($enabled === []) {
            return [];
        }

        $userFolder = $this->fileScope->userFolder($uid);
        if ($userFolder === null) {
            return [];
        }

        $out = [];
        foreach ($this->fileScope->pathsByTeam($uid, $enabled, $userFolder) as $teamId => $paths) {
            $relative = [];
            foreach ($paths as $path) {
                $rel = $userFolder->getRelativePath($path);
                if ($rel !== null && $rel !== '') {
                    $relative[] = '/' . ltrim($rel, '/');
                }
            }
            if ($relative === []) {
                continue;
            }
            $out[] = [
                'teamId'   => (string)$teamId,
                'teamName' => $this->getTeamName((string)$teamId),
                'paths'    => $relative,
            ];
        }

        return $out;
    }

    /**
     * Everything the request modal needs about one file, in one round trip.
     *
     * Returns `eligible: false` rather than throwing when the file is simply
     * not in a team folder: the script's prefix test can disagree with the
     * server (a folder was detached between page load and click), and that is a
     * "no" to be rendered, not a fault to be logged.
     *
     * @param string[] $teamIds the caller's own teams
     * @return array<string,mixed>
     */
    public function reviewContext(string $uid, int $fileId, array $teamIds): array {
        $miss = ['eligible' => false, 'teamId' => null, 'teamName' => '', 'fileName' => '',
                 'members' => [], 'openReviews' => []];

        if ($fileId <= 0 || !$this->isEnabledGlobally()) {
            return $miss;
        }

        $enabled = array_values(array_filter(
            $teamIds,
            fn ($teamId): bool => is_string($teamId) && $teamId !== '' && $this->isEnabledForTeam($teamId),
        ));
        if ($enabled === []) {
            return $miss;
        }

        $resolved = $this->fileScope->resolveFile($uid, $enabled, $fileId);
        if ($resolved === null) {
            return $miss;
        }

        $node = $resolved['node'];
        if ($node->getType() !== \OCP\Files\FileInfo::TYPE_FILE) {
            // Folders are out of scope for v1. Reported as ineligible rather
            // than as an error, for the same reason as above.
            return $miss;
        }

        $teamId = $resolved['teamId'];

        // Only reviews inside this team are shown. A review of the same file
        // raised from another team is that team's business.
        $open = [];
        foreach ($this->reviewMapper->findOpenByFile($fileId) as $review) {
            if ($review->getTeamId() !== $teamId) {
                continue;
            }
            $open[] = $this->toArray($review, $this->reviewerMapper->findByReview($review->getId()), $uid);
        }

        return [
            'eligible'    => true,
            'teamId'      => $teamId,
            'teamName'    => $this->getTeamName($teamId),
            'fileName'    => $node->getName(),
            'fileId'      => $fileId,
            'members'     => $this->teamRoster($teamId, $uid),
            'openReviews' => $open,
        ];
    }

    // ---------------------------------------------------------------------
    // The request
    // ---------------------------------------------------------------------

    /**
     * Create a review request.
     *
     * @param string[] $reviewerUids explicit reviewers; an empty array means
     *                               "everyone in the team except me"
     * @return array<string,mixed> the created review, serialised
     *
     * @throws ValidationException   the payload cannot describe a valid request
     * @throws AccessDeniedException the caller may not act on this team or file
     */
    public function requestReview(
        string  $teamId,
        int     $fileId,
        array   $reviewerUids,
        ?string $message,
        ?int    $dueAt,
        string  $uid,
    ): array {
        $this->assertTeamEnabled($teamId);
        $this->assertMember($teamId, $uid);

        if ($fileId <= 0) {
            throw new ValidationException('A file must be given');
        }

        $resolved = $this->fileScope->resolveFile($uid, [$teamId], $fileId);
        if ($resolved === null || $resolved['teamId'] !== $teamId) {
            // One message for "you cannot see it" and "it is not in this
            // team's folders": distinguishing them would tell the caller
            // whether a file id they cannot reach exists.
            throw new AccessDeniedException('That file is not in this team\'s folders');
        }

        $node = $resolved['node'];
        if ($node->getType() !== \OCP\Files\FileInfo::TYPE_FILE) {
            throw new ValidationException('A review can only be requested on a file, not a folder');
        }

        $message = $this->normaliseText($message, self::MAX_MESSAGE_LENGTH, 'message');
        $dueAt   = $this->normaliseDueAt($dueAt);

        $reviewers = $this->resolveReviewers($teamId, $reviewerUids, $uid);
        $this->assertReviewersCanRead($reviewers, $fileId);

        $review = new FileReview();
        $review->setTeamId($teamId);
        $review->setFileId($fileId);
        $review->setFileName(mb_substr($node->getName(), 0, 255));
        $review->setRequestedBy($uid);
        $review->setMessage($message);
        $review->setDueAt($dueAt);
        $review->setTalkToken(null);
        $review->setStatus(FileReview::STATUS_OPEN);
        $review->setCreatedAt(time());

        /** @var FileReview $review */
        $review = $this->reviewMapper->insert($review);
        $this->reviewerMapper->insertRoster($review->getId(), $reviewers);

        // Best-effort, in this order on purpose: the review exists before the
        // chat is touched, so a Talk failure leaves a usable request rather
        // than rolling back work the user asked for.
        $token = $this->announceInFileChat($review, $uid);
        if ($token !== null) {
            $review->setTalkToken($token);
            $this->reviewMapper->update($review);
        }

        $this->notifyRequested($review, $reviewers, $uid);

        $this->logger->info('[TeamHub][FileReviewService] review requested', [
            'reviewId' => $review->getId(), 'teamId' => $teamId, 'fileId' => $fileId,
            'reviewers' => count($reviewers), 'talk' => $token !== null,
            'app' => Application::APP_ID,
        ]);

        return $this->toArray($review, $this->reviewerMapper->findByReview($review->getId()), $uid);
    }

    // ---------------------------------------------------------------------
    // Reading
    // ---------------------------------------------------------------------

    /**
     * One team's reviews.
     *
     * @return list<array<string,mixed>>
     */
    public function listForTeam(string $teamId, string $uid, ?string $status = null, int $limit = 100, int $offset = 0): array {
        $this->assertMember($teamId, $uid);

        $reviews = $this->reviewMapper->findByTeam($teamId, $status, $limit, $offset);
        if ($reviews === []) {
            return [];
        }

        $rosters = $this->reviewerMapper->findByReviewIds(
            array_map(static fn (FileReview $r): int => $r->getId(), $reviews),
        );

        $out = [];
        foreach ($reviews as $review) {
            $out[] = $this->toArray($review, $rosters[$review->getId()] ?? [], $uid);
        }

        return $out;
    }

    /**
     * One review, with the membership check that makes it readable.
     *
     * @return array<string,mixed>
     * @throws NotFoundException
     * @throws AccessDeniedException
     */
    public function getReview(int $reviewId, string $uid): array {
        $review = $this->loadReview($reviewId);
        $this->assertMember($review->getTeamId(), $uid);

        return $this->toArray($review, $this->reviewerMapper->findByReview($review->getId()), $uid);
    }

    /** @throws NotFoundException */
    public function loadReview(int $reviewId): FileReview {
        try {
            return $this->reviewMapper->findById($reviewId);
        } catch (\Throwable) {
            throw new NotFoundException('Review not found');
        }
    }

    // ---------------------------------------------------------------------
    // The two verbs
    // ---------------------------------------------------------------------

    /**
     * A reviewer finishes their part.
     *
     * Idempotent by design rather than by accident: completing twice reports
     * `already`, and so does completing a review that has since been closed.
     * Neither is an exception, because neither is a fault — it is a queue that
     * moved on while a browser tab did not.
     *
     * @return array{status:string, review:array<string,mixed>|null, message:string}
     */
    public function complete(int $reviewId, string $uid, ?string $remark = null): array {
        $review = $this->loadReview($reviewId);
        $this->assertMember($review->getTeamId(), $uid);

        $row = $this->reviewerMapper->findOne($reviewId, $uid);
        if ($row === null) {
            throw new AccessDeniedException('You were not asked to review this file');
        }

        if (!$review->isOpen()) {
            return [
                'status'  => 'already',
                'review'  => $this->toArray($review, $this->reviewerMapper->findByReview($reviewId), $uid),
                'message' => 'This review has already been closed',
            ];
        }

        if ($row->hasCompleted()) {
            return [
                'status'  => 'already',
                'review'  => $this->toArray($review, $this->reviewerMapper->findByReview($reviewId), $uid),
                'message' => 'You have already completed this review',
            ];
        }

        $remark = $this->normaliseText($remark, self::MAX_REMARK_LENGTH, 'remark');

        $row->setCompletedAt(time());
        $row->setRemark($remark);
        $this->reviewerMapper->update($row);

        $outstanding = $this->reviewerMapper->countOutstanding($reviewId);
        $this->notifyCompleted($review, $uid, $outstanding);
        // The completion is announced where the discussion is, not only in the
        // requester's queue — see the method's docblock.
        $this->postCompletionToFileChat($review, $uid, $remark);

        $this->logger->info('[TeamHub][FileReviewService] review completed by one reviewer', [
            'reviewId' => $reviewId, 'outstanding' => $outstanding, 'app' => Application::APP_ID,
        ]);

        return [
            'status'  => 'completed',
            'review'  => $this->toArray($review, $this->reviewerMapper->findByReview($reviewId), $uid),
            'message' => 'Review completed',
        ];
    }

    /**
     * The requester ends the request.
     *
     * v4.8.20 — this no longer deletes anything. The discussion lives in the
     * file's own conversation, which the file owns and TeamHub never removes.
     * What closing still does is take the request out of the My Work of every
     * reviewer who had not answered, which is what the confirmation is for.
     *
     * Requester-only. A team administrator is deliberately not given this:
     * the review is a question one person asked, and the answer to "somebody
     * else should be able to end it" is that they can ask their own.
     *
     * @return array{status:string, review:array<string,mixed>, message:string}
     */
    public function close(int $reviewId, string $uid): array {
        $review = $this->loadReview($reviewId);
        $this->assertMember($review->getTeamId(), $uid);

        if ($review->getRequestedBy() !== $uid) {
            throw new AccessDeniedException('Only the person who asked for this review can close it');
        }

        if (!$review->isOpen()) {
            return [
                'status'  => 'already',
                'review'  => $this->toArray($review, $this->reviewerMapper->findByReview($reviewId), $uid),
                'message' => 'This review is already closed',
            ];
        }

        $roster      = $this->reviewerMapper->findByReview($reviewId);
        $outstanding = [];
        foreach ($roster as $row) {
            if (!$row->hasCompleted()) {
                $outstanding[] = $row->getUserId();
            }
        }

        $review->setStatus(FileReview::STATUS_CLOSED);
        $review->setClosedAt(time());
        $review->setClosedBy($uid);
        $this->reviewMapper->update($review);

        // v4.8.20 — closing destroys nothing. The conversation belongs to the
        // file, not to the review: it was there before the request and stays
        // afterwards, with the discussion still in it. The token is kept on the
        // row for the same reason — it still points at a real chat.
        //
        // What closing *does* still do is withdraw the request from the queues
        // of everyone who had not answered, which is why the notification below
        // and the frontend's confirmation both remain.

        $this->notifyClosed($review, $roster, $uid);

        $this->logger->info('[TeamHub][FileReviewService] review closed', [
            'reviewId' => $reviewId, 'outstanding' => count($outstanding),
            'app' => Application::APP_ID,
        ]);

        return [
            'status'  => 'closed',
            'review'  => $this->toArray($review, $roster, $uid),
            'message' => 'Review closed',
        ];
    }

    // ---------------------------------------------------------------------
    // Housekeeping
    // ---------------------------------------------------------------------

    /**
     * Somebody left a team: drop the obligations they can no longer meet.
     *
     * Only their reviewer rows go. Reviews they *requested* stay, because the
     * team still owes them an answer and the rows are the record of it — and
     * because a departure that silently deleted other people's queued work
     * would be a surprise nobody asked for.
     *
     * **Membership is re-tested first, and that is the whole subtlety.** Both
     * callers — leaving, and being removed — can end with the person still in
     * the team through a group or a sub-team. Deleting their obligations then
     * would silently cancel work they can still do and still owe. Same test,
     * for the same reason, as `MessageSubscriptionService::forgetTeamSubscriptions`.
     */
    public function dropTeamMember(string $teamId, string $uid): void {
        try {
            if ($this->memberService->isEffectiveMember($teamId, $uid, $this->db)) {
                return;
            }

            $reviewIds = $this->reviewMapper->findIdsByTeam($teamId);
            if ($reviewIds === []) {
                return;
            }

            $removed = $this->reviewerMapper->deleteForUserInReviews($uid, $reviewIds);
            if ($removed > 0) {
                $this->logger->info('[TeamHub][FileReviewService] dropped reviewer rows on departure', [
                    'teamId' => $teamId, 'removed' => $removed, 'app' => Application::APP_ID,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][FileReviewService] departure cleanup failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    // A team being deleted deliberately has no cleanup path here. TeamHub's
    // own module tables are not purged on team deletion — `TeamService::deleteTeam`
    // destroys connected Nextcloud resources and nothing else — and reviews
    // need no exception to that: the provider filters every row against the
    // caller's live team set, so a review in a team that no longer exists is
    // already invisible to everyone. Adding a purge here would be inventing a
    // contract no other module follows.

    // ---------------------------------------------------------------------
    // Serialisation
    // ---------------------------------------------------------------------

    /**
     * One review as the frontend and the My Work provider both see it.
     *
     * `viewerUid` decides only the two convenience booleans; it never removes
     * anything, because every caller has already passed the membership check
     * that makes the whole record readable.
     *
     * @param FileReviewer[] $roster
     * @return array<string,mixed>
     */
    public function toArray(FileReview $review, array $roster, string $viewerUid): array {
        $reviewers   = [];
        $outstanding = 0;
        $viewerRow   = null;

        foreach ($roster as $row) {
            if (!$row->hasCompleted()) {
                $outstanding++;
            }
            if ($row->getUserId() === $viewerUid) {
                $viewerRow = $row;
            }
            $reviewers[] = [
                'uid'         => $row->getUserId(),
                'displayName' => $this->displayName($row->getUserId()),
                'completedAt' => $row->getCompletedAt(),
                'remark'      => $row->getRemark(),
            ];
        }

        return [
            'id'            => $review->getId(),
            'teamId'        => $review->getTeamId(),
            'fileId'        => $review->getFileId(),
            'fileName'      => $review->getFileName(),
            'fileUrl'       => '/f/' . $review->getFileId(),
            'requestedBy'   => $review->getRequestedBy(),
            'requestedByName' => $this->displayName($review->getRequestedBy()),
            'message'       => $review->getMessage(),
            'dueAt'         => $review->getDueAt(),
            'talkToken'     => $review->getTalkToken(),
            'status'        => $review->getStatus(),
            'createdAt'     => $review->getCreatedAt(),
            'closedAt'      => $review->getClosedAt(),
            'closedBy'      => $review->getClosedBy(),
            'reviewers'     => $reviewers,
            'total'         => count($roster),
            'outstanding'   => $outstanding,
            'isRequester'   => $review->getRequestedBy() === $viewerUid,
            'isReviewer'    => $viewerRow !== null,
            'viewerCompletedAt' => $viewerRow?->getCompletedAt(),
        ];
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /** @throws AccessDeniedException */
    private function assertMember(string $teamId, string $uid): void {
        if (!$this->memberService->isEffectiveMember($teamId, $uid, $this->db)) {
            throw new AccessDeniedException('You are not a member of this team');
        }
    }

    /** @throws AccessDeniedException */
    private function assertTeamEnabled(string $teamId): void {
        if (!$this->isEnabledForTeam($teamId)) {
            throw new AccessDeniedException('File reviews are not enabled for this team');
        }
    }

    /**
     * Turn the requested reviewer set into a verified snapshot.
     *
     * An empty request means "everyone", which is resolved here rather than
     * stored as a marker — a stored "everyone" would keep changing meaning as
     * the team changed, and "who did I ask" would stop having an answer.
     *
     * @param string[] $requested
     * @return string[]
     * @throws ValidationException
     */
    private function resolveReviewers(string $teamId, array $requested, string $requesterUid): array {
        $requested = array_values(array_unique(array_filter(
            $requested,
            static fn ($u): bool => is_string($u) && $u !== '',
        )));

        if ($requested === []) {
            // "All team members". `getAllEffectiveMembers` is the roster the
            // members widget uses — group-derived members included, which is
            // the point — and it authorises the current user itself.
            $requested = array_map(
                static fn (array $m): string => (string)($m['userId'] ?? ''),
                $this->memberService->getAllEffectiveMembers($teamId),
            );
        }

        // The requester is never a reviewer: they are the one waiting.
        $requested = array_values(array_filter(
            $requested,
            static fn (string $u): bool => $u !== '' && $u !== $requesterUid,
        ));

        if ($requested === []) {
            throw new ValidationException('Pick at least one reviewer');
        }
        if (count($requested) > self::MAX_REVIEWERS) {
            throw new ValidationException('Too many reviewers for one request');
        }

        foreach ($requested as $candidate) {
            if (!$this->memberService->isEffectiveMember($teamId, $candidate, $this->db)) {
                throw new ValidationException('Reviewers must be members of this team');
            }
        }

        return $requested;
    }

    /**
     * Every reviewer must already be able to open the file.
     *
     * With the request restricted to the team's own folders this should never
     * fire. It is still checked, and the names are still reported, because the
     * alternative when it does fire is a request somebody cannot act on and
     * cannot see why.
     *
     * @param string[] $reviewers
     * @throws ValidationException
     */
    private function assertReviewersCanRead(array $reviewers, int $fileId): void {
        $blocked = [];

        foreach ($reviewers as $reviewer) {
            $folder = $this->fileScope->userFolder($reviewer);
            if ($folder === null) {
                $blocked[] = $this->displayName($reviewer);
                continue;
            }
            try {
                if ($folder->getFirstNodeById($fileId) === null) {
                    $blocked[] = $this->displayName($reviewer);
                }
            } catch (\Throwable) {
                $blocked[] = $this->displayName($reviewer);
            }
        }

        if ($blocked !== []) {
            throw new ValidationException(
                'These people cannot open the file: ' . implode(', ', $blocked),
            );
        }
    }

    /**
     * The file's own conversation, and the request posted into it (v4.8.20).
     *
     * **TeamHub does not create a room for a review.** It used to, and that was
     * the wrong shape: the file already has a chat, it is the one that opens
     * beside the file in the Files sidebar, and it is therefore the one
     * reviewers are actually looking at. A second conversation about the same
     * document is where half the discussion goes missing.
     *
     * So this resolves — creating only if nobody has opened it yet — the
     * conversation Talk attaches to the file, and posts the request there so a
     * reviewer opening the file finds the ask already in the chat.
     *
     * The token is stored on the review as a **convenience handle**, not as
     * something TeamHub owns: the conversation belongs to the file, outlives
     * the review, and is never deleted by us.
     *
     * Returns null on every failure — Talk absent, file conversations switched
     * off instance-wide, or a post that did not land. A review without a chat
     * is a working review.
     */
    private function announceInFileChat(FileReview $review, string $uid): ?string {
        try {
            $token = $this->talkService->fileConversationToken(
                $review->getFileId(),
                $review->getFileName(),
            );
            if ($token === null) {
                return null;
            }

            $l = $this->requesterL10n($uid);

            // `%s`, not `{file}`. IL10N::t() interpolates with vsprintf — the
            // `{placeholder}` form belongs to the frontend's `t()` and to
            // notification rich subjects, neither of which is in play here.
            // A `{}` written by mistake reaches Talk verbatim; v4.8.19 fixed
            // exactly that in the room name this method replaced.
            //
            // TRANSLATORS: posted in a file's chat when somebody asks for a review; %s is a person
            $lines = [$l->t('%s asked for a review of this file.', [$this->displayName($uid)])];

            // The deadline goes in the post, not only in My Work: the chat is
            // where the reviewers are, and a request that does not say when it
            // is wanted by is a request people will get to eventually.
            $dueAt = $review->getDueAt();
            if ($dueAt !== null) {
                // TRANSLATORS: the deadline on a file review, posted in the file's chat; %s is a date
                $lines[] = $l->t('Please review by %s.', [$this->formatDueDate($dueAt, $uid)]);
            }

            if (($review->getMessage() ?? '') !== '') {
                $lines[] = (string)$review->getMessage();
            }

            $this->talkService->postAsParticipant($token, $uid, implode("\n", $lines));

            return $token;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][FileReviewService] could not announce in the file chat', [
                'reviewId' => $review->getId(), 'error' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
            return null;
        }
    }

    /**
     * A completion, posted into the file's chat (v4.8.20).
     *
     * The remark goes with it. That is the point of posting at all: a reviewer
     * who typed "the figures on page 3 are stale" has said something the rest
     * of the team needs to see next to the file, not only the requester in a
     * queue row.
     *
     * Best-effort. A chat that will not accept the post never fails the
     * completion it is reporting.
     */
    private function postCompletionToFileChat(FileReview $review, string $uid, ?string $remark): void {
        try {
            $token = $review->getTalkToken()
                ?? $this->talkService->fileConversationToken(
                    $review->getFileId(),
                    $review->getFileName(),
                );
            if ($token === null || $token === '') {
                return;
            }

            $l     = $this->requesterL10n($uid);
            // TRANSLATORS: posted in a file's chat when a reviewer finishes; %s is a person
            $lines = [$l->t('%s completed their review of this file.', [$this->displayName($uid)])];

            if (($remark ?? '') !== '') {
                $lines[] = (string)$remark;
            }

            $this->talkService->postAsParticipant($token, $uid, implode("\n", $lines));
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][FileReviewService] could not post the completion to the file chat', [
                'reviewId' => $review->getId(), 'error' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * @param string[] $reviewers
     */
    private function notifyRequested(FileReview $review, array $reviewers, string $uid): void {
        $this->notify($reviewers, 'file_review_requested', $review, [
            'actor'     => $this->displayName($uid),
            'actorId'   => $uid,
        ]);
    }

    private function notifyCompleted(FileReview $review, string $reviewerUid, int $outstanding): void {
        $requester = $review->getRequestedBy();
        if ($requester === $reviewerUid) {
            return;
        }

        $this->notify([$requester], 'file_review_completed', $review, [
            'actor'   => $this->displayName($reviewerUid),
            'actorId' => $reviewerUid,
        ]);

        // A second, distinct notification rather than a variant of the first:
        // on a five-reviewer request the individual ones blur together, and
        // "you can close this now" is a different piece of news.
        if ($outstanding === 0) {
            $this->notify([$requester], 'file_review_all_done', $review, [
                'actor'   => $this->displayName($reviewerUid),
                'actorId' => $reviewerUid,
            ]);
        }
    }

    /**
     * @param FileReviewer[] $roster
     */
    private function notifyClosed(FileReview $review, array $roster, string $uid): void {
        $recipients = [];
        foreach ($roster as $row) {
            if ($row->getUserId() !== $uid) {
                $recipients[] = $row->getUserId();
            }
        }

        // This is the only signal a reviewer who never completed will get:
        // their My Work row disappears silently otherwise. Still true in
        // v4.8.20 — the chat survives now, but the request leaving their queue
        // is exactly as invisible as it was.
        $this->notify($recipients, 'file_review_closed', $review, [
            'actor'   => $this->displayName($uid),
            'actorId' => $uid,
        ]);
    }

    /**
     * @param string[]             $recipients
     * @param array<string,string> $extra
     */
    private function notify(array $recipients, string $subject, FileReview $review, array $extra): void {
        $recipients = array_values(array_unique(array_filter(
            $recipients,
            static fn ($u): bool => is_string($u) && $u !== '',
        )));
        if ($recipients === []) {
            return;
        }

        $link     = $this->urlGenerator->linkToRouteAbsolute('teamhub.page.index') . '?mywork';
        $teamName = $this->getTeamName($review->getTeamId());

        foreach ($recipients as $userId) {
            try {
                $notification = $this->notificationManager->createNotification();
                $notification->setApp(Application::APP_ID)
                    ->setUser($userId)
                    ->setDateTime(new \DateTime())
                    ->setObject('file_review', (string)$review->getId())
                    ->setSubject($subject, array_merge($extra, [
                        'file'     => $review->getFileName(),
                        'fileId'   => (string)$review->getFileId(),
                        'team'     => $teamName,
                        'teamId'   => $review->getTeamId(),
                        'reviewId' => (string)$review->getId(),
                    ]))
                    ->setLink($link);
                $this->notificationManager->notify($notification);
            } catch (\Throwable $e) {
                // One recipient's notification failing must not cost the
                // others theirs, and must not fail the action that sent it.
                $this->logger->warning('[TeamHub][FileReviewService] notification failed', [
                    'subject' => $subject, 'reviewId' => $review->getId(),
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }
    }

    /**
     * The team roster for the picker.
     *
     * @return list<array{uid:string, displayName:string}>
     */
    private function teamRoster(string $teamId, string $requesterUid): array {
        $out = [];
        try {
            foreach ($this->memberService->getAllEffectiveMembers($teamId) as $member) {
                $memberUid = (string)($member['userId'] ?? '');
                if ($memberUid === '' || $memberUid === $requesterUid) {
                    continue;
                }
                $out[] = [
                    'uid'         => $memberUid,
                    'displayName' => (string)($member['displayName'] ?? $memberUid),
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][FileReviewService] roster lookup failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        return $out;
    }

    private function normaliseText(?string $value, int $max, string $field): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new ValidationException(ucfirst($field) . ' is too long');
        }

        return $value;
    }

    private function normaliseDueAt(?int $dueAt): ?int {
        if ($dueAt === null || $dueAt === 0) {
            return null;
        }
        if ($dueAt < 0 || $dueAt > self::MAX_DUE_AT) {
            throw new ValidationException('That due date is not a valid date');
        }

        return $dueAt;
    }

    /**
     * A due date, localised, in the reader's own timezone (v4.8.21).
     *
     * Copied deliberately from `MilestoneAutoPostService::formatDate()`,
     * including the reason it looks like this: `IL10N::l()` hands the DateTime
     * straight to the calendar formatter without touching its zone, and
     * `new \DateTime('@' . $ts)` is always UTC — so the language got localised
     * and the date did not, and a milestone dated the 1st read as the 31st for
     * anybody west of Greenwich. Setting the zone first is what fixes it.
     *
     * The chat post is one string for many readers, so it uses the requester's
     * language and zone — the same choice the room name made before it.
     */
    private function formatDueDate(int $ts, string $uid): string {
        try {
            $dt = (new \DateTime('@' . $ts))->setTimezone($this->timezoneService->forUser($uid));

            return (string)$this->requesterL10n($uid)->l('date', $dt);
        } catch (\Throwable) {
            return $this->timezoneService->formatTimestamp($ts, $uid);
        }
    }

    private function displayName(string $uid): string {
        try {
            $user = $this->userManager->get($uid);

            return $user !== null ? (string)$user->getDisplayName() : $uid;
        } catch (\Throwable) {
            return $uid;
        }
    }

    /**
     * The requester's own language, for the one string that is written once
     * and read by everybody: the Talk room name.
     *
     * A room has a single name, so per-recipient translation is not available
     * the way it is for notifications. The requester's language is the honest
     * choice — they are the author of the request.
     */
    private function requesterL10n(string $uid): \OCP\IL10N {
        try {
            $user = $this->userManager->get($uid);
            if ($user !== null) {
                return $this->l10nFactory->get(
                    Application::APP_ID,
                    $this->l10nFactory->getUserLanguage($user),
                );
            }
        } catch (\Throwable) {
            // Fall through to the instance default.
        }

        return $this->l10nFactory->get(Application::APP_ID);
    }

    /**
     * Team display name straight from `circles_circle`.
     *
     * Same six lines as `MessageSubscriptionService::getTeamName()` and for the
     * same reason: reaching for `TeamService` here would pull the app's
     * heaviest service into every construction of this one, to answer a
     * question that is one indexed read.
     */
    private function getTeamName(string $teamId): string {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('display_name')
                ->from('circles_circle')
                ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
                ->setMaxResults(1);
            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            return $row ? (string)$row['display_name'] : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
