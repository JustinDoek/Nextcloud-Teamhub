<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning\Step;

use OCA\TeamHub\Service\Provisioning\ProvisioningContext;
use OCA\TeamHub\Service\Provisioning\StepResult;

/**
 * One step of a provisioning operation (v4.9.6, Phase 2).
 *
 * ## The contract every step keeps
 *
 * - **Idempotent.** `run()` may be called again after it threw, after the
 *   request died halfway, or after the runner recorded nothing. A step
 *   looks for what it may already have made (a registry row, a project with
 *   the reserved identifier, a job id it stored) before making it again.
 * - **Reports, never swallows.** A partial success is `attention` with the
 *   particulars in `detail`; a failure is `failed` with a code and a
 *   sentence, and `retrySafe` says whether running again is sound.
 * - **Runs as the creator.** The session user is the operation's creator
 *   (the pump is their request; the background job impersonates them under
 *   the same re-checks the import job makes). Nothing in a step decides
 *   *who* — it asks `$ctx->userId`.
 * - **Rollback is per resource.** `rollback()` is asked only for a step
 *   that completed or needs attention, and only by the rollback path, which
 *   has already verified who is asking. A step that linked an existing
 *   resource removes the *link* and never the resource; a step that made
 *   an empty resource may remove it; a step whose resource may hold
 *   people's work reports `attention` and leaves it.
 *
 * ## Adding a step
 *
 * Implement this, register the class in {@see StepRegistry::ORDER}, add its
 * label to `src/lib/provisioning.js`, and — if it makes a resource — write
 * a `teamhub_resource_link` row through `ProvisioningContext`'s services.
 * `applies()` decides per operation whether the step exists at all, so a
 * blueprint that does not want a conversation has no Talk step, rather than
 * a skipped one.
 */
interface StepInterface {

    /** The stable key stored in `teamhub_provisioning_step.step_key`. */
    public function key(): string;

    /** The kind of resource the step makes or finds, or null. */
    public function resourceType(): ?string;

    /** Whether this operation has this step at all. */
    public function applies(ProvisioningContext $ctx): bool;

    /** Whether a rollback of this step could ever do something. */
    public function rollbackPossible(): bool;

    public function run(ProvisioningContext $ctx): StepResult;

    /**
     * Undo what `run()` did, per the resource's policy. `$confirm` is the
     * caller's explicit go-ahead for resources that may hold activity.
     *
     * @param array<string,mixed> $stepRow the step as stored
     */
    public function rollback(ProvisioningContext $ctx, array $stepRow, bool $confirm): StepResult;
}
