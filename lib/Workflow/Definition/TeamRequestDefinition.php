<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow\Definition;

use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\Workflow\WorkflowActorResolver;
use OCA\TeamHub\Workflow\IWorkflowDefinition;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCA\TeamHub\Workflow\WorkflowStepDefinition;
use OCP\IL10N;

/**
 * Request a new Nextcloud team — the reference workflow (WorkflowHub
 * phase 2, v4.10.14).
 *
 * A member of an existing team asks for a new team to be created. Four
 * steps, strictly in order:
 *
 *   1. `submit`  — the requester. Completed at creation: sending the
 *                  request *is* this step (`autoCompleteOnCreate`).
 *   2. `approve` — the owner or a moderator of the requesting team
 *                  (`team_moderator`, Circles level ≥ 4) approves or rejects.
 *   3. `process` — the **service team** that holds the Nextcloud services
 *                  creates the team and completes the step.
 *   4. `confirm` — the requester confirms the new team is what they asked
 *                  for (`initiator`, materialised as `user:{uid}`).
 *
 * Completing step 4 completes the workflow. The team itself is created by
 * the people in step 3 with the tools they already have — this workflow
 * carries the request and the answers, not the provisioning (no side
 * effects, no premium logic).
 *
 * ## v4.10.23 — the desk does step 3, and this is a licensed service
 *
 * Until now step 3 was a configured Nextcloud group (default `admin`) and
 * the whole workflow ran on every instance, licensed or not. *Request a new
 * team* is the first of the six services in the **Nextcloud Services**
 * bundle, and the bundle is held by one service team or by nobody
 * (DESIGN §2.146). So:
 *
 *   - step 3's actor is the desk, resolved at creation exactly as
 *     `ServiceRequestDefinition` resolves its handling step, and copied
 *     onto the step row — a request stays with the desk that took it even
 *     if the bundle later moves;
 *   - the workflow is **dark while nobody holds the bundle**
 *     (`isStartable()`), so the start points are absent rather than opening
 *     a request nobody would answer;
 *   - it is **licensed** (`allowsUnlicensedUse()` is false), because a
 *     request only a service team can answer cannot outlive the licence
 *     that makes service teams work.
 *
 * `VERSION` is 2 for the change of actor. Instances created under version 1
 * are untouched: an actor is copied onto the step row at creation, so a
 * request already with the administrators stays with them, and no
 * transition verb reads the licence — a workflow in flight always finishes.
 */
class TeamRequestDefinition implements IWorkflowDefinition {

    public const KEY     = 'team_request';
    public const VERSION = 2;

    public const STEP_SUBMIT  = 'submit';
    public const STEP_APPROVE = 'approve';
    public const STEP_PROCESS = 'process';
    public const STEP_CONFIRM = 'confirm';

    public const MAX_NAME   = 255;
    public const MAX_REASON = 1000;

    public function __construct(
        private ServiceTeamService $serviceTeams,
    ) {
    }

    public function getKey(): string {
        return self::KEY;
    }

    public function getVersion(): int {
        return self::VERSION;
    }

    public function getName(): string {
        return 'Request a new team';
    }

    public function getTitle(IL10N $l, array $data): string {
        // TRANSLATORS: %s is the name of the team somebody asked for
        return $l->t('Team request: %s', [(string)($data['teamName'] ?? '')]);
    }

    public function getDescription(IL10N $l, array $data): string {
        return (string)($data['reason'] ?? '');
    }

    public function getSteps(): array {
        return [
            new WorkflowStepDefinition(self::STEP_SUBMIT,  'Request submitted',                       WorkflowActor::initiator(), true),
            new WorkflowStepDefinition(self::STEP_APPROVE, 'Team owner or moderator approves',        WorkflowActor::teamModerator()),
            // The desk's placeholder, filled in by resolveActor() at create.
            new WorkflowStepDefinition(self::STEP_PROCESS, 'The service team creates the team', WorkflowActor::serviceAgentPlaceholder()),
            new WorkflowStepDefinition(self::STEP_CONFIRM, 'Requester confirms',                      WorkflowActor::initiator()),
        ];
    }

