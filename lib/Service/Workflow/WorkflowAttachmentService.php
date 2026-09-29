<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Workflow;

use OCA\TeamHub\AppInfo\Application;
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
use OCA\TeamHub\Exception\WorkflowTransitionException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowAttachmentVisibility;
use OCA\TeamHub\Workflow\WorkflowCapability;
use OCA\TeamHub\Workflow\WorkflowEventType;
use OCA\TeamHub\Workflow\WorkflowStatus;
use OCA\TeamHub\Workflow\WorkflowStepStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Documents on a workflow (WorkflowHub phase 6, v4.10.21;
 * `docs/workflow-archiving.md` § Documents).
 *
 * The archive has to be able to show the requesting team "the documents you
 * were given" and the service team "everything, including our own working
 * material". That only means something if a document can say which of the
 * two it is, so phase 6 gives a workflow documents and gives every document
 * a **classification that is always stated**
 * (`WorkflowAttachmentVisibility`): there is no default value, no default
 * in the column, and `attach()` refuses a call that does not name one.
 *
 * ## A reference, not a copy
 *
 * A document is an `oc_filecache` id. The file stays in Nextcloud, with the
 * owner, the shares, the versions and the trash it already had, and this
 * table holds a pointer plus the one fact Nextcloud cannot hold — how far
 * inside *this workflow* the document may travel. Two consequences worth
 * stating:
 *
 *   - **attaching grants nothing.** Every read resolves the file id against
 *     the reader's own Nextcloud, so a document listed in a projection
 *     opens only for somebody who could have opened it anyway. The archive
 *     is a record of what was handed over, not a back door into a team
 *     folder;
 *   - **the name is a label.** `fileName` is the name at the moment of
 *     attaching, so a completed workflow can still say what was handed over
 *     after the file has been moved, renamed or deleted.
 *
 * ## Who may attach what
 *
 *   - `requester` — any participant of the workflow, and any eligible agent
 *     of the handling desk. This is the answer, and both sides contribute
 *     to it.
 *   - `internal` — an eligible agent of the handling desk, and nobody else.
 *     A requester cannot classify their own upload as internal; there would
 *     be nothing in it for them if they could, but "the requester decided
 *     what the desk keeps to itself" is not a sentence this feature should
 *     be able to produce.
 *
 * ## Only while the workflow runs
 *
 * Attaching and detaching need an **open** workflow. Once it has ended the
 * record is archived and immutable, documents included: a record whose
 * evidence could still be added to or taken away afterwards would not be
 * one. `WorkflowEngine::purge()` is the only thing that removes documents
 * from an ended workflow, and it removes the whole workflow with them.
 */
class WorkflowAttachmentService {

    /** Documents per workflow. A workflow is a request, not a file share. */
    public const MAX_PER_WORKFLOW = 50;

    public function __construct(
        private WorkflowAttachmentMapper  $attachments,
        private WorkflowInstanceMapper    $instances,
        private WorkflowStepMapper        $steps,
        private WorkflowParticipantMapper $participants,
        private WorkflowEventService      $events,
        private WorkflowActorResolver     $resolver,
        private WorkflowLicenceTier       $tier,
        private ServiceTeamService        $serviceTeams,
        private AuditService              $auditService,
        private IRootFolder               $rootFolder,
        private ITimeFactory              $timeFactory,
        private IL10N                     $l,
        private LoggerInterface           $logger,
    ) {
    }

