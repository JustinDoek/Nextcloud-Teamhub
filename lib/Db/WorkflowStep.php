<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One row of `teamhub_wf_step` (WorkflowHub phase 1, v4.10.13).
 *
 * A step of one instance, materialised from the definition at creation:
 * key, order, label and responsible actor are copies, so the instance no
 * longer depends on the definition class. `stepStatus` is one of
 * `OCA\TeamHub\Workflow\WorkflowStepStatus`, written only by the engine.
 * `assignee` (v4.10.20) is the service agent who claimed the step, '' while
 * nobody has.
 *
 * @method int     getInstanceId()
 * @method void    setInstanceId(int $v)
 * @method string  getStepKey()
 * @method void    setStepKey(string $v)
 * @method int     getStepOrder()
 * @method void    setStepOrder(int $v)
 * @method string  getStepStatus()
 * @method void    setStepStatus(string $v)
 * @method string  getActorType()
 * @method void    setActorType(string $v)
 * @method string  getActorId()
 * @method void    setActorId(string $v)
 * @method string  getLabel()
 * @method void    setLabel(string $v)
 * @method ?int    getEnteredAt()
 * @method void    setEnteredAt(?int $v)
 * @method ?int    getStartedAt()
 * @method void    setStartedAt(?int $v)
 * @method ?int    getCompletedAt()
 * @method void    setCompletedAt(?int $v)
 * @method ?string getCompletedBy()
 * @method void    setCompletedBy(?string $v)
 * @method ?string getActionTaken()
 * @method void    setActionTaken(?string $v)
 * @method ?string getReason()
 * @method void    setReason(?string $v)
 * @method void    setAssignee(?string $v)
 * @method void    setRoleLabel(?string $v)
 * @method ?string getLinks()
 * @method void    setLinks(?string $v)
 * @method void    setStageLabel(?string $v)
 * @method void    setNonBlocking(?int $v)
 */
class WorkflowStep extends Entity {

    protected int     $instanceId  = 0;
    protected string  $stepKey     = '';
    protected int     $stepOrder   = 0;
    protected string  $stepStatus  = '';
    protected string  $actorType   = '';
    protected string  $actorId     = '';
    protected string  $label       = '';
    protected ?int    $enteredAt   = null;
    protected ?int    $startedAt   = null;
    protected ?int    $completedAt = null;
    protected ?string $completedBy = null;
    protected ?string $actionTaken = null;
    protected ?string $reason      = null;
    /**
     * v4.10.20 — the agent who claimed this step, '' while it is in the
     * queue. Only a step whose actor is a service team ever carries one: the
     * actor says *who may* act, the assignee says *who has taken it on*, and
     * narrowing one to the other is what a queue is. Cleared by a release,
     * replaced by an assignment.
     *
     * Nullable in the schema, never null in the application (v4.10.21): the
     * column had to be added to an existing table, and Nextcloud refuses a
     * NOT NULL column with an empty-string default there because '' and NULL
     * are one value on Oracle. `getAssignee()` below is what keeps that
     * database detail out of the engine — every read goes through it, and it
     * answers '' for a step nobody has taken.
     */
    protected ?string $assignee    = '';
    /**
     * v4.10.31 — the role a desk step needs, as a service team typed it in
     * the service builder ("Functional admin"). A label, not a permission:
     * any member of the service team may claim the step. '' for every step
     * of a built-in workflow. Nullable in the schema for the same reason as
     * `assignee`; `getRoleLabel()` answers '' for NULL.
     */
    protected ?string $roleLabel   = '';
    /**
     * v4.10.36 — the step's links as the service builder wrote them, a JSON
     * list of `{label, url, kind}`; NULL for a step without any. Read through
     * `linkList()`, never raw.
     */
    protected ?string $links       = null;
    /**
     * v4.10.37 — the step's own name when it holds several tasks (this row's
     * `label` is then the task's); '' otherwise. See `getStageLabel()`.
     */
    protected ?string $stageLabel  = '';
    /** v4.10.37 — 1: the request may move on while this task is open. */
    protected ?int    $nonBlocking = 0;

    public function __construct() {
        $this->addType('instanceId',  'integer');
        $this->addType('stepOrder',   'integer');
        $this->addType('enteredAt',   'integer');
        $this->addType('startedAt',   'integer');
        $this->addType('completedAt', 'integer');
        $this->addType('nonBlocking', 'integer');
    }

    /**
     * The agent who has this step, or '' when nobody has (v4.10.21).
     *
     * A real method rather than the `@method` magic, so the one place the
     * column's nullability could leak into the engine is closed here. The
     * engine compares the answer against '' in half a dozen places — "is it
     * claimed", "is it mine", "may I act on it" — and a NULL that reached
     * those comparisons would read as a third state that is neither
     * unclaimed nor claimed.
     */
    public function getAssignee(): string {
        return $this->assignee ?? '';
    }

    /** The step's own name when it holds several tasks, or '' (v4.10.37). */
    public function getStageLabel(): string {
        return $this->stageLabel ?? '';
    }

    /** Whether the request may move on while this task is still open (v4.10.37). */
    public function isNonBlocking(): bool {
        return (int)($this->nonBlocking ?? 0) === 1;
    }

    /** The role the step needs, or '' (v4.10.31). */
    public function getRoleLabel(): string {
        return $this->roleLabel ?? '';
    }

    /**
     * The step's links (v4.10.36), `[]` when it has none. Only entries with
     * an `https://` address come back: the builder never stores another, and
     * a row is not trusted to prove it.
     *
     * v4.10.39 — **not** `getLinks()`: `QBMapper` writes a row through the
     * entity's own getters, so a `getLinks()` answering an array stored the
     * string "Array" in the column (found on the instance, 2026-09-25). The
     * magic `getLinks()` stays the raw JSON the mapper needs.
     *
     * @return array<int, array{label: string, url: string, kind: string}>
     */
    public function linkList(): array {
        if ($this->links === null || $this->links === '') {
            return [];
        }
        $decoded = json_decode($this->links, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $link) {
            $url = is_array($link) ? (string)($link['url'] ?? '') : '';
            if (!str_starts_with($url, 'https://')) {
                continue;
            }
            $out[] = [
                'label' => (string)($link['label'] ?? ''),
                'url'   => $url,
                'kind'  => (string)($link['kind'] ?? 'link'),
            ];
        }
        return $out;
    }
}
