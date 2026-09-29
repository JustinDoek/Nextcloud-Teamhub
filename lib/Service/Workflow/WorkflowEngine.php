<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Workflow;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\WorkflowInstance;
use OCA\TeamHub\Db\WorkflowInstanceMapper;
use OCA\TeamHub\Db\WorkflowParticipant;
use OCA\TeamHub\Db\WorkflowParticipantMapper;
use OCA\TeamHub\Db\WorkflowAttachmentMapper;
use OCA\TeamHub\Db\WorkflowStep;
use OCA\TeamHub\Db\WorkflowStepMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\LicenseGateException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Exception\WorkflowRateLimitException;
use OCA\TeamHub\Exception\WorkflowTransitionException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Workflow\IWorkflowDefinition;
use OCA\TeamHub\Workflow\IWorkflowDefinitionHooks;
use OCA\TeamHub\Workflow\IWorkflowClosableByDesk;
use OCA\TeamHub\Workflow\IWorkflowDefinitionEndHook;
use OCA\TeamHub\Workflow\IWorkflowDefinitionReference;
use OCA\TeamHub\Workflow\IWorkflowSystemStarted;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowAttachmentVisibility;
use OCA\TeamHub\Workflow\WorkflowCapability;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCA\TeamHub\Workflow\WorkflowEventType;
use OCA\TeamHub\Workflow\WorkflowParticipantRole;
use OCA\TeamHub\Workflow\WorkflowStatus;
use OCA\TeamHub\Workflow\WorkflowStepStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * The WorkflowHub engine (phase 1–2, v4.10.13–14; `docs/workflowhub-architecture.md` §5).
 *
 * The only writer of `teamhub_wf_instance`, `teamhub_wf_step`,
 * `teamhub_wf_participant` and, through `WorkflowEventService`,
 * `teamhub_wf_event`. Controllers, providers and definitions never change
 * a status themselves: they call one of the methods below, and every one
 * of them does the same things, in one transaction —
 *
 *   1. establishes who the caller is to the workflow (initiator, a holder
 *      of the active step's actor, any participant, a Nextcloud
 *      administrator) and refuses with `AccessDeniedException` otherwise —
 *      from the session uid and the live roles, never from the request body;
 *   2. asserts the step and instance transitions against the tables in
 *      `WorkflowStepStatus` / `WorkflowStatus` and refuses with
 *      `WorkflowTransitionException` (the caller's view was stale: 409);
 *   3. writes the rows;
 *   4. appends one event per change and one audit line;
 *
 * and, after the commit, sends the notifications the change earned
 * (`WorkflowNotificationService`) — never before, so a rolled-back change
 * tells nobody anything.
 *
 * ## Sequential steps, parallel tasks
 *
 * Steps run in order: no branches, no loops (product rules 14–15). A step
 * of a built service may hold several **tasks** (v4.10.37,
 * `docs/service-builder.md` § 4) — one row each, sharing the step's
 * `step_order`. All tasks of a step become available together; the next
 * step becomes available when every task of this one that is not
 * *non-blocking* is completed; a non-blocking task stays open while the
 * request moves on, and must be done before the request can end. Every
 * verb acts on **one row** — named by its key, or the one the caller has
 * (`pickStep()`); a step of one task, which is every built-in step, is
 * exactly the step it was. Rejecting a task rejects the workflow and
 * skips every other open task; cancelling cancels them all. A first step
 * marked `autoCompleteOnCreate` is done in the initiator's name at
 * creation — sending the request is that step.
 *
 * ## The instance status follows the steps
 *
 * `deriveOpenStatus()` computes `submitted` / `in_progress` / `waiting`
 * from the step rows; the engine never stores a status the steps do not
 * support. `blocked` is the one status a person declares (`block()`), and
 * lifting it re-derives from the steps.
 *
 * ## Participants are kept
 *
 * The initiator and every step's actor are written as participants at
 * creation; a person who acts on a group or team-role step is added as an
 * `actor` participant. No transition removes a participant row, so a
 * person whose step is done keeps the workflow in their list until it
 * ends — the product rule this table exists for.
 *
 * ## Definition version
 *
 * `create()` stamps `definition_version` and copies the steps into rows
 * (turning the definition-only `initiator` actor into `user:{uid}`).
 * From then on the engine reads the step rows, never the definition; an
 * instance started on version 1 finishes on version 1.
 *
 * Every method returns the instance as a plain array (`viewOf()`), with a
 * `viewer` block saying what the caller may do next and nothing internal —
 * no row ids but the instance's, no retention, no removed participants.
 *
 * ## The licence (phase 4, v4.10.16)
 *
 * The instance is licensed or it is not; `WorkflowLicenceTier` says which,
 * per request, and this class is the only place that acts on the answer
 * (`docs/unlicensed-workflow-data-lifecycle.md`):
 *
 *   - **Starting** a definition that does not allow unlicensed use is
 *     refused on the basic tier (`LicenseGateException` → 403).
 *   - **Completing, rejecting, cancelling, answering** never read the
 *     licence: an expired licence must not block a workflow that exists.
 *   - **Asking for a status update** is licensed only.
 *   - **The view** on the basic tier carries the responsible actor but no
 *     step list, no current step and no progress; the history is the
 *     basic timeline (no step keys, no step bookkeeping).
 *   - **An ended workflow on the basic tier is purged** — instance, steps,
 *     participants, events — *inside the ending transaction*
 *     (`finish()` → `purge()`). The caller gets the last view once, the
 *     participants get the ending notification once, and nothing else
 *     ever answers for the workflow again: `get()` is 404, the list does
 *     not carry it, no archive, no result record. When the purge fails the
 *     transaction rolls back and the workflow is still open — completion
 *     and removal are one thing or none.
 *   - **Ended workflows the licence retained** stay while it lasts and are
 *     hidden (404) on the basic tier; a later licence shows them again.
 *     Nothing purged is ever restored.
 *
 * ## Archiving (phase 6, v4.10.21)
 *
 * The licensed half of that same branch. Where the unlicensed tier purges
 * an ended workflow, the licensed tier **records** it: `finish()` hands
 * the instance and its steps to `WorkflowArchiveService::record()`, inside
 * the same transaction, and gets back one authoritative archive record
 * plus its projections (`docs/workflow-archiving.md`). Three things follow,
 * and together they are what keeps phase 6 from disturbing anything phase
 * 4 decided:
 *
 *   - **the unlicensed path is untouched.** `retainsEnded()` still decides,
 *     and the purge branch is the one phase 4 wrote, line for line;
 *   - **the record is part of the ending.** A failing archive rolls the
 *     completion back with it, so there is no completed workflow without a
 *     record and no record without a completed workflow;
 *   - **the engine never reads an archive.** No transition consults one and
 *     nothing here branches on one — archiving is the last thing that
 *     happens to a workflow, not a state a workflow can be in.
 */
class WorkflowEngine {

    public const OUTCOME_COMPLETED = 'completed';
    public const OUTCOME_REJECTED  = 'rejected';
    public const OUTCOME_CANCELLED = 'cancelled';
    /**
     * v4.10.31 — an admin of the service team closed the request. Neither a
     * success nor a rejection: the steps done stay done, the rest are
     * skipped, and the people involved read from the steps how it went.
     */
    public const OUTCOME_CLOSED    = 'closed';

    /** A participant may ask the responsible actor for a status update this often, per step. */
    public const STATUS_REQUEST_INTERVAL = 24 * 3600;

    /**
     * The basic timeline (phase 4): what `listEvents()` returns on the
     * unlicensed tier. Everything that says *what happened between people*;
     * nothing that says *which step* (`step_available`, `step_started`,
     * `step_skipped`) or is bookkeeping (`participant_added`).
     */
    public const BASIC_TIMELINE_EVENTS = [
        WorkflowEventType::CREATED,
        WorkflowEventType::STEP_COMPLETED,
        WorkflowEventType::STEP_REJECTED,
        WorkflowEventType::INFORMATION_REQUESTED,
        WorkflowEventType::INFORMATION_PROVIDED,
        WorkflowEventType::STATUS_REQUESTED,
        WorkflowEventType::MESSAGE,
        WorkflowEventType::BLOCKED,
        WorkflowEventType::UNBLOCKED,
        WorkflowEventType::COMPLETED,
        WorkflowEventType::REJECTED,
        WorkflowEventType::CANCELLED,
    ];

    private const MAX_TEXT = 1000;

    /** @var array<int, callable(): void> what to do once the current transaction has committed */
    private array $afterCommit = [];

    /**
     * @var array<int, callable(): void> v4.10.38 — the paperclip's shares for
     * the current write, made after the commit unless the write ended the
     * request: an ended request's shares are removed, not made (`finish()`).
     */
    private array $pendingShares = [];

    public function __construct(
        private WorkflowDefinitionRegistry  $registry,
        private WorkflowInstanceMapper      $instances,
        private WorkflowStepMapper          $steps,
        private WorkflowParticipantMapper   $participants,
        private WorkflowEventService        $events,
        private WorkflowActorResolver       $resolver,
        private WorkflowNotificationService $notifier,
        private WorkflowLicenceTier         $tier,
        // v4.10.20 - service teams: who may claim a queued step, who the
        // owner is, and who may read an internal note. Asked, never told:
        // the engine never writes a service team.
        private ServiceTeamService          $serviceTeams,
        // v4.10.21 - archiving. The engine tells it a workflow has ended
        // and hands over the rows it already has; it never reads an
        // archive back, and no decision here depends on one.
        private WorkflowArchiveService      $archive,
        private WorkflowAttachmentMapper    $attachments,
        private AuditService                $auditService,
        private IDBConnection               $db,
        private ITimeFactory                $timeFactory,
        private IL10N                       $l,
        private LoggerInterface             $logger,
        // v4.10.38 — the paperclip: files shared with the other side of a
        // service request for a while (`docs/service-builder.md` § 7).
        // Optional so an engine built without it (the unit tests) simply
        // takes no files.
        private ?WorkflowShareService       $shares = null,
    ) {
    }

    // ──────────────────────────────────────────────────────────────────────
    // Creation
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Open a workflow of one definition on one team.
     *
     * The definition decides who may start it and what the payload must
     * look like; the engine decides the rest: the version is stamped, the
     * steps are copied, the first step becomes available (or is completed
     * at once when the definition says sending the request *is* the first
     * step), the initiator and every step's actor become participants, and
     * the events are written. The holders of the first step somebody else
     * has to take are notified after the commit.
     *
     * @param array<string, mixed> $data the opening payload, validated by the definition
     * @return array<string, mixed>
     * @throws NotFoundException unknown definition
     * @throws AccessDeniedException the definition refuses this starter
     * @throws ValidationException bad payload
     * @throws WorkflowTransitionException a workflow of this kind is already open on the team
     * @throws LicenseGateException the definition is not allowed unlicensed and the instance is not licensed
     */
    public function create(string $definitionKey, string $teamId, string $uid, array $data = []): array {
        $definition = $this->registry->get($definitionKey);
        if ($definition === null) {
            throw new NotFoundException($this->l->t('There is no workflow of that kind.'));
        }
        if (!$definition->isStartable()) {
            // v4.10.17 — a registered but dark definition (the feature
            // switch of docs/workflowhub-architecture.md §11 step 2). The
            // same answer as an unknown key: it is not offered, so to a
            // client it does not exist. Reading is unaffected — instances
            // that already exist keep resolving their definition.
            throw new NotFoundException($this->l->t('There is no workflow of that kind.'));
        }
        if ($teamId === '' || $uid === '') {
            throw new ValidationException($this->l->t('A team and a user are required.'));
        }
        if (!$definition->allowsUnlicensedUse() && !$this->tier->isFull()) {
            // Phase 4: a definition not allowed for unlicensed use hides on
            // the basic tier (describeDefinitions) and refuses here — the
            // one licence check on the way in. Nothing on the way out has one.
            throw new LicenseGateException($this->tier->enforcementLevel(), 'This workflow requires an active TeamHub licence.');
        }
        if (!$definition->canStart($uid, $teamId, $this->resolver)) {
            throw new AccessDeniedException($this->l->t('You cannot start this workflow on this team.'));
        }
        // v4.10.38 — files picked on the request form are the requester's to
        // share with the team, not part of the request's data.
        $fileIds = $data['fileIds'] ?? [];
        unset($data['fileIds']);
        $data = $definition->validateStart($data);
        if ($definition instanceof IWorkflowDefinitionHooks) {
            // v4.10.29 — the checks the payload alone cannot answer.
            $data = $definition->validateForTeam($teamId, $data);
        }

        return $this->createInstance($definition, $teamId, $uid, $data, $fileIds);
    }

    /**
     * Start a workflow TeamHub itself opens, on behalf of `$initiatorUid`
     * (v4.10.50). Only for a definition marked {@see IWorkflowSystemStarted};
     * `isStartable()` and `canStart()` are the public route's questions and
     * are not asked — the caller is server code that has already decided.
     * The licence is still asked: a system-started request is as licensed
     * as any other.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws NotFoundException|LicenseGateException|ValidationException|WorkflowTransitionException
     */
    public function createForSystem(string $definitionKey, string $teamId, string $initiatorUid, array $data = []): array {
        $definition = $this->registry->get($definitionKey);
        if (!$definition instanceof IWorkflowSystemStarted) {
            throw new NotFoundException($this->l->t('There is no workflow of that kind.'));
        }
        if ($teamId === '' || $initiatorUid === '') {
            throw new ValidationException($this->l->t('A team and a user are required.'));
        }
        if (!$definition->allowsUnlicensedUse() && !$this->tier->isFull()) {
            throw new LicenseGateException($this->tier->enforcementLevel(), 'This workflow requires an active TeamHub licence.');
        }
        $data = $definition->validateStart($data);
        if ($definition instanceof IWorkflowDefinitionHooks) {
            $data = $definition->validateForTeam($teamId, $data);
        }
        return $this->createInstance($definition, $teamId, $initiatorUid, $data, []);
    }

    /**
     * The shared body of {@see create()} and {@see createForSystem()}: every
     * check has been made, the data is validated.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function createInstance(IWorkflowDefinition $definition, string $teamId, string $uid, array $data, mixed $fileIds): array {
        return $this->transactional(function () use ($definition, $teamId, $uid, $data, $fileIds): array {
            if ($definition->getConcurrency() === WorkflowDefinitionRegistry::CONCURRENCY_ONE_OPEN_PER_TEAM
                && $this->instances->findOpenForDefinitionAndTeam($definition->getKey(), $teamId, WorkflowStatus::OPEN) !== []
            ) {
                throw new WorkflowTransitionException($this->l->t('This team already has an open workflow of this kind.'));
            }

            $now = $this->timeFactory->getTime();
            [$subjectType, $subjectId] = $definition->subjectOf($teamId, $data);

            $instance = new WorkflowInstance();
            $instance->setDefinitionKey($definition->getKey());
            $instance->setDefinitionVersion($definition->getVersion());
            $instance->setTeamId($teamId);
            $instance->setSubjectType((string)$subjectType);
            $instance->setSubjectId((string)$subjectId);
            $instance->setStatus(WorkflowStatus::SUBMITTED);
            $instance->setStartedBy($uid);
            $instance->setStartedAt($now);
            $instance->setUpdatedAt($now);
            $instance->setRetentionUntil(0);
            $instance->setData($data);
            $instance = $this->instances->insert($instance);
            $id       = (int)$instance->getId();

            // Materialise the steps: the instance's own copy of the definition.
            // The definition-only `initiator` actor becomes this user.
            $stepRows  = [];
            $order     = 0;
            $prevStage = null;
            foreach ($definition->getSteps() as $def) {
                // v4.10.37 — the tasks of one step share its order.
                if ($def->stage === null || $def->stage !== $prevStage) {
                    $order++;
                }
                $prevStage = $def->stage;
                $actor = $def->actor;
                if ($actor->isInitiator()) {
                    $actor = WorkflowActor::user($uid);
                } elseif ($actor->isUnresolved()) {
                    // v4.10.20 - a placeholder the definition fills in from
                    // the payload: today the service team that handles this
                    // request. Refused rather than stored empty, because a
                    // step whose actor nobody holds is a queue nobody works.
                    $actor = $definition->resolveActor($def, $teamId, $data);
                    if ($actor === null || $actor->isUnresolved()) {
                        throw new WorkflowTransitionException(
                            $this->l->t('This request has no service team to handle it.'));
                    }
                }
                $step  = new WorkflowStep();
                $step->setInstanceId($id);
                $step->setStepKey($def->key);
                $step->setStepOrder($order);
                $step->setStepStatus(WorkflowStepStatus::PENDING);
                $step->setActorType($actor->type);
                $step->setActorId($actor->id);
                $step->setLabel(mb_substr($def->label, 0, 255));
                // v4.10.31 — the role a built service's desk step needs.
                $step->setRoleLabel($def->roleLabel);
                // v4.10.36 — the links a built service put on the step.
                $step->setLinks($def->links === []
                    ? null
                    : json_encode(array_values($def->links), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                // v4.10.37 — the step's name over its tasks, and whether the
                // request may move on without this one.
                $step->setStageLabel(mb_substr($def->stageLabel, 0, 255));
                $step->setNonBlocking($def->nonBlocking ? 1 : 0);
                if ($order === 1) {
                    $step->setStepStatus(WorkflowStepStatus::AVAILABLE);
                    $step->setEnteredAt($now);
                }
                $stepRows[] = $this->steps->insert($step);
            }
            $instance->setCurrentStep($stepRows[0]->getStepKey());
            $instance = $this->instances->update($instance);

            // Participants: the initiator, then every step's actor once.
            $participants = [];
            $participants[] = $this->addParticipant($instance, WorkflowActor::user($uid), WorkflowParticipantRole::INITIATOR, $uid, []);
            $seen = [WorkflowActor::user($uid)->key() => true];
            foreach ($stepRows as $step) {
                $actor = WorkflowActor::of($step->getActorType(), $step->getActorId());
                if (isset($seen[$actor->key()])) {
                    continue;
                }
                $seen[$actor->key()] = true;
                $participants[] = $this->addParticipant($instance, $actor, WorkflowParticipantRole::RESPONSIBLE, $uid, []);
            }

            $files = $this->attachFiles($instance, $stepRows, $uid, $fileIds, false, null);
            $this->events->record($id, WorkflowEventType::CREATED, $uid, null, [
                'definitionKey'     => $definition->getKey(),
                'definitionVersion' => $definition->getVersion(),
            ] + ($files !== [] ? ['files' => $files] : []));
            $firstStage = $this->rowsOfOrder($stepRows, 1);
            foreach ($firstStage as $row) {
                $this->events->record($id, WorkflowEventType::STEP_AVAILABLE, null, $row);
            }
            $this->audit($instance, 'created', $uid, ['definitionVersion' => $definition->getVersion()]);

            // Sending the request *is* the first step: done, in the initiator's name.
            $first = $definition->getSteps()[0];
            if ($first->autoCompleteOnCreate) {
                $this->assertHolder($uid, $stepRows[0], $instance);
                $this->startStepRow($instance, $stepRows[0], $uid, $participants);
                $instance = $this->completeStepRow($instance, $stepRows, $stepRows[0], $uid, null);
            } else {
                $this->notifyStageAvailable($instance, $firstStage, null);
                $this->deskAuditStage($instance, $firstStage, 'received', $uid);
            }

            // A one-step definition that auto-completes ends here; `finish()`
            // applies the unlicensed purge in that case like everywhere else.
            return $this->finish($instance, $stepRows, $uid);
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    // Reads
    // ──────────────────────────────────────────────────────────────────────

    /**
     * One workflow, for a participant or a Nextcloud administrator. On the
     * basic tier an ended workflow does not exist for anybody — 404, the
     * same answer a purged one gives — so the history the licence retained
     * is not reachable while the licence is not there.
     *
     * @return array<string, mixed>
     * @throws NotFoundException
     * @throws AccessDeniedException
     */
    public function get(int $id, string $uid, ?string $stepKey = null): array {
        $instance     = $this->loadVisible($id);
        $steps        = $this->steps->findByInstance($id);
        $participants = $this->participants->findByInstance($id);
        $this->assertMayView($uid, $instance, $participants);
        // v4.10.37 — `$stepKey` is the task the caller opened the request
        // from; the view's `viewer` block answers for that task.
        return $this->viewOf($instance, $steps, $participants, $uid, null, $stepKey);
    }

    /**
     * The one step somebody can act on, or null when the workflow has
     * ended.
     *
     * @return array<string, mixed>|null
     */
    public function getActiveStep(int $id, string $uid): ?array {
        $view = $this->get($id, $uid);
        foreach ($view['steps'] as $step) {
            if (WorkflowStepStatus::isActive($step['status'])) {
                return $step;
            }
        }
        return null;
    }

    /**
     * The history of one workflow, oldest first. On the basic tier this is
     * the *basic timeline*: the events a participant can act on or was told
     * about, without the step bookkeeping (`step_available`, `step_started`,
     * `step_skipped`, `participant_added`) and without step ids or keys —
     * which together would name the current step the tier does not show.
     *
     * **Internal events are removed first** (v4.10.20), for everybody who is
     * not an eligible agent of the service team handling the workflow — the
     * requester and every other participant included, and a Nextcloud
     * administrator included, because being able to administer a server is
     * not the same as being on the desk. That filter is applied before the
     * tier's, so a licence can never widen what the requester reads.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listEvents(int $id, string $uid): array {
        $instance = $this->loadVisible($id);
        $steps    = $this->steps->findByInstance($id);
        $this->assertMayView($uid, $instance, $this->participants->findByInstance($id));

        $events = $this->events->listForInstance($id);
        if (!$this->isHandlingAgent($uid, $steps)) {
            $events = array_values(array_filter(
                $events,
                static fn (array $e): bool => ($e['visibility'] ?? WorkflowEventType::VISIBILITY_ALL)
                    !== WorkflowEventType::VISIBILITY_INTERNAL,
            ));
        }

        if ($this->tier->can(WorkflowCapability::VIEW_CURRENT_STEP)) {
            return $events;
        }
        $out = [];
        foreach ($events as $event) {
            if (!in_array($event['type'], self::BASIC_TIMELINE_EVENTS, true)) {
                continue;
            }
            $event['stepId']  = null;
            $event['stepKey'] = null;
            $out[] = $event;
        }
        return $out;
    }

    /**
     * Every workflow the user takes part in — as a named participant, as a
     * member of a participating group, or as a current holder of a team
     * role on the instance's team — most recently updated first.
     *
     * Two lookups: the direct actors (uid, groups) hit the participant
     * table by key; the team-relative actors come back as candidates on the
     * user's teams and are resolved one by one, so a member of the team
     * does not see the owner's step and a former owner does not keep it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForParticipant(string $uid, ?string $status = null): array {
        if ($status !== null && !WorkflowStatus::isValid($status)) {
            throw new ValidationException($this->l->t('That is not a workflow status.'));
        }
        // Phase 4: no completed history on the basic tier. A terminal
        // status asked for by name answers empty rather than 403 — the
        // list is "what you can see", and that is nothing.
        $retained = $this->tier->can(WorkflowCapability::COMPLETED_HISTORY);
        if (!$retained && $status !== null && !WorkflowStatus::isOpen($status)) {
            return [];
        }

        $direct = [[WorkflowActor::TYPE_USER, $uid]];
        foreach ($this->resolver->groupsOf($uid) as $gid) {
            $direct[] = [WorkflowActor::TYPE_GROUP, $gid];
        }
        // v4.10.23 — the desks this person works. Without this a service
        // agent could open a request their desk holds (`get()` resolves the
        // participant row through `holds()`) but never find it in a list,
        // which is the one place the index and the permission rule had
        // drifted apart. It matters more now that the team request is
        // handled by a desk: before, step 3 was a group, and a group actor
        // was already indexed here.
        $ids = $this->participants->findInstanceIdsForActors($direct);
        // v4.10.31 — …but a desk member's **My Work** holds only the
        // requests they are involved in: one they claimed or worked a step
        // of. The rest of the desk's requests are the desk's, and live in
        // the service team's widget (`listQueue()`); listing every one of
        // them in every member's My Work is the noise Justin asked to
        // remove. `get()` is unchanged: any member may still open any
        // request their desk holds.
        $deskActors = [];
        foreach ($this->serviceTeams->serviceTeamsForAgent($uid) as $serviceTeamId) {
            $deskActors[] = [WorkflowActor::TYPE_SERVICE_AGENT, $serviceTeamId];
        }
        $deskOnly = $deskActors === []
            ? []
            : array_values(array_diff($this->participants->findInstanceIdsForActors($deskActors), $ids));
        $ids = array_merge($ids, $deskOnly);

        $teams = $this->resolver->teamsOf($uid);
        if ($teams !== []) {
            $candidates = $this->participants->findTeamRelativeForTeams(array_keys($teams), WorkflowActor::TEAM_RELATIVE);
            foreach ($candidates as $c) {
                if ($this->resolver->holds($uid, WorkflowActor::of($c['actorType'], ''), $c['teamId'])) {
                    $ids[] = $c['instanceId'];
                }
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $instances = $this->instances->findByIds($ids, $status);
        if (!$retained) {
            $instances = array_values(array_filter($instances, static fn (WorkflowInstance $i): bool => WorkflowStatus::isOpen($i->getStatus())));
        }
        $stepsById = $this->steps->findByInstances(array_map(static fn (WorkflowInstance $i): int => (int)$i->getId(), $instances));
        if ($deskOnly !== []) {
            $deskOnlySet = array_flip($deskOnly);
            $instances   = array_values(array_filter(
                $instances,
                fn (WorkflowInstance $i): bool => !isset($deskOnlySet[(int)$i->getId()])
                    || $this->workedOn($uid, $stepsById[(int)$i->getId()] ?? []),
            ));
        }

        // v4.10.17 — one query for the viewer's own recent status requests
        // across the whole list, so `viewer.canRequestStatus` can respect the
        // 24-hour cooldown without a query per row.
        $asks = $this->events->recentStatusRequests(
            array_map(static fn (WorkflowInstance $i): int => (int)$i->getId(), $instances),
            $uid,
            $this->timeFactory->getTime() - self::STATUS_REQUEST_INTERVAL,
        );

        $out = [];
        foreach ($instances as $instance) {
            $id    = (int)$instance->getId();
            $steps = $stepsById[$id] ?? [];
            $parts = $this->participants->findByInstance($id);
            // v4.10.37 — one item per party, per task: a person with two
            // tasks open on one request (two requester tasks of one step,
            // two team tasks they claimed) gets a row for each, each
            // answering for its own task. Everybody else gets the one row.
            $own = WorkflowStatus::isOpen($instance->getStatus()) ? $this->ownActiveRows($uid, $steps) : [];
            if (count($own) < 2) {
                $out[] = $this->viewOf($instance, $steps, $parts, $uid, $asks[$id] ?? []);
                continue;
            }
            foreach ($own as $row) {
                $out[] = $this->viewOf($instance, $steps, $parts, $uid, $asks[$id] ?? [], $row->getStepKey());
            }
        }
        return $out;
    }

    /**
     * The open tasks that are this person's own (v4.10.37): claimed by or
     * assigned to them, or theirs by name — a requester's task.
     *
     * @param WorkflowStep[] $steps
     * @return WorkflowStep[]
     */
    private function ownActiveRows(string $uid, array $steps): array {
        return array_values(array_filter(
            $this->activeStepsOf($steps),
            static fn (WorkflowStep $s): bool => $s->getAssignee() === $uid
                || ($s->getActorType() === WorkflowActor::TYPE_USER && $s->getActorId() === $uid),
        ));
    }

    /**
     * Did this person take part in the desk's work on a request (v4.10.31)?
     * They claimed, were assigned, or completed one of its service-team
     * steps.
     *
     * @param WorkflowStep[] $steps
     */
    private function workedOn(string $uid, array $steps): bool {
        foreach ($steps as $step) {
            if ($step->getActorType() !== WorkflowActor::TYPE_SERVICE_AGENT) {
                continue;
            }
            if ($step->getAssignee() === $uid || $step->getCompletedBy() === $uid) {
                return true;
            }
        }
        return false;
    }

    /**
     * Display names for uids a view mentions (v4.10.15) — the people on the
     * steps and the participant list, and the history's actors. Only uids
     * the caller was already given are resolved; this adds no person.
     *
     * @param string[] $uids
     * @return array<string, string> uid → display name
     */
    public function describePeople(array $uids): array {
        $out = [];
        foreach (array_unique(array_filter(array_map('strval', $uids))) as $uid) {
            $out[$uid] = $this->resolver->displayName($uid);
        }
        return $out;
    }

    /**
     * The definitions somebody may start, with their steps — what a client
     * needs to offer a "request …" button. On the basic tier only the
     * definitions explicitly allowed for unlicensed use are listed (hidden,
     * not disabled); `unlicensed` says which they are on either tier.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describeDefinitions(): array {
        $full = $this->tier->isFull();
        $out  = [];
        foreach ($this->registry->all() as $definition) {
            // v4.10.17 — a dark definition is not offered to anybody.
            if (!$definition->isStartable()) {
                continue;
            }
            if (!$full && !$definition->allowsUnlicensedUse()) {
                continue;
            }
            $steps = [];
            foreach ($definition->getSteps() as $step) {
                $steps[] = [
                    'key'   => $step->key,
                    'label' => $definition->getStepLabel($this->l, $step->key) ?? $step->label,
                    'actor' => ['type' => $step->actor->type, 'id' => $step->actor->id],
                ];
            }
            $out[] = [
                'key'         => $definition->getKey(),
                'version'     => $definition->getVersion(),
                'name'        => $definition->getName(),
                'concurrency' => $definition->getConcurrency(),
                'unlicensed'  => $definition->allowsUnlicensedUse(),
                'steps'       => $steps,
            ];
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Step transitions
    // ──────────────────────────────────────────────────────────────────────

    /**
     * A holder of the active step's actor takes it up: available → in
     * progress. The workflow leaves `submitted` on its first start.
     *
     * @return array<string, mixed>
     */
    public function startStep(int $id, string $uid, ?string $stepKey = null): array {
        return $this->transactional(function () use ($id, $uid, $stepKey): array {
            [$instance, $steps, $participants] = $this->loadOpen($id);
            $step = $this->pickStep($instance, $steps, $uid, $stepKey);
            $this->assertHolder($uid, $step, $instance);
            $this->assertNotBlocked($instance);
            if ($step->getStepStatus() !== WorkflowStepStatus::AVAILABLE) {
                throw new WorkflowTransitionException($this->l->t('This step has already been started.'));
            }

            $this->startStepRow($instance, $step, $uid, $participants);
            $instance = $this->reflectSteps($instance, $steps);
            $this->audit($instance, 'step_started', $uid, ['step' => $step->getStepKey()]);
            return $this->finish($instance, $steps, $uid, $step->getStepKey());
        });
    }

    /**
     * A holder of the active step's actor completes it. A step that is
     * merely available is started and completed in one go — the one-click
     * action a My Work row will offer — and both events are written.
     * Completing the last step completes the workflow.
     *
     * @return array<string, mixed>
     */
    public function completeStep(int $id, string $uid, ?string $note = null, ?string $stepKey = null, mixed $fileIds = []): array {
        return $this->transactional(function () use ($id, $uid, $note, $stepKey, $fileIds): array {
            [$instance, $steps, $participants] = $this->loadOpen($id);
            $step = $this->pickStep($instance, $steps, $uid, $stepKey);
            $this->assertHolder($uid, $step, $instance);
            $this->assertNotBlocked($instance);
            $this->assertMayEnd($steps, $step);
            $note  = $this->text($note);
            $files = $this->attachFiles($instance, $steps, $uid, $fileIds, false, $step->getStepKey());

            if ($step->getStepStatus() === WorkflowStepStatus::AVAILABLE) {
                $this->startStepRow($instance, $step, $uid, $participants);
            }
            $instance = $this->completeStepRow($instance, $steps, $step, $uid, $note, $files);
            return $this->finish($instance, $steps, $uid, $step->getStepKey());
        });
    }

    // ----------------------------------------------------------------------
    // Service teams: the queue (phase 5, v4.10.20)
    // ----------------------------------------------------------------------

    /**
     * Take an unclaimed request out of the queue.
     *
     * Claiming is the first half of working a queue and the reason the
     * queue is safe: after it, exactly one agent owns the answer. It starts
     * the step in the same move - an agent who claims something has begun -
     * so a claimed request reads as in progress to everybody, including the
     * person who asked.
     *
     * @return array<string, mixed>
     * @throws AccessDeniedException not an agent of the handling service team
     * @throws WorkflowTransitionException already claimed, or not a service step
     * @throws \OCA\TeamHub\Exception\LicenseGateException
     */
    public function claimStep(int $id, string $uid, ?string $stepKey = null): array {
        $this->serviceTeams->requireLicence();
        return $this->transactional(function () use ($id, $uid, $stepKey): array {
            [$instance, $steps, $participants] = $this->loadOpen($id);
            $step = $this->requireServiceStep($uid, $instance, $steps, $stepKey, true);
            $this->assertNotBlocked($instance);

            if ($step->getAssignee() === $uid) {
                throw new WorkflowTransitionException($this->l->t('You already have this request.'));
            }
            if ($step->getAssignee() !== '') {
                throw new WorkflowTransitionException($this->l->t('Another agent has this request; take it over first.'));
            }

            $step->setAssignee($uid);
            $this->steps->update($step);
            $this->events->record((int)$instance->getId(), WorkflowEventType::STEP_CLAIMED, $uid, $step);
            $this->audit($instance, 'step_claimed', $uid, ['step' => $step->getStepKey()]);
            $this->deskAudit($instance, $step, 'claimed', $uid);

            if ($step->getStepStatus() === WorkflowStepStatus::AVAILABLE) {
                $this->startStepRow($instance, $step, $uid, $participants);
            } else {
                $this->recordActorParticipant($instance, $step, $uid, $participants);
            }
            $instance = $this->reflectSteps($instance, $steps);
            return $this->finish($instance, $steps, $uid, $step->getStepKey());
        });
    }

    /**
     * Hand a request to another eligible agent.
     *
     * v4.10.27 — **team admins only** (Justin, 2026-09-24): a member claims
     * for themselves, an admin distributes. Until then any agent could hand
     * work to any other; with every member of a department on the desk, that
     * made "who has this" a matter of whoever pressed last. The target must
     * be eligible, checked here and not taken from the client. Taking a
     * request *over* is the same call with yourself as the target, so it is
     * an admin act too — a member who wants a colleague's request asks an
     * admin, or the colleague releases it.
     *
     * @return array<string, mixed>
     * @throws AccessDeniedException
     * @throws ValidationException the target is not an eligible agent
     * @throws \OCA\TeamHub\Exception\LicenseGateException
     */
    public function assignStep(int $id, string $uid, string $targetUid, ?string $stepKey = null): array {
        $this->serviceTeams->requireLicence();
        return $this->transactional(function () use ($id, $uid, $targetUid, $stepKey): array {
            [$instance, $steps, $participants] = $this->loadOpen($id);
            $step = $this->requireServiceStep($uid, $instance, $steps, $stepKey);
            $this->assertNotBlocked($instance);

            $targetUid = trim($targetUid);
            // v4.10.27 — reassigning is the team admins' (Justin, 2026-09-24,
            // `/service-teams`): a member claims for themselves, an admin
            // distributes. Checked before the target, so a member learns what
            // they may not do rather than what is wrong with the name.
            if (!$this->serviceTeams->isServiceOwner($uid, $step->getActorId())) {
                throw new AccessDeniedException($this->l->t('Only an admin of the service team can reassign a request.'));
            }
            if ($targetUid === '') {
                throw new ValidationException($this->l->t('Pick the agent who should take this on.'));
            }
            if (!$this->serviceTeams->isEligibleAgent($targetUid, $step->getActorId())) {
                throw new ValidationException($this->l->t('That person is not an agent of this service team.'));
            }
            if ($step->getAssignee() === $targetUid) {
                throw new WorkflowTransitionException($this->l->t('That agent already has this request.'));
            }

            $previous = $step->getAssignee();
            $step->setAssignee($targetUid);
            $this->steps->update($step);
            $this->events->record((int)$instance->getId(), WorkflowEventType::STEP_ASSIGNED, $uid, $step, [
                'to'   => $targetUid,
                'from' => $previous !== '' ? $previous : null,
            ]);
            $this->audit($instance, 'step_assigned', $uid, ['step' => $step->getStepKey(), 'to' => $targetUid]);
            $this->deskAudit($instance, $step, 'assigned', $uid, ['to' => $targetUid]);

            if ($step->getStepStatus() === WorkflowStepStatus::AVAILABLE) {
                $this->moveStep($step, WorkflowStepStatus::IN_PROGRESS);
                $step->setStartedAt($this->timeFactory->getTime());
                $this->steps->update($step);
                $this->events->record((int)$instance->getId(), WorkflowEventType::STEP_STARTED, $uid, $step);
            }
            // The person who now owns it is a participant in their own name,
            // whether or not they have acted yet: the row is theirs, so the
            // workflow must be readable to them.
            $this->recordActorParticipant($instance, $step, $targetUid, $participants);
            $instance = $this->reflectSteps($instance, $steps);

            // Told after the commit, like every other hand-over, and never to
            // the person who did it.
            if ($targetUid !== $uid) {
                $this->afterCommit[] = function () use ($instance, $step, $targetUid, $uid): void {
                    $this->notifier->stepAssigned($instance, $step, $targetUid, $uid);
                };
            }
            return $this->finish($instance, $steps, $uid, $step->getStepKey());
        });
    }

    /**
     * Put a claimed request back in the queue.
     *
     * The assignee or the service owner may do this; nobody else, because
     * releasing somebody else's work is how a request quietly loses its
     * owner. The step goes back to *available* so the queue shows it as
     * unclaimed again, and the reason - if one was given - stays in the
     * history for whoever picks it up.
     *
     * @return array<string, mixed>
     * @throws AccessDeniedException
     * @throws \OCA\TeamHub\Exception\LicenseGateException
     */
    public function releaseStep(int $id, string $uid, ?string $reason = null, ?string $stepKey = null): array {
        $this->serviceTeams->requireLicence();
        return $this->transactional(function () use ($id, $uid, $reason, $stepKey): array {
            [$instance, $steps] = $this->loadOpen($id);
            $step = $this->requireServiceStep($uid, $instance, $steps, $stepKey);

            if ($step->getAssignee() === '') {
                throw new WorkflowTransitionException($this->l->t('Nobody has this request.'));
            }
            if ($step->getAssignee() !== $uid && !$this->serviceTeams->isServiceOwner($uid, $step->getActorId())) {
                throw new AccessDeniedException($this->l->t('Only the agent who has this request, or the service owner, can release it.'));
            }

            $reason   = $this->text($reason);
            $previous = $step->getAssignee();
            $step->setAssignee('');
            if ($step->getStepStatus() === WorkflowStepStatus::IN_PROGRESS) {
                // Back to the queue, not back to nothing: the step is
                // available again and its started_at is cleared, so the next
                // agent's "in progress" measures their own work.
                $this->moveStep($step, WorkflowStepStatus::AVAILABLE);
                $step->setStartedAt(null);
            }
            $this->steps->update($step);
            $this->events->record((int)$instance->getId(), WorkflowEventType::STEP_RELEASED, $uid, $step, array_filter([
                'from'   => $previous,
                'reason' => $reason,
            ], static fn ($v): bool => $v !== null && $v !== ''));
            $this->audit($instance, 'step_released', $uid, ['step' => $step->getStepKey()]);
            $this->deskAudit($instance, $step, 'released', $uid, ['from' => $previous]);
            $instance = $this->reflectSteps($instance, $steps);
            return $this->finish($instance, $steps, $uid, $step->getStepKey());
        });
    }

    /**
     * Add a note the service team keeps to itself.
     *
     * The one write that produces an event the requester never reads
     * (`visibility = internal`). It is what makes a queue usable without
     * making the requester read the desk's working notes - and, the other
     * way round, what stops a desk hiding its answer: an internal note
     * changes no status and moves no step, so nothing a requester is owed
     * can be delivered as one.
     *
     * Any eligible agent of the handling service team may write one,
     * claimed or not: a colleague's second opinion on a request they have
     * not taken is exactly the case.
     *
     * @return array<string, mixed>
     * @throws AccessDeniedException
     * @throws ValidationException
     * @throws \OCA\TeamHub\Exception\LicenseGateException
     */
    public function addInternalNote(int $id, string $uid, string $note, ?string $stepKey = null, mixed $fileIds = []): array {
        $this->serviceTeams->requireLicence();
        return $this->transactional(function () use ($id, $uid, $note, $stepKey, $fileIds): array {
            [$instance, $steps, $participants] = $this->loadOpen($id);
            $step = $this->requireServiceStep($uid, $instance, $steps, $stepKey);

            $note = $this->text($note);
            if ($note === null) {
                throw new ValidationException($this->l->t('An internal note needs some text.'));
            }

            // An internal note's files go to the team, and stay the team's.
            $files = $this->attachFiles($instance, $steps, $uid, $fileIds, true, $step->getStepKey());
            $this->events->record(
                (int)$instance->getId(),
                WorkflowEventType::INTERNAL_NOTE,
                $uid,
                $step,
                ['note' => $note] + ($files !== [] ? ['files' => $files] : []),
                WorkflowEventType::VISIBILITY_INTERNAL,
            );
            // Audited without the text: an audit line is read by people who
            // are not on the service team.
            $this->audit($instance, 'internal_note', $uid, ['step' => $step->getStepKey()]);

            $instance->setUpdatedAt($this->timeFactory->getTime());
            $instance = $this->instances->update($instance);
            return $this->viewOf($instance, $steps, $participants, $uid, null, $step->getStepKey());
        });
    }

    /**
     * The requester and the person who claimed the request write to each
     * other (v4.11.0; Justin, 2026-09-28).
     *
     * A message changes no status and moves no step — asking something the
     * request has to wait for is `requestInformation()`, which the desk's
     * composer sends instead when its sender says so. It is an event in the
     * request's own history, visible to both sides, so the conversation
     * stays with the request and goes into its archive with it.
     *
     * Who may write: the requester, and on the desk's side whoever claimed
     * it (or an admin of the service team) — an unclaimed request is claimed
     * before anybody answers for the desk. Only while the request is open.
     *
     * Who is told: the other side. A requester's message on a request that
     * nobody has claimed notifies nobody; whoever claims it reads it in the
     * request's history. Each new message replaces the recipient's earlier
     * message notification about the same request, so the bell holds one
     * per request rather than one per message.
     *
     * @return array<string, mixed>
     * @throws AccessDeniedException
     * @throws ValidationException
     * @throws WorkflowTransitionException
     * @throws \OCA\TeamHub\Exception\LicenseGateException
     */
    public function postMessage(int $id, string $uid, string $note, mixed $fileIds = []): array {
        $this->serviceTeams->requireLicence();
        return $this->transactional(function () use ($id, $uid, $note, $fileIds): array {
            [$instance, $steps, $participants] = $this->loadOpen($id);
            $side = $this->messageSide($uid, $instance, $steps);
            if ($side === null) {
                throw new AccessDeniedException($this->serviceTeamOf($steps) === ''
                    ? $this->l->t('Messages are for service requests only.')
                    : $this->l->t('Only the requester and whoever claimed the request can send messages.'));
            }
            $note = $this->text($note);
            if ($note === null) {
                throw new ValidationException($this->l->t('A message needs some text.'));
            }

            $deskStep   = $this->conversationStep($steps);
            $claimants  = $this->claimantsOf($steps);
            $recipients = $side === 'requester'
                ? $claimants
                // A team admin writing on a colleague's request: the colleague
                // hears of it too, not only the requester.
                : array_merge([$instance->getStartedBy()], $claimants);
            $recipients = array_values(array_unique(array_filter(
                $recipients,
                static fn (string $r): bool => $r !== '' && $r !== $uid,
            )));

            $files = $this->attachFiles($instance, $steps, $uid, $fileIds, false, $deskStep?->getStepKey());
            $this->events->record(
                (int)$instance->getId(),
                WorkflowEventType::MESSAGE,
                $uid,
                $deskStep,
                ['note' => $note] + ($files !== [] ? ['files' => $files] : []),
            );
            // Audited without the text, like an internal note.
            $this->audit($instance, 'message', $uid, $deskStep !== null ? ['step' => $deskStep->getStepKey()] : []);

            $instance->setUpdatedAt($this->timeFactory->getTime());
            $instance = $this->instances->update($instance);
            if ($recipients !== []) {
                $this->afterCommit[] = fn () => $this->notifier->messagePosted($instance, $deskStep, $recipients, $uid, $note);
            }
            return $this->viewOf($instance, $steps, $participants, $uid, null, null);
        });
    }

    /**
     * Which side of a service request's conversation this person writes
     * for: 'requester', 'desk', or null when they may not write (v4.11.0).
     * The same rule decides `viewer.canMessage`, so the composer and the
     * call agree.
     *
     * @param WorkflowStep[] $steps
     */
    private function messageSide(string $uid, WorkflowInstance $instance, array $steps): ?string {
        $desk = $this->serviceTeamOf($steps);
        if ($uid === '' || $desk === '' || !WorkflowStatus::isOpen($instance->getStatus())) {
            return null;
        }
        if ($instance->getStartedBy() === $uid) {
            return 'requester';
        }
        $claimants = $this->claimantsOf($steps);
        if ($claimants === []) {
            return null;
        }
        if (in_array($uid, $claimants, true) || $this->serviceTeams->isServiceOwner($uid, $desk)) {
            return 'desk';
        }
        return null;
    }

    /**
     * The desk task the conversation belongs to (v4.11.0): the open desk
     * task the request is at, or — once the desk has answered and the
     * request is back with the requester — the desk's last task.
     *
     * @param WorkflowStep[] $steps
     */
    private function conversationStep(array $steps): ?WorkflowStep {
        $rows = $this->conversationRows($steps);
        return $rows[0] ?? null;
    }

    /**
     * Who claimed the request on the desk's side, as far as the conversation
     * goes (v4.11.0): the assignees of the open desk tasks, or — once the
     * desk has answered — the people who had its last task. Empty for a
     * request nobody has claimed.
     *
     * @param WorkflowStep[] $steps
     * @return string[]
     */
    private function claimantsOf(array $steps): array {
        $out = [];
        foreach ($this->conversationRows($steps) as $row) {
            $who = $row->getAssignee() !== '' ? $row->getAssignee() : (string)$row->getCompletedBy();
            if ($who !== '') {
                $out[$who] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * @param WorkflowStep[] $steps
     * @return WorkflowStep[]
     */
    private function conversationRows(array $steps): array {
        $desk = array_values(array_filter(
            $steps,
            static fn (WorkflowStep $s): bool => $s->getActorType() === WorkflowActor::TYPE_SERVICE_AGENT,
        ));
        $active = array_values(array_filter(
            $desk,
            static fn (WorkflowStep $s): bool => WorkflowStepStatus::isActive($s->getStepStatus()),
        ));
        if ($active !== []) {
            return $active;
        }
        $last = 0;
        foreach ($desk as $row) {
            if ($row->getStepStatus() === WorkflowStepStatus::COMPLETED) {
                $last = max($last, $row->getStepOrder());
            }
        }
        return array_values(array_filter(
            $desk,
            static fn (WorkflowStep $s): bool => $s->getStepOrder() === $last && $s->getStepStatus() === WorkflowStepStatus::COMPLETED,
        ));
    }

    /**
     * One service team's incoming queue: every open workflow whose active
     * step is that team's to handle.
     *
     * Three buckets, and they are the three questions an agent has when
     * they open the page: what has nobody taken, what have I taken, what
     * has somebody else taken. Built from one indexed read of the step
     * table (`th_wfs_actor_idx`), not from a scan of every open workflow.
     *
     * @return array<string, mixed>
     * @throws AccessDeniedException
     * @throws \OCA\TeamHub\Exception\LicenseGateException
     */
    public function listQueue(string $serviceTeamId, string $uid): array {
        $this->serviceTeams->requireLicence();
        if (!$this->serviceTeams->isEligibleAgent($uid, $serviceTeamId)) {
            throw new AccessDeniedException($this->l->t('You are not an agent of this service team.'));
        }

        $rows          = $this->steps->findActiveForActor(WorkflowActor::TYPE_SERVICE_AGENT, $serviceTeamId);
        $closed        = $this->closedForDesk($serviceTeamId, $uid);
        $withOthers = $this->withOthersForDesk($serviceTeamId, $uid, $rows);
        if ($rows === []) {
            return ['serviceTeamId' => $serviceTeamId, 'unclaimed' => [], 'mine' => [], 'others' => [], 'withOthers' => $withOthers, 'closed' => $closed];
        }

        $instanceIds = array_values(array_unique(array_map(
            static fn (WorkflowStep $r): int => $r->getInstanceId(),
            $rows,
        )));
        $instances  = $this->instances->findByIds($instanceIds);
        $stepsById  = $this->steps->findByInstances($instanceIds);
        $partsById  = $this->participants->findByInstances($instanceIds);

        $out = ['serviceTeamId' => $serviceTeamId, 'unclaimed' => [], 'mine' => [], 'others' => [], 'withOthers' => $withOthers, 'closed' => $closed];
        $byId = [];
        foreach ($instances as $instance) {
            $byId[(int)$instance->getId()] = $instance;
        }
        // v4.10.37 — one row per **task**: a step with three team tasks puts
        // three rows in the queue, each claimed on its own. The rows come
        // from the step index in queue order; each view answers for its task.
        foreach ($rows as $row) {
            $instance = $byId[$row->getInstanceId()] ?? null;
            if ($instance === null || !WorkflowStatus::isOpen($instance->getStatus())) {
                continue;
            }
            $instanceId = (int)$instance->getId();
            $view = $this->viewOf(
                $instance,
                $stepsById[$instanceId] ?? [],
                $partsById[$instanceId] ?? [],
                $uid,
                [],
                $row->getStepKey(),
            );
            $assignee = $row->getAssignee();
            $bucket   = $assignee === '' ? 'unclaimed' : ($assignee === $uid ? 'mine' : 'others');
            $out[$bucket][] = $view;
        }
        return $out;
    }

    /**
     * Open requests of this desk that are **back with the requester**
     * (v4.10.31): a built service's planned requester action between two
     * desk steps, a confirmation the requester has not given yet. Without
     * this list they would be in no tab at all — not in the queue, because
     * no desk step is active, and not closed, because they are not.
     *
     * Found through the participant index (the desk is a participant of
     * every request it handles), minus the ones already in the queue.
     * Called only from `listQueue()`, after its checks.
     *
     * @param WorkflowStep[] $activeDeskSteps
     * @return array<int, array<string, mixed>>
     */
    private function withOthersForDesk(string $serviceTeamId, string $uid, array $activeDeskSteps): array {
        $inQueue = [];
        foreach ($activeDeskSteps as $step) {
            $inQueue[$step->getInstanceId()] = true;
        }
        $ids = array_values(array_filter(
            $this->participants->findInstanceIdsForActors([[WorkflowActor::TYPE_SERVICE_AGENT, $serviceTeamId]]),
            static fn (int $id): bool => !isset($inQueue[$id]),
        ));
        if ($ids === []) {
            return [];
        }
        $open = array_values(array_filter(
            $this->instances->findByIds($ids),
            static fn (WorkflowInstance $i): bool => WorkflowStatus::isOpen($i->getStatus()),
        ));
        if ($open === []) {
            return [];
        }
        $openIds   = array_map(static fn (WorkflowInstance $i): int => (int)$i->getId(), $open);
        $stepsById = $this->steps->findByInstances($openIds);
        $partsById = $this->participants->findByInstances($openIds);
        $out = [];
        foreach ($open as $instance) {
            $id = (int)$instance->getId();
            $out[] = $this->viewOf($instance, $stepsById[$id] ?? [], $partsById[$id] ?? [], $uid, []);
        }
        return $out;
    }

    /** How far back the queue's Closed tab reaches (Justin, 2026-09-24). */
    public const CLOSED_WINDOW_DAYS = 30;

    /**
     * The desk's Closed tab: requests whose service step **finished** in the
     * last `CLOSED_WINDOW_DAYS` days, newest first (v4.10.27).
     *
     * "Closed" is the desk's point of view, not the workflow's: a request the
     * desk answered is closed for the desk the moment its step completes,
     * even while the requester has yet to confirm it. Each row carries a
     * `desk` block saying how the desk's part ended — answered, rejected or
     * withdrawn by the requester — because the workflow status alone cannot
     * say it for a request that has moved on.
     *
     * Called only from `listQueue()`, after its licence and eligibility
     * checks.
     *
     * @return array<int, array<string, mixed>>
     */
    private function closedForDesk(string $serviceTeamId, string $uid): array {
        $since = $this->timeFactory->getTime() - self::CLOSED_WINDOW_DAYS * 86400;
        // v4.10.31 — a built service may hand a request to the desk more
        // than once. While a later desk step is still open the request is
        // in the queue, not closed; once all are done, the latest one says
        // how and when the desk's part ended.
        $stillOpen = [];
        foreach ($this->steps->findActiveForActor(WorkflowActor::TYPE_SERVICE_AGENT, $serviceTeamId) as $step) {
            $stillOpen[$step->getInstanceId()] = true;
        }
        $finished = [];
        foreach ($this->steps->findForActorSince(WorkflowActor::TYPE_SERVICE_AGENT, $serviceTeamId, $since) as $step) {
            $completedAt = $step->getCompletedAt();
            if ($completedAt === null || $completedAt < $since || isset($stillOpen[$step->getInstanceId()])) {
                continue;
            }
            // v4.10.31 — SKIPPED with a completion time is a step an admin
            // closed while it was active (closeRequest()).
            if (!in_array($step->getStepStatus(), [WorkflowStepStatus::COMPLETED, WorkflowStepStatus::REJECTED, WorkflowStepStatus::CANCELLED, WorkflowStepStatus::SKIPPED], true)) {
                continue;
            }
            $previous = $finished[$step->getInstanceId()] ?? null;
            if ($previous === null
                || [$completedAt, $step->getStepOrder()] > [(int)$previous->getCompletedAt(), $previous->getStepOrder()]
            ) {
                $finished[$step->getInstanceId()] = $step;
            }
        }
        if ($finished === []) {
            return [];
        }

        $instanceIds = array_keys($finished);
        $stepsById   = $this->steps->findByInstances($instanceIds);
        $partsById   = $this->participants->findByInstances($instanceIds);
        $out = [];
        foreach ($this->instances->findByIds($instanceIds) as $instance) {
            $instanceId = (int)$instance->getId();
            $step       = $finished[$instanceId];
            // v4.10.31 — an open request that will come back to this desk
            // (a later desk step still pending, behind a requester action)
            // is not closed for the desk yet.
            if (WorkflowStatus::isOpen($instance->getStatus())) {
                foreach ($stepsById[$instanceId] ?? [] as $later) {
                    if ($later->getStepStatus() === WorkflowStepStatus::PENDING
                        && $later->getActorType() === WorkflowActor::TYPE_SERVICE_AGENT
                        && $later->getActorId() === $serviceTeamId
                    ) {
                        continue 2;
                    }
                }
            }
            $view = $this->viewOf($instance, $stepsById[$instanceId] ?? [], $partsById[$instanceId] ?? [], $uid, []);
            $view['desk'] = [
                'status'   => $step->getStepStatus(),
                'closedAt' => (int)$step->getCompletedAt(),
                'closedBy' => (string)($step->getCompletedBy() ?? ''),
            ];
            $out[] = $view;
        }
        usort($out, static fn (array $a, array $b): int => $b['desk']['closedAt'] <=> $a['desk']['closedAt']);
        return $out;
    }

    /**
     * How many requests wait in a desk's queue with nobody on them
     * (v4.10.27) — the badge behind the service team's name in the
     * navigation. A count, not a list: it is asked on every page load for
     * every desk the viewer is on, and a queue view per desk would build a
     * full workflow view per row to throw it away.
     *
     * No licence or eligibility check of its own: the caller
     * (`ServiceTeamController::index`) asks only for the desks
     * `serviceTeamsForAgent()` returned, which already applied both.
     */
    public function unclaimedCount(string $serviceTeamId): int {
        $rows = array_filter(
            $this->steps->findActiveForActor(WorkflowActor::TYPE_SERVICE_AGENT, $serviceTeamId),
            static fn (WorkflowStep $s): bool => $s->getAssignee() === '',
        );
        if ($rows === []) {
            return 0;
        }
        // v4.10.37 — unclaimed *tasks*: what the queue's New tab lists.
        $ids    = array_values(array_unique(array_map(static fn (WorkflowStep $s): int => $s->getInstanceId(), $rows)));
        $isOpen = [];
        foreach ($this->instances->findByIds($ids) as $instance) {
            $isOpen[(int)$instance->getId()] = WorkflowStatus::isOpen($instance->getStatus());
        }
        $open = 0;
        foreach ($rows as $row) {
            $open += ($isOpen[$row->getInstanceId()] ?? false) ? 1 : 0;
        }
        return $open;
    }

    /**
     * A holder of the active step's actor rejects it, with a reason. The
     * step and the workflow are rejected; the steps that were never
     * reached are skipped.
     *
     * @return array<string, mixed>
     */
    public function rejectStep(int $id, string $uid, string $reason, ?string $stepKey = null, mixed $fileIds = []): array {
        return $this->transactional(function () use ($id, $uid, $reason, $stepKey, $fileIds): array {
            [$instance, $steps, $participants] = $this->loadOpen($id);
            $step = $this->pickStep($instance, $steps, $uid, $stepKey);
            $this->assertHolder($uid, $step, $instance);
            $this->assertNotBlocked($instance);
            $reason = $this->text($reason);
            if ($reason === null) {
                throw new ValidationException($this->l->t('A reason is required.'));
            }

            $this->recordActorParticipant($instance, $step, $uid, $participants);
            $this->moveStep($step, WorkflowStepStatus::REJECTED);
            $now = $this->timeFactory->getTime();
            $step->setCompletedAt($now);
            $step->setCompletedBy($uid);
            $step->setActionTaken('reject');
            $step->setReason($reason);
            $this->steps->update($step);
            $files = $this->attachFiles($instance, $steps, $uid, $fileIds, false, $step->getStepKey());
            $this->events->record((int)$instance->getId(), WorkflowEventType::STEP_REJECTED, $uid, $step, ['reason' => $reason] + ($files !== [] ? ['files' => $files] : []));
            $this->deskAudit($instance, $step, 'rejected', $uid);

            // v4.10.37 — rejecting one task rejects the request: every other
            // open task, of this step or an earlier non-blocking one, is
            // skipped with it.
            $this->closeActive($instance, $steps, $step, WorkflowStepStatus::SKIPPED, null, $uid, null, WorkflowEventType::STEP_SKIPPED);
            $this->closeRemaining($instance, $steps, $step, WorkflowStepStatus::SKIPPED, WorkflowEventType::STEP_SKIPPED);
            $instance = $this->endInstance($instance, WorkflowStatus::REJECTED, self::OUTCOME_REJECTED, $uid);
            $this->notifyEndHook($instance, self::OUTCOME_REJECTED, $uid, $reason);
            $this->events->record((int)$instance->getId(), WorkflowEventType::REJECTED, $uid, null, ['reason' => $reason]);
            $this->audit($instance, 'rejected', $uid, ['step' => $step->getStepKey()]);
            $this->notifyEnded($instance, $uid, $reason);
            return $this->finish($instance, $steps, $uid, $step->getStepKey());
        });
    }

    /**
     * The actor of a step in progress needs something from another
     * participant: the step waits, the workflow waits, the initiator is
     * told.
     *
     * @return array<string, mixed>
     */
    public function requestInformation(int $id, string $uid, string $note, ?string $stepKey = null, mixed $fileIds = []): array {
        return $this->transactional(function () use ($id, $uid, $note, $stepKey, $fileIds): array {
            [$instance, $steps, $participants] = $this->loadOpen($id);
            $step = $this->pickStep($instance, $steps, $uid, $stepKey);
            $this->assertHolder($uid, $step, $instance);
            $this->assertNotBlocked($instance);
            $note = $this->text($note);
            if ($note === null) {
                throw new ValidationException($this->l->t('Say what information is needed.'));
            }
            // An available step is taken up by asking on it.
            if ($step->getStepStatus() === WorkflowStepStatus::AVAILABLE) {
                $this->startStepRow($instance, $step, $uid, $participants);
            } else {
                $this->recordActorParticipant($instance, $step, $uid, $participants);
            }
            $this->moveStep($step, WorkflowStepStatus::WAITING_FOR_INFORMATION);
            $this->steps->update($step);
            $files = $this->attachFiles($instance, $steps, $uid, $fileIds, false, $step->getStepKey());
            $this->events->record((int)$instance->getId(), WorkflowEventType::INFORMATION_REQUESTED, $uid, $step, ['note' => $note] + ($files !== [] ? ['files' => $files] : []));
            $instance = $this->reflectSteps($instance, $steps);
            $this->audit($instance, 'information_requested', $uid, ['step' => $step->getStepKey()]);
            $this->afterCommit[] = fn () => $this->notifier->informationRequested($instance, $step, $uid, $note);
            return $this->finish($instance, $steps, $uid, $step->getStepKey());
        });
    }

    /**
     * Any participant answers a request for information: the step goes
     * back to in progress and its holders are told.
     *
     * @return array<string, mixed>
     */
    public function provideInformation(int $id, string $uid, string $note, ?string $stepKey = null, mixed $fileIds = []): array {
        return $this->transactional(function () use ($id, $uid, $note, $stepKey, $fileIds): array {
            [$instance, $steps, $participants] = $this->loadOpen($id);
            // v4.10.37 — the task that asked, or the first one waiting.
            $step = $this->waitingStep($instance, $steps, $stepKey);
            if (!$this->isParticipant($uid, $instance, $participants)) {
                throw new AccessDeniedException($this->l->t('You are not part of this workflow.'));
            }
            $this->assertNotBlocked($instance);
            // `available → in_progress` is the *start* transition, so the
            // table alone would let an answer start a step nobody asked
            // about. Only a waiting step takes an answer.
            if ($step->getStepStatus() !== WorkflowStepStatus::WAITING_FOR_INFORMATION) {
                throw new WorkflowTransitionException($this->l->t('Nobody asked for information on this step.'));
            }
            $note = $this->text($note);
            if ($note === null) {
                throw new ValidationException($this->l->t('The answer cannot be empty.'));
            }
            $this->moveStep($step, WorkflowStepStatus::IN_PROGRESS);
            $this->steps->update($step);
            $files = $this->attachFiles($instance, $steps, $uid, $fileIds, false, $step->getStepKey());
            $this->events->record((int)$instance->getId(), WorkflowEventType::INFORMATION_PROVIDED, $uid, $step, ['note' => $note] + ($files !== [] ? ['files' => $files] : []));
            $instance = $this->reflectSteps($instance, $steps);
            $this->audit($instance, 'information_provided', $uid, ['step' => $step->getStepKey()]);
            $this->afterCommit[] = fn () => $this->notifier->informationProvided($instance, $step, $uid, $note);
            return $this->finish($instance, $steps, $uid, $step->getStepKey());
        });
    }

    /**
     * A participant who is *not* responsible right now asks the
     * responsible actor where things stand (v4.10.14). Changes no status:
     * an event is recorded and the holders of the active step are
     * notified. One request per participant per step per
     * `STATUS_REQUEST_INTERVAL`; a second one inside the interval is
     * refused with `WorkflowRateLimitException` and sends nothing.
     *
     * @return array<string, mixed>
     */
    public function requestStatus(int $id, string $uid, ?string $note = null, ?string $stepKey = null): array {
        // Phase 4: licensed only. Checked before anything is read so an
        // unlicensed caller learns nothing about the workflow from the answer.
        $this->tier->require(WorkflowCapability::REQUEST_STATUS_UPDATE, 'Status update requests require an active TeamHub licence.');
        return $this->transactional(function () use ($id, $uid, $note, $stepKey): array {
            [$instance, $steps, $participants] = $this->loadOpen($id);
            $step = $this->pickStep($instance, $steps, $uid, $stepKey);
            if (!$this->isParticipant($uid, $instance, $participants)) {
                throw new AccessDeniedException($this->l->t('You are not part of this workflow.'));
            }
            $actor = WorkflowActor::of($step->getActorType(), $step->getActorId());
            if ($this->resolver->holds($uid, $actor, $instance->getTeamId())) {
                throw new ValidationException($this->l->t('This step is waiting for you; there is nobody to ask.'));
            }
            $note = $this->text($note);

            $now = $this->timeFactory->getTime();
            foreach ($this->events->listForInstance((int)$instance->getId()) as $event) {
                if ($event['type'] === WorkflowEventType::STATUS_REQUESTED
                    && $event['actorUid'] === $uid
                    && $event['stepKey'] === $step->getStepKey()
                    && $event['occurredAt'] > $now - self::STATUS_REQUEST_INTERVAL
                ) {
                    $retry = (int)$event['occurredAt'] + self::STATUS_REQUEST_INTERVAL - $now;
                    $hours = max(1, (int)ceil($retry / 3600));
                    throw new WorkflowRateLimitException(
                        // TRANSLATORS: %n is the whole number of hours before the same person may ask for an update on this step again
                        $this->l->n(
                            'You already asked for an update on this step; you can ask again in %n hour.',
                            'You already asked for an update on this step; you can ask again in %n hours.',
                            $hours,
                        ),
                        max(1, $retry),
                    );
                }
            }

            $this->events->record((int)$instance->getId(), WorkflowEventType::STATUS_REQUESTED, $uid, $step, $note !== null ? ['note' => $note] : []);
            $this->audit($instance, 'status_requested', $uid, ['step' => $step->getStepKey()]);
            $this->afterCommit[] = fn () => $this->notifier->statusRequested($instance, $step, $uid, $note);
            return $this->viewOf($instance, $steps, $participants, $uid, null, $step->getStepKey());
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    // Workflow-level transitions
    // ──────────────────────────────────────────────────────────────────────

    /**
     * A holder of the active step's actor, or a Nextcloud administrator,
     * declares the workflow blocked. Steps are untouched; every step
     * transition is refused until `unblock()`.
     *
     * @return array<string, mixed>
     */
    public function block(int $id, string $uid, string $reason): array {
        return $this->transactional(function () use ($id, $uid, $reason): array {
            [$instance, $steps, $participants] = $this->loadOpen($id);
            $step = $this->pickStep($instance, $steps, $uid, null);
            if (!$this->resolver->isNextcloudAdmin($uid)) {
                $this->assertHolder($uid, $step, $instance);
            }
            $reason = $this->text($reason);
            if ($reason === null) {
                throw new ValidationException($this->l->t('A reason is required.'));
            }
            $this->recordActorParticipant($instance, $step, $uid, $participants);
            $instance = $this->moveInstance($instance, WorkflowStatus::BLOCKED);
            $this->events->record((int)$instance->getId(), WorkflowEventType::BLOCKED, $uid, $step, ['reason' => $reason]);
            $this->audit($instance, 'blocked', $uid, ['step' => $step->getStepKey()]);
            return $this->finish($instance, $steps, $uid);
        });
    }

    /**
     * Lift a block; the status is re-derived from the steps.
     *
     * @return array<string, mixed>
     */
    public function unblock(int $id, string $uid): array {
        return $this->transactional(function () use ($id, $uid): array {
            [$instance, $steps, $participants] = $this->loadOpen($id);
            $step = $this->pickStep($instance, $steps, $uid, null);
            if (!$this->resolver->isNextcloudAdmin($uid)) {
                $this->assertHolder($uid, $step, $instance);
            }
            if ($instance->getStatus() !== WorkflowStatus::BLOCKED) {
                throw new WorkflowTransitionException($this->l->t('This workflow is not blocked.'));
            }
            $this->recordActorParticipant($instance, $step, $uid, $participants);
            $instance = $this->moveInstance($instance, $this->deriveOpenStatus($steps));
            $this->events->record((int)$instance->getId(), WorkflowEventType::UNBLOCKED, $uid, $step);
            $this->audit($instance, 'unblocked', $uid, ['step' => $step->getStepKey()]);
            return $this->finish($instance, $steps, $uid);
        });
    }

    /**
     * The initiator or a Nextcloud administrator withdraws the workflow.
     * The active step and every pending one are cancelled.
     *
     * @return array<string, mixed>
     */
    public function cancel(int $id, string $uid, string $reason = ''): array {
        return $this->transactional(function () use ($id, $uid, $reason): array {
            [$instance, $steps] = $this->loadOpen($id);
            if ($instance->getStartedBy() !== $uid && !$this->resolver->isNextcloudAdmin($uid)) {
                throw new AccessDeniedException($this->l->t('Only the person who started this workflow, or an administrator, can cancel it.'));
            }
            $reason = $this->text($reason);

            // v4.10.37 — withdrawing ends every open task, non-blocking
            // ones included; the desk's stream hears it once.
            $active = $this->activeStepOf($steps);
            $this->closeActive($instance, $steps, null, WorkflowStepStatus::CANCELLED, 'cancel', $uid, $reason, null);
            $this->deskAuditStage($instance, $this->activeOrFinished($steps, WorkflowStepStatus::CANCELLED), 'cancelled', $uid);
            $this->closeRemaining($instance, $steps, $active, WorkflowStepStatus::CANCELLED, null);
            $instance = $this->endInstance($instance, WorkflowStatus::CANCELLED, self::OUTCOME_CANCELLED, $uid);
            $this->notifyEndHook($instance, self::OUTCOME_CANCELLED, $uid, $reason);
            $this->events->record((int)$instance->getId(), WorkflowEventType::CANCELLED, $uid, $active, $reason !== null ? ['reason' => $reason] : []);
            $this->audit($instance, 'cancelled', $uid);
            $this->notifyEnded($instance, $uid, $reason);
            return $this->finish($instance, $steps, $uid);
        });
    }

    /**
     * An admin of the service team closes the request (v4.10.31).
     *
     * At any step, and with an optional note. Two cases:
     *
     *   - The request waits for the **requester's confirmation** (its last
     *     step, the requester's own): the admin completes that step in the
     *     requester's place, and the request ends *completed* like any
     *     other. A requester who never comes back does not keep a finished
     *     request open.
     *   - Anywhere else: the request ends with the outcome **closed**. The
     *     steps already done stay done; the active one and those after it
     *     are *skipped*, so the tracker shows exactly which steps were not
     *     done. Not a rejection — Justin: *"It's up to those involved to see
     *     if it was successful or a rejection."*
     *
     * Licensed like every desk verb.
     *
     * @return array<string, mixed>
     * @throws AccessDeniedException not an admin of the handling service team
     */
    public function closeRequest(int $id, string $uid, ?string $note = null, mixed $fileIds = []): array {
        $this->serviceTeams->requireLicence();
        return $this->transactional(function () use ($id, $uid, $note, $fileIds): array {
            [$instance, $steps, $participants] = $this->loadOpen($id);
            if (!$this->mayCloseForDesk($uid, $instance, $steps)) {
                throw new AccessDeniedException($this->l->t('Only an admin of the service team can close this request.'));
            }
            $note   = $this->text($note);
            $files  = $this->attachFiles($instance, $steps, $uid, $fileIds, false, null);
            $active = $this->activeStepOf($steps);
            $last   = max(array_map(static fn (WorkflowStep $s): int => $s->getStepOrder(), $steps));
            // The desk's activity stream says who closed it, whichever step
            // it was on: written against a step of the desk's own.
            $deskStep = null;
            foreach ($steps as $candidate) {
                if ($candidate->getActorType() === WorkflowActor::TYPE_SERVICE_AGENT) {
                    $deskStep = $candidate;
                }
            }

            if ($active !== null
                && count($this->activeStepsOf($steps)) === 1
                && $active->getStepOrder() === $last
                && $active->getActorType() === WorkflowActor::TYPE_USER
                && $active->getActorId() === $instance->getStartedBy()
                && $active->getStepStatus() !== WorkflowStepStatus::WAITING_FOR_INFORMATION
            ) {
                if ($active->getStepStatus() === WorkflowStepStatus::AVAILABLE) {
                    $this->startStepRow($instance, $active, $uid, $participants);
                }
                $instance = $this->completeStepRow($instance, $steps, $active, $uid, $note, $files);
                if ($deskStep !== null) {
                    $this->deskAudit($instance, $deskStep, 'closed', $uid);
                }
                return $this->finish($instance, $steps, $uid);
            }

            // v4.10.37 — every open task is skipped, the ones of this step and
            // any non-blocking one still open from an earlier step.
            $this->closeActive($instance, $steps, null, WorkflowStepStatus::SKIPPED, 'close', $uid, $note, null);
            if ($deskStep !== null) {
                $this->deskAudit($instance, $deskStep, 'closed', $uid);
            }
            $this->closeRemaining($instance, $steps, $active, WorkflowStepStatus::SKIPPED, null);
            $instance = $this->endInstance($instance, WorkflowStatus::COMPLETED, self::OUTCOME_CLOSED, $uid);
            $this->events->record((int)$instance->getId(), WorkflowEventType::CLOSED, $uid, $active,
                ($note !== null ? ['note' => $note] : []) + ($files !== [] ? ['files' => $files] : []));
            $this->audit($instance, 'closed', $uid);
            $this->notifyEnded($instance, $uid, $note);
            return $this->finish($instance, $steps, $uid);
        });
    }

    /**
     * Write a workflow that already happened somewhere else (v4.10.29).
     *
     * The one way the engine takes a history instead of making one, used by
     * the repair step that moves the ledger's quota requests onto the engine
     * (`ImportLedgerQuotaRequests`, `docs/workflowhub-architecture.md` §11
     * step 3). Everything else about the instance is what `create()` would
     * have written — the version, the step rows, the participants — and the
     * steps' states are then set from `$history` with the original times,
     * **without the transition table**: the table says how a workflow may
     * move from here, and an import does not move, it arrives.
     *
     * - A step `$history` names is `completed` or `rejected` with its actor,
     *   time and reason; a step it does not name is pending.
     * - The first step that is neither is the active one (available).
     * - A rejection ends the workflow there; the steps after it are skipped.
     * - Nobody is notified. The people concerned were told when it happened;
     *   an upgrade that re-sent every open request would be noise.
     *
     * A placeholder step the definition cannot resolve (no desk holds the
     * service) takes `$fallbackActor`, so a request that was waiting on the
     * Nextcloud administrators before the upgrade stays with them rather than
     * becoming a queue nobody works. Without a fallback it is refused.
     *
     * Ends through `finish()` like every write, so an imported workflow that
     * has already ended is archived on a licensed instance and purged on an
     * unlicensed one — the same lifecycle as one that ended here.
     *
     * @param array<string, mixed> $data the instance data, already in the definition's shape
     * @param array<string, array{status: string, by?: ?string, at?: ?int, reason?: ?string}> $history
     * @return array<string, mixed>
     * @throws NotFoundException unknown definition
     * @throws WorkflowTransitionException a placeholder nobody holds and no fallback
     */
    public function importInstance(
        string         $definitionKey,
        string         $teamId,
        string         $startedBy,
        int            $startedAt,
        array          $data,
        array          $history,
        ?WorkflowActor $fallbackActor = null,
    ): array {
        $definition = $this->registry->get($definitionKey);
        if ($definition === null) {
            throw new NotFoundException($this->l->t('There is no workflow of that kind.'));
        }

        return $this->transactional(function () use ($definition, $teamId, $startedBy, $startedAt, $data, $history, $fallbackActor): array {
            [$subjectType, $subjectId] = $definition->subjectOf($teamId, $data);

            $instance = new WorkflowInstance();
            $instance->setDefinitionKey($definition->getKey());
            $instance->setDefinitionVersion($definition->getVersion());
            $instance->setTeamId($teamId);
            $instance->setSubjectType((string)$subjectType);
            $instance->setSubjectId((string)$subjectId);
            $instance->setStatus(WorkflowStatus::SUBMITTED);
            $instance->setStartedBy($startedBy);
            $instance->setStartedAt($startedAt);
            $instance->setUpdatedAt($startedAt);
            $instance->setRetentionUntil(0);
            $instance->setData($data);
            $instance = $this->instances->insert($instance);
            $id       = (int)$instance->getId();
            $this->events->record($id, WorkflowEventType::CREATED, $startedBy, null, [
                'definitionKey'     => $definition->getKey(),
                'definitionVersion' => $definition->getVersion(),
                'imported'          => true,
            ], WorkflowEventType::VISIBILITY_ALL, $startedAt);

            $stepRows = [];
            $order    = 0;
            $previous = $startedAt;
            $active   = null;
            $rejected = null;
            foreach ($definition->getSteps() as $def) {
                $order++;
                $actor = $def->actor;
                if ($actor->isInitiator()) {
                    $actor = WorkflowActor::user($startedBy);
                } elseif ($actor->isUnresolved()) {
                    $actor = $definition->resolveActor($def, $teamId, $data) ?? $fallbackActor;
                    if ($actor === null || $actor->isUnresolved()) {
                        throw new WorkflowTransitionException(
                            $this->l->t('This request has no service team to handle it.'));
                    }
                }
                $step = new WorkflowStep();
                $step->setInstanceId($id);
                $step->setStepKey($def->key);
                $step->setStepOrder($order);
                $step->setActorType($actor->type);
                $step->setActorId($actor->id);
                $step->setLabel(mb_substr($def->label, 0, 255));

                $past = $history[$def->key] ?? null;
                if ($rejected !== null) {
                    $step->setStepStatus(WorkflowStepStatus::SKIPPED);
                } elseif ($active === null && $past !== null && in_array($past['status'], [WorkflowStepStatus::COMPLETED, WorkflowStepStatus::REJECTED], true)) {
                    $at = (int)($past['at'] ?? $previous);
                    $by = (string)($past['by'] ?? $startedBy);
                    $step->setStepStatus($past['status']);
                    $step->setEnteredAt($previous);
                    $step->setStartedAt($previous);
                    $step->setCompletedAt($at);
                    $step->setCompletedBy($by);
                    $step->setActionTaken($past['status'] === WorkflowStepStatus::REJECTED ? 'reject' : 'complete');
                    $step->setReason(isset($past['reason']) ? $this->text((string)$past['reason']) : null);
                    $previous = $at;
                    if ($past['status'] === WorkflowStepStatus::REJECTED) {
                        $rejected = $step;
                    }
                } elseif ($active === null) {
                    $step->setStepStatus(WorkflowStepStatus::AVAILABLE);
                    $step->setEnteredAt($previous);
                    $active = $step;
                } else {
                    $step->setStepStatus(WorkflowStepStatus::PENDING);
                }
                $stepRows[] = $step = $this->steps->insert($step);

                if (in_array($step->getStepStatus(), [WorkflowStepStatus::COMPLETED, WorkflowStepStatus::REJECTED], true)) {
                    $rejectedHere = $step->getStepStatus() === WorkflowStepStatus::REJECTED;
                    $this->events->record(
                        $id,
                        $rejectedHere ? WorkflowEventType::STEP_REJECTED : WorkflowEventType::STEP_COMPLETED,
                        $step->getCompletedBy(),
                        $step,
                        $step->getReason() !== null ? [$rejectedHere ? 'reason' : 'note' => $step->getReason()] : [],
                        WorkflowEventType::VISIBILITY_ALL,
                        $step->getCompletedAt(),
                    );
                } elseif ($step->getStepStatus() === WorkflowStepStatus::AVAILABLE) {
                    $this->events->record($id, WorkflowEventType::STEP_AVAILABLE, null, $step, [], WorkflowEventType::VISIBILITY_ALL, $previous);
                }
            }

            // Participants, as create() writes them, plus everybody who acted.
            $participants = [$this->addParticipant($instance, WorkflowActor::user($startedBy), WorkflowParticipantRole::INITIATOR, $startedBy, [])];
            $seen = [WorkflowActor::user($startedBy)->key() => true];
            foreach ($stepRows as $step) {
                $actor = WorkflowActor::of($step->getActorType(), $step->getActorId());
                if (!isset($seen[$actor->key()])) {
                    $seen[$actor->key()] = true;
                    $participants[] = $this->addParticipant($instance, $actor, WorkflowParticipantRole::RESPONSIBLE, $startedBy, []);
                }
            }
            foreach ($stepRows as $step) {
                if ((string)$step->getCompletedBy() !== '') {
                    $this->recordActorParticipant($instance, $step, (string)$step->getCompletedBy(), $participants);
                }
            }

            if ($rejected !== null) {
                $instance->setOutcome(self::OUTCOME_REJECTED);
                $instance->setCurrentStep(null);
                $instance->setEndedAt($rejected->getCompletedAt());
                $instance->setEndedBy($rejected->getCompletedBy());
                $instance->setStatus(WorkflowStatus::REJECTED);
                $this->events->record($id, WorkflowEventType::REJECTED, $rejected->getCompletedBy(), null,
                    $rejected->getReason() !== null ? ['reason' => $rejected->getReason()] : [],
                    WorkflowEventType::VISIBILITY_ALL, $rejected->getCompletedAt());
            } elseif ($active === null) {
                $instance->setOutcome(self::OUTCOME_COMPLETED);
                $instance->setCurrentStep(null);
                $instance->setEndedAt($previous);
                $instance->setEndedBy(end($stepRows)->getCompletedBy());
                $instance->setStatus(WorkflowStatus::COMPLETED);
                $this->events->record($id, WorkflowEventType::COMPLETED, $instance->getEndedBy(), null, [],
                    WorkflowEventType::VISIBILITY_ALL, $previous);
            } else {
                $instance->setCurrentStep($active->getStepKey());
                $instance->setStatus($this->deriveOpenStatus($stepRows));
            }
            $instance->setUpdatedAt($previous);
            $instance = $this->instances->update($instance);
            $this->audit($instance, 'imported', null, ['status' => $instance->getStatus()]);

            return $this->finish($instance, $stepRows, $startedBy);
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    // Status derivation — the one place the instance status comes from
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The open status the step rows support: `in_progress` while a step is
     * being worked on, `waiting` while one waits for information,
     * `submitted` while the first step is available and nothing has ever
     * been started, `in_progress` when a later step is available after an
     * earlier one completed.
     *
     * @param WorkflowStep[] $steps
     */
    public function deriveOpenStatus(array $steps): string {
        $anyCompleted = false;
        foreach ($steps as $step) {
            switch ($step->getStepStatus()) {
                case WorkflowStepStatus::IN_PROGRESS:
                    return WorkflowStatus::IN_PROGRESS;
                case WorkflowStepStatus::WAITING_FOR_INFORMATION:
                    return WorkflowStatus::WAITING;
                case WorkflowStepStatus::COMPLETED:
                    $anyCompleted = true;
                    break;
            }
        }
        return $anyCompleted ? WorkflowStatus::IN_PROGRESS : WorkflowStatus::SUBMITTED;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internals
    // ──────────────────────────────────────────────────────────────────────

    private function load(int $id): WorkflowInstance {
        $instance = $this->instances->findById($id);
        if ($instance === null) {
            throw new NotFoundException($this->l->t('Workflow not found.'));
        }
        return $instance;
    }

    /**
     * `load()` for the reads: on the basic tier an ended workflow is not
     * there (phase 4). The answer is the same 404 a purged one gives, so
     * nothing distinguishes "retained by a past licence" from "gone".
     */
    private function loadVisible(int $id): WorkflowInstance {
        $instance = $this->load($id);
        if (!WorkflowStatus::isOpen($instance->getStatus()) && !$this->tier->can(WorkflowCapability::COMPLETED_HISTORY)) {
            throw new NotFoundException($this->l->t('Workflow not found.'));
        }
        return $instance;
    }

    /**
     * The last thing every write does: build the caller's view and, when
     * the workflow has just ended on the basic tier, purge it (phase 4).
     * The view is built *before* the purge from the rows still in memory —
     * it is the temporary completion confirmation the caller sees once —
     * and the notifications queued for after the commit hold their own
     * copies of what they need. Runs inside the write's transaction, so a
     * failing purge rolls the ending back with it.
     *
     * @param WorkflowStep[] $steps
     * @return array<string, mixed>
     */
    private function finish(WorkflowInstance $instance, array $steps, string $uid, ?string $focusKey = null): array {
        $id   = (int)$instance->getId();
        $view = $this->viewOf($instance, $steps, $this->participants->findByInstance($id), $uid, null, $focusKey);
        if (WorkflowStatus::isOpen($instance->getStatus())) {
            return $view;
        }
        // v4.10.38 — the request has ended: every share the paperclip made
        // for it is removed. Read now, before an unlicensed ending purges the
        // rows; removed after the commit, because a share is not part of the
        // transaction. The daily job catches one that could not be removed.
        $live = $this->shares?->liveShares($id) ?? [];
        // Files attached to the very write that ended it are not shared:
        // the event still names them, and nobody is handed a share that
        // the ending would take back at once.
        $this->pendingShares = [];
        if ($live !== []) {
            $this->afterCommit[] = fn () => $this->shares?->removeShares($live);
        }
        if (!$this->tier->retainsEnded()) {
            // Phase 4, unchanged: on the unlicensed tier the ending *is* the
            // removal. No archive is written, nothing is projected, and the
            // branch below is not reached.
            $this->purge($instance);
            $view['purged'] = true;
            return $view;
        }
        // Phase 6: the licensed ending writes its record, in the same
        // transaction, so a workflow is never quietly completed without one.
        $archive         = $this->archive->record($instance, $steps, $this->serviceTeamOf($steps));
        $view['archive'] = [
            'archiveId' => (int)$archive->getId(),
            'reference' => $archive->getRefNumber(),
        ];
        return $view;
    }

    /**
     * Remove every row of an ended workflow — events, participants, steps,
     * then the instance — inside the caller's transaction (phase 4;
     * `docs/unlicensed-workflow-data-lifecycle.md`). What stays: the audit
     * lines already written (`workflow.<key>.<event>` with the instance id,
     * the team and the actor uid — no submission text) plus one
     * `workflow.<key>.purged` line, and the notifications sent after the
     * commit, which carry the title's data only.
     *
     * Idempotent by construction: a second purge of the same id deletes
     * nothing and is not an error, which is what a background job that
     * still holds an id needs.
     */
    private function purge(WorkflowInstance $instance): void {
        $id = (int)$instance->getId();
        // v4.10.21 — a licence that lapsed *while* the workflow ran is the
        // one way an ended workflow can have documents and even an archive
        // (written by an earlier ending that rolled back, or by a licence
        // that was still valid a moment ago). Both go with it: a record
        // pointing at rows that no longer exist is worse than no record.
        $this->archive->removeForInstance($id);
        $this->attachments->deleteByInstance($id);
        $this->events->deleteForInstance($id);
        $this->participants->deleteByInstance($id);
        $this->steps->deleteByInstance($id);
        $removed = $this->instances->deleteById($id);
        $this->audit($instance, 'purged', null, ['outcome' => (string)$instance->getOutcome(), 'rows' => $removed]);
        $this->logger->info('[TeamHub][Workflow] ended workflow purged (unlicensed tier)', [
            'instance' => $id, 'definition' => $instance->getDefinitionKey(), 'outcome' => $instance->getOutcome(), 'app' => Application::APP_ID,
        ]);
    }

    /**
     * @return array{0: WorkflowInstance, 1: WorkflowStep[], 2: WorkflowParticipant[]}
     * @throws WorkflowTransitionException when the workflow has ended
     */
    private function loadOpen(int $id): array {
        $instance = $this->load($id);
        if (!WorkflowStatus::isOpen($instance->getStatus())) {
            throw new WorkflowTransitionException($this->l->t('This workflow has already ended.'));
        }
        return [$instance, $this->steps->findByInstance($id), $this->participants->findByInstance($id)];
    }

    /**
     * The step the request is at: the first open task of the latest step
     * that has open tasks (v4.10.37). A non-blocking task left open from an
     * earlier step is open too, but the request is past it.
     *
     * @param WorkflowStep[] $steps
     */
    private function activeStepOf(array $steps): ?WorkflowStep {
        $current = null;
        foreach ($this->activeStepsOf($steps) as $step) {
            if ($current === null || $step->getStepOrder() > $current->getStepOrder()) {
                $current = $step;
            }
        }
        return $current;
    }

    /**
     * Every open task, in step order (v4.10.37).
     *
     * @param WorkflowStep[] $steps
     * @return WorkflowStep[]
     */
    private function activeStepsOf(array $steps): array {
        $out = array_values(array_filter($steps, static fn (WorkflowStep $s): bool => WorkflowStepStatus::isActive($s->getStepStatus())));
        usort($out, static fn (WorkflowStep $a, WorkflowStep $b): int => [$a->getStepOrder(), (int)$a->getId()] <=> [$b->getStepOrder(), (int)$b->getId()]);
        return $out;
    }

    /**
     * The tasks of one step (v4.10.37); one row for every step of one task.
     *
     * @param WorkflowStep[] $steps
     * @return WorkflowStep[]
     */
    private function rowsOfOrder(array $steps, int $order): array {
        return array_values(array_filter($steps, static fn (WorkflowStep $s): bool => $s->getStepOrder() === $order));
    }

    /**
     * The task a person acts on (v4.10.37). Named by its key, it must be
     * open; unnamed, it is the open task that is most plainly theirs —
     * claimed by or assigned to them, then theirs by name, then one they
     * may take up — and otherwise the task the request is at, so the
     * permission check that follows refuses with the usual words.
     *
     * @param WorkflowStep[] $steps
     * @throws WorkflowTransitionException the named task is not open
     */
    private function pickStep(WorkflowInstance $instance, array $steps, string $uid, ?string $stepKey): WorkflowStep {
        if ($stepKey !== null && $stepKey !== '') {
            foreach ($steps as $step) {
                if ($step->getStepKey() === $stepKey) {
                    if (!WorkflowStepStatus::isActive($step->getStepStatus())) {
                        throw new WorkflowTransitionException($this->l->t('This task is no longer open.'));
                    }
                    return $step;
                }
            }
            throw new WorkflowTransitionException($this->l->t('This task is no longer open.'));
        }
        return $this->preferredActiveFor($uid, $instance, $steps) ?? $this->requireActiveStep($instance, $steps);
    }

    /**
     * The open task that is most plainly this person's, or null when the
     * request has none (v4.10.37). See `pickStep()`.
     *
     * @param WorkflowStep[] $steps
     */
    private function preferredActiveFor(string $uid, WorkflowInstance $instance, array $steps): ?WorkflowStep {
        $active = $this->activeStepsOf($steps);
        if ($active === []) {
            return null;
        }
        foreach ($active as $step) {
            if ($uid !== '' && $step->getAssignee() === $uid) {
                return $step;
            }
        }
        foreach ($active as $step) {
            if ($step->getActorType() === WorkflowActor::TYPE_USER && $step->getActorId() === $uid) {
                return $step;
            }
        }
        foreach ($active as $step) {
            if ($step->getAssignee() === ''
                && $this->resolver->holds($uid, WorkflowActor::of($step->getActorType(), $step->getActorId()), $instance->getTeamId())
            ) {
                return $step;
            }
        }
        return $this->activeStepOf($steps);
    }

    /**
     * The task waiting for an answer (v4.10.37): the one named, or the first
     * that waits.
     *
     * @param WorkflowStep[] $steps
     */
    private function waitingStep(WorkflowInstance $instance, array $steps, ?string $stepKey): WorkflowStep {
        if ($stepKey !== null && $stepKey !== '') {
            return $this->pickStep($instance, $steps, '', $stepKey);
        }
        foreach ($this->activeStepsOf($steps) as $step) {
            if ($step->getStepStatus() === WorkflowStepStatus::WAITING_FOR_INFORMATION) {
                return $step;
            }
        }
        return $this->requireActiveStep($instance, $steps);
    }

    /**
     * Refuse to complete the task that would end the request while another
     * task is still open (v4.10.37) — a non-blocking one left from an
     * earlier step. *"A non-blocking task must be finished before the
     * request can close"* (`docs/service-builder.md` § 4).
     *
     * @param WorkflowStep[] $steps
     */
    private function assertMayEnd(array $steps, WorkflowStep $step): void {
        if (!$this->wouldEnd($steps, $step)) {
            return;
        }
        foreach ($this->activeStepsOf($steps) as $other) {
            if ($other !== $step) {
                throw new WorkflowTransitionException(
                    // TRANSLATORS: %s is the name of a task still open, e.g. "Privacy check"
                    $this->l->t('This request cannot end while "%s" is still open.', [$other->getLabel()]));
            }
        }
    }

    /**
     * Would completing this task end the request: it is on the last step,
     * and every other task of that step the request waits for is done?
     *
     * @param WorkflowStep[] $steps
     */
    private function wouldEnd(array $steps, WorkflowStep $step): bool {
        foreach ($steps as $other) {
            if ($other->getStepOrder() > $step->getStepOrder()) {
                return false;
            }
        }
        foreach ($this->rowsOfOrder($steps, $step->getStepOrder()) as $other) {
            if ($other !== $step && !$other->isNonBlocking() && $other->getStepStatus() !== WorkflowStepStatus::COMPLETED) {
                return false;
            }
        }
        return true;
    }

    /** @param WorkflowStep[] $steps */
    private function requireActiveStep(WorkflowInstance $instance, array $steps): WorkflowStep {
        $step = $this->activeStepOf($steps);
        if ($step === null) {
            // An open instance without an active step is a broken row, not a
            // stale view; log it as such and refuse.
            $this->logger->error('[TeamHub][Workflow] open instance without an active step', [
                'instance' => $instance->getId(), 'app' => Application::APP_ID,
            ]);
            throw new WorkflowTransitionException($this->l->t('This workflow has no step to act on.'));
        }
        return $step;
    }

    /** @param WorkflowStep[] $steps */
    private function stepAfter(array $steps, WorkflowStep $current): ?WorkflowStep {
        foreach ($steps as $step) {
            if ($step->getStepOrder() === $current->getStepOrder() + 1) {
                return $step;
            }
        }
        return null;
    }

    /**
     * The whole permission rule for acting on a step: hold the actor, and -
     * once somebody has claimed it - be that person.
     *
     * **Claiming narrows, it does not replace.** A service team's step is
     * held by every eligible agent; an agent who claims it takes it out of
     * the shared queue, and from then on only they (or the service owner,
     * who can always step in) may complete or reject it. Any other agent
     * must take it over explicitly, which is an assignment and is recorded
     * as one - two agents answering the same request without knowing is the
     * failure a queue exists to prevent.
     */
    private function assertHolder(string $uid, WorkflowStep $step, WorkflowInstance $instance): void {
        $actor = WorkflowActor::of($step->getActorType(), $step->getActorId());
        if (!$this->resolver->holds($uid, $actor, $instance->getTeamId())) {
            throw new AccessDeniedException($this->l->t('This step is not yours to act on.'));
        }
        if ($step->getActorType() === WorkflowActor::TYPE_SERVICE_AGENT && $step->getAssignee() === '') {
            // v4.10.39 — see mayActOnClaimed().
            throw new AccessDeniedException($this->l->t('Claim this task before you act on it.'));
        }
        if (!$this->mayActOnClaimed($uid, $step)) {
            throw new AccessDeniedException($this->l->t('Another agent has this request; take it over first.'));
        }
    }

    /**
     * Whether a step is this person's to act on as far as claiming goes.
     * Outside a service team: true unless somebody else claimed it. A team
     * task: only once claimed (v4.10.39), and then by whoever claimed it.
     *
     * The one person who may act on somebody else's claim is an admin of the
     * service team — the role "service owner" maps to since v4.10.23.
     */
    private function mayActOnClaimed(string $uid, WorkflowStep $step): bool {
        $assignee = $step->getAssignee();
        if ($step->getActorType() !== WorkflowActor::TYPE_SERVICE_AGENT) {
            return $assignee === '' || $assignee === $uid;
        }
        // v4.10.39 — **a team task is claimed before anybody acts on it**
        // (Justin, 2026-09-25: Jaap saw *Complete step* on a task he had not
        // claimed). Until then it is the queue's, and the one thing to do
        // with it is claim it; completing, rejecting or asking the requester
        // on an unclaimed task was how two members could answer the same
        // request without knowing.
        if ($assignee === '') {
            return false;
        }
        return $assignee === $uid || $this->serviceTeams->isServiceOwner($uid, $step->getActorId());
    }

    /**
     * May this person close the request for the service team (v4.10.31)?
     *
     * Only for a definition that allows it (`IWorkflowClosableByDesk`), and
     * only for an admin of the service team that handles the request — read
     * from the instance's own step rows, so a service moved or rebuilt since
     * does not change who may close a request made before. At any step:
     * Justin, 2026-09-24, *"it's not automatically a rejection. The Service
     * request says what's done and shows which step wasn't done."*
     *
     * @param WorkflowStep[] $steps
     */
    private function mayCloseForDesk(string $uid, WorkflowInstance $instance, array $steps): bool {
        if ($uid === '' || !WorkflowStatus::isOpen($instance->getStatus())) {
            return false;
        }
        if (!$this->registry->get($instance->getDefinitionKey()) instanceof IWorkflowClosableByDesk) {
            return false;
        }
        $desk = $this->serviceTeamOf($steps);
        return $desk !== '' && $this->serviceTeams->isServiceOwner($uid, $desk);
    }

    /**
     * Is this person an eligible agent of the service team handling the
     * workflow? False for every workflow no service team handles - which is
     * what makes the internal half invisible everywhere else.
     *
     * @param WorkflowStep[] $steps
     */
    private function isHandlingAgent(string $uid, array $steps): bool {
        $serviceTeamId = $this->serviceTeamOf($steps);
        return $serviceTeamId !== '' && $this->serviceTeams->isEligibleAgent($uid, $serviceTeamId);
    }

    /**
     * The service team handling this workflow, or '' when none does. Read
     * from the steps rather than from a column on the instance: the actor
     * on the step is already the single truth about who handles what, and a
     * second copy could disagree with it.
     *
     * @param WorkflowStep[] $steps
     */
    private function serviceTeamOf(array $steps): string {
        foreach ($steps as $step) {
            if ($step->getActorType() === WorkflowActor::TYPE_SERVICE_AGENT) {
                return $step->getActorId();
            }
        }
        return '';
    }

    /**
     * The active step, when it belongs to a service team the caller may work.
     *
     * @param WorkflowStep[] $steps
     * @throws AccessDeniedException
     * @throws WorkflowTransitionException
     */
    private function requireServiceStep(string $uid, WorkflowInstance $instance, array $steps, ?string $stepKey = null, bool $toClaim = false): WorkflowStep {
        if ($stepKey !== null && $stepKey !== '') {
            $step = $this->pickStep($instance, $steps, $uid, $stepKey);
        } else {
            // v4.10.37 — unnamed: the desk task this person has, or — when
            // claiming — the first nobody has, or the task the request is at.
            $step  = null;
            $desk  = array_values(array_filter(
                $this->activeStepsOf($steps),
                static fn (WorkflowStep $s): bool => $s->getActorType() === WorkflowActor::TYPE_SERVICE_AGENT,
            ));
            foreach ($desk as $candidate) {
                if ($candidate->getAssignee() === $uid) {
                    $step = $candidate;
                    break;
                }
            }
            if ($step === null && $toClaim) {
                foreach ($desk as $candidate) {
                    if ($candidate->getAssignee() === '') {
                        $step = $candidate;
                        break;
                    }
                }
            }
            $step ??= $desk[0] ?? $this->requireActiveStep($instance, $steps);
        }
        if ($step->getActorType() !== WorkflowActor::TYPE_SERVICE_AGENT) {
            throw new WorkflowTransitionException($this->l->t('This step is not handled by a service team.'));
        }
        if (!$this->serviceTeams->isEligibleAgent($uid, $step->getActorId())) {
            throw new AccessDeniedException($this->l->t('You are not an agent of the service team handling this request.'));
        }
        return $step;
    }

    private function assertNotBlocked(WorkflowInstance $instance): void {
        if ($instance->getStatus() === WorkflowStatus::BLOCKED) {
            throw new WorkflowTransitionException($this->l->t('This workflow is blocked; lift the block first.'));
        }
    }

    /**
     * v4.10.21 — the rule itself moved to `WorkflowActorResolver`, which is
     * where "who holds what, right now" already lives. The archive asks the
     * same question of an ended workflow, and one rule answered in two
     * classes is one rule that can drift.
     *
     * @param WorkflowParticipant[] $participants
     */
    private function isParticipant(string $uid, WorkflowInstance $instance, array $participants): bool {
        return $this->resolver->isAmongParticipants($uid, $participants, $instance->getTeamId());
    }

    /** @param WorkflowParticipant[] $participants */
    private function assertMayView(string $uid, WorkflowInstance $instance, array $participants): void {
        if ($this->isParticipant($uid, $instance, $participants) || $this->resolver->isNextcloudAdmin($uid)) {
            return;
        }
        throw new AccessDeniedException($this->l->t('You are not part of this workflow.'));
    }

    /** @param WorkflowParticipant[] $participants */
    private function startStepRow(WorkflowInstance $instance, WorkflowStep $step, string $uid, array $participants): void {
        $this->moveStep($step, WorkflowStepStatus::IN_PROGRESS);
        $step->setStartedAt($this->timeFactory->getTime());
        $this->steps->update($step);
        $this->recordActorParticipant($instance, $step, $uid, $participants);
        $this->events->record((int)$instance->getId(), WorkflowEventType::STEP_STARTED, $uid, $step);
    }

    /**
     * Complete a step in progress: the row, the event, the audit line, then
     * either the next step becomes available (its holders are told) or the
     * workflow completes (everybody is told).
     *
     * @param WorkflowStep[] $steps
     */
    private function completeStepRow(WorkflowInstance $instance, array $steps, WorkflowStep $step, string $uid, ?string $note, array $files = []): WorkflowInstance {
        // v4.10.29 — the definition's side effect goes first, before any row
        // moves: when it throws, nothing below has happened and the
        // transaction rolls back to a step still waiting for its answer.
        $definition = $this->registry->get($instance->getDefinitionKey());
        if ($definition instanceof IWorkflowDefinitionHooks) {
            $definition->onStepCompleted($instance, $step->getStepKey(), $uid);
        }
        $this->moveStep($step, WorkflowStepStatus::COMPLETED);
        $now = $this->timeFactory->getTime();
        $step->setCompletedAt($now);
        $step->setCompletedBy($uid);
        $step->setActionTaken('complete');
        $step->setReason($note);
        $this->steps->update($step);
        $this->events->record((int)$instance->getId(), WorkflowEventType::STEP_COMPLETED, $uid, $step,
            ($note !== null ? ['note' => $note] : []) + ($files !== [] ? ['files' => $files] : []));
        $this->audit($instance, 'step_completed', $uid, ['step' => $step->getStepKey()]);
        $this->deskAudit($instance, $step, 'answered', $uid);

        // v4.10.37 — a step moves on when every task of it the request waits
        // for is done; until then the other tasks carry on.
        $order = $step->getStepOrder();
        foreach ($this->rowsOfOrder($steps, $order) as $sibling) {
            if (!$sibling->isNonBlocking() && $sibling->getStepStatus() !== WorkflowStepStatus::COMPLETED) {
                return $this->syncCurrentStep($this->reflectSteps($instance, $steps), $steps);
            }
        }

        $next = array_values(array_filter(
            $this->rowsOfOrder($steps, $order + 1),
            static fn (WorkflowStep $s): bool => $s->getStepStatus() === WorkflowStepStatus::PENDING,
        ));
        $later = false;
        foreach ($steps as $other) {
            $later = $later || $other->getStepOrder() > $order;
        }
        if ($next === [] && $later) {
            // A non-blocking task finishing after the request moved past its
            // step: nothing moves, the request carries on where it is.
            return $this->syncCurrentStep($this->reflectSteps($instance, $steps), $steps);
        }
        if ($next !== []) {
            foreach ($next as $row) {
                $this->moveStep($row, WorkflowStepStatus::AVAILABLE);
                $row->setEnteredAt($now);
                $this->steps->update($row);
                $this->events->record((int)$instance->getId(), WorkflowEventType::STEP_AVAILABLE, null, $row);
            }
            $instance->setCurrentStep($next[0]->getStepKey());
            $instance = $this->reflectSteps($instance, $steps);
            $this->notifyStageAvailable($instance, $next, $uid);
            $this->deskAuditStage($instance, $next, 'received', $instance->getStartedBy());
            return $instance;
        }

        $instance = $this->endInstance($instance, WorkflowStatus::COMPLETED, self::OUTCOME_COMPLETED, $uid);
        $this->events->record((int)$instance->getId(), WorkflowEventType::COMPLETED, $uid);
        $this->audit($instance, 'completed', $uid);
        $this->notifyEnded($instance, $uid, null);
        return $instance;
    }

    /**
     * A person acting on a group or team-role step is recorded as a
     * participant in their own name, so the workflow knows who exactly
     * took part. A user already on the list is not added twice.
     *
     * @param WorkflowParticipant[] $participants
     */
    private function recordActorParticipant(WorkflowInstance $instance, WorkflowStep $step, string $uid, array &$participants): void {
        foreach ($participants as $p) {
            if ($p->getActorType() === WorkflowActor::TYPE_USER && $p->getActorId() === $uid && $p->getRemovedAt() === null) {
                return;
            }
        }
        $participants[] = $this->addParticipant($instance, WorkflowActor::user($uid), WorkflowParticipantRole::ACTOR, $uid, ['step' => $step->getStepKey()]);
    }

    /** @param array<string, mixed> $payload */
    private function addParticipant(WorkflowInstance $instance, WorkflowActor $actor, string $role, ?string $addedBy, array $payload): WorkflowParticipant {
        $p = new WorkflowParticipant();
        $p->setInstanceId((int)$instance->getId());
        $p->setActorType($actor->type);
        $p->setActorId($actor->id);
        $p->setWfRole($role);
        $p->setAddedAt($this->timeFactory->getTime());
        $p->setAddedBy($addedBy);
        $p = $this->participants->insert($p);
        $this->events->record((int)$instance->getId(), WorkflowEventType::PARTICIPANT_ADDED, $addedBy, null,
            $payload + ['actorType' => $actor->type, 'actorId' => $actor->id, 'role' => $role]);
        return $p;
    }

    private function moveStep(WorkflowStep $step, string $to): void {
        WorkflowStepStatus::assertTransition($step->getStepStatus(), $to);
        $step->setStepStatus($to);
    }

    private function moveInstance(WorkflowInstance $instance, string $to): WorkflowInstance {
        WorkflowStatus::assertTransition($instance->getStatus(), $to);
        $instance->setStatus($to);
        $instance->setUpdatedAt($this->timeFactory->getTime());
        return $this->instances->update($instance);
    }

    /**
     * Re-derive the instance status from its steps after a step change.
     *
     * @param WorkflowStep[] $steps
     */
    private function reflectSteps(WorkflowInstance $instance, array $steps): WorkflowInstance {
        return $this->moveInstance($instance, $this->deriveOpenStatus($steps));
    }

    /**
     * v4.10.50 — tell a definition that keeps a decision of its own that
     * the workflow was rejected or withdrawn ({@see IWorkflowDefinitionEndHook}).
     */
    private function notifyEndHook(WorkflowInstance $instance, string $outcome, string $uid, ?string $reason): void {
        $definition = $this->registry->get($instance->getDefinitionKey());
        if ($definition instanceof IWorkflowDefinitionEndHook) {
            $definition->onEnded($instance, $outcome, $uid, $reason);
        }
    }

    private function endInstance(WorkflowInstance $instance, string $status, string $outcome, string $uid): WorkflowInstance {
        $now = $this->timeFactory->getTime();
        $instance->setOutcome($outcome);
        $instance->setCurrentStep(null);
        $instance->setEndedAt($now);
        $instance->setEndedBy($uid);
        return $this->moveInstance($instance, $status);
    }

    /**
     * Every step after the one that ended the workflow: skipped (rejected
     * workflow) or cancelled (withdrawn workflow).
     *
     * @param WorkflowStep[] $steps
     */
    private function closeRemaining(WorkflowInstance $instance, array $steps, ?WorkflowStep $ended, string $status, ?string $eventType): void {
        foreach ($steps as $step) {
            if ($ended !== null && $step->getStepOrder() <= $ended->getStepOrder()) {
                continue;
            }
            if ($step->getStepStatus() !== WorkflowStepStatus::PENDING) {
                continue;
            }
            $this->moveStep($step, $status);
            $this->steps->update($step);
            if ($eventType !== null) {
                $this->events->record((int)$instance->getId(), $eventType, null, $step);
            }
        }
    }

    /**
     * The paperclip (v4.10.38, `docs/service-builder.md` § 7): check the files
     * a person picked beside what they wrote, and share them with the other
     * side once the write has committed.
     *
     * The requester's files go to the service team, a team member's to the
     * requester — or, on an internal note, to the team. Only a request a
     * service team handles takes files, and only when its service allows
     * them. Refused before anything is written, so a message never goes out
     * without the files its writer attached.
     *
     * @param WorkflowStep[] $steps
     * @return array<int, array{fileId: int, name: string}> what the event lists
     * @throws ValidationException
     */
    private function attachFiles(WorkflowInstance $instance, array $steps, string $uid, mixed $fileIds, bool $internal, ?string $stepKey): array {
        if ($fileIds === null || $fileIds === '' || $fileIds === []) {
            return [];
        }
        if ($this->shares === null) {
            throw new ValidationException($this->l->t('Files cannot be attached here.'));
        }
        $desk = $this->serviceTeamOf($steps);
        if ($desk === '') {
            throw new ValidationException($this->l->t('Files can be attached to service requests only.'));
        }
        $settings = WorkflowShareService::settingsOf($instance->getData());
        if (!$settings['allowed']) {
            throw new ValidationException($this->l->t('This service does not take files.'));
        }
        $files = $this->shares->resolve($uid, $fileIds);
        if ($files === []) {
            return [];
        }

        $toTeam    = $internal || $uid === $instance->getStartedBy();
        $recipient = $toTeam ? $desk : $instance->getStartedBy();
        $audience  = $toTeam ? WorkflowShareService::AUDIENCE_TEAM : WorkflowShareService::AUDIENCE_REQUESTER;
        $visibility = $internal ? WorkflowAttachmentVisibility::INTERNAL : WorkflowAttachmentVisibility::REQUESTER;
        $this->pendingShares[] = fn () => $this->shares?->share($instance, $uid, $files, $audience, $recipient, $visibility, $stepKey, $settings);
        return $files;
    }

    /**
     * Every open task but one ends with the request (v4.10.37): skipped when
     * a task is rejected or an admin closes the request, cancelled when the
     * requester withdraws. `$action` and `$reason` are written on the rows
     * that ended by a person's decision; `$eventType` is recorded per row
     * when given.
     *
     * @param WorkflowStep[] $steps
     */
    private function closeActive(
        WorkflowInstance $instance,
        array $steps,
        ?WorkflowStep $except,
        string $status,
        ?string $action,
        string $uid,
        ?string $reason,
        ?string $eventType,
    ): void {
        $now = $this->timeFactory->getTime();
        foreach ($this->activeStepsOf($steps) as $step) {
            if ($step === $except) {
                continue;
            }
            $this->moveStep($step, $status);
            if ($action !== null) {
                $step->setCompletedAt($now);
                $step->setCompletedBy($uid);
                $step->setActionTaken($action);
                $step->setReason($reason);
            }
            $this->steps->update($step);
            if ($eventType !== null) {
                $this->events->record((int)$instance->getId(), $eventType, null, $step);
            }
        }
    }

    /**
     * The rows that were open a moment ago and have just ended with this
     * status by a person's act — for the desk's one activity line.
     *
     * @param WorkflowStep[] $steps
     * @return WorkflowStep[]
     */
    private function activeOrFinished(array $steps, string $status): array {
        return array_values(array_filter(
            $steps,
            static fn (WorkflowStep $s): bool => $s->getStepStatus() === $status && $s->getActionTaken() !== null,
        ));
    }

    /**
     * Point the instance's `current_step` at the step the request is at
     * after a task finished inside it (v4.10.37).
     *
     * @param WorkflowStep[] $steps
     */
    private function syncCurrentStep(WorkflowInstance $instance, array $steps): WorkflowInstance {
        $current = $this->activeStepOf($steps);
        if ($current !== null && $instance->getCurrentStep() !== $current->getStepKey()) {
            $instance->setCurrentStep($current->getStepKey());
            $instance = $this->instances->update($instance);
        }
        return $instance;
    }

    /**
     * Tell the holders of every task of a step that just became available
     * (v4.10.37). The request's earlier notifications are withdrawn once,
     * first: withdrawing per task would take back the one just sent for the
     * task before it.
     *
     * @param WorkflowStep[] $rows
     */
    private function notifyStageAvailable(WorkflowInstance $instance, array $rows, ?string $causedBy): void {
        if ($rows === []) {
            return;
        }
        $id = (int)$instance->getId();
        $this->afterCommit[] = function () use ($instance, $rows, $causedBy, $id): void {
            $this->notifier->withdraw($id);
            foreach ($rows as $row) {
                $this->notifier->stepAvailable($instance, $row, $causedBy);
            }
        };
    }

    /**
     * One activity line on the desk's stream for a step, however many of
     * its tasks are the desk's (v4.10.37) — written against its first desk
     * task. A step with no desk task writes nothing, as before.
     *
     * @param WorkflowStep[] $rows
     * @param array<string, mixed> $meta
     */
    private function deskAuditStage(WorkflowInstance $instance, array $rows, string $event, ?string $uid, array $meta = []): void {
        foreach ($rows as $row) {
            if ($row->getActorType() === WorkflowActor::TYPE_SERVICE_AGENT) {
                $this->deskAudit($instance, $row, $event, $uid, $meta);
                return;
            }
        }
    }

    private function notifyEnded(WorkflowInstance $instance, string $endedBy, ?string $reason): void {
        $id    = (int)$instance->getId();
        $users = [];
        foreach ($this->participants->findByInstance($id) as $p) {
            if ($p->getActorType() === WorkflowActor::TYPE_USER && $p->getRemovedAt() === null) {
                $users[] = $p->getActorId();
            }
        }
        $this->afterCommit[] = function () use ($instance, $users, $endedBy, $reason, $id): void {
            $this->notifier->withdraw($id);
            $this->notifier->ended($instance, $users, $endedBy, $reason);
        };
    }

    /** Trim and cap free text; null when empty. */
    private function text(?string $raw): ?string {
        $t = trim((string)$raw);
        return $t === '' ? null : mb_substr($t, 0, self::MAX_TEXT);
    }

    /** @param array<string, mixed> $meta */
    private function audit(WorkflowInstance $instance, string $event, ?string $uid, array $meta = []): void {
        $this->auditService->log(
            $instance->getTeamId(),
            'workflow.' . $instance->getDefinitionKey() . '.' . $event,
            $uid,
            'workflow',
            (string)$instance->getId(),
            $meta,
        );
    }

    /**
     * One state change of a request **in a service team's queue**, written
     * against the service team (v4.10.27) so it reaches that team's activity
     * stream (`ActivityService::fetchServiceDeskActivity`).
     *
     * `audit()` above writes against the workflow's *own* team — the team
     * the request is about — which is right for that team's record and
     * wrong for the desk: the desk would never see its own work. A step that
     * is not a service step writes nothing here.
     *
     * Only state changes. An internal note is not one and never reaches the
     * stream; neither does anything the requester's side does after the desk
     * is done.
     *
     * @param array<string, mixed> $meta
     */
    private function deskAudit(WorkflowInstance $instance, WorkflowStep $step, string $event, ?string $uid, array $meta = []): void {
        if ($step->getActorType() !== WorkflowActor::TYPE_SERVICE_AGENT || $step->getActorId() === '') {
            return;
        }
        $definition = $this->registry->get($instance->getDefinitionKey());
        $title      = $definition !== null ? $definition->getTitle($this->l, $instance->getData()) : $instance->getDefinitionKey();
        $this->auditService->log(
            $step->getActorId(),
            'service.request_' . $event,
            $uid,
            'workflow',
            (string)$instance->getId(),
            $meta + ['title' => mb_substr((string)$title, 0, 255)],
        );
    }

    /**
     * One transaction around a write; the notifications it earned go out
     * only after the commit, and never after a rollback.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function transactional(callable $fn): mixed {
        $this->afterCommit   = [];
        $this->pendingShares = [];
        $this->db->beginTransaction();
        try {
            $result = $fn();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->afterCommit   = [];
            $this->pendingShares = [];
            $this->db->rollBack();
            throw $e;
        }
        $pending = array_merge($this->pendingShares, $this->afterCommit);
        $this->afterCommit   = [];
        $this->pendingShares = [];
        foreach ($pending as $task) {
            try {
                $task();
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][Workflow] post-commit task failed', [
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }
        return $result;
    }

    // ──────────────────────────────────────────────────────────────────────
    // The wire shape
    // ──────────────────────────────────────────────────────────────────────

    /**
     * What a participant sees. Row ids, retention and removed participants
     * stay inside; the step's label and the workflow's title are the
     * viewer's language.
     *
     * Phase 4 — the tier shapes the view. On both tiers `responsible` names
     * the active step's actor (a person, a group, a role) so a client never
     * has to read the step list to know who is up. On the basic tier the
     * step list is empty and `currentStep` is null (no current step, no
     * progress) and `viewer.canRequestStatus` is false. `purged` is set by
     * `finish()` on the one view an unlicensed caller sees of a workflow
     * that has just ended — the temporary completion confirmation, after
     * which the workflow does not exist.
     *
     * @param WorkflowStep[] $steps
     * @param WorkflowParticipant[] $participants
     * @return array<string, mixed>
     */
    private function viewOf(
        WorkflowInstance $instance,
        array            $steps,
        array            $participants,
        string           $viewerUid,
        /**
         * stepKey → when this viewer last asked for an update on it
         * (v4.10.17), for this instance only. `null` means "look it up",
         * which is right for a single-workflow answer; `listForParticipant()`
         * passes a slice of one batched query so a list of n workflows
         * still costs one.
         *
         * @var array<string, int>|null
         */
        ?array           $recentAsks = null,
        /**
         * v4.10.37 — the task this view answers for: the queue row's, the
         * My Work row's, the one just acted on. `null` is the task most
         * plainly the viewer's (`preferredActiveFor()`); a key that is no
         * longer open falls back the same way.
         */
        ?string          $focusKey = null,
    ): array {
        $definition = $this->registry->get($instance->getDefinitionKey());
        $data       = $instance->getData();
        $active     = null;
        if ($focusKey !== null && $focusKey !== '') {
            foreach ($steps as $candidate) {
                if ($candidate->getStepKey() === $focusKey && WorkflowStepStatus::isActive($candidate->getStepStatus())) {
                    $active = $candidate;
                }
            }
        }
        $active ??= $this->preferredActiveFor($viewerUid, $instance, $steps);
        $open       = WorkflowStatus::isOpen($instance->getStatus());
        $isAdmin    = $this->resolver->isNextcloudAdmin($viewerUid);
        $holds      = $active !== null
            && $this->resolver->holds($viewerUid, WorkflowActor::of($active->getActorType(), $active->getActorId()), $instance->getTeamId());
        $isParticipant = $this->isParticipant($viewerUid, $instance, $participants);
        // v4.10.20 - the service-team half. `$serviceTeamId` is '' for every
        // workflow no desk handles, and then everything below is absent
        // rather than empty: a workflow with no service team must not grow
        // an internal section that says "nobody".
        $serviceTeamId = $this->serviceTeamOf($steps);
        $isAgent       = $serviceTeamId !== '' && $this->serviceTeams->isEligibleAgent($viewerUid, $serviceTeamId);
        $assignee      = $active !== null ? $active->getAssignee() : '';
        // A claimed step is only actionable by the person who has it (or the
        // service owner) - the same rule `assertHolder()` enforces, so the
        // button and the call agree.
        $claimBlocks = $active !== null && !$this->mayActOnClaimed($viewerUid, $active);
        $canAct     = $open && $holds && !$claimBlocks && $instance->getStatus() !== WorkflowStatus::BLOCKED;
        // v4.10.31 — an admin of the desk may close the request at any step.
        $canClose   = $open && $this->mayCloseForDesk($viewerUid, $instance, $steps);
        // v4.10.31 — "action required", which is narrower than "you may
        // act": a desk step nobody has claimed can be acted on by every
        // member of the desk, but it is nobody's own work until somebody
        // claims it. My Work files a request under Action required on this,
        // and under Waiting for others otherwise.
        $actionRequired = $canAct && $active !== null
            && ($active->getActorType() !== WorkflowActor::TYPE_SERVICE_AGENT || $assignee === $viewerUid);
        $showSteps  = $this->tier->can(WorkflowCapability::VIEW_CURRENT_STEP);
        // v4.11.0 — the conversation: who may write, and whether a question
        // from the desk is waiting for the requester's answer.
        $messageSide = $serviceTeamId !== '' && $this->serviceTeams->isAvailable()
            ? $this->messageSide($viewerUid, $instance, $steps)
            : null;
        $awaitingAnswer = false;
        foreach ($steps as $step) {
            $awaitingAnswer = $awaitingAnswer
                || ($step->getActorType() === WorkflowActor::TYPE_SERVICE_AGENT
                    && $step->getStepStatus() === WorkflowStepStatus::WAITING_FOR_INFORMATION);
        }
        // v4.10.17 — the cooldown is part of the answer, not only of the
        // refusal. `requestStatus()` allows one ask per person per step per
        // 24 hours and answers 429 otherwise; while the view said the
        // viewer could ask, My Work kept offering "Ask for an update" and
        // the press failed. CLAUDE.md § Permissions: an action somebody
        // cannot take is hidden, not left to fail.
        $canAskStatus = $this->tier->can(WorkflowCapability::REQUEST_STATUS_UPDATE);
        if ($canAskStatus && $active !== null) {
            if ($recentAsks === null) {
                $recentAsks = $this->events->recentStatusRequests(
                    [(int)$instance->getId()],
                    $viewerUid,
                    $this->timeFactory->getTime() - self::STATUS_REQUEST_INTERVAL,
                )[(int)$instance->getId()] ?? [];
            }
            $canAskStatus = !isset($recentAsks[$active->getStepKey()]);
        }

        $stepViews = [];
        if ($showSteps) {
            foreach ($steps as $step) {
                // v4.10.36 — a requester step's links are the thing to do,
                // for everybody on the request; a desk step's are the team's
                // own material (work instructions, the system to go to) and
                // only its agents see them (`docs/service-builder.md` § 6.1).
                $links = $step->getActorType() === WorkflowActor::TYPE_SERVICE_AGENT && !$isAgent
                    ? []
                    : $step->linkList();
                $isOpenTask = $open && WorkflowStepStatus::isActive($step->getStepStatus());
                $taskAssignee = $step->getAssignee();
                $stepViews[] = [
                    'key'         => $step->getStepKey(),
                    'order'       => $step->getStepOrder(),
                    // v4.10.37 — the step's name over its tasks ('' for a
                    // step of one task), and whether it may be left open.
                    'stageLabel'  => $step->getStageLabel(),
                    'nonBlocking' => $step->isNonBlocking(),
                    // v4.10.37 — may this viewer act on this task now: what
                    // a requester's link button completes, per task.
                    'canAct'      => $isOpenTask
                        && $instance->getStatus() !== WorkflowStatus::BLOCKED
                        && $this->resolver->holds($viewerUid, WorkflowActor::of($step->getActorType(), $step->getActorId()), $instance->getTeamId())
                        && $this->mayActOnClaimed($viewerUid, $step),
                    // Who has a team task: the team's own business.
                    'assignee'    => $isAgent && $step->getActorType() === WorkflowActor::TYPE_SERVICE_AGENT ? $taskAssignee : '',
                    'status'      => $step->getStepStatus(),
                    'label'       => $definition?->getStepLabel($this->l, $step->getStepKey()) ?? $step->getLabel(),
                    // v4.10.31 — "Needs: Functional admin"; '' for built-ins.
                    'roleLabel'   => $step->getRoleLabel(),
                    'links'       => $links,
                    'actor'       => ['type' => $step->getActorType(), 'id' => $step->getActorId()],
                    'enteredAt'   => $step->getEnteredAt(),
                    'startedAt'   => $step->getStartedAt(),
                    'completedAt' => $step->getCompletedAt(),
                    'completedBy' => $step->getCompletedBy(),
                    'actionTaken' => $step->getActionTaken(),
                    'reason'      => $step->getReason(),
                ];
            }
        }

        $participantViews = [];
        $uids = [$instance->getStartedBy(), $instance->getEndedBy()];
        foreach ($participants as $p) {
            if ($p->getRemovedAt() !== null) {
                continue;
            }
            $participantViews[] = [
                'actor'   => ['type' => $p->getActorType(), 'id' => $p->getActorId()],
                'role'    => $p->getWfRole(),
                'addedAt' => $p->getAddedAt(),
            ];
            if ($p->getActorType() === WorkflowActor::TYPE_USER) {
                $uids[] = $p->getActorId();
            }
        }
        foreach ($steps as $step) {
            if (!$showSteps && $step !== $active) {
                continue; // the basic tier names the responsible actor, nobody else on the steps
            }
            $uids[] = $step->getCompletedBy();
            if ($step->getActorType() === WorkflowActor::TYPE_USER) {
                $uids[] = $step->getActorId();
            }
        }

        return [
            'id'                => (int)$instance->getId(),
            'definitionKey'     => $instance->getDefinitionKey(),
            'definitionVersion' => $instance->getDefinitionVersion(),
            'title'             => $definition !== null ? $definition->getTitle($this->l, $data) : $instance->getDefinitionKey(),
            'description'       => $definition !== null ? $definition->getDescription($this->l, $data) : '',
            'teamId'            => $instance->getTeamId(),
            'teamName'          => $this->resolver->teamName($instance->getTeamId()),
            // v4.10.39 — asked from no team: recorded against the service team
            // handling it (TeamServiceDefinition), so "Requested from" has no team to name.
            'personal'          => $serviceTeamId !== '' && $instance->getTeamId() === $serviceTeamId,
            'subject'           => ['type' => $instance->getSubjectType(), 'id' => $instance->getSubjectId()],
            'status'            => $instance->getStatus(),
            'outcome'           => $instance->getOutcome(),
            'currentStep'       => $showSteps ? $instance->getCurrentStep() : null,
            'responsible'       => $open && $active !== null ? ['type' => $active->getActorType(), 'id' => $active->getActorId()] : null,
            'startedBy'         => $instance->getStartedBy(),
            'startedAt'         => $instance->getStartedAt(),
            'updatedAt'         => $instance->getUpdatedAt(),
            'endedBy'           => $instance->getEndedBy(),
            'endedAt'           => $instance->getEndedAt(),
            'data'              => $data,
            // v4.10.29 — the active step's own words for complete / reject
            // (*Grant* / *Decline*); empty when the engine's own apply.
            'actionLabels'      => $open && $active !== null && $definition instanceof IWorkflowDefinitionHooks
                ? $definition->getActionLabels($this->l, $active->getStepKey())
                : [],
            // v4.10.50 — where the work is done when it is not the row (the
            // adoption grid); a link in the detail view, or null.
            'reference'         => $open && $definition instanceof IWorkflowDefinitionReference
                ? $definition->getReference($this->l, $instance, $viewerUid)
                : null,
            'steps'             => $stepViews,
            'participants'      => $participantViews,
            'people'            => $this->describePeople(array_filter($uids, static fn ($u): bool => $u !== null && $u !== '')),
            'purged'            => false,
            // The requester's half of the workflow ends above. Everything
            // below is the service team's, and is present only for an
            // eligible agent of the team handling it (v4.10.20).
            'internal'          => $isAgent ? [
                'serviceTeamId'   => $serviceTeamId,
                'serviceTeamName' => $this->resolver->teamName($serviceTeamId),
                'assignee'        => $assignee,
                'assigneeName'    => $assignee !== '' ? $this->resolver->displayName($assignee) : '',
                'claimable'       => $open && $assignee === '' && $active !== null
                    && $active->getActorType() === WorkflowActor::TYPE_SERVICE_AGENT,
                // v4.10.27 — team admins only; see assignStep().
                'canAssign'       => $open && $active !== null
                    && $active->getActorType() === WorkflowActor::TYPE_SERVICE_AGENT
                    && $this->serviceTeams->isServiceOwner($viewerUid, $serviceTeamId),
                'canRelease'      => $open && $assignee !== ''
                    && ($assignee === $viewerUid || $this->serviceTeams->isServiceOwner($viewerUid, $serviceTeamId)),
                'isServiceOwner'  => $this->serviceTeams->isServiceOwner($viewerUid, $serviceTeamId),
                // v4.10.31 — this member claimed or worked a step of it:
                // the widget's *My tasks* tab keeps it while it is back
                // with the requester.
                'involved'        => $this->workedOn($viewerUid, $steps),
                'agents'          => $this->describePeople($this->serviceTeams->eligibleAgents($serviceTeamId)),
            ] : null,
            'viewer'            => [
                // v4.10.37 — the task this view answers for; the client
                // sends it back with every verb, so an action lands on the
                // task the row showed.
                'stepKey'          => $active?->getStepKey(),
                'isParticipant'    => $isParticipant,
                'isResponsible'    => $open && $holds,
                'canAct'           => $canAct,
                'canCancel'        => $open && ($instance->getStartedBy() === $viewerUid || $isAdmin),
                // v4.10.31 — see mayCloseForDesk() and closeRequest().
                'canClose'         => $canClose,
                // v4.10.31 — this viewer's action is required (see above).
                'actionRequired'   => $actionRequired,
                'canRequestStatus' => $canAskStatus && $open && $isParticipant && !$holds,
                // v4.10.20 - "I am on the desk that handles this", which is
                // what decides whether the internal half is shown at all.
                'isServiceAgent'   => $isAgent,
                // v4.11.0 — see postMessage(). `messageSide` is 'requester',
                // 'desk' or null.
                'canMessage'       => $messageSide !== null,
                'messageSide'      => $messageSide,
                // The desk may make its message a question the request waits
                // for (requestInformation): its own open desk task, claimed.
                'canAskRequester'  => $messageSide === 'desk' && $canAct && $active !== null
                    && $active->getActorType() === WorkflowActor::TYPE_SERVICE_AGENT
                    && $active->getStepStatus() !== WorkflowStepStatus::WAITING_FOR_INFORMATION,
                // The requester's next message answers the desk's question
                // (provideInformation) rather than being a plain message.
                // Not while blocked: provideInformation() refuses then, and a
                // plain message is what the requester can still send.
                'answersQuestion'  => $messageSide === 'requester' && $awaitingAnswer
                    && $instance->getStatus() !== WorkflowStatus::BLOCKED,
            ],
        ];
    }
}
