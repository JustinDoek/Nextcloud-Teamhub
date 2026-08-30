<?php

declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Nextcloud tags on teams (v4.8.0).
 *
 * ── Where the tags come from ─────────────────────────────────────────────
 *
 * **TeamHub never creates a tag.** The vocabulary is Nextcloud's own, managed
 * by an administrator under Settings → Administration → **Basic settings**
 * (`OCA\SystemTags\Settings\Admin::getSection()` returns `server`). This
 * service only attaches existing tags to teams and reads them back, so an
 * instance keeps one tag list across Files and TeamHub rather than two that
 * drift.
 *
 * ── Why there is no table and no migration ───────────────────────────────
 *
 * A tag on a team is stored exactly where a tag on a file is stored:
 * `oc_systemtag_object_mapping`, keyed on `(objecttype, objectid, systemtagid)`.
 * `ISystemTagObjectMapper` takes a free-form `$objectType`, the column is
 * `varchar(64)` with its own index, and `objectid` is `varchar(64)` against a
 * Circles `unique_id` of 31 characters. Teams fit the existing storage
 * without a schema change.
 *
 * This is the same mechanism Nextcloud Governance uses for its Sensitivity
 * Labels: `files_confidential` creates restricted system tags
 * (`createTag($name, userVisible: true, userAssignable: false)`) and assigns
 * them with `assignTags($nodeId, 'files', …)`. We generalise the bottom half
 * off `files`. The label *rules* layer — which tags count as classification
 * labels, their precedence, automatic assignment — is deliberately not here.
 *
 * ── Why we do NOT register a SystemTagsEntityEvent entity ────────────────
 *
 * Nextcloud offers `SystemTagsEntityEvent::EVENT_ENTITY` to make a custom
 * object type taggable over WebDAV. Registering `teamhub_team` there would be
 * a permission hole. From `SystemTagsRelationsCollection` (NC 34.0.2):
 *
 *     foreach ($event->getEntityCollections() as $entity => $entityExistsFunction) {
 *         $children[] = new SystemTagsObjectTypeCollection(
 *             $entity, …, $entityExistsFunction,
 *             fn ($name) => true,          // ← childWriteAccessFunction
 *         );
 *     }
 *
 * `files` gets a real closure checking `PERMISSION_UPDATE`; every
 * app-registered entity gets `fn ($name) => true`. Any authenticated user
 * could then write tag assignments on any team via
 * `/remote.php/dav/systemtags-relations/teamhub_team/{id}`, with the
 * existence closure as the only gate — and that closure gates reads too, so
 * it cannot be overloaded into an authorisation check without also breaking
 * read access. We call the OCP APIs from our own gated routes instead.
 *
 * ── Who may assign what ──────────────────────────────────────────────────
 *
 * Reads are gated on membership, writes on `requireAdminLevel` (level ≥ 8) —
 * the same idiom as `TeamExpiryService::requestExtension`. On top of that,
 * every write asks core's own `canUserAssignTag()`. That delegates the
 * boundary rather than reimplementing it: a *restricted* tag (userAssignable
 * off) stays visible on a team but only an NC admin can set it, which is
 * exactly the behaviour a later classification feature needs.
 */
class TeamTagService {

	/**
	 * The `objecttype` written to `oc_systemtag_object_mapping`.
	 *
	 * Namespaced with the app id because the column is a shared, free-form
	 * key space — core uses `files` and any app may claim a name.
	 */
	public const OBJECT_TYPE = 'teamhub_team';

	public function __construct(
		private ISystemTagManager $tagManager,
		private ISystemTagObjectMapper $tagMapper,
		private IUserSession $userSession,
		private MemberService $memberService,
		private AuditService $auditService,
		private LoggerInterface $logger,
	) {
	}

	// -------------------------------------------------------------------------
	// Read
	// -------------------------------------------------------------------------

	/**
	 * Tags currently on a team, as plain arrays.
	 *
	 * Filtered through `canUserSeeTag()` so an invisible tag does not leak
	 * its existence to a member who may not see it.
	 *
	 * @return list<array{id: string, name: string, color: ?string, userAssignable: bool, canAssign: bool}>
	 */
	public function getTagsForTeam(string $teamId): array {
		$this->memberService->requireMemberLevel($teamId);

		return $this->readTags($teamId);
	}