    /**
     * Attach a document to an open workflow, with its classification.
     *
     * @throws \OCA\TeamHub\Exception\LicenseGateException unlicensed instance
     * @throws NotFoundException unknown workflow, or a file this caller cannot read
     * @throws AccessDeniedException not a participant, or not an agent for an internal document
     * @throws ValidationException no classification, an unknown one, or the cap is reached
     * @throws WorkflowTransitionException the workflow has ended
     */
    public function attach(
        int     $instanceId,
        string  $uid,
        int     $fileId,
        string  $visibility,
        ?string $stepKey = null,
    ): array {
        $this->requireLicence();
        if (!WorkflowAttachmentVisibility::isValid($visibility)) {
            // Including the empty string: a document with no stated
            // classification is not a document this feature stores.
            throw new ValidationException($this->l->t('Say who may see this document.'));
        }
        $instance = $this->requireOpen($instanceId);
        $steps    = $this->steps->findByInstance($instanceId);
        $isAgent  = $this->isHandlingAgent($uid, $steps);

        if ($visibility === WorkflowAttachmentVisibility::INTERNAL) {
            if (!$isAgent) {
                throw new AccessDeniedException(
                    $this->l->t('Only an agent of the service team handling this request can add an internal document.'));
            }
        } elseif (!$isAgent && !$this->isParticipant($uid, $instance)) {
            throw new AccessDeniedException($this->l->t('You are not part of this workflow.'));
        }

        $existing = $this->attachments->findByInstance($instanceId);
        if (count($existing) >= self::MAX_PER_WORKFLOW) {
            throw new ValidationException($this->l->t('This workflow already has the maximum number of documents.'));
        }
        if ($this->attachments->findByFile($instanceId, $fileId) !== null) {
            // Not an error worth a stack trace, but not silently a second
            // row either: two rows for one file could disagree about its
            // classification, which is the one thing this table must not
            // allow.
            throw new ValidationException($this->l->t('That document is already attached to this workflow.'));
        }

        $file = $this->readableFile($uid, $fileId);
        $row  = new WorkflowAttachment();
        $row->setInstanceId($instanceId);
        $row->setFileId($fileId);
        $row->setFileName(mb_substr($file->getName(), 0, 255));
        $row->setVisibility($visibility);
        $row->setStepKey($this->stepKeyOf($steps, $stepKey));
        $row->setAddedBy($uid);
        $row->setAddedAt($this->timeFactory->getTime());
        $row = $this->attachments->insert($row);

        // The event follows the document: an internal document's arrival is
        // itself internal, so the requester's history does not learn that a
        // file they may not read exists.
        $this->events->record(
            $instanceId,
            WorkflowEventType::ATTACHMENT_ADDED,
            $uid,
            null,
            ['fileId' => $fileId, 'fileName' => $row->getFileName(), 'visibility' => $visibility],
            $visibility === WorkflowAttachmentVisibility::INTERNAL
                ? WorkflowEventType::VISIBILITY_INTERNAL
                : WorkflowEventType::VISIBILITY_ALL,
        );
        // Audited without the file name: an audit line is read by people who
        // are not on the workflow.
        $this->auditService->log(
            $instance->getTeamId(),
            'workflow.' . $instance->getDefinitionKey() . '.attachment_added',
            $uid,
            'workflow',
            (string)$instanceId,
            ['visibility' => $visibility, 'fileId' => $fileId],
        );
        return $this->describe($row);
    }

    /**
     * The documents of a live workflow, as this caller may see them.
     *
     * The live read asks who is looking, because a live workflow has one
     * viewer at a time rather than an audience. The **archive** asks the
     * audience instead (`WorkflowArchiveService::documentsFor()`), which is
     * the stricter of the two: there, an internal document cannot reach the
     * requesting team's projection even for a reader who is also an agent.
     *
     * @return array<int, array<string, mixed>>
     * @throws AccessDeniedException
     * @throws NotFoundException
     */
    public function listFor(int $instanceId, string $uid): array {
        $this->requireLicence();
        $instance = $this->instances->findById($instanceId);
        if ($instance === null) {
            throw new NotFoundException($this->l->t('Workflow not found.'));
        }
        $steps   = $this->steps->findByInstance($instanceId);
        $isAgent = $this->isHandlingAgent($uid, $steps);
        if (!$isAgent && !$this->isParticipant($uid, $instance) && !$this->resolver->isNextcloudAdmin($uid)) {
            throw new AccessDeniedException($this->l->t('You are not part of this workflow.'));
        }

        $out = [];
        foreach ($this->attachments->findByInstance($instanceId) as $row) {
            if ($row->getVisibility() === WorkflowAttachmentVisibility::INTERNAL && !$isAgent) {
                continue;
            }
            $out[] = $this->describe($row);
        }
        return $out;
    }

