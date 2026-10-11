<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * The two notifications a task between two people needs (v4.11.15).
 *
 * Giving a task to a colleague and finishing one somebody gave you are
 * hand-overs, and `/mywork-workflows` rule 4 is that **every hand-over
 * notifies the other party and bumps their My Work cache** — a workflow that
 * forgets the second half looks stuck for up to the cache TTL.
 *
 * The notification is the fallback, never the workflow: the row in My Work is
 * where the work is done, and this is what tells somebody a row appeared. So
 * there are exactly two subjects, both pointing at My Work, and nothing here
 * is sent for a task somebody wrote for themselves or for the whole team —
 * there is no other party to tell.
 *
 * Notifications are keyed on the team (`NOTIFY_OBJECT`), so
 * `TeamService::deleteTeam()`-style housekeeping can withdraw them all at once
 * if that is ever wanted; nothing withdraws them today, because a task
 * notification is about an event that happened rather than a state that could
 * stop being true.
 */
class TaskNotificationService {

    public const NOTIFY_OBJECT   = 'task';
    public const NOTIFY_ASSIGNED  = 'task_assigned';
    public const NOTIFY_COMPLETED = 'task_completed';

    public function __construct(
        private INotificationManager $notificationManager,
        private IUserManager $userManager,
        private ICacheFactory $cacheFactory,
        private ITimeFactory $timeFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * A colleague was given a task. Nothing is sent when somebody assigns a
     * task to themselves.
     */
    public function taskAssigned(
        string $assigneeUid,
        string $assignerUid,
        string $teamId,
        string $teamName,
        string $title,
        ?int $dueAt = null,
    ): void {
        if ($assigneeUid === '' || $assigneeUid === $assignerUid) {
            return;
        }

        $this->send($assigneeUid, self::NOTIFY_ASSIGNED, $teamId, [
            'teamId'      => $teamId,
            'teamName'    => $teamName,
            'taskTitle'   => $title,
            'actorUid'    => $assignerUid,
            'actorName'   => $this->displayName($assignerUid),
            'dueAt'       => $dueAt ?? 0,
        ]);
    }

    /** A task somebody else asked for was finished. */
    public function taskCompleted(
        string $organiserUid,
        string $completerUid,
        string $teamId,
        string $teamName,
        string $title,
    ): void {
        if ($organiserUid === '' || $organiserUid === $completerUid) {
            return;
        }

        $this->send($organiserUid, self::NOTIFY_COMPLETED, $teamId, [
            'teamId'    => $teamId,
            'teamName'  => $teamName,
            'taskTitle' => $title,
            'actorUid'  => $completerUid,
            'actorName' => $this->displayName($completerUid),
        ]);
    }

    /**
     * Notify, then make the recipient's My Work re-read.
     *
     * The cache bump happens even when the notification throws: the row is the
     * workflow and the bell is the fallback, so a failed bell must not leave
     * somebody looking at a stale queue.
     *
     * @param array<string,string|int> $params
     */
    private function send(string $uid, string $subject, string $teamId, array $params): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($uid)
                ->setDateTime(new \DateTime('@' . $this->timeFactory->getTime()))
                ->setObject(self::NOTIFY_OBJECT, $teamId)
                ->setSubject($subject, $params);
            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TaskNotification] notification failed', [
                'subject' => $subject, 'teamId' => $teamId, 'user' => $uid,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        MyWorkService::bumpNonce($this->cacheFactory, $uid);
    }

    private function displayName(string $uid): string {
        $user = $this->userManager->get($uid);
        $name = $user !== null ? trim((string)$user->getDisplayName()) : '';
        return $name !== '' ? $name : $uid;
    }
}
