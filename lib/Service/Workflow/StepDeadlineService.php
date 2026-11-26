<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Workflow;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\WorkflowInstanceMapper;
use OCA\TeamHub\Db\WorkflowStep;
use OCA\TeamHub\Db\WorkflowStepMapper;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowEventType;
use OCA\TeamHub\Workflow\WorkflowStatus;
use OCA\TeamHub\Workflow\WorkflowStepStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Deadlines on the steps of a service (v4.20.68, phase 8j;
 * `docs/service-builder.md` § 14.6).
 *
 * A step may say *within so many working days*. When the request reaches it,
 * the engine stamps the moment it is due on the row (`config.dueAt`): the end
 * of the last of those days, Monday to Friday, in the server's time zone.
 * Weekends do not count; public holidays are not known to Nextcloud and do.
 *
 * Once a day `RemindOverdueStepsJob` reminds whoever has an overdue step,
 * **once** (`config.reminded`). Silence is never consent: a deadline reminds,
 * it never decides. A step waiting for the requester's answer is not reminded
 * about; it is not in the holder's hands.
 */
class StepDeadlineService {

    public const MAX_DAYS = 60;

    public function __construct(
        private WorkflowStepMapper          $steps,
        private WorkflowInstanceMapper      $instances,
        private WorkflowActorResolver       $resolver,
        private WorkflowNotificationService $notifier,
        private WorkflowEventService        $events,
        private ServiceTeamService          $serviceTeams,
        private IConfig                     $config,
        private ITimeFactory                $timeFactory,
        private LoggerInterface             $logger,
    ) {
    }

    /** The end of the `$days`-th working day after `$from`, in the server's time zone. */
    public function dueAt(int $from, int $days): int {
        return self::endOfWorkingDays($from, $days, $this->timeZone());
    }

    /**
     * The day a deadline falls on, `Y-m-d`, in the server's time zone
     * (v4.20.69). A deadline is a date: shown as a moment, it moves to the
     * next day for anybody east of the server (found on the instance).
     */
    public function dueDate(int $dueAt): string {
        return (new \DateTimeImmutable('@' . $dueAt))->setTimezone($this->timeZone())->format('Y-m-d');
    }

    /**
     * Monday to Friday. Counting starts the day after `$from`; a step reached
     * on a Friday afternoon with one working day is due Monday evening.
     */
    public static function endOfWorkingDays(int $from, int $days, \DateTimeZone $zone): int {
        $day  = (new \DateTimeImmutable('@' . $from))->setTimezone($zone)->setTime(23, 59, 59);
        $left = max(1, $days);
        while ($left > 0) {
            $day = $day->modify('+1 day');
            if ((int)$day->format('N') <= 5) {
                $left--;
            }
        }
        return $day->getTimestamp();
    }

    /** Stamp the deadline on a row the request has just reached, when its step has one. */
    public function stamp(WorkflowStep $row, int $now): void {
        $config = $row->configMap();
        $days   = (int)($config['dueDays'] ?? 0);
        if ($days < 1 || isset($config['dueAt'])) {
            return;
        }
        $dueAt = $this->dueAt($now, min($days, self::MAX_DAYS));
        $row->mergeConfig(['dueAt' => $dueAt, 'dueDate' => $this->dueDate($dueAt)]);
    }

    /**
     * Remind whoever has an overdue step, once. Returns how many steps.
     */
    public function remindOverdue(): int {
        if (!$this->serviceTeams->isAvailable()) {
            return 0;
        }
        $now  = $this->timeFactory->getTime();
        $done = 0;
        foreach ($this->steps->findActiveWithConfig('"dueAt"') as $row) {
            try {
                $done += $this->remind($row, $now) ? 1 : 0;
            } catch (\Throwable $e) {
                // One step that cannot be reminded about must not hold the rest.
                $this->logger->error('[TeamHub][Workflow] an overdue step could not be reminded about', [
                    'step' => $row->getId(), 'exception' => $e, 'app' => Application::APP_ID,
                ]);
            }
        }
        return $done;
    }

    private function remind(WorkflowStep $row, int $now): bool {
        $config = $row->configMap();
        if (!empty($config['reminded']) || (int)($config['dueAt'] ?? PHP_INT_MAX) > $now
            || !in_array($row->getStepStatus(), [WorkflowStepStatus::AVAILABLE, WorkflowStepStatus::IN_PROGRESS], true)) {
            return false;
        }
        $instance = $this->instances->findById($row->getInstanceId());
        if ($instance === null || !WorkflowStatus::isOpen($instance->getStatus()) || $instance->getStatus() === WorkflowStatus::BLOCKED) {
            return false;
        }
        $uids = $row->getAssignee() !== ''
            ? [$row->getAssignee()]
            : $this->resolver->holdersOf(WorkflowActor::of($row->getActorType(), $row->getActorId()), $instance->getTeamId());
        if ($row->isApproval()) {
            $uids = array_values(array_filter($uids, static fn (string $uid): bool => $uid !== $instance->getStartedBy()));
        }
        $row->mergeConfig(['reminded' => true]);
        $this->steps->update($row);
        $this->events->record((int)$instance->getId(), WorkflowEventType::STEP_OVERDUE, null, $row,
            ['dueAt' => (int)$config['dueAt'], 'uids' => array_values(array_unique($uids))], WorkflowEventType::VISIBILITY_INTERNAL);
        $this->notifier->stepOverdue($instance, $row, $uids);
        return true;
    }

    private function timeZone(): \DateTimeZone {
        try {
            return new \DateTimeZone((string)$this->config->getSystemValue('default_timezone', 'UTC') ?: 'UTC');
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }
}