    /**
     * Detach a document from an open workflow.
     *
     * The person who attached it, or — for an internal document — the
     * service owner, who is answerable for what their desk keeps. Nobody
     * else: removing somebody else's evidence from a request in flight is
     * exactly the move this rule exists to prevent.
     *
     * @throws AccessDeniedException
     * @throws NotFoundException
     * @throws WorkflowTransitionException the workflow has ended; the record is closed
     */
    public function remove(int $attachmentId, string $uid): void {
        $this->requireLicence();
        $row = $this->attachments->findById($attachmentId);
        if ($row === null) {
            throw new NotFoundException($this->l->t('Document not found.'));
        }
        $instance = $this->requireOpen($row->getInstanceId());
        $steps    = $this->steps->findByInstance($row->getInstanceId());

        $mayRemove = $row->getAddedBy() === $uid;
        if (!$mayRemove && $row->getVisibility() === WorkflowAttachmentVisibility::INTERNAL) {
            $desk = $this->serviceTeamOf($steps);
            $mayRemove = $desk !== '' && $this->serviceTeams->isServiceOwner($uid, $desk);
        }
        if (!$mayRemove) {
            throw new AccessDeniedException($this->l->t('Only the person who attached this document can remove it.'));
        }

        $this->attachments->deleteById($attachmentId);
        $this->events->record(
            $row->getInstanceId(),
            WorkflowEventType::ATTACHMENT_REMOVED,
            $uid,
            null,
            ['fileName' => $row->getFileName(), 'visibility' => $row->getVisibility()],
            $row->getVisibility() === WorkflowAttachmentVisibility::INTERNAL
                ? WorkflowEventType::VISIBILITY_INTERNAL
                : WorkflowEventType::VISIBILITY_ALL,
        );
        $this->auditService->log(
            $instance->getTeamId(),
            'workflow.' . $instance->getDefinitionKey() . '.attachment_removed',
            $uid,
            'workflow',
            (string)$row->getInstanceId(),
            ['visibility' => $row->getVisibility(), 'fileId' => $row->getFileId()],
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internals
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The file, resolved in **this caller's own** Nextcloud.
     *
     * Not in the workflow's team folder, not as the instance's owner, not
     * with a fallback: if the person attaching cannot see the file, there is
     * nothing to attach. A folder is refused — a workflow carries documents,
     * and a folder id would be a moving target whose contents could change
     * after the record was sealed.
     *
     * @throws NotFoundException
     */
    private function readableFile(string $uid, int $fileId): File {
        if ($fileId <= 0) {
            throw new NotFoundException($this->l->t('That document could not be found.'));
        }
        try {
            $nodes = $this->rootFolder->getUserFolder($uid)->getById($fileId);
        } catch (FilesNotFoundException | \Throwable $e) {
            $this->logger->debug('[TeamHub][WorkflowAttachment] file lookup failed', [
                'fileId' => $fileId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            throw new NotFoundException($this->l->t('That document could not be found.'));
        }
        foreach ($nodes as $node) {
            if ($node instanceof File && $node->isReadable()) {
                return $node;
            }
        }
        throw new NotFoundException($this->l->t('That document could not be found.'));
    }

    /** @throws WorkflowTransitionException @throws NotFoundException */
    private function requireOpen(int $instanceId): WorkflowInstance {
        $instance = $this->instances->findById($instanceId);
        if ($instance === null) {
            throw new NotFoundException($this->l->t('Workflow not found.'));
        }
        if (!WorkflowStatus::isOpen($instance->getStatus())) {
            throw new WorkflowTransitionException(
                $this->l->t('This workflow has ended; its documents are part of the record now.'));
        }
        return $instance;
    }

    private function isParticipant(string $uid, WorkflowInstance $instance): bool {
        return $this->resolver->isAmongParticipants(
            $uid,
            $this->participants->findByInstance((int)$instance->getId()),
            $instance->getTeamId(),
        );
    }

    /** @param WorkflowStep[] $steps */
    private function isHandlingAgent(string $uid, array $steps): bool {
        $desk = $this->serviceTeamOf($steps);
        return $desk !== '' && $this->serviceTeams->isEligibleAgent($uid, $desk);
    }

    /**
     * The service team handling this workflow, read from the steps — the
     * same single truth `WorkflowEngine` reads it from, rather than a
     * second column that could disagree with it.
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
     * The step a document belongs to: the one the caller named if it is
     * real, otherwise the active one. A key the client invented is dropped
     * rather than stored — nothing depends on it, and a stored key that
     * matches no step would be a label pointing nowhere.
     *
     * @param WorkflowStep[] $steps
     */
    private function stepKeyOf(array $steps, ?string $asked): ?string {
        foreach ($steps as $step) {
            if ($asked !== null && $step->getStepKey() === $asked) {
                return $asked;
            }
        }
        foreach ($steps as $step) {
            if (WorkflowStepStatus::isActive($step->getStepStatus())) {
                return $step->getStepKey();
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function describe(WorkflowAttachment $row): array {
        return [
            'id'          => (int)$row->getId(),
            'instanceId'  => $row->getInstanceId(),
            'fileId'      => $row->getFileId(),
            'fileName'    => $row->getFileName(),
            'visibility'  => $row->getVisibility(),
            'stepKey'     => $row->getStepKey(),
            'addedBy'     => $row->getAddedBy(),
            'addedByName' => $this->resolver->displayName($row->getAddedBy()),
            'addedAt'     => $row->getAddedAt(),
            // v4.10.38 — a file shared through the paperclip: until when,
            // and whether TeamHub has already taken the share back.
            'shared'      => $row->getShareId() !== '',
            'sharedUntil' => $row->getShareUntil(),
            'unsharedAt'  => $row->getUnsharedAt(),
        ];
    }

    /** @throws \OCA\TeamHub\Exception\LicenseGateException */
    private function requireLicence(): void {
        $this->tier->require(
            WorkflowCapability::ARCHIVE_RESULTS,
            'Workflow documents require an active TeamHub licence.',
        );
    }
}
