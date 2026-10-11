<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\Decision;
use OCA\TeamHub\Db\DecisionAudit;
use OCA\TeamHub\Db\DecisionAuditMapper;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Decisions audit trail service (Session J).
 *
 * Append-only log of lifecycle transitions on a decision. Called from
 * inside DecisionService at each transition (propose/finalize/withdraw/
 * approve/deny) and from CommentController on comments to decision
 * messages.
 *
 * The transition vocabulary is closed:
 *   proposed | commented | finalized | withdrawn | approved | denied
 *
 * Any new transition added later goes through this same gate.
 *
 * Failure mode: logging is best-effort. A failed audit write must not
 * abort the originating action — the operational record is more valuable
 * than the audit guarantee. Failures are logged at warning level.
 */
class DecisionAuditService {

    public const TRANSITIONS = [
        'proposed', 'commented', 'finalized', 'withdrawn', 'approved', 'denied',
        // Session C — link/unlink events
        'task_linked', 'task_unlinked', 'decision_linked', 'decision_unlinked',
        // v3.74.10 — approver meeting on a proposal
        'approver_meeting_scheduled',
        // v3.75.2 — external decision links (outbound URLs)
        'external_link_added', 'external_link_removed',
        // v4.5.42 — the discussion phase. The gate below *silently drops*
        // unknown transitions (a warning, not a throw), so a transition added
        // to DecisionService without being added here writes nothing and
        // nobody finds out. Both of these were doing exactly that.
        'proposal_updated', 'shared_for_discussion',
        // v4.11.18 — the review and retirement cycle. Justin: every step of
        // a review goes into the audit stream, so all six are here and each
        // is written by exactly one place in `DecisionReviewService`:
        //   review_requested — a member asked, with their motivation
        //   review_due       — the period fell due; the approvers were told
        //   review_kept      — a reviewer let the decision stand
        //   review_archived  — a reviewer retired it
        //   superseded       — a newer decision took over, naming it
        //   archived         — the row moved into the archive category
        // `archived` is written alongside `review_archived` or `superseded`
        // rather than instead of them: the first says what happened to the
        // record, the second says why, and a reader of the stream wants both.
        'review_requested', 'review_due', 'review_kept', 'review_archived',
        'superseded', 'archived',
        // v4.11.24 — a requested review is a request, so it is answered
        // `denied` or `accepted` rather than kept or archived:
        //   review_denied   — the decision stands as it is
        //   review_accepted — the decision is to be altered, and
        //   revised         — what that did to the record: the wording was
        //                     rewritten in place, with the previous text in
        //                     the payload. Two lines, like `superseded` and
        //                     `archived`: one says why, one says what.
        //
        // v4.11.26 — `reopened` is **not** in this list and must not come
        // back. It was the 4.11.24 pairing for `review_accepted`, when
        // accepting sent the decision to `open` to be redrafted; that handed
        // an approved decision back to a lifecycle whose `denied` is
        // terminal, and a review that can destroy the decision it was asked
        // about is the bug this version fixes. Rows written by 4.11.24 keep
        // their `reopened` line — the panel still renders the label — but
        // nothing writes another one, and `log()` would now drop it.
        'review_denied', 'review_accepted', 'revised',
        // v4.11.31 — `supersedes` is the *other* half of a supersede, and
        // the half that was missing. `superseded` is written against the
        // decision that stops governing; this one is written against the
        // decision that takes over, so the arc is readable from either end.
        // Justin, 2026-10-06: *when we supersede a decision this is visible
        // on the superseded decision, but not on the decision that
        // supersedes it.*
        //
        // Two rows for one act, like `superseded` and `archived` — but these
        // two sit on *different decisions*, which is the point: an audit
        // trail is per decision, and a reader of the newer one was being
        // told nothing at all.
        'supersedes',
        // v4.11.31 — publishing. These two have been *written* since
        // v4.11.12 and dropped on the floor ever since: `setPublic()` calls
        // `log()` with them and `log()` silently discards a transition that
        // is not in this list. Exactly the failure the v4.5.42 note above
        // describes, which is why that note is there.
        'published', 'unpublished',
        // v4.11.30 — a decision filed under the wrong category, moved into
        // the right one by an approver of the one it was in. The payload
        // carries `from` and `to`, because "moved" without them cannot
        // answer the question it invites, and `category` on the row now
        // holds only the second of the two.
        'category_moved',
    ];

