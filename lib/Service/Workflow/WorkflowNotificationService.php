<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Workflow;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\WorkflowInstance;
use OCA\TeamHub\Db\WorkflowStep;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * The bell for workflows (WorkflowHub phase 2, v4.10.14).
 *
 * Sends and withdraws Nextcloud notifications about workflow instances;
 * `Notifier.php` renders them per recipient language. Recipients are
 * always resolved here, server-side, from the step's actor
 * (`WorkflowActorResolver::holdersOf()`) or the participant rows — never
 * from anything a client sent. The person who caused the event is never
 * told about their own action.
 *
 * Every notification of one instance shares object type `workflow` and
 * the instance id, so `withdraw()` clears the bell for everybody when a
 * step moves on or the workflow ends. A status request is the one subject
 * that is *not* withdrawn by the next step — it is answered by the step
 * moving, which is what the requester wanted to see.
 *
 * Called by `WorkflowEngine` after its transaction has committed; a
 * notification about a state that was rolled back would be a lie.
 *
 * ## What a notification may carry (phase 4, v4.10.16)
 *
 * On an unlicensed instance the workflow's rows are deleted the moment it
 * ends, and the ending notification is the one thing that outlives it —
 * it sits in the recipient's bell until they dismiss it. So the payload
 * is deliberately small: the definition key, the instance id, the team,
 * the step's label, who acted, the note or reason the event is *about*,
 * and `getNotificationData()` — the fields the title needs, not the
 * submission. See `docs/unlicensed-workflow-data-lifecycle.md`.
 *
 * Every earlier notification about the instance is withdrawn when the
 * workflow ends (`WorkflowEngine::notifyEnded()` withdraws before
 * sending), so after a purge at most one notification per participant
 * remains.
 */
class WorkflowNotificationService {

    public const OBJECT_TYPE = 'workflow';

    public const SUBJECT_STEP_AVAILABLE        = 'workflow_step_available';
    public const SUBJECT_STATUS_REQUESTED      = 'workflow_status_requested';
    public const SUBJECT_INFORMATION_REQUESTED = 'workflow_information_requested';
    public const SUBJECT_INFORMATION_PROVIDED  = 'workflow_information_provided';
    public const SUBJECT_ENDED                 = 'workflow_ended';
    /** v4.10.20 - a service agent handed a request to somebody in particular. */
    public const SUBJECT_STEP_ASSIGNED         = 'workflow_step_assigned';
    /** v4.11.0 - the other side of a service request wrote a message. */
    public const SUBJECT_MESSAGE               = 'workflow_message';

    public function __construct(
        private INotificationManager      $notifications,
        private WorkflowActorResolver     $resolver,
        private WorkflowDefinitionRegistry $definitions,
        private IUserManager              $userManager,
        private ITimeFactory              $timeFactory,
        private LoggerInterface           $logger,
    ) {
    }

    /** The holders of a step that just became available, minus whoever made it so. */
    public function stepAvailable(WorkflowInstance $instance, WorkflowStep $step, ?string $causedBy): void {
        $this->sendTo(
            $this->holders($step, $instance),
            self::SUBJECT_STEP_AVAILABLE,
            $instance,
            $step,
            $causedBy,
            [],
        );
    }

    /**
     * One agent: a request was handed to you (v4.10.20).
     *
     * Its own subject rather than "a step is waiting for you", because the
     * two are different facts. A step becoming available is the workflow
     * moving; an assignment is a colleague deciding this one is yours, and
     * the recipient needs to know which of the two happened to know whether
     * to answer the person who did it.
     */
    public function stepAssigned(WorkflowInstance $instance, WorkflowStep $step, string $targetUid, string $assignedBy): void {
        $this->sendTo(
            [$targetUid],
            self::SUBJECT_STEP_ASSIGNED,
            $instance,
            $step,
            $assignedBy,
            [],
        );
    }

    /** The holders of the active step: somebody asked where things stand. */
    public function statusRequested(WorkflowInstance $instance, WorkflowStep $step, string $requestedBy, ?string $note): void {
        $this->sendTo(
            $this->holders($step, $instance),
            self::SUBJECT_STATUS_REQUESTED,
            $instance,
            $step,
            $requestedBy,
            ['note' => $note ?? ''],
        );
    }

    /** The initiator: the active step's actor needs something. */
    public function informationRequested(WorkflowInstance $instance, WorkflowStep $step, string $requestedBy, string $note): void {
        $this->sendTo(
            [$instance->getStartedBy()],
            self::SUBJECT_INFORMATION_REQUESTED,
            $instance,
            $step,
            $requestedBy,
            ['note' => $note],
        );
    }

    /** The holders of the active step: the answer came. */
    public function informationProvided(WorkflowInstance $instance, WorkflowStep $step, string $providedBy, string $note): void {
        $this->sendTo(
            $this->holders($step, $instance),
            self::SUBJECT_INFORMATION_PROVIDED,
            $instance,
            $step,
            $providedBy,
            ['note' => $note],
        );
    }

