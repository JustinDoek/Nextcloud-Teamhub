<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\DashboardWidgets;
use OCP\Accounts\IAccountManager;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Profile\IProfileManager;
use Psr\Log\LoggerInterface;

/** Department birthdays. Never returns a birth year or a private property. */
class BirthdaysService {
    private const PROFILE_KEY = 'people_collaboration_profile_v1';
    private const SEEN_KEY = 'people_birthday_celebrated_year';

    public function __construct(
        private IConfig $config,
        private IAccountManager $accounts,
        private IProfileManager $profileVisibility,
        private IUserManager $users,
        private MemberService $members,
        private TeamTypeService $types,
        private TeamService $teams,
        private PresenceTeamService $presenceTeams,
        private IUserSession $session,
        private LoggerInterface $logger,
    ) {}

    /** @return array{birthdays:list<array{userId:string,displayName:string,monthDay:string,today:bool}>} */
    public function upcoming(string $teamId): array {
        // Membership is checked even for empty/non-department teams to avoid a
        // cross-team enumeration channel. All member types are included.
        $this->members->requireMemberLevel($teamId);
        if (!$this->isEnabledDepartment($teamId)) {
            return ['birthdays' => [], 'unavailable' => true];
        }
        $today = $this->today($this->session->getUser()?->getUID() ?? '');
        $rows = [];
        $eligible = 0;
        foreach ($this->members->getEffectiveMemberIds($teamId) as $uid) {
            $candidate = $this->permittedBirthday($uid);
            if ($candidate === null) {
                continue;
            }
            $eligible++;
            $next = self::nextOccurrence($candidate['monthDay'], $today);
            if ($next === null) {
                continue;
            }
            $rows[] = [
                'userId' => $uid,
                'displayName' => $candidate['displayName'],
                'monthDay' => $candidate['monthDay'],
                'today' => $next->format('Y-m-d') === $today->format('Y-m-d'),
                '_sort' => $next->format('Y-m-d'),
            ];
        }
        // Aggregate counts only. Never log names, dates or individual consent decisions.
        $this->logger->debug('[TeamHub][Birthdays] Department widget eligibility', [
            'teamId' => $teamId,
            'eligibleCount' => $eligible,
        ]);
        usort($rows, static fn(array $a, array $b): int =>
            strcmp($a['_sort'], $b['_sort']) ?: strcasecmp($a['displayName'], $b['displayName']));
        $rows = array_slice($rows, 0, 3);
        foreach ($rows as &$row) {
            unset($row['_sort']);
        }
        unset($row);
        return ['birthdays' => $rows];
    }

    /** @return array{celebrate:bool,displayName?:string} */
    public function myCelebration(string $userId): array {
        $birthday = $this->permittedBirthday($userId, true);
        if ($birthday === null) {
            return ['celebrate' => false];
        }
        $today = $this->today($userId);
        $date = self::nextOccurrence($birthday['monthDay'], $today);
        if ($date === null || $date->format('Y-m-d') !== $today->format('Y-m-d')) {
            return ['celebrate' => false];
        }
        $year = $today->format('Y');
        if ($this->config->getUserValue($userId, Application::APP_ID, self::SEEN_KEY, '') === $year) {
            return ['celebrate' => false];
        }
        // A user can belong to multiple departments. Show one celebration,
        // only if at least one eligible department has its widget enabled.
        $teams = $this->teams->getUserTeams();
        foreach ($teams as $team) {
            $teamId = (string)($team['id'] ?? '');
            if ($teamId !== '' && $this->isEnabledDepartment($teamId)) {
                return ['celebrate' => true, 'displayName' => $birthday['displayName']];
            }
        }
        return ['celebrate' => false];
    }

    public function acknowledge(string $userId): void {
        // Never mark another user's celebration, or a celebration which no
        // longer qualifies because the user withdrew consent.
        if ($this->myCelebration($userId)['celebrate']) {
            $this->config->setUserValue($userId, Application::APP_ID, self::SEEN_KEY, $this->today($userId)->format('Y'));
        }
    }

