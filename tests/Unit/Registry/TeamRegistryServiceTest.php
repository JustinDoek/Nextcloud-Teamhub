<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Registry;

use OCA\TeamHub\Constants\CirclesConfig;
use OCA\TeamHub\Db\TeamRegistryMapper;
use OCA\TeamHub\Service\TeamRegistryService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `TeamRegistryService` (v4.10.6): the registry row and the CFG_APP lock —
 * written together on create, the lock released before TeamHub's own
 * destroy, every other config bit left alone, and the hourly re-lock that
 * puts the bit back on registered teams only.
 */
class TeamRegistryServiceTest extends TestCase {

    /** @var array<string,int> unique_id => config, the circles table */
    private array $circles = [];
    /** @var array<string,array{origin:string, created_by:?string}> */
    private array $registry = [];
    /** @var array<int,array{team:string, config:int}> */
    private array $writes = [];

    private function service(): TeamRegistryService {
        $mapper = $this->createMock(TeamRegistryMapper::class);
        $mapper->method('exists')->willReturnCallback(fn (string $id): bool => isset($this->registry[$id]));
        $mapper->method('insert')->willReturnCallback(function (string $id, string $origin, ?string $by): void {
            if (!isset($this->registry[$id])) {
                $this->registry[$id] = ['origin' => $origin, 'created_by' => $by];
            }
        });
        $mapper->method('delete')->willReturnCallback(function (string $id): void {
            unset($this->registry[$id]);
        });
        $mapper->method('allTeamIds')->willReturnCallback(fn (): array => array_keys($this->registry));

        return new class($mapper, $this->createMock(IDBConnection::class), $this->createMock(LoggerInterface::class), $this) extends TeamRegistryService {
            public function __construct(TeamRegistryMapper $m, IDBConnection $db, LoggerInterface $log, private TeamRegistryServiceTest $t) {
                parent::__construct($m, $db, $log);
            }
            protected function readConfigs(array $teamIds): array {
                return $this->t->readConfigs($teamIds);
            }
            protected function writeConfig(string $teamId, int $config): void {
                $this->t->writeConfig($teamId, $config);
            }
        };
    }

    /** @internal for the anonymous subclass */
    public function readConfigs(array $ids): array {
        $out = [];
        foreach ($ids as $id) {
            if (isset($this->circles[$id])) {
                $out[$id] = $this->circles[$id];
            }
        }
        return $out;
    }

    /** @internal for the anonymous subclass */
    public function writeConfig(string $id, int $config): void {
        $this->circles[$id] = $config;
        $this->writes[]     = ['team' => $id, 'config' => $config];
    }

    public function testRegisterCreatedWritesTheRowAndTheLock(): void {
        $this->circles['t1'] = CirclesConfig::CFG_VISIBLE;
        $svc = $this->service();

        $svc->registerCreated('t1', 'inge');

        $this->assertSame(['origin' => TeamRegistryMapper::ORIGIN_TEAMHUB, 'created_by' => 'inge'], $this->registry['t1']);
        $this->assertTrue($svc->isTeamHubTeam('t1'));
        $this->assertTrue($svc->isLocked('t1'));
        // Every other bit survives — the lock is OR-ed in, not written over.
        $this->assertSame(CirclesConfig::CFG_VISIBLE | CirclesConfig::CFG_APP, $this->circles['t1']);
    }

    public function testACircleTeamHubDidNotCreateIsNotATeam(): void {
        $this->circles['ext'] = 0;
        $svc = $this->service();
        $this->assertFalse($svc->isTeamHubTeam('ext'));
        $this->assertFalse($svc->isLocked('ext'));
        $this->assertFalse($svc->isLocked('missing'));
    }

    public function testUnlockClearsOnlyTheLockBit(): void {
        $this->circles['t1'] = CirclesConfig::CFG_OPEN | CirclesConfig::CFG_APP | CirclesConfig::CFG_FEDERATED;
        $svc = $this->service();

        $svc->unlock('t1');

        $this->assertSame(CirclesConfig::CFG_OPEN | CirclesConfig::CFG_FEDERATED, $this->circles['t1']);
        $this->assertFalse($svc->isLocked('t1'));
    }

    public function testLockAndUnlockAreIdempotent(): void {
        $this->circles['t1'] = CirclesConfig::CFG_APP;
        $svc = $this->service();

        $svc->lock('t1');
        $this->assertSame([], $this->writes, 'an already-locked circle is not rewritten');

        $svc->unlock('t1');
        $svc->unlock('t1');
        $this->assertCount(1, $this->writes, 'the second unlock finds nothing to clear');
    }

    public function testLockingAMissingCircleThrows(): void {
        $this->expectException(\RuntimeException::class);
        $this->service()->lock('nope');
    }

    public function testRegisterCreatedSurvivesAFailedLock(): void {
        // No circle row yet — lock() throws; the registry row must still land.
        $svc = $this->service();
        $svc->registerCreated('t1', 'inge');
        $this->assertTrue($svc->isTeamHubTeam('t1'));
    }

    public function testUnregisterRemovesTheRow(): void {
        $this->registry['t1'] = ['origin' => 'teamhub', 'created_by' => 'inge'];
        $svc = $this->service();
        $svc->unregister('t1');
        $this->assertFalse($svc->isTeamHubTeam('t1'));
    }

    public function testRelockAllPutsTheBitBackOnRegisteredTeamsOnly(): void {
        $this->registry = [
            'locked'   => ['origin' => 'teamhub', 'created_by' => null],
            'unlocked' => ['origin' => 'grandfathered', 'created_by' => null],
            'gone'     => ['origin' => 'teamhub', 'created_by' => null], // no circle row any more
        ];
        $this->circles = [
            'locked'   => CirclesConfig::CFG_APP | CirclesConfig::CFG_VISIBLE,
            'unlocked' => CirclesConfig::CFG_VISIBLE,
            'external' => 0, // a Contacts-made circle: not registered, must stay untouched
        ];
        $svc = $this->service();

        $this->assertSame(1, $svc->relockAll());
        $this->assertSame(CirclesConfig::CFG_APP | CirclesConfig::CFG_VISIBLE, $this->circles['unlocked']);
        $this->assertSame(0, $this->circles['external']);
        $this->assertSame([['team' => 'unlocked', 'config' => CirclesConfig::CFG_APP | CirclesConfig::CFG_VISIBLE]], $this->writes);

        $this->assertSame(0, $svc->relockAll(), 'a second pass finds nothing to do');
    }

    public function testRelockAllWithAnEmptyRegistryTouchesNothing(): void {
        $this->circles['external'] = 0;
        $this->assertSame(0, $this->service()->relockAll());
        $this->assertSame([], $this->writes);
    }
}
