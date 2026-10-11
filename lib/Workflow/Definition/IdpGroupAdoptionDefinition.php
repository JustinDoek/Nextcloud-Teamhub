<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow\Definition;

use OCA\TeamHub\MyWork\SourceGroup;
use OCA\TeamHub\Db\IdpGroupMapper;
use OCA\TeamHub\Db\WorkflowInstance;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\IdpTeamProvisionService;
use OCA\TeamHub\Service\PolicyService;
use OCA\TeamHub\Service\Workflow\WorkflowActorResolver;
use OCA\TeamHub\Service\Workflow\WorkflowEngine;
use OCA\TeamHub\Workflow\IWorkflowDefinition;
use OCA\TeamHub\Workflow\IWorkflowDefinitionSource;
use OCA\TeamHub\Workflow\IWorkflowDefinitionActionPolicy;
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
 * Make a team for a Nextcloud group that came from the directory, or leave
 * it alone — the other half of *Waiting to join TeamHub* (v4.11.39,
 * DESIGN §2.172).
 *
 * `TeamAdoptionDefinition` is this one's sibling and they answer the same
 * question from two ends: a **team** that exists and has no record, and a
 * **group** that has a record and no team. §2.171 put both in one grid; this
 * puts both in My Work.
 *
 * ## Why a second definition and not a second subject kind
 *
 * HANDOFF `0-inherit-mywork` recommended extending the adoption definition.
 * It is separate because of one line in `WorkflowEngine::createInstance()`:
 * with `CONCURRENCY_ONE_OPEN_PER_TEAM` the engine refuses a second open
 * instance *per team id*, and a directory group has no team to key that on.
 * Keying both kinds on one definition would have meant either a sentinel
 * team id — one waiting group instance-wide, the rest silently refused — or
 * a new per-subject concurrency mode that every other definition would then
 * have to be read against. Justin, 2026-10-09: the sibling, keeping the
 * per-team rule, with the group as the team id.
 *
 * ## The group id *is* the team id, and what that costs
 *
 * `teamhub_wf_instance.team_id` is a `circles_circle.unique_id` everywhere
 * else. Here it is a Nextcloud group id, which buys the concurrency rule for
 * free — one open request per group, enforced by the engine, no new mode —
 * and costs exactly one thing: `WorkflowActorResolver::teamName()` finds no
 * circle and answers `''`. That is why it degrades rather than throws, and
 * why both views guard on `teamName` before rendering it; the group's name
 * is in the title, where it belongs. **Nothing here is team-relative** —
 * the only actor is the `admin` group — so no membership is ever resolved
 * against this id.
 *
 *   1. `submit` — the group was found; done when the request opens, in
 *                 TeamHub's own name (`PolicyService::ACTOR_SYSTEM`), because
 *                 a group has no owner and nobody asked for this.
 *   2. `decide`  — a team is made for it, or it is declined. **Always the
 *                 Nextcloud administrators**, never a desk: a desk decides
 *                 adoptions, directory groups are an administrator's, and
 *                 `/admin/idp-groups` is admin-gated (§2.171).
 *
 * **The decision is made in the grid**, where the template and policy are
 * chosen per row, so the request points there ({@see getReference()}) and the
 * row's own *Accept* carries whatever the grid holds. Completing the step
 * provisions ({@see onStepCompleted()}); rejecting declines
 * ({@see onEnded()}). Both are idempotent, because the grid may have
 * recorded the decision before it ended the request.
 *
 * Licensed, like every workflow. On an unlicensed instance no request is
 * opened and the grid is the only place — the same arrangement adoption has,
 * and the reason `IdpTeamProvisionService` itself stays licence-free.
 */
