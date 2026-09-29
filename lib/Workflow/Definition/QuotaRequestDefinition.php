<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow\Definition;

use OCA\TeamHub\Constants\ServiceCatalogue;
use OCA\TeamHub\Db\WorkflowInstance;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Exception\WorkflowTransitionException;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\TeamSpaceService;
use OCA\TeamHub\Service\Workflow\WorkflowActorResolver;
use OCA\TeamHub\Workflow\IWorkflowDefinition;
use OCA\TeamHub\Workflow\IWorkflowDefinitionHooks;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCA\TeamHub\Workflow\WorkflowStepDefinition;
use OCP\IL10N;
use OCP\Util;

/**
 * A request for more storage in a team's space — one of the Nextcloud
 * services (v4.10.29; v1 since v4.10.13, dark until now).
 *
 * **The seventh service of the bundle.** Until v4.10.29 the quota request
 * ran on `WorkflowLedgerService` (`TeamSpaceQuotaService`, v4.10.2) and was
 * answered by the Nextcloud administrators. Justin, 2026-09-24: it is a
 * Nextcloud service, answered by the desk that holds the Nextcloud services
 * like the other six. So it has the service-request shape —
 *
 *   1. `submit`  — a team admin asks (size and reason). Sending is the step.
 *   2. `handle`  — the service team **grants** (the quota is written, here,
 *                  in `onStepCompleted()`) or **declines** with a reason.
 *   3. `confirm` — the requester closes the answered request.
 *
 * — and it is its own class rather than a `ServiceRequestDefinition`
 * because it is the one service whose answer *does* something, and whose
 * form asks for a size rather than a summary.
 *
 * **A desk member who is not a Nextcloud administrator can change a team's
 * quota.** That is the decision, not an accident: holding the Nextcloud
 * services is an administrator's delegation to that team, and a desk that
 * could approve but not grant would be a desk that asks an administrator
 * to do it — which is the workflow this replaced. The grant is bounded by
 * what the team asked for (never more, never on a team without a space)
 * and is on the record: the step's actor, the audit line and the desk's
 * activity stream all name who granted it.
 *
 * Started from Manage team → Files (the action menu on the Files section)
 * and from the card on the Services page, which asks which team. Both call
 * the engine's ordinary start route; everything the server must know —
 * that there is a space, that the size is an increase, what the quota is
 * now — is checked or recorded in `validateForTeam()`, never taken from the
 * client.
 *
 * **Licensed**, like every service: a request only a desk can answer
 * cannot start without one. The ledger version was licensed too, so no
 * instance loses anything it had.
 */
class QuotaRequestDefinition implements IWorkflowDefinition, IWorkflowDefinitionHooks {

    public const KEY     = 'teamspace_quota';
    /** v2 (v4.10.29): the desk's three steps. v1 (decide → close) never ran. */
    public const VERSION = 2;

    public const STEP_SUBMIT  = 'submit';
    public const STEP_HANDLE  = 'handle';
    public const STEP_CONFIRM = 'confirm';

    /** The largest request accepted: 10 TiB, well past any real team folder. */
    public const MAX_BYTES = 10 * 1024 * 1024 * 1024 * 1024;

    public const MAX_REASON = 1000;

    public function __construct(
        private ServiceTeamService $serviceTeams,
        private TeamSpaceService   $teamSpaces,
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
        return 'Team space quota request';
    }

    public function getTitle(IL10N $l, array $data): string {
        // The same string the ledger-based rows used (v4.10.2).
        return $l->t('Team space quota increase to %s', [Util::humanFileSize((int)($data['requestedBytes'] ?? 0))]);
    }

    public function getDescription(IL10N $l, array $data): string {
        return (string)($data['reason'] ?? '');
    }

    public function getSteps(): array {
        return [
            new WorkflowStepDefinition(self::STEP_SUBMIT,  'Request submitted',                WorkflowActor::initiator(), true),
            new WorkflowStepDefinition(self::STEP_HANDLE,  'Service team grants or declines',  WorkflowActor::serviceAgentPlaceholder()),
            new WorkflowStepDefinition(self::STEP_CONFIRM, 'Requester closes',                 WorkflowActor::initiator()),
        ];
    }

