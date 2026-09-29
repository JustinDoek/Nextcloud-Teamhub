<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Workflow;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\WorkflowArchive;
use OCA\TeamHub\Db\WorkflowArchiveMapper;
use OCA\TeamHub\Db\WorkflowArchiveView;
use OCA\TeamHub\Db\WorkflowArchiveViewMapper;
use OCA\TeamHub\Db\WorkflowAttachment;
use OCA\TeamHub\Db\WorkflowAttachmentMapper;
use OCA\TeamHub\Db\WorkflowInstance;
use OCA\TeamHub\Db\WorkflowInstanceMapper;
use OCA\TeamHub\Db\WorkflowParticipantMapper;
use OCA\TeamHub\Db\WorkflowStep;
use OCA\TeamHub\Db\WorkflowStepMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowArchiveAudience;
use OCA\TeamHub\Workflow\WorkflowAttachmentVisibility;
use OCA\TeamHub\Workflow\WorkflowCapability;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCA\TeamHub\Workflow\WorkflowEventType;
use OCA\TeamHub\Workflow\WorkflowStatus;
use OCA\TeamHub\Workflow\WorkflowStepStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Licensed workflow archiving (WorkflowHub phase 6, v4.10.21;
 * `docs/workflow-archiving.md`).
 *
 * ## One record, two readings
 *
 * When a workflow ends on a **licensed** instance, this class writes one
 * `teamhub_wf_archive` row — the authoritative completed workflow record —
 * and one `teamhub_wf_archive_view` row per audience. That is the whole of
 * product rules 3 to 5:
 *
 *   - the record is **one row over the workflow that already exists**. It
 *     copies no step, no event, no note and no document; it points at the
 *     instance, and reading an archive reads the workflow's own rows;
 *   - a projection is an **index entry, not a copy**: which team may read
 *     which record as which audience, plus the keys a search filters on;
 *   - so the requesting team and the Service Team read *the same record*
 *     through two different filters, and there is no second copy that can
 *     drift, contradict or outlive the first.
 *
 * ## What each audience reads
 *
 * The filter is a property of the **audience**, not of the viewer. The
 * requesting-team projection drops every internal event and every internal
 * document before it knows who is asking, so there is no role, no licence
 * and no administrator flag that can widen it. The service-team projection
 * adds the internal half, and only an eligible agent of the handling desk
 * may ask for it — the same rule that governs a live internal note
 * (v4.10.20), and for the same reason: administering a server is not
 * working a service desk.
 *
 * ## The history is immutable, and it can be checked
 *
 * `teamhub_wf_event` has no update path and the engine refuses every write
 * to an ended workflow, so the history of an archived workflow cannot
 * change. Phase 6 makes that provable rather than merely true: the record
 * carries `event_count` and `event_seal`, a SHA-256 chain over the log as
 * it stood at the moment of archival. `verifySeal()` recomputes it, and
 * every read of the authoritative record reports the answer.
 *
 * ## The unlicensed path is untouched
 *
 * Nothing in this class runs on an unlicensed instance. `record()` is
 * called by `WorkflowEngine::finish()` only on the branch that already
 * decided the ending is retained; the other branch still purges inside the
 * ending transaction, exactly as phase 4 wrote it
 * (`docs/unlicensed-workflow-data-lifecycle.md`). A licence that lapses
 * *while* a workflow runs means the workflow is purged when it ends, and
 * `removeForInstance()` is how the purge takes any archive with it.
 */
class WorkflowArchiveService {

    /**
     * The Circles level from which a team role — not participation — is
     * enough to read the team's whole archive. 8 is team admin; 9 is the
     * owner. A moderator (4) is not enough: moderating a team's content is
     * not the same as reading what the team has asked the organisation for.
     */
    public const TEAM_ARCHIVE_LEVEL = 8;

    /** Why this viewer may read this archive. Reported on every projection. */
    public const ROLE_REQUESTER     = 'requester';
    public const ROLE_PARTICIPANT   = 'participant';
    public const ROLE_TEAM_ADMIN    = 'team_admin';
    public const ROLE_NC_ADMIN      = 'nc_admin';
    public const ROLE_SERVICE_AGENT = 'service_agent';
    public const ROLE_SERVICE_OWNER = 'service_owner';

    /** How a completed workflow ended, in the words the requester reads. */
    public const DECISION_APPROVED  = 'approved';
    public const DECISION_REJECTED  = 'rejected';
    public const DECISION_WITHDRAWN = 'withdrawn';

    public function __construct(
        private WorkflowArchiveMapper     $archives,
        private WorkflowArchiveViewMapper $projections,
        private WorkflowInstanceMapper    $instances,
        private WorkflowStepMapper        $steps,
        private WorkflowParticipantMapper $participants,
        private WorkflowAttachmentMapper  $attachments,
        private WorkflowEventService      $events,
        private WorkflowActorResolver     $resolver,
        private WorkflowDefinitionRegistry $registry,
        private WorkflowLicenceTier       $tier,
        private WorkflowConfigService      $config,
        private ServiceTeamService        $serviceTeams,
        private AuditService              $auditService,
        private ITimeFactory              $timeFactory,
        private IL10N                     $l,
        private LoggerInterface           $logger,
    ) {
    }

