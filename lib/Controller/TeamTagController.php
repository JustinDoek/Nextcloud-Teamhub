<?php

declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\TeamTagService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Nextcloud tags on teams (v4.8.0).
 *
 * ── Gating ───────────────────────────────────────────────────────────────
 *
 * Every route here is team-scoped, and **the gate lives in `TeamTagService`**,
 * not in this controller: `requireMemberLevel()` to read, `requireAdminLevel()`
 * to write, plus core's own `canUserAssignTag()` on both writes. That keeps
 * the check on the service rather than on one caller of it — the idiom
 * `TeamExpiryService::requestExtension` already uses — so a second caller
 * added later cannot reach the mapper ungated. See `HANDOFF.md` §00sec on why
 * gate idioms are worth converging rather than inventing.
 *
 * Both mutations are CSRF-protected — no `#[NoCSRFRequired]` — and neither is
 * a GET, so no GET mutates.
 *
 * The tags themselves are **not** created here. They are Nextcloud's own,
 * managed by an administrator under Settings → Administration → Basic
 * settings; these routes only attach and detach existing ones.
 */
class TeamTagController extends Controller {

	use ExceptionResponseTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private TeamTagService $teamTagService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * GET /api/v1/teams/{teamId}/tags
	 *
	 * @return JSONResponse{tags: list<array>}
	 */
	#[NoAdminRequired]
	public function getTags(string $teamId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(['tags' => $this->teamTagService->getTagsForTeam($teamId)]);
		} catch (\Throwable $e) {
			return $this->exceptionResponse($e, 'Failed to load team tags', ['teamId' => $teamId]);
		}
	}

	/**
	 * GET /api/v1/tags
	 *
	 * The picker's options: every tag the caller could put on a team. Not
	 * team-scoped, because the tag vocabulary is instance-wide — and not a
	 * new disclosure, since `/remote.php/dav/systemtags` already serves the
	 * same list to any authenticated user. See `getAssignableTags()`.
	 *
	 * @return JSONResponse{tags: list<array>}
	 */
	#[NoAdminRequired]
	public function getAvailableTags(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(['tags' => $this->teamTagService->getAssignableTags()]);
		} catch (\Throwable $e) {
			return $this->exceptionResponse($e, 'Failed to load tags');
		}
	}

	/**
	 * POST /api/v1/teams/{teamId}/tags/{tagId}
	 *
	 * Idempotent — assigning a tag the team already carries succeeds and
	 * writes no second audit row.
	 *
	 * @return JSONResponse{tags: list<array>}
	 */
	#[NoAdminRequired]
	public function addTag(string $teamId, string $tagId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(['tags' => $this->teamTagService->addTag($teamId, $tagId)]);
		} catch (\Throwable $e) {
			return $this->exceptionResponse($e, 'Failed to add tag', ['teamId' => $teamId, 'tagId' => $tagId]);
		}
	}

	/**
	 * DELETE /api/v1/teams/{teamId}/tags/{tagId}
	 *
	 * @return JSONResponse{tags: list<array>}
	 */
	#[NoAdminRequired]
	public function removeTag(string $teamId, string $tagId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(['tags' => $this->teamTagService->removeTag($teamId, $tagId)]);
		} catch (\Throwable $e) {
			return $this->exceptionResponse($e, 'Failed to remove tag', ['teamId' => $teamId, 'tagId' => $tagId]);
		}
	}
}
