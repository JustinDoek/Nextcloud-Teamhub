<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\TeamSpace;

use OCA\TeamHub\Db\AuditLogMapper;
use OCA\TeamHub\MyWork\ActionType;
use OCA\TeamHub\MyWork\Category;
use OCA\TeamHub\MyWork\Provider\TeamSpaceAdminWorkProvider;
use OCA\TeamHub\MyWork\SourceGroup;
use OCA\TeamHub\MyWork\WorkQuery;
use OCA\TeamHub\Service\TeamExpiryService;
use OCA\TeamHub\Service\TeamSpaceReconcileService;
use OCA\TeamHub\Service\TeamSpaceService;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `TeamSpaceAdminWorkProvider` (v4.10.1): administrator-only, unavailable
 * without a provider, three kinds of row, the procedure on the row, and the
 * in-app hand-over — Hand to team owner → Waiting for others → the owner
 * reports done → Close.
 */
class TeamSpaceAdminWorkProviderTest extends TestCase {

    private bool $isAdmin   = true;
    private bool $available = true;
    /** @var array<int, array<string,mixed>> */
    private array $shared    = [];
    /** @var array<int, array<string,mixed>> */
    private array $conflicts = [];
    /** @var array<int, array<string,mixed>> */
    private array $reports   = [];
    private ?array $owner    = ['uid' => 'inge', 'displayName' => 'Inge NC', 'email' => 'inge@example.org'];
    /** @var array<int, array<string,mixed>> the reconcile service's writes */
    private array $calls = [];

    private function provider(): TeamSpaceAdminWorkProvider {
        $reconcile = $this->createMock(TeamSpaceReconcileService::class);
        $reconcile->method('listSharedFolderTeams')->willReturnCallback(fn (): array => $this->shared);
        $reconcile->method('listConflicts')->willReturnCallback(fn (): array => $this->conflicts);
        $reconcile->method('assignTask')->willReturnCallback(function (string $teamId, string $by, string $byName, array $owner, string $note): array {
            $this->calls[] = ['assign', $teamId, $by, $byName, $owner, $note];
            return ['status' => 'assigned'];
        });
        $reconcile->method('closeTask')->willReturnCallback(function (string $teamId, string $by): array {
            if ($teamId === 'gone') {
                throw new \RuntimeException('This task is not open.');
            }
            $this->calls[] = ['close', $teamId, $by];
            return ['status' => 'closed'];
        });

        $spaces = $this->createMock(TeamSpaceService::class);
        $spaces->method('isAvailable')->willReturnCallback(fn (): bool => $this->available);

        $audit = $this->createMock(AuditLogMapper::class);
        $audit->method('findByEventTypes')->willReturnCallback(fn (): array => $this->reports);

        $expiry = $this->createMock(TeamExpiryService::class);
        $expiry->method('resolveTeamOwner')->willReturnCallback(fn (): ?array => $this->owner);
        $expiry->method('resolveTeamName')->willReturnCallback(fn (string $id): string => 'Team ' . $id);

        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturnCallback(fn (): bool => $this->isAdmin);

        $lieke = $this->createMock(IUser::class);
        $lieke->method('getDisplayName')->willReturn('Lieke Adm');
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(fn (string $uid) => $uid === 'lieke' ? $lieke : null);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn (string $s, array $p = []) => vsprintf(str_replace(['%1$s', '%2$s', '%3$s'], ['%s', '%s', '%s'], $s), $p));
        $l->method('n')->willReturnCallback(fn (string $s, string $pl, int $n, array $p = []) => vsprintf(str_replace(['%n', '%1$s'], [(string)$n, '%s'], $n === 1 ? $s : $pl), $p));