    // ──────────────────────────────────────────────────────────────────────
    // Writing the record — the one write, inside the ending transaction
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Archive a workflow that has just ended.
     *
     * Called by `WorkflowEngine::finish()` from inside the transaction that
     * ended the workflow, and only on the licensed branch. Everything here
     * is therefore part of the ending: if the archive cannot be written the
     * completion rolls back with it, and a workflow is never quietly
     * completed without its record.
     *
     * Idempotent. A second call for the same instance returns the record
     * that is already there rather than writing a second one — the unique
     * index would refuse it anyway, and the product rule is that there is
     * one record.
     *
     * @param WorkflowStep[] $steps
     * @param string $serviceTeamId the desk that handled it, '' when none did
     */
    public function record(WorkflowInstance $instance, array $steps, string $serviceTeamId): WorkflowArchive {
        $instanceId = (int)$instance->getId();
        $existing   = $this->archives->findByInstance($instanceId);
        if ($existing !== null) {
            return $existing;
        }

        $now         = $this->timeFactory->getTime();
        $completedAt = $instance->getEndedAt() ?? $now;
        $reference   = $this->referenceFor($instanceId, $completedAt);

        // The archived event is written first, so the seal covers it: the
        // log is sealed complete, including the fact that it was sealed.
        $this->events->record($instanceId, WorkflowEventType::ARCHIVED, null, null, [
            'reference' => $reference,
        ]);
        $log = $this->events->listForInstance($instanceId);

        $archive = new WorkflowArchive();
        $archive->setInstanceId($instanceId);
        $archive->setRefNumber($reference);
        $archive->setDefinitionKey($instance->getDefinitionKey());
        $archive->setDefinitionVersion($instance->getDefinitionVersion());
        $archive->setTeamId($instance->getTeamId());
        $archive->setServiceTeamId($serviceTeamId);
        $archive->setSubjectType($instance->getSubjectType());
        $archive->setSubjectId($instance->getSubjectId());
        $archive->setWfStatus($instance->getStatus());
        $archive->setOutcome((string)$instance->getOutcome());
        $archive->setStartedBy($instance->getStartedBy());
        $archive->setStartedAt($instance->getStartedAt());
        $archive->setCompletedBy((string)$instance->getEndedBy());
        $archive->setCompletedAt($completedAt);
        $archive->setArchivedAt($now);
        $archive->setEventCount(count($log));
        $archive->setEventSeal($this->sealOf($log));
        [$retentionUntil, $policy] = $this->retentionFor($completedAt);
        $archive->setRetentionUntil($retentionUntil);
        $archive->setRetentionPolicy($policy);
        $archive->setLegalHold(0);
        $archive = $this->archives->insert($archive);

        $this->writeProjections($archive, $instance);

        $this->auditService->log(
            $instance->getTeamId(),
            'workflow.' . $instance->getDefinitionKey() . '.archived',
            null,
            'workflow_archive',
            (string)$archive->getId(),
            ['reference' => $reference, 'instance' => $instanceId, 'outcome' => (string)$instance->getOutcome()],
        );
        return $archive;
    }

    /**
     * The projections of one record: the requesting team always, the
     * service team when one handled it.
     *
     * A projection row carries filter keys and a search haystack built from
     * the requester's own words — the title and the description the
     * definition renders from the instance's data. Nothing internal reaches
     * it: a haystack is the one place where a substring of something
     * invisible could be confirmed to exist, and both projections therefore
     * share the same one.
     */
    private function writeProjections(WorkflowArchive $archive, WorkflowInstance $instance): void {
        $definition = $this->registry->get($instance->getDefinitionKey());
        $data       = $instance->getData();
        $haystack   = mb_strtolower(trim(implode(' ', array_filter([
            $archive->getRefNumber(),
            $definition?->getTitle($this->l, $data) ?? $instance->getDefinitionKey(),
            $definition?->getDescription($this->l, $data) ?? '',
        ]))));
        $haystack   = mb_substr($haystack, 0, 2000);
        $serviceKey = (string)($data['serviceKey'] ?? '');

        $audiences = [[WorkflowArchiveAudience::REQUESTING_TEAM, $instance->getTeamId()]];
        if ($archive->getServiceTeamId() !== '') {
            $audiences[] = [WorkflowArchiveAudience::SERVICE_TEAM, $archive->getServiceTeamId()];
        }
        foreach ($audiences as [$audience, $teamId]) {
            if ($teamId === '') {
                continue;
            }
            $row = new WorkflowArchiveView();
            $row->setArchiveId((int)$archive->getId());
            $row->setAudience($audience);
            $row->setTeamId($teamId);
            $row->setRefNumber($archive->getRefNumber());
            $row->setDefinitionKey($archive->getDefinitionKey());
            $row->setServiceKey($serviceKey);
            $row->setOutcome($archive->getOutcome());
            $row->setCompletedAt($archive->getCompletedAt());
            $row->setSearchText($haystack);
            $this->projections->insert($row);
        }
    }

    /**
     * `WF-2026-000042` — the year it was completed in, and the instance id
     * that already is unique. Derived rather than drawn from a counter: a
     * counter is a second source of truth about how many workflows there
     * are, and a gap in it looks like a deleted record.
     */
    private function referenceFor(int $instanceId, int $completedAt): string {
        $year = gmdate('Y', $completedAt);
        return sprintf('WF-%s-%06d', $year, $instanceId);
    }

