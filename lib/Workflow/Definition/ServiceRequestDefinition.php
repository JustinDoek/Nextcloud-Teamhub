<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow\Definition;

use OCA\TeamHub\Constants\ServiceCatalogue;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\Workflow\WorkflowActorResolver;
use OCA\TeamHub\Workflow\IWorkflowClosableByDesk;
use OCA\TeamHub\Workflow\IWorkflowDefinition;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCA\TeamHub\Workflow\WorkflowStepDefinition;
use OCP\IL10N;

/**
 * A request to a Service Team (WorkflowHub phase 5, v4.10.20).
 *
 * **One class, one registered instance per service.** `Application.php`
 * registers four of these — external access, a team folder, team archiving
 * and the Nextcloud catch-all — each with its own definition key, plus the
 * retired team modification (v4.10.45, dark, for the requests made on it). They are the same workflow with different words, which is
 * exactly what a service catalogue is; giving each its own class would have
 * been five copies of this file, and giving them all one key would have made
 * "which service is this" a field inside the payload rather than the thing
 * the queue is keyed on. (The sixth catalogue service, *request a new team*,
 * runs the reference workflow `team_request` that has existed since
 * v4.10.14 — a desk taking that over is what a desk is for.)
 *
 * Three steps:
 *
 *   1. `submit` — the requester. Completed at creation: sending the request
 *                 *is* this step (`autoCompleteOnCreate`).
 *   2. `handle` — the **service team**, as a whole. Its actor is a
 *                 placeholder in `getSteps()` and is resolved by
 *                 `resolveActor()` into `service_agent:{serviceTeamId}` when
 *                 the instance is created. Every eligible agent holds it;
 *                 one of them claims it, and from that moment it is theirs
 *                 (`WorkflowEngine::assertHolder()`).
 *   3. `confirm` — the requester confirms the answer is what they needed.
 *
 * The desk does the work with the tools it already has and completes the
 * step: like every built-in workflow so far this carries the request and the
 * answers, not the provisioning. No side effects.
 *
 * **Licensed.** `allowsUnlicensedUse()` is false — Service Teams are a
 * licensed capability, so a definition that can only be handled by one must
 * not be startable without a licence. It is also `isStartable()` only while
 * a service team actually offers it: a catalogue entry is what wires a
 * service up, so a definition nobody offers is dark rather than broken.
 *
 * **Closable by the desk** (v4.10.31, `IWorkflowClosableByDesk`): an admin
 * of the service team may close a request at any step. It carries words
 * and does nothing else, so ending it early can leave nothing half done.
 * The quota request and the team request are not closable: their desk
 * step *does* something, and closing one would skip that.
 */
class ServiceRequestDefinition implements IWorkflowDefinition, IWorkflowClosableByDesk {

    public const VERSION = 1;

    public const STEP_SUBMIT  = 'submit';
    public const STEP_HANDLE  = 'handle';
    public const STEP_CONFIRM = 'confirm';

    public const MAX_SUMMARY = 255;
    public const MAX_DETAILS = 1000;

    public function __construct(
        private ServiceTeamService $serviceTeams,
        /** The service this instance of the class stands for; one of `ServiceCatalogue::SERVICES`. */
        private string             $serviceKey = ServiceCatalogue::GENERAL,
    ) {
    }

    public function getKey(): string {
        return (string)ServiceCatalogue::definitionFor($this->serviceKey);
    }

    public function getServiceKey(): string {
        return $this->serviceKey;
    }

    public function getVersion(): int {
        return self::VERSION;
    }

    public function getName(): string {
        return 'Service request: ' . $this->serviceKey;
    }

