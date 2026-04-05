<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\MessageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class MessageController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private MessageService $messageService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Returns { pinned: object|null, messages: array }
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listMessages(string $teamId, int $limit = 50, int $offset = 0): JSONResponse {
        try {
            $result = $this->messageService->getTeamMessages($teamId, $limit, $offset);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function createMessage(
        string $teamId,
        string $subject,
        string $message,
        string $priority = 'normal',
        string $messageType = 'normal',
        ?array $pollOptions = null
    ): JSONResponse {
        try {
            $this->logger->debug('Creating message', [
                'teamId'      => $teamId,
                'subject'     => $subject,
                'messageType' => $messageType,
                'app'         => Application::APP_ID,
            ]);
            $newMessage = $this->messageService->createMessage($teamId, $subject, $message, $priority, $messageType, $pollOptions);
            return new JSONResponse($newMessage, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create message', [
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function updateMessage(string $teamId, int $messageId, string $subject, string $message): JSONResponse {
        try {
            $updatedMessage = $this->messageService->updateMessage($messageId, $subject, $message);
            return new JSONResponse($updatedMessage);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function deleteMessage(string $teamId, int $messageId): JSONResponse {
        try {
            $this->messageService->deleteMessage($teamId, $messageId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            $status = str_contains($e->getMessage(), 'permissions') || str_contains($e->getMessage(), 'member')
                ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
            return new JSONResponse(['error' => $e->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getAggregatedMessages(): JSONResponse {
        try {
            $messages = $this->messageService->getAggregatedMessages();
            return new JSONResponse($messages);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // Pin
    // -------------------------------------------------------------------------

    #[NoAdminRequired]
    public function pinMessage(string $teamId, int $messageId): JSONResponse {
        try {
            $message = $this->messageService->pinMessage($teamId, $messageId);
            return new JSONResponse($message);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function unpinMessage(string $teamId, int $messageId): JSONResponse {
        try {
            $message = $this->messageService->unpinMessage($teamId, $messageId);
            return new JSONResponse($message);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    // -------------------------------------------------------------------------
    // Polls
    // -------------------------------------------------------------------------

    #[NoAdminRequired]
    public function votePoll(int $messageId, int $optionIndex): JSONResponse {
        try {
            $results = $this->messageService->votePoll($messageId, $optionIndex);
            return new JSONResponse($results);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getPollResults(int $messageId): JSONResponse {
        try {
            $results = $this->messageService->getPollResults($messageId);
            return new JSONResponse($results);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function closePoll(int $messageId): JSONResponse {
        try {
            $this->logger->debug('Close poll request received', [
                'messageId' => $messageId,
                'app'       => Application::APP_ID,
            ]);
            $updatedMessage = $this->messageService->closePoll($messageId);
            return new JSONResponse($updatedMessage);
        } catch (\Exception $e) {
            $this->logger->error('Close poll failed in controller', [
                'exception' => $e->getMessage(),
                'messageId' => $messageId,
                'app'       => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    // -------------------------------------------------------------------------
    // Questions
    // -------------------------------------------------------------------------

    #[NoAdminRequired]
    public function markQuestionSolved(int $messageId, int $commentId): JSONResponse {
        try {
            $this->logger->debug('Mark question solved request received', [
                'messageId' => $messageId,
                'commentId' => $commentId,
                'app'       => Application::APP_ID,
            ]);
            $updatedMessage = $this->messageService->markQuestionSolved($messageId, $commentId);
            return new JSONResponse($updatedMessage);
        } catch (\Exception $e) {
            $this->logger->error('Mark question solved failed in controller', [
                'exception' => $e->getMessage(),
                'messageId' => $messageId,
                'commentId' => $commentId,
                'app'       => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function unmarkQuestionSolved(int $messageId): JSONResponse {
        try {
            $this->logger->debug('Unmark question solved request received', [
                'messageId' => $messageId,
                'app'       => Application::APP_ID,
            ]);
            $updatedMessage = $this->messageService->unmarkQuestionSolved($messageId);
            return new JSONResponse($updatedMessage);
        } catch (\Exception $e) {
            $this->logger->error('Unmark question solved failed in controller', [
                'exception' => $e->getMessage(),
                'messageId' => $messageId,
                'app'       => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}
