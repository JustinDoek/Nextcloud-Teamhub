<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow\Definition;

use OCA\TeamHub\Constants\ServiceCatalogue;
use OCA\TeamHub\Db\WorkflowInstance;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Exception\WorkflowTransitionException;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\TeamExpiryService;
use OCA\TeamHub\Service\Workflow\WorkflowActorResolver;
use OCA\TeamHub\Workflow\IWorkflowDefinition;
use OCA\TeamHub\Workflow\IWorkflowDefinitionHooks;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCA\TeamHub\Workflow\WorkflowStepDefinition;
use OCP\IL10N;

/**
 * A request for more time before a team's expiration date — one of the
 * Nextcloud services (v4.10.45).
 *
 * Justin, 2026-09-25: *Request a team modification* goes, because a team's
 * admins change their own team; what they cannot do themselves is give it
 * more time. So the bundle's second service asks for that, and it replaces
 * the extension request a team admin used to send the Nextcloud
 * administrators (`TeamExpiryService::requestExtension()`, v4.6.13) the
 * same way the quota request replaced the ledger in v4.10.29 (DESIGN
 * §2.148):
 *
 *   1. `submit`  — a team admin asks (a date and a reason). Sending is the step.
 *   2. `handle`  — the service team **grants** (the date is written, here, in
 *                  `onStepCompleted()`) or **declines** with a reason.
 *   3. `confirm` — the requester closes the answered request.
 *
 * **The ledger request is not removed.** Team expiration works without a
 * licence and service teams need one, so where no service team offers this
 * service — no licence, or nobody holds the Nextcloud services — Manage team
 * still sends the old request to the Nextcloud administrators. Where one
 * does, Manage team starts this workflow instead.
 *
 * A grant is exactly the date asked for (the ledger let an administrator
 * grant a shorter one; a desk that wants to give less declines and says
 * so), never earlier than the current date, and on the record like every
 * change of the date.
 */
class TeamExpiryRequestDefinition implements IWorkflowDefinition, IWorkflowDefinitionHooks {

    public const KEY     = 'team_expiry';
    public const VERSION = 1;

    public const STEP_SUBMIT  = 'submit';
    public const STEP_HANDLE  = 'handle';
    public const STEP_CONFIRM = 'confirm';

    public const MAX_REASON = 1000;

    public function __construct(
        private ServiceTeamService $serviceTeams,
        private TeamExpiryService  $expiry,
        // The refusals below reach the requester and the desk as the error
        // they read, so they are in the caller's language.
        private IL10N              $l,
    ) {
    }

    public function getKey(): string {
        return self::KEY;
    }

    public function getVersion(): int {
        return self::VERSION;
    }

    public function getName(): string {
        return 'Team expiration extension request';
    }

    public function getTitle(IL10N $l, array $data): string {
        // TRANSLATORS: title of a request for a later expiration date; %s is the date asked for (YYYY-MM-DD)
        return $l->t('More time for the team, until %s', [(string)($data['proposedOn'] ?? '')]);
    }

    public function getDescription(IL10N $l, array $data): string {
        return (string)($data['reason'] ?? '');
    }

    public function getSteps(): array {
        return [
            new WorkflowStepDefinition(self::STEP_SUBMIT,  'Request submitted',               WorkflowActor::initiator(), true),
            new WorkflowStepDefinition(self::STEP_HANDLE,  'Service team grants or declines', WorkflowActor::serviceAgentPlaceholder()),
            new WorkflowStepDefinition(self::STEP_CONFIRM, 'Requester closes',                WorkflowActor::initiator()),
        ];
    }

    public function getStepLabel(IL10N $l, string $stepKey): ?string {
        switch ($stepKey) {
            case self::STEP_SUBMIT:
                // TRANSLATORS: workflow step - the requester sent the request
                return $l->t('Request submitted');
            case self::STEP_HANDLE:
                // TRANSLATORS: workflow step - the service team grants or declines a request
                return $l->t('Service team grants or declines');
            case self::STEP_CONFIRM:
                // TRANSLATORS: workflow step - the person who asked closes the answered request
                return $l->t('Requester closes');
        }
        return null;
    }

    /** The handling step: the service team that holds this service. */
    public function resolveActor(WorkflowStepDefinition $step, string $teamId, array $data): ?WorkflowActor {
        if ($step->key !== self::STEP_HANDLE) {
            return null;
        }
        $serviceTeamId = $this->serviceTeams->serviceTeamForDefinition(self::KEY);
        return $serviceTeamId === null ? null : WorkflowActor::serviceAgent($serviceTeamId);
    }

    public function getConcurrency(): string {
        return WorkflowDefinitionRegistry::CONCURRENCY_ONE_OPEN_PER_TEAM;
    }

