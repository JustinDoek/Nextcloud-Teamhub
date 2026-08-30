<?php

declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\TeamGroupService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Personal sidebar grouping of teams (v4.7.3).
 *
 * ── Why these endpoints carry no membership gate, except one ─────────────
 *
 * Everything here is scoped to `$uid` taken from the session, never from the
 * request. A group is one user's private label for their own sidebar: it
 * reads nothing about any team, discloses nothing to anybody else, and
 * grants no access. There is no team-scoped resource to gate.
 *
 * `assignTeam` is the exception and *does* gate, because it is the one route
 * that takes a team id. The check protects nothing on its own — storing an
 * unknown id in your own preference blob buckets a team that is never
 * rendered, since the sidebar only ever iterates teams `listTeams` already
 * authorised. It is here so the route answers the question every audit of
 * SKILLS.md step 1a item 7 asks, without a reader having to reconstruct the
 * argument above, and because refusing junk is cheaper than storing it.
 *
 * All four mutations are CSRF-protected — no `#[NoCSRFRequired]` — and every
 * one of them is a PUT/POST/DELETE, so no GET mutates.
 */
class TeamGroupController extends Controller {

	use ExceptionResponseTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private TeamGroupService $teamGroupService,
		private MemberService $memberService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * GET /api/v1/team-groups
	 *
	 * @return JSONResponse{groups: list<array>, assignments: array<string, string>}
	 */
	#[NoAdminRequired]
	public function getGroups(): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($this->teamGroupService->getState($uid));
		} catch (\Throwable $e) {
			return $this->exceptionResponse($e, 'Failed to load team groups');
		}
	}

	/**
	 * POST /api/v1/team-groups
	 * Body: { name: string }
	 *
	 * Returns the whole state plus the new group's id, so the client can
	 * move a team into it without a second round trip — creating a group
	 * from a team's action menu does exactly that.
	 */
	#[NoAdminRequired]
	public function createGroup(): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$name = $this->request->getParam('name');
		if (!is_string($name)) {
			return new JSONResponse(['error' => 'name is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			return new JSONResponse($this->teamGroupService->createGroup($uid, $name));
		} catch (\Throwable $e) {
			return $this->exceptionResponse($e, 'Failed to create group');
		}
	}

	/**
	 * PUT /api/v1/team-groups/{groupId}
	 * Body: { name?: string, expanded?: bool }
	 *
	 * Only keys present in the body are written, so the chevron can persist
	 * its state with `{expanded}` alone and a rename does not have to send
	 * the current expansion.
	 */
	#[NoAdminRequired]
	public function updateGroup(string $groupId): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->request->getParams();

		$name = null;
		if (array_key_exists('name', $body)) {
			if (!is_string($body['name'])) {
				return new JSONResponse(['error' => 'name must be a string'], Http::STATUS_BAD_REQUEST);
			}
			$name = $body['name'];
		}

		$expanded = null;
		if (array_key_exists('expanded', $body)) {
			// Accept bool, int and the '0'/'1'/'true'/'false' strings a form
			// post can produce; anything else is a bad request rather than a
			// silent coercion to false.
			$expanded = filter_var($body['expanded'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
			if ($expanded === null) {
				return new JSONResponse(['error' => 'expanded must be a boolean'], Http::STATUS_BAD_REQUEST);
			}
		}

		if ($name === null && $expanded === null) {
			return new JSONResponse(['error' => 'Nothing to update'], Http::STATUS_BAD_REQUEST);
		}

		try {
			return new JSONResponse($this->teamGroupService->updateGroup($uid, $groupId, $name, $expanded));
		} catch (\Throwable $e) {
			return $this->exceptionResponse($e, 'Failed to update group', ['groupId' => $groupId]);
		}
	}

	/**
	 * DELETE /api/v1/team-groups/{groupId}
	 *
	 * The group's teams are not touched — they become loose items in the
	 * sidebar again.
	 */
	#[NoAdminRequired]
	public function deleteGroup(string $groupId): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($this->teamGroupService->deleteGroup($uid, $groupId));
		} catch (\Throwable $e) {
			return $this->exceptionResponse($e, 'Failed to delete group', ['groupId' => $groupId]);
		}
	}

	/**
	 * PUT /api/v1/teams/{teamId}/group
	 * Body: { groupId: string|null }
	 *
	 * `groupId: null` removes the team from every group, leaving it loose.
	 *
	 * Gated with `requireMemberLevel` — see the class docblock for why this
	 * one route checks and the others do not. It is the indexed direct-row
	 * lookup, not the full Circles member fetch.
	 */
	#[NoAdminRequired]
	public function assignTeam(string $teamId): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body    = $this->request->getParams();
		$groupId = $body['groupId'] ?? null;

		// An explicit null (or the empty string a form post turns it into)
		// means "ungrouped". Anything that is neither null nor a string is a
		// malformed request.
		if ($groupId === '') {
			$groupId = null;
		}
		if ($groupId !== null && !is_string($groupId)) {
			return new JSONResponse(['error' => 'groupId must be a string or null'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->memberService->requireMemberLevel($teamId);
			return new JSONResponse($this->teamGroupService->assignTeam($uid, $teamId, $groupId));
		} catch (\Throwable $e) {
			return $this->exceptionResponse($e, 'Failed to move team', ['teamId' => $teamId]);
		}
	}
}
