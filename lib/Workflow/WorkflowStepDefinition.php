<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow;

/**
 * One step of a built-in workflow definition (WorkflowHub phase 1).
 *
 * Immutable and code-defined: a definition lists its steps in order, and
 * `WorkflowEngine::create()` materialises one `WorkflowStep` row per
 * definition step — key, order, actor and label copied — so a running
 * instance never depends on the definition class again. That copy is what
 * makes the definition *version* fixed at start: a later version may add,
 * rename or re-route steps without touching an instance that started on an
 * earlier one.
 *
 * `$label` is the developer-facing English name of the step, snapshotted
 * on the row; `IWorkflowDefinition::getStepLabel()` gives the translated
 * one to whoever renders it.
 *
 * `$autoCompleteOnCreate` (v4.10.14) marks a step that *is* the act of
 * starting the workflow — "request submitted". The engine enters, starts
 * and completes it in the initiator's name at creation, so the tracker
 * shows it done from the first moment and nobody has to click a step they
 * have already performed. Only the first step may carry it.
 *
 * `$roleLabel` (v4.10.31) is the role a desk step needs, as a service team
 * typed it in the service builder ("Functional admin"). A label for the
 * queue and the tracker, not a permission. Built-in definitions leave it ''.
 *
 * `$links` (v4.10.36) are the links a service team put on the step in the
 * builder — `[{label, url, kind}]`, every url `https://` — copied onto the
 * step row like the label, so a running request keeps the links it started
 * with. Built-in definitions leave it empty.
 *
 * **Tasks** (v4.10.37, `docs/service-builder.md` § 4). A step of a built
 * service may hold several tasks, done in parallel. Each task is one entry
 * here, and consecutive entries with the same `$stage` form one step: the
 * engine gives their rows the same step order, makes them available
 * together, and moves on when every task that is not `$nonBlocking` is
 * done. `$stageLabel` is the step's own name, `$label` the task's. An entry
 * without a stage is a step of its own — every built-in definition.
 */
final class WorkflowStepDefinition {

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly WorkflowActor $actor,
        public readonly bool $autoCompleteOnCreate = false,
        public readonly string $roleLabel = '',
        /** @var array<int, array{label: string, url: string, kind: string}> */
        public readonly array $links = [],
        /** v4.10.37 — the step this task belongs to; null = a step of its own. */
        public readonly ?string $stage = null,
        /** v4.10.37 — the step's name when it holds several tasks. */
        public readonly string $stageLabel = '',
        /** v4.10.37 — the request may move on while this task is still open. */
        public readonly bool $nonBlocking = false,
    ) {
        if ($key === '' || strlen($key) > 64 || !preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException('A step key is 1–64 lowercase characters, digits or underscores: ' . $key);
        }
        if ($label === '') {
            throw new \InvalidArgumentException('A step needs a label: ' . $key);
        }
        if (mb_strlen($roleLabel) > 255) {
            throw new \InvalidArgumentException('A role label is at most 255 characters: ' . $key);
        }
        if (mb_strlen($stageLabel) > 255) {
            throw new \InvalidArgumentException('A stage label is at most 255 characters: ' . $key);
        }
        foreach ($links as $link) {
            // The builder normalised these; this only refuses what must never
            // reach a row, whatever wrote the definition.
            if (!is_array($link) || !str_starts_with((string)($link['url'] ?? ''), 'https://')) {
                throw new \InvalidArgumentException('A step link is an array with an https:// url: ' . $key);
            }
        }
    }
}
