<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;

/**
 * The optional People collaboration profile a user chose to publish.
 *
 * ## The gate is the People module, and nothing else (Justin, 2026-10-30)
 *
 * Until v4.13.51 a viewer had to share a **Department-template team with People
 * enabled** with the person whose profile they were reading. That made the
 * feature invisible on every upgraded instance, because the v4.13.41 migration
 * writes `presence_enabled = 0` for every team that already existed -- so the
 * gate was closed everywhere until an administrator opened it team by team,
 * and nothing on the page could say why.
 *
 * A collaboration profile is a property of a **person**, not of a team: it is
 * what somebody published about themselves so colleagues can find them. The
 * instance-wide People switch is the decision that matters, and it is made in
 * one place by an administrator.
 *
 * **A signed-in viewer is still required.** A Nextcloud profile page can be
 * made public, and these are details for colleagues, not for the internet.
 * Each field remains governed by its own sharing switch on the owner's profile.
 */
class PeopleDirectoryService {
    private const PROFILE_KEY = 'people_collaboration_profile_v1';

    /** Department display names already resolved, keyed by team id. @var array<string, string> */
    private array $departmentNames = [];

    public function __construct(
        private IUserSession $session,
        private IUserManager $users,
        private IConfig $config,
        private IDBConnection $db,
        private TeamTypeService $types,
        private PresenceLocationService $locations,
        private IFactory $l10nFactory,
    ) {}

    /** @return array<string, mixed>|null Null when access is denied. */
    public function profile(string $targetId): ?array {
        return $this->profiles([$targetId])[$targetId] ?? null;
    }

    /**
     * The same answer as `profile()`, for several people in one pass (v4.13.63).
     *
     * **This exists so the unified-search provider can reuse this gate rather
     * than reimplement it.** `PeopleProfileService`'s docblock requires that
     * any people-directory feature go through explicit field-level disclosure
     * rules before reading a shared field, and those rules are here -- the
     * module switch, a signed-in viewer, an enabled account, and
     * `shareHobbies`. A provider that looked at the stored JSON itself would
     * be a second gate to keep in step, which is the drift §2.219 removed.
     *
     * **Why batched rather than a loop over `profile()`.** Two of the lookups
     * are per-call rather than per-person: `PresenceLocationService::getBuildings()`
     * triggers the room-backend sync (v4.13.53, deliberately, on the read that
     * displays the tree), and a department name is one query per distinct team.
     * Called once per candidate from a search provider, that would fire the
     * sync on every keystroke. The tree is resolved once here and the
     * department names are memoised for the call.
     *
     * Order of the result follows `$targetIds`. A denied or unknown account is
     * **absent** from the map rather than present with a null -- the caller
     * iterates what it got.
     *
     * @param list<string> $targetIds
     * @return array<string, array<string, mixed>>
     */
    public function profiles(array $targetIds): array {
        $viewer = $this->session->getUser();
        if ($viewer === null) {
            return [];
        }
        if ($this->config->getAppValue(Application::APP_ID, 'presence_module_enabled', '1') !== '1') {
            return [];
        }

        $l    = $this->l10nFactory->get(Application::APP_ID);
        $tree = $this->locations->getBuildings();
        $out  = [];

        foreach (array_unique($targetIds) as $targetId) {
            $target = $this->users->get($targetId);
            if ($target === null || !$target->isEnabled()) {
                continue;
            }
            $out[$targetId] = $this->assemble($targetId, $target->getDisplayName(), $viewer->getUID(), $tree, $l);
        }

        return $out;
    }

