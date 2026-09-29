<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow;

use OCA\TeamHub\Service\Workflow\WorkflowActorResolver;
use OCP\IL10N;

/**
 * A built-in workflow (WorkflowHub phase 1, v4.10.13;
 * `docs/workflowhub-architecture.md` §5).
 *
 * A definition is a PHP class, registered in `WorkflowDefinitionRegistry`
 * from `Application.php` the way My Work providers are. It describes the
 * *fixed* shape of one kind of workflow — its ordered steps and who is
 * responsible for each — and the rules for opening one. It holds no state
 * and touches no table: `WorkflowEngine` is the only writer, and it reads
 * the definition once, at `create()`, to materialise the steps.
 *
 * ## Versioning
 *
 * `getVersion()` is an integer the definition bumps whenever its steps
 * change. The engine stamps it on the instance (`definition_version`) and
 * copies the steps into `teamhub_wf_step`, so an instance started on
 * version 1 runs to its end on version 1 even after version 2 ships. A
 * definition therefore never needs to keep old step lists around; it only
 * needs to keep answering `getKey()` for as long as instances of it exist.
 *
 * ## Steps
 *
 * Sequential only in this phase — the list order is the execution order,
 * exactly one step is active at a time, completing a step activates the
 * next, completing the last completes the workflow. No branches, no
 * parallel steps, no conditions (product rules 14–15).
 *
 * ## What a definition does not decide
 *
 * Who may *act* on a step — that is the step's actor, resolved live by
 * `WorkflowActorResolver`. Who may *cancel* — the initiator or a Nextcloud
 * administrator, engine-wide. Whether the instance is licensed for it —
 * next phase, on the instance's tier.
 */
interface IWorkflowDefinition {

    /** Stable machine key, e.g. `teamspace_quota`. Persisted; never changes once shipped. */
    public function getKey(): string;

    /** Bumped whenever `getSteps()` changes shape. Stamped on every instance at creation. */
    public function getVersion(): int;

    /** Developer-facing name, for logs and diagnostics. */
    public function getName(): string;

    /**
     * The workflow's title for a person, in their language — "New team
     * Marketing", "Team space quota increase to 20 GB". Built from the
     * instance's validated data. Used in notifications (per recipient) and
     * in the API view (the viewer).
     *
     * @param array<string, mixed> $data the instance's data
     */
    public function getTitle(IL10N $l, array $data): string;

    /**
     * A sentence or two about this instance for the detail view, in the
     * person's language — the reason given, the size asked for. Empty when
     * the title says it all.
     *
     * @param array<string, mixed> $data the instance's data
     */
    public function getDescription(IL10N $l, array $data): string;

    /**
     * A step's label for a person, in their language. Unknown keys — a
     * step of an older version this definition no longer lists — fall back
     * to the label snapshotted on the step row, which the caller has.
     */
    public function getStepLabel(IL10N $l, string $stepKey): ?string;

    /**
     * The steps, in execution order. At least one. Keys unique within the
     * definition.
     *
     * @return WorkflowStepDefinition[]
     */
    public function getSteps(): array;

    /**
     * Fill in a step actor the definition could not name at write time
     * (WorkflowHub phase 5, v4.10.20).
     *
     * A step whose actor is a *placeholder* — today only
     * `WorkflowActor::serviceAgentPlaceholder()` — is resolved here, once,
     * while `WorkflowEngine::create()` materialises the steps. The engine
     * refuses to open the workflow when this returns null for a placeholder,
     * because a step nobody holds is a queue nobody can work.
     *
     * Returning null for anything else is the normal answer: a definition
     * that names concrete actors never implements this beyond `return null`.
     * The engine only asks about placeholders, so this cannot be used to
     * rewrite an actor the definition already declared.
     *
     * @param array<string, mixed> $data the validated opening payload
     */
    public function resolveActor(WorkflowStepDefinition $step, string $teamId, array $data): ?WorkflowActor;

    /**
     * How many open instances a team may have: `one_open_per_team` (a
     * second `create()` while one is open is refused) or `unbounded`.
     */
    public function getConcurrency(): string;

    /**
     * May this user open an instance on this team? The engine has not yet
     * checked anything about the user; this is the whole gate for creation.
     * The resolver answers role questions (team level, group membership).
     */
    public function canStart(string $uid, string $teamId, WorkflowActorResolver $resolver): bool;

    /**
     * Validate and normalise the opening payload. Returns what will be
     * stored as the instance's `data`; throws
     * `OCA\TeamHub\Exception\ValidationException` on bad input.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validateStart(array $data): array;

    /**
     * What the workflow is about, as (type, id) — the team space, a file,
     * the team itself. Read from the validated data; `('team', $teamId)`
     * when there is nothing more specific.
     *
     * @param array<string, mixed> $data the validated payload
     * @return array{0: string, 1: string}
     */
    public function subjectOf(string $teamId, array $data): array;

    /**
     * May this definition be opened at all on this instance (v4.10.17)?
     *
     * A definition answering false is **registered but dark**: it is not
     * listed by `describeDefinitions()` and `create()` refuses it with a
     * 404, exactly as if it did not exist. Reading is untouched — the
     * registry still resolves it, so instances that already exist keep
     * their title, step labels and notification data.
     *
     * This is the feature switch of `docs/workflowhub-architecture.md` §11
     * step 2: a definition whose shape ships before the service behind it
     * is wired stays dark until the wiring session flips it on, and
     * flipping it back off is the rollback. Answer true unless there is a
     * reason not to.
     */
    public function isStartable(): bool;

    /**
     * May this workflow be *started* on an unlicensed instance (phase 4,
     * v4.10.16)? The built-in workflows explicitly allowed for unlicensed
     * use answer true. A definition that answers false is not listed and
     * cannot be started while the instance is unlicensed — but an
     * instance of it that was started while licensed always runs to its
     * end (the licence-transition rules in
     * `docs/unlicensed-workflow-data-lifecycle.md`).
     */
    public function allowsUnlicensedUse(): bool;

    /**
     * The part of the instance's data a notification may carry (phase 4,
     * v4.10.16) — what `getTitle()` needs to render the workflow's name in
     * the recipient's language, and nothing more. A notification row
     * outlives the workflow on an unlicensed instance (it stays until the
     * recipient dismisses it), so the whole submission must not travel
     * with it: the team name, not the reason.
     *
     * @param array<string, mixed> $data the instance's data
     * @return array<string, mixed>
     */
    public function getNotificationData(array $data): array;
}