    /**
     * The retention window this record is born with.
     *
     * Metadata only in this phase: the columns are written and reported,
     * and **nothing deletes on them**. The pass that would is deferred, on
     * purpose — see `docs/workflow-archiving.md` § Deferred.
     *
     * @return array{0: int, 1: string} (retention_until, policy)
     */
    private function retentionFor(int $completedAt): array {
        $days = $this->config->getArchiveRetentionDays();
        if ($days <= 0) {
            return [0, WorkflowConfigService::RETENTION_KEEP];
        }
        return [$completedAt + $days * 86400, 'days:' . $days];
    }

    /**
     * A SHA-256 chain over the event log, oldest first.
     *
     * Chained rather than a hash of the whole: a chain says *where* the log
     * diverges, and appending to a sealed log changes the final digest even
     * when every existing row is untouched. Every field an event carries is
     * in the chain, visibility included — a note that was internal must not
     * become readable by editing one column without the seal noticing.
     *
     * @param array<int, array<string, mixed>> $log
     */
    private function sealOf(array $log): string {
        $chain = '';
        foreach ($log as $event) {
            $chain = hash('sha256', implode('|', [
                $chain,
                (string)($event['id'] ?? ''),
                (string)($event['type'] ?? ''),
                (string)($event['actorUid'] ?? ''),
                (string)($event['occurredAt'] ?? ''),
                (string)($event['stepKey'] ?? ''),
                (string)($event['visibility'] ?? ''),
                json_encode($event['payload'] ?? [], JSON_UNESCAPED_UNICODE) ?: '',
            ]));
        }
        return $chain === '' ? hash('sha256', '') : $chain;
    }

    /**
     * Recompute the seal and compare. False means the event log of this
     * archived workflow is not the log that was sealed — rows added,
     * removed or changed since.
     */
    public function verifySeal(WorkflowArchive $archive): bool {
        $log = $this->events->listForInstance($archive->getInstanceId());
        return count($log) === $archive->getEventCount()
            && hash_equals($archive->getEventSeal(), $this->sealOf($log));
    }

