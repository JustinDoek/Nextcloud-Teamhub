<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\CirclesMemberType;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Personal Department membership milestones. An account login date is not a
 * department joining date, so only the actual recorded direct membership is
 * eligible. No employment length is inferred from the Nextcloud account.
 */
class AnniversaryService {
    private const PROFILE_KEY = 'people_collaboration_profile_v1';
    private const SEEN_KEY = 'people_anniversary_seen_v1';
    public const MILESTONES = [1, 5, 10, 25];

    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
        private IUserManager $users,
        private TeamService $teams,
        private TeamTypeService $types,
        private PresenceTeamService $presenceTeams,
    ) {}

    /** @return array{celebrations:list<array{teamId:string,teamName:string,displayName:string,years:int}>} */
    public function celebrations(string $userId): array {
        if (!$this->allowedToCelebrate($userId)) {
            return ['celebrations' => []];
        }
        $today = $this->today($userId);
        $seen = $this->seen($userId);
        $result = [];
        // getUserTeams already scopes to effective membership and excludes
        // inaccessible/removed teams. Never accept an arbitrary teamId here.
        foreach ($this->teams->getUserTeams() as $team) {
            $id = (string)($team['id'] ?? '');
            if ($id === '' || !$this->enabledForDepartment($id)) {
                continue;
            }
            $date = $this->directJoinDate($userId, $id);
            if ($date === null) {
                continue;
            }
            $years = self::milestoneOn($date, $today);
            if ($years === null || isset($seen[$this->seenToken($id, $years, $today)])) {
                continue;
            }
            $result[] = [
                'teamId' => $id,
                'teamName' => (string)($team['name'] ?? $id),
                'displayName' => $this->users->get($userId)?->getDisplayName() ?: $userId,
                'years' => $years,
            ];
        }
        return ['celebrations' => $result];
    }

    /** Acknowledge only a currently eligible milestone, never a forged team. */
    public function acknowledge(string $userId, string $teamId, int $years): bool {
        foreach ($this->celebrations($userId)['celebrations'] as $celebration) {
            if ($celebration['teamId'] === $teamId && $celebration['years'] === $years) {
                $seen = $this->seen($userId);
                $seen[$this->seenToken($teamId, $years, $this->today($userId))] = true;
                // Bound storage; an employee with many departments should not
                // accumulate unlimited app-config values over decades.
                if (count($seen) > 100) {
                    $seen = array_slice($seen, -100, null, true);
                }
                $this->config->setUserValue($userId, Application::APP_ID, self::SEEN_KEY,
                    json_encode($seen, JSON_THROW_ON_ERROR));
                return true;
            }
        }
        return false;
    }

    /**
     * Returns a milestone exactly on the annual calendar date (including the
     * 29 February → 1 March convention in non-leap years). Must not celebrate
     * a user's first day as their first year.
     */
    public static function milestoneOn(\DateTimeImmutable $joined, \DateTimeImmutable $today): ?int {
        $years = (int)$today->format('Y') - (int)$joined->format('Y');
        if (!in_array($years, self::MILESTONES, true) || $joined > $today) {
            return null;
        }
        $month = (int)$joined->format('m');
        $day = (int)$joined->format('d');
        if ($month === 2 && $day === 29 && !checkdate(2, 29, (int)$today->format('Y'))) {
            $month = 3;
            $day = 1;
        }
        return (int)$today->format('m') === $month && (int)$today->format('d') === $day ? $years : null;
    }

    private function enabledForDepartment(string $teamId): bool {
        return $this->types->getType($teamId) === 'department'
            && $this->config->getAppValue(Application::APP_ID, 'presence_module_enabled', '1') === '1'
            && $this->presenceTeams->getConfig($teamId)['presence_enabled']
            && $this->config->getAppValue(Application::APP_ID, 'people_anniversary_enabled_' . $teamId, '1') === '1';
    }

    private function allowedToCelebrate(string $userId): bool {
        $user = $this->users->get($userId);
        if ($user === null || !$user->isEnabled()) {
            return false;
        }
        $stored = $this->config->getUserValue($userId, Application::APP_ID, self::PROFILE_KEY, '');
        $profile = json_decode($stored, true);
        return !is_array($profile) || ($profile['shareAnniversary'] ?? true) === true;
    }

    private function directJoinDate(string $userId, string $teamId): ?\DateTimeImmutable {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('joined', 'instance')->from('circles_member')
            ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('Member')))
            ->setMaxResults(1)->executeQuery();
        $row = $result->fetchAssociative();
        $result->closeCursor();
        if (!$row || !CirclesMemberType::isLocalInstance((string)($row['instance'] ?? ''))) {
            return null;
        }
        $timestamp = MemberService::joinedToEpoch($row['joined'] ?? null);
        if ($timestamp <= 0 || $timestamp > time()) {
            return null;
        }
        // Join dates are interpreted as calendar dates in UTC, the same
        // convention as the read-only department date in the People profile.
        return new \DateTimeImmutable(gmdate('Y-m-d', $timestamp), new \DateTimeZone('UTC'));
    }

    private function today(string $userId): \DateTimeImmutable {
        $zone = $this->config->getUserValue($userId, 'core', 'timezone', 'UTC');
        try {
            $timezone = new \DateTimeZone($zone);
        } catch (\Throwable $e) {
            $timezone = new \DateTimeZone('UTC');
        }
        return new \DateTimeImmutable('today', $timezone);
    }

    /** @return array<string,bool> */
    private function seen(string $userId): array {
        $stored = json_decode($this->config->getUserValue($userId, Application::APP_ID, self::SEEN_KEY, '{}'), true);
        return is_array($stored) ? array_filter($stored, static fn($v): bool => $v === true) : [];
    }

    private function seenToken(string $teamId, int $years, \DateTimeImmutable $today): string {
        // Hash avoids storing arbitrary team identifiers as JSON object keys.
        return hash('sha256', $teamId . ':' . $today->format('Y') . ':' . $years);
    }
}
