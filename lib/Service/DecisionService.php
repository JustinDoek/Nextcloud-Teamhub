<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\CommentMapper;
use OCA\TeamHub\Db\Decision;
use OCA\TeamHub\Db\DecisionMapper;
use OCA\TeamHub\Db\DecisionTeamConfigMapper;
use OCA\TeamHub\Db\MessageMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
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

    // v4.7.0 — schemes a browser will execute or inline if they reach an
    // `href` or an iframe `src`. See assertSourceRefSchemeSafe().
    private const SCRIPTABLE_SCHEMES = ['javascript', 'data', 'vbscript', 'file', 'blob'];

    // v4.5.42 — how a proposal was opened. See Decision's docblock and
    // Version000405042 for why 'immediate' is the backfill value.
    public const SHARE_IMMEDIATE = 'immediate';
    public const SHARE_SELECTED  = 'selected';
    public const SHARE_TEAM      = 'team';
    public const SHARE_MODES     = [self::SHARE_IMMEDIATE, self::SHARE_SELECTED, self::SHARE_TEAM];
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
        private TimezoneService          $timezoneService,
        private LoggerInterface          $logger,
        private \OCA\TeamHub\Db\MessageAttachmentMapper $attachmentMapper,
        // v3.97.5 — optional milestone linkage on proposals (Advanced projects).
        private \OCA\TeamHub\Db\MilestoneMapper $milestoneMapper,
        private \OCA\TeamHub\Db\ProjectMapper   $projectMapper,
        // v4.5.42 — who may see a `selected` proposal while it is open.
        private \OCA\TeamHub\Db\DecisionAudienceMapper $audienceMapper,
        // v4.5.42 — the discussion surfaces. TalkService does not depend on
        // this service, so there is no cycle.
        private TalkService $talkService,
        private \OCP\IURLGenerator $urlGenerator,
        // v4.5.44 — the Talk post's lead-in is user-facing text written by the
        // server. One chat message is read by everyone in the room, so there
        // is no per-recipient language to resolve; it is written in the
        // **proposer's** language, because they are its author.
        private \OCP\L10N\IFactory $l10nFactory,
    ) {}

    // =========================================================================
    // Gate enforcement (Session A)
    // =========================================================================

    public function assertModuleEnabledGlobally(): void {
        $globalEnabled = $this->config->getAppValue(
            Application::APP_ID,
            'decisions_module_enabled',
            '1'
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
        ?int    $milestoneId = null,
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
            } else {
                $this->assertSourceRefSchemeSafe($sourceRef);
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

        // v3.97.5 — validate optional milestone linkage. Milestones only
        // apply to Advanced project teams; passing a milestoneId on a
        // non-project or Basic-project team is a client bug we refuse
        // rather than silently dropping. The milestone must belong to the
        // same team — cross-team writes would be a security issue.
        if ($milestoneId !== null) {
            $project = $this->projectMapper->findByTeam($teamId);
            if ($project === null || $project->getMode() !== 'advanced') {
                throw new \InvalidArgumentException('milestoneId only applies to Advanced project teams');
            }
            $milestone = $this->milestoneMapper->findById($milestoneId);
            if ($milestone === null || $milestone->getTeamId() !== $teamId) {
                throw new \InvalidArgumentException('milestoneId does not point to a milestone in this team');
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
        $row->setMilestoneId($milestoneId);

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
    // Discussion phase (v4.5.42)
    // =========================================================================

    /**
     * Edit an open proposal's question and body.
     *
     * **This is what makes `open` a working state rather than a waiting room.**
     * Before 4.5.42 a proposal could not be changed after creation at all —
     * `refreshProposalDocument()` only rewrote the markdown from the row it
     * already had. A proposer who got feedback had no way to act on it except
     * to withdraw and re-propose, which loses the discussion.
     *
     * **Both halves live on the message.** `propose()` captures the decision's
     * question from `message.subject` (see the comment there — "subject is the
     * canonical decision question"), and the body has always been
     * `message.message`. So an edit writes the message row and mirrors the
     * subject into `decision.question`, which is a cache of it. Writing only
     * the decision row would leave the stream showing the old text.
     *
     * Authorisation: proposer only, and only while `open`. An admin override
     * is deliberately absent for the same reason `finalize()` has none — the
     * proposal is the proposer's statement, and an admin rewriting someone
     * else's proposal under their name is not a power this feature should
     * have. Admins can still `withdraw()`.
     *
     * @param ?string $question null leaves it unchanged
     * @param ?string $body     null leaves it unchanged
     * @return array Serialised decision
     */
    public function updateProposal(
        string  $teamId,
        int     $decisionId,
        ?string $question,
        ?string $body,
        string  $actingUserId,
    ): array {
        $this->assertModuleEnabledForTeam($teamId);
        $this->memberService->requireMemberLevel($teamId);

        $decision = $this->loadDecisionInTeam($decisionId, $teamId);
        $this->assertNotTerminal($decision);

        if ($decision->getStatus() !== 'open') {
            throw new \RuntimeException(
                'Only open proposals can be edited (current status: ' . $decision->getStatus() . ')'
            );
        }
        if ($decision->getProposedBy() !== $actingUserId) {
            throw new \RuntimeException('Only the proposer can edit this proposal');
        }

        $message = $this->messageMapper->find($decision->getMessageId());

        $newSubject = (string)($message['subject'] ?? '');
        $newBody    = (string)($message['message'] ?? '');
        $changed    = [];

        if ($question !== null) {
            $question = trim($question);
            if ($question === '') {
                throw new \InvalidArgumentException('Question cannot be empty');
            }
            if (mb_strlen($question) > self::MAX_QUESTION_LEN) {
                throw new \InvalidArgumentException('Question exceeds maximum length');
            }
            if ($question !== $newSubject) {
                $newSubject = $question;
                $changed[]  = 'question';
            }
        }

        if ($body !== null) {
            if (mb_strlen($body) > self::MAX_ANSWER_LEN) {
                throw new \InvalidArgumentException('Proposal body exceeds maximum length');
            }
            if ($body !== $newBody) {
                $newBody   = $body;
                $changed[] = 'body';
            }
        }

        if ($changed === []) {
            // Nothing to do, but still a successful call — the client sent
            // what it had and the server agreed it matched.
            return $this->serialize($decision);
        }

        // One write for both halves: MessageMapper::update takes subject and
        // body together, so there is no window where the stream shows a new
        // title over an old body.
        $this->messageMapper->update($decision->getMessageId(), $newSubject, $newBody);
        $decision->setQuestion($newSubject);

        /** @var Decision $saved */
        $saved = $this->decisionMapper->update($decision);

        // The proposal document is regenerated only when one already exists.
        // An open proposal normally has none — writeProposalDocument runs at
        // finalize — so this is a no-op in the common case and a correction in
        // the case where a proposal was finalized, reopened by a future
        // version, and edited.
        try {
            $this->writeProposalDocument($saved, $actingUserId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][DecisionService] updateProposal — document rewrite failed (non-fatal)', [
                'decision_id' => $decisionId, 'error' => $e->getMessage(),
            ]);
        }

        $this->auditService->log($saved, 'proposal_updated', $actingUserId, [
            'fields' => $changed,
        ]);

        $this->logger->info('[TeamHub][DecisionService] updateProposal', [
            'team_id' => $teamId, 'decision_id' => $decisionId, 'fields' => $changed,
        ]);

        return $this->serialize($saved);
    }

    /**
     * Finalize an open proposal using its own body as the final wording.
     *
     * **A second finalize path, and it is not a duplicate of `finalize()`.**
     * That one takes a *comment id*: the proposer clicks the gavel on their
     * own most recent comment and that comment becomes the final wording. It
     * is the right shape for a proposal discussed in TeamHub comments, and the
     * wrong shape for one discussed in Talk — where there is no TeamHub
     * comment to select, and demanding one would force the proposer to
     * re-type their conclusion into a comment box purely to satisfy the API.
     *
     * Here the *proposal body* is the final wording, exactly as it already is
     * for a proposal created with `autoFinalize`. The difference between the
     * two is only when the proposer says "this is my final text".
     *
     * Same authorisation as `finalize()`: proposer only, no admin override.
     *
     * @return array Serialised decision
     */
    public function finalizeProposal(
        string $teamId,
        int    $decisionId,
        string $actingUserId,
    ): array {
        $this->assertModuleEnabledForTeam($teamId);
        $this->memberService->requireMemberLevel($teamId);

        $decision = $this->loadDecisionInTeam($decisionId, $teamId);
        $this->assertNotTerminal($decision);

        if ($decision->getStatus() !== 'open') {
            throw new \RuntimeException(
                'Only open proposals can be finalized (current status: ' . $decision->getStatus() . ')'
            );
        }
        if ($decision->getProposedBy() !== $actingUserId) {
            throw new \RuntimeException('Only the proposer can finalize this proposal');
        }

        $message = $this->messageMapper->find($decision->getMessageId());
        $answer  = trim((string)($message['message'] ?? ''));
        if ($answer === '') {
            throw new \InvalidArgumentException(
                'The proposal has no text to finalize. Edit it first.'
            );
        }
        if (mb_strlen($answer) > self::MAX_ANSWER_LEN) {
            $answer = mb_substr($answer, 0, self::MAX_ANSWER_LEN);
        }

        $now = time();

        // Participants are the team's effective members at finalize time —
        // identical to finalize(). Deliberately NOT the discussion audience:
        // participants records who the decision applies to, which is the whole
        // team, while the audience records who was invited to draft it.
        $participantUids = [];
        foreach ($this->memberService->getAllEffectiveMembers($teamId) as $m) {
            if (!empty($m['userId'])) {
                $participantUids[] = (string)$m['userId'];
            }
        }
        $participantUids = array_values(array_unique($participantUids));
        sort($participantUids);

        $decision->setStatus('finalized');
        $decision->setAnsweredBy($actingUserId);
        $decision->setSelectedAnswer($answer);
        $decision->setParticipants(json_encode($participantUids, JSON_THROW_ON_ERROR));
        $decision->setResolvedBy($actingUserId);
        $decision->setDecidedAt($now);
        // selectedCommentId stays null: no comment was chosen, and writing one
        // would claim a provenance this path does not have.

        /** @var Decision $saved */
        $saved = $this->decisionMapper->update($decision);

        try {
            $this->writeProposalDocument($saved, $actingUserId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][DecisionService] finalizeProposal — document write failed (non-fatal)', [
                'decision_id' => $decisionId, 'error' => $e->getMessage(),
            ]);
        }

        $this->auditService->log($saved, 'finalized', $actingUserId, [
            'from'    => 'proposal_body',
            'excerpt' => $answer,
        ]);

        $this->logger->info('[TeamHub][DecisionService] finalizeProposal', [
            'team_id' => $teamId, 'decision_id' => $decisionId,
            'share_mode' => $saved->getShareMode(),
        ]);

        return $this->serialize($saved);
    }

    /**
     * May this user see this decision?
     *
     * Team membership is the baseline and every other path already enforces
     * it. This adds the one restriction 4.5.42 introduces: an **open**
     * proposal shared with a **selected** audience is visible only to the
     * proposer and that audience.
     *
     * Three deliberate narrowings:
     *  - Only `open`. Once finalized the decision is a team record — it goes
     *    to the category's approvers and into the team's history, so
     *    restricting it would hide a decision the team is subject to.
     *  - Only `selected`. `team` and `immediate` restrict nothing.
     *  - Approvers are **not** admitted. Justin's call: "only those that have
     *    been selected". The consequence is real and worth stating — an
     *    approver loses the early sight of an open proposal they get in the
     *    other modes (DecisionWorkProvider's WAITING_FOR_OTHERS row), and
     *    first sees it at finalize.
     *
     * **Fails closed.** A lookup that throws denies rather than allows: this
     * is the gate, not a display detail.
     */
    public function canViewDecision(Decision $decision, string $userId): bool {
        if ($decision->getStatus() !== 'open') {
            return true;
        }
        if ($decision->getShareMode() !== self::SHARE_SELECTED) {
            return true;
        }
        if ($decision->getProposedBy() === $userId) {
            return true;
        }

        try {
            return $this->audienceMapper->isInAudience($decision->getId(), $userId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][DecisionService] audience check failed — denying', [
                'decision_id' => $decision->getId(), 'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Open a proposal for discussion on a Talk surface.
     *
     * Two modes, and the split is the whole point of 4.5.42:
     *  - `selected` — a new Talk group conversation named after the proposal,
     *    with the chosen people in it. Only they and the proposer see the
     *    proposal while it is open.
     *  - `team` — a post in the team's own conversation, promoted to a thread
     *    where Talk supports it. Visible to the whole team, which is what an
     *    open proposal has always been.
     *
     * **Talk failure does not fail the share.** The proposal is already open
     * and editable; what a failed Talk call costs is the discussion venue, not
     * the proposal. The result carries the Talk outcome so the client can say
     * "shared, but the conversation could not be created" rather than pretend
     * either way. Same reasoning as the best-effort document write in
     * `finalize()`.
     *
     * @param string[] $userIds  people to invite; ignored unless mode is 'selected'
     * @return array{decision: array<string,mixed>, share: array<string,mixed>}
     */
    public function shareProposal(
        string $teamId,
        int    $decisionId,
        string $mode,
        array  $userIds,
        string $actingUserId,
    ): array {
        $this->assertModuleEnabledForTeam($teamId);
        $this->memberService->requireMemberLevel($teamId);

        if ($mode !== self::SHARE_SELECTED && $mode !== self::SHARE_TEAM) {
            throw new \InvalidArgumentException(
                'Share mode must be "' . self::SHARE_SELECTED . '" or "' . self::SHARE_TEAM . '"'
            );
        }

        $decision = $this->loadDecisionInTeam($decisionId, $teamId);
        if ($decision->getProposedBy() !== $actingUserId) {
            throw new \RuntimeException('Only the proposer can share this proposal');
        }
        if ($decision->getStatus() !== 'open') {
            throw new \RuntimeException('Only open proposals can be shared for discussion');
        }

        // MessageMapper::find() throws a bare \Exception when the row is gone,
        // which nothing maps — it would surface as a 500 with no message. A
        // decision always has a message, so this is a data-integrity failure,
        // but it deserves to say so rather than to be an opaque error.
        try {
            $message = $this->messageMapper->find($decision->getMessageId());
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][DecisionService] shareProposal — backing message missing', [
                'team_id' => $teamId, 'decision_id' => $decisionId,
                'message_id' => $decision->getMessageId(), 'exception' => $e,
            ]);
            throw new \RuntimeException('The message behind this proposal could not be read');
        }

        $title = trim((string)($message['subject'] ?? $decision->getQuestion()));
        $body  = trim((string)($message['message'] ?? ''));

        $post = $this->buildProposalPost($teamId, $decisionId, $title, $body, $actingUserId);

        $talkToken    = null;
        $talkThreadId = null;
        $share        = ['ok' => false, 'error' => ''];

        if ($mode === self::SHARE_SELECTED) {
            // Only real team members may be invited: the audience is a
            // visibility grant, so accepting an arbitrary uid here would let a
            // proposer show a team proposal to someone outside the team.
            $userIds = $this->filterToTeamMembers($teamId, $userIds, $actingUserId);
            if ($userIds === []) {
                throw new \InvalidArgumentException(
                    'Select at least one team member to discuss this with'
                );
            }

            $result = $this->talkService->createProposalRoom(
                $this->proposalRoomName($title),
                $userIds,
                $actingUserId,
                $post,
            );
            $talkToken = $result['token'];
            $share = [
                'ok'      => $result['ok'],
                'invited' => $result['invited'] ?? 0,
                'error'   => $result['error'],
            ];
        } else {
            $token = $this->teamTalkToken($teamId);
            if ($token === null) {
                throw new \RuntimeException(
                    'This team has no Talk conversation to post the proposal in'
                );
            }
            // The proposal's own title becomes the thread's subject, clipped
            // the same way a Talk room name is.
            $result = $this->talkService->startProposalThread(
                $token,
                $actingUserId,
                $post,
                $this->proposalRoomName($title),
            );
            $talkToken    = $result['ok'] ? $token : null;
            // The posted message's id — the id its thread takes as soon as
            // anyone replies. See startProposalThread().
            $talkThreadId = $result['threadId'];
            $share = [
                'ok'    => $result['ok'],
                // Restores the `share.threaded` fact of §2.77, which v4.5.46
                // dropped along with the false "threads unsupported" error.
                // It is now a checked `talk_threads` row rather than an
                // assumption, so it is safe for a caller to believe.
                'threaded' => $result['threaded'] ?? false,
                'error' => $result['error'],
            ];
        }

        // The persistence step is the one that must not fail silently: the
        // Talk surface may already exist, and a proposal whose share_mode was
        // not recorded would still be visible to the whole team while its
        // conversation is private to a few. Typed and logged with the full
        // exception so the cause is in the log rather than guessed at.
        try {
            $serialised = $this->recordDiscussion(
                $teamId, $decisionId, $mode, $talkToken, $talkThreadId, $userIds, $actingUserId,
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][DecisionService] shareProposal — could not record the discussion', [
                'team_id' => $teamId, 'decision_id' => $decisionId, 'mode' => $mode,
                'audience_size' => count($userIds), 'exception' => $e,
            ]);
            throw new \RuntimeException('The proposal was created but its sharing settings could not be saved');
        }

        $this->auditService->log(
            $this->loadDecisionInTeam($decisionId, $teamId),
            'shared_for_discussion',
            $actingUserId,
            ['mode' => $mode, 'talk_ok' => $share['ok']],
        );

        return ['decision' => $serialised, 'share' => $share];
    }

    /**
     * The text posted into Talk: a lead-in, the title, the body, and a way
     * back to the proposal.
     *
     * The lead-in exists because the post lands in a conversation among other
     * chat messages, where a bold line of text does not say what is being
     * asked of the reader. "Requesting feedback on a new proposal" does.
     *
     * Written in the **proposer's** language: a chat message is one message
     * for every reader, so there is no per-recipient language to resolve the
     * way a notification has. Falling back to the instance default when the
     * proposer's language cannot be read is fine — it is one sentence, and an
     * untranslated lead-in still reads.
     */
    /**
     * Refuse a sourceRef that could act as code once rendered.
     *
     * v4.7.0 — the contract note on this class has said "controllers must
     * validate" since the field was added, and no controller does.
     * `TeamDecisionsView` hands a ref whose type is `url` to
     * `openSourceUrl()`, which puts it in an `<a href>` **and** an
     * `<iframe src>`; a `javascript:` ref there is stored XSS in the
     * Nextcloud origin, executable by anyone who opens the decision.
     *
     * It is not reachable through propose() today, because
     * ALLOWED_SOURCE_TYPES does not contain 'url' — but that is an accident
     * of a different allowlist rather than a defence of this field, and the
     * render path stays live for legacy rows that already carry that type.
     * Guideline 12 is explicit that a check is worth making where it looks
     * unnecessary.
     *
     * Deliberately narrower than the strict http(s) allowlist that
     * `DecisionExternalLinkService::validateUrl()` applies: that field is
     * definitionally a URL, this one is not. A ref may legitimately be a bare
     * message id, a `.proposals/…md` path, or free text a proposer typed — so
     * only a scriptable scheme, or a non-http(s) authority-form URL, is
     * refused. Text that merely contains a colon still passes.
     */
    private function assertSourceRefSchemeSafe(string $sourceRef): void {
        // Leading scheme per RFC 3986: ALPHA *( ALPHA / DIGIT / "+" / "-" / "." ) ":"
        //
        // Control characters are stripped anywhere in the string, because a
        // browser ignores them inside a scheme — `java\tscript:` dispatches as
        // `javascript:`. Spaces are NOT stripped, and that distinction matters:
        // stripping them too would join `see http://x.com` into
        // `seehttp://x.com`, which reads as a scheme and would refuse a
        // perfectly legitimate free-text ref. A space cannot appear inside a
        // real scheme, so leaving it in is what keeps the match honest.
        // Leading whitespace is trimmed so a padded `  javascript:` cannot
        // dodge the anchor (propose() already trims, but this method must not
        // depend on its caller for that).
        $probe = ltrim((string)preg_replace('/[\x00-\x1F\x7F]/', '', $sourceRef));
        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $probe, $m) !== 1) {
            return; // no scheme — an id, a relative path, or prose
        }
        $scheme = strtolower($m[1]);
        if (in_array($scheme, self::SCRIPTABLE_SCHEMES, true)) {
            throw new \InvalidArgumentException('sourceRef may not use the "' . $scheme . ':" scheme');
        }
        // Anything written as `scheme://…` is a URL and must be http(s).
        if (str_contains($probe, '://') && !in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('sourceRef must be an http(s) URL');
        }
    }

    private function buildProposalPost(
        string $teamId,
        int    $decisionId,
        string $title,
        string $body,
        string $actingUserId,
    ): string {
        $l = $this->l10nFactory->get(
            Application::APP_ID,
            $this->l10nFactory->getUserLanguage($this->userManager->get($actingUserId)),
        );

        // TRANSLATORS: lead-in of the Talk message posted when a decision
        // proposal is shared for discussion. The proposal title follows.
        $lines = [$l->t('Requesting feedback on a new proposal:')];
        $lines[] = '';
        $lines[] = '**' . $title . '**';
        if ($body !== '') {
            $lines[] = '';
            $lines[] = $body;
        }
        $lines[] = '';
        // Same shape MessageService and MemberService already use for team
        // deep links, plus the decision target the Decisions tab reads.
        $lines[] = $this->urlGenerator->linkToRouteAbsolute('teamhub.page.index')
            . '?team=' . urlencode($teamId)
            . '&tab=decisions&decision=' . $decisionId;

        return implode("\n", $lines);
    }

    /**
     * Talk conversation names are shown in a list; a full proposal question
     * would be unreadable there. Clipped on a word boundary where one is
     * available.
     */
    private function proposalRoomName(string $title): string {
        $name = trim($title) !== '' ? trim($title) : 'Decision proposal';
        if (mb_strlen($name) <= 64) {
            return $name;
        }
        $clipped = mb_substr($name, 0, 61);
        $space   = mb_strrpos($clipped, ' ');
        if ($space !== false && $space > 30) {
            $clipped = mb_substr($clipped, 0, $space);
        }
        return $clipped . '…';
    }

    /**
     * Keep only uids that are effective members of this team, minus the actor.
     *
     * The audience is a visibility grant, so this is a security check and not
     * a convenience: without it a proposer could name any uid on the instance
     * and hand them a team proposal.
     *
     * @param string[] $userIds
     * @return string[]
     */
    private function filterToTeamMembers(string $teamId, array $userIds, string $actingUserId): array {
        $allowed = [];
        foreach ($this->memberService->getAllEffectiveMembers($teamId) as $m) {
            if (!empty($m['userId'])) {
                $allowed[(string)$m['userId']] = true;
            }
        }

        return array_values(array_unique(array_filter(
            $userIds,
            static fn ($u): bool => is_string($u)
                && $u !== ''
                && $u !== $actingUserId
                && isset($allowed[$u]),
        )));
    }

    /** The team's Talk room token, or null when the team has no conversation. */
    private function teamTalkToken(string $teamId): ?string {
        try {
            $resources = $this->resourceService->getTeamResources($teamId);
            $token     = $resources['talk']['token'] ?? null;
            return is_string($token) && $token !== '' ? $token : null;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][DecisionService] teamTalkToken lookup failed', [
                'team_id' => $teamId, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Talk rooms belonging to open proposals this viewer may see (v4.5.45).
     *
     * What's New reaches Talk rooms through team membership, and a proposal
     * shared with a *selected* audience gets a conversation of its own that no
     * team owns — deliberately, since registering it as a team resource would
     * drop every proposal room into that team's resource-review queue. Without
     * this the discussion would be invisible in the feed to the very people
     * who were invited to it.
     *
     * `team`-mode proposals are **not** included: they are posted in the team's
     * own conversation, which the feed already resolves. Returning them would
     * hand back a token the caller already has.
     *
     * Visibility is applied here, not by the caller: same rule as
     * `canViewDecision()` — proposer or named audience.
     *
     * @param string[] $teamIds teams the viewer belongs to
     * @return array<int, array{token:string, teamId:string}>
     */
    public function openProposalTalkRooms(array $teamIds, string $viewerUid): array {
        if ($teamIds === [] || $viewerUid === '') {
            return [];
        }

        try {
            $rows = $this->decisionMapper->findOpenSharedProposals($teamIds);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][DecisionService] openProposalTalkRooms failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $out = [];
        foreach ($rows as $decision) {
            $token = $decision->getTalkToken();
            if (!is_string($token) || $token === '') {
                continue;
            }
            if ($decision->getShareMode() !== self::SHARE_SELECTED) {
                continue;
            }
            if (!$this->canViewDecision($decision, $viewerUid)) {
                continue;
            }
            $out[] = ['token' => $token, 'teamId' => $decision->getTeamId()];
        }

        return $out;
    }

    /**
     * Record how an open proposal is being discussed.
     *
     * Called by DecisionController after the Talk surface has been created, so
     * a Talk failure leaves the proposal open with no discussion link rather
     * than failing the whole creation. The audience is written here too,
     * because it is meaningless without the mode.
     *
     * @param string[] $audienceUserIds ignored unless $shareMode is 'selected'
     */
    public function recordDiscussion(
        string  $teamId,
        int     $decisionId,
        string  $shareMode,
        ?string $talkToken,
        ?int    $talkThreadId,
        array   $audienceUserIds,
        string  $actingUserId,
    ): array {
        $this->assertModuleEnabledForTeam($teamId);
        $this->memberService->requireMemberLevel($teamId);

        if (!in_array($shareMode, self::SHARE_MODES, true)) {
            throw new \InvalidArgumentException('Unknown share mode: ' . $shareMode);
        }

        $decision = $this->loadDecisionInTeam($decisionId, $teamId);
        if ($decision->getProposedBy() !== $actingUserId) {
            throw new \RuntimeException('Only the proposer can change how this proposal is shared');
        }
        if ($decision->getStatus() !== 'open') {
            throw new \RuntimeException('Only open proposals have a discussion phase');
        }

        $decision->setShareMode($shareMode);
        $decision->setTalkToken($talkToken);
        $decision->setTalkThreadId($talkThreadId);

        /** @var Decision $saved */
        $saved = $this->decisionMapper->update($decision);

        if ($shareMode === self::SHARE_SELECTED) {
            // The proposer is implicit — canViewDecision() admits them by
            // proposed_by, so storing them would be a second source of truth
            // for the same fact.
            $this->audienceMapper->replaceAudience(
                $decisionId,
                array_values(array_filter(
                    $audienceUserIds,
                    static fn ($u): bool => is_string($u) && $u !== '' && $u !== $actingUserId,
                )),
            );
        } else {
            // Switching away from `selected` drops the restriction, so the
            // rows would be a stale claim about who can see it.
            $this->audienceMapper->deleteForDecision($decisionId);
        }

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
        // the timeline retains both moments — v4.5.25 records the second one
        // in its own column instead, because My Work's Completed section needs
        // to know when this was *resolved*, and a decision finalized weeks ago
        // but approved today was falling outside the retention window.
        $decision->setResolvedAt(time());

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
        // v4.5.25 — same reason as approve(): the moment it was resolved, which
        // decidedAt (the finalize moment) does not record.
        $decision->setResolvedAt($now);

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
                    throw new AccessDeniedException('Not authorized: you are not an approver for category ' . $categoryName);
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

        // 4. Decide layout: new finalizations use {decisionId}/{subject}.md
        //    (subfolder per decision, so attachments can be copied alongside).
        //    Legacy decisions finalized before v3.71.2 wrote {decisionId}.md
        //    flat at the .proposals/ root — if such a flat file exists for
        //    this decision, keep using the flat layout on regen so we never
        //    orphan content. New decisions always use the subfolder layout.
        $legacyFilename = $d->getId() . '.md';
        $legacyFlatPath = self::PROPOSALS_FOLDER . '/' . $legacyFilename;
        $legacyFlatExists = false;
        try {
            $proposalsFolder->get($legacyFilename);
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
            return $this->writeFileInto($proposalsFolder, $legacyFilename, $markdown, $legacyFlatPath);
        }

        // New layout: .proposals/{id}/{subject-slug}.md
        $subfolderName = (string)$d->getId();
        try {
            $subfolder = $proposalsFolder->get($subfolderName);
            if (!($subfolder instanceof \OCP\Files\Folder)) {
                throw new \RuntimeException('Proposal subfolder path exists but is not a folder');
            }
        } catch (\OCP\Files\NotFoundException) {
            $subfolder = $proposalsFolder->newFolder($subfolderName);
        }

        $filename = $this->proposalFilename($d);

        // On regen the question may have changed, producing a new filename.
        // Remove any previous proposal .md so we don't orphan the old file.
        // Attachments (images, PDFs) are left untouched.
        foreach ($subfolder->getDirectoryListing() as $node) {
            if ($node instanceof \OCP\Files\File
                && $node->getName() !== $filename
                && str_ends_with($node->getName(), '.md')) {
                try {
                    $node->delete();
                } catch (\Throwable $e) {
                    $this->logger->warning('[TeamHub][DecisionService] Could not remove old proposal file', [
                        'old_name' => $node->getName(),
                        'error'    => $e->getMessage(),
                    ]);
                }
            }
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

    private function proposalFilename(Decision $d): string {
        $question = $d->getQuestion() ?? '';
        $slug = preg_replace('/[^\p{L}\p{N}\s\-_]/u', '', $question);
        $slug = preg_replace('/[\s_]+/', '-', trim($slug));
        $slug = mb_substr($slug, 0, 80);
        $slug = rtrim($slug, '-');
        if ($slug === '') {
            $slug = (string)$d->getId();
        }
        return $slug . '.md';
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
                // proposal file and to preserve the user's intended name.
                $destName = $this->sanitizeAttachmentName($row->getFileName() ?: ('attachment-' . $fileId));
                // Don't ever overwrite the proposal markdown.
                $proposalName = $this->proposalFilename($d);
                if ($destName === $proposalName) {
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

        // Dates are rendered in the proposer's timezone. The document is a
        // single shared artifact regenerated on every change, so there is no
        // per-viewer choice to make; the proposer is the stable anchor and
        // matches how the closing artifact attributes its dates. Bare date()
        // would have used the server's UTC day for everyone.
        $proposerUid = (string)$d->getProposedBy();
        $iso = fn(int $ts) => $this->timezoneService->formatTimestamp($ts, $proposerUid, 'Y-m-d H:i');
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
        string $viewerUid = '',
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

        // v4.5.42 — the Decisions tab is the third surface a restricted open
        // proposal could appear on, after the feed and the stream. Filtered
        // after the query rather than in it: the audience lives in its own
        // table and the mapper's cursor pagination is built around a plain id
        // ordering that a join would complicate for one page's worth of rows.
        //
        // The consequence is worth stating: a page can come back short when
        // restricted proposals are filtered out of it, and `nextBefore` is
        // still computed from the **unfiltered** row count so pagination does
        // not stall. A viewer excluded from several proposals sees a slightly
        // smaller page, never a truncated list.
        $items = [];
        foreach ($rows as $r) {
            if (!$this->canViewDecision($r, $viewerUid)) {
                continue;
            }
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
    public function get(string $teamId, int $decisionId, string $viewerUid = ''): array {
        $this->assertModuleEnabledForTeam($teamId);
        $this->memberService->requireMemberLevel($teamId);

        $decision = $this->loadDecisionInTeam($decisionId, $teamId);

        // v4.5.42 — the authoritative audience gate. The feed and the stream
        // filter lists; this is the one that stops a direct fetch by id, and
        // without it the other two would be decoration.
        //
        // "Decision not found" rather than "forbidden" on purpose: the caller
        // is a team member who has no way to know the proposal exists, and a
        // 403 would confirm that it does.
        //
        // The viewer is passed in rather than read from the session, matching
        // every mutating method on this service. An empty uid therefore fails
        // the audience test rather than passing it — a caller that forgets to
        // identify itself gets nothing.
        if (!$this->canViewDecision($decision, $viewerUid)) {
            throw new \RuntimeException('Decision not found');
        }

        $serialised = $this->serialize($decision);

        // v4.5.42 — the current proposal text, for the proposer's editor.
        //
        // Deliberately here and not in serialize(): the body lives on the
        // backing message, so including it there would cost one extra query
        // per row in list() and in every feed hydration, for a field only the
        // detail panel reads. `selectedAnswer` is not a substitute — it is
        // null until finalize, which is exactly the window the editor is for.
        try {
            $message = $this->messageMapper->find($decision->getMessageId());
            $serialised['proposalBody'] = (string)($message['message'] ?? '');
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][DecisionService] proposal body lookup failed', [
                'decision_id' => $decisionId, 'error' => $e->getMessage(),
            ]);
            $serialised['proposalBody'] = null;
        }

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

        // v3.97.5 — resolve the linked milestone once so the frontend can
        // render the chip without a second fetch. Soft link: if the
        // milestone has been deleted, the row's milestone_id remains but
        // label/date resolve to null and the frontend hides the chip.
        $milestoneLabel = null;
        $milestoneDate  = null;
        $milestoneId    = $d->getMilestoneId();
        if ($milestoneId !== null) {
            try {
                $milestone = $this->milestoneMapper->findById($milestoneId);
                if ($milestone !== null && $milestone->getTeamId() === $d->getTeamId()) {
                    $milestoneLabel = $milestone->getLabel();
                    $ts = $milestone->getMilestoneDate();
                    $milestoneDate = $ts !== null
                        ? (new \DateTimeImmutable('@' . $ts))->format('Y-m-d')
                        : null;
                }
            } catch (\Throwable) {
                // Silent — soft link. Chip stays hidden.
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
            // v4.5.25 — when it was approved/denied, as opposed to finalized.
            'resolvedAt'          => $d->getResolvedAt(),
            'withdrawnAt'         => $d->getWithdrawnAt(),
            'milestoneId'         => $milestoneId,
            'milestoneLabel'      => $milestoneLabel,
            'milestoneDate'       => $milestoneDate,
            // v4.5.42 — the discussion phase. `audience` is resolved only for
            // `selected` proposals: for the other two modes there are no rows,
            // and returning [] would read as "shared with nobody" rather than
            // "visibility is not restricted".
            'shareMode'           => $d->getShareMode(),
            'talkToken'           => $d->getTalkToken(),
            'talkThreadId'        => $d->getTalkThreadId(),
            'audience'            => $d->getShareMode() === self::SHARE_SELECTED
                ? $this->safeAudience($d->getId())
                : null,
        ];
    }

    /**
     * Audience user ids, or [] if the lookup fails.
     *
     * Serialisation must not throw: a decision whose audience cannot be read
     * still needs to render. The *visibility* decision never comes from here —
     * `canViewDecision()` does its own lookup and fails closed.
     *
     * @return string[]
     */
    private function safeAudience(int $decisionId): array {
        try {
            return $this->audienceMapper->findUserIds($decisionId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][DecisionService] audience lookup failed', [
                'decision_id' => $decisionId, 'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * List the files in .proposals/{decisionId}/ for the Source heading
     * in the Decisions detail panel.
     *
     * Returns an array of { file_id, name, mime, size, is_proposal }
     * where is_proposal=true marks the canonical .md proposal file
     * (named by sanitised question since v3.79.1, or {decisionId}.md before).
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

        $legacyFilename = $decisionId . '.md';
        $out = [];

        // Subfolder layout (preferred — new finalizations).
        // The canonical proposal is the single .md file in the subfolder
        // (named by sanitised question since v3.79.1, or {id}.md before).
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
                        'is_proposal' => str_ends_with($name, '.md'),
                    ];
                }
                return $out;
            }
        } catch (\OCP\Files\NotFoundException) {
            // No subfolder — try legacy flat layout.
        }

        // Legacy flat layout (pre-v3.71.2): .proposals/{decisionId}.md only.
        try {
            $flat = $proposalsFolder->get($legacyFilename);
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