    /**
     * Remove an archive and its projections. Part of
     * `WorkflowEngine::purge()`: a licence that lapsed while the workflow
     * ran takes the record with the workflow, because a record pointing at
     * rows that no longer exist is worse than no record.
     */
    public function removeForInstance(int $instanceId): void {
        $archive = $this->archives->findByInstance($instanceId);
        if ($archive === null) {
            return;
        }
        $this->projections->deleteByArchive((int)$archive->getId());
        $this->archives->deleteByInstance($instanceId);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Reading — every path through assertMayRead()
    // ──────────────────────────────────────────────────────────────────────

    /**
     * One archive projection, for one audience, for one viewer.
     *
     * @return array<string, mixed>
     * @throws NotFoundException the archive does not exist, or this instance is unlicensed
     * @throws AccessDeniedException not this viewer's to read as this audience
     * @throws ValidationException unknown audience
     */
    public function get(int $archiveId, string $uid, string $audience = WorkflowArchiveAudience::REQUESTING_TEAM): array {
        $this->requireLicence();
        $archive = $this->archives->findById($archiveId);
        if ($archive === null) {
            throw new NotFoundException($this->l->t('Archived workflow not found.'));
        }
        $role = $this->assertMayRead($uid, $archive, $audience);
        return $this->project($archive, $audience, $uid, $role);
    }

    /**
     * The same, addressed by the workflow rather than by the record — what
     * a link from a finished workflow in My Work resolves.
     *
     * @return array<string, mixed>
     */
    public function getByInstance(int $instanceId, string $uid, string $audience = WorkflowArchiveAudience::REQUESTING_TEAM): array {
        $this->requireLicence();
        $archive = $this->archives->findByInstance($instanceId);
        if ($archive === null) {
            throw new NotFoundException($this->l->t('Archived workflow not found.'));
        }
        $role = $this->assertMayRead($uid, $archive, $audience);
        return $this->project($archive, $audience, $uid, $role);
    }

    /**
     * **The link from a projection to the authoritative record.**
     *
     * A projection is a reading; this is what it is a reading *of*. It
     * answers with the record's own identity — the instance it archives,
     * the definition and the version it was created on, the reference — and
     * with the integrity of the sealed history, verified on the spot. The
     * history itself comes back filtered for the audience that asked, for
     * the same reason the projection is: a link to the record is not a way
     * around the filter.
     *
     * @return array<string, mixed>
     */
    public function authoritativeRecord(int $archiveId, string $uid, string $audience = WorkflowArchiveAudience::REQUESTING_TEAM): array {
        $this->requireLicence();
        $archive = $this->archives->findById($archiveId);
        if ($archive === null) {
            throw new NotFoundException($this->l->t('Archived workflow not found.'));
        }
        $role     = $this->assertMayRead($uid, $archive, $audience);
        $instance = $this->instances->findById($archive->getInstanceId());
        if ($instance === null) {
            // The record outlived its workflow: impossible through any code
            // path here, and worth a log line rather than a silent empty.
            $this->logger->error('[TeamHub][WorkflowArchive] archive without an instance', [
                'archive' => $archive->getId(), 'instance' => $archive->getInstanceId(), 'app' => Application::APP_ID,
            ]);
            throw new NotFoundException($this->l->t('Archived workflow not found.'));
        }

        return [
            'archiveId'         => (int)$archive->getId(),
            'reference'         => $archive->getRefNumber(),
            'audience'          => $audience,
            'viewerRole'        => $role,
            'authoritative'     => [
                'instanceId'        => $archive->getInstanceId(),
                'definitionKey'     => $archive->getDefinitionKey(),
                // The version the workflow was *created* on, carried from
                // the instance. A definition that has moved on since does
                // not move this record with it.
                'definitionVersion' => $archive->getDefinitionVersion(),
                'teamId'            => $archive->getTeamId(),
                'serviceTeamId'     => $archive->getServiceTeamId(),
                'subject'           => ['type' => $archive->getSubjectType(), 'id' => $archive->getSubjectId()],
                'status'            => $archive->getWfStatus(),
                'outcome'           => $archive->getOutcome(),
                'startedAt'         => $archive->getStartedAt(),
                'completedAt'       => $archive->getCompletedAt(),
                'archivedAt'        => $archive->getArchivedAt(),
            ],
            'integrity'         => [
                'eventCount' => $archive->getEventCount(),
                'sealed'     => $archive->getEventSeal() !== '',
                'verified'   => $this->verifySeal($archive),
            ],
            'history'           => $this->historyFor($archive->getInstanceId(), $audience),
            'retention'         => $this->retentionOf($archive),
        ];
    }

    /**
     * Search and filter the archives this viewer may read.
     *
     * The scope is resolved from **live roles** before a row is touched:
     * the teams the viewer is in now, and the desks they are an agent of
     * now. A membership that ended is not in it, and neither is a desk
     * somebody was taken off — which is what makes "permission changes
     * after completion" a property of the read rather than of the record.
     *
     * The per-archive rule then runs over the page that comes back, because
     * being in a team is not by itself a reason to read every request that
     * team ever made.
     *
     * @param array<string, mixed> $filters q, outcome, definitionKey, serviceKey, audience, from, to, limit, offset
     * @return array<string, mixed>
     */
    public function search(string $uid, array $filters = []): array {
        $this->requireLicence();
        $audience = trim((string)($filters['audience'] ?? ''));
        if ($audience !== '' && !WorkflowArchiveAudience::isValid($audience)) {
            throw new ValidationException($this->l->t('That is not an archive view.'));
        }

        $scope = $this->scopeFor($uid, $audience !== '' ? [$audience] : WorkflowArchiveAudience::ALL);
        $rows  = $this->projections->search($scope, $filters);
        if ($rows === []) {
            return ['results' => [], 'scope' => $this->describeScope($scope)];
        }

        $archives = $this->archives->findByIds(array_map(
            static fn (WorkflowArchiveView $r): int => $r->getArchiveId(),
            $rows,
        ));

        // One read for the whole page rather than one per row: a title is
        // rendered from the instance's data, and a page of 100 rendered one
        // at a time would be 100 queries for one screen.
        $instances = [];
        foreach ($this->instances->findByIds(array_map(
            static fn (WorkflowArchive $a): int => $a->getInstanceId(),
            $archives,
        )) as $instance) {
            $instances[(int)$instance->getId()] = $instance;
        }

        $results = [];
        foreach ($rows as $row) {
            $archive = $archives[$row->getArchiveId()] ?? null;
            if ($archive === null) {
                continue;
            }
            $role = $this->roleFor($uid, $archive, $row->getAudience());
            if ($role === null) {
                // In scope by team, not in scope by role — a member who was
                // never part of this request and does not administer the
                // team. Skipped silently: a count that changed with the
                // viewer's role would be a disclosure of its own.
                continue;
            }
            $results[] = $this->summarise($archive, $row, $role, $instances[$archive->getInstanceId()] ?? null);
        }
        return ['results' => $results, 'scope' => $this->describeScope($scope)];
    }

    /**
     * The audiences this viewer may read one archive as — what a client
     * needs to decide whether to offer the internal view at all.
     *
     * @return string[]
     */
    public function audiencesFor(string $uid, WorkflowArchive $archive): array {
        $out = [];
        foreach (WorkflowArchiveAudience::ALL as $audience) {
            if ($this->roleFor($uid, $archive, $audience) !== null) {
                $out[] = $audience;
            }
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Permission — the one rule, in one place
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @throws AccessDeniedException
     * @throws ValidationException
     */
    private function assertMayRead(string $uid, WorkflowArchive $archive, string $audience): string {
        if (!WorkflowArchiveAudience::isValid($audience)) {
            throw new ValidationException($this->l->t('That is not an archive view.'));
        }
        $role = $this->roleFor($uid, $archive, $audience);
        if ($role === null) {
            throw new AccessDeniedException($this->l->t('This archived workflow is not yours to read.'));
        }
        return $role;
    }

    /**
     * Why this person may read this archive as this audience, or null when
     * they may not. **Every read goes through here**, and it reads nothing
     * off the record: team level, participation and desk eligibility are
     * all asked of the live roles at the moment of the question.
     *
     * `service_team`: an eligible agent of the desk that handled it. Not a
     * Nextcloud administrator, not the requesting team's owner — the same
     * boundary a live internal note has.
     *
     * `requesting_team`: somebody who took part, a team administrator of
     * the requesting team, or a Nextcloud administrator. A plain member who
     * was never part of the request does not read it: a team's archive is
     * not a team's noticeboard.
     *
     * Every branch except the Nextcloud administrator's also requires
     * **current** membership of the requesting team. Somebody removed from
     * the team stops reading its archive, including the requests they made
     * themselves — the same rule the live workflow applies, and the reason
     * a deleted membership needs no clean-up pass to be safe.
     */
    private function roleFor(string $uid, WorkflowArchive $archive, string $audience): ?string {
        if ($uid === '' || !WorkflowArchiveAudience::isValid($audience)) {
            return null;
        }

        if ($audience === WorkflowArchiveAudience::SERVICE_TEAM) {
            $desk = $archive->getServiceTeamId();
            if ($desk === '') {
                return null;
            }
            if ($this->serviceTeams->isServiceOwner($uid, $desk)) {
                return self::ROLE_SERVICE_OWNER;
            }
            return $this->serviceTeams->isEligibleAgent($uid, $desk) ? self::ROLE_SERVICE_AGENT : null;
        }

        if ($this->resolver->isNextcloudAdmin($uid)) {
            return self::ROLE_NC_ADMIN;
        }
        $teamId = $archive->getTeamId();
        if ($teamId === '' || !$this->resolver->isEffectiveMember($uid, $teamId)) {
            return null;
        }
        if ($this->resolver->memberLevel($uid, $teamId) >= self::TEAM_ARCHIVE_LEVEL) {
            return self::ROLE_TEAM_ADMIN;
        }
        if ($archive->getStartedBy() === $uid) {
            return self::ROLE_REQUESTER;
        }
        $participants = $this->participants->findByInstance($archive->getInstanceId());
        return $this->resolver->isAmongParticipants($uid, $participants, $teamId)
            ? self::ROLE_PARTICIPANT
            : null;
    }

    /**
     * The (audience, team) pairs a search may look at — the viewer's teams
     * for the requesting-team archive, the desks they work for the service
     * archive.
     *
     * @param string[] $audiences
     * @return array<int, array{0: string, 1: string}>
     */
    private function scopeFor(string $uid, array $audiences): array {
        $scope = [];
        if (in_array(WorkflowArchiveAudience::REQUESTING_TEAM, $audiences, true)) {
            foreach (array_keys($this->resolver->teamsOf($uid)) as $teamId) {
                $scope[] = [WorkflowArchiveAudience::REQUESTING_TEAM, (string)$teamId];
            }
        }
        if (in_array(WorkflowArchiveAudience::SERVICE_TEAM, $audiences, true)) {
            foreach ($this->serviceTeams->serviceTeamsForAgent($uid) as $deskId) {
                $scope[] = [WorkflowArchiveAudience::SERVICE_TEAM, $deskId];
            }
        }
        return $scope;
    }

    /**
     * @param array<int, array{0: string, 1: string}> $scope
     * @return array<int, array{audience: string, teamId: string, teamName: string}>
     */
    private function describeScope(array $scope): array {
        $out = [];
        foreach ($scope as [$audience, $teamId]) {
            $out[] = ['audience' => $audience, 'teamId' => $teamId, 'teamName' => $this->resolver->teamName($teamId)];
        }
        return $out;
    }

    /** @throws \OCA\TeamHub\Exception\LicenseGateException */
    private function requireLicence(): void {
        $this->tier->require(
            WorkflowCapability::ARCHIVE_RESULTS,
            'The workflow archive requires an active TeamHub licence.',
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Rendering — the two projections
    // ──────────────────────────────────────────────────────────────────────

    /**
     * One row of a search result: enough to list and open it, nothing more.
     *
     * @return array<string, mixed>
     */
    private function summarise(
        WorkflowArchive     $archive,
        WorkflowArchiveView $row,
        string              $role,
        ?WorkflowInstance   $instance,
    ): array {
        $definition = $this->registry->get($archive->getDefinitionKey());
        $data       = $instance?->getData() ?? [];
        return [
            'archiveId'   => (int)$archive->getId(),
            'reference'   => $archive->getRefNumber(),
            'audience'    => $row->getAudience(),
            'viewerRole'  => $role,
            'title'       => $definition !== null ? $definition->getTitle($this->l, $data) : $archive->getDefinitionKey(),
            'teamId'      => $archive->getTeamId(),
            'teamName'    => $this->resolver->teamName($archive->getTeamId()),
            'outcome'     => $archive->getOutcome(),
            'decision'    => $this->decisionOf($archive->getWfStatus()),
            'completedAt' => $archive->getCompletedAt(),
            'serviceKey'  => $row->getServiceKey(),
            'link'        => $this->linkOf($archive),
        ];
    }

    /**
     * The projection itself.
     *
     * The requesting-team half is built first and is the whole answer for
     * that audience. The service-team half is the same object plus
     * `internal`, so a desk reads exactly what the requester reads and then
     * some — the two can never describe the request differently, because
     * there is only one description.
     *
     * @return array<string, mixed>
     */
    private function project(WorkflowArchive $archive, string $audience, string $viewerUid, string $role): array {
        $instance = $this->instances->findById($archive->getInstanceId());
        if ($instance === null) {
            throw new NotFoundException($this->l->t('Archived workflow not found.'));
        }
        $steps       = $this->steps->findByInstance($archive->getInstanceId());
        $documents   = $this->attachments->findByInstance($archive->getInstanceId());
        $definition  = $this->registry->get($archive->getDefinitionKey());
        $data        = $instance->getData();
        $history     = $this->historyFor($archive->getInstanceId(), $audience);

        $view = [
            'archiveId'   => (int)$archive->getId(),
            'reference'   => $archive->getRefNumber(),
            'audience'    => $audience,
            'viewerRole'  => $role,
            'request'     => [
                'title'       => $definition !== null ? $definition->getTitle($this->l, $data) : $archive->getDefinitionKey(),
                'summary'     => $definition !== null ? $definition->getDescription($this->l, $data) : '',
                'teamId'      => $archive->getTeamId(),
                'teamName'    => $this->resolver->teamName($archive->getTeamId()),
                'subject'     => ['type' => $archive->getSubjectType(), 'id' => $archive->getSubjectId()],
                'submittedBy' => $archive->getStartedBy(),
                'submittedAt' => $archive->getStartedAt(),
                'serviceKey'  => (string)($data['serviceKey'] ?? ''),
            ],
            'outcome'     => [
                'status'      => $archive->getWfStatus(),
                'outcome'     => $archive->getOutcome(),
                // "Approval or rejection", in the one word a requester reads.
                'decision'    => $this->decisionOf($archive->getWfStatus()),
                'completedAt' => $archive->getCompletedAt(),
                'completedBy' => $archive->getCompletedBy(),
                'closingNote' => $this->closingNoteOf($steps),
            ],
            'documents'   => $this->documentsFor($documents, $audience),
            'followUp'    => $this->followUpOf($steps, $history),
            'history'     => $history,
            'retention'   => $this->retentionOf($archive),
            'link'        => $this->linkOf($archive),
            'internal'    => null,
        ];
        $view['people'] = $this->peopleOf($archive, $steps, $history, $documents, $audience);

        if ($audience === WorkflowArchiveAudience::SERVICE_TEAM) {
            $view['internal'] = $this->internalHalf($archive, $steps, $documents, $viewerUid);
        }
        return $view;
    }

    /**
     * The service team's half: what the desk did, and how.
     *
     * Present only on the `service_team` projection, and `null` rather than
     * empty on the other — an absent key says "this view has no internal
     * half", where an empty one would say "the desk did nothing".
     *
     * @param WorkflowStep[]       $steps
     * @param WorkflowAttachment[] $documents
     * @return array<string, mixed>
     */
    private function internalHalf(WorkflowArchive $archive, array $steps, array $documents, string $viewerUid): array {
        $desk = $archive->getServiceTeamId();
        // The unfiltered log: this half is only ever built for an audience
        // that has already been proven to be the desk.
        $log  = $this->events->listForInstance($archive->getInstanceId());

        $notes = $escalations = $technical = [];
        foreach ($log as $event) {
            switch ($event['type']) {
                case WorkflowEventType::INTERNAL_NOTE:
                    $notes[] = [
                        'at'   => $event['occurredAt'],
                        'by'   => $event['actorUid'],
                        'note' => (string)($event['payload']['note'] ?? ''),
                    ];
                    break;
                case WorkflowEventType::BLOCKED:
                case WorkflowEventType::UNBLOCKED:
                    $escalations[] = [
                        'at'     => $event['occurredAt'],
                        'by'     => $event['actorUid'],
                        'kind'   => $event['type'],
                        'reason' => (string)($event['payload']['reason'] ?? ''),
                    ];
                    break;
                case WorkflowEventType::STEP_CLAIMED:
                case WorkflowEventType::STEP_ASSIGNED:
                case WorkflowEventType::STEP_RELEASED:
                    $technical[] = [
                        'at'     => $event['occurredAt'],
                        'by'     => $event['actorUid'],
                        'kind'   => $event['type'],
                        'to'     => (string)($event['payload']['to'] ?? ''),
                        'from'   => (string)($event['payload']['from'] ?? ''),
                        'reason' => (string)($event['payload']['reason'] ?? ''),
                    ];
                    break;
            }
        }

        return [
            'serviceTeamId'   => $desk,
            'serviceTeamName' => $this->resolver->teamName($desk),
            'isServiceOwner'  => $this->serviceTeams->isServiceOwner($viewerUid, $desk),
            'assignedAgents'  => $this->assignedAgentsOf($steps, $technical),
            'processingSteps' => $this->processingStepsOf($steps),
            'internalNotes'   => $notes,
            'escalations'     => $escalations,
            'technicalActions'=> $technical,
            'operationalOutcome' => $this->operationalOutcomeOf($archive, $steps, $technical),
            'documents'       => $this->documentsFor($documents, WorkflowArchiveAudience::SERVICE_TEAM),
        ];
    }

    /**
     * Everybody who held the request at some point: the agent a step was
     * assigned to when it closed, and everybody a technical action named
     * along the way.
     *
     * @param WorkflowStep[] $steps
     * @param array<int, array<string, mixed>> $technical
     * @return string[]
     */
    private function assignedAgentsOf(array $steps, array $technical): array {
        $seen = [];
        foreach ($steps as $step) {
            if ($step->getActorType() !== WorkflowActor::TYPE_SERVICE_AGENT) {
                continue;
            }
            foreach ([$step->getAssignee(), (string)$step->getCompletedBy()] as $uid) {
                if ($uid !== '') {
                    $seen[$uid] = true;
                }
            }
        }
        foreach ($technical as $action) {
            foreach ([$action['by'], $action['to'], $action['from']] as $uid) {
                if ((string)$uid !== '') {
                    $seen[(string)$uid] = true;
                }
            }
        }
        return array_keys($seen);
    }

    /**
     * The steps as the desk reads them — with the timings that say where
     * the time went, which is the half a requester is not shown.
     *
     * @param WorkflowStep[] $steps
     * @return array<int, array<string, mixed>>
     */
    private function processingStepsOf(array $steps): array {
        $out = [];
        foreach ($steps as $step) {
            $definitionLabel = $step->getLabel();
            $out[] = [
                'key'         => $step->getStepKey(),
                'order'       => $step->getStepOrder(),
                'label'       => $definitionLabel,
                'status'      => $step->getStepStatus(),
                'actor'       => ['type' => $step->getActorType(), 'id' => $step->getActorId()],
                'assignee'    => $step->getAssignee(),
                'enteredAt'   => $step->getEnteredAt(),
                'startedAt'   => $step->getStartedAt(),
                'completedAt' => $step->getCompletedAt(),
                'completedBy' => $step->getCompletedBy(),
                'actionTaken' => $step->getActionTaken(),
                'note'        => $step->getReason(),
            ];
        }
        return $out;
    }

    /**
     * How the desk's work went: how long the request sat, how long it was
     * worked on, how often it changed hands.
     *
     * @param WorkflowStep[] $steps
     * @param array<int, array<string, mixed>> $technical
     * @return array<string, mixed>
     */
    private function operationalOutcomeOf(WorkflowArchive $archive, array $steps, array $technical): array {
        $handled = null;
        foreach ($steps as $step) {
            if ($step->getActorType() === WorkflowActor::TYPE_SERVICE_AGENT) {
                $handled = $step;
                break;
            }
        }
        $waited = $worked = null;
        if ($handled !== null) {
            $entered = $handled->getEnteredAt();
            $started = $handled->getStartedAt();
            $closed  = $handled->getCompletedAt();
            if ($entered !== null && $started !== null) {
                $waited = max(0, $started - $entered);
            }
            if ($started !== null && $closed !== null) {
                $worked = max(0, $closed - $started);
            }
        }
        $counts = array_count_values(array_map(
            static fn (array $a): string => (string)$a['kind'],
            $technical,
        ));
        return [
            'handledBy'        => $handled?->getCompletedBy(),
            'handlingStepKey'  => $handled?->getStepKey(),
            'secondsInQueue'   => $waited,
            'secondsInProgress'=> $worked,
            'totalSeconds'     => max(0, $archive->getCompletedAt() - $archive->getStartedAt()),
            'claims'           => $counts[WorkflowEventType::STEP_CLAIMED] ?? 0,
            'reassignments'    => $counts[WorkflowEventType::STEP_ASSIGNED] ?? 0,
            'releases'         => $counts[WorkflowEventType::STEP_RELEASED] ?? 0,
        ];
    }

    /**
     * The history, filtered for the audience.
     *
     * **The requesting-team filter does not ask who is reading.** It drops
     * every event marked internal, always — so an internal note cannot
     * reach that projection through a viewer who happens to also be an
     * agent, an administrator, or both. The service-team projection gets
     * the log whole, and the audience it belongs to has already been proven.
     *
     * @return array<int, array<string, mixed>>
     */
    private function historyFor(int $instanceId, string $audience): array {
        $log = $this->events->listForInstance($instanceId);
        if ($audience === WorkflowArchiveAudience::SERVICE_TEAM) {
            return $log;
        }
        return array_values(array_filter(
            $log,
            static fn (array $e): bool => ($e['visibility'] ?? WorkflowEventType::VISIBILITY_ALL)
                !== WorkflowEventType::VISIBILITY_INTERNAL,
        ));
    }

    /**
     * The documents this audience may see.
     *
     * The requesting team reads the ones classified `requester`, and that
     * is decided by the classification on the row — never by the viewer, and
     * never by a default, because there is no default.
     *
     * @param WorkflowAttachment[] $documents
     * @return array<int, array<string, mixed>>
     */
    private function documentsFor(array $documents, string $audience): array {
        $out = [];
        foreach ($documents as $document) {
            if ($audience !== WorkflowArchiveAudience::SERVICE_TEAM
                && $document->getVisibility() !== WorkflowAttachmentVisibility::REQUESTER
            ) {
                continue;
            }
            $out[] = [
                'id'         => (int)$document->getId(),
                'fileId'     => $document->getFileId(),
                'fileName'   => $document->getFileName(),
                'visibility' => $document->getVisibility(),
                'stepKey'    => $document->getStepKey(),
                'addedBy'    => $document->getAddedBy(),
                'addedAt'    => $document->getAddedAt(),
            ];
        }
        return $out;
    }

    /**
     * What is still owed, after the workflow is over.
     *
     * Derived from the record, not stored beside it: the note the closing
     * actor left, the reason a rejection gave, and any question that was
     * asked of a participant and never answered. A workflow that ended
     * cleanly has an empty list, which is the honest answer.
     *
     * @param WorkflowStep[] $steps
     * @param array<int, array<string, mixed>> $history already filtered for the audience
     * @return array<int, array<string, mixed>>
     */
    private function followUpOf(array $steps, array $history): array {
        $out = [];
        foreach ($steps as $step) {
            $note = trim((string)$step->getReason());
            if ($note === '') {
                continue;
            }
            $kind = match ($step->getStepStatus()) {
                WorkflowStepStatus::REJECTED  => 'rejection_reason',
                WorkflowStepStatus::CANCELLED => 'cancellation_reason',
                WorkflowStepStatus::COMPLETED => 'closing_note',
                default                       => null,
            };
            if ($kind === null) {
                continue;
            }
            $out[] = [
                'kind'    => $kind,
                'stepKey' => $step->getStepKey(),
                'text'    => $note,
                'at'      => $step->getCompletedAt(),
                'by'      => $step->getCompletedBy(),
            ];
        }

        // A question asked and never answered is the one follow-up the step
        // rows cannot show: the step moved on without it.
        $open = [];
        foreach ($history as $event) {
            if ($event['type'] === WorkflowEventType::INFORMATION_REQUESTED) {
                $open[(string)$event['stepKey']] = $event;
            } elseif ($event['type'] === WorkflowEventType::INFORMATION_PROVIDED) {
                unset($open[(string)$event['stepKey']]);
            }
        }
        foreach ($open as $event) {
            $out[] = [
                'kind'    => 'unanswered_question',
                'stepKey' => $event['stepKey'],
                'text'    => (string)($event['payload']['note'] ?? ''),
                'at'      => $event['occurredAt'],
                'by'      => $event['actorUid'],
            ];
        }
        return $out;
    }

    /**
     * The note left on the step that closed the workflow — the answer, in
     * the closing actor's own words.
     *
     * @param WorkflowStep[] $steps
     */
    private function closingNoteOf(array $steps): ?string {
        $last = null;
        foreach ($steps as $step) {
            if ($step->getCompletedAt() === null) {
                continue;
            }
            if ($last === null || $step->getStepOrder() > $last->getStepOrder()) {
                $last = $step;
            }
        }
        $note = trim((string)$last?->getReason());
        return $note === '' ? null : $note;
    }

    /** completed → approved, rejected → rejected, cancelled → withdrawn. */
    private function decisionOf(string $status): string {
        return match ($status) {
            WorkflowStatus::COMPLETED => self::DECISION_APPROVED,
            WorkflowStatus::REJECTED  => self::DECISION_REJECTED,
            default                   => self::DECISION_WITHDRAWN,
        };
    }

    /** @return array<string, mixed> */
    private function retentionOf(WorkflowArchive $archive): array {
        return [
            'archivedAt'     => $archive->getArchivedAt(),
            'retentionUntil' => $archive->getRetentionUntil(),
            'policy'         => $archive->getRetentionPolicy(),
            'legalHold'      => $archive->isOnLegalHold(),
            // Said plainly so no client has to infer it from a zero: this
            // phase writes the metadata and deletes nothing on it.
            'enforced'       => false,
        ];
    }

    /** @return array<string, mixed> */
    private function linkOf(WorkflowArchive $archive): array {
        return [
            'archiveId'         => (int)$archive->getId(),
            'instanceId'        => $archive->getInstanceId(),
            'reference'         => $archive->getRefNumber(),
            'definitionKey'     => $archive->getDefinitionKey(),
            'definitionVersion' => $archive->getDefinitionVersion(),
        ];
    }

    /**
     * Display names for the uids this projection mentions — and only those.
     * The service-team projection resolves the agents it already named; the
     * requesting-team one never learns a name it was not going to show.
     *
     * @param WorkflowStep[]       $steps
     * @param array<int, array<string, mixed>> $history
     * @param WorkflowAttachment[] $documents
     * @return array<string, string>
     */
    private function peopleOf(WorkflowArchive $archive, array $steps, array $history, array $documents, string $audience): array {
        $uids = [$archive->getStartedBy(), $archive->getCompletedBy()];
        foreach ($history as $event) {
            $uids[] = (string)($event['actorUid'] ?? '');
        }
        $internal = $audience === WorkflowArchiveAudience::SERVICE_TEAM;
        foreach ($steps as $step) {
            $uids[] = (string)$step->getCompletedBy();
            if ($step->getActorType() === WorkflowActor::TYPE_USER) {
                $uids[] = $step->getActorId();
            }
            if ($internal) {
                $uids[] = $step->getAssignee();
            }
        }
        foreach ($documents as $document) {
            if ($internal || $document->getVisibility() === WorkflowAttachmentVisibility::REQUESTER) {
                $uids[] = $document->getAddedBy();
            }
        }

        $out = [];
        foreach (array_unique(array_filter($uids)) as $uid) {
            $out[$uid] = $this->resolver->displayName($uid);
        }
        return $out;
    }
}
