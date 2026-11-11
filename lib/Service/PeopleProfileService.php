<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * The authenticated user's optional collaboration profile. This service never
 * exposes another user's profile; later people-directory features must add
 * explicit, field-level disclosure rules before reading any shared fields.
 *
 * Existing Presence data and location identifiers remain unchanged. No new DB
 * table/migration is required: profiles are stored as a single per-user JSON
 * preference using Nextcloud's IConfig API.
 */
class PeopleProfileService {
    // Do not change this key during releases: existing user's saved values must
    // remain readable after every in-place Nextcloud app update.
    private const KEY = 'people_collaboration_profile_v1';

    private const DEFAULTS = [
        'departmentTeamId' => '',
        'roomId' => null,
        'expertise' => '',
        'askMeAbout' => '',
        'hobbies' => '',
        'languages' => '',
        'funFact' => '',
        'contactMethods' => [],
        'companyStartDate' => '',
        'departmentStartDate' => '',
        'shareBirthday' => true,
        'shareAnniversary' => true,
        'shareHobbies' => true,
    ];

    private const CONTACT_METHODS = ['chat', 'email', 'phone', 'in_person'];

    private const MAX_LENGTH = [
        'expertise' => 500,
        'askMeAbout' => 500,
        'hobbies' => 500,
        'languages' => 200,
        'funFact' => 300,
    ];

    public function __construct(
        private IConfig $config,
        private IDBConnection $db,
        private TeamService $teams,
        private TeamTypeService $teamTypes,
        private PresenceLocationService $locations,
        private IUserManager $users,
    ) {}

    /**
     * Only departments visible through TeamService::getUserTeams() are eligible.
     * This includes Nextcloud indirect group membership and excludes teams
     * pending deletion. Never trust a submitted team or room id from the client.
     *
     * @return array{profile: array<string,mixed>, departments: list<array<string,string>>, locations: array}
     */
    public function get(string $userId, string $selectedDepartment = ''): array {
        $departments = $this->departments();
        $locations = $this->locations->getBuildings();
        $saved = $this->read($userId);
        $profile = array_replace(self::DEFAULTS, $saved);
        // Old free-text contact preferences are translated to supported choices
        // for display, but no unknown free text is promoted to a checkbox.
        $profile['contactMethods'] = $this->contactMethods($saved);
        // These dates are source-controlled, not self-reported. Deliberately
        // ignore dates saved in the previous People profile version.
        $profile['companyStartDate'] = $this->accountCreationDate($userId);
        $allowed = array_column($departments, 'id');
        if (!in_array($profile['departmentTeamId'], $allowed, true)) {
            $profile['departmentTeamId'] = '';
        }
        if ($selectedDepartment !== '' && in_array($selectedDepartment, $allowed, true)) {
            $profile['departmentTeamId'] = $selectedDepartment;
        }
        $profile['roomId'] = $this->validRoomId($locations, $profile['roomId']);
        // The actual Circles join time is the only source for department tenure.
        // An indirect group member without a direct join timestamp has no
        // reliable date; show 'Not available' rather than guessing.
        $profile['departmentStartDate'] = $profile['departmentTeamId'] !== ''
            ? $this->departmentJoinedDate($userId, $profile['departmentTeamId']) : '';
        unset($profile['departmentStartDates'], $profile['contactPreference']);
        return ['profile' => $profile, 'departments' => $departments, 'locations' => $locations];
    }

    /**
     * Validate the *entire* proposed profile before writing any preference.
     * Unknown fields are ignored, and allowed team / room ids are re-checked
     * for the authenticated user on every write.
     *
     * @param array<string,mixed> $input
     * @return array{profile: array<string,mixed>, departments: list<array<string,string>>, locations: array}
     */
    public function save(string $userId, array $input): array {
        $departments = $this->departments();
        $allowed = array_column($departments, 'id');
        $locations = $this->locations->getBuildings();
        $old = $this->read($userId);
        $new = array_replace(self::DEFAULTS, $old);

        $department = $input['departmentTeamId'] ?? '';
        if (!is_string($department) || ($department !== '' && !in_array($department, $allowed, true))) {
            throw new \InvalidArgumentException('Choose a department team you belong to.');
        }
        $new['departmentTeamId'] = $department;

        $roomId = $input['roomId'] ?? null;
        if ($roomId === '') {
            $roomId = null;
        }
        if ($roomId !== null && (!is_int($roomId) || $roomId <= 0 || $this->validRoomId($locations, $roomId) === null)) {
            throw new \InvalidArgumentException('Choose an existing room.');
        }
        $new['roomId'] = $roomId;

        foreach (self::MAX_LENGTH as $field => $limit) {
            $value = $input[$field] ?? '';
            if (!is_string($value) || (function_exists('mb_strlen') ? mb_strlen(trim($value)) : strlen(trim($value))) > $limit) {
                throw new \InvalidArgumentException("Invalid {$field} (maximum {$limit} characters).");
            }
            $new[$field] = trim($value);
        }
        $methods = $input['contactMethods'] ?? $this->contactMethods($old);
        if (!is_array($methods) || !array_is_list($methods) || count($methods) > count(self::CONTACT_METHODS)) {
            throw new \InvalidArgumentException('Choose valid contact methods.');
        }
        foreach ($methods as $method) {
            if (!is_string($method) || !in_array($method, self::CONTACT_METHODS, true)) {
                throw new \InvalidArgumentException('Choose valid contact methods.');
            }
        }
        $new['contactMethods'] = array_values(array_unique($methods));
        // Prevent spoofing read-only dates, including profiles written by v4.13.41.
        unset($new['companyStartDate'], $new['departmentStartDate'],
            $new['departmentStartDates'], $new['contactPreference']);
        foreach (['shareBirthday', 'shareAnniversary', 'shareHobbies'] as $field) {
            if (!is_bool($input[$field] ?? ($old[$field] ?? true))) {
                throw new \InvalidArgumentException("{$field} must be a boolean.");
            }
            $new[$field] = $input[$field] ?? ($old[$field] ?? true);
        }
        $this->config->setUserValue($userId, Application::APP_ID, self::KEY, json_encode($new, JSON_THROW_ON_ERROR));
        return $this->get($userId);
    }

