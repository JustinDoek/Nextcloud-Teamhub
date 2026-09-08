<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Service\FileReviewService;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\TeamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * File review requests (v4.8.18). Design: FILE-REVIEW-PLAN.md §3.2.
 *
 * ## Two of these are called from outside TeamHub
 *
 * `getScopes` and `reviewContext` are what the Files-app file action talks to,
 * from a page TeamHub does not render. That changes nothing about how they are
 * written — every method here re-establishes the caller and their membership
 * through the service, and none of them trusts a team id or a file id because
 * it arrived in a URL. It is worth stating because the temptation with a
 * "internal, our own script calls it" endpoint is to gate it less, and this is
 * exactly the endpoint pair where that would be wrong: `reviewContext` returns
 * a team's member list.
 *
 * ## Why the team id is in the path for the single-review verbs
 *
 * `show`, `complete` and `close` could be addressed by review id alone. They
 * carry the team as well, and the team is checked against the review's own,
 * so that a caller who is a member of team A cannot reach a review in team B by
 * guessing its id — the membership check would pass on the team they named
 * while the row belongs to another. The service checks membership against the
 * *review's* team, and this controller checks that the two agree.
 *
 * No method carries `#[NoCSRFRequired]`. The three that change state keep the
 * framework's protection; the reads have nothing to gain from dropping it.
 */
class FileReviewController extends Controller {

    use ExceptionResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private FileReviewService $reviewService,
        private TeamService $teamService,
        private MemberService $memberService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Licence gate (v4.8.31), the same shape as `MyWorkController`'s.
     *
     * **This is the boundary; the Files-app entry and the My Work provider are
     * presentation.** Until this version there was no gate here at all: a
     * review could be created, completed and closed on an unlicensed instance
     * through the API, even though nobody could ever see it, because the only
     * place a review surfaces is My Work and My Work answers 403. Hiding the
     * menu entry without this would be exactly the "the frontend won't call
     * this" reasoning SKILLS.md § Security standards rules out.
     *
     * Every method is gated, `getScopes()` included. It is the cheapest one to
     * leave open and the argument for an exception was that the listener never
     * calls it unlicensed anyway — which is the same reasoning, one step
     * removed.
     */
    private function licenseGate(): ?JSONResponse {
        if ($this->reviewService->isEnabledGlobally()) {
            return null;
        }

        return new JSONResponse([
            'error'       => 'File reviews require an active TeamHub license.',
            'licenseGate' => true,
        ], Http::STATUS_FORBIDDEN);
    }