    private function isEnabledDepartment(string $teamId): bool {
        if ($this->types->getType($teamId) !== 'department'
            || $this->config->getAppValue(Application::APP_ID, 'presence_module_enabled', '1') !== '1'
            || !$this->presenceTeams->getConfig($teamId)['presence_enabled']) {
            return false;
        }
        $hidden = DashboardWidgets::hiddenFrom(
            $this->config->getAppValue(Application::APP_ID, 'dashboard_hidden_' . $teamId, '')
        );
        return !in_array('widget-birthdays', $hidden, true);
    }

    /** Use the Nextcloud user's configured timezone, with a safe UTC fallback. */
    private function today(string $userId): \DateTimeImmutable {
        $timezone = $userId !== ''
            ? $this->config->getUserValue($userId, 'core', 'timezone', 'UTC')
            : 'UTC';
        try {
            $zone = new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            $zone = new \DateTimeZone('UTC');
        }
        return new \DateTimeImmutable('today', $zone);
    }

    /**
     * Nextcloud normally stores a YYYY-MM-DD date, but some backends include
     * an ISO timestamp. Parse the *calendar* date, never convert to a local
     * timezone: that could turn a birthday on the 1st into the 31st.
     */
    public static function monthDayFromNextcloud(string $value): ?string {
        $value = trim($value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:(?:T| )(?:\d{2}:\d{2})(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?$/D', $value, $m)) {
            return null;
        }
        $year = (int)$m[1];
        $month = (int)$m[2];
        $day = (int)$m[3];
        if ($year < 1 || !checkdate($month, $day, $year)) {
            return null;
        }
        return sprintf('%02d-%02d', $month, $day);
    }

    /** @return array{monthDay:string,displayName:string}|null */
    private function permittedBirthday(string $uid, bool $ownCelebration = false): ?array {
        $raw = $this->config->getUserValue($uid, Application::APP_ID, self::PROFILE_KEY, '');
        $profile = json_decode($raw, true);
        if (is_array($profile) && ($profile['shareBirthday'] ?? true) !== true) {
            return null;
        }
        $user = $this->users->get($uid);
        if ($user === null || !$user->isEnabled()) {
            return null;
        }
        try {
            $property = $this->accounts->getAccount($user)->getProperty(IAccountManager::PROPERTY_BIRTHDATE);
            // Nextcloud's profile visibility API covers a defined set of *profile
            // fields*. Birthday is an account property but is not a native profile
            // display field on all supported versions. Calling the profile manager
            // for an unsupported key can throw and silently remove every birthday.
            // Always enforce the account scope and the person's explicit TeamHub
            // sharing preference. Respect additional profile visibility whenever
            // this key is supported by the installed Nextcloud version.
            if (!$ownCelebration && $property->getScope() === IAccountManager::SCOPE_PRIVATE) {
                return null;
            }
            if (!$ownCelebration && array_key_exists(IAccountManager::PROPERTY_BIRTHDATE, IProfileManager::DEFAULT_PROPERTY_VISIBILITY)
                && !$this->profileVisibility->isProfileFieldVisible(
                    IAccountManager::PROPERTY_BIRTHDATE,
                    $user,
                    $this->session->getUser(),
                )) {
                return null;
            }
            $rawDate = (string)$property->getValue();
        } catch (\Throwable $e) {
            return null;
        }
        $monthDay = self::monthDayFromNextcloud($rawDate);
        if ($monthDay === null) {
            return null;
        }
        return ['monthDay' => $monthDay, 'displayName' => $user->getDisplayName() ?: $uid];
    }

    /**
     * Next birthday on/after today. On non-leap years a 29 February birthday
     * is celebrated on 1 March. Only the recurring month/day leaves this class.
     */
    public static function nextOccurrence(string $monthDay, \DateTimeImmutable $today): ?\DateTimeImmutable {
        if (!preg_match('/^(\d{2})-(\d{2})$/D', $monthDay, $match)) {
            return null;
        }
        $month = (int)$match[1];
        $day = (int)$match[2];
        if (!checkdate($month, $day, 2000)) {
            return null;
        }
        for ($year = (int)$today->format('Y'); $year <= (int)$today->format('Y') + 1; $year++) {
            $m = $month;
            $d = $day;
            if ($month === 2 && $day === 29 && !checkdate(2, 29, $year)) {
                $m = 3;
                $d = 1;
            }
            $date = $today->setDate($year, $m, $d)->setTime(0, 0);
            if ($date >= $today->setTime(0, 0)) {
                return $date;
            }
        }
        return null;
    }

}