	/**
	 * Every tag the caller could actually put on a team — the picker's options.
	 *
	 * Filtered by `canUserSeeTag()` and then `canUserAssignTag()`, so a
	 * restricted tag is absent rather than offered and refused.
	 *
	 * **This is not a new disclosure.** The same list, unfiltered, is already
	 * readable by any authenticated user over WebDAV at
	 * `/remote.php/dav/systemtags` — which is exactly where `NcSelectTags`
	 * gets it. We serve it ourselves only because that component offers no
	 * hook to narrow its options to the assignable ones.
	 *
	 * @return list<array{id: string, name: string, color: ?string, userAssignable: bool, canAssign: bool}>
	 */
	public function getAssignableTags(): array {
		$user = $this->userSession->getUser();

		$out = [];
		foreach ($this->tagManager->getAllTags() as $tag) {
			if (!$this->tagManager->canUserSeeTag($tag, $user)) {
				continue;
			}
			if (!$this->tagManager->canUserAssignTag($tag, $user)) {
				continue;
			}
			$out[] = $this->mapTag($tag, $user);
		}

		return $out;
	}

	// -------------------------------------------------------------------------
	// Write
	// -------------------------------------------------------------------------

	/**
	 * Assign one existing tag to a team. Returns the team's tags afterwards.
	 *
	 * Assigning a tag the team already carries is a no-op — `assignTags()`
	 * fails silently on an existing relationship — so the route is idempotent
	 * and a double click writes one audit row, not two.
	 *
	 * @return list<array{id: string, name: string, color: ?string, userAssignable: bool, canAssign: bool}>
	 */
	public function addTag(string $teamId, string $tagId): array {
		$this->memberService->requireAdminLevel($teamId);

		$tag = $this->requireAssignableTag($tagId);

		$already = in_array($tagId, $this->currentTagIds($teamId), true);

		$this->tagMapper->assignTags($teamId, self::OBJECT_TYPE, [$tagId]);

		if (!$already) {
			$this->auditService->log(
				$teamId,
				'team.tag_added',
				$this->userSession->getUser()?->getUID(),
				'team',
				$teamId,
				['tag' => $tag->getName(), 'tagId' => $tagId],
			);
		}

		return $this->readTags($teamId);
	}

	/**
	 * Remove one tag from a team. Returns the team's tags afterwards.
	 *
	 * @return list<array{id: string, name: string, color: ?string, userAssignable: bool, canAssign: bool}>
	 */
	public function removeTag(string $teamId, string $tagId): array {
		$this->memberService->requireAdminLevel($teamId);

		$tag = $this->requireAssignableTag($tagId);

		$had = in_array($tagId, $this->currentTagIds($teamId), true);

		$this->tagMapper->unassignTags($teamId, self::OBJECT_TYPE, [$tagId]);

		if ($had) {
			$this->auditService->log(
				$teamId,
				'team.tag_removed',
				$this->userSession->getUser()?->getUID(),
				'team',
				$teamId,
				['tag' => $tag->getName(), 'tagId' => $tagId],
			);
		}

		return $this->readTags($teamId);
	}

	// -------------------------------------------------------------------------
	// Bulk read — for list surfaces that would otherwise be N+1
	// -------------------------------------------------------------------------

