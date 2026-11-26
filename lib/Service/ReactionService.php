<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\CommentMapper;
use OCA\TeamHub\Db\MessageMapper;
use OCA\TeamHub\Db\ReactionMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Emoji reactions on team messages and on their comments (v4.20.27).
 *
 * The rules, all enforced here rather than in the card:
 *  - **Reacting needs membership** of the message's team, direct or through a
 *    group or sub-team (`MemberService::requireMemberLevel`). Reading the
 *    reactions comes with reading the message, so it has no gate of its own.
 *  - **One of each emoji per person per target**, by a unique index; adding
 *    one twice is not an error, it is already there.
 *  - **No notification.** A reaction is the lightweight answer that saves a
 *    comment; if every 👍 rang the author's bell it would be a comment again.
 *  - Two ceilings that only an abuser meets: 20 different emojis on one
 *    target, 10 of them from one person.
 *
 * Depends on mappers and MemberService only, so MessageService and
 * CommentController can both inject it without closing a cycle.
 */
class ReactionService {

    public const TARGET_MESSAGE = 'message';
    public const TARGET_COMMENT = 'comment';

    private const MAX_DISTINCT_PER_TARGET = 20;
    private const MAX_PER_USER_PER_TARGET = 10;
    /** Names listed in a chip's tooltip; the rest are counted. */
    private const MAX_NAMES = 10;

