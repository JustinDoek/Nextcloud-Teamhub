<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\TeamSpace;

use OCA\TeamHub\Db\TeamAppResourceMapper;
use OCA\TeamHub\MyWork\ActionType;
use OCA\TeamHub\MyWork\Category;
use OCA\TeamHub\MyWork\Provider\TeamAdminWorkProvider;
use OCA\TeamHub\MyWork\WorkQuery;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\ResourceDiscoveryService;
use OCA\TeamHub\Service\TeamSpaceReconcileService;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The owner's half of the team-space hand-over on `TeamAdminWorkProvider`
 * (v4.10.1): a task row for the team's admins with the steps, Complete
 * reporting it done, nothing for a team the viewer does not administer.
 */
class TeamAdminWorkProviderTeamSpaceTaskTest extends TestCase {

    /** @var string[] teams the viewer administers */
    private array $adminOf = ['t1'];
    /** @var array<int, array<string,mixed>> */
    private array $tasks = [];
    /** @var array<int, array<string,mixed>> */
    private array $completed = [];

    private function provider(): TeamAdminWorkProvider {
        $mapper = $this->createMock(TeamAppResourceMapper::class);
        $mapper->method('findPendingByTeam')->willReturn([]);

        $members = $this->createMock(MemberService::class);
        $members->method('requireAdminLevel')->willReturnCallback(function (string $teamId): void {
            if (!in_array($teamId, $this->adminOf, true)) {
                throw new \RuntimeException('not an admin');
            }
        });
        $members->method('getPendingRequests')->willReturn([]);

        $reconcile = $this->createMock(TeamSpaceReconcileService::class);
        $reconcile->method('listAssignedTasks')->willReturnCallback(
            fn (array $teamIds): array => array_values(array_filter($this->tasks, fn (array $t): bool => in_array($t['teamId'], $teamIds, true)))
        );
        $reconcile->method('handOverWorkflow')->willReturnCallback(fn (?array $task, ?string $owner, array $labels): array => ['steps' => [['label' => $labels[0], 'state' => 'done', 'actor' => 'Lieke Adm', 'at' => 1], ['label' => $labels[1], 'state' => 'current', 'actor' => null, 'at' => null], ['label' => $labels[2], 'state' => 'pending', 'actor' => null, 'at' => null]], 'current' => 2]);
        $reconcile->method('completeTask')->willReturnCallback(function (string $teamId, string $uid, string $name): array {
            if ($teamId === 'stale') {
                throw new \RuntimeException('This task is not open.');
            }
            $this->completed[] = [$teamId, $uid, $name];
            return ['status' => 'done'];
        });


        $inge = $this->createMock(IUser::class);
        $inge->method('getDisplayName')->willReturn('Inge NC');
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(fn (string $uid) => $uid === 'inge' ? $inge : null);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn (string $s, array $p = []) => vsprintf(str_replace(['%1$s', '%2$s'], ['%s', '%s'], $s), $p));

        return new TeamAdminWorkProvider(
            $mapper,
            $this->createMock(ResourceDiscoveryService::class),
            $members,
            $reconcile,
            $users,
            $l,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function query(array $teamIds): WorkQuery {
        return new WorkQuery(userId: 'inge', teamIds: $teamIds, teamNames: ['t1' => 'Sales'], now: 1_700_000_000);
    }

    public function testTheTaskRowCarriesTheStepsAndReportsDone(): void {
        $this->tasks = [$this->task()];
        $p    = $this->provider();
        $rows = $p->fetchItems($this->query(['t1']))->items;

        $this->assertCount(1, $rows);
        $item = $rows[0];
        $this->assertSame('teamadmin:teamspace:t1:task', $item->id);
        $this->assertSame(Category::ACTION_REQUIRED, $item->category);
        $this->assertSame('high', $item->priority);
        $this->assertSame('Sales', $item->teamName);
        $this->assertSame('Move the shared folder into the team space', $item->title);
        $this->assertSame('"Sales docs" → "Sales"', $item->subtitle);
        $this->assertSame('Lieke Adm handed this to you: Before Friday', $item->reason);
        $this->assertCount(4, $item->metadata['steps'], 'move, connect, disconnect, complete');
        $this->assertSame('manage_team', $item->openTarget['kind']);
        $this->assertSame('integrations', $item->openTarget['tab']);
        $this->assertSame('Report the move done?', $item->metadata['confirm'][ActionType::COMPLETE]['title']);
        $this->assertSame([ActionType::OPEN, ActionType::COMPLETE], $p->getAvailableActions('inge', $item));

        $this->assertSame($item->id, $p->getItem('inge', 'teamspace:t1:task', ['t1'])?->id);
        $this->assertNull($p->getItem('inge', 'teamspace:t1:task', ['other']), 'not among the caller\'s teams');

        $result = $p->executeAction('inge', $item, ActionType::COMPLETE, []);
        $this->assertTrue($result->ok);
        $this->assertTrue($result->removed, 'the row leaves the owner\'s queue');
        $this->assertSame([['t1', 'inge', 'Inge NC']], $this->completed);
    }

    public function testAConnectedSpaceDropsTheConnectStepAndANoteIsOptional(): void {
        $this->tasks = [$this->task(['spaceConnected' => true, 'task' => $this->handed('')])];
        $item = $this->provider()->fetchItems($this->query(['t1']))->items[0];
        $this->assertCount(3, $item->metadata['steps']);
        $this->assertSame('Lieke Adm handed this to you and is waiting for it.', $item->reason);
    }

    public function testNothingForATeamTheViewerDoesNotAdminister(): void {
        $this->adminOf = [];
        $this->tasks   = [$this->task()];
        $p = $this->provider();
        $this->assertSame([], $p->fetchItems($this->query(['t1']))->items);
        $this->assertNull($p->getItem('inge', 'teamspace:t1:task', ['t1']));
    }

    public function testAStaleTaskIsAConflict(): void {
        $this->tasks = [$this->task(['teamId' => 'stale'])];
        $this->adminOf = ['stale'];
        $p    = $this->provider();
        $item = $p->fetchItems($this->query(['stale']))->items[0];
        $result = $p->executeAction('inge', $item, ActionType::COMPLETE, []);
        $this->assertFalse($result->ok);
        $this->assertSame('conflict', $result->errorCode);
        $this->assertSame('unsupported', $p->executeAction('inge', $item, ActionType::APPROVE, [])->errorCode);
    }

    /** @param array<string,mixed> $overrides */
    private function task(array $overrides = []): array {
        return $overrides + [
            'teamId' => 't1', 'teamName' => 'Sales', 'sharedFolderId' => 4711, 'sharedFolderName' => 'Sales docs',
            'spaceName' => 'Sales', 'spaceConnected' => false, 'since' => 1_699_999_000,
            'task' => $this->handed('Before Friday'),
        ];
    }

    /** @return array<string,mixed> */
    private function handed(string $note): array {
        return [
            'status' => 'assigned', 'ownerUid' => 'inge', 'ownerName' => 'Inge NC',
            'assignedBy' => 'lieke', 'assignedByName' => 'Lieke Adm', 'assignedAt' => 1_699_999_500, 'note' => $note,
            'completedAt' => null, 'completedBy' => null, 'closedAt' => null, 'closedBy' => null,
        ];
    }
}
