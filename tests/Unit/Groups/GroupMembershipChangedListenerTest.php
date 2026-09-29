<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Groups;

use OCA\TeamHub\BackgroundJob\GroupMirrorSyncJob;
use OCA\TeamHub\Listener\GroupMembershipChangedListener;
use OCA\TeamHub\Service\GroupMirrorSyncService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IGroup;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `GroupMembershipChangedListener` (v4.10.47): a join or leave on a group
 * attached to a team queues one sync job for that group; any other group, or
 * any other event, queues nothing; a failure never escapes into the group
 * write that dispatched the event.
 */
class GroupMembershipChangedListenerTest extends TestCase {

    private function group(string $gid): IGroup {
        $g = $this->createMock(IGroup::class);
        $g->method('getGID')->willReturn($gid);
        return $g;
    }

    private function listener(GroupMirrorSyncService $sync, IJobList $jobs): GroupMembershipChangedListener {
        return new GroupMembershipChangedListener($sync, $jobs, $this->createMock(LoggerInterface::class));
    }

    public function testJoinAndLeaveOnATeamGroupQueueTheGroupSync(): void {
        $sync = $this->createMock(GroupMirrorSyncService::class);
        $sync->method('isTeamGroup')->willReturnCallback(fn (string $g): bool => $g === 'Marketing');
        $jobs = $this->createMock(IJobList::class);
        $jobs->expects($this->exactly(2))->method('add')
            ->with(GroupMirrorSyncJob::class, ['groupId' => 'Marketing']);

        $l    = $this->listener($sync, $jobs);
        $user = $this->createMock(IUser::class);
        $l->handle(new UserAddedEvent($this->group('Marketing'), $user));
        $l->handle(new UserRemovedEvent($this->group('Marketing'), $user));
    }

    public function testOtherGroupsAndOtherEventsQueueNothing(): void {
        $sync = $this->createMock(GroupMirrorSyncService::class);
        $sync->method('isTeamGroup')->willReturn(false);
        $jobs = $this->createMock(IJobList::class);
        $jobs->expects($this->never())->method('add');

        $l = $this->listener($sync, $jobs);
        $l->handle(new UserAddedEvent($this->group('Finance'), $this->createMock(IUser::class)));
        $l->handle(new Event());
    }

    public function testAFailureIsSwallowed(): void {
        $sync = $this->createMock(GroupMirrorSyncService::class);
        $sync->method('isTeamGroup')->willThrowException(new \RuntimeException('db down'));
        $jobs = $this->createMock(IJobList::class);
        $jobs->expects($this->never())->method('add');

        $this->listener($sync, $jobs)
            ->handle(new UserAddedEvent($this->group('Marketing'), $this->createMock(IUser::class)));
        $this->addToAssertionCount(1);
    }
}