    private const MAX_COMMENT_EXCERPT_LEN = 200;

    public function __construct(
        private DecisionAuditMapper $auditMapper,
        private IUserManager        $userManager,
        private LoggerInterface     $logger,
    ) {}

    /**
     * Append an audit row. The payload is transition-specific (see
     * migration docblock). Pass null when the transition has no payload
     * (proposed, approved).
     *
     * @param array<string,mixed>|null $payload
     */
    public function log(Decision $decision, string $transition, string $actor, ?array $payload = null): void {
        if (!in_array($transition, self::TRANSITIONS, true)) {
            $this->logger->warning('[TeamHub][DecisionAuditService] Unknown transition rejected', [
                'transition'  => $transition,
                'decision_id' => $decision->getId(),
            ]);
            return;
        }

        try {
            $row = new DecisionAudit();
            $row->setTeamId($decision->getTeamId());
            $row->setDecisionId($decision->getId());
            $row->setTransition($transition);
            $row->setActor($actor);
            $row->setPayloadJson(
                $payload === null
                    ? null
                    : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            );
            $row->setCreatedAt(time());

            $this->auditMapper->insert($row);

            $this->logger->debug('[TeamHub][DecisionAuditService] logged', [
                'decision_id' => $decision->getId(),
                'transition'  => $transition,
                'actor'       => $actor,
            ]);
        } catch (\Throwable $e) {
            // Audit failure must NEVER undo the action that triggered it.
            $this->logger->warning('[TeamHub][DecisionAuditService] log failed (non-fatal)', [
                'decision_id' => $decision->getId(),
                'transition'  => $transition,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Convenience for comment events — builds the standard payload from a
     * comment row and clips the excerpt to a manageable length.
     */
    public function logComment(Decision $decision, int $commentId, string $authorUid, string $commentText): void {
        $excerpt = trim($commentText);
        if (mb_strlen($excerpt) > self::MAX_COMMENT_EXCERPT_LEN) {
            $excerpt = mb_substr($excerpt, 0, self::MAX_COMMENT_EXCERPT_LEN) . '…';
        }
        $this->log($decision, 'commented', $authorUid, [
            'comment_id' => $commentId,
            'excerpt'    => $excerpt,
        ]);
    }

    /**
     * List all audit events for a decision, ordered oldest-first, in the
     * wire format consumed by the timeline UI.
     *
     * Each entry includes the actor's display name (resolved live — we
     * don't denormalise display names in the audit row because they can
     * change and the audit trail should reflect "who they are today" the
     * same way other parts of the app do).
     *
     * @return array<int, array{
     *   id: int,
     *   transition: string,
     *   actor: string,
     *   actorDisplayName: string,
     *   payload: array<string,mixed>|null,
     *   createdAt: int
     * }>
     */
    public function listForDecision(int $decisionId): array {
        $rows = $this->auditMapper->findByDecisionId($decisionId);
        $out  = [];
        foreach ($rows as $row) {
            $payload = null;
            $raw = $row->getPayloadJson();
            if ($raw !== null && $raw !== '') {
                try {
                    $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    $payload = null;
                }
            }
            $user = $this->userManager->get($row->getActor());
            $out[] = [
                'id'               => $row->getId(),
                'transition'       => $row->getTransition(),
                'actor'            => $row->getActor(),
                'actorDisplayName' => $user !== null ? ($user->getDisplayName() ?: $row->getActor()) : $row->getActor(),
                'payload'          => $payload,
                'createdAt'        => $row->getCreatedAt(),
            ];
        }
        return $out;
    }
}
