<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One row of `teamhub_wf_event` (WorkflowHub phase 1, v4.10.13).
 *
 * Append-only. `eventType` is one of
 * `OCA\TeamHub\Workflow\WorkflowEventType`; `stepId` / `stepKey` name the
 * step when the event is about one; `actorUid` is null for the engine's
 * own passes; `payloadJson` carries what the event needs to be read back
 * (a reason, a note, the definition version). `visibility` (v4.10.20)
 * separates what the requester reads from what the service team keeps.
 *
 * @method int     getInstanceId()
 * @method void    setInstanceId(int $v)
 * @method ?int    getStepId()
 * @method void    setStepId(?int $v)
 * @method ?string getStepKey()
 * @method void    setStepKey(?string $v)
 * @method string  getEventType()
 * @method void    setEventType(string $v)
 * @method ?string getActorUid()
 * @method void    setActorUid(?string $v)
 * @method int     getOccurredAt()
 * @method void    setOccurredAt(int $v)
 * @method ?string getPayloadJson()
 * @method void    setPayloadJson(?string $v)
 * @method string  getVisibility()
 * @method void    setVisibility(string $v)
 */
class WorkflowEvent extends Entity {

    protected int     $instanceId  = 0;
    protected ?int    $stepId      = null;
    protected ?string $stepKey     = null;
    protected string  $eventType   = '';
    protected ?string $actorUid    = null;
    protected int     $occurredAt  = 0;
    protected ?string $payloadJson = null;
    /**
     * v4.10.20 — `all` or `internal`. An internal event is the service
     * team's own note; `WorkflowEngine::listEvents()` drops it for anybody
     * who is not an eligible agent of the team handling the workflow. The
     * row is written the same way either way: the licence and the reader
     * decide what is shown, never what was recorded.
     */
    protected string  $visibility  = 'all';

    public function __construct() {
        $this->addType('instanceId', 'integer');
        $this->addType('stepId',     'integer');
        $this->addType('occurredAt', 'integer');
    }

    /** @return array<string, mixed> */
    public function getPayload(): array {
        $raw = $this->getPayloadJson();
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $payload */
    public function setPayload(array $payload): void {
        $this->setPayloadJson($payload === [] ? null : (json_encode($payload, JSON_UNESCAPED_UNICODE) ?: null));
    }
}
