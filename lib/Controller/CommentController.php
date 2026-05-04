<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\CommentMapper;
use OCA\TeamHub\Db\MessageMapper;
use OCA\TeamHub\Service\AuditService;
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
            $data = $this->commentMapper->update($commentId, $user->getUID(), $comment);
            return new JSONResponse($data);
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
            $comments = $this->commentMapper->findByMessageId($messageId);
            return new JSONResponse($comments);
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
            $data = $this->commentMapper->create($messageId, $user->getUID(), $comment);
            return new JSONResponse($data, Http::STATUS_CREATED);
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

            // Authorisation: author may always delete; otherwise require team admin.
            $isAuthor = ($authorId === $uid);
            $deletedByAdmin = false;
            if (!$isAuthor) {
                try {
                    $this->memberService->requireAdminLevel($teamId);
                    $deletedByAdmin = true;
                } catch (\Throwable $e) {
                    return new JSONResponse(['error' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
                }
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
}