class IdpGroupAdoptionDefinition implements
    IWorkflowDefinition,
    IWorkflowDefinitionHooks,
    IWorkflowDefinitionEndHook,
    IWorkflowDefinitionReference,
    IWorkflowDefinitionActionPolicy,
    IWorkflowDefinitionSource,
    IWorkflowSystemStarted {

    public const KEY     = 'idp_group_adoption';
    public const VERSION = 1;

    public const STEP_SUBMIT = 'submit';
    public const STEP_DECIDE = 'decide';

    /** The only actor the decide step ever has. */
    public const ADMIN_GROUP = 'admin';

    public function __construct(
        private IdpTeamProvisionService $provisioning,
        private IdpGroupMapper          $groups,
        private IGroupManager           $groupManager,
        private IURLGenerator           $urlGenerator,
    ) {
    }

    public function getKey(): string {
        return self::KEY;
    }

    public function getVersion(): int {
        return self::VERSION;
    }

    public function getName(): string {
        return 'Directory group adoption';
    }

    public function getTitle(IL10N $l, array $data): string {
        // TRANSLATORS: title of a request to build a TeamHub team for a Nextcloud group that came from a directory; %s is the group's name
        return $l->t('Make a team for the group "%s"', [$this->nameOf($data)]);
    }

    public function getDescription(IL10N $l, array $data): string {
        return $l->t('This Nextcloud group qualifies for a team. Accept it to build a team whose members follow the group, with a template and a policy, or decline it to leave the group as it is.');
    }

    public function getSteps(): array {
        return [
            new WorkflowStepDefinition(self::STEP_SUBMIT, 'Group found',                  WorkflowActor::initiator(), true),
            new WorkflowStepDefinition(self::STEP_DECIDE, 'Team made or group declined',  WorkflowActor::group(self::ADMIN_GROUP)),
        ];
    }

    public function getStepLabel(IL10N $l, string $stepKey): ?string {
        switch ($stepKey) {
            case self::STEP_SUBMIT:
                // TRANSLATORS: workflow step - TeamHub found a Nextcloud group that qualifies for a team
                return $l->t('Group found');
            case self::STEP_DECIDE:
                // TRANSLATORS: workflow step - an administrator makes the team for the group or declines it
                return $l->t('Team made or group declined');
        }
        return null;
    }

    /**
     * Nothing to resolve: the decide step names the `admin` group in
     * {@see getSteps()}, so the engine never asks.
     */
    public function resolveActor(WorkflowStepDefinition $step, string $teamId, array $data): ?WorkflowActor {
        return null;
    }

    /**
     * One open request per group — see the class docblock. The group id is
     * the `team_id` the engine keys this on.
     */
    /**
     * v4.11.41 — the My Work source chip these requests appear under.
     * A Nextcloud administrator's instance-wide housekeeping, beside the
     * expiry and team-space admin rows already in that chip. Justin,
     * 2026-10-09: *in the my work page under administration just like we do
     * with expiry dates.*
     */
    public function sourceGroup(): string {
        return SourceGroup::ADMINISTRATION;
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
            'groupId'     => mb_substr(trim((string)($data['groupId'] ?? '')), 0, 64),
            'displayName' => mb_substr(trim((string)($data['displayName'] ?? '')), 0, 255),
            'idpGroupId'  => (int)($data['idpGroupId'] ?? 0),
        ];
    }

    public function validateForTeam(string $teamId, array $data): array {
        return $data;
    }

    /**
     * Completing the decision makes the team, with whatever template and
     * policy the grid holds — which for a directory group is the pair on the
     * Team creation tab, because a group has no stored pair of its own
     * (§2.171). `acceptPending()` moves the row out of `pending` first, so a
     * grid that got there a moment earlier wins and this is a no-op.
     */
    public function onStepCompleted(WorkflowInstance $instance, string $stepKey, string $uid): void {
        if ($stepKey !== self::STEP_DECIDE) {
            return;
        }
        $row = $this->rowFor($instance);
        if ($row === null || (string)($row['status'] ?? '') !== IdpGroupMapper::STATUS_PENDING) {
            return;
        }
        try {
            $this->provisioning->acceptPending((int)$row['id'], $uid);
        } catch (ValidationException | NotFoundException $e) {
            // Somebody decided it between the guard above and the write, or
            // the row went. The step is already completing inside the
            // engine's transaction, and re-throwing would roll the whole
            // request back over a decision that has in fact been made. Only
            // these two are that race — a provisioning *failure* does not
            // throw at all: it comes back as `ok: false` and is on the row
            // and in the audit log.
        }
    }

    /**
     * Rejected is declined. Cancelled is *not*: a request is cancelled when
     * the decision was taken in the grid, or when the group disappeared and
     * its row was forgotten (v4.11.37) — in both cases the row is already
     * what it should be, and writing a decline over it would invent one.
     */
    public function onEnded(WorkflowInstance $instance, string $outcome, string $uid, ?string $reason): void {
        if ($outcome !== WorkflowEngine::OUTCOME_REJECTED) {
            return;
        }
        $row = $this->rowFor($instance);
        if ($row === null || (string)($row['status'] ?? '') !== IdpGroupMapper::STATUS_PENDING) {
            return;
        }
        try {
            $this->provisioning->declinePending((int)$row['id'], $uid, (string)$reason);
        } catch (ValidationException | NotFoundException $e) {
            // Same race as above, same reason not to re-throw.
        }
    }

    /**
     * No. The initiator is `app:teamhub` and a group has no owner, so there
     * is nobody to ask — and asking would block the task for good. See
     * {@see IWorkflowDefinitionActionPolicy}.
     */
    public function allowsInformationRequest(): bool {
        return false;
    }

    /**
     * No. Declining *is* the no, and it records one; withdrawing decided
     * nothing and the task came back on the next reconcile. TeamHub may
     * still retire its own request — the refusal is scoped to a caller who
     * is not the initiator.
     */
    public function allowsWithdrawal(): bool {
        return false;
    }

    public function getActionLabels(IL10N $l, string $stepKey): array {
        if ($stepKey !== self::STEP_DECIDE) {
            return [];
        }
        return [
            // TRANSLATORS: button - build a TeamHub team for this Nextcloud group
            'complete' => $l->t('Accept'),
            // TRANSLATORS: button - leave a Nextcloud group without a team, with a reason
            'reject'   => $l->t('Decline'),
        ];
    }

    /**
     * The grid in Admin → TeamHub, which is where the template and policy
     * are chosen. Administrators only — the row has no other audience, and
     * the page behind the link is admin-gated anyway.
     */
    public function getReference(IL10N $l, WorkflowInstance $instance, string $viewerUid): ?array {
        if (!$this->groupManager->isAdmin($viewerUid)) {
            return null;
        }
        return [
            // TRANSLATORS: link from a request to the grid where groups and teams waiting to join TeamHub are decided
            'label' => $l->t('Choose template and policy in the team grid'),
            'url'   => $this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => 'teamhub']) . '#team-adoption',
        ];
    }

    /**
     * A group, not a team — the one place the subject says what this is
     * about, and what `teamhub_wf_instance.subject_type` records.
     */
    public function subjectOf(string $teamId, array $data): array {
        return ['group', (string)($data['groupId'] ?? $teamId)];
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
            'groupId'     => (string)($data['groupId'] ?? ''),
            'displayName' => $this->nameOf($data),
        ];
    }

    /** The group's display name, falling back to its id. */
    private function nameOf(array $data): string {
        $name = (string)($data['displayName'] ?? '');
        return $name !== '' ? $name : (string)($data['groupId'] ?? '');
    }

    /**
     * The `teamhub_idp_group` row this request is about: by the id it was
     * opened with, else by the group. The fallback matters because
     * v4.11.37 deletes and re-inserts a row when a group goes and comes
     * back, which gives the same group a new id.
     *
     * @return array<string,mixed>|null
     */
    private function rowFor(WorkflowInstance $instance): ?array {
        $data = $instance->getData();
        $id   = (int)($data['idpGroupId'] ?? 0);
        $row  = $id > 0 ? $this->groups->find($id) : null;
        if ($row !== null) {
            return $row;
        }
        $groupId = (string)($data['groupId'] ?? $instance->getTeamId());
        return $groupId !== '' ? $this->groups->findByGroup($groupId) : null;
    }

    /** The initiator for a request nobody asked for: TeamHub itself. */
    public static function systemActor(): string {
        return PolicyService::ACTOR_SYSTEM;
    }
}
