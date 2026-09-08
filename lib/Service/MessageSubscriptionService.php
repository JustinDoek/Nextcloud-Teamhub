<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\MessageSubscriptionMapper;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Per-message comment-notification subscriptions (v4.8.7, GitHub #95).
 *
 * ## The model
 *
 * A subscription is a **derived** answer, not a stored one:
 *
 *     subscribed(message, user) = override(message, user) ?? (user === message.author)
 *
 * The stored table only ever holds deliberate acts. Everything else falls out
 * of the default, which is what makes the feature true for the messages that
 * already exist — the author of every historical message is subscribed
 * without a single row being written.
 *
 * The three storage states never surface as three UI states. The control is a
 * two-ended toggle whose ends are exact inverses, which is the rule DESIGN
 * §2.75 set out after the Follow toggle was removed in v4.5.40 for breaking
 * it: "Follow" and "Hide from My Work" shared one slot while meaning different
 * things, and mute had no exit. Here Subscribe and Unsubscribe are each
 * other's undo and both are always reachable.
 *
 * ## Membership is re-checked at send time, and there is no public-message
 * ## exception
 *
 * A subscription row is a preference, never an authorisation. Somebody who
 * subscribed while a member and was then removed from the team must stop
 * receiving, and `isEffectiveMember` — not the subscription table — is what
 * decides that.
 *
 * `is_public` does not soften this, which is worth stating because the
 * opposite is the intuitive guess. A public message's *body* is readable by
 * non-members through the personal feed, but its *comment thread* is not:
 * `CommentController::listComments` calls `requireMemberLevel` with no public
 * branch, and `MessageService::stampInteractionRights` already sets
 * `can_view_comments = false` for "a public post from a team you are not in",
 * withholding even the comment count. So a notification to a removed
 * subscriber would link to a thread that answers 403. The restriction is not
 * a new policy — it is the boundary the app already draws, applied to a new
 * surface.
 *
 * Overrides belonging to a removed user are kept rather than deleted. They
 * cost nothing, and if that person rejoins the team their expressed
 * preference is still theirs.
 */
class MessageSubscriptionService {

    public function __construct(
        private MessageSubscriptionMapper $subscriptionMapper,
        private MemberService $memberService,
        private IDBConnection $db,
        private INotificationManager $notificationManager,
        private IUserManager $userManager,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Is this user subscribed to comment notifications on this message?
     *
     * @param string $authorId The message's author, which supplies the default
     *                         when the user has recorded no override.
     */
    public function isSubscribed(int $messageId, string $userId, string $authorId): bool {
        $override = $this->subscriptionMapper->findOverride($messageId, $userId);
        if ($override !== null) {
            return $override;
        }
        return $userId === $authorId;
    }

    /**
     * Record a subscribe/unsubscribe and return the resulting state.
     *
     * The override is written even when it agrees with the default — an author
     * who unsubscribes and then subscribes again ends up with an explicit
     * `true` rather than no row. Both represent the same answer, so collapsing
     * one into the other would be safe; not collapsing it is simpler, and it
     * keeps "this user has decided about this thread" legible in the data.
     */
    public function setSubscribed(int $messageId, string $userId, bool $subscribed): bool {
        $this->subscriptionMapper->setOverride($messageId, $userId, $subscribed);
        return $subscribed;
    }

    /**
     * Stamp `subscribed` onto a list of message rows for one viewer.
     *
     * One query for the whole page — the alternative is a request per card,
     * which is what a stream of 50 messages would otherwise cost. Rows are
     * modified in place.
     *
     * @param array<int,array<string,mixed>> $messages
     */
    public function stampSubscriptionState(array &$messages, string $viewerUid): void {
        if ($messages === [] || $viewerUid === '') {
            return;
        }

        $ids = [];
        foreach ($messages as $m) {
            $ids[] = (int)($m['id'] ?? 0);
        }

        $overrides = $this->subscriptionMapper->findOverridesForMessages($ids, $viewerUid);

        foreach ($messages as &$m) {
            $mid = (int)($m['id'] ?? 0);
            $m['subscribed'] = $overrides[$mid]
                ?? ((string)($m['author_id'] ?? '') === $viewerUid);
        }
        unset($m);
    }

    /**
     * Forget a user's subscriptions to a team's non-public messages.
     *
     * Called from every path that ends someone's membership: `leaveTeam`,
     * `removeMember`, and the NC-admin `adminRemoveUserFromTeam`.
     *
     * **Re-tests effective membership first, and does nothing if they are
     * still in.** Every caller can leave somebody in the team by another
     * route — `leaveTeam` reports exactly that as `stillMember`, and dropping
     * a direct `circles_member` row does not touch membership inherited from a
     * group or sub-team. The check lives here rather than at the three call
     * sites so the rule has one home and a fourth caller inherits it.
     *
     * Public messages are excluded by `deleteForUserInTeam()`: a public
     * message and its thread stay readable to everyone, so a subscription to
     * one still means something after leaving.
     *
     * Best-effort and silent on failure by design: a leave or a removal must
     * not fail because a cleanup query did, and `resolveRecipients()` re-tests
     * membership at send time anyway. This deletion is hygiene — it stops dead
     * rows accumulating and keeps the table meaning what it says — not the
     * boundary.
     */
    public function forgetTeamSubscriptions(string $teamId, string $userId): void {
        if ($teamId === '' || $userId === '') {
            return;
        }
        try {
            if ($this->memberService->isEffectiveMember($teamId, $userId, $this->db)) {
                return;
            }

            $deleted = $this->subscriptionMapper->deleteForUserInTeam($teamId, $userId);
            if ($deleted > 0) {
                $this->logger->info('[TeamHub][MessageSubscriptionService] dropped message subscriptions on team departure', [
                    'teamId' => $teamId,
                    'deleted' => $deleted,
                    'app' => Application::APP_ID,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MessageSubscriptionService] could not drop subscriptions on team departure', [
                'teamId' => $teamId,
                'error' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * Everyone who should hear about a new comment on this message.
     *
     * The author unless they opted out, plus everyone who opted in, minus the
     * person who just commented — and then, for a non-public message, only
     * those still in the team.
     *
     * `$isPublic` is what makes the membership filter conditional. A public
     * message and its thread are readable by everyone (v4.8.7), so somebody
     * who subscribed and later left the team can still open exactly what the
     * notification points at, and withholding it would be withholding a
     * notification about something they can read.
     *
     * @return string[] UIDs, deduplicated.
     */
    public function resolveRecipients(
        int $messageId,
        string $teamId,
        string $authorId,
        string $excludeUid,
        bool $isPublic = false,
    ): array {
        $overrides = $this->subscriptionMapper->findOverridesForMessage($messageId);

        $candidates = [];

        // The author's default is "subscribed", so they are in unless an
        // override says otherwise.
        if (($overrides[$authorId] ?? true) === true) {
            $candidates[$authorId] = true;
        }
        // Everyone who explicitly opted in. An explicit `false` is simply not
        // added — and cannot be re-added by the line above, because that one
        // only ever considers the author.
        foreach ($overrides as $uid => $state) {
            if ($state === true) {
                $candidates[(string)$uid] = true;
            }
        }

        unset($candidates[$excludeUid]);

        // Cast to string for the same reason MessageService does at every
        // notification call site since v4.0.5: a uid that looks like an
        // integer comes back from PHP array keys as an int, and
        // `Notification::setUser()` is strict-string.
        //
        // For a public message that is the whole filter — the thread is
        // readable by anyone, so a subscriber who has left the team is still
        // a legitimate recipient, and `forgetTeamSubscriptions()` deliberately
        // leaves their row in place.
        if ($isPublic) {
            $recipients = [];
            foreach (array_keys($candidates) as $uid) {
                $uid = (string)$uid;
                if ($uid !== '') {
                    $recipients[] = $uid;
                }
            }
            return $recipients;
        }

        // Non-public: membership is the security boundary, re-checked here
        // rather than trusted from the subscription row. Departure normally
        // deletes these rows, but not every way membership ends runs that
        // cleanup — a group membership change, or the stuck Circles event in
        // HANDOFF §0000, can drop somebody from a team without any TeamHub
        // code path firing. This check is what holds in those cases.
        $recipients = [];
        foreach (array_keys($candidates) as $uid) {
            $uid = (string)$uid;
            if ($uid === '') {
                continue;
            }
            try {
                if ($this->memberService->isEffectiveMember($teamId, $uid, $this->db)) {
                    $recipients[] = $uid;
                }
            } catch (\Throwable $e) {
                // Fail closed: an unresolvable membership is not a licence to
                // notify. Logged because a team whose membership cannot be
                // read is a real problem, just not this feature's to solve.
                $this->logger->warning('[TeamHub][MessageSubscriptionService] membership check failed, skipping recipient', [
                    'teamId' => $teamId,
                    'messageId' => $messageId,
                    'error' => $e->getMessage(),
                    'app' => Application::APP_ID,
                ]);
            }
        }

        return $recipients;
    }

    /**
     * Notify every subscriber that a comment landed.
     *
     * **This method does not throw.** DESIGN §2.56 is explicit that a
     * post-commit side effect must never retroactively fail a write that
     * succeeded: the comment row is already stored by the time this runs, and
     * a notification problem is an admin's business in the log, not a 500 for
     * the person who wrote the comment. Every recipient is additionally
     * wrapped on its own so one bad uid costs one notification, not the rest
     * of the list.
     *
     * One notification per comment, deliberately — `commentId` rides in the
     * subject parameters so two comments on the same message are two distinct
     * bell entries rather than one row NC could fold together.
     *
     * @param array<string,mixed> $message The parent message row.
     */
    public function notifyNewComment(array $message, int $commentId, string $commenterUid): void {
        try {
            $messageId = (int)($message['id'] ?? 0);
            $teamId    = (string)($message['team_id'] ?? '');
            $authorId  = (string)($message['author_id'] ?? '');
            $subject   = (string)($message['subject'] ?? '');

            if ($messageId <= 0 || $teamId === '' || $authorId === '') {
                return;
            }

            $recipients = $this->resolveRecipients(
                $messageId,
                $teamId,
                $authorId,
                $commenterUid,
                !empty($message['isPublic']),
            );
            if ($recipients === []) {
                return;
            }

            $commenterName = $commenterUid;
            try {
                $u = $this->userManager->get($commenterUid);
                if ($u !== null) {
                    $commenterName = (string)$u->getDisplayName();
                }
            } catch (\Throwable) {
                // Fall back to the uid — a missing display name is not a
                // reason to withhold the notification.
            }

            $teamName = $this->getTeamName($teamId);

            // ?message= lands the reader on the page of the stream holding the
            // thread. `MessageController::listMessages` resolves it through
            // `aroundMessageId`, which has existed since v4.5.26 for the
            // "What's new" deep link — this reuses it rather than adding a
            // second way to point at a message.
            $link = $this->urlGenerator->linkToRouteAbsolute('teamhub.page.index')
                . '?team=' . urlencode($teamId)
                . '&message=' . urlencode((string)$messageId);

            foreach ($recipients as $userId) {
                try {
                    $notification = $this->notificationManager->createNotification();
                    $notification->setApp('teamhub')
                        ->setUser((string)$userId)
                        ->setDateTime(new \DateTime())
                        ->setObject('message', (string)$messageId)
                        ->setSubject('message_comment', [
                            'author'    => $commenterName,
                            'authorId'  => $commenterUid,
                            'subject'   => $subject,
                            'team'      => $teamName,
                            'teamId'    => $teamId,
                            'messageId' => $messageId,
                            'commentId' => $commentId,
                        ])
                        ->setLink($link);
                    $this->notificationManager->notify($notification);
                } catch (\Throwable $e) {
                    $this->logger->warning('[TeamHub][MessageSubscriptionService] failed to notify one subscriber', [
                        'messageId' => $messageId,
                        'error' => $e->getMessage(),
                        'app' => Application::APP_ID,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MessageSubscriptionService] comment notification dispatch failed after comment committed', [
                'commentId' => $commentId,
                'error' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * Team display name straight from `circles_circle`.
     *
     * Deliberately not borrowed from `MessageService::getTeamNameById()`,
     * which is the same query: `MessageService` calls *this* service to stamp
     * subscription state onto its payloads, so depending on it here would
     * close a constructor-injection cycle the container cannot resolve. Six
     * lines of duplication is the cheaper of the two problems.
     */
    private function getTeamName(string $teamId): string {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('display_name')
                ->from('circles_circle')
                ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
                ->setMaxResults(1);
            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            return $row ? (string)$row['display_name'] : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