    /** A team admin or owner (Circles level ≥ 8), while a desk offers the service. */
    public function canStart(string $uid, string $teamId, WorkflowActorResolver $resolver): bool {
        return $this->serviceTeams->serviceTeamForDefinition(self::KEY) !== null
            && $resolver->memberLevel($uid, $teamId) >= 8;
    }

    public function validateStart(array $data): array {
        $date   = trim((string)($data['proposedOn'] ?? ''));
        $reason = trim((string)($data['reason'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new ValidationException($this->l->t('Pick the date the team needs until.'));
        }
        if ($reason === '') {
            throw new ValidationException($this->l->t('A reason is required.'));
        }
        return [
            'proposedOn' => $date,
            'reason'     => mb_substr($reason, 0, self::MAX_REASON),
        ];
    }

    /**
     * The team may expire, has a date, has no ledger request waiting, and
     * the date asked for is later. The current date is recorded here, from
     * the server, so the desk sees what the team had when it asked.
     */
    public function validateForTeam(string $teamId, array $data): array {
        $current = $this->currentDate($teamId);
        if ($this->expiry->hasPendingRequest($teamId)) {
            throw new ValidationException($this->l->t('A request for more time for this team is already waiting for a decision.'));
        }
        try {
            $asked = $this->expiry->parseDate((string)$data['proposedOn']);
        } catch (ValidationException) {
            throw new ValidationException($this->l->t('Pick a date in the future, at most %d years from now.', [TeamExpiryService::MAX_YEARS_AHEAD]));
        }
        if ($asked <= $current['expiresAt']) {
            throw new ValidationException($this->l->t('Pick a date after the current expiration date, %s.', [$current['expiresOn']]));
        }
        return $data + ['currentOn' => $current['expiresOn']];
    }

    /**
     * Granting is completing the desk's step: the date is written first,
     * inside the transaction, so a refusal leaves the request waiting for
     * its answer rather than reporting a grant that did not happen.
     */
    public function onStepCompleted(WorkflowInstance $instance, string $stepKey, string $uid): void {
        if ($stepKey !== self::STEP_HANDLE) {
            return;
        }
        $teamId  = $instance->getTeamId();
        $asked   = (string)($instance->getData()['proposedOn'] ?? '');
        $current = $this->expiry->getExpiry($teamId);
        if ($current === null) {
            throw new WorkflowTransitionException($this->l->t('This team no longer has an expiration date; decline the request instead.'));
        }
        if ($asked <= (string)$current['expiresOn']) {
            throw new WorkflowTransitionException($this->l->t('The team already runs until this date or later; decline the request instead.'));
        }
        try {
            $this->expiry->extendByServiceTeam($teamId, $asked, $uid);
        } catch (ValidationException) {
            throw new WorkflowTransitionException($this->l->t('This date can no longer be granted; decline the request instead.'));
        }
    }

    public function getActionLabels(IL10N $l, string $stepKey): array {
        switch ($stepKey) {
            case self::STEP_HANDLE:
                return [
                    // TRANSLATORS: button - give the team the later expiration date it asked for
                    'complete' => $l->t('Grant'),
                    // TRANSLATORS: button - refuse a request for more time, with a reason
                    'reject'   => $l->t('Decline'),
                ];
            case self::STEP_CONFIRM:
                return [
                    // TRANSLATORS: button - the requester closes an answered request
                    'complete' => $l->t('Close'),
                ];
        }
        return [];
    }

    public function subjectOf(string $teamId, array $data): array {
        return ['team_expiry', $teamId];
    }

    /** Offered while a desk holds it. */
    public function isStartable(): bool {
        return $this->serviceTeams->serviceTeamForDefinition(self::KEY) !== null;
    }

    /** Service Teams are licensed, so a request only a desk can answer is too. */
    public function allowsUnlicensedUse(): bool {
        return false;
    }

    /** The title needs the date; the reason stays with the instance. */
    public function getNotificationData(array $data): array {
        return [
            'proposedOn' => (string)($data['proposedOn'] ?? ''),
            'serviceKey' => ServiceCatalogue::TEAM_EXPIRY,
        ];
    }

    /** @return array{expiresAt: int, expiresOn: string} */
    private function currentDate(string $teamId): array {
        if (!$this->expiry->isEligible($teamId)) {
            throw new ValidationException($this->l->t('This team cannot have an expiration date.'));
        }
        $current = $this->expiry->getExpiry($teamId);
        if ($current === null) {
            throw new ValidationException($this->l->t('This team has no expiration date, so there is nothing to extend.'));
        }
        return ['expiresAt' => (int)$current['expiresAt'], 'expiresOn' => (string)$current['expiresOn']];
    }
}