    /**
     * Team-folder path prefixes for the current user.
     *
     * One call per Files page load, and the only thing that makes a
     * synchronous `enabled()` callback possible in the file action.
     */
    #[NoAdminRequired]
    public function getScopes(): JSONResponse {
        $gate = $this->licenseGate();
        if ($gate !== null) {
            return $gate;
        }

        $uid = $this->currentUid();
        if ($uid === null) {
            return $this->unauthenticated();
        }

        try {
            return new JSONResponse([
                'scopes' => $this->reviewService->listScopes($uid, $this->userTeamIds()),
            ]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load review scopes', []);
        }
    }

    /**
     * Everything the request modal needs about one file.
     */
    #[NoAdminRequired]
    public function reviewContext(int $fileId): JSONResponse {
        $gate = $this->licenseGate();
        if ($gate !== null) {
            return $gate;
        }

        $uid = $this->currentUid();
        if ($uid === null) {
            return $this->unauthenticated();
        }

        try {
            return new JSONResponse(
                $this->reviewService->reviewContext($uid, $fileId, $this->userTeamIds()),
            );
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load review context', ['fileId' => $fileId]);
        }
    }

    /**
     * The per-team switch. Readable by any member; writable by team admins.
     *
     * Read and write are one pair of methods rather than two so the answer the
     * write returns is produced by the same code the read uses — a saved switch
     * that reports back a value from somewhere else is how a toggle ends up
     * disagreeing with itself.
     */
    #[NoAdminRequired]
    public function getConfig(string $teamId): JSONResponse {
        $gate = $this->licenseGate();
        if ($gate !== null) {
            return $gate;
        }

        $uid = $this->currentUid();
        if ($uid === null) {
            return $this->unauthenticated();
        }

        try {
            $this->memberService->requireMemberLevel($teamId);

            return new JSONResponse([
                'file_reviews_enabled' => $this->reviewService->isEnabledForTeam($teamId),
                'module_enabled'       => $this->reviewService->isEnabledGlobally(),
            ]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load the file review settings', ['teamId' => $teamId]);
        }
    }

    #[NoAdminRequired]
    public function saveConfig(string $teamId, bool $fileReviewsEnabled): JSONResponse {
        $gate = $this->licenseGate();
        if ($gate !== null) {
            return $gate;
        }

        $uid = $this->currentUid();
        if ($uid === null) {
            return $this->unauthenticated();
        }

        try {
            // Team admin, not merely a member: this decides whether a whole
            // surface exists for everybody else in the team.
            $this->memberService->requireAdminLevel($teamId);
            $this->reviewService->setEnabledForTeam($teamId, $fileReviewsEnabled);

            return new JSONResponse([
                'file_reviews_enabled' => $this->reviewService->isEnabledForTeam($teamId),
                'module_enabled'       => $this->reviewService->isEnabledGlobally(),
            ]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to save the file review settings', ['teamId' => $teamId]);
        }
    }

    #[NoAdminRequired]
    public function index(string $teamId, ?string $status = null): JSONResponse {
        $gate = $this->licenseGate();
        if ($gate !== null) {
            return $gate;
        }

        $uid = $this->currentUid();
        if ($uid === null) {
            return $this->unauthenticated();
        }

        try {
            return new JSONResponse([
                'reviews' => $this->reviewService->listForTeam($teamId, $uid, $status),
            ]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load reviews', ['teamId' => $teamId]);
        }
    }

    /**
     * @param string[]|null $reviewers null or empty means "all team members"
     */
    #[NoAdminRequired]
    public function create(
        string  $teamId,
        int     $fileId,
        ?array  $reviewers = null,
        ?string $message = null,
        ?int    $dueAt = null,
    ): JSONResponse {
        $gate = $this->licenseGate();
        if ($gate !== null) {
            return $gate;
        }

        $uid = $this->currentUid();
        if ($uid === null) {
            return $this->unauthenticated();
        }

        try {
            $review = $this->reviewService->requestReview(
                $teamId,
                $fileId,
                is_array($reviewers) ? $reviewers : [],
                $message,
                $dueAt,
                $uid,
            );

            return new JSONResponse(['review' => $review], Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to request the review', [
                'teamId' => $teamId, 'fileId' => $fileId,
            ]);
        }
    }

    #[NoAdminRequired]
    public function show(string $teamId, int $reviewId): JSONResponse {
        $gate = $this->licenseGate();
        if ($gate !== null) {
            return $gate;
        }

        $uid = $this->currentUid();
        if ($uid === null) {
            return $this->unauthenticated();
        }

        try {
            $this->assertReviewInTeam($reviewId, $teamId);

            return new JSONResponse(['review' => $this->reviewService->getReview($reviewId, $uid)]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load the review', ['reviewId' => $reviewId]);
        }
    }

    /**
     * A reviewer finishes their part.
     *
     * Completing something already completed, or a review somebody has since
     * closed, answers 409 rather than 200-with-an-error or 500. It is a queue
     * that moved on, and the client's correct response is to refresh.
     */
    #[NoAdminRequired]
    public function complete(string $teamId, int $reviewId, ?string $remark = null): JSONResponse {
        $gate = $this->licenseGate();
        if ($gate !== null) {
            return $gate;
        }

        $uid = $this->currentUid();
        if ($uid === null) {
            return $this->unauthenticated();
        }

        try {
            $this->assertReviewInTeam($reviewId, $teamId);
            $result = $this->reviewService->complete($reviewId, $uid, $remark);

            return new JSONResponse(
                $result,
                $result['status'] === 'already' ? Http::STATUS_CONFLICT : Http::STATUS_OK,
            );
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to complete the review', ['reviewId' => $reviewId]);
        }
    }

    /**
     * The requester ends the request, and the Talk room goes with it.
     */
    #[NoAdminRequired]
    public function close(string $teamId, int $reviewId): JSONResponse {
        $gate = $this->licenseGate();
        if ($gate !== null) {
            return $gate;
        }

        $uid = $this->currentUid();
        if ($uid === null) {
            return $this->unauthenticated();
        }

        try {
            $this->assertReviewInTeam($reviewId, $teamId);
            $result = $this->reviewService->close($reviewId, $uid);

            return new JSONResponse(
                $result,
                $result['status'] === 'already' ? Http::STATUS_CONFLICT : Http::STATUS_OK,
            );
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to close the review', ['reviewId' => $reviewId]);
        }
    }

    // ---------------------------------------------------------------------

    /**
     * The review must belong to the team the URL names.
     *
     * A mismatch is reported as "not found" rather than "forbidden", so that a
     * review id in a team the caller cannot see does not become discoverable by
     * the difference between the two answers.
     *
     * @throws NotFoundException
     */
    private function assertReviewInTeam(int $reviewId, string $teamId): void {
        if ($this->reviewService->loadReview($reviewId)->getTeamId() !== $teamId) {
            throw new NotFoundException('Review not found');
        }
    }

    private function currentUid(): ?string {
        $user = $this->userSession->getUser();

        return $user === null ? null : $user->getUID();
    }

    /** @return string[] */
    private function userTeamIds(): array {
        $ids = [];
        foreach ($this->teamService->getUserTeams() as $team) {
            $id = (string)($team['id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function unauthenticated(): JSONResponse {
        return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
    }
}
