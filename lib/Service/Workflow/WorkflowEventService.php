<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Workflow;

use OCA\TeamHub\Db\WorkflowEvent;
use OCA\TeamHub\Db\WorkflowEventMapper;
use OCA\TeamHub\Db\WorkflowStep;
use OCA\TeamHub\Workflow\WorkflowEventType;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Writes and reads the workflow event log (WorkflowHub phase 1, v4.10.13).
 *
 * Every state change `WorkflowEngine` makes goes through `record()` — one
 * event per change, with the actor and the moment — so the log is complete
 * by construction rather than by discipline. Readers get plain arrays.
 */
class WorkflowEventService {

    public function __construct(
        private WorkflowEventMapper $mapper,
        private ITimeFactory        $timeFactory,
    ) {
    }

    /**
     * Append one event. `$actorUid` is null for the engine's own passes.
     *
     * `$visibility` is `all` for everything but a service team's internal
     * note (v4.10.20) — the default is what every caller written before
     * phase 5 meant.
     *
     * @param array<string, mixed> $payload
     */
    public function record(
        int           $instanceId,
        string        $type,
        ?string       $actorUid,
        ?WorkflowStep $step = null,
        array         $payload = [],
        string        $visibility = WorkflowEventType::VISIBILITY_ALL,
        // v4.10.29 — when it happened, for the one writer that records the
        // past: the import of the ledger's quota requests. Null is now.
        ?int          $occurredAt = null,
    ): WorkflowEvent {
        if (!WorkflowEventType::isValid($type)) {
            throw new \InvalidArgumentException('Unknown workflow event type: ' . $type);
        }
        if (!WorkflowEventType::isValidVisibility($visibility)) {
            throw new \InvalidArgumentException('Unknown workflow event visibility: ' . $visibility);
        }
        $event = new WorkflowEvent();
        $event->setInstanceId($instanceId);
        $event->setStepId($step?->getId());
        $event->setStepKey($step?->getStepKey());
        $event->setEventType($type);
        $event->setActorUid($actorUid);
        $event->setOccurredAt($occurredAt ?? $this->timeFactory->getTime());
        $event->setPayload($payload);
        $event->setVisibility($visibility);
        return $this->mapper->insert($event);
    }

    /**
     * Remove the whole log of one instance (phase 4, v4.10.16) — the one
     * write that is not an append, and only ever part of
     * `WorkflowEngine::purge()` on the unlicensed tier.
     */
    public function deleteForInstance(int $instanceId): void {
        $this->mapper->deleteByInstance($instanceId);
    }

    /**
     * The instance's history, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForInstance(int $instanceId): array {
        return array_map([$this, 'toArray'], $this->mapper->findByInstance($instanceId));
    }

    /**
     * When each of these instances last had a status update asked for by
     * this person, per step, within `$since` (v4.10.17) — one query for a
     * whole list, so the view can say whether the viewer may ask again
     * without a query per row.
     *
     * @param int[] $instanceIds
     * @return array<int, array<string, int>> instanceId → stepKey → occurredAt
     */
    public function recentStatusRequests(array $instanceIds, string $uid, int $since): array {
        return $this->mapper->findOwnEventsSince($instanceIds, $uid, WorkflowEventType::STATUS_REQUESTED, $since);
    }

    /** @return array<string, mixed> */
    public function toArray(WorkflowEvent $event): array {
        return [
            'id'         => $event->getId(),
            'instanceId' => $event->getInstanceId(),
            'stepId'     => $event->getStepId(),
            'stepKey'    => $event->getStepKey(),
            'type'       => $event->getEventType(),
            'actorUid'   => $event->getActorUid(),
            'occurredAt' => $event->getOccurredAt(),
            'payload'    => $event->getPayload(),
            'visibility' => $event->getVisibility(),
        ];
    }
}
