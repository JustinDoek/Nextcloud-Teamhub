<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow;

/**
 * Which of the engine's generic verbs a definition does **not** offer
 * (v4.11.41). Optional; a definition that does not implement it offers all
 * of them, which is every definition but one.
 *
 * ## Why this exists
 *
 * The engine's verbs are deliberately generic — `/mywork-workflows` §
 * *Frontend: nothing per workflow*, and that is worth keeping. But two of
 * them assume something `IdpGroupAdoptionDefinition` does not have: a
 * **person who asked**. TeamHub opens that request itself about a Nextcloud
 * group, with `PolicyService::ACTOR_SYSTEM` as the initiator, and a group
 * has no owner. So:
 *
 *   - **Ask for information** notifies `$instance->getStartedBy()`
 *     ({@see \OCA\TeamHub\Service\Workflow\WorkflowNotificationService::informationRequested()}),
 *     which here is `app:teamhub` — no account, no bell, nobody to answer.
 *     It still moves the step to `waiting_for_information`, and
 *     `provideInformation()` admits participants only, so the task would be
 *     **blocked for good** by a button that looks ordinary. Justin,
 *     2026-10-10: *we have nobody to ask the information.*
 *   - **Withdraw request** ends the instance as cancelled, and this
 *     definition's `onEnded()` deliberately decides nothing on a
 *     cancellation — so the row stays `pending` and the next
 *     `IdpGroupRequestService::reconcile()` opens the task again an hour
 *     later. Justin: *withdraw request is the same as deny* — in fact worse,
 *     a no-op that comes back. Deny is `reject`, which decides.
 *
 * Hiding them in the client would not have been enough: *Ask for
 * information* has a lasting effect, and "the frontend won't call this" is
 * not a boundary (`CLAUDE.md`). The engine refuses both, and the view's
 * `viewer` block reports them as unavailable so no button is drawn.
 *
 * ## Withdrawal still has to work for TeamHub itself
 *
 * `reconcile()` and the grid's decisions cancel the request to take the task
 * off an administrator's list. That is the same verb, so the refusal is
 * scoped to a caller who is **not the initiator**: TeamHub cancelling its
 * own request is allowed, a person pressing a button is not. Which is why
 * `IdpGroupRequestService::withdraw()` always acts as the system actor.
 */
interface IWorkflowDefinitionActionPolicy {

    /**
     * Whether a holder may ask the initiator for information.
     *
     * False needs an initiator nobody can reach; it has nothing to do with
     * whether the definition is licensed or who holds the step.
     */
    public function allowsInformationRequest(): bool;

    /**
     * Whether a **person** may withdraw the request.
     *
     * False does not stop the definition's own owner service from
     * cancelling: the engine allows a cancellation whose caller is the
     * instance's initiator, so TeamHub can still retire its own request.
     */
    public function allowsWithdrawal(): bool;
}
