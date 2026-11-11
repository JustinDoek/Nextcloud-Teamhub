<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\TimezoneService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Builds the one verified context providers are given (v4.13.23).
 *
 * ## Why a factory and not a constructor call at each site
 *
 * Every field of a {@see TeamHubContext} has exactly one correct source, and
 * four of them are easy to get wrong in a way nothing notices: the user id
 * must come from the session and never from request input, the level must come
 * from the same membership query the gates use, and the locale and time zone
 * must be the *viewer's* rather than the instance's. A constructor call per
 * surface is four chances per surface to take a shortcut — and the shortcut
 * that gets taken is reading the user id off a parameter, which is the one
 * that matters.
 *
 * So there is one builder, it reads the session itself, and a caller cannot
 * pass it a user id at all.
 *
 * ## The caller still owns authorisation
 *
 * This builds a context; it does not grant anything. `forTeam()` records what
 * the membership query returned, including level 0 for somebody who is not a
 * member. The surface calling a provider has already run its own gate — a
 * controller's `requireMemberLevel()`, or the effective-state service — and
 * the context is the *result* of that, not a substitute for it.
 */
class TeamHubContextFactory {

    /** Fallback locale when the viewer has expressed no preference. */
    private const DEFAULT_LOCALE = 'en';

    public function __construct(
        private IUserSession    $userSession,
        private IConfig         $config,
        private TimezoneService $timezoneService,
        private MemberService   $memberService,
        private IDBConnection   $db,
        private LoggerInterface $logger,
    ) {}

    /**
     * A context for the current session's user, about one team.
     *
     * The team id is the caller's — a verified route parameter — and the user
     * id is the session's. There is deliberately no parameter for the user: a
     * surface that wanted to name one would be doing the thing this class
     * exists to prevent.
     *
     * @throws \RuntimeException when there is no authenticated session. A
     *         provider must never be called without one, and returning a
     *         context with an empty user id would hand it a caller it cannot
     *         authorise against.
     */
    public function forTeam(
        string $teamId,
        string $appId,
        string $integrationKey,
        string $capabilityKey,
        array  $teamConfig = [],
    ): TeamHubContext {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \RuntimeException('Cannot build a TeamHub provider context without an authenticated session.');
        }
        $userId = $user->getUID();

        return new TeamHubContext(
            teamId:         $teamId,
            userId:         $userId,
            level:          $this->levelOf($teamId, $userId),
            locale:         $this->localeOf($userId),
            timezone:       $this->timezoneOf($userId),
            appId:          $appId,
            integrationKey: $integrationKey,
            capabilityKey:  $capabilityKey,
            teamConfig:     $teamConfig,
        );
    }

    /**
     * A context for a named actor, about a team they may not be a member of.
     *
     * For lifecycle hooks fired from an administrative action — a template
     * reconciliation reaches every team of a template, and the administrator
     * who triggered it is typically in none of them. The level is whatever the
     * membership query returns, which is 0 for a non-member, and **a lifecycle
     * context is not a membership assertion**: a provider receiving one is
     * being told that something happened to a team, not that this person may
     * read it.
     */
    public function forActor(
        string $teamId,
        string $actorUid,
        string $appId,
        string $integrationKey,
        string $capabilityKey,
        array  $teamConfig = [],
    ): TeamHubContext {
        return new TeamHubContext(
            teamId:         $teamId,
            userId:         $actorUid,
            level:          $this->levelOf($teamId, $actorUid),
            locale:         $this->localeOf($actorUid),
            timezone:       $this->timezoneOf($actorUid),
            appId:          $appId,
            integrationKey: $integrationKey,
            capabilityKey:  $capabilityKey,
            teamConfig:     $teamConfig,
        );
    }

    /**
     * The viewer's Circles level in the team.
     *
     * Reads the same indexed query `MemberService`'s gates read, so a
     * provider's idea of "is this person a team admin" is the app's idea of it.
     * A failure reads as level 0: a context that cannot establish a level must
     * not claim one.
     */
    private function levelOf(string $teamId, string $userId): int {
        try {
            return $this->memberService->getMemberLevelFromDb($this->db, $teamId, $userId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][Integration] Could not resolve a member level for a provider context', [
                'team_id' => $teamId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);

            return 0;
        }
    }

    private function localeOf(string $userId): string {
        try {
            $lang = (string)$this->config->getUserValue($userId, 'core', 'lang', '');

            return $lang !== '' ? $lang : self::DEFAULT_LOCALE;
        } catch (\Throwable) {
            return self::DEFAULT_LOCALE;
        }
    }

    private function timezoneOf(string $userId): string {
        try {
            return $this->timezoneService->forUser($userId)->getName();
        } catch (\Throwable) {
            // UTC rather than the server's zone: a provider formatting a date
            // in the wrong zone should be wrong in a way that is obvious and
            // uniform, not wrong in a way that depends on where the server is.
            return 'UTC';
        }
    }
}