    /**
     * One person's published fields. Every disclosure rule that is not about
     * the viewer's session lives here.
     *
     * @param array<int, array<string, mixed>> $tree The room hierarchy, resolved once by the caller.
     * @return array<string, mixed>
     */
    private function assemble(string $targetId, string $displayName, string $viewerId, array $tree, \OCP\IL10N $l): array {
        $own = $viewerId === $targetId;

        $stored = json_decode($this->config->getUserValue($targetId, Application::APP_ID, self::PROFILE_KEY, ''), true);
        $stored = is_array($stored) ? $stored : [];

        $workplace = $this->workplaceLabel($tree, $stored['roomId'] ?? null);

        $methods = is_array($stored['contactMethods'] ?? null) ? $stored['contactMethods'] : [];
        $validMethods = [
            'chat'      => $l->t('Chat'),
            'email'     => $l->t('Email'),
            'phone'     => $l->t('Phone'),
            'in_person' => $l->t('In person'),
        ];
        $methods = array_values(array_intersect_key($validMethods, array_fill_keys(array_filter($methods, 'is_string'), true)));

        $shareHobbies = ($stored['shareHobbies'] ?? true) === true;

        // A list of label/value pairs, translated here. The two readers (the
        // standalone page and the section on the Nextcloud profile) then render
        // what they are given instead of each keeping a copy of the labels --
        // the shape that let the old assoc-array version drift.
        $fields = [
            [$l->t('Department'),            $this->departmentName((string)($stored['departmentTeamId'] ?? ''))],
            [$l->t('Usual workplace'),       $workplace],
            [$l->t('Expertise'),             (string)($stored['expertise'] ?? '')],
            [$l->t('Ask me about'),          (string)($stored['askMeAbout'] ?? '')],
            [$l->t('Preferred contact'),     implode(', ', $methods)],
            [$l->t('Languages'),             (string)($stored['languages'] ?? '')],
            [$l->t('Hobbies and interests'), $shareHobbies ? (string)($stored['hobbies'] ?? '') : ''],
            [$l->t('Fun fact'),              $shareHobbies ? (string)($stored['funFact'] ?? '') : ''],
        ];

        $out = [];
        foreach ($fields as [$label, $value]) {
            if (trim($value) !== '') {
                $out[] = ['label' => $label, 'value' => $value];
            }
        }

        // Do not publish date-of-birth, account creation, or raw employment
        // timestamps. Nextcloud manages birthday visibility independently.
        return [
            'displayName'  => $displayName ?: $targetId,
            'isOwnProfile' => $own,
            'sectionTitle' => $l->t('Collaboration profile'),
            'fields'       => $out,
        ];
    }

    /**
     * "Building · Floor · Room" for a published room id, or an empty string.
     *
     * Takes the tree rather than fetching it, because fetching it syncs.
     *
     * @param array<int, array<string, mixed>> $tree
     */
    private function workplaceLabel(array $tree, mixed $roomId): string {
        if ($roomId === null) {
            return '';
        }
        foreach ($tree as $building) {
            foreach ($building['floors'] ?? [] as $floor) {
                foreach ($floor['rooms'] ?? [] as $room) {
                    if ((int)($room['id'] ?? 0) === (int)$roomId) {
                        return implode(' · ', array_filter([
                            (string)($building['name'] ?? ''),
                            (string)($floor['name'] ?? ''),
                            (string)($room['name'] ?? ''),
                        ]));
                    }
                }
            }
        }
        return '';
    }

    /**
     * The name of the department the user published, resolved without asking
     * anything about the viewer.
     *
     * The name used to be read out of the viewer's own shared-team list, which
     * is exactly what could not survive dropping the team gate: with no shared
     * team there was no name, so the field would have gone blank for everybody.
     *
     * Still checked against the template, so a stale or hand-edited id cannot
     * surface the name of some unrelated team. One indexed read rather than
     * `TeamService`, the same six lines and the same reason as
     * `FileReviewService::getTeamName()`.
     */
    private function departmentName(string $teamId): string {
        if ($teamId === '' || $this->types->getType($teamId) !== 'department') {
            return '';
        }
        // Memoised for the life of the call: a search answers for many people
        // at once and a department is shared by most of them.
        if (isset($this->departmentNames[$teamId])) {
            return $this->departmentNames[$teamId];
        }
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('display_name')
                ->from('circles_circle')
                ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
                ->setMaxResults(1);
            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            return $this->departmentNames[$teamId] = $row ? (string)$row['display_name'] : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
