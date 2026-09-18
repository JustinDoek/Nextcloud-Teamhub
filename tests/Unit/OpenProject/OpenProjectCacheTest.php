<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\OpenProject;

use OCA\TeamHub\Service\OpenProject\OpenProjectCache;

/**
 * Cache separation between users, teams, projects and hosts, and the
 * generation-based invalidation.
 */
class OpenProjectCacheTest extends OpenProjectTestCase {

    public function testKeysDifferPerUserTeamProjectAndHost(): void {
        $cache = $this->cache();
        $base  = $cache->key('overview', 'alice', 'team-a', 12, self::HOST);

        $this->assertNotSame($base, $cache->key('overview', 'bob', 'team-a', 12, self::HOST), 'another user');
        $this->assertNotSame($base, $cache->key('overview', 'alice', 'team-b', 12, self::HOST), 'another team');
        $this->assertNotSame($base, $cache->key('overview', 'alice', 'team-a', 13, self::HOST), 'another project');
        $this->assertNotSame($base, $cache->key('overview', 'alice', 'team-a', 12, 'https://other.example'), 'another host');
        $this->assertNotSame($base, $cache->key('mywork:assigned', 'alice', 'team-a', 12, self::HOST), 'another kind');
        $this->assertSame($base, $cache->key('overview', 'alice', 'team-a', 12, self::HOST), 'stable');
    }

    public function testOneUsersValueIsNeverServedToAnother(): void {
        $cache = $this->cache();
        $cache->set($cache->key('overview', 'alice', 't', 1, self::HOST), ['secret' => 'alice'], 60);

        $this->assertNull($cache->get($cache->key('overview', 'bob', 't', 1, self::HOST)));
        $this->assertSame(['secret' => 'alice'], $cache->get($cache->key('overview', 'alice', 't', 1, self::HOST)));
    }

    public function testInvalidatingATeamChangesEveryKeyForThatTeamOnly(): void {
        $cache  = $this->cache();
        $before = $cache->key('overview', 'alice', 'team-a', 1, self::HOST);
        $other  = $cache->key('overview', 'alice', 'team-b', 1, self::HOST);
        $cache->set($before, 'v', 60);

        $cache->invalidateTeam('team-a');

        $after = $cache->key('overview', 'alice', 'team-a', 1, self::HOST);
        $this->assertNotSame($before, $after);
        $this->assertNull($cache->get($after), 'the old value is unreachable under the new key');
        $this->assertSame($other, $cache->key('overview', 'alice', 'team-b', 1, self::HOST), 'other teams untouched');

        $cache->forgetTeam('team-a');
        $this->assertSame($before, $cache->key('overview', 'alice', 'team-a', 1, self::HOST), 'forgetting resets the generation');
    }

    public function testRefreshCooldownAllowsOneRefreshPerWindow(): void {
        $cache = $this->cache();
        $key   = $cache->key('overview', 'alice', 't', 1, self::HOST);

        $this->assertTrue($cache->allowRefresh($key));
        $this->assertFalse($cache->allowRefresh($key), 'second click inside the cooldown');
        $this->assertTrue($cache->allowRefresh($cache->key('overview', 'bob', 't', 1, self::HOST)), 'per user');
    }

    public function testUserKeyIsPerUserAndHost(): void {
        $cache = $this->cache();
        $this->assertNotSame($cache->userKey('capabilities', 'alice', self::HOST), $cache->userKey('capabilities', 'bob', self::HOST));
        $this->assertNotSame($cache->userKey('capabilities', 'alice', self::HOST), $cache->userKey('capabilities', 'alice', 'https://b'));
    }

    public function testTtlConstantsAreConservative(): void {
        $this->assertLessThanOrEqual(120, OpenProjectCache::TTL_MY_WORK, 'personal data expires quickly');
        $this->assertLessThanOrEqual(300, OpenProjectCache::TTL_OVERVIEW);
        $this->assertGreaterThan(0, OpenProjectCache::REFRESH_COOLDOWN);
    }
}
