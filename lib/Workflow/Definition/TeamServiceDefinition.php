<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow\Definition;

use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\Workflow\WorkflowActorResolver;
use OCA\TeamHub\Service\Workflow\WorkflowShareService;
use OCA\TeamHub\Workflow\IWorkflowClosableByDesk;
use OCA\TeamHub\Workflow\IWorkflowDefinition;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCA\TeamHub\Workflow\WorkflowStepDefinition;
use OCP\IL10N;

/**
 * A service a service team built (v4.10.31, the service builder;
 * `docs/service-builder.md`).
 *
 * **One class, one registered instance per published service**, built from
 * the row's *published* document by `Application.php` — the same move as
 * `ServiceRequestDefinition`, with the words and the steps read from a row
 * instead of a constant. Product rule 6 (*no workflow builder*) is retired
 * by that document; rules 14–15 still hold: steps run in order, one at a
 * time, with no branches.
 *
 * The shape:
 *
 *   1. `submit`   — the requester; completed at creation, as everywhere.
 *   2. `step_1` … `step_n` — what the builder placed, each either
 *                   - a **desk step**: the service team, as a whole, with
 *                     the role it needs as a label (any member may claim
 *                     it), or
 *                   - a **requester action**: the requester does something
 *                     planned ("Sign and upload the agreement") before the
 *                     desk continues.
 *   3. `confirm`  — the requester confirms. An admin of the service team
 *                   may close it in their place (`IWorkflowClosableByDesk`).
 *
 * Unplanned back-and-forth needs nothing here: every desk step can already
 * ask the requester for information (the engine's *request information*).
 *
 * **Versioning.** `getVersion()` is the row's `pub_version`, raised by every
 * publish. The engine copies the steps — label, actor, role — onto each
 * request at start, so a request finishes on the version it started on. The
 * builder's own step labels are the team's words and are not translated:
 * `getStepLabel()` answers null for them and the engine falls back to the
 * copy on the step row. The service's title is copied into the request's
 * data for the same reason: renaming a service does not rename the requests
 * already made.
 *
 * **Personal requests** (v4.10.39, `docs/service-builder.md` § 2): the
 * requesting team is an optional field of the form. A request asked from
 * no team is recorded against the **service team itself** — the instance
 * needs a team, and the service team is the only one the request belongs
 * to — and anybody may make one. The view says `personal: true`.
 *
 * **Tasks** (v4.10.37, `docs/service-builder.md` § 4). A step of the
 * document holds one or more tasks; each becomes a step definition of its
 * own, grouped into the step by `stage`, so the engine makes one row per
 * task. A step with one task keeps the key `step_{n}`; with several, its
 * tasks are `step_{n}_{m}`. A task's links (v4.10.36, § 6) are copied onto
 * its row with the label and the role.
 *
 * **A withdrawn shape.** v4.10.36 let a service start by opening a link
 * (`start: link`); v4.10.37 took it back (Justin: *"We want 1 process"*).
 * A service still *published* that way is never startable and is left off
 * the Services page (`isLegacyLinkService()`) until its team publishes it
 * again; it stays registered for the same reason every unpublished service
 * does.
 */
class TeamServiceDefinition implements IWorkflowDefinition, IWorkflowClosableByDesk {

    public const KEY_PREFIX = 'team_service_';

    public const STEP_SUBMIT  = 'submit';
    public const STEP_CONFIRM = 'confirm';

    public const KIND_DESK      = 'desk';
    public const KIND_REQUESTER = 'requester';

    public const MAX_SUMMARY = 255;
    public const MAX_DETAILS = 1000;

    /**
     * @param array<string, mixed> $document the row's published document, as `TeamServiceBuilder::normalise()` wrote it
     */
    public function __construct(
        private ServiceTeamService $serviceTeams,
        private int                $serviceId,
        /** The service team that built it and answers it. */
        private string             $serviceTeamId,
        private int                $version,
        private bool               $listed,
        private array              $document,
    ) {
    }

    public static function keyFor(int $serviceId): string {
        return self::KEY_PREFIX . $serviceId;
    }

    /** The service id behind a definition key, or null when it is not a built service. */
    public static function idOf(string $definitionKey): ?int {
        if (!str_starts_with($definitionKey, self::KEY_PREFIX)) {
            return null;
        }
        $id = substr($definitionKey, strlen(self::KEY_PREFIX));
        return ctype_digit($id) ? (int)$id : null;
    }

    public function getKey(): string {
        return self::keyFor($this->serviceId);
    }

    public function getServiceId(): int {
        return $this->serviceId;
    }

    public function getServiceTeamId(): string {
        return $this->serviceTeamId;
    }

    public function getVersion(): int {
        return $this->version;
    }

    /** The service's own title: statistics and diagnostics print it. */
    public function getName(): string {
        return $this->serviceTitle();
    }

    public function getTitle(IL10N $l, array $data): string {
        $summary = trim((string)($data['summary'] ?? ''));
        $service = trim((string)($data['serviceTitle'] ?? '')) ?: $this->serviceTitle();
        if ($summary === '') {
            return $service;
        }
        // TRANSLATORS: %1$s is the name of a service, %2$s the requester's own one-line summary
        return $l->t('%1$s: %2$s', [$service, $summary]);
    }

    public function getDescription(IL10N $l, array $data): string {
        return (string)($data['details'] ?? '');
    }

