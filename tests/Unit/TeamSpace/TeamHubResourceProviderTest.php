<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\TeamSpace;

use OCA\TeamHub\Teams\TeamHubResourceProvider;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `TeamHubResourceProvider` (v4.10.1): one resource per known team, none
 * for an unknown one, the resource id being the team id.
 */
class TeamHubResourceProviderTest extends TestCase {

    private function provider(array $teams): TeamHubResourceProvider {
        $url = $this->createMock(IURLGenerator::class);
        $url->method('linkToRouteAbsolute')->willReturn('https://nc.example/apps/teamhub/');
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn (string $s, array $p = []) => vsprintf($s, $p));

        return new class($this->createMock(IDBConnection::class), $url, $l, $this->createMock(LoggerInterface::class), $teams) extends TeamHubResourceProvider {
            /** @param array<string,string> $teams */
            public function __construct(IDBConnection $db, IURLGenerator $url, IL10N $l, LoggerInterface $log, private array $teams) {
                parent::__construct($db, $url, $l, $log);
            }
            protected function teamName(string $teamId): ?string {
                return $this->teams[$teamId] ?? null;
            }
        };
    }

    public function testIdentity(): void {
        $p = $this->provider([]);
        $this->assertSame('teamhub', $p->getId());
        $this->assertSame('TeamHub', $p->getName());
        $this->assertStringStartsWith('<svg', $p->getIconSvg());
        $this->assertStringContainsString('currentColor', $p->getIconSvg());
    }

    public function testAKnownTeamHasItsHomeAsTheOneResource(): void {
        $p = $this->provider(['abc' => 'Marketing']);
        $resources = $p->getSharedWith('abc');
        $this->assertCount(1, $resources);
        $r = $resources[0];
        $this->assertSame('abc', $r->getId());
        $this->assertSame('Marketing in TeamHub', $r->getLabel());
        $this->assertSame('https://nc.example/apps/teamhub/?team=abc', $r->getUrl());
        $this->assertSame($p, $r->getProvider());
        $this->assertTrue($p->isSharedWithTeam('abc', 'abc'));
        $this->assertFalse($p->isSharedWithTeam('abc', 'other'));
        $this->assertSame(['abc'], $p->getTeamsForResource('abc'));
    }

    public function testAnUnknownTeamHasNothing(): void {
        $p = $this->provider([]);
        $this->assertSame([], $p->getSharedWith('nope'));
        $this->assertFalse($p->isSharedWithTeam('nope', 'nope'));
        $this->assertSame([], $p->getTeamsForResource('nope'));
    }
}
