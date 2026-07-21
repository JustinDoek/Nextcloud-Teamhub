<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\CommentMapper;
use OCA\TeamHub\Db\MessageMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\DecisionService;
use OCA\TeamHub\Service\MemberService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class CommentController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private CommentMapper $commentMapper,
        private MessageMapper $messageMapper,
        private MemberService $memberService,
        private AuditService $auditService,
        private DecisionService $decisionService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function updateComment(int $commentId, string $comment): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if (empty(trim($comment))) {
            return new JSONResponse(['error' => 'Comment cannot be empty'], Http::STATUS_BAD_REQUEST);
        }

        try {
            // Decision lock: refuse non-admin updates when the parent message
            // is in a terminal decision state. Look up the comment first to
            // resolve message_id.
            $existing = $this->commentMapper->find($commentId);
            if ($existing === null) {
                return new JSONResponse(['error' => 'Comment not found'], Http::STATUS_NOT_FOUND);
            }

            // Require membership of the comment's team before allowing an edit
            // (a user removed from the team must not keep editing old comments).
            try {
                $message = $this->messageMapper->find((int)$existing['message_id']);
            } catch (\Throwable $e) {
                return new JSONResponse(['error' => 'Message not found'], Http::STATUS_NOT_FOUND);
            }
            $this->memberService->requireMemberLevel((string)$message['team_id']);

            $lockError = $this->checkDecisionLockForMessage(
                (int)$existing['message_id'],
                $user->getUID(),
            );
            if ($lockError !== null) {
                return $lockError;
            }

            $data = $this->commentMapper->update($commentId, $user->getUID(), $comment);
            return new JSONResponse($data);
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update comment', [
                'commentId' => $commentId,
                'exception' => $e,
                'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listComments(int $messageId): JSONResponse {
        try {
            // Resolve the parent message's team and require membership before
            // exposing its comment thread. A comment row carries no team_id of
            // its own, so without this an authenticated non-member could read
            // any team's comments by iterating messageId.
            try {
                $message = $this->messageMapper->find($messageId);
            } catch (\Throwable $e) {
                return new JSONResponse(['error' => 'Message not found'], Http::STATUS_NOT_FOUND);
            }
            $this->memberService->requireMemberLevel((string)$message['team_id']);

            $comments = $this->commentMapper->findByMessageId($messageId);
            return new JSONResponse($comments);
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list comments', [
                'messageId' => $messageId,
                'exception' => $e,
                'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function createComment(int $messageId, string $comment): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if (empty(trim($comment))) {
            return new JSONResponse(['error' => 'Comment cannot be empty'], Http::STATUS_BAD_REQUEST);
        }

        try {
            // Resolve the message's team and require membership before allowing
            // a comment write — a non-member must not be able to post into a
            // team's thread by iterating messageId.
            try {
                $message = $this->messageMapper->find($messageId);
            } catch (\Throwable $e) {
                return new JSONResponse(['error' => 'Message not found'], Http::STATUS_NOT_FOUND);
            }
            $this->memberService->requireMemberLevel((string)$message['team_id']);

            // Decision lock: when the parent message has a decision in
            // status='decided' or 'withdrawn', comments are frozen for
            // non-admins. Admins may still moderate (write).
            $lockError = $this->checkDecisionLockForMessage($messageId, $user->getUID());
            if ($lockError !== null) {
                return $lockError;
            }

            $data = $this->commentMapper->create($messageId, $user->getUID(), $comment);

            // Session J — if the parent message has a decision row, log a
            // 'commented' audit transition. Best-effort; service swallows
            // its own failures.
            $this->decisionService->auditCommentOnDecision(
                $messageId,
                (int)($data['id'] ?? 0),
                $user->getUID(),
                $comment,
            );

            return new JSONResponse($data, Http::STATUS_CREATED);
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create comment', [
                'messageId' => $messageId,
                'exception' => $e,
                'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Hard-delete a single comment.
     *
     * Authorisation:
     *   - the comment author may delete their own comment, OR
     *   - a team admin (level >= 8) of the team that owns the parent message may delete any comment.
     *
     * Side effects:
     *   - Writes a `comment.deleted` audit log entry on the team's audit trail
     *     with metadata { message_id, author_id, deleted_by_admin, cleared_solved }.
     *   - If the deleted comment was the marked answer to a solved question,
     *     the parent message is reverted to unsolved (question_solved=0,
     *     solved_comment_id=NULL).
     *
     * Returns the updated parent message so the frontend can refresh comment_count
     * and any solved-state UI in a single round trip.
     */
    #[NoAdminRequired]
    public function deleteComment(int $commentId): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $uid = $user->getUID();


        try {
            // Look up the target comment.
            $comment = $this->commentMapper->find($commentId);
            if ($comment === null) {
                return new JSONResponse(['error' => 'Comment not found'], Http::STATUS_NOT_FOUND);
            }

            $authorId  = (string)$comment['author_id'];
            $messageId = (int)$comment['message_id'];

            // Look up the parent message — needed for team_id (audit + auth) and
            // for the solved-question revert. find() throws if the message has
            // been deleted underneath the comment, which is a 404 condition.
            try {
                $message = $this->messageMapper->find($messageId);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][CommentController] orphan comment — parent message missing', [
                    'commentId' => $commentId,
                    'messageId' => $messageId,
                    'app' => Application::APP_ID,
                ]);
                return new JSONResponse(['error' => 'Parent message not found'], Http::STATUS_NOT_FOUND);
            }

            $teamId = (string)$message['team_id'];

            // Decision lock: when the parent message has a terminal-state
            // decision, refuse the delete unless the caller is a team admin.
            // (Authors are still locked out — the freeze is real, not just
            // a hint to non-authors.) Admins fall through to the existing
            // authorisation block which will set deletedByAdmin=true.
            $isAuthor = ($authorId === $uid);
            $isAdmin = false;
            try {
                $this->memberService->requireAdminLevel($teamId);
                $isAdmin = true;
            } catch (\Throwable) {
                $isAdmin = false;
            }
            if (!$isAdmin && $this->decisionService->isCommentLocked($teamId, $messageId)) {
                return new JSONResponse(
                    ['error' => 'Comments are locked: this message has a decided or withdrawn decision'],
                    Http::STATUS_FORBIDDEN
                );
            }

            // Authorisation: author may always delete; otherwise require team admin.
            $deletedByAdmin = false;
            if (!$isAuthor) {
                if (!$isAdmin) {
                    return new JSONResponse(['error' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
                }
                $deletedByAdmin = true;
            }

            // Solved-question revert: if this comment was the marked answer,
            // clear the solved flag on the parent message before deleting the
            // comment row so we never leave a dangling solved_comment_id pointing
            // at a non-existent comment.
            $clearedSolved = false;
            $solvedCommentId = isset($message['solvedCommentId']) ? (int)$message['solvedCommentId'] : 0;
            if (!empty($message['questionSolved']) && $solvedCommentId === $commentId) {
                $this->messageMapper->unmarkQuestionSolved($messageId);
                $clearedSolved = true;
            }

            // Hard delete.
            $this->commentMapper->delete($commentId);

            // Audit log — best-effort; AuditService swallows internal failures.
            $this->auditService->log(
                $teamId,
                'comment.deleted',
                $uid,
                'comment',
                (string)$commentId,
                [
                    'message_id'       => $messageId,
                    'author_id'        => $authorId,
                    'deleted_by_admin' => $deletedByAdmin,
                    'cleared_solved'   => $clearedSolved,
                ],
            );

            // Re-fetch the parent message so the response carries an up-to-date
            // questionSolved/solvedCommentId state and a fresh comment_count
            // (find() does not include comment_count in its query).
            $updatedMessage = $this->messageMapper->find($messageId);
            $updatedMessage['comment_count'] = $this->commentMapper->countByMessageId($messageId);

            return new JSONResponse([
                'success'        => true,
                'commentId'      => $commentId,
                'messageId'      => $messageId,
                'message'        => $updatedMessage,
                'cleared_solved' => $clearedSolved,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete comment', [
                'commentId' => $commentId,
                'exception' => $e,
                'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Decision-lock helper for create/update flows.
     *
     * Returns a 403 JSONResponse to be returned to the caller when:
     *   - the parent message exists,
     *   - the team has the Decisions module enabled,
     *   - the message has a decision row in 'decided' or 'withdrawn' state,
     *   - AND the acting user is NOT a team admin (admins may always moderate).
     *
     * Returns null when the comment write should proceed normally.
     *
     * Errors looking up the message are swallowed (returns null) because
     * the caller's own logic will fail on a missing message — duplicating
     * the 404 path here would just confuse the response shape.
     */
    private function checkDecisionLockForMessage(int $messageId, string $uid): ?JSONResponse {
        try {
            $message = $this->messageMapper->find($messageId);
        } catch (\Throwable) {
            // Parent message missing — let the actual create/update path
            // surface the right error.
            return null;
        }
        $teamId = (string)$message['team_id'];

        if (!$this->decisionService->isCommentLocked($teamId, $messageId)) {
            return null;
        }

        // Locked. Admin override: admin may proceed.
        try {
            $this->memberService->requireAdminLevel($teamId);
            return null;
        } catch (\Throwable) {
            return new JSONResponse(
                ['error' => 'Comments are locked: this message has a decided or withdrawn decision'],
                Http::STATUS_FORBIDDEN
            );
        }
    }
}