    /** @return array<string,mixed> */
    private function read(string $userId): array {
        $raw = $this->config->getUserValue($userId, Application::APP_ID, self::KEY, '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<array{id:string,name:string}> */
    private function departments(): array {
        $teams = $this->teams->getUserTeams();
        $ids = array_values(array_filter(array_map(static fn(array $team): string => (string)($team['id'] ?? ''), $teams)));
        if ($ids === []) {
            return [];
        }
        $types = $this->teamTypes->getTypesForTeams($ids);
        $result = [];
        foreach ($teams as $team) {
            $id = (string)($team['id'] ?? '');
            if (($types[$id] ?? '') === 'department') {
                $result[] = ['id' => $id, 'name' => (string)($team['name'] ?? $id)];
            }
        }
        usort($result, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $result;
    }

    private function validRoomId(array $tree, mixed $roomId): ?int {
        if (!is_int($roomId) || $roomId <= 0) {
            return null;
        }
        foreach ($tree as $building) {
            foreach ($building['floors'] ?? [] as $floor) {
                foreach ($floor['rooms'] ?? [] as $room) {
                    if ((int)($room['id'] ?? 0) === $roomId) {
                        return $roomId;
                    }
                }
            }
        }
        return null;
    }

    /** @return list<string> */
    private function contactMethods(array $saved): array {
        if (isset($saved['contactMethods']) && is_array($saved['contactMethods'])) {
            return array_values(array_unique(array_filter($saved['contactMethods'],
                static fn($item): bool => is_string($item) && in_array($item, self::CONTACT_METHODS, true))));
        }
        // Backward compatibility with the earlier free-text contact field.
        $legacy = strtolower(trim((string)($saved['contactPreference'] ?? '')));
        if ($legacy === '') {
            return [];
        }
        $result = [];
        foreach (['chat' => ['chat', 'talk', 'message'], 'email' => ['email', 'e-mail', 'mail'],
                  'phone' => ['phone', 'call', 'telephone'], 'in_person' => ['person', 'face', 'office']] as $method => $terms) {
            foreach ($terms as $term) {
                if (str_contains($legacy, $term)) {
                    $result[] = $method;
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * Nextcloud has no universally available account-creation timestamp.
     * Prefer a real creation event captured by TeamHub; for older accounts use
     * IUser::getFirstLogin() (supported since Nextcloud 31). 0 means never
     * logged in and -1 means unknown: neither is a date. Never use last login.
     */
    private function accountCreationDate(string $userId): string {
        $raw = $this->config->getUserValue($userId, Application::APP_ID, 'people_account_created_at', '');
        if (ctype_digit($raw) && (int)$raw > 0 && (int)$raw <= time()) {
            return gmdate('Y-m-d', (int)$raw);
        }
        $user = $this->users->get($userId);
        $firstLogin = $user?->getFirstLogin() ?? 0;
        return $firstLogin > 0 && $firstLogin <= time() ? gmdate('Y-m-d', $firstLogin) : '';
    }

    /** Direct membership only; indirect group membership has no individual joined date. */
    private function departmentJoinedDate(string $userId, string $departmentId): string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('joined')
            ->from('circles_member')
            ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($departmentId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        $timestamp = \OCA\TeamHub\Service\MemberService::joinedToEpoch($row['joined'] ?? null);
        return $timestamp > 0 && $timestamp <= time() ? gmdate('Y-m-d', $timestamp) : '';
    }
}
