<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\Decision;
use OCA\TeamHub\Db\DecisionReview;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Bells for the decision review cycle (v4.11.18).
 *
 * The first notifications the Decisions module has ever had, and they are
 * deliberately only three. Everything the module did before this version
 * reached people through My Work rows, which is right: `/mywork-workflows`
 * says the row is the workflow and the bell is how you learn the row
 * appeared. A review is the module's first interaction where somebody is
 * *waiting* on somebody else — a member who asked, approvers who were asked
 * — so it is the first one where learning late has a cost.
 *
 *   decision_review_requested — to the category's approvers: a member asked.
 *   decision_review_due       — to the category's approvers: the period fell
 *                               due. No actor; the calendar is not a person.
 *   decision_review_completed — to the member who asked: an approver
 *                               answered, and what they decided.
 *
 * **Nothing is sent for a periodic review's completion to anybody but the
 * requester, and a periodic review has no requester** — so completing one
 * sends no bell at all. The approver who answered it already knows, and
 * telling the other approvers that a queue they share got shorter is the
 * kind of notification people turn off.
 *
 * **The object is the decision**, so a completed review withdraws the
 * pending bells about that decision for everyone (`withdraw()`): an approver
 * who comes back to a bell for a review a colleague already answered would
 * click through to a row that is gone.
 *
 * Per-recipient language throughout — the strings live in `Notifier.php` and
 * are resolved there against each recipient's own locale, which is why this
 * service passes facts and never sentences.
 *
 * Failure is never fatal and the cache bump happens anyway: the row is the
 * workflow, so a failed bell must not leave somebody looking at a stale
 * queue. Same contract as `TaskNotificationService`.
 */
class DecisionReviewNotificationService {

    public const NOTIFY_OBJECT = 'decision';

    public const NOTIFY_REQUESTED = 'decision_review_requested';
    public const NOTIFY_DUE       = 'decision_review_due';
    public const NOTIFY_COMPLETED = 'decision_review_completed';

    public function __construct(
        private INotificationManager $notificationManager,
        private IUserManager         $userManager,
        private ICacheFactory        $cacheFactory,
        private ITimeFactory         $timeFactory,
        private LoggerInterface      $logger,
    ) {}

    /**
     * A member asked for a review. Goes to the approvers, never back to the
     * requester — they just pressed the button.
     *
     * @param string[] $approverUids
     */
    public function reviewRequested(array $approverUids, string $requesterUid, Decision $decision): void {
        $this->sendMany($approverUids, self::NOTIFY_REQUESTED, $decision, [
            'actorUid'  => $requesterUid,
            'actorName' => $this->displayName($requesterUid),
        ], $requesterUid);
    }

    /**
     * The review period fell due. Goes to the approvers, with no actor.
     *
     * @param string[] $approverUids
     */
    public function reviewDue(array $approverUids, Decision $decision): void {
        $this->sendMany($approverUids, self::NOTIFY_DUE, $decision, [
            'dueAt' => (int)($decision->getReviewDueAt() ?? 0),
        ]);
    }

    /**
     * An approver answered a review a member asked for.
     *
     * Withdraws the pending bells about this decision first — including the
     * ones the other approvers are still holding — and only then tells the
     * requester. A periodic review has no requester, so it withdraws and
     * sends nothing, which is the whole of its "completed" behaviour.
     */
    public function reviewCompleted(DecisionReview $review, string $reviewerUid, Decision $decision): void {
        $this->withdraw((int)$decision->getId());

        $requester = $review->getRequestedBy();
        if (!$review->isRequested() || $requester === '') {
            return;
        }

        $this->sendMany([$requester], self::NOTIFY_COMPLETED, $decision, [
            'actorUid'  => $reviewerUid,
            'actorName' => $this->displayName($reviewerUid),
            'outcome'   => (string)$review->getOutcome(),
        ], $reviewerUid);
    }

    /** Clear every review bell about a decision, for everybody. */
    public function withdraw(int $decisionId): void {
        try {
            $n = $this->notificationManager->createNotification();
            $n->setApp(Application::APP_ID)
                ->setObject(self::NOTIFY_OBJECT, (string)$decisionId);
            $this->notificationManager->markProcessed($n);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][DecisionReviewNotification] withdrawal failed', [
                'decisionId' => $decisionId, 'error' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * @param string[]                 $uids
     * @param array<string,string|int> $extra
     * @param string                   $skipUid never notify this person — the
     *                                          one who caused the event
     */
    private function sendMany(
        array  $uids,
        string $subject,
        Decision $decision,
        array  $extra = [],
        string $skipUid = '',
    ): void {
        $params = array_merge([
            'teamId'     => $decision->getTeamId(),
            'decisionId' => (int)$decision->getId(),
            'question'   => $this->shorten($decision->getQuestion()),
            'category'   => (string)$decision->getCategory(),
        ], $extra);

        foreach (array_unique($uids) as $uid) {
            $uid = (string)$uid;
            if ($uid === '' || $uid === $skipUid) {
                continue;
            }
            $this->send($uid, $subject, (int)$decision->getId(), $params);
        }
    }

    /**
     * @param array<string,string|int> $params
     */
    private function send(string $uid, string $subject, int $decisionId, array $params): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($uid)
                ->setDateTime(new \DateTime('@' . $this->timeFactory->getTime()))
                ->setObject(self::NOTIFY_OBJECT, (string)$decisionId)
                ->setSubject($subject, $params);
            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][DecisionReviewNotification] notification failed', [
                'subject' => $subject, 'decisionId' => $decisionId, 'user' => $uid,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        MyWorkService::bumpNonce($this->cacheFactory, $uid);
    }

    /**
     * A decision's question can be 4000 characters. A bell shows a line, so
     * the line is what is stored — truncating in the parser would mean every
     * locale carrying the whole body to throw it away.
     */
    private function shorten(string $text): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        return mb_strlen($text) > 120 ? mb_substr($text, 0, 119) . '…' : $text;
    }

    private function displayName(string $uid): string {
        if ($uid === '') {
            return '';
        }
        $name = trim((string)($this->userManager->get($uid)?->getDisplayName() ?? ''));
        return $name !== '' ? $name : $uid;
    }
}
