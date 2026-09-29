<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow;

use OCA\TeamHub\Db\WorkflowInstance;
use OCP\IL10N;

/**
 * What a built-in definition may add to the engine's fixed lifecycle
 * (v4.10.29). Optional: a definition that does not implement it is handled
 * exactly as before, which is every definition but the quota request.
 *
 * It exists for the first definition whose answer *does* something. A
 * service request carries a question and an answer; the desk does the work
 * with its own tools and completes the step. The quota request is
 * different — granting it is the act, and a desk member who pressed
 * *Grant* and then had to set the quota somewhere else by hand would be
 * doing the work twice, with a window in which the workflow says granted
 * and the team space says otherwise.
 *
 * Three hooks, each called by `WorkflowEngine` at one fixed point:
 *
 *   - `validateForTeam()` — at `create()`, after `validateStart()`, with
 *     the team the request is about. The place for a check the payload
 *     alone cannot answer ("this team has a space", "the size is an
 *     increase") and for facts the server records rather than the client
 *     supplies (the current quota). Outside the transaction; a refusal is a
 *     `ValidationException` (400) or a `WorkflowTransitionException` (409).
 *   - `onStepCompleted()` — inside the transaction that completes a step,
 *     **before** any row is written. A throw rolls the completion back, so
 *     a grant whose side effect failed is still waiting to be granted. The
 *     side effect itself is outside the database and is not rolled back if
 *     something *after* it fails; it must therefore be idempotent (setting
 *     a quota to a value is).
 *   - `getActionLabels()` — the active step's own words for the engine's
 *     verbs (*Grant* instead of *Complete step*), in the viewer's language.
 *     The verbs themselves never change: a label is words, not a new action
 *     (`/mywork-workflows` rule 2).
 *
 * Not a place for branching, conditions or extra steps — product rules
 * 14–15 stand. A hook acts on the one step the engine is already moving.
 */
interface IWorkflowDefinitionHooks {

    /**
     * Check the start against the team and return the data to store. Called
     * with the output of `validateStart()`.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws \OCA\TeamHub\Exception\ValidationException
     * @throws \OCA\TeamHub\Exception\WorkflowTransitionException
     */
    public function validateForTeam(string $teamId, array $data): array;

    /**
     * A step of an instance of this definition is being completed by `$uid`.
     * Inside the transaction; throw to refuse the completion.
     */
    public function onStepCompleted(WorkflowInstance $instance, string $stepKey, string $uid): void;

    /**
     * The words for `complete` and `reject` on one step, or an empty array
     * for the engine's own.
     *
     * @return array<string, string> action → label; only `complete` and `reject` are read
     */
    public function getActionLabels(IL10N $l, string $stepKey): array;
}
