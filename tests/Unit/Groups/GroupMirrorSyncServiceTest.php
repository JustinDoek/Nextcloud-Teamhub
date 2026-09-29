<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Groups;

use OCA\TeamHub\Service\GroupMirrorSyncService;
use OCA\TeamHub\Service\TeamRegistryService;
use OCP\IDBConnection;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * `GroupMirrorSyncService` (v4.10.47): the current Circles copy of a group
 * matches the group — missing users added by one Circles group sync,
 * departed users removed one by one, nothing removed when the group reports
 * no users — and every team holding an older copy is moved onto the current
 * one, after the copy is complete.
 */
class GroupMirrorSyncServiceTest extends TestCase {

    /** @var list<array{teamId: string, groupId: string, singleId: string}> */
    public array $bindings = [];
    /** @var array<string, list<string>|null> gid => users, null = no such group */
    public array $groups = [];
    /** @var array<string, string> gid => the copy Circles maintains now */
    public array $current = [];
    /** @var array<string, list<string>> copy id => users */
    public array $copies = [];
    /** @var list<string> the order of every write, for the ordering test */
    public array $log = [];
    public bool $circlesFails = false;
    public bool $relinkFails = false;

    private function service(): GroupMirrorSyncService {
        return new class(
            $this->createMock(IDBConnection::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(TeamRegistryService::class),
            $this->createMock(ContainerInterface::class),
            $this->createMock(LoggerInterface::class),
            $this,
        ) extends GroupMirrorSyncService {
            public function __construct(
                IDBConnection $db, IGroupManager $gm, TeamRegistryService $reg,
                ContainerInterface $c, LoggerInterface $log,
                private GroupMirrorSyncServiceTest $t,
            ) {
                parent::__construct($db, $gm, $reg, $c, $log);
            }
            protected function readTeamGroupBindings(): array {
                return $this->t->bindings;
            }
            protected function readCopyMemberIds(string $copyId): array {
                return $this->t->copies[$copyId] ?? [];
            }
            protected function readGroupUserIds(string $groupId): ?array {
                return $this->t->groups[$groupId] ?? null;
            }
            protected function circlesCurrentCopyId(string $groupId): ?string {
                return $this->t->current[$groupId] ?? null;
            }
            protected function circlesAddMissing(string $groupId): bool {
                $this->t->log[] = 'add:' . $groupId;
                return !$this->t->circlesFails;
            }
            protected function circlesRemove(string $groupId, string $userId): bool {
                $this->t->log[] = 'remove:' . $groupId . ':' . $userId;
                return !$this->t->circlesFails;
            }
            protected function relink(string $teamId, string $groupId, string $staleSingleId): bool {
                $this->t->log[] = 'relink:' . $teamId . ':' . $staleSingleId;
                return !$this->t->relinkFails;
            }
        };
    }

    private function marketing(array $groupUsers, array $currentCopy, array $bindings = ['current']): void {
        $this->groups['Marketing']  = $groupUsers;
        $this->current['Marketing'] = 'current';
        $this->copies['current']    = $currentCopy;
        foreach ($bindings as $i => $copy) {
            $this->bindings[] = ['teamId' => 'team' . $i, 'groupId' => 'Marketing', 'singleId' => $copy];
        }
    }

    public function testUserAddedLaterIsSyncedWithOneCirclesCall(): void {
        $this->marketing(['Charles West', 'Dick Turner', 'Inge NC'], ['Dick Turner', 'Inge NC']);

        $this->assertSame(['added' => 1, 'removed' => 0, 'relinked' => 0], $this->service()->syncGroup('Marketing'));
        $this->assertSame(['add:Marketing'], $this->log);
    }

    public function testUserWhoLeftTheGroupIsRemovedFromTheCopy(): void {
        $this->marketing(['Inge NC'], ['Dick Turner', 'Inge NC']);

        $this->assertSame(['added' => 0, 'removed' => 1, 'relinked' => 0], $this->service()->syncGroup('Marketing'));
        $this->assertSame(['remove:Marketing:Dick Turner'], $this->log);
    }

    public function testInSyncGroupTouchesNothing(): void {
        $this->marketing(['Dick Turner', 'Inge NC'], ['Inge NC', 'Dick Turner']);

        $this->assertSame(['added' => 0, 'removed' => 0, 'relinked' => 0], $this->service()->syncGroup('Marketing'));
        $this->assertSame([], $this->log);
    }

    public function testEmptyGroupNeverEmptiesTheCopy(): void {
        // An unreachable LDAP answers an empty list; that must not strip the team.
        $this->marketing([], ['Dick Turner', 'Inge NC']);

        $this->assertSame(['added' => 0, 'removed' => 0, 'relinked' => 0], $this->service()->syncGroup('Marketing'));
        $this->assertSame([], $this->log);
    }

    public function testStaleCopyIsRelinkedAfterTheCurrentCopyIsComplete(): void {
        // The 2026-09-26 case: Marketing_profile held the 08-07 copy; Circles
        // had put Charles into the 09-25 one. One team current, one stale.
        $this->marketing(['Charles West', 'Dick Turner', 'Inge NC'], ['Dick Turner', 'Inge NC'], ['stale-0807', 'current']);

        $this->assertSame(['added' => 1, 'removed' => 0, 'relinked' => 1], $this->service()->syncGroup('Marketing'));
        $this->assertSame(['add:Marketing', 'relink:team0:stale-0807'], $this->log);
    }

    public function testFailedRelinkIsNotCounted(): void {
        $this->relinkFails = true;
        $this->marketing(['Inge NC'], ['Inge NC'], ['stale']);

        $this->assertSame(['added' => 0, 'removed' => 0, 'relinked' => 0], $this->service()->syncGroup('Marketing'));
        $this->assertSame(['relink:team0:stale'], $this->log);
    }

    public function testDeletedGroupOrUnresolvableCopyIsLeftAlone(): void {
        $this->bindings = [
            ['teamId' => 't1', 'groupId' => 'Gone', 'singleId' => 'x'],
            ['teamId' => 't2', 'groupId' => 'NoCircles', 'singleId' => 'y'],
        ];
        $this->groups['NoCircles'] = ['Dick Turner'];   // group exists, Circles cannot resolve a copy

        $s = $this->service();
        $this->assertSame(['added' => 0, 'removed' => 0, 'relinked' => 0], $s->syncGroup('Gone'));
        $this->assertSame(['added' => 0, 'removed' => 0, 'relinked' => 0], $s->syncGroup('NoCircles'));
        $this->assertSame(['added' => 0, 'removed' => 0, 'relinked' => 0], $s->syncGroup(''));
        $this->assertSame([], $this->log);
    }

    public function testFailedCirclesCallsAreNotCounted(): void {
        $this->circlesFails = true;
        $this->marketing(['Charles West', 'Inge NC'], ['Dick Turner', 'Inge NC']);

        $this->assertSame(['added' => 0, 'removed' => 0, 'relinked' => 0], $this->service()->syncGroup('Marketing'));
        $this->assertSame(['add:Marketing', 'remove:Marketing:Dick Turner'], $this->log);
    }

    public function testSyncAllVisitsEachGroupOnceAndIsTeamGroupReadsTheBindings(): void {
        $this->bindings = [
            ['teamId' => 'a', 'groupId' => 'Marketing', 'singleId' => 'm-old'],
            ['teamId' => 'b', 'groupId' => 'Marketing', 'singleId' => 'm-new'],
            ['teamId' => 'c', 'groupId' => 'Sales',     'singleId' => 's'],
        ];
        $this->groups  = ['Marketing' => ['Charles West'], 'Sales' => ['Ann']];
        $this->current = ['Marketing' => 'm-new', 'Sales' => 's'];
        $this->copies  = ['m-new' => [], 's' => ['Ann', 'Bob']];

        $s = $this->service();
        $this->assertSame(['groups' => 2, 'added' => 1, 'removed' => 1, 'relinked' => 1], $s->syncAll());
        $this->assertSame(['add:Marketing', 'relink:a:m-old', 'remove:Sales:Bob'], $this->log);
        $this->assertTrue($s->isTeamGroup('Marketing'));
        $this->assertFalse($s->isTeamGroup('Finance'));
        $this->assertFalse($s->isTeamGroup(''));
    }
}