	/**
	 * Tags for many teams at once, keyed by team id.
	 *
	 * Browse Teams and the admin All-teams table render a chip row per team;
	 * calling `getTagsForTeam()` in a loop would be one mapping query per
	 * team plus one membership check per team. This does two in total.
	 *
	 * **It performs no membership check**, because it is only ever handed a
	 * list of teams the caller has already authorised — `browseAllTeams()`
	 * and the admin table both produce one. Do not call it with team ids
	 * taken from a request.
	 *
	 * @param list<string> $teamIds
	 * @return array<string, list<array{id: string, name: string, color: ?string, userAssignable: bool, canAssign: bool}>>
	 */
	public function getTagsForTeams(array $teamIds): array {
		if ($teamIds === []) {
			return [];
		}

		$byTeam = $this->tagMapper->getTagIdsForObjects($teamIds, self::OBJECT_TYPE);

		$allIds = [];
		foreach ($byTeam as $ids) {
			foreach ($ids as $id) {
				$allIds[$id] = true;
			}
		}

		$tags = $this->resolveTags(array_keys($allIds));

		$out = [];
		foreach ($teamIds as $teamId) {
			$out[$teamId] = [];
			foreach ($byTeam[$teamId] ?? [] as $id) {
				if (isset($tags[$id])) {
					$out[$teamId][] = $tags[$id];
				}
			}
		}

		return $out;
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * @return list<string>
	 */
	private function currentTagIds(string $teamId): array {
		$map = $this->tagMapper->getTagIdsForObjects([$teamId], self::OBJECT_TYPE);

		return array_values($map[$teamId] ?? []);
	}

	/**
	 * @return list<array{id: string, name: string, color: ?string, userAssignable: bool, canAssign: bool}>
	 */
	private function readTags(string $teamId): array {
		return array_values($this->resolveTags($this->currentTagIds($teamId)));
	}

	/**
	 * Resolve tag ids to display arrays, keyed by id.
	 *
	 * A tag deleted in Basic settings leaves its mapping rows behind — core
	 * cleans up on its own event, but a row written before that listener
	 * existed, or a direct database edit, can outlive the tag. Rather than
	 * let one dangling id turn a team page into a 500, `getTagsByIds()` is
	 * retried per id and the missing ones are dropped.
	 *
	 * @param list<string> $tagIds
	 * @return array<string, array{id: string, name: string, color: ?string, userAssignable: bool, canAssign: bool}>
	 */
	private function resolveTags(array $tagIds): array {
		if ($tagIds === []) {
			return [];
		}

		try {
			$tags = $this->tagManager->getTagsByIds($tagIds);
		} catch (TagNotFoundException $e) {
			$tags = [];
			foreach ($tagIds as $id) {
				try {
					$tags += $this->tagManager->getTagsByIds([$id]);
				} catch (TagNotFoundException $inner) {
					$this->logger->debug('[TeamHub][TeamTagService] Dangling tag mapping ignored', [
						'tagId' => $id,
						'app'   => Application::APP_ID,
					]);
				}
			}
		}

		$user = $this->userSession->getUser();

		$out = [];
		foreach ($tags as $tag) {
			if (!$this->tagManager->canUserSeeTag($tag, $user)) {
				continue;
			}
			$out[$tag->getId()] = $this->mapTag($tag, $user);
		}

		return $out;
	}

	/**
	 * Load a tag for writing, refusing one the caller may not assign.
	 *
	 * `canUserAssignTag()` is core's own rule, so a restricted tag stays
	 * admin-only here without TeamHub having to know what "restricted" means.
	 */
	private function requireAssignableTag(string $tagId): ISystemTag {
		if ($tagId === '') {
			throw new ValidationException('tagId is required');
		}

		try {
			$tags = $this->tagManager->getTagsByIds([$tagId]);
		} catch (TagNotFoundException $e) {
			throw new NotFoundException('Tag not found');
		}

		$tag  = reset($tags);
		$user = $this->userSession->getUser();

		if ($tag === false || !$this->tagManager->canUserSeeTag($tag, $user)) {
			throw new NotFoundException('Tag not found');
		}

		if (!$this->tagManager->canUserAssignTag($tag, $user)) {
			throw new AccessDeniedException(
				'This tag can only be assigned by a Nextcloud administrator.',
			);
		}

		return $tag;
	}

	/**
	 * @return array{id: string, name: string, color: ?string, userAssignable: bool, canAssign: bool}
	 */
	private function mapTag(ISystemTag $tag, ?IUser $user): array {
		return [
			'id'             => $tag->getId(),
			'name'           => $tag->getName(),
			'color'          => $this->safeColor($tag->getColor()),
			'userAssignable' => $tag->isUserAssignable(),
			'canAssign'      => $this->tagManager->canUserAssignTag($tag, $user),
		];
	}

	/**
	 * Pass a tag colour through only if it is six hex digits.
	 *
	 * `oc_systemtag.color` is `varchar(6)` and core writes bare hex, so this
	 * should never reject anything real. It is here because the value's only
	 * consumer is a CSS colour in a style binding: a browser would refuse a
	 * malformed one and Vue does not interpolate it into markup, so nothing
	 * is currently exploitable — but validating the one field that reaches a
	 * style attribute is cheaper than re-deriving that argument the next time
	 * somebody moves it into a template. SKILLS.md § Defensive programming.
	 */
	private function safeColor(?string $color): ?string {
		if ($color === null) {
			return null;
		}

		return preg_match('/^[0-9A-Fa-f]{6}$/', $color) === 1 ? $color : null;
	}
}
