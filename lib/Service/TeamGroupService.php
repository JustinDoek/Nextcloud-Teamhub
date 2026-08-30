<?php

declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCP\IConfig;

/**
 * Personal grouping of the teams in the sidebar (v4.7.3).
 *
 * A user on a large instance can belong to dozens of teams, and a flat list
 * stops being navigable well before that. This lets each user bucket their
 * own teams into named, collapsible groups.
 *
 * Why `oc_preferences` and not a table. Every value here is one user's view
 * of their own sidebar: nothing else reads it, no other user can see it, and
 * there is no query that spans users. A table would buy a migration and an
 * index for a blob that is read once per page load and written on a menu
 * click. This is the same call `MyWorkConfigService` and the feed preferences
 * made, and `LayoutController` before them.
 *
 * ── One built-in group, and no catch-all bucket ──────────────────────────
 *
 * `favorites` always exists and cannot be deleted or renamed. Everything
 * else is the user's own.
 *
 * There is deliberately **no "Others" group**. A team that has not been put
 * anywhere is not rendered inside a bucket — it stays a loose item in the
 * sidebar, exactly where it is today. That is what makes this additive: a
 * user who never touches the feature sees the flat list they already had
 * with one empty group above it, and an upgrade cannot make anybody's teams
 * appear to vanish into a collapsed container. It also means deleting a
 * group needs no destination — its teams simply become loose again.
 *
 * **The built-in's name is deliberately not stored.** A stored "Favorites"
 * would be written in whatever language the user happened to be running when
 * the preference was first saved, and would then stay English forever for a
 * reader who later switches to Dutch. The service returns `name: null` for
 * the built-in and the client renders the translated label. Only user-created
 * groups carry a stored name, because that name is the user's own words.
 *
 * ── Membership is implicit, not enumerated ───────────────────────────────
 *
 * `assignments` maps team id → group id and holds only teams the user has
 * actually placed. A team with no entry is loose. That is what makes joining
 * a team need no write at all, and what stops a team the user has left from
 * leaving a dangling row: it is simply never looked up again.
 */
class TeamGroupService {

	/** Key under the `teamhub` app id in `oc_preferences`. */
	public const PREF_KEY = 'team_groups';

	public const GROUP_FAVORITES = 'favorites';

	/**
	 * Bounds on the stored blob. None of these is a limit a real user meets;
	 * they exist so a scripted caller cannot grow one preference row without
	 * end. `oc_preferences.configvalue` is LONGTEXT, so the ceiling is a
	 * choice rather than a schema constraint — which is exactly why it has
	 * to be made here.
	 */
	private const MAX_GROUPS         = 50;
	private const MAX_ASSIGNMENTS    = 2000;
	private const MAX_NAME_LENGTH    = 64;
	private const MAX_TEAM_ID_LENGTH = 64;

	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * The user's whole grouping state, normalised and safe to render.
	 *
	 * @return array{groups: list<array{id: string, name: ?string, expanded: bool, builtin: bool}>, assignments: array<string, string>}
	 */
	public function getState(string $uid): array {
		return $this->read($uid);
	}

	/**
	 * Create a group. The name is the user's own text, so it is the one
	 * field here that needs real validation.
	 *
	 * @return array{groups: list<array{id: string, name: ?string, expanded: bool, builtin: bool}>, assignments: array<string, string>, groupId: string}
	 */
	public function createGroup(string $uid, string $name): array {
		$state = $this->read($uid);
		$name  = $this->validateName($name);

		if (count($state['groups']) >= self::MAX_GROUPS) {
			throw new ValidationException('You have reached the maximum number of groups');
		}
		foreach ($state['groups'] as $group) {
			if ($group['name'] !== null && mb_strtolower($group['name']) === mb_strtolower($name)) {
				throw new ValidationException('A group with that name already exists');
			}
		}

		// Server-generated id. A client never names a group id, so an id can
		// never collide with the built-in or carry anything but hex.
		$groupId = 'g' . bin2hex(random_bytes(6));

		// New groups start collapsed — a group is created to tidy a long list
		// away, so opening it by default would defeat the point. Favorites is
		// the exception and starts expanded (see defaults()).
		$state['groups'][] = [
			'id'       => $groupId,
			'name'     => $name,
			'expanded' => false,
			'builtin'  => false,
		];

		$this->write($uid, $state);

		return $state + ['groupId' => $groupId];
	}

