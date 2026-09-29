<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow\Definition;

use OCA\TeamHub\Constants\ServiceCatalogue;
use OCA\TeamHub\Db\TeamAdoptionMapper;
use OCA\TeamHub\Db\WorkflowInstance;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\TeamAdoptionDecisionService;
use OCA\TeamHub\Service\Workflow\WorkflowActorResolver;
use OCA\TeamHub\Service\Workflow\WorkflowEngine;
use OCA\TeamHub\Workflow\IWorkflowDefinition;
use OCA\TeamHub\Workflow\IWorkflowDefinitionEndHook;
use OCA\TeamHub\Workflow\IWorkflowDefinitionHooks;
use OCA\TeamHub\Workflow\IWorkflowDefinitionReference;
use OCA\TeamHub\Workflow\IWorkflowSystemStarted;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCA\TeamHub\Workflow\WorkflowStepDefinition;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Accept or decline a team made outside TeamHub — the eighth Nextcloud
 * service (v4.10.50, DESIGN §2.149).
 *
 * **Nobody asks for it.** `TeamAdoptionService`'s sweep finds a circle
 * TeamHub did not create and opens this request on its owner's behalf
 * (`WorkflowEngine::createForSystem()`), so it is {@see IWorkflowSystemStarted},
 * never startable from the public route, and has no card on the Services
 * page (`ServiceCatalogue::NO_CARD`).
 *
 *   1. `submit` — the team was found; done when the request opens, in the
 *                 owner's name.
 *   2. `decide` — accepted or declined. By the team that holds the Nextcloud
 *                 services when one does (a queue request, claimed like any
 *                 other), otherwise by the Nextcloud administrators (a task
 *                 in their My Work). One definition, two actors: which one is
 *                 decided in {@see resolveActor()} when the request opens.
 *
 * **The decision is made in a grid**, where the template and policy are
 * chosen per team (Admin → TeamHub, and a widget on the holding team). The
 * request points there ({@see getReference()}). Its own *Accept* works too
 * — it accepts with what the grid holds for the team, or the defaults — and
 * *Decline* asks for the reason like every rejection.
 *
 * The decision itself lives on the adoption row, not here: completing the
 * step accepts ({@see onStepCompleted()}), rejecting or withdrawing it
 * declines or withdraws ({@see onEnded()}). Both are idempotent, because the
 * grid may have recorded the decision before it ended the request.
 *
 * Licensed, like every service. On an unlicensed instance the sweep opens no
 * request and the grid is the only place (`TeamAdoptionService::route()`).
 */