    public function getTitle(IL10N $l, array $data): string {
        $summary = trim((string)($data['summary'] ?? ''));
        $service = ServiceCatalogue::describe($l, $this->serviceKey)['label'];
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
        return [
            new WorkflowStepDefinition(self::STEP_SUBMIT,  'Request submitted',        WorkflowActor::initiator(), true),
            new WorkflowStepDefinition(self::STEP_HANDLE,  'Service team handles it',  WorkflowActor::serviceAgentPlaceholder()),
            new WorkflowStepDefinition(self::STEP_CONFIRM, 'Requester confirms',       WorkflowActor::initiator()),
        ];
    }

    public function getStepLabel(IL10N $l, string $stepKey): ?string {
        switch ($stepKey) {
            case self::STEP_SUBMIT:
                // TRANSLATORS: workflow step - the requester sent the request
                return $l->t('Request submitted');
            case self::STEP_HANDLE:
                // TRANSLATORS: workflow step - an agent of the service team works on the request
                return $l->t('Service team handles it');
            case self::STEP_CONFIRM:
                // TRANSLATORS: workflow step - the requester confirms the answer is what they needed
                return $l->t('Requester confirms');
        }
        return null;
    }

    /**
     * The handling step's actor: the service team that offers this service.
     *
     * Resolved once, at creation, and copied onto the step row — so a
     * request stays with the desk that took it even if the catalogue is
     * later changed or the service moved to another team. That is the same
     * rule `TeamRequestDefinition` applies to its configured group.
     */
    public function resolveActor(WorkflowStepDefinition $step, string $teamId, array $data): ?WorkflowActor {
        if ($step->key !== self::STEP_HANDLE) {
            return null;
        }
        $serviceTeamId = $this->serviceTeams->serviceTeamForDefinition($this->getKey());
        return $serviceTeamId === null ? null : WorkflowActor::serviceAgent($serviceTeamId);
    }

    public function getConcurrency(): string {
        return WorkflowDefinitionRegistry::CONCURRENCY_UNBOUNDED;
    }

    /**
     * Any effective member of the requesting team, while a service team
     * offers the service — or, for a service a request needs no team for
     * (`ServiceCatalogue::TEAM_OPTIONAL`, v4.10.44), anybody signed in,
     * asking personally: the request is then recorded against the service
     * team that answers it. The desk's own agents are not a special case.
     * A service the desk does not offer now (v4.10.45: team archiving while
     * archiving before deletion is on) cannot be started.
     */
    public function canStart(string $uid, string $teamId, WorkflowActorResolver $resolver): bool {
        $desk = $this->serviceTeams->serviceTeamForDefinition($this->getKey());
        if ($desk === null || $uid === '' || !$this->serviceTeams->isServiceOffered($this->serviceKey)) {
            return false;
        }
        $service = ServiceCatalogue::serviceForDefinition($this->getKey());
        if ($teamId === $desk && $service !== null && ServiceCatalogue::isTeamOptional($service)) {
            return true;
        }
        return $resolver->isEffectiveMember($uid, $teamId);
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
            'summary'    => $summary,
            'details'    => mb_substr($details, 0, self::MAX_DETAILS),
            'serviceKey' => $this->serviceKey,
        ];
    }

    public function subjectOf(string $teamId, array $data): array {
        return ['service_request', $this->serviceKey];
    }

    /**
     * Dark until a service team offers this service. A catalogue entry is
     * what wires a service up, so a definition nobody offers is not listed
     * and cannot be started — the same answer as an unknown key, which is
     * what it effectively is on that instance.
     */
    public function isStartable(): bool {
        return $this->serviceTeams->isServiceOffered($this->serviceKey)
            && $this->serviceTeams->serviceTeamForDefinition($this->getKey()) !== null;
    }

    /** Service Teams are licensed, so a request only a desk can answer is too. */
    public function allowsUnlicensedUse(): bool {
        return false;
    }

    /** The title needs the summary; the details stay with the instance. */
    public function getNotificationData(array $data): array {
        return [
            'summary'    => (string)($data['summary'] ?? ''),
            'serviceKey' => $this->serviceKey,
        ];
    }
}