    public function __construct(
        private ReactionMapper $reactionMapper,
        private MessageMapper $messageMapper,
        private CommentMapper $commentMapper,
        private MemberService $memberService,
        private IUserSession $userSession,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {}

    /**
     * Add the viewer's `$emoji` to a message or a comment.
     *
     * @return list<array<string,mixed>> the target's reactions afterwards
     * @throws ValidationException|NotFoundException|AccessDeniedException
     */
    public function add(string $targetType, int $targetId, string $emoji): array {
        $emoji = self::normaliseEmoji($emoji);
        $uid   = $this->requireUid();
        [$teamId, $messageId] = $this->resolveTarget($targetType, $targetId);
        $this->memberService->requireMemberLevel($teamId);

        $counts = $this->reactionMapper->countsFor($targetType, $targetId, $uid);
        $isNewEmoji = !in_array($emoji, $counts['emojis'], true);
        if ($isNewEmoji && $counts['distinct'] >= self::MAX_DISTINCT_PER_TARGET) {
            throw new ValidationException('This item has the maximum number of different reactions');
        }
        if ($counts['mine'] >= self::MAX_PER_USER_PER_TARGET) {
            throw new ValidationException('You have added the maximum number of reactions to this item');
        }

        $this->reactionMapper->insert($teamId, $targetType, $targetId, $messageId, $uid, $emoji);
        return $this->summaryFor($targetType, $targetId, $uid);
    }

    /**
     * Take the viewer's `$emoji` off a message or a comment. Removing one that
     * is not there is not an error.
     *
     * @return list<array<string,mixed>> the target's reactions afterwards
     */
    public function remove(string $targetType, int $targetId, string $emoji): array {
        $emoji = self::normaliseEmoji($emoji);
        $uid   = $this->requireUid();
        [$teamId] = $this->resolveTarget($targetType, $targetId);
        $this->memberService->requireMemberLevel($teamId);

        $this->reactionMapper->deleteOne($targetType, $targetId, $uid, $emoji);
        return $this->summaryFor($targetType, $targetId, $uid);
    }

    /**
     * Stamp `reactions` onto rows that carry an `id` — message rows from the
     * stream, comment rows from a thread. One query for the batch. Never
     * throws: a reaction lookup failing must not take the stream with it.
     *
     * @param array<int|string, array<string,mixed>> $rows
     */
    public function stamp(array &$rows, string $targetType, string $viewerUid): void {
        if ($rows === []) {
            return;
        }
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int)($row['id'] ?? 0);
        }
        try {
            $summaries = $this->summaries($targetType, $ids, $viewerUid);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ReactionService] stamp failed', [
                'targetType' => $targetType, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            $summaries = [];
        }
        foreach ($rows as &$row) {
            $row['reactions'] = $summaries[(int)($row['id'] ?? 0)] ?? [];
        }
        unset($row);
    }

    /** A deleted message takes its own reactions and its thread's. Never throws. */
    public function deleteForMessage(int $messageId): void {
        try {
            $this->reactionMapper->deleteByMessageId($messageId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ReactionService] deleteForMessage failed', [
                'messageId' => $messageId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    /** Never throws. */
    public function deleteForComment(int $commentId): void {
        try {
            $this->reactionMapper->deleteByTarget(self::TARGET_COMMENT, $commentId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ReactionService] deleteForComment failed', [
                'commentId' => $commentId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * An emoji as stored: trimmed, at most 8 code points and 32 bytes, and
     * no letters, digits, whitespace or markup characters. That admits every
     * emoji the picker offers (skin tones and ZWJ sequences included) and
     * refuses a word typed into the API. A keycap such as 1️⃣ starts with a
     * digit and is refused; the picker's keycaps are not worth a looser rule.
     *
     * @throws ValidationException
     */
    public static function normaliseEmoji(string $emoji): string {
        $emoji = trim($emoji);
        if ($emoji === ''
            || strlen($emoji) > 32
            || !mb_check_encoding($emoji, 'UTF-8')
            || mb_strlen($emoji, 'UTF-8') > 8
            || preg_match('/[\p{L}\p{N}\s<>&"\'`\\\\\/]/u', $emoji) === 1
        ) {
            throw new ValidationException('Not a valid reaction');
        }
        return $emoji;
    }

    // -------------------------------------------------------------------------

    /**
     * @param int[] $targetIds
     * @return array<int, list<array{emoji:string, count:int, reacted:bool, names:list<string>, more:int}>>
     */
    private function summaries(string $targetType, array $targetIds, string $viewerUid): array {
        $rows = $this->reactionMapper->findForTargets($targetType, $targetIds);
        if ($rows === []) {
            return [];
        }

        $nameCache = [];
        $grouped   = [];
        foreach ($rows as $row) {
            $tid   = $row['target_id'];
            $emoji = $row['emoji'];
            $uid   = $row['user_id'];
            if (!isset($grouped[$tid][$emoji])) {
                $grouped[$tid][$emoji] = ['emoji' => $emoji, 'count' => 0, 'reacted' => false, 'names' => [], 'more' => 0];
            }
            $g = &$grouped[$tid][$emoji];
            $g['count']++;
            if ($uid === $viewerUid) {
                $g['reacted'] = true;
            }
            if (count($g['names']) < self::MAX_NAMES) {
                if (!array_key_exists($uid, $nameCache)) {
                    $nameCache[$uid] = $this->userManager->getDisplayName($uid) ?? $uid;
                }
                $g['names'][] = $nameCache[$uid];
            } else {
                $g['more']++;
            }
            unset($g);
        }

        // Emoji order is first-given-first, which `findForTargets`' id order
        // already produced; strip the keys for JSON.
        $out = [];
        foreach ($grouped as $tid => $byEmoji) {
            $out[$tid] = array_values($byEmoji);
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function summaryFor(string $targetType, int $targetId, string $viewerUid): array {
        return $this->summaries($targetType, [$targetId], $viewerUid)[$targetId] ?? [];
    }

    /**
     * @return array{0:string, 1:int} [teamId, messageId] — for a comment, its parent message
     * @throws ValidationException|NotFoundException
     */
    private function resolveTarget(string $targetType, int $targetId): array {
        if ($targetId <= 0) {
            throw new NotFoundException('Not found');
        }
        if ($targetType === self::TARGET_MESSAGE) {
            $message = $this->findMessage($targetId);
            return [(string)$message['team_id'], $targetId];
        }
        if ($targetType === self::TARGET_COMMENT) {
            $comment = $this->commentMapper->find($targetId);
            if ($comment === null) {
                throw new NotFoundException('Comment not found');
            }
            $messageId = (int)($comment['message_id'] ?? 0);
            $message   = $this->findMessage($messageId);
            return [(string)$message['team_id'], $messageId];
        }
        throw new ValidationException('Unknown reaction target');
    }

    /** @throws NotFoundException */
    private function findMessage(int $messageId): array {
        try {
            return $this->messageMapper->find($messageId);
        } catch (\Throwable) {
            throw new NotFoundException('Message not found');
        }
    }

    /** @throws AccessDeniedException */
    private function requireUid(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new AccessDeniedException('User not authenticated');
        }
        return $user->getUID();
    }
}
