<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\CommentMapper;
use OCA\TeamHub\Db\Decision;
use OCA\TeamHub\Db\DecisionMapper;
use OCA\TeamHub\Db\DecisionTeamConfigMapper;
use OCA\TeamHub\Db\MessageMapper;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Decisions business logic service.
 *
 * GATE ENFORCEMENT
 * ----------------
 *   assertModuleEnabledGlobally()   — config endpoints only.
 *   assertModuleEnabledForTeam($id) — feature endpoints (everything else here).
 *
 * Both throw \RuntimeException, which the controller maps to 404 so the
 * feature looks non-existent when off. See DESIGN.md §2.40 for rationale.
 *
 * INVARIANTS
 * ----------
 *   - A message has at most one decision row. propose() refuses duplicates.
 *   - Once status is 'decided' or 'withdrawn', the row is terminal — markBest()
 *     and withdraw() refuse to operate on terminal rows. To replace a decision
 *     post-fact, use supersedes_id on a NEW decision.
 *   - selected_comment_id, selected_answer, answered_by, decided_at are set
 *     together by markBest(). withdrawn_reason, withdrawn_at are set together
 *     by withdraw(). resolved_by tracks "who resolved it on whose behalf" —
 *     equal to proposed_by when the proposer marks/withdraws themselves;
 *     equal to the acting admin otherwise.
 *   - participants is captured at decision time as a JSON array of the team
 *     members at that moment. It is not maintained live.
 *
 * SOURCE FIELDS
 * -------------
 *   source_type accepts: 'message' (default, set automatically), 'document',
 *   'external'. 'meeting_notes' is reserved for a future feature and is
 *   rejected at the API layer with InvalidArgumentException.
 *   source_ref is a free-form ≤512 char string; controllers must validate
 *   shape (URL for 'document' and 'external', file id for 'document', etc.).
 *   v1 just length-checks it.
 */
class DecisionService {

    private const IMPACTS = ['low', 'medium', 'high'];
    private const LEVELS  = ['operational', 'tactical', 'strategic'];
    // Lifecycle (Session H):
    //   open       — initial state when a decision is proposed; discussion via comments
    //   finalized  — proposer has posted the final wording and locked discussion; awaiting approval
    //   approved   — an approver in the category's approver list accepted the finalized proposal (terminal)
    //   denied     — an approver rejected the finalized proposal with a reason (terminal)
    //   withdrawn  — proposer cancelled before finalizing, OR admin withdrew (terminal)
    // Legacy values 'proposed' / 'decided' / 'withdrawn' are no longer written.
    // 'withdrawn' is reused with the same meaning. Existing test data should be wiped.
    private const STATUSES = ['open', 'finalized', 'approved', 'denied', 'withdrawn'];
    // Statuses where the row is immutable (no further state transitions allowed)
    private const TERMINAL_STATUSES = ['approved', 'denied', 'withdrawn'];
    // Statuses where comments on the parent message are locked (read-only)
    private const COMMENTS_LOCKED_STATUSES = ['finalized', 'approved', 'denied', 'withdrawn'];
    // Hidden subfolder in the team folder where finalized proposal documents are written
    private const PROPOSALS_FOLDER = '.proposals';
    private const ALLOWED_SOURCE_TYPES = ['message', 'document', 'external', 'direct'];
    private const MAX_QUESTION_LEN = 4000;
    private const MAX_ANSWER_LEN = 4000;
    private const MAX_WITHDRAWN_REASON_LEN = 1000;
    private const MAX_CATEGORY_LEN = 128;
    private const MAX_SOURCE_REF_LEN = 512;
    private const ADMIN_LEVEL = 8;

    public function __construct(
        private IConfig                  $config,
        private DecisionMapper           $decisionMapper,
        private DecisionTeamConfigMapper $configMapper,
        private MessageMapper            $messageMapper,
        private CommentMapper            $commentMapper,
        private MemberService            $memberService,
        private DecisionCategoryService  $categoryService,
        private DecisionAuditService     $auditService,
        private ResourceService          $resourceService,
        private IUserManager             $userManager,
        private IRootFolder              $rootFolder,
        private LoggerInterface          $logger,
        private \OCA\TeamHub\Db\MessageAttachmentMapper $attachmentMapper,
    ) {}

    // =========================================================================
    // Gate enforcement (Session A)
    // =========================================================================

    public function assertModuleEnabledGlobally(): void {
        $globalEnabled = $this->config->getAppValue(
            Application::APP_ID,
            'decisions_module_enabled',
            '0'
        ) === '1';
        if (!$globalEnabled) {
            throw new \RuntimeException('Decisions module is not enabled');
        }
    }

    public function assertModuleEnabledForTeam(string $teamId): void {
        $this->assertModuleEnabledGlobally();
        $row = $this->configMapper->findByTeam($teamId);
        if ($row === null || $row->getDecisionsEnabled() !== 1) {
            throw new \RuntimeException('Decisions module is not enabled for this team');
        }
    }

