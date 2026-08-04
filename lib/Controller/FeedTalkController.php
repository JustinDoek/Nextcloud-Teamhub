<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Service\LicenseService;
use OCA\TeamHub\Service\MessageService;
use OCA\TeamHub\Service\TalkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * v4.5.26 — Talk interaction from the "What's new" feed.
 *
 * Replying inside a thread and voting on a poll, so a member does not have to
 * leave the page to take part in something the page just showed them.
 *
 * **Three gates, and none of them is the frontend.**
 *
 *  1. The licence gate, because "What's new" is a licensed feature (§2.66) and
 *     these endpoints only exist to serve it.
 *  2. `MessageService::resolveFeedRoomTeam` — the room must be connected to a
 *     team the caller is a member of, resolved through the same room→team map
 *     that put the row in their feed. A token they can reach some other way is
 *     refused here.
 *  3. Talk itself — `TalkService` builds a real `Participant` through Talk's
 *     own services for every write, so read-only rooms, lobbies, moderation and
 *     bans all apply without TeamHub having to re-implement any of them.
 *
 * Nothing here writes into Talk's tables. See the block comment above
 * `TalkService::findThreadReplies` for why reads may and writes may not.
 */
class FeedTalkController extends Controller {

    /** Longest reply this endpoint accepts. Talk's own chat limit is ~32000. */
    private const MAX_REPLY_LENGTH = 8000;

    /** Ceiling on options in one vote — a poll with more than this is not a poll. */
    private const MAX_VOTE_OPTIONS = 64;