        return new TeamSpaceAdminWorkProvider(
            $reconcile, $spaces, $audit, $expiry, $groups, $users, $l, $this->createMock(LoggerInterface::class),
        );
    }

    private function query(): WorkQuery {
        return new WorkQuery(userId: 'lieke', teamIds: [], now: 1_700_000_000);
    }

    public function testIdentityAndGroup(): void {
        $p = $this->provider();
        $this->assertSame('teamspace_admin', $p->getId());
        $this->assertTrue($p->isInstanceScoped());
        $this->assertSame(SourceGroup::ADMINISTRATION, SourceGroup::of($p->getId()));
        $this->assertTrue($p->isAvailable());
        // v4.10.29 — Approve / Reject left with the quota request.
        $this->assertSame([ActionType::OPEN, ActionType::DELEGATE, ActionType::COMPLETE], $p->getCapabilities()['actions']);
        $this->assertNotContains('team_space_quota', $p->getCapabilities()['resourceTypes']);
    }

    public function testUnavailableWithoutTheNextcloud35Provider(): void {
        $this->available = false;
        $p = $this->provider();
        $this->assertFalse($p->isAvailable());
        $this->assertNotNull($p->getUnavailableReason());
        $this->shared = [$this->sharedTeam()];
        $this->assertSame([], $p->fetchItems($this->query())->items);
    }

    public function testNonAdminsGetNothing(): void {
        $this->isAdmin = false;
        $this->shared  = [$this->sharedTeam()];
        $p = $this->provider();
        $this->assertSame([], $p->fetchItems($this->query())->items);
        $this->assertNull($p->getItem('lieke', 'shared:t1', []));
    }

    public function testASharedFolderRowOffersTheHandOverWithTheProcedure(): void {
        $this->shared = [$this->sharedTeam()];
        $p    = $this->provider();
        $rows = $p->fetchItems($this->query())->items;

        $this->assertCount(1, $rows);
        $item = $rows[0];
        $this->assertSame('teamspace_admin:shared:t1', $item->id);
        $this->assertSame(Category::ACTION_REQUIRED, $item->category);
        $this->assertSame('Move the shared folder of Sales into its team space', $item->title);
        $this->assertSame('"Sales docs" → "Sales"', $item->subtitle);
        $this->assertCount(4, $item->metadata['steps'], 'move, connect, disconnect, clears itself');
        $this->assertStringContainsString('+ Connect team folder', $item->metadata['steps'][1]);
        $this->assertSame('inge', $item->metadata['ownerUid']);
        $this->assertNull($item->metadata['taskStatus']);
        $this->assertSame('Hand to team owner', $item->metadata['actionLabels'][ActionType::DELEGATE]);
        $this->assertSame('Hand the move to Inge NC?', $item->metadata['confirm'][ActionType::DELEGATE]['title']);
        $this->assertSame('Note to the owner (optional)', $item->metadata['confirm'][ActionType::DELEGATE]['reasonLabel']);
        $this->assertSame([ActionType::OPEN, ActionType::DELEGATE], $p->getAvailableActions('lieke', $item));
        $this->assertSame(['type' => 'user', 'id' => 'inge', 'displayName' => 'Inge NC'], $item->waitingFor);
        $this->assertArrayNotHasKey('mailtoUrl', $item->metadata, 'the workflow stays in the app');

        $this->assertSame($item->id, $p->getItem('lieke', 'shared:t1', [])?->id, 're-read resolves the same row');
        $this->assertNull($p->getItem('lieke', 'shared:nope', []));
    }

    public function testAConnectedSpaceDropsTheConnectStep(): void {
        $this->shared = [$this->sharedTeam(['spaceConnected' => true])];
        $item = $this->provider()->fetchItems($this->query())->items[0];
        $this->assertCount(3, $item->metadata['steps']);
    }

    public function testNoOwnerMeansNoHandOver(): void {
        $this->owner  = null;
        $this->shared = [$this->sharedTeam()];
        $p    = $this->provider();
        $item = $p->fetchItems($this->query())->items[0];
        $this->assertNull($item->metadata['ownerUid']);
        $this->assertSame([ActionType::OPEN], $p->getAvailableActions('lieke', $item));
        $this->assertSame('Hand the move to the team owner?', $item->metadata['confirm'][ActionType::DELEGATE]['title']);
    }

    public function testHandOverRecordsTheOwnerAndTheNote(): void {
        $this->shared = [$this->sharedTeam()];
        $p    = $this->provider();
        $item = $p->fetchItems($this->query())->items[0];

        $result = $p->executeAction('lieke', $item, ActionType::DELEGATE, ['reason' => 'Before Friday please']);
        $this->assertTrue($result->ok);
        $this->assertStringContainsString('Handed to Inge NC', $result->message);
        $this->assertSame(
            [['assign', 't1', 'lieke', 'Lieke Adm', ['uid' => 'inge', 'displayName' => 'Inge NC'], 'Before Friday please']],
            $this->calls,
        );
    }

    public function testWhileTheOwnerHasItTheRowWaits(): void {
        $this->shared = [$this->sharedTeam(['task' => $this->task('assigned')])];
        $p    = $this->provider();
        $item = $p->fetchItems($this->query())->items[0];
        $this->assertSame(Category::WAITING_FOR_OTHERS, $item->category);
        $this->assertSame(TeamSpaceAdminWorkProvider::STATUS_ASSIGNED, $item->status);
        $this->assertSame('assigned', $item->metadata['taskStatus']);
        $this->assertSame([ActionType::OPEN], $p->getAvailableActions('lieke', $item));
        $this->assertSame('Handed to Inge NC. You are notified when they report the move done.', $item->reason);
        $this->assertSame(1_699_999_500, $item->updatedAt);
    }

    public function testWhenTheOwnerReportedDoneTheRowComesBackWithClose(): void {
        $this->shared = [$this->sharedTeam(['task' => $this->task('done')])];
        $p    = $this->provider();
        $item = $p->fetchItems($this->query())->items[0];
        $this->assertSame(Category::ACTION_REQUIRED, $item->category);
        $this->assertSame('high', $item->priority);
        $this->assertSame(TeamSpaceAdminWorkProvider::STATUS_OWNER_DONE, $item->status);
        $this->assertSame([ActionType::OPEN, ActionType::COMPLETE], $p->getAvailableActions('lieke', $item));
        $this->assertSame('Close', $item->metadata['actionLabels'][ActionType::COMPLETE]);
        $this->assertSame('Close the move for Sales?', $item->metadata['confirm'][ActionType::COMPLETE]['title']);

        $result = $p->executeAction('lieke', $item, ActionType::COMPLETE, []);
        $this->assertTrue($result->ok);
        $this->assertSame([['close', 't1', 'lieke']], $this->calls);
    }

    public function testAClosedRowIsCompletedAndActionless(): void {
        $this->shared = [$this->sharedTeam(['task' => $this->task('closed')])];
        $p    = $this->provider();
        $item = $p->fetchItems($this->query())->items[0];
        $this->assertSame(Category::COMPLETED, $item->category);
        $this->assertSame(1_699_999_900, $item->completedAt);
        $this->assertSame([], $p->getAvailableActions('lieke', $item));
        $this->assertSame([], $item->metadata['confirm']);
    }

    public function testALedgerThatMovedIsAConflictNotAFailure(): void {
        $this->shared = [$this->sharedTeam(['teamId' => 'gone', 'task' => $this->task('done')])];
        $p    = $this->provider();
        $item = $p->fetchItems($this->query())->items[0];
        $result = $p->executeAction('lieke', $item, ActionType::COMPLETE, []);
        $this->assertFalse($result->ok);
        $this->assertSame('conflict', $result->errorCode);
    }

    public function testReportsAreCompletedRowsFromTheAuditLog(): void {
        $this->reports = [[
            'id' => 77, 'team_id' => 't1', 'event_type' => TeamSpaceReconcileService::AUDIT_SHARES_REMOVED,
            'actor_uid' => null, 'target_type' => 'file', 'target_id' => '5', 'created_at' => 1_699_990_000,
            'metadata' => [
                'folder_id' => 5, 'mount_point' => 'Sales',
                'removed' => [['kind' => 'group', 'id' => 'admin', 'name' => 'Administrators'], ['kind' => 'circle', 'id' => 'x', 'name' => 'Project X']],
                'disconnected_teams' => ['Project X'],
            ],
        ], [
            'id' => 78, 'team_id' => 't2', 'event_type' => TeamSpaceReconcileService::AUDIT_DUPLICATE_REMOVED,
            'actor_uid' => null, 'target_type' => 'file', 'target_id' => '9', 'created_at' => 1_699_990_100,
            'metadata' => ['removed_space' => 'Two', 'kept_folder' => 'Two (1)', 'folder_id' => 8],
        ]];
        $p    = $this->provider();
        $rows = $p->fetchItems($this->query())->items;

        $this->assertCount(2, $rows);
        $this->assertSame('teamspace_admin:report:77', $rows[0]->id);
        $this->assertSame(Category::COMPLETED, $rows[0]->category);
        $this->assertSame(1_699_990_000, $rows[0]->completedAt);
        $this->assertSame('The team folder of Team t1 became a team space', $rows[0]->title);
        $this->assertSame('Access to "Sales" was removed for 2 groups or teams; a team space belongs to its team only.', $rows[0]->reason);
        $this->assertSame([
            'Group "Administrators" no longer has access.',
            'Team "Project X" no longer has access.',
            'Team "Project X" was disconnected from the folder and received an empty team space of its own.',
        ], $rows[0]->metadata['steps']);
        $this->assertSame([ActionType::OPEN], $p->getAvailableActions('lieke', $rows[0]));
        $this->assertSame('unsupported', $p->executeAction('lieke', $rows[0], ActionType::COMPLETE, [])->errorCode);

        $this->assertSame('An empty duplicate team space of Team t2 was removed', $rows[1]->title);
        $this->assertSame([], $rows[1]->metadata['steps']);
        $this->assertSame('report:78', $p->getItem('lieke', 'report:78', [])?->providerItemId);
    }

    public function testConflictsComeFirstAndAreHighPriority(): void {
        $this->conflicts = [['teamId' => 't3', 'teamName' => 'Three', 'space' => 'Three', 'folder' => 'Three (1)', 'since' => 1]];
        $this->shared    = [$this->sharedTeam()];
        $p    = $this->provider();
        $rows = $p->fetchItems($this->query())->items;
        $this->assertSame('teamspace_admin:conflict:t3', $rows[0]->id);
        $this->assertSame('high', $rows[0]->priority);
        $this->assertSame('Three has two folders with content', $rows[0]->title);
        $this->assertCount(4, $rows[0]->metadata['steps']);
        $this->assertSame([ActionType::OPEN], $p->getAvailableActions('lieke', $rows[0]));
        $this->assertSame('teamspace_admin:shared:t1', $rows[1]->id);
    }

    public function testNonAdminActionsAreForbidden(): void {
        $this->shared = [$this->sharedTeam()];
        $p    = $this->provider();
        $item = $p->fetchItems($this->query())->items[0];
        $this->isAdmin = false;
        $this->assertSame('forbidden', $p->executeAction('lieke', $item, ActionType::DELEGATE, [])->errorCode);
        $this->assertSame([], $p->getAvailableActions('lieke', $item));
    }

    /** @param array<string,mixed> $overrides */
    private function sharedTeam(array $overrides = []): array {
        return $overrides + [
            'teamId' => 't1', 'teamName' => 'Sales', 'sharedFolderId' => 4711, 'sharedFolderName' => 'Sales docs',
            'spaceName' => 'Sales', 'spaceConnected' => false, 'since' => 1_699_999_000, 'task' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function task(string $status): array {
        return [
            'status' => $status, 'ownerUid' => 'inge', 'ownerName' => 'Inge NC',
            'assignedBy' => 'lieke', 'assignedByName' => 'Lieke Adm', 'assignedAt' => 1_699_999_500, 'note' => '',
            'completedAt' => $status !== 'assigned' ? 1_699_999_800 : null, 'completedBy' => $status !== 'assigned' ? 'inge' : null,
            'closedAt' => $status === 'closed' ? 1_699_999_900 : null, 'closedBy' => $status === 'closed' ? 'lieke' : null,
        ];
    }
}