    /**
     * Predicate variant for callers that don't want to catch.
     * Used by CommentService to decide whether to apply the comment lock.
     */
    public function isModuleActiveForTeam(string $teamId): bool {
        try {
            $this->assertModuleEnabledForTeam($teamId);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Batch hydration: given a list of message IDs, return a map
     * (messageId → serialised decision payload) for those messages that
     * have a decision attached.
     *
     * Used by MessageService::getTeamMessages to attach decision payload
     * to each message-row in a single round trip. No gate check here —
     * the caller (MessageService) has already authorised by team membership
     * via the message-list query; surfacing a decision row keyed to a
     * message the user can already see is not a leak.
     *
     * @param int[] $messageIds
     * @return array<int, array<string,mixed>>
     */
    public function hydrateForMessages(array $messageIds): array {
        if (empty($messageIds)) {
            return [];
        }
        $rows = $this->decisionMapper->findByMessageIds($messageIds);
        $out = [];
        foreach ($rows as $messageId => $row) {
            $out[$messageId] = $this->serialize($row);
        }
        return $out;
    }

    // =========================================================================
    // Propose
    // =========================================================================

    /**
     * Create a new decision attached to an existing message.
     *
     * Authorisation: the acting user must be a team member. Anyone can propose.
     * Validation:
     *   - The message must exist and belong to the team.
     *   - The message must not already have a decision row.
     *   - impact must be one of low/medium/high.
     *   - category, if given, must be ≤128 chars.
     *   - supersedesId, if given, must point at a decision in the same team.
     *   - sourceType, if given, must be in ALLOWED_SOURCE_TYPES.
     *   - sourceRef, if given, must be ≤512 chars.
     *
     * Side effects: none beyond the row insert. (Notification on propose is a
     * Session C concern — it's an in-stream surface, not a backend concern.)
     *
     * @return array  Serialised decision (see serialize()).
     */
    public function propose(
        string  $teamId,
        int     $messageId,
        string  $impact,
        ?string $level,
        ?string $category,
        ?int    $supersedesId,
        ?string $sourceType,
        ?string $sourceRef,
        string  $actingUserId,
        bool    $autoFinalize = false,
    ): array {
        $this->assertModuleEnabledForTeam($teamId);
        $this->memberService->requireMemberLevel($teamId);

        // Validate impact.
        if (!in_array($impact, self::IMPACTS, true)) {
            throw new \InvalidArgumentException('impact must be one of: ' . implode(', ', self::IMPACTS));
        }

        // Validate level — defaults to 'operational' when null/omitted.
        if ($level === null || $level === '') {
            $level = 'operational';
        }
        if (!in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException('level must be one of: ' . implode(', ', self::LEVELS));
        }

        // Validate category.
        if ($category !== null) {
            $category = trim($category);
            if ($category === '') {
                $category = null;
            } elseif (mb_strlen($category) > self::MAX_CATEGORY_LEN) {
                throw new \InvalidArgumentException('category exceeds maximum length');
            }
        }

        // Validate source fields.
        if ($sourceType !== null && !in_array($sourceType, self::ALLOWED_SOURCE_TYPES, true)) {
            throw new \InvalidArgumentException(
                'sourceType must be one of: ' . implode(', ', self::ALLOWED_SOURCE_TYPES)
                . ' (meeting_notes is reserved for a future feature)'
            );
        }
        if ($sourceRef !== null) {
            $sourceRef = trim($sourceRef);
            if ($sourceRef === '') {
                $sourceRef = null;
            } elseif (mb_strlen($sourceRef) > self::MAX_SOURCE_REF_LEN) {
                throw new \InvalidArgumentException('sourceRef exceeds maximum length');
            }
        }
        // If sourceType is set but sourceRef is null, or vice versa, that's
        // accepted — sourceType='message' is set later with no ref needed,
        // and 'document'/'external' can be added without a stored ref.

        // Look up the message. Throws \Exception 'Message not found'.
        try {
            $message = $this->messageMapper->find($messageId);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Message not found');
        }
        if ((string)$message['team_id'] !== $teamId) {
            // The message belongs to a different team. Treat as not-found at
            // this scope — don't leak existence to other teams' members.
            throw new \InvalidArgumentException('Message not found in this team');
        }

        // Refuse duplicate.
        if ($this->decisionMapper->findByMessageId($messageId) !== null) {
            throw new \RuntimeException('This message already has a decision attached');
        }

        // Validate supersedes target.
        if ($supersedesId !== null) {
            $prior = $this->decisionMapper->findById($supersedesId);
            if ($prior === null || $prior->getTeamId() !== $teamId) {
                throw new \InvalidArgumentException('supersedesId does not point to a decision in this team');
            }
        }

        // Capture question from the message subject. The spec lets the message
        // body carry context; subject is the canonical decision question.
        $question = (string)$message['subject'];
        if (mb_strlen($question) > self::MAX_QUESTION_LEN) {
            $question = mb_substr($question, 0, self::MAX_QUESTION_LEN);
        }

        $now = time();
        $row = new Decision();
        $row->setTeamId($teamId);
        $row->setMessageId($messageId);
        $row->setProposedBy($actingUserId);
        $row->setQuestion($question);
        $row->setImpact($impact);
        $row->setLevel($level);
        $row->setCategory($category);
        // Session A (compose modal): when autoFinalize is true, skip the
        // open/discussion phase and land directly on 'finalized' (awaits
        // approval). Used by ComposeDecisionModal where the proposer has
        // written the full proposal upfront — no comment selection needed.
        // The proposal markdown serves as the selectedAnswer.
        if ($autoFinalize) {
            $row->setStatus('finalized');
            // The proposal body becomes the selectedAnswer. $message is the
            // array loaded above from messageMapper->find().
            $row->setSelectedAnswer((string)($message['message'] ?? ''));
            $row->setDecidedAt($now);
        } else {
            $row->setStatus('open');
        }
        $row->setSupersedesId($supersedesId);
        $row->setSourceType($sourceType ?? 'message');
        $row->setSourceRef($sourceRef);
        $row->setCreatedAt($now);

        /** @var Decision $saved */
        $saved = $this->decisionMapper->insert($row);

        $this->logger->info('[TeamHub][DecisionService] propose', [
            'team_id' => $teamId,
            'message_id' => $messageId,
            'decision_id' => $saved->getId(),
            'proposed_by' => $actingUserId,
            'impact' => $impact,
        ]);

        // Session J — first audit event.
        $this->auditService->log($saved, 'proposed', $actingUserId, null);

        // Supersede side-effect (Session K): when the new proposal supersedes
        // an existing OPEN decision, auto-withdraw the original with a
        // reference to the new one. We only do this for 'open' originals —
        // a finalized/approved/denied decision is already a settled record
        // and the supersedes link alone is enough to read it as superseded.
        if ($supersedesId !== null && isset($prior) && $prior->getStatus() === 'open') {
            try {
                $prior->setStatus('withdrawn');
                $prior->setWithdrawnReason('Superseded by decision #' . $saved->getId());
                $prior->setResolvedBy($actingUserId);
                $prior->setWithdrawnAt($now);
                $updatedPrior = $this->decisionMapper->update($prior);
                // Audit the supersession on the OLD decision so the timeline
                // shows what happened on each side.
                $this->auditService->log($updatedPrior, 'withdrawn', $actingUserId, [
                    'reason'         => 'Superseded by decision #' . $saved->getId(),
                    'superseded_by'  => $saved->getId(),
                ]);
                $this->logger->info('[TeamHub][DecisionService] supersede auto-withdrew original', [
                    'team_id'              => $teamId,
                    'superseded_id'        => $prior->getId(),
                    'superseding_id'       => $saved->getId(),
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][DecisionService] supersede auto-withdraw failed (non-fatal)', [
                    'superseded_id'  => $prior->getId(),
                    'superseding_id' => $saved->getId(),
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        // Session A (compose modal): when proposals are auto-finalized at
        // creation time, log the matching audit event so the timeline reads
        // "Proposed" → "Finalized proposal" the same as the manual path.
        // The proposal-document write (.proposals/{id}/{id}.md + attachment
        // copies) is NOT called here — running it inside the DB transaction
        // is timing-sensitive (the message row isn't committed yet) and
        // would also miss attachments which are registered in a separate
        // request from the frontend. The frontend always calls
        // POST /decisions/{id}/refresh-proposal after attachment registration
        // for compose-modal decisions, which runs writeProposalDocument
        // cleanly outside any transaction.
        if ($autoFinalize) {
            try {
                $this->auditService->log($saved, 'finalized', $actingUserId, [
                    'auto_finalize' => true,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][DecisionService] propose (autoFinalize) — audit log failed (non-fatal)', [
                    'decision_id' => $saved->getId(),
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return $this->serialize($saved);
    }

    /**
     * Re-run the proposal-document write for a decision. Called from the
     * frontend AFTER attachments have been registered against the message,
     * so the .proposals/{id}/ folder picks up the newly-linked files.
     *
     * Used by the compose modal (Session A) where attachments are registered
     * in a second step after postMessage returns; without this call, the
     * proposal folder is written too early (before the attachment sidecar
     * rows exist) and ends up empty of attachment copies.
     *
     * Idempotent — overwrites the .md and re-syncs attachments. Safe to call
     * multiple times.
     *
     * @throws \InvalidArgumentException  if the decision does not exist or
     *                                    is not in this team, or has no doc
     */
    public function refreshProposalDocument(string $teamId, int $decisionId, string $actingUserId): array {
        $this->assertModuleEnabledForTeam($teamId);
        $this->memberService->requireMemberLevel($teamId);

        $decision = $this->decisionMapper->findById($decisionId);
        if ($decision === null || $decision->getTeamId() !== $teamId) {
            throw new \InvalidArgumentException('Decision not found in this team');
        }
        if (!in_array($decision->getStatus(), ['finalized', 'approved', 'denied'], true)) {
            throw new \InvalidArgumentException('Decision has no proposal document yet');
        }

        try {
            $docInfo = $this->writeProposalDocument($decision, $actingUserId);
            $this->logger->info('[TeamHub][DecisionService] refreshProposalDocument', [
                'decision_id' => $decisionId,
                'doc_path'    => $docInfo['cachePath'] ?? null,
                'file_id'     => $docInfo['fileId'] ?? null,
            ]);
            return [
                'ok'        => true,
                'doc_path'  => $docInfo['cachePath'] ?? null,
                'file_id'   => $docInfo['fileId'] ?? null,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][DecisionService] refreshProposalDocument failed', [
                'decision_id' => $decisionId,
                'error'       => $e->getMessage(),
            ]);
            return [
                'ok'    => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // =========================================================================
    // Mark best comment as the decided answer
    // =========================================================================

    /**
     * Finalize an open decision (Session H — replaces markBest).
     *
     * The proposer signals that discussion is closed by clicking the gavel
     * on their own most recent comment. That comment's text becomes the
     * canonical final wording (selectedAnswer), the status moves to 'finalized',
     * and the proposal document is written to {team-folder}/.proposals/{id}.md.
     *
     * Lifecycle:  open → finalized (awaiting approval; not terminal)
     *
     * Authorisation:
     *   - Only the proposer (decision.proposed_by) may finalize. Admins do
     *     NOT have a bypass here — finalize is a statement of "I am done
     *     drafting", which only the proposer can make. Admins still can
     *     withdraw on a proposer's behalf via withdraw().
     *
     * Comments stay readable but become locked once the decision is
     * finalized (see COMMENTS_LOCKED_STATUSES).
     */
    public function finalize(
        string $teamId,
        int    $decisionId,
        int    $commentId,
        string $actingUserId,
    ): array {
        $this->assertModuleEnabledForTeam($teamId);

        $decision = $this->loadDecisionInTeam($decisionId, $teamId);
        $this->assertNotTerminal($decision);

        if ($decision->getStatus() !== 'open') {
            throw new \RuntimeException(
                'Only open decisions can be finalized (current status: ' . $decision->getStatus() . ')'
            );
        }

        // Authorisation: proposer ONLY. Per Session H spec, finalize is the
        // proposer's statement; nobody else can make it on their behalf.
        if ($decision->getProposedBy() !== $actingUserId) {
            throw new \RuntimeException('Only the proposer can finalize this decision');
        }
        $this->memberService->requireMemberLevel($teamId);

        // The chosen comment must exist, belong to the same message, AND
        // be authored by the proposer themselves (their last word is what
        // gets recorded).
        $comment = $this->commentMapper->find($commentId);
        if ($comment === null) {
            throw new \InvalidArgumentException('Selected comment not found');
        }
        if ((int)$comment['message_id'] !== $decision->getMessageId()) {
            throw new \InvalidArgumentException('Selected comment does not belong to this decision');
        }
        if ((string)$comment['author_id'] !== $actingUserId) {
            throw new \InvalidArgumentException('Finalize requires a comment authored by the proposer');
        }

        $now    = time();
        $answer = (string)($comment['comment'] ?? '');
        if (mb_strlen($answer) > self::MAX_ANSWER_LEN) {
            $answer = mb_substr($answer, 0, self::MAX_ANSWER_LEN);
        }

        // Capture participants — UIDs of effective members at finalize time.
        $members = $this->memberService->getAllEffectiveMembers($teamId);
        $participantUids = [];
        foreach ($members as $m) {
            if (!empty($m['userId'])) {
                $participantUids[] = (string)$m['userId'];
            }
        }
        $participantUids = array_values(array_unique($participantUids));
        sort($participantUids);

        $decision->setStatus('finalized');
        $decision->setSelectedCommentId($commentId);
        $decision->setAnsweredBy($actingUserId);
        $decision->setSelectedAnswer($answer);
        $decision->setParticipants(json_encode($participantUids, JSON_THROW_ON_ERROR));
        $decision->setResolvedBy($actingUserId);
        // decidedAt is now overloaded — it captures the finalize time;
        // approved/denied paths leave it as-is and use a new column later
        // (Session J audit table) for precise per-transition timestamps.
        $decision->setDecidedAt($now);

        /** @var Decision $saved */
        $saved = $this->decisionMapper->update($decision);

        // Write the proposal document to {team-folder}/.proposals/.
        // Best-effort: if the team has no team folder, log and continue —
        // the decision is still finalized.
        //
        // v3.71.10 — previously this also set sourceType='document' and
        // sourceRef=cachePath on the decision row. That caused the detail
        // panel's Source heading to display the .md path as a text line
        // ABOVE the file list that already shows the same file. Dropped:
        // sourceRef now only ever reflects what the proposer originally
        // entered (URL or text). The proposal .md is surfaced via the
        // sourceFiles list endpoint and the in-app viewer.
        try {
            $docInfo = $this->writeProposalDocument($saved, $actingUserId);
            if ($docInfo !== null) {
                $this->logger->info('[TeamHub][DecisionService] finalize — proposal document written', [
                    'team_id'     => $teamId,
                    'decision_id' => $saved->getId(),
                    'doc_path'    => $docInfo['cachePath'],
                    'file_id'     => $docInfo['fileId'],
                ]);
            }
        } catch (\Throwable $e) {
            // Don't undo the finalize on document failure — log and move on.
            $this->logger->warning('[TeamHub][DecisionService] finalize — proposal document write failed (non-fatal)', [
                'team_id'     => $teamId,
                'decision_id' => $saved->getId(),
                'error'       => $e->getMessage(),
            ]);
        }

        $this->logger->info('[TeamHub][DecisionService] finalize', [
            'team_id' => $teamId,
            'decision_id' => $saved->getId(),
            'comment_id' => $commentId,
            'resolved_by' => $actingUserId,
        ]);

        // Session J — finalize audit. Excerpt clipped server-side in the helper.
        $this->auditService->log($saved, 'finalized', $actingUserId, [
            'comment_id' => $commentId,
            'excerpt'    => $answer, // full final wording — useful in the timeline
        ]);

        return $this->serialize($saved);
    }

    // =========================================================================
    // Withdraw (proposer cancels before finalizing, or admin acts on a stuck row)
    // =========================================================================

    /**
     * Withdraw a non-terminal decision.
     *
     * Permitted source states: open, finalized
     * Permitted actors:
     *   - the proposer at any non-terminal status
     *   - a team admin (level ≥ 8) at any non-terminal status (admin override
     *     for stuck/absent proposers)
     *
     * 'denied' is a separate path used by approvers — withdraw is the
     * proposer/admin path. Both end the decision; they're distinguished by
     * the resulting status so the UI can show the right banner.
     */
    public function withdraw(
        string $teamId,
        int    $decisionId,
        string $reason,
        string $actingUserId,
    ): array {
        $this->assertModuleEnabledForTeam($teamId);

        $decision = $this->loadDecisionInTeam($decisionId, $teamId);
        $this->assertNotTerminal($decision);

        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Withdrawal reason is required');
        }
        if (mb_strlen($reason) > self::MAX_WITHDRAWN_REASON_LEN) {
            throw new \InvalidArgumentException('Withdrawal reason exceeds maximum length');
        }

        $isProposer = $decision->getProposedBy() === $actingUserId;
        if (!$isProposer) {
            $this->memberService->requireAdminLevel($teamId);
        } else {
            $this->memberService->requireMemberLevel($teamId);
        }

        $now = time();
        $decision->setStatus('withdrawn');
        $decision->setWithdrawnReason($reason);
        $decision->setResolvedBy($actingUserId);
        $decision->setWithdrawnAt($now);

        /** @var Decision $saved */
        $saved = $this->decisionMapper->update($decision);

        $this->logger->info('[TeamHub][DecisionService] withdraw', [
            'team_id' => $teamId,
            'decision_id' => $saved->getId(),
            'resolved_by' => $actingUserId,
            'by_admin' => !$isProposer,
        ]);

        // Session J — withdraw audit.
        $this->auditService->log($saved, 'withdrawn', $actingUserId, [
            'reason'   => $reason,
            'by_admin' => !$isProposer,
        ]);

        return $this->serialize($saved);
    }

    // =========================================================================
    // Approve / Deny — Session H
    // =========================================================================

    /**
     * Approve a finalized decision.
     *
     * Lifecycle:  finalized → approved (terminal)
     *
     * Authorisation:
     *   - The acting user must be in the category's approver list (m:n from
     *     Session G). Per spec Q2: either-one — any single approver suffices.
     *   - Per spec Q7: the team owner can approve their own proposal because
     *     they are auto-added to every category's approver list.
     *
     * If the decision has no category (legacy free-text categories from
     * pre-Session-G — should not happen for new decisions but defended
     * here), only a team admin (level ≥ 8) can approve.
     */
    public function approve(
        string $teamId,
        int    $decisionId,
        string $actingUserId,
        string $reason = '',
    ): array {
        $this->assertModuleEnabledForTeam($teamId);
        $this->memberService->requireMemberLevel($teamId);

        // v3.71.3 — rationale is mandatory for approve, mirroring deny. The
        // text is stored in the audit payload (the row entity has no
        // dedicated `approved_reason` column; reusing `withdrawn_reason` for
        // approvals would be semantically wrong). The detail panel surfaces
        // the audit timeline, so the reason remains visible to all members.
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Approval reason is required');
        }
        if (mb_strlen($reason) > self::MAX_WITHDRAWN_REASON_LEN) {
            throw new \InvalidArgumentException('Approval reason exceeds maximum length');
        }

        $decision = $this->loadDecisionInTeam($decisionId, $teamId);
        if ($decision->getStatus() !== 'finalized') {
            throw new \RuntimeException(
                'Only finalized decisions can be approved (current status: ' . $decision->getStatus() . ')'
            );
        }

        $this->assertApproverFor($teamId, $decision, $actingUserId);

        $decision->setStatus('approved');
        $decision->setResolvedBy($actingUserId);
        // decidedAt was set at finalize-time. We don't overwrite it here so
        // the timeline retains both moments. Session J will add explicit
        // per-transition timestamps.

        /** @var Decision $saved */
        $saved = $this->decisionMapper->update($decision);

        $this->logger->info('[TeamHub][DecisionService] approve', [
            'team_id'     => $teamId,
            'decision_id' => $saved->getId(),
            'approved_by' => $actingUserId,
        ]);

        // Session J — approve audit + proposal document regen so the .md
        // file in .proposals/ reflects the final outcome (best-effort).
        // v3.71.3 — capture approval reason in the audit payload.
        $this->auditService->log($saved, 'approved', $actingUserId, [
            'reason' => $reason,
        ]);
        $this->regenerateProposalDocument($saved);

        return $this->serialize($saved);
    }

    /**
     * Deny a finalized decision with a mandatory reason.
     *
     * Lifecycle:  finalized → denied (terminal — per spec Q6, permanent)
     *
     * Same approver gate as approve(). The reason is stored in withdrawn_reason
     * (column reuse — it's the "termination reason" slot) and surfaced in
     * the detail panel.
     */
    public function deny(
        string $teamId,
        int    $decisionId,
        string $reason,
        string $actingUserId,
    ): array {
        $this->assertModuleEnabledForTeam($teamId);
        $this->memberService->requireMemberLevel($teamId);

        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Denial reason is required');
        }
        if (mb_strlen($reason) > self::MAX_WITHDRAWN_REASON_LEN) {
            throw new \InvalidArgumentException('Denial reason exceeds maximum length');
        }

        $decision = $this->loadDecisionInTeam($decisionId, $teamId);
        if ($decision->getStatus() !== 'finalized') {
            throw new \RuntimeException(
                'Only finalized decisions can be denied (current status: ' . $decision->getStatus() . ')'
            );
        }

        $this->assertApproverFor($teamId, $decision, $actingUserId);

        $now = time();
        $decision->setStatus('denied');
        $decision->setWithdrawnReason($reason);
        $decision->setResolvedBy($actingUserId);
        $decision->setWithdrawnAt($now);

        /** @var Decision $saved */
        $saved = $this->decisionMapper->update($decision);

        $this->logger->info('[TeamHub][DecisionService] deny', [
            'team_id'     => $teamId,
            'decision_id' => $saved->getId(),
            'denied_by'   => $actingUserId,
        ]);

        // Session J — deny audit + proposal document regen.
        $this->auditService->log($saved, 'denied', $actingUserId, [
            'reason' => $reason,
        ]);
        $this->regenerateProposalDocument($saved);

        return $this->serialize($saved);
    }

    /**
     * Throws if $actingUserId is not authorised to approve/deny this decision.
     *
     * Resolution path:
     *   1. Decision has a category present in teamhub_dec_categories →
     *      check the m:n approver list. Pass if user is listed.
     *   2. Decision's category string doesn't match any predefined category
     *      (legacy/free-text row) → fall back to "must be team admin".
     */
    private function assertApproverFor(string $teamId, Decision $d, string $actingUserId): void {
        $categoryName = $d->getCategory();

        if ($categoryName !== null && $categoryName !== '') {
            // Find the matching category row (case-sensitive — names are
            // stored case-preserved from creation).
            $allCategories = $this->categoryService->listForTeam($teamId);
            foreach ($allCategories as $cat) {
                if ($cat['name'] === $categoryName) {
                    if (in_array($actingUserId, $cat['approvers'], true)) {
                        return; // Approved approver.
                    }
                    throw new \RuntimeException('Not authorized: you are not an approver for category ' . $categoryName);
                }
            }
            // Category string doesn't match any predefined row — fall
            // through to admin check.
        }

        // No category, or category not in the predefined list → admin only.
        $this->memberService->requireAdminLevel($teamId);
    }

    // =========================================================================
    // Proposal document writer (Session H)
    // =========================================================================

    /**
     * Render the finalized decision as a markdown document and write it to
     * the team folder's hidden .proposals/ subfolder. Returns the cache-path
     * + fileId, or null if the team has no team folder.
     *
     * The hidden-folder pattern mirrors FilesService::cacheImageInTeamFolder
     * (.teamhub-cache) — same lifecycle, same access model: the team folder
     * is circle-shared with all members, so every member can read the file
     * without per-user ACL plumbing. The leading dot keeps it out of normal
     * Files browsing.
     *
     * Document content includes: the question, the original proposal
     * message body, every comment in chronological order with author
     * displayName + ISO timestamp, and the final wording. Approval status
     * appended at the bottom — regenerated whenever finalize fires;
     * Session J's audit will append further events.
     *
     * @return array{ fileId: int, cachePath: string }|null
     */
    private function writeProposalDocument(Decision $d, string $actingUserId): ?array {
        // 1. Resolve the team folder fileId via ResourceService.
        $resources = $this->resourceService->getTeamResources($d->getTeamId());
        if (empty($resources['files']) || empty($resources['files']['folder_id'])) {
            $this->logger->warning('[TeamHub][DecisionService] writeProposalDocument — team has no team folder, skipping', [
                'team_id'     => $d->getTeamId(),
                'decision_id' => $d->getId(),
            ]);
            return null;
        }
        $teamFolderId = (int)$resources['files']['folder_id'];
        $this->logger->debug('[TeamHub][DecisionService] writeProposalDocument — START', [
            'team_id'        => $d->getTeamId(),
            'decision_id'    => $d->getId(),
            'message_id'     => $d->getMessageId(),
            'team_folder_id' => $teamFolderId,
            'acting_user'    => $actingUserId,
        ]);

        // 2. Resolve the team folder node via the acting user's mountpoints.
        $teamFolderNodes = $this->rootFolder->getById($teamFolderId);
        if (empty($teamFolderNodes)) {
            throw new \RuntimeException('Team folder not found: ' . $teamFolderId);
        }
        /** @var \OCP\Files\Folder $teamFolder */
        $teamFolder = $teamFolderNodes[0];
        if (!($teamFolder instanceof \OCP\Files\Folder)) {
            throw new \RuntimeException('Team folder ID does not point to a folder: ' . $teamFolderId);
        }

        // 3. Ensure .proposals/ exists.
        try {
            $proposalsFolder = $teamFolder->get(self::PROPOSALS_FOLDER);
            if (!($proposalsFolder instanceof \OCP\Files\Folder)) {
                throw new \RuntimeException('Proposals path exists but is not a folder');
            }
        } catch (\OCP\Files\NotFoundException) {
            $proposalsFolder = $teamFolder->newFolder(self::PROPOSALS_FOLDER);
        }

        // 4. Decide layout: new finalizations use {decisionId}/{decisionId}.md
        //    (subfolder per decision, so attachments can be copied alongside).
        //    Legacy decisions finalized before v3.71.2 wrote {decisionId}.md
        //    flat at the .proposals/ root — if such a flat file exists for
        //    this decision, keep using the flat layout on regen so we never
        //    orphan content. New decisions always use the subfolder layout.
        $filename = $d->getId() . '.md';
        $legacyFlatPath = self::PROPOSALS_FOLDER . '/' . $filename;
        $legacyFlatExists = false;
        try {
            $proposalsFolder->get($filename);
            $legacyFlatExists = true;
        } catch (\OCP\Files\NotFoundException) {
            // No legacy flat file — use subfolder layout.
        }

        $markdown = $this->renderProposalMarkdown($d);

        $this->logger->debug('[TeamHub][DecisionService] writeProposalDocument — layout decision', [
            'decision_id'        => $d->getId(),
            'legacy_flat_exists' => $legacyFlatExists,
            'will_use_layout'    => $legacyFlatExists ? 'legacy-flat' : 'subfolder',
        ]);

        if ($legacyFlatExists) {
            // Legacy layout: write/overwrite .proposals/{id}.md flat.
            return $this->writeFileInto($proposalsFolder, $filename, $markdown, $legacyFlatPath);
        }

        // New layout: .proposals/{id}/{id}.md
        $subfolderName = (string)$d->getId();
        try {
            $subfolder = $proposalsFolder->get($subfolderName);
            if (!($subfolder instanceof \OCP\Files\Folder)) {
                throw new \RuntimeException('Proposal subfolder path exists but is not a folder');
            }
        } catch (\OCP\Files\NotFoundException) {
            $subfolder = $proposalsFolder->newFolder($subfolderName);
        }

        $cachePath = self::PROPOSALS_FOLDER . '/' . $subfolderName . '/' . $filename;
        $info = $this->writeFileInto($subfolder, $filename, $markdown, $cachePath);

        // 5. Copy any registered attachments on the parent message into the
        //    same subfolder, so they appear in the Decisions detail panel's
        //    Source list. Best-effort: failures logged, not raised.
        try {
            $this->copyAttachmentsIntoFolder($d, $subfolder);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][DecisionService] copyAttachmentsIntoFolder failed (non-fatal)', [
                'decision_id' => $d->getId(),
                'error'       => $e->getMessage(),
            ]);
        }

        return $info;
    }

    /**
     * Write content into a target folder under the given filename. Overwrites
     * if the file already exists; replaces if a non-file node collides.
     *
     * @return array{fileId:int, cachePath:string}
     */
    private function writeFileInto(\OCP\Files\Folder $folder, string $filename, string $content, string $cachePath): array {
        try {
            $existing = $folder->get($filename);
            if ($existing instanceof \OCP\Files\File) {
                $existing->putContent($content);
                return [
                    'fileId'    => $existing->getId(),
                    'cachePath' => $cachePath,
                ];
            }
            // Path collides with a non-file — overwrite as new (shouldn't happen).
            $existing->delete();
        } catch (\OCP\Files\NotFoundException) {
            // Fall through to newFile.
        }

        /** @var \OCP\Files\File $file */
        $file = $folder->newFile($filename, $content);
        return [
            'fileId'    => $file->getId(),
            'cachePath' => $cachePath,
        ];
    }

    /**
     * Copy each attachment registered against the decision's parent message
     * into the given target folder. Idempotent: if a file with the same name
     * already exists at the destination, it is overwritten.
     *
     * Runs as whichever user's session triggered finalize; that user must
     * have read access to the attachment file (true for circle-shared
     * attachments uploaded via PostMessageForm).
     */
    private function copyAttachmentsIntoFolder(Decision $d, \OCP\Files\Folder $target): void {
        $messageId = $d->getMessageId();
        $this->logger->debug('[TeamHub][DecisionService] copyAttachmentsIntoFolder — START', [
            'decision_id'   => $d->getId(),
            'message_id'    => $messageId,
            'target_folder' => $target->getInternalPath(),
        ]);
        if ($messageId <= 0) {
            $this->logger->debug('[TeamHub][DecisionService] copyAttachmentsIntoFolder — bailing, messageId <= 0');
            return;
        }
        $rows = $this->attachmentMapper->findByMessageId($messageId);
        $this->logger->debug('[TeamHub][DecisionService] copyAttachmentsIntoFolder — sidecar rows found', [
            'decision_id' => $d->getId(),
            'message_id'  => $messageId,
            'row_count'   => count($rows),
        ]);
        if (empty($rows)) {
            return;
        }
        $copied = 0;
        foreach ($rows as $row) {
            $fileId = $row->getFileId();
            try {
                $nodes = $this->rootFolder->getById($fileId);
                if (empty($nodes)) {
                    $this->logger->debug('[TeamHub][DecisionService] attachment file_id not found in user mountpoints', [
                        'file_id' => $fileId, 'decision_id' => $d->getId(),
                    ]);
                    continue;
                }
                $source = $nodes[0];
                if (!($source instanceof \OCP\Files\File)) {
                    continue;
                }
                // Use the stored display name to avoid collisions with the .md
                // file (decisionId.md) and to preserve the user's intended name.
                $destName = $this->sanitizeAttachmentName($row->getFileName() ?: ('attachment-' . $fileId));
                // Don't ever overwrite the proposal markdown.
                if ($destName === ($d->getId() . '.md')) {
                    $destName = 'attachment-' . $fileId . '-' . $destName;
                }
                $content = $source->getContent();
                $this->writeFileInto($target, $destName, $content, $target->getInternalPath() . '/' . $destName);
                $copied++;
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][DecisionService] failed to copy attachment', [
                    'decision_id' => $d->getId(),
                    'file_id'     => $fileId,
                    'error'       => $e->getMessage(),
                ]);
            }
        }
        if ($copied > 0) {
            $this->logger->info('[TeamHub][DecisionService] attachments copied into proposal subfolder', [
                'decision_id' => $d->getId(),
                'copied'      => $copied,
            ]);
        }
    }