    public function getStepLabel(IL10N $l, string $stepKey): ?string {
        switch ($stepKey) {
            case self::STEP_SUBMIT:
                // TRANSLATORS: workflow step - the requester sent the request
                return $l->t('Request submitted');
            case self::STEP_HANDLE:
                // TRANSLATORS: workflow step - the service team grants or declines a request for more team storage
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
        $bytes  = (int)($data['requestedBytes'] ?? 0);
        $reason = trim((string)($data['reason'] ?? ''));
        if ($bytes <= 0 || $bytes > self::MAX_BYTES) {
            throw new ValidationException($this->l->t('The requested size is out of range.'));
        }
        if ($reason === '') {
            throw new ValidationException($this->l->t('A reason is required.'));
        }
        return [
            'requestedBytes' => $bytes,
            'reason'         => mb_substr($reason, 0, self::MAX_REASON),
        ];
    }

    /**
     * The team has a space, and the size is more than it has. The current
     * quota is recorded here, from the server, so the desk sees what the
     * team had when it asked — a client-sent `currentBytes` is dropped by
     * `validateStart()` before this runs.
     */
    public function validateForTeam(string $teamId, array $data): array {
        $space = $this->teamSpaces->getTeamSpace($teamId);
        if ($space === null) {
            throw new WorkflowTransitionException($this->l->t('This team has no team space to enlarge.'));
        }
        $current = (int)($space['quota'] ?? 0);
        if ($current > 0 && (int)$data['requestedBytes'] <= $current) {
            throw new ValidationException($this->l->t('The requested size is not larger than the current quota.'));
        }
        return $data + ['currentBytes' => $current];
    }

    /**
     * Granting is completing the desk's step: the quota is written first,
     * inside the transaction, so a provider that refuses leaves the request
     * waiting for its answer rather than reporting a grant that did not
     * happen. Idempotent — setting the same quota twice is the same quota.
     *
     * A space that has meanwhile grown to the requested size or beyond is
     * not shrunk back to it: that would be a grant that takes storage away.
     */
    public function onStepCompleted(WorkflowInstance $instance, string $stepKey, string $uid): void {
        if ($stepKey !== self::STEP_HANDLE) {
            return;
        }
        $teamId = $instance->getTeamId();
        $bytes  = (int)($instance->getData()['requestedBytes'] ?? 0);
        $space  = $this->teamSpaces->getTeamSpace($teamId);
        if ($space === null) {
            throw new WorkflowTransitionException($this->l->t('This team no longer has a team space; decline the request instead.'));
        }
        $current = (int)($space['quota'] ?? 0);
        if ($current > 0 && $current >= $bytes) {
            throw new WorkflowTransitionException($this->l->t('The team space already has this much storage or more; decline the request instead.'));
        }
        $this->teamSpaces->updateQuota($teamId, $bytes);
    }

    public function getActionLabels(IL10N $l, string $stepKey): array {
        switch ($stepKey) {
            case self::STEP_HANDLE:
                return [
                    // TRANSLATORS: button - give the team the storage it asked for
                    'complete' => $l->t('Grant'),
                    // TRANSLATORS: button - refuse a request for more team storage, with a reason
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
        return ['team_space', $teamId];
    }

    /** Offered while a desk holds it and this Nextcloud has team spaces. */
    public function isStartable(): bool {
        return $this->teamSpaces->isAvailable()
            && $this->serviceTeams->serviceTeamForDefinition(self::KEY) !== null;
    }

    /** Service Teams are licensed, so a request only a desk can answer is too. */
    public function allowsUnlicensedUse(): bool {
        return false;
    }

    /** The title needs the size; the reason stays with the instance. */
    public function getNotificationData(array $data): array {
        return [
            'requestedBytes' => (int)($data['requestedBytes'] ?? 0),
            'serviceKey'     => ServiceCatalogue::TEAM_QUOTA,
        ];
    }
}
