<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\MessageMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\MessageSubscriptionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Subscribe/unsubscribe to comment notifications on one message
 * (v4.8.7, GitHub #95).
 *
 * Two verbs and no GET. The current state ships on every message row from
 * `MessageService::getTeamMessages()`, and both writes answer with the state
 * they produced, so a read endpoint would have no caller — and an endpoint
 * with no caller is one more surface to gate and audit for nothing.
 *
 * Neither method carries `#[NoCSRFRequired]`: both change state, so both keep
 * the framework's CSRF protection.
 */
class MessageSubscriptionController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private MessageMapper $messageMapper,
        private MemberService $memberService,
        private MessageSubscriptionService $subscriptionService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function subscribe(int $messageId): JSONResponse {
        return $this->setSubscription($messageId, true);
    }

    #[NoAdminRequired]
    public function unsubscribe(int $messageId): JSONResponse {
        return $this->setSubscription($messageId, false);
    }

    /**
     * Both verbs, one gate.
     *
     * Membership is required to change a subscription for the same reason it
     * is required to read the thread: a non-member cannot open the comments
     * (`CommentController::listComments` refuses them, `is_public` included),
     * so there is nothing for them to subscribe to. This is also what stops a
     * removed member from re-subscribing themselves — though it is not the
     * load-bearing check, because `MessageSubscriptionService` re-tests
     * membership when it sends, not when the row was written.
     */
    private function setSubscription(int $messageId, bool $subscribed): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            try {
                $message = $this->messageMapper->find($messageId);
            } catch (\Throwable) {
                return new JSONResponse(['error' => 'Message not found'], Http::STATUS_NOT_FOUND);
            }

            $this->memberService->requireMemberLevel((string)$message['team_id']);

            $state = $this->subscriptionService->setSubscribed(
                $messageId,
                $user->getUID(),
                $subscribed,
            );

            return new JSONResponse([
                'messageId'  => $messageId,
                'subscribed' => $state,
            ]);
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update message subscription', [
                'messageId' => $messageId,
                'exception' => $e,
                'app' => Application::APP_ID,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to update subscription'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
