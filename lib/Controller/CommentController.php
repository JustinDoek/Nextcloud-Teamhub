<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\CommentMapper;
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
}
