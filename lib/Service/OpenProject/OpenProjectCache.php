<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject;

use OCA\TeamHub\AppInfo\Application;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;

/**
 * Conservative caching for OpenProject reads (v4.9.3).
 *
 * ## The rule that shapes every key
 *
 * OpenProject answers as the user who asked. Two members of the same team can
 * see different work packages, different counts, even a different project
 * name if one of them lost access. So a cached answer belongs to one user,
 * one team, one project and one host, and the key says all four. Nothing
 * here is ever shared between users — not the overview, not the counts, not
 * the milestone — because there is no case in which two users are guaranteed
 * to be authorised identically.
 *
 * ## Invalidation without wildcards
 *
 * Nextcloud's cache has no "delete everything under this prefix". Instead
 * each team carries a generation number in appconfig; it is part of every key
 * for that team, and changing the link bumps it. Old entries expire on their
 * own TTL and are simply never asked for again.
 *
 * ## TTLs
 *
 * Personal work data is the most time-sensitive (a work package assigned a
 * minute ago should not take five to appear), the overview less so, and the
 * capability probe least — an administrator who just connected the app can
 * press "Test connection", which bypasses the cache.
 */
class OpenProjectCache {

    public const TTL_CAPABILITIES = 60;
    public const TTL_OVERVIEW     = 300;
    public const TTL_MY_WORK      = 120;
    public const TTL_TYPES        = 900;

    /**
     * The shortest interval between two forced refreshes of one entry, per
     * user. Below this a "Refresh" click is served from cache — a widget
     * hammered by a bouncing tab must not turn into a request storm at
     * OpenProject.
     */
    public const REFRESH_COOLDOWN = 10;

    /** Bump when the shape of a cached value changes. */
    private const SCHEMA_VERSION = 'v1';

    private const CFG_GENERATION = 'openproject_cache_gen_';

    private ICache $cache;

    public function __construct(
        ICacheFactory   $cacheFactory,
        private IConfig $config,
    ) {
        $this->cache = $cacheFactory->createDistributed('teamhub_openproject');
    }

    /**
     * Build the key for one user's view of one team's project.
     *
     * `$kind` names what is cached (`overview`, `mywork:assigned`, …). The
     * host is part of the key so a repointed integration never serves the old
     * instance's data under the new one's id.
     */
    public function key(string $kind, string $userId, string $teamId, int $projectId, string $host): string {
        return implode('|', [
            self::SCHEMA_VERSION,
            $this->generation($teamId),
            $kind,
            $userId,
            $teamId,
            (string)$projectId,
            md5($host),
        ]);
    }

    /** A key for something that is per user but not per team (capabilities). */
    public function userKey(string $kind, string $userId, string $host): string {
        return implode('|', [self::SCHEMA_VERSION, $kind, $userId, md5($host)]);
    }

    /** @return mixed|null */
    public function get(string $key): mixed {
        $value = $this->cache->get($key);
        return $value === null ? null : $value;
    }

    public function set(string $key, mixed $value, int $ttl): void {
        $this->cache->set($key, $value, $ttl);
    }

    public function remove(string $key): void {
        $this->cache->remove($key);
    }

    /**
     * Is a forced refresh of `$key` allowed right now for this user. Records
     * the attempt, so two clicks inside the cooldown both see `false` after
     * the first.
     */
    public function allowRefresh(string $key): bool {
        $gate = $key . '|refresh';
        if ($this->cache->get($gate) !== null) {
            return false;
        }
        $this->cache->set($gate, 1, self::REFRESH_COOLDOWN);
        return true;
    }

    /**
     * Forget everything cached for one team, for every user, at once. Called
     * when the link is created, changed or removed.
     */
    public function invalidateTeam(string $teamId): void {
        $this->config->setAppValue(
            Application::APP_ID,
            self::CFG_GENERATION . $teamId,
            (string)($this->generation($teamId) + 1),
        );
    }

    /** Drop the team's generation counter — part of the team-delete cascade. */
    public function forgetTeam(string $teamId): void {
        $this->config->deleteAppValue(Application::APP_ID, self::CFG_GENERATION . $teamId);
    }

    private function generation(string $teamId): int {
        return (int)$this->config->getAppValue(Application::APP_ID, self::CFG_GENERATION . $teamId, '0');
    }
}