    public function __construct(
        string $appName,
        IRequest $request,
        private MessageService $messageService,
        private TalkService $talkService,
        private LicenseService $licenseService,
        private IUserSession $userSession,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Replies inside a Talk thread shown in the feed.
     *
     * Read-only, so the room→team check is the whole gate: if the feed was
     * allowed to show the thread, its replies are part of the same
     * conversation.
     */
    // No #[NoCSRFRequired] — see the note on MessageController::getFeedPreferences.
    #[NoAdminRequired]
    public function listThreadReplies(string $token, int $threadId): JSONResponse {
        try {
            if (($gate = $this->licenceGate()) !== null) {
                return $gate;
            }
            $this->messageService->resolveFeedRoomTeam($token);

            $roomId = $this->talkService->findRoomIdByToken($token);
            $limit  = min(200, max(1, (int)$this->request->getParam('limit', 50)));

            $replies = $this->talkService->findThreadReplies($roomId, $threadId, $limit);

            return new JSONResponse([
                'replies' => $this->hydrateActorNames($replies),
                'count'   => count($replies),
            ]);
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][FeedTalkController] listThreadReplies failed', [
                'threadId' => $threadId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to load replies'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Post a reply into a Talk thread.
     *
     * Deliberately **not** gated on TeamHub's `commentMinLevel`: that setting
     * governs comments on TeamHub messages, and applying it to a Talk
     * conversation would invent a restriction the team never configured — the
     * same member can say the same thing in the Talk tab a click away. Talk's
     * own permissions are the ones that apply, and `TalkService::replyToThread`
     * routes through them.
     */
    #[NoAdminRequired]
    public function replyToThread(string $token, int $threadId): JSONResponse {
        try {
            if (($gate = $this->licenceGate()) !== null) {
                return $gate;
            }
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
            }
            $this->messageService->resolveFeedRoomTeam($token);

            $message = trim((string)$this->request->getParam('message', ''));
            if ($message === '') {
                return new JSONResponse(['error' => 'Reply cannot be empty'], Http::STATUS_BAD_REQUEST);
            }
            if (mb_strlen($message) > self::MAX_REPLY_LENGTH) {
                return new JSONResponse(['error' => 'Reply is too long'], Http::STATUS_BAD_REQUEST);
            }
            if ($threadId <= 0) {
                return new JSONResponse(['error' => 'Invalid thread'], Http::STATUS_BAD_REQUEST);
            }

            // v4.5.33 — `thread=0` marks a reply to a Talk *mention*: an
            // ordinary chat message that has no thread of its own yet. Purely a
            // hint about how Talk should associate the reply — it grants
            // nothing, so a client getting it wrong costs at most some
            // threading, never access.
            $asThread = $this->parseBoolParam('thread', true);

            $result = $this->talkService->replyToThread(
                $token, $threadId, $user->getUID(), $message, $asThread,
            );
            if (!$result['ok']) {
                // Talk's own message, surfaced rather than swallowed — 4.5.22
                // finding 22 was a signature mismatch that read as "could not
                // complete this action" for a whole session.
                return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
            }

            // Hand back the refreshed thread so the card updates in one round
            // trip instead of a post followed by a poll.
            $roomId  = $this->talkService->findRoomIdByToken($token);
            $replies = $this->talkService->findThreadReplies($roomId, $threadId, 50);

            return new JSONResponse([
                'success' => true,
                'replies' => $this->hydrateActorNames($replies),
                'count'   => count($replies),
            ], Http::STATUS_CREATED);
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][FeedTalkController] replyToThread failed', [
                'threadId' => $threadId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to post reply'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Cast a vote on a Talk poll shown in the feed.
     *
     * Option ids are indices into the poll's own option list. They are
     * validated for shape here and for membership of the poll by Talk — this
     * controller has no business deciding what a valid option is for another
     * app's poll.
     */
    #[NoAdminRequired]
    public function votePoll(string $token, int $pollId): JSONResponse {
        try {
            if (($gate = $this->licenceGate()) !== null) {
                return $gate;
            }
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
            }
            $this->messageService->resolveFeedRoomTeam($token);

            if ($pollId <= 0) {
                return new JSONResponse(['error' => 'Invalid poll'], Http::STATUS_BAD_REQUEST);
            }

            $raw = $this->request->getParam('optionIds', []);
            if (!is_array($raw)) {
                $raw = ($raw === '' || $raw === null) ? [] : explode(',', (string)$raw);
            }
            $optionIds = [];
            foreach ($raw as $value) {
                if (!is_scalar($value) || !is_numeric($value)) {
                    return new JSONResponse(['error' => 'Invalid option'], Http::STATUS_BAD_REQUEST);
                }
                $i = (int)$value;
                if ($i < 0) {
                    return new JSONResponse(['error' => 'Invalid option'], Http::STATUS_BAD_REQUEST);
                }
                $optionIds[$i] = true;
            }
            $optionIds = array_keys($optionIds);
            // An empty list is how Talk expresses "retract my vote", so it is
            // allowed through rather than rejected as a missing parameter.
            if (count($optionIds) > self::MAX_VOTE_OPTIONS) {
                return new JSONResponse(['error' => 'Too many options'], Http::STATUS_BAD_REQUEST);
            }

            $result = $this->talkService->votePoll($token, $pollId, $optionIds, $user->getUID());
            if (!$result['ok']) {
                return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
            }

            // Hand back the new tallies with the vote so the card can update
            // its bars in place. Without this the view has to refetch the
            // whole feed to move one percentage, which collapses every
            // expanded thread on the page.
            $roomId  = $this->talkService->findRoomIdByToken($token);
            $tallies = $this->talkService->findPollTallies($roomId, $pollId);

            return new JSONResponse([
                'success'    => true,
                'my_votes'   => $this->talkService->findOwnPollVotes([$pollId], $user->getUID())[$pollId] ?? [],
                'votes'      => $tallies['votes'] ?? null,
                'num_voters' => $tallies['num_voters'] ?? null,
                'status'     => $tallies['status'] ?? null,
            ]);
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][FeedTalkController] votePoll failed', [
                'pollId' => $pollId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to record vote'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ---------------------------------------------------------------------

    /** Same coercions as MessageController::parseBoolParam. */
    private function parseBoolParam(string $name, bool $default): bool {
        $raw = $this->request->getParam($name, null);
        if ($raw === null) {
            return $default;
        }
        if (is_bool($raw)) {
            return $raw;
        }
        $s = strtolower((string)$raw);
        return $s === '1' || $s === 'true' || $s === 'yes' || $s === 'on';
    }

    /**
     * "What's new" is a licensed feature (§2.66) and these endpoints are part
     * of it. Same ladder as `MessageController::getPersonalFeed`: `none` and
     * `grace` are active, anything else refuses with a flag the frontend can
     * tell apart from a generic failure.
     */
    private function licenceGate(): ?JSONResponse {
        $level = $this->licenseService->getEnforcementLevel();
        if ($level === 'none' || $level === 'grace') {
            return null;
        }
        return new JSONResponse([
            'error'            => "What’s new requires an active TeamHub license.",
            'licenseGate'      => true,
            'enforcementLevel' => $level,
        ], Http::STATUS_FORBIDDEN);
    }

    /**
     * Attach `actor_display_name` to Talk replies, mirroring
     * `CommentController::hydrateAuthorNames`.
     *
     * Only `users` actors are resolved. A guest or federated actor keeps its
     * raw id rather than being looked up as a local user of the same name —
     * attributing a federated reply to whoever happens to hold that uid here
     * would be a quiet identity bug.
     *
     * @param array<int,array<string,mixed>> $replies
     * @return array<int,array<string,mixed>>
     */
    private function hydrateActorNames(array $replies): array {
        $uids = [];
        foreach ($replies as $r) {
            if (($r['actor_type'] ?? 'users') === 'users' && !empty($r['actor_id'])) {
                $uids[(string)$r['actor_id']] = true;
            }
        }
        $nameMap = [];
        foreach (array_keys($uids) as $uid) {
            try {
                $u = $this->userManager->get($uid);
                $nameMap[$uid] = $u ? (string)$u->getDisplayName() : $uid;
            } catch (\Throwable) {
                $nameMap[$uid] = $uid;
            }
        }
        foreach ($replies as &$r) {
            $id = (string)($r['actor_id'] ?? '');
            $r['actor_display_name'] = $nameMap[$id] ?? $id;
        }
        unset($r);

        return $replies;
    }
}