	/**
	 * Rename a group and/or set its expanded state.
	 *
	 * Both fields are optional so the chevron can persist its state without
	 * sending a name, and a rename does not have to know the current
	 * expansion. Favorites accepts `expanded` but refuses `name` — its label
	 * is translated, not stored.
	 *
	 * @return array{groups: list<array{id: string, name: ?string, expanded: bool, builtin: bool}>, assignments: array<string, string>}
	 */
	public function updateGroup(string $uid, string $groupId, ?string $name, ?bool $expanded): array {
		$state = $this->read($uid);
		$index = $this->indexOf($state['groups'], $groupId);

		if ($index === null) {
			throw new NotFoundException('Group not found');
		}

		if ($name !== null) {
			if ($state['groups'][$index]['builtin']) {
				throw new ValidationException('This group cannot be renamed');
			}
			$name = $this->validateName($name);
			foreach ($state['groups'] as $i => $group) {
				if ($i !== $index
					&& $group['name'] !== null
					&& mb_strtolower($group['name']) === mb_strtolower($name)) {
					throw new ValidationException('A group with that name already exists');
				}
			}
			$state['groups'][$index]['name'] = $name;
		}

		if ($expanded !== null) {
			$state['groups'][$index]['expanded'] = $expanded;
		}

		$this->write($uid, $state);

		return $state;
	}

	/**
	 * Delete a group. Its teams are not deleted and do not move into another
	 * container — they lose their assignment and become loose items again,
	 * which is why this drops the rows rather than rewriting them.
	 *
	 * @return array{groups: list<array{id: string, name: ?string, expanded: bool, builtin: bool}>, assignments: array<string, string>}
	 */
	public function deleteGroup(string $uid, string $groupId): array {
		$state = $this->read($uid);
		$index = $this->indexOf($state['groups'], $groupId);

		if ($index === null) {
			throw new NotFoundException('Group not found');
		}
		if ($state['groups'][$index]['builtin']) {
			throw new ValidationException('This group cannot be deleted');
		}

		array_splice($state['groups'], $index, 1);
		$state['assignments'] = array_filter(
			$state['assignments'],
			static fn (string $assigned): bool => $assigned !== $groupId,
		);

		$this->write($uid, $state);

		return $state;
	}

	/**
	 * Move a team into a group, or out of every group.
	 *
	 * `$groupId === null` means "ungrouped": the row is deleted rather than
	 * pointed at a placeholder, so the stored state only ever holds
	 * deliberate placements and a user who never groups anything never grows
	 * a preference row at all.
	 *
	 * @return array{groups: list<array{id: string, name: ?string, expanded: bool, builtin: bool}>, assignments: array<string, string>}
	 */
	public function assignTeam(string $uid, string $teamId, ?string $groupId): array {
		$state = $this->read($uid);

		if ($teamId === '' || mb_strlen($teamId) > self::MAX_TEAM_ID_LENGTH) {
			throw new ValidationException('Invalid team id');
		}

		if ($groupId === null) {
			unset($state['assignments'][$teamId]);
		} else {
			if ($this->indexOf($state['groups'], $groupId) === null) {
				throw new NotFoundException('Group not found');
			}
			if (!isset($state['assignments'][$teamId])
				&& count($state['assignments']) >= self::MAX_ASSIGNMENTS) {
				throw new ValidationException('You have reached the maximum number of grouped teams');
			}
			$state['assignments'][$teamId] = $groupId;
		}

		$this->write($uid, $state);

		return $state;
	}

	// ------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------

	/**
	 * Read and normalise. Every failure mode here resolves to the defaults
	 * rather than an error: this is sidebar chrome, and a user whose
	 * preference row got corrupted should see their teams as a plain list,
	 * not an app that refuses to render.
	 *
	 * @return array{groups: list<array{id: string, name: ?string, expanded: bool, builtin: bool}>, assignments: array<string, string>}
	 */
	private function read(string $uid): array {
		$raw = $this->config->getUserValue($uid, Application::APP_ID, self::PREF_KEY, '');
		if ($raw === '') {
			return $this->defaults();
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return $this->defaults();
		}

		return $this->normalise($decoded);
	}