    /**
     * The other side of a service request's conversation (v4.11.0). The
     * engine decides who that is (`WorkflowEngine::postMessage()`).
     *
     * One message notification per recipient per request: the recipient's
     * earlier one about this request is withdrawn first, so a conversation
     * of ten messages is one entry in the bell, showing the latest.
     *
     * @param string[] $recipients
     */
    public function messagePosted(WorkflowInstance $instance, ?WorkflowStep $step, array $recipients, string $postedBy, string $note): void {
        foreach (array_unique($recipients) as $uid) {
            if ($uid === '' || $uid === $postedBy) {
                continue;
            }
            try {
                $n = $this->notifications->createNotification();
                $n->setApp(Application::APP_ID)
                    ->setUser($uid)
                    ->setObject(self::OBJECT_TYPE, (string)$instance->getId())
                    ->setSubject(self::SUBJECT_MESSAGE);
                $this->notifications->markProcessed($n);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][Workflow] message notification withdrawal failed', [
                    'instance' => $instance->getId(), 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }
        $this->sendTo($recipients, self::SUBJECT_MESSAGE, $instance, $step, $postedBy, ['note' => $note]);
    }

    /**
     * Everybody who took part, by name: the workflow ended.
     *
     * @param string[] $userParticipants uids of the `user` participant rows
     */
    public function ended(WorkflowInstance $instance, array $userParticipants, string $endedBy, ?string $reason): void {
        $recipients = array_unique(array_merge([$instance->getStartedBy()], $userParticipants));
        $this->sendTo(
            $recipients,
            self::SUBJECT_ENDED,
            $instance,
            null,
            $endedBy,
            ['outcome' => (string)$instance->getOutcome(), 'note' => $reason ?? ''],
        );
    }

    /** Clear every notification about the instance, for everybody. */
    public function withdraw(int $instanceId): void {
        try {
            $n = $this->notifications->createNotification();
            $n->setApp(Application::APP_ID)->setObject(self::OBJECT_TYPE, (string)$instanceId);
            $this->notifications->markProcessed($n);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][Workflow] notification withdrawal failed', [
                'instance' => $instanceId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * The subset of the instance's data a notification may carry — the
     * definition decides (`getNotificationData()`); an unknown definition
     * carries nothing rather than everything.
     *
     * @return array<string, mixed>
     */
    private function notificationData(WorkflowInstance $instance): array {
        $definition = $this->definitions->get($instance->getDefinitionKey());
        if ($definition === null) {
            return [];
        }
        try {
            return $definition->getNotificationData($instance->getData());
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][Workflow] notification data could not be built', [
                'instance' => $instance->getId(), 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    /** @return string[] */
    private function holders(WorkflowStep $step, WorkflowInstance $instance): array {
        try {
            return $this->resolver->holdersOf(WorkflowActor::of($step->getActorType(), $step->getActorId()), $instance->getTeamId());
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][Workflow] could not resolve step holders', [
                'instance' => $instance->getId(), 'step' => $step->getStepKey(), 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * @param string[] $recipients
     * @param array<string, string> $extra
     */
    private function sendTo(array $recipients, string $subject, WorkflowInstance $instance, ?WorkflowStep $step, ?string $causedBy, array $extra): void {
        $actorName = $causedBy !== null ? ($this->userManager->get($causedBy)?->getDisplayName() ?? $causedBy) : '';
        $params = $extra + [
            'instanceId'    => (int)$instance->getId(),
            'definitionKey' => $instance->getDefinitionKey(),
            'teamId'        => $instance->getTeamId(),
            'stepKey'       => $step?->getStepKey() ?? '',
            'stepLabel'     => $step?->getLabel() ?? '',
            'actorUid'      => $causedBy ?? '',
            'actorName'     => $actorName,
            // Phase 4 (v4.10.16): the title's data, not the submission. A
            // notification row is the one copy of workflow content that
            // outlives the workflow on an unlicensed instance — it stays in
            // the recipient's bell until they dismiss it — so it carries
            // what `getTitle()` renders and nothing else. The reason, the
            // requested size and any other field stay with the instance and
            // go when it goes.
            'data'          => $this->notificationData($instance),
        ];
        $now = $this->timeFactory->getDateTime();
        foreach (array_unique($recipients) as $uid) {
            if ($uid === '' || $uid === $causedBy) {
                continue;
            }
            try {
                $n = $this->notifications->createNotification();
                $n->setApp(Application::APP_ID)
                    ->setUser($uid)
                    ->setDateTime($now)
                    ->setObject(self::OBJECT_TYPE, (string)$instance->getId())
                    ->setSubject($subject, $params);
                $this->notifications->notify($n);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][Workflow] notification failed', [
                    'subject' => $subject, 'instance' => $instance->getId(), 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }
    }
}