    public function getSteps(): array {
        $steps = [
            new WorkflowStepDefinition(self::STEP_SUBMIT, 'Request submitted', WorkflowActor::initiator(), true),
        ];
        $n = 0;
        foreach ((array)($this->document['steps'] ?? []) as $step) {
            $n++;
            $stepLabel = trim((string)($step['label'] ?? '')) ?: ('Step ' . $n);
            $requester = ($step['kind'] ?? '') === self::KIND_REQUESTER;
            $tasks     = self::tasksOf($step);
            $several   = count($tasks) > 1;
            $m = 0;
            foreach ($tasks as $task) {
                $m++;
                $taskLabel = trim((string)($task['label'] ?? ''));
                $steps[] = new WorkflowStepDefinition(
                    $several ? 'step_' . $n . '_' . $m : 'step_' . $n,
                    // One task borrows the step's name when it has none.
                    $taskLabel !== '' ? $taskLabel : $stepLabel,
                    $requester ? WorkflowActor::initiator() : WorkflowActor::serviceAgentPlaceholder(),
                    false,
                    $requester ? '' : trim((string)($task['role'] ?? '')),
                    is_array($task['links'] ?? null) ? $task['links'] : [],
                    'step_' . $n,
                    $several || $taskLabel !== '' ? $stepLabel : '',
                    !$requester && !empty($task['nonBlocking']),
                );
            }
        }
        $steps[] = new WorkflowStepDefinition(self::STEP_CONFIRM, 'Requester confirms', WorkflowActor::initiator());
        return $steps;
    }

    /**
     * A step's tasks, whichever shape stored it: a step published before
     * v4.10.37 carried its role and links itself, and is one task.
     *
     * @param array<string, mixed> $step
     * @return array<int, array<string, mixed>>
     */
    public static function tasksOf(array $step): array {
        $tasks = $step['tasks'] ?? null;
        if (is_array($tasks) && $tasks !== []) {
            return array_values(array_filter($tasks, 'is_array'));
        }
        return [['label' => '', 'role' => $step['role'] ?? '', 'links' => $step['links'] ?? [], 'nonBlocking' => false]];
    }

    /** Only the two fixed steps have translated words; the builder's are the team's own. */
    public function getStepLabel(IL10N $l, string $stepKey): ?string {
        switch ($stepKey) {
            case self::STEP_SUBMIT:
                // TRANSLATORS: workflow step - the requester sent the request
                return $l->t('Request submitted');
            case self::STEP_CONFIRM:
                // TRANSLATORS: workflow step - the requester confirms the answer is what they needed
                return $l->t('Requester confirms');
        }
        return null;
    }

    /** Every desk step is the service team that built the service. */
    public function resolveActor(WorkflowStepDefinition $step, string $teamId, array $data): ?WorkflowActor {
        if (!$step->actor->isUnresolved()) {
            return null;
        }
        return $this->serviceTeams->isActiveServiceTeam($this->serviceTeamId)
            ? WorkflowActor::serviceAgent($this->serviceTeamId)
            : null;
    }

    public function getConcurrency(): string {
        return WorkflowDefinitionRegistry::CONCURRENCY_UNBOUNDED;
    }

    /**
     * While the service is live: any effective member of the team the request
     * is asked from — or, for a personal request (asked from no team, and so
     * recorded against the service team, v4.10.39), anybody signed in.
     */
    public function canStart(string $uid, string $teamId, WorkflowActorResolver $resolver): bool {
        if (!$this->isStartable() || $uid === '') {
            return false;
        }
        return $teamId === $this->serviceTeamId || $resolver->isEffectiveMember($uid, $teamId);
    }

    public function validateStart(array $data): array {
        $summary = trim((string)($data['summary'] ?? ''));
        $details = trim((string)($data['details'] ?? ''));
        if ($summary === '' || mb_strlen($summary) > self::MAX_SUMMARY) {
            throw new ValidationException('A one-line summary of at most 255 characters is required.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $summary)) {
            throw new ValidationException('The summary contains characters that are not allowed.');
        }
        if ($details === '') {
            throw new ValidationException('Describe what you need.');
        }
        return [
            'summary'      => $summary,
            'details'      => mb_substr($details, 0, self::MAX_DETAILS),
            'serviceKey'   => $this->getKey(),
            // The title as it was when the request was made (see the class docblock).
            'serviceTitle' => $this->serviceTitle(),
            // v4.10.38 — what the paperclip may do on this request, as the
            // service said when it started (`WorkflowShareService::settingsOf()`).
            'fileSharing'  => WorkflowShareService::settingsOf(['fileSharing' => $this->document['files'] ?? []]),
        ];
    }

    public function subjectOf(string $teamId, array $data): array {
        return ['service_request', $this->getKey()];
    }

    /**
     * Listed, and its service team still an active one. An unpublished
     * service stays registered but dark, so its running requests keep their
     * title and steps.
     */
    public function isStartable(): bool {
        return $this->listed
            && !$this->isLegacyLinkService()
            && $this->serviceTeams->isActiveServiceTeam($this->serviceTeamId);
    }

    /**
     * Published as a service that starts by opening a link (v4.10.36, since
     * withdrawn): not startable, not on the Services page, until republished.
     */
    public function isLegacyLinkService(): bool {
        return ($this->document['start'] ?? '') === 'link';
    }

    /** Service teams are licensed, so a service only a desk can answer is too. */
    public function allowsUnlicensedUse(): bool {
        return false;
    }

    public function getNotificationData(array $data): array {
        return [
            'summary'      => (string)($data['summary'] ?? ''),
            'serviceKey'   => $this->getKey(),
            'serviceTitle' => (string)($data['serviceTitle'] ?? $this->serviceTitle()),
        ];
    }

    private function serviceTitle(): string {
        $title = trim((string)($this->document['title'] ?? ''));
        return $title !== '' ? $title : $this->getKey();
    }
}