class TeamAdoptionDefinition implements
    IWorkflowDefinition,
    IWorkflowDefinitionHooks,
    IWorkflowDefinitionEndHook,
    IWorkflowDefinitionReference,
    IWorkflowSystemStarted {

    public const KEY     = 'team_adoption';
    public const VERSION = 1;

    public const STEP_SUBMIT = 'submit';
    public const STEP_DECIDE = 'decide';

    /** The group whose members decide when no team holds the service. */
    public const ADMIN_GROUP = 'admin';

    public function __construct(
        private ServiceTeamService          $serviceTeams,
        private TeamAdoptionDecisionService $decisions,
        private TeamAdoptionMapper          $adoptions,
        private IGroupManager               $groupManager,
        private IURLGenerator               $urlGenerator,
    ) {
    }

    public function getKey(): string {
        return self::KEY;
    }

    public function getVersion(): int {
        return self::VERSION;
    }

    public function getName(): string {
        return 'Team adoption';
    }

    public function getTitle(IL10N $l, array $data): string {
        // TRANSLATORS: title of a request to take a team made outside TeamHub into TeamHub; {name} is the team's name
        return $l->t('Accept team "%s" into TeamHub', [(string)($data['teamName'] ?? '')]);
    }

    public function getDescription(IL10N $l, array $data): string {
        return $l->t('This team was made outside TeamHub. Accept it to show it in TeamHub with a template and a policy, or decline it to leave it out.');
    }

    public function getSteps(): array {
        return [
            new WorkflowStepDefinition(self::STEP_SUBMIT, 'Team found',                  WorkflowActor::initiator(), true),
            new WorkflowStepDefinition(self::STEP_DECIDE, 'Team accepted or declined',   WorkflowActor::serviceAgentPlaceholder()),
        ];
    }

    public function getStepLabel(IL10N $l, string $stepKey): ?string {
        switch ($stepKey) {
            case self::STEP_SUBMIT:
                // TRANSLATORS: workflow step - TeamHub found a team that was made outside TeamHub
                return $l->t('Team found');
            case self::STEP_DECIDE:
                // TRANSLATORS: workflow step - somebody accepts the team into TeamHub or declines it
                return $l->t('Team accepted or declined');
        }
        return null;
    }

    /** The holding service team when there is one, the administrators otherwise. */
    public function resolveActor(WorkflowStepDefinition $step, string $teamId, array $data): ?WorkflowActor {
        if ($step->key !== self::STEP_DECIDE) {
            return null;
        }
        $holder = $this->serviceTeams->serviceTeamForDefinition(self::KEY);
        return $holder !== null
            ? WorkflowActor::serviceAgent($holder)
            : WorkflowActor::group(self::ADMIN_GROUP);
    }

    public function getConcurrency(): string {
        return WorkflowDefinitionRegistry::CONCURRENCY_ONE_OPEN_PER_TEAM;
    }

    /** Never from the public route — see {@see IWorkflowSystemStarted}. */
    public function canStart(string $uid, string $teamId, WorkflowActorResolver $resolver): bool {
        return false;
    }

    public function validateStart(array $data): array {
        return [
            'teamName'   => mb_substr(trim((string)($data['teamName'] ?? '')), 0, 255),
            'adoptionId' => (int)($data['adoptionId'] ?? 0),
        ];
    }

    public function validateForTeam(string $teamId, array $data): array {
        return $data;
    }

    /**
     * Completing the decision is accepting. The row is read by the id the
     * request was opened with; the grid's template and policy are on it.
     */
    public function onStepCompleted(WorkflowInstance $instance, string $stepKey, string $uid): void {
        if ($stepKey !== self::STEP_DECIDE) {
            return;
        }
        $row = $this->rowFor($instance);
        if ($row === null) {
            return;
        }
        $this->decisions->accept($row, $uid);
    }

    /** Rejected is declined; withdrawn is withdrawn. */
    public function onEnded(WorkflowInstance $instance, string $outcome, string $uid, ?string $reason): void {
        $row = $this->rowFor($instance);
        if ($row === null || $row['status'] !== TeamAdoptionMapper::STATUS_PENDING) {
            return;
        }
        if ($outcome === WorkflowEngine::OUTCOME_REJECTED) {
            $this->decisions->decline($row, $uid, (string)$reason);
        } elseif ($outcome === WorkflowEngine::OUTCOME_CANCELLED) {
            $this->decisions->withdraw($row, $uid, (string)$reason);
        }
    }

    public function getActionLabels(IL10N $l, string $stepKey): array {
        if ($stepKey !== self::STEP_DECIDE) {
            return [];
        }
        return [
            // TRANSLATORS: button - take a team made outside TeamHub into TeamHub
            'complete' => $l->t('Accept'),
            // TRANSLATORS: button - leave a team made outside TeamHub out of TeamHub, with a reason
            'reject'   => $l->t('Decline'),
        ];
    }

    /**
     * The grid: on the holding team's home for its members, in Admin →
     * TeamHub for an administrator. Nothing for the owner who is waiting.
     */
    public function getReference(IL10N $l, WorkflowInstance $instance, string $viewerUid): ?array {
        $holder = $this->serviceTeams->serviceTeamForDefinition(self::KEY);
        if ($holder !== null && $this->serviceTeams->isEligibleAgent($viewerUid, $holder)) {
            return [
                // TRANSLATORS: link from a request to the grid where teams made outside TeamHub are accepted with a template and policy
                'label' => $l->t('Choose template and policy in the team grid'),
                'url'   => $this->urlGenerator->linkToRoute('teamhub.page.index') . '?team=' . rawurlencode($holder),
            ];
        }
        if ($this->groupManager->isAdmin($viewerUid)) {
            return [
                'label' => $l->t('Choose template and policy in the team grid'),
                'url'   => $this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => 'teamhub']) . '#team-adoption',
            ];
        }
        return null;
    }

    public function subjectOf(string $teamId, array $data): array {
        return ['team', $teamId];
    }

    /** See {@see IWorkflowSystemStarted}: the public route must refuse it. */
    public function isStartable(): bool {
        return false;
    }

    public function allowsUnlicensedUse(): bool {
        return false;
    }

    public function getNotificationData(array $data): array {
        return [
            'teamName'   => (string)($data['teamName'] ?? ''),
            'serviceKey' => ServiceCatalogue::TEAM_ADOPTION,
        ];
    }

    /** @return array<string,mixed>|null */
    private function rowFor(WorkflowInstance $instance): ?array {
        $id = (int)($instance->getData()['adoptionId'] ?? 0);
        $row = $id > 0 ? $this->adoptions->find($id) : null;
        return $row ?? $this->adoptions->findByTeam($instance->getTeamId());
    }
}