    public function getStepLabel(IL10N $l, string $stepKey): ?string {
        switch ($stepKey) {
            case self::STEP_SUBMIT:
                // TRANSLATORS: workflow step — the requester sent the request
                return $l->t('Request submitted');
            case self::STEP_APPROVE:
                // TRANSLATORS: workflow step — the requesting team's owner or a moderator approves the new team
                return $l->t('Team owner or moderator approves');
            case self::STEP_PROCESS:
                // TRANSLATORS: workflow step — the service team creates the requested team
                return $l->t('The service team creates the team');
            case self::STEP_CONFIRM:
                // TRANSLATORS: workflow step — the requester confirms the new team is right
                return $l->t('Requester confirms');
        }
        return null;
    }

    /**
     * The processing step's actor: the service team that holds the
     * Nextcloud services (v4.10.23).
     *
     * Resolved once, at creation, and copied onto the step row — so a
     * request stays with the desk that took it even if the bundle is later
     * released or claimed by another team. A placeholder that resolves to
     * nothing refuses the workflow rather than storing a step nobody holds,
     * which is the engine's rule and is why `isStartable()` hides the start
     * points first.
     */
    public function resolveActor(WorkflowStepDefinition $step, string $teamId, array $data): ?WorkflowActor {
        if ($step->key !== self::STEP_PROCESS) {
            return null;
        }
        $serviceTeamId = $this->serviceTeams->serviceTeamForDefinition(self::KEY);
        return $serviceTeamId === null ? null : WorkflowActor::serviceAgent($serviceTeamId);
    }

    public function getConcurrency(): string {
        return WorkflowDefinitionRegistry::CONCURRENCY_UNBOUNDED;
    }

    /**
     * Any effective member of the requesting team, while a service team
     * holds the Nextcloud services. The desk's own members are not a
     * special case: asking your own desk for a team is a legitimate
     * request and lands in the queue like any other.
     */
    /**
     * A member of the team the request is asked from — or anybody signed in,
     * asking personally (v4.10.44): a new team needs no team to ask from.
     * The request is then recorded against the service team that answers it.
     */
    public function canStart(string $uid, string $teamId, WorkflowActorResolver $resolver): bool {
        $desk = $this->serviceTeams->serviceTeamForDefinition(self::KEY);
        if ($desk === null || $uid === '') {
            return false;
        }
        return $teamId === $desk || $resolver->isEffectiveMember($uid, $teamId);
    }

    /**
     * v4.10.44 — the Services page asks every service with one form (a line
     * and a description). Its `summary` is the new team's name and its
     * `details` the reason, when the request does not name them itself:
     * until now the card on that page was refused with "A team name … is
     * required".
     */
    public function validateStart(array $data): array {
        $name   = trim((string)($data['teamName'] ?? $data['summary'] ?? ''));
        $reason = trim((string)($data['reason'] ?? $data['details'] ?? ''));
        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            throw new ValidationException('A team name of at most 255 characters is required.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new ValidationException('The team name contains characters that are not allowed.');
        }
        if ($reason === '') {
            throw new ValidationException('Say why the team is needed.');
        }
        return [
            'teamName' => $name,
            'reason'   => mb_substr($reason, 0, self::MAX_REASON),
        ];
    }

    public function subjectOf(string $teamId, array $data): array {
        return ['team_request', (string)($data['teamName'] ?? '')];
    }

    /**
     * Dark until a service team holds the Nextcloud services. Nobody would
     * answer a request otherwise, and a start point that opens a dialog
     * nobody reads is worse than no start point at all.
     */
    public function isStartable(): bool {
        return $this->serviceTeams->serviceTeamForDefinition(self::KEY) !== null;
    }

    /** Service Teams are licensed, so a request only a desk can answer is too. */
    public function allowsUnlicensedUse(): bool {
        return false;
    }

    /** The title needs the team name; the reason stays with the instance. */
    public function getNotificationData(array $data): array {
        return ['teamName' => (string)($data['teamName'] ?? '')];
    }
}