	/**
	 * Force any decoded blob into the documented shape.
	 *
	 * Written defensively on purpose. The value is user-writable through
	 * this service's own endpoints, and a shape that reaches the sidebar
	 * unchecked is a shape a future bug can render — so unknown groups are
	 * dropped, the built-in is re-inserted if missing, and every assignment
	 * pointing at a group that no longer exists is discarded.
	 *
	 * @param array<mixed> $decoded
	 * @return array{groups: list<array{id: string, name: ?string, expanded: bool, builtin: bool}>, assignments: array<string, string>}
	 */
	private function normalise(array $decoded): array {
		$groups = [];
		$seen   = [];

		foreach (($decoded['groups'] ?? []) as $group) {
			if (!is_array($group)) {
				continue;
			}
			$id = isset($group['id']) && is_string($group['id']) ? $group['id'] : '';
			// Only ids this service could have issued are accepted back. An
			// `others` id from a pre-release blob fails this test and is
			// dropped, which puts its teams back in the loose list — the
			// intended outcome, not a loss.
			if (!preg_match('/^(favorites|g[0-9a-f]{12})$/', $id) || isset($seen[$id])) {
				continue;
			}
			$seen[$id] = true;

			$builtin = $id === self::GROUP_FAVORITES;
			$name    = null;
			if (!$builtin) {
				if (!isset($group['name']) || !is_string($group['name'])) {
					continue;
				}
				$name = mb_substr(trim($group['name']), 0, self::MAX_NAME_LENGTH);
				if ($name === '') {
					continue;
				}
			}

			$groups[] = [
				'id'       => $id,
				'name'     => $name,
				'expanded' => (bool)($group['expanded'] ?? false),
				'builtin'  => $builtin,
			];

			if (count($groups) >= self::MAX_GROUPS) {
				break;
			}
		}

		// Re-insert the built-in if the stored blob lost it.
		if (!isset($seen[self::GROUP_FAVORITES])) {
			array_unshift($groups, $this->defaults()['groups'][0]);
		}

		$byId        = array_column($groups, null, 'id');
		$assignments = [];
		foreach (($decoded['assignments'] ?? []) as $teamId => $groupId) {
			if (!is_string($teamId) || !is_string($groupId)) {
				continue;
			}
			if ($teamId === '' || mb_strlen($teamId) > self::MAX_TEAM_ID_LENGTH) {
				continue;
			}
			// A team pointing at a group that no longer exists is stored as
			// nothing at all — the implicit rule leaves it loose.
			if (!isset($byId[$groupId])) {
				continue;
			}
			$assignments[$teamId] = $groupId;
			if (count($assignments) >= self::MAX_ASSIGNMENTS) {
				break;
			}
		}

		return ['groups' => $groups, 'assignments' => $assignments];
	}

	/**
	 * The state a user who has never touched this feature gets: one empty,
	 * expanded Favorites group, and every team loose beneath it.
	 *
	 * Favorites ships expanded because an empty collapsed group is invisible
	 * chrome — it is the affordance that shows the feature exists at all.
	 * Only user-created groups start collapsed.
	 *
	 * @return array{groups: list<array{id: string, name: ?string, expanded: bool, builtin: bool}>, assignments: array<string, string>}
	 */
	private function defaults(): array {
		return [
			'groups' => [
				['id' => self::GROUP_FAVORITES, 'name' => null, 'expanded' => true, 'builtin' => true],
			],
			'assignments' => [],
		];
	}

	/**
	 * @param list<array{id: string, name: ?string, expanded: bool, builtin: bool}> $groups
	 */
	private function indexOf(array $groups, string $groupId): ?int {
		foreach ($groups as $i => $group) {
			if ($group['id'] === $groupId) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * A group name is free text the user typed, so it gets the full
	 * treatment: control characters stripped (they render as nothing and
	 * would make two visually identical names non-equal), length bounded,
	 * emptiness refused.
	 *
	 * Note it is *not* HTML-escaped here. Vue escapes on render, and
	 * escaping at the storage boundary would double-encode an ampersand
	 * every time the value round-trips through an edit.
	 */
	private function validateName(string $name): string {
		$name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
		$name = trim($name);

		if ($name === '') {
			throw new ValidationException('Group name cannot be empty');
		}
		if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
			throw new ValidationException('Group name is too long');
		}

		return $name;
	}

	/**
	 * @param array{groups: list<array{id: string, name: ?string, expanded: bool, builtin: bool}>, assignments: array<string, string>} $state
	 */
	private function write(string $uid, array $state): void {
		error_log('[TeamHub][TeamGroupService] Saving groups for ' . $uid . ': ' . json_encode([
			'groups'      => count($state['groups']),
			'assignments' => count($state['assignments']),
		]));

		$this->config->setUserValue(
			$uid,
			Application::APP_ID,
			self::PREF_KEY,
			json_encode($state, JSON_THROW_ON_ERROR),
		);
	}
}
