<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCP\Calendar\IManager as CalendarManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Read-only bridge to Nextcloud's native availability / FreeBusy calculation.
 * Nextcloud combines the user's VAVAILABILITY working hours, absences and
 * calendar busy periods. TeamHub does not duplicate working-hour settings.
 *
 * A null verdict means the attendee cannot be evaluated (missing email,
 * calendar error, or no result); callers must NEVER interpret it as free.
 * Call with short, actual meeting windows, not whole half-days: a user who
 * works 08:00–16:30 is correctly available 15:00–16:00 but not 16:00–17:00.
 */
class NextcloudAvailabilityService {
    /** @var array<string, array<string, bool|null>> */
    private array $cache = [];

    public function __construct(
        private CalendarManager $calendarManager,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<string> $userIds Local Nextcloud IDs; caller must verify team membership.
     * @return array<string, bool|null> map of uid => true/false/null (unknown)
     */
    public function checkMany(string $organiserUid, array $userIds, int $startTs, int $endTs): array {
        if ($endTs <= $startTs || $endTs - $startTs > 86400) {
            throw new \InvalidArgumentException('Invalid availability time window');
        }
        $userIds = array_values(array_unique($userIds));
        $cacheKey = $organiserUid . '|' . $startTs . '|' . $endTs . '|' . implode('|', $userIds);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        $out = array_fill_keys($userIds, null);
        $organiser = $this->userManager->get($organiserUid);
        if ($organiser === null) {
            return $out;
        }
        $emails = [];
        $byEmail = [];
        foreach ($userIds as $uid) {
            $user = $this->userManager->get($uid);
            $email = $user?->getEMailAddress();
            if (!is_string($email) || trim($email) === '') {
                continue;
            }
            $key = strtolower(trim($email));
            $byEmail[$key][] = $uid;
            $emails[] = $email;
        }
        if ($emails === []) {
            return $this->cache[$cacheKey] = $out;
        }
        try {
            $results = $this->calendarManager->checkAvailability(
                new \DateTimeImmutable('@' . $startTs),
                new \DateTimeImmutable('@' . $endTs),
                $organiser,
                array_values(array_unique($emails)),
            );
            foreach ($results as $result) {
                $email = strtolower(preg_replace('/^mailto:/i', '', trim($result->getAttendeeEmail())));
                foreach ($byEmail[$email] ?? [] as $uid) {
                    $out[$uid] = $result->isAvailable();
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub] Native availability check failed', ['exception' => $e]);
        }
        return $this->cache[$cacheKey] = $out;
    }
}
