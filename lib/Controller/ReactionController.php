<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\ReactionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Emoji reactions on team messages and comments (v4.20.27).
 *
 * Thin by design: every rule (membership of the message's team, one of each
 * emoji per person, the two ceilings, valid emoji) lives in ReactionService.
 * Each write answers with the target's reactions afterwards, so the card
 * shows the server's count rather than its own guess.
 *
 * No `#[NoCSRFRequired]`: these are writes from the TeamHub page, which
 * sends the request token with every axios call.
 */
class ReactionController extends Controller {
    use ExceptionResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private ReactionService $reactionService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function addMessageReaction(int $messageId, string $emoji = ''): DataResponse|JSONResponse {
        return $this->run(fn (): array => $this->reactionService->add(ReactionService::TARGET_MESSAGE, $messageId, $emoji), ['messageId' => $messageId]);
    }

    #[NoAdminRequired]
    public function removeMessageReaction(int $messageId, string $emoji = ''): DataResponse|JSONResponse {
        return $this->run(fn (): array => $this->reactionService->remove(ReactionService::TARGET_MESSAGE, $messageId, $emoji), ['messageId' => $messageId]);
    }

    #[NoAdminRequired]
    public function addCommentReaction(int $commentId, string $emoji = ''): DataResponse|JSONResponse {
        return $this->run(fn (): array => $this->reactionService->add(ReactionService::TARGET_COMMENT, $commentId, $emoji), ['commentId' => $commentId]);
    }

    #[NoAdminRequired]
    public function removeCommentReaction(int $commentId, string $emoji = ''): DataResponse|JSONResponse {
        return $this->run(fn (): array => $this->reactionService->remove(ReactionService::TARGET_COMMENT, $commentId, $emoji), ['commentId' => $commentId]);
    }

    private function run(callable $write, array $context): DataResponse|JSONResponse {
        try {
            return new DataResponse(['reactions' => $write()]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not save the reaction', $context);
        }
    }
}
