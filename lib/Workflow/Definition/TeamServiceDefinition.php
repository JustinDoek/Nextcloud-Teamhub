<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow\Definition;

use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Exception\WorkflowTransitionException;
use OCA\TeamHub\Service\ServiceTeam\ApproverResolver;
use OCA\TeamHub\Service\ServiceTeam\RequestForm;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\Workflow\WorkflowActorResolver;
use OCA\TeamHub\Service\Workflow\WorkflowShareService;
use OCA\TeamHub\Workflow\IWorkflowApprovals;
use OCA\TeamHub\Workflow\IWorkflowClosableByDesk;
use OCA\TeamHub\Workflow\IWorkflowDefinition;
use OCA\TeamHub\Workflow\IWorkflowDefinitionSource;
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
 *                   Since v4.20.54 the service says how (`confirm.mode`): by
 *                   the requester, by itself after a number of days, or not
 *                   at all — then there is no such step and the request ends
 *                   when the team's last step is done.
 *
 *                   An **approval** (v4.20.58, § 14.4) is a third kind: somebody
 *                   the service names approves or rejects.
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
 * **The request form** (v4.20.54, § 14.3) is the service's own: its fields
 * are the document's `request`, checked by `RequestForm`. A request keeps a
 * copy of what was answered, with the labels it was asked under
 * (`data.form`); one made before that has `data.details` instead.
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
class TeamServiceDefinition implements IWorkflowDefinition,
    IWorkflowDefinitionSource, IWorkflowClosableByDesk, IWorkflowApprovals {

    public const KEY_PREFIX = 'team_service_';

    public const STEP_SUBMIT  = 'submit';
    public const STEP_CONFIRM = 'confirm';

    public const KIND_DESK      = 'desk';
    public const KIND_REQUESTER = 'requester';
    public const KIND_APPROVAL  = 'approval';
    public const KINDS          = [self::KIND_DESK, self::KIND_REQUESTER, self::KIND_APPROVAL];

    /** What an approver's rejection does to the request (v4.20.58). */
    public const REJECT_ENDS      = 'end';
    public const REJECT_CARRIES_ON = 'continue';
    public const REJECT_OUTCOMES  = [self::REJECT_ENDS, self::REJECT_CARRIES_ON];

    /** Who confirms the result (v4.20.54, `docs/service-builder.md` § 14.3). */
    public const CONFIRM_REQUESTER = 'requester';
    public const CONFIRM_AUTO      = 'auto';
    public const CONFIRM_NONE      = 'none';
    public const CONFIRM_MODES     = [self::CONFIRM_REQUESTER, self::CONFIRM_AUTO, self::CONFIRM_NONE];
    public const CONFIRM_DAYS      = 14;
    public const MAX_CONFIRM_DAYS  = 90;

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
        /** v4.20.54 — checks what a requester filled in. */
        private RequestForm        $form,
        /** v4.20.58 — who approves. */
        private ApproverResolver   $approvers,
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
        // v4.20.54 — built from the answers the request kept. A request made
        // before the form was the service's own has its one description.
        if (is_array($data['form'] ?? null)) {
            return RequestForm::asText($l, $data['form'], (string)($data['titleField'] ?? ''));
        }
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
            if (($step['kind'] ?? '') === self::KIND_APPROVAL) {
                $steps[] = new WorkflowStepDefinition(
                    'step_' . $n, $stepLabel, WorkflowActor::approverPlaceholder(),
                    type: WorkflowStepDefinition::TYPE_APPROVAL,
                    config: (is_array($step['approval'] ?? null) ? $step['approval'] : []) + self::rowConfigOf($step),
                );
                continue;
            }
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
                    // v4.20.62 — a step's notices, once: on its first task.
                    // v4.20.71 — and each task's own fields.
                    config: ($m === 1 ? self::rowConfigOf($step) : [])
                        + (array_filter((array)($task['fields'] ?? []), 'is_array') !== [] ? ['fields' => array_values(array_filter((array)$task['fields'], 'is_array'))] : []),
                );
            }
        }
        // v4.20.54 — a service may need no confirmation at all.
        if (self::confirmOf($this->document)['mode'] !== self::CONFIRM_NONE) {
            $steps[] = new WorkflowStepDefinition(self::STEP_CONFIRM, 'Requester confirms', WorkflowActor::initiator());
        }
        return $steps;
    }

    /**
     * How a service's requests are confirmed (v4.20.54). A document saved
     * before it could say is confirmed by the requester, as it always was.
     *
     * @param array<string, mixed> $document
     * @return array{mode: string, days: int}
     */
    public static function confirmOf(array $document): array {
        $raw  = is_array($document['confirm'] ?? null) ? $document['confirm'] : [];
        $mode = (string)($raw['mode'] ?? '');
        $days = (int)($raw['days'] ?? 0);
        return [
            'mode' => in_array($mode, self::CONFIRM_MODES, true) ? $mode : self::CONFIRM_REQUESTER,
            'days' => $days >= 1 && $days <= self::MAX_CONFIRM_DAYS ? $days : self::CONFIRM_DAYS,
        ];
    }

    /**
     * What a step row keeps of its step: its notices (v4.20.62) and its
     * condition (v4.20.66) and its deadline (v4.20.68).
     *
     * @param array<string, mixed> $step
     * @return array{notify?: list<array<string, mixed>>, when?: array<string, mixed>}
     */
    private static function rowConfigOf(array $step): array {
        $notify = array_values(array_filter((array)($step['notify'] ?? []), 'is_array'));
        return ($notify === [] ? [] : ['notify' => $notify])
            + (is_array($step['when'] ?? null) ? ['when' => $step['when']] : [])
            // v4.20.68 — within so many working days.
            + ((int)($step['dueDays'] ?? 0) > 0 ? ['dueDays' => (int)$step['dueDays']] : []);
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

    /** v4.20.58 — never the requester; see `ApproverResolver`. */
    public function resolveApprover(WorkflowStepDefinition $step, string $requesterUid, array $data): ?WorkflowActor {
        return $this->approvers->resolve(
            (array)($step->config['approver'] ?? []),
            $requesterUid,
            is_array($data['form'] ?? null) ? $data['form'] : [],
        );
    }

    /**
     * v4.11.41 — the My Work source chip these requests appear under.
     * No group, for the same reason as the catalogue services: a service a
     * team built is whatever they made it. The Services chip is its home.
     */
    public function sourceGroup(): string {
        return '';
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
        if ($teamId === $this->serviceTeamId) {
            return true;
        }
        // v4.20.54 — a service that does not ask for a team takes personal
        // requests only.
        return RequestForm::of($this->document)['askTeam'] && $resolver->isEffectiveMember($uid, $teamId);
    }

    /**
     * Check the answers against the published form. The client sends them as
     * `answers` (field key → value) with the `version` of the service it drew
     * the form from; a page loaded before v4.20.54 sends `summary` and
     * `details` bare, which are the standard form's own keys.
     */
    public function validateStart(array $data): array {
        $version = $data['version'] ?? null;
        if ($version !== null && (int)$version !== $this->version) {
            // The form was published again while somebody was filling it in.
            throw new WorkflowTransitionException($this->form->changedMessage());
        }
        $answers = $data['answers'] ?? $data;
        if (!is_array($answers)) {
            throw new ValidationException($this->form->unreadableMessage());
        }
        $request = RequestForm::of($this->document);
        $form    = $this->form->answers($request, $answers);
        return [
            // The answer that names the request; '' when none does.
            'summary'      => RequestForm::titleOf($form, $request['titleField']),
            'titleField'   => $request['titleField'],
            // v4.20.54 — what was asked and answered, in the form's order.
            'form'         => $form,
            // How this request is confirmed, as the service said at the start.
            'confirm'      => self::confirmOf($this->document),
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