    /**
     * Strip path separators and control chars from a filename. Does NOT
     * truncate length — the Files layer handles that.
     */
    private function sanitizeAttachmentName(string $name): string {
        $name = str_replace(['/', '\\', "\0"], '_', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/', '_', $name);
        $name = trim($name);
        return $name !== '' ? $name : 'attachment';
    }

    /**
     * Regenerate the proposal document for a decision that already has one
     * (called from approve / deny). Resolves the team folder via the same
     * path writeProposalDocument uses; if the document doesn't exist yet
     * — e.g. team had no folder at finalize time — this is a no-op.
     *
     * Best-effort: failures are logged and swallowed. The status transition
     * has already happened; the markdown is just a side-channel record.
     */
    private function regenerateProposalDocument(Decision $d): void {
        if ($d->getSourceType() !== 'document' || empty($d->getSourceRef())) {
            // No previously-written document — nothing to regenerate.
            return;
        }
        try {
            $info = $this->writeProposalDocument($d, (string)($d->getResolvedBy() ?? $d->getProposedBy()));
            if ($info !== null) {
                $this->logger->info('[TeamHub][DecisionService] proposal document regenerated', [
                    'decision_id' => $d->getId(),
                    'doc_path'    => $info['cachePath'],
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][DecisionService] proposal document regen failed (non-fatal)', [
                'decision_id' => $d->getId(),
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the markdown body of a proposal document. Pure function over
     * the Decision + its message + comments. No I/O.
     */
    private function renderProposalMarkdown(Decision $d): string {
        $lines = [];

        $iso = fn(int $ts) => date('Y-m-d H:i', $ts);
        $name = function (string $uid): string {
            if ($uid === '') return 'Unknown';
            $user = $this->userManager->get($uid);
            return $user !== null ? ($user->getDisplayName() ?: $uid) : $uid;
        };

        // ── Header ──
        $lines[] = '# ' . $this->mdEscape($d->getQuestion() !== '' ? $d->getQuestion() : 'Untitled decision');
        $lines[] = '';
        $lines[] = '- **Status:** ' . $this->statusLabel($d->getStatus());
        if ($d->getCategory() !== null && $d->getCategory() !== '') {
            $lines[] = '- **Category:** ' . $this->mdEscape($d->getCategory());
        }
        $lines[] = '- **Impact:** ' . $d->getImpact();
        $lines[] = '- **Proposed by:** ' . $this->mdEscape($name($d->getProposedBy())) . ' (' . $d->getProposedBy() . ')';
        $lines[] = '- **Proposed:** ' . $iso($d->getCreatedAt());
        if ($d->getDecidedAt() !== null) {
            $lines[] = '- **Finalized:** ' . $iso($d->getDecidedAt());
        }
        if ($d->getStatus() === 'approved') {
            $lines[] = '- **Approved by:** ' . $this->mdEscape($name((string)$d->getResolvedBy())) . ' (' . $d->getResolvedBy() . ')';
        }
        if ($d->getStatus() === 'denied') {
            $lines[] = '- **Denied by:** ' . $this->mdEscape($name((string)$d->getResolvedBy())) . ' (' . $d->getResolvedBy() . ')';
            if ($d->getWithdrawnReason() !== null) {
                $lines[] = '- **Denial reason:** ' . $this->mdEscape($d->getWithdrawnReason());
            }
        }
        $lines[] = '';

        // ── Original proposal ──
        $message = $this->messageMapper->find($d->getMessageId());
        $lines[] = '## Proposal';
        $lines[] = '';
        if ($message !== null && !empty($message['message'])) {
            $lines[] = (string)$message['message'];
        } else {
            $lines[] = '_(Original message could not be retrieved.)_';
        }
        $lines[] = '';

        // ── Discussion (all comments in order) ──
        $comments = $this->commentMapper->findByMessageId($d->getMessageId());
        if (!empty($comments)) {
            $lines[] = '## Discussion';
            $lines[] = '';
            foreach ($comments as $c) {
                $author = $name((string)($c['author_id'] ?? ''));
                $ts = (int)($c['created_at'] ?? 0);
                $lines[] = '### ' . $this->mdEscape($author) . ' — ' . $iso($ts);
                $lines[] = '';
                $lines[] = (string)($c['comment'] ?? '');
                $lines[] = '';
            }
        }

        // ── Final wording ──
        if ($d->getSelectedAnswer() !== null && $d->getSelectedAnswer() !== '') {
            $lines[] = '## Final wording';
            $lines[] = '';
            $lines[] = (string)$d->getSelectedAnswer();
            $lines[] = '';
        }

        // ── Audit trail (Session J) ──
        // Pulled in here so the regenerated document reflects everything
        // that's happened up to this regen call (e.g. approve/deny).
        try {
            $audit = $this->auditService->listForDecision($d->getId());
        } catch (\Throwable) {
            $audit = [];
        }
        if (!empty($audit)) {
            $lines[] = '## Audit trail';
            $lines[] = '';
            foreach ($audit as $ev) {
                $when  = $iso((int)$ev['createdAt']);
                $who   = (string)($ev['actorDisplayName'] ?? $ev['actor'] ?? '');
                $trans = (string)$ev['transition'];
                $line  = '- **' . $iso((int)$ev['createdAt']) . '** — ' . $this->mdEscape($trans)
                       . ' by ' . $this->mdEscape($who);
                $payload = $ev['payload'] ?? null;
                if (is_array($payload)) {
                    if (!empty($payload['reason'])) {
                        $line .= ': ' . $this->mdEscape((string)$payload['reason']);
                    } elseif (!empty($payload['excerpt']) && $trans === 'commented') {
                        $line .= ': "' . $this->mdEscape((string)$payload['excerpt']) . '"';
                    }
                }
                $lines[] = $line;
                // suppress unused warning
                unset($when);
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function statusLabel(string $status): string {
        return match ($status) {
            'open'       => 'Open',
            'finalized'  => 'Finalized · Awaiting approval',
            'approved'   => 'Approved',
            'denied'     => 'Denied',
            'withdrawn'  => 'Withdrawn',
            default      => $status,
        };
    }

    /**
     * Minimal markdown escaper for heading/inline text. Block content
     * (proposal body, comments, final wording) is intentionally NOT escaped
     * — those are meant to render with their original formatting (the team
     * may have used markdown in their messages).
     */
    private function mdEscape(string $s): string {
        // Strip control chars; escape backslash and the chars that affect
        // headings/lists/links.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
        return str_replace(
            ['\\', '`', '*', '_', '[', ']', '<', '>'],
            ['\\\\', '\\`', '\\*', '\\_', '\\[', '\\]', '\\<', '\\>'],
            $s,
        );
    }

    // =========================================================================
    // Read paths
    // =========================================================================

    /**
     * List decisions for a team. Filters / sort / pagination via the mapper.
     * Result rows are serialise()d; the 'tasks' field is not eagerly populated
     * (the list view doesn't need it — callers fetch tasks on-expand).
     *
     * @param array{status?:string|array,impact?:string|array,category?:string|array,proposedBy?:string|array,q?:string} $filters
     * @return array{items: array<int, array<string,mixed>>, nextBefore: ?int}
     */
    public function list(
        string $teamId,
        array  $filters,
        string $sort,
        ?int   $before,
        int    $limit,
    ): array {
        $this->assertModuleEnabledForTeam($teamId);
        $this->memberService->requireMemberLevel($teamId);

        $normalised = [];
        foreach (['status', 'impact', 'level', 'category', 'proposedBy'] as $key) {
            if (!isset($filters[$key])) continue;
            $val = $filters[$key];
            if (is_string($val)) {
                $val = $val === '' ? [] : [$val];
            }
            if (!is_array($val)) {
                continue;
            }
            // Filter out empty strings and stringify all entries.
            $val = array_values(array_filter(array_map('strval', $val), fn($s) => $s !== ''));
            if (!empty($val)) {
                $normalised[$key] = $val;
            }
        }
        // v3.71.3 — expand status filter to include legacy synonyms in the
        // DB. Some rows pre-date the 'proposed' → 'open' / 'decided' →
        // 'approved' renames and still carry the legacy values, so a filter
        // of ['open'] would miss them. The display layer already maps these
        // for the user; do the same on the query side so the Open / Approved
        // widget tabs are never accidentally empty.
        if (!empty($normalised['status'])) {
            $expanded = $normalised['status'];
            if (in_array('open', $expanded, true) && !in_array('proposed', $expanded, true)) {
                $expanded[] = 'proposed';
            }
            if (in_array('approved', $expanded, true) && !in_array('decided', $expanded, true)) {
                $expanded[] = 'decided';
            }
            $normalised['status'] = $expanded;
        }
        if (!empty($filters['q']) && is_string($filters['q'])) {
            $q = trim($filters['q']);
            if ($q !== '') {
                $normalised['q'] = mb_substr($q, 0, 200); // hard cap on user-supplied LIKE
            }
        }
        if (!in_array($sort, ['recent', 'created'], true)) {
            $sort = 'recent';
        }
        $limit = max(1, min(100, $limit));

        $rows = $this->decisionMapper->list($teamId, $normalised, $sort, $before, $limit);

        $items = [];
        foreach ($rows as $r) {
            $items[] = $this->serialize($r);
        }

        // Cursor: when we got a full page, next call should start before the
        // smallest id in this page. (See DecisionMapper::list for the v1
        // cursor approximation note.)
        $nextBefore = null;
        if (count($rows) === $limit && !empty($rows)) {
            $nextBefore = $rows[count($rows) - 1]->getId();
        }

        return ['items' => $items, 'nextBefore' => $nextBefore];
    }

    /**
     * Fetch a single decision, including its linked Deck tasks (hydrated via
     * DeckService::getCardsByIds — so the response carries titles/board info
     * suitable for direct render).
     */
    public function get(string $teamId, int $decisionId): array {
        $this->assertModuleEnabledForTeam($teamId);
        $this->memberService->requireMemberLevel($teamId);

        $decision = $this->loadDecisionInTeam($decisionId, $teamId);
        $serialised = $this->serialize($decision);
        // Task links are fetched via the separate GET /decisions/{id}/tasks
        // endpoint (Session B). No longer embedded in the show() response.
        return $serialised;
    }

    /**
     * Distinct non-null categories used in this team — for filter picker.
     * @return string[]
     */
    public function categories(string $teamId): array {
        $this->assertModuleEnabledForTeam($teamId);
        $this->memberService->requireMemberLevel($teamId);
        return $this->decisionMapper->distinctCategoriesByTeam($teamId);
    }

    // =========================================================================
    // Comment-lock helper (used by CommentController)
    // =========================================================================

    /**
     * Return true when the comment write should be REFUSED because the parent
     * message has a terminal-state decision (decided or withdrawn).
     *
     * Locking semantics (per spec):
     *   - When the parent message has a decision in status='proposed', comments
     *     remain writable.
     *   - When the parent message has a decision in status='decided' or
     *     'withdrawn', comments are LOCKED — no create/update/delete except
     *     by a team admin doing moderation (admin override is enforced at the
     *     controller level, not here).
     *   - If the module is off for the team, or the message has no decision
     *     row, this returns false (no lock).
     */
    public function isCommentLocked(string $teamId, int $messageId): bool {
        if (!$this->isModuleActiveForTeam($teamId)) {
            return false;
        }
        $d = $this->decisionMapper->findByMessageId($messageId);
        if ($d === null) {
            return false;
        }
        return in_array($d->getStatus(), self::COMMENTS_LOCKED_STATUSES, true);
    }

    /**
     * Audit hook for CommentController. Called on every newly-created
     * comment; if the parent message has a decision row, append a
     * 'commented' transition to the audit trail. Otherwise no-op.
     *
     * Best-effort — never throws.
     */
    public function auditCommentOnDecision(int $messageId, int $commentId, string $authorUid, string $commentText): void {
        try {
            $d = $this->decisionMapper->findByMessageId($messageId);
            if ($d === null) return;
            $this->auditService->logComment($d, $commentId, $authorUid, $commentText);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][DecisionService] auditCommentOnDecision failed (non-fatal)', [
                'message_id' => $messageId,
                'comment_id' => $commentId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Read the full audit trail for a decision. Membership is checked
     * upstream by the controller.
     *
     * @return array<int, array<string,mixed>>
     */
    public function listAuditForDecision(string $teamId, int $decisionId): array {
        $this->assertModuleEnabledForTeam($teamId);
        // 404 if the decision isn't in this team — same gate as other reads.
        $this->loadDecisionInTeam($decisionId, $teamId);
        return $this->auditService->listForDecision($decisionId);
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Load and team-scope a decision. 404 (RuntimeException) if missing OR
     * if it belongs to another team — we deliberately don't differentiate.
     */
    private function loadDecisionInTeam(int $decisionId, string $teamId): Decision {
        $d = $this->decisionMapper->findById($decisionId);
        if ($d === null || $d->getTeamId() !== $teamId) {
            throw new \RuntimeException('Decision not found');
        }
        return $d;
    }

    private function assertNotTerminal(Decision $d): void {
        if (in_array($d->getStatus(), self::TERMINAL_STATUSES, true)) {
            throw new \RuntimeException(
                'Decision is in terminal state (' . $d->getStatus() . ') and cannot be modified'
            );
        }
    }

    /**
     * Hydrate the link rows of a decision with display metadata from Deck.
     * Cards that have since been deleted appear with a 'missing' flag so the
     * frontend can render a tombstone instead of dropping them.
     *
     * @return array<int, array<string,mixed>>
     */

    /**
     * Convert a Decision entity to the API shape. Entity objects do not leak
     * past this service boundary (DESIGN.md §thin controllers / fat services).
     */
    private function serialize(Decision $d): array {
        $participants = null;
        if ($d->getParticipants() !== null) {
            try {
                $participants = json_decode($d->getParticipants(), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $participants = null;
            }
        }
        return [
            'id'                  => $d->getId(),
            'teamId'              => $d->getTeamId(),
            'messageId'           => $d->getMessageId(),
            'proposedBy'          => $d->getProposedBy(),
            'answeredBy'          => $d->getAnsweredBy(),
            'selectedCommentId'   => $d->getSelectedCommentId(),
            'category'            => $d->getCategory(),
            'impact'              => $d->getImpact(),
            'level'               => $d->getLevel() ?? 'operational',
            'question'            => $d->getQuestion(),
            'selectedAnswer'      => $d->getSelectedAnswer(),
            'participants'        => $participants,
            'status'              => $d->getStatus(),
            'withdrawnReason'     => $d->getWithdrawnReason(),
            'resolvedBy'          => $d->getResolvedBy(),
            'supersedesId'        => $d->getSupersedesId(),
            'sourceType'          => $d->getSourceType(),
            'sourceRef'           => $d->getSourceRef(),
            'createdAt'           => $d->getCreatedAt(),
            'decidedAt'           => $d->getDecidedAt(),
            'withdrawnAt'         => $d->getWithdrawnAt(),
        ];
    }

    /**
     * List the files in .proposals/{decisionId}/ for the Source heading
     * in the Decisions detail panel.
     *
     * Returns an array of { file_id, name, mime, size, is_proposal }
     * where is_proposal=true marks the canonical {decisionId}.md.
     *
     * If the decision predates the subfolder layout (v3.71.2) and only a
     * flat .proposals/{decisionId}.md exists, returns a single-item list
     * pointing at that legacy file.
     *
     * @return array<int, array{file_id:int, name:string, mime:string, size:int, is_proposal:bool}>
     */
    public function listSourceFiles(string $teamId, int $decisionId): array {
        $this->memberService->requireMemberLevel($teamId);

        // Verify the decision belongs to this team — prevents cross-team probing.
        try {
            $d = $this->decisionMapper->findById($decisionId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return [];
        }
        if ($d->getTeamId() !== $teamId) {
            return [];
        }

        $resources = $this->resourceService->getTeamResources($teamId);
        if (empty($resources['files']) || empty($resources['files']['folder_id'])) {
            return [];
        }
        $teamFolderId = (int)$resources['files']['folder_id'];
        $teamFolderNodes = $this->rootFolder->getById($teamFolderId);
        if (empty($teamFolderNodes)) {
            return [];
        }
        $teamFolder = $teamFolderNodes[0];
        if (!($teamFolder instanceof \OCP\Files\Folder)) {
            return [];
        }

        try {
            $proposalsFolder = $teamFolder->get(self::PROPOSALS_FOLDER);
            if (!($proposalsFolder instanceof \OCP\Files\Folder)) {
                return [];
            }
        } catch (\OCP\Files\NotFoundException) {
            return [];
        }

        $proposalFilename = $decisionId . '.md';
        $out = [];

        // Subfolder layout (preferred — new finalizations).
        try {
            $subfolder = $proposalsFolder->get((string)$decisionId);
            if ($subfolder instanceof \OCP\Files\Folder) {
                foreach ($subfolder->getDirectoryListing() as $node) {
                    if (!($node instanceof \OCP\Files\File)) {
                        continue;
                    }
                    $name = $node->getName();
                    $out[] = [
                        'file_id'     => $node->getId(),
                        'name'        => $name,
                        'mime'        => $node->getMimeType() ?: 'application/octet-stream',
                        'size'        => $node->getSize(),
                        'is_proposal' => ($name === $proposalFilename),
                    ];
                }
                return $out;
            }
        } catch (\OCP\Files\NotFoundException) {
            // No subfolder — try legacy flat layout.
        }

        // Legacy flat layout (pre-v3.71.2): .proposals/{decisionId}.md only.
        try {
            $flat = $proposalsFolder->get($proposalFilename);
            if ($flat instanceof \OCP\Files\File) {
                $out[] = [
                    'file_id'     => $flat->getId(),
                    'name'        => $flat->getName(),
                    'mime'        => $flat->getMimeType() ?: 'text/markdown',
                    'size'        => $flat->getSize(),
                    'is_proposal' => true,
                ];
            }
        } catch (\OCP\Files\NotFoundException) {
            // Nothing to show.
        }

        return $out;
    }

    /**
     * v3.71.10 — read raw bytes of a proposal source file by fileId. Used
     * by the in-app read-only viewer. Authorisation: the file must live
     * inside a TeamHub team folder's .proposals/ subtree that the calling
     * user has read access to. We resolve the file via IRootFolder (which
     * already applies per-user ACLs), then walk up to verify the .proposals
     * ancestor — anything outside that subtree is rejected as 404.
     *
     * Returns [mime, name, content] or null when not accessible.
     *
     * @return array{mime:string, name:string, content:string}|null
     */
    public function getProposalSourceFileContent(int $fileId, string $actingUserId): ?array {
        // getById applies the user's mount table — if they can't see it,
        // we get an empty array.
        $userFolder = $this->rootFolder->getUserFolder($actingUserId);
        $nodes = $userFolder->getById($fileId);
        if (empty($nodes)) {
            $this->logger->debug('[TeamHub][DecisionService] getProposalSourceFileContent — file not accessible to user', [
                'file_id' => $fileId, 'user' => $actingUserId,
            ]);
            return null;
        }
        /** @var \OCP\Files\Node $node */
        $node = $nodes[0];
        if (!($node instanceof \OCP\Files\File)) {
            return null;
        }

        // Walk up the parent chain looking for a .proposals folder. If the
        // file isn't inside one, it's not a proposal source — refuse.
        $parent = $node->getParent();
        $foundProposalsAncestor = false;
        // Bound the walk: a real .proposals/{id}/file is two levels up; a
        // legacy flat one is one level up. Allow a few extra steps for
        // future structure changes but stop before traversing the whole
        // tree.
        for ($i = 0; $i < 6; $i++) {
            try {
                if ($parent->getName() === self::PROPOSALS_FOLDER) {
                    $foundProposalsAncestor = true;
                    break;
                }
                $parent = $parent->getParent();
            } catch (\Throwable) {
                break;
            }
        }
        if (!$foundProposalsAncestor) {
            $this->logger->warning('[TeamHub][DecisionService] getProposalSourceFileContent — file not inside .proposals/, refusing', [
                'file_id' => $fileId,
            ]);
            return null;
        }

        try {
            $content = $node->getContent();
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][DecisionService] getProposalSourceFileContent — read failed', [
                'file_id' => $fileId, 'error' => $e->getMessage(),
            ]);
            return null;
        }

        return [
            'mime'    => $node->getMimeType() ?: 'application/octet-stream',
            'name'    => $node->getName(),
            'content' => $content,
        ];
    }
}
