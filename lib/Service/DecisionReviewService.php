<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\Decision;
use OCA\TeamHub\Db\DecisionMapper;
use OCA\TeamHub\Db\DecisionReview;
use OCA\TeamHub\Db\DecisionReviewMapper;
use OCA\TeamHub\Util\ReviewSchedule;
use OCP\ICacheFactory;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Reviewing, superseding and archiving an approved decision (v4.11.18).
 *
 * ## What this is for
 *
 * A decision that has been approved governs until something changes it.
 * Before this version nothing could: `approved` is terminal, and the only
 * supersede the module had was a field on a *new* proposal that auto-withdrew
 * an old one while it was still open — which is why the button appeared on
 * open proposals and nowhere else. Three things change that, and all three
 * end in the same place:
 *
 *  - **A review somebody asks for.** Circumstances changed; any team member
 *    may put the decision back in front of the category's approvers with
 *    their reasons.
 *  - **A review the calendar asks for.** A category may carry a review
 *    period; `DecisionReviewJob` opens the review two weeks before it falls
 *    due and the approvers find it in My Work.
 *  - **A supersede.** A newer decision overrules an older one, and an
 *    approver of the older one's category says so.
 *
 * ## The rule that shapes the whole file
 *
 * **A review never un-approves the decision.** The row keeps `approved`,
 * keeps its `resolved_by` and `resolved_at`, and keeps governing while the
 * review runs — Justin, 2026-10-03: *it stays final until a decision has
 * been made on the review*. What the review produces is one of four
 * outcomes, and only the last of them takes the decision out of force:
 *
 *  - `denied`   — the request is refused; the decision stands as it is and
 *                 the review clock restarts. Justin, 2026-10-05: *deny means
 *                 the original decision stands*.
 *  - `kept`     — the periodic twin of `denied`: nothing to change.
 *  - `accepted` — the request is granted and the wording is rewritten **in
 *                 place**. Still `approved`, still the same approval; the
 *                 team is never left without a decision in force.
 *  - `archived` — the decision is retired: `status = archived`, moved into
 *                 the team's Archive category, read-only.
 *
 * **Nothing here can turn an approved decision back into a proposal**, and
 * v4.11.26 means it this time. v4.11.24 made `accepted` reopen the decision
 * to `open` for redrafting, which handed it back to the proposal lifecycle —
 * where `deny()` and `withdraw()` are reachable and terminal. A standing
 * decision could be destroyed by answering a review, approval and all:
 * Marketing #11 on the test instance went approved → review accepted →
 * reopened → finalized → **denied** in four clicks. `reviseApproved()`
 * replaces that, and `review_accepted` + `revised` replace
 * `review_accepted` + `reopened`.
 *
 * If a team wants a *different* decision rather than different wording, they
 * propose one and supersede this one — a record of two decisions, not one
 * decision that changed its mind (Justin's example: dropping pens entirely
 * supersedes which pen colours to ship; changing the colours is the same
 * decision altered).
 *
 * ## Who may do what
 *
 *  - **Request a review** — any member of the team. The spec's words: *any
 *    team member*. Asking is not deciding, so it is not gated on the
 *    category's approver list, and it is deliberately not gated on
 *    `decisions_action_min_level` either — that gate is for *writing* on a
 *    decision (links, publishing), and a review request writes nothing about
 *    the decision, it writes a question about it.
 *  - **Complete a review, and supersede** — an approver of the decision's
 *    category, or a team admin where the category has no approver list.
 *    Exactly `DecisionService::assertApproverFor()`, which is public for
 *    this reason: retiring a decision is the mirror of approving it, so the
 *    same hands, decided by the same authority rather than a third copy of
 *    the rule.
 *
 * Either-one, like approval: any single approver's answer completes the
 * review. A category whose approvers disagree settles it between themselves,
 * which is the same thing the module already assumes about approving.
 *
 * ## Every step is in the audit stream
 *
 * Justin's requirement, and the reason the transitions live in
 * `DecisionAuditService::TRANSITIONS` rather than being invented here: that
 * list silently drops anything it does not recognise, so a transition added
 * in this file and not there would write nothing and nobody would find out
 * (v4.5.42 learnt that twice).
 *
 * ## My Work is the surface
 *
 * There is no review page. The approver's task is a row in My Work
 * (`DecisionWorkProvider`) — *Keep* and *Archive* on a periodic review,
 * *Deny request* on a requested one, with accepting left to the decision
 * panel because it is a rewrite and not a verb. The requester's task is the
 * same subject under *Waiting for others*, and both rows carry the whole
 * workflow in `metadata.workflow` — the shape in `/mywork-workflows`. The
 * bell (`DecisionReviewNotificationService`) is how somebody learns the row
 * appeared; it is never the workflow.
 */
class DecisionReviewService {

    /** The motivation a member writes with a review request. */
    public const MAX_MOTIVATION_LEN = 1000;

    /** A reviewer's reason for the answer they gave. Required since v4.11.23. */
    public const MAX_REASON_LEN = 1000;

    /**
     * The wording a review request asks the decision to carry (v4.11.30).
     *
     * 4000, matching `DecisionService::MAX_QUESTION_LEN` and
     * `MAX_ANSWER_LEN` — this is a proposal *for* those two fields, and a
     * cap the decision itself would refuse would let a requester ask for
     * something no accept could ever write.
     */
    public const MAX_PROPOSED_LEN = 4000;

    public const OUTCOMES = [
        DecisionReview::OUTCOME_DENIED,
        DecisionReview::OUTCOME_ACCEPTED,
        DecisionReview::OUTCOME_KEPT,
        DecisionReview::OUTCOME_ARCHIVED,
    ];

    public function __construct(
        private DecisionMapper        $decisionMapper,
        private DecisionReviewMapper  $reviewMapper,
        private DecisionService       $decisionService,
        private DecisionCategoryService $categoryService,
        private DecisionAuditService  $auditService,
        private DecisionReviewNotificationService $notifications,
        private MemberService         $memberService,
        private IUserManager          $userManager,
        // v4.11.21 — My Work caches per viewer, and a review changes whose
        // queue holds what. See `bumpQueues()` for why this is here and not
        // left to the notification service.
        private ICacheFactory         $cacheFactory,
        private LoggerInterface       $logger,
    ) {}

    /**
     * Make everybody whose My Work changed re-read it.
     *
     * **This is not the notification service's job, and tying it to that was
     * the bug** (found 2026-10-04): `DecisionReviewNotificationService`
     * bumps the cache of each recipient it sends to, which sounds equivalent
     * and is not, because notifications deliberately skip people —
     *
     *  - the person who caused the event never gets a bell about it, so an
     *    approver who is also the requester got no bump and kept seeing the
     *    old queue;
     *  - a periodic review has no requester, so completing one notified
     *    nobody and therefore invalidated nothing;
     *  - the approver who answers a review is never a recipient of the
     *    answer, so their own *Action required* row stayed until the TTL.
     *
     * A notification is a courtesy with rules about who deserves one. A
     * cache bump is a fact about which stored answers are now wrong, and it
     * has no rules: everyone whose queue could have changed gets one,
     * including the actor.
     *
     * `/mywork-workflows` rule 6 says every step must bump the other side's
     * cache; the thing worth writing down is that "the other side" includes
     * this side.
     *
     * @param string[] $uids
     */
    private function bumpQueues(array $uids): void {
        foreach (array_unique(array_filter($uids, static fn ($u): bool => is_string($u) && $u !== '')) as $uid) {
            MyWorkService::bumpNonce($this->cacheFactory, $uid);
        }
    }

    // =========================================================================
    // Reads
    // =========================================================================

    /**
     * Every review of a decision, newest first — the decision panel's
     * review history and the audit stream's companion.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForDecision(string $teamId, int $decisionId): array {
        $this->decisionService->assertModuleEnabledForTeam($teamId);
        $this->memberService->requireMemberLevel($teamId);

        // Scope check: the id must be a decision of this team, or a member of
        // team A could read team B's reviews by guessing an id.
        $this->loadDecision($teamId, $decisionId);

        return array_map(
            fn (DecisionReview $r): array => $this->serialize($r),
            $this->reviewMapper->findByDecision($decisionId),
        );
    }

    /**
     * The open review of a decision, or null. Unauthenticated-safe in the
     * sense that matters: it is only ever called behind a membership check.
     */
    public function openReviewFor(int $decisionId): ?DecisionReview {
        return $this->reviewMapper->findOpenForDecision($decisionId);
    }

    /**
     * The open reviews in a set of teams, for My Work.
     *
     * @param string[] $teamIds
     * @return DecisionReview[]
     */
    public function openReviewsForTeams(array $teamIds): array {
        return $this->reviewMapper->findOpenForTeams($teamIds);
    }

    // =========================================================================
    // Request a review
    // =========================================================================

    /**
     * Ask the category's approvers to look at an approved decision again.
     *
     * **v4.11.30 — the request may carry what it is asking for.** Justin,
     * 2026-10-05: *the review requester should give the correct wording and
     * if needed attachment.* A proposal has a text and an attachment, and
     * the attachment is commonly where the substance is; a review that could
     * only carry prose about it left the approver writing wording they had
     * not been given and unable to touch the file at all.
     *
     * All four of the new arguments are optional, and a request with none of
     * them is still a review — that is the only shape 4.11.18 had, and *look
     * at this again* remains a thing a member may legitimately ask without
     * having an answer ready. What the proposal changes is the approver's
     * side: with wording on the row they are offered a one-click **Approve**,
     * without it they are sent to the wording editor, which is the only
     * thing a bare request can be answered with.
     *
     * @param string  $motivation       Required, 1–1000 chars. The approver is
     *                                  being asked to spend their attention; a
     *                                  request with no reasons is a request
     *                                  they cannot answer.
     * @param ?string $proposedQuestion The question the requester asks the
     *                                  decision to carry. ≤4000, null when
     *                                  blank.
     * @param ?string $proposedAnswer   The wording they ask it to carry.
     * @param ?int    $attachmentFileId A file in the **requester's** own
     *                                  Nextcloud. Copied into the decision's
     *                                  proposal folder before the row is
     *                                  written, so the approvers can read it;
     *                                  the row holds the copy's id.
     * @param ?string $attachmentName   The name the requester knows it by.
     *
     * @return array<string, mixed> the serialised review
     * @throws \RuntimeException         module off, decision not found, wrong
     *                                   status, a review already running, or
     *                                   the team has no team folder to stage
     *                                   an attachment into
     * @throws \InvalidArgumentException empty or oversized motivation,
     *                                   oversized proposed wording, or an
     *                                   attachment this user cannot read
     */
    public function request(
        string  $teamId,
        int     $decisionId,
        string  $motivation,
        string  $actingUserId,
        ?string $proposedQuestion = null,
        ?string $proposedAnswer = null,
        ?int    $attachmentFileId = null,
        ?string $attachmentName = null,
    ): array {
        $this->decisionService->assertModuleEnabledForTeam($teamId);
        $this->memberService->requireMemberLevel($teamId);

        $motivation = trim($motivation);
        if ($motivation === '') {
            throw new \InvalidArgumentException('A motivation is required to request a review');
        }
        if (mb_strlen($motivation) > self::MAX_MOTIVATION_LEN) {
            throw new \InvalidArgumentException('Motivation exceeds maximum length');
        }

        // v4.11.30 — the wording the requester asks for. Optional: a review
        // that only asks for a look is still a review, and that is the only
        // shape 4.11.18 had. Normalised to null when blank, because *nothing
        // was proposed* is the fact the approver's buttons are chosen from
        // and `''` would read as a proposal with no words in it.
        $proposedQuestion = $this->normalizeProposed($proposedQuestion, 'Proposed question');
        $proposedAnswer   = $this->normalizeProposed($proposedAnswer, 'Proposed wording');

        $decision = $this->loadDecision($teamId, $decisionId);
        $this->assertReviewable($decision);

        if ($this->reviewMapper->findOpenForDecision($decisionId) !== null) {
            // A conflict rather than a second row: two open reviews would be
            // two approvers answering different questions about one decision.
            throw new \RuntimeException('A review of this decision is already open');
        }

        // **Staged before the row is written** (v4.11.30). The copy has to
        // exist for the approver to have anything to review, and a review
        // row that promised a file the staging had failed to produce would be
        // a request nobody could answer honestly. Taking the copy first means
        // a failure here leaves nothing behind: no row, no open review, and
        // the caller gets the reason.
        $staged = null;
        if ($attachmentFileId !== null && $attachmentFileId > 0) {
            $staged = $this->decisionService->stageReviewAttachment(
                $teamId,
                $decisionId,
                $attachmentFileId,
                (string)$attachmentName,
            );
        }

        $review = $this->insertReview(
            $decision,
            DecisionReview::KIND_REQUESTED,
            $actingUserId,
            $motivation,
            $proposedQuestion,
            $proposedAnswer,
            $staged['fileId'] ?? null,
            $staged['name'] ?? null,
        );

        $this->auditService->log($decision, 'review_requested', $actingUserId, [
            'review_id'  => $review->getId(),
            'motivation' => $motivation,
            // What was asked for, so the trail reads as the request it was
            // and not merely as the fact that one happened. The wording
            // itself is on the row; these say whether there was any.
            'proposed'        => $review->hasProposedWording(),
            'attachment_name' => $staged['name'] ?? null,
        ]);

        $approvers = $this->approverUids($teamId, $decision);

        $this->notifications->reviewRequested($approvers, $actingUserId, $decision);

        // The approvers gain an *Action required* row and the requester a
        // *Waiting for others* one — so both sides, and the requester is on
        // this list precisely because the notification skips them.
        $this->bumpQueues(array_merge($approvers, [$actingUserId]));

        $this->logger->info('[TeamHub][DecisionReviewService] review requested', [
            'team_id' => $teamId, 'decision_id' => $decisionId,
            'review_id' => $review->getId(), 'by' => $actingUserId,
        ]);

        return $this->serialize($review);
    }

    /**
     * Open the periodic review of a decision whose period has fallen due.
     *
     * Called by `DecisionReviewJob` and by nobody else — there is no request
     * behind it, so there is no membership check to make and no session to
     * make it against. The job is responsible for only passing decisions it
     * read from `findReviewDue()`.
     *
     * Returns null when a review is already open, which is the ordinary case
     * on the second pass after a notice: the job stamps
     * `review_notified_at`, but a decision whose stamp was cleared by a
     * period change could otherwise get a second row.
     */
    public function openPeriodicReview(Decision $decision): ?DecisionReview {
        if ($decision->getStatus() !== 'approved') {
            return null;
        }
        if ($this->reviewMapper->findOpenForDecision((int)$decision->getId()) !== null) {
            return null;
        }

        $review = $this->insertReview($decision, DecisionReview::KIND_PERIODIC, '', null);

        $this->auditService->log($decision, 'review_due', '', [
            'review_id' => $review->getId(),
            'due_at'    => $decision->getReviewDueAt(),
        ]);

        $approvers = $this->approverUids($decision->getTeamId(), $decision);

        $this->notifications->reviewDue($approvers, $decision);
        $this->bumpQueues($approvers);

        $this->logger->info('[TeamHub][DecisionReviewService] periodic review opened', [
            'team_id' => $decision->getTeamId(), 'decision_id' => $decision->getId(),
            'review_id' => $review->getId(), 'due_at' => $decision->getReviewDueAt(),
        ]);

        return $review;
    }

    /**
     * The daily pass: open the periodic review of every approved decision
     * that has come within the notice window, and tell its approvers.
     *
     * Runs instance-wide from `DecisionReviewJob` — the teams it should
     * touch are exactly the teams that have a decision falling due, so there
     * is nothing to iterate but the decisions themselves.
     *
     * **`review_notified_at` is stamped whether or not a review was opened.**
     * A decision whose member-requested review is already running needs no
     * second one: completing that review resets the clock, which is the same
     * answer the periodic one was asking for. Stamping it is what stops the
     * pass re-reading the same row every morning for two weeks.
     *
     * Per-decision failures are caught and counted. One team's missing
     * archive category or one deleted approver must not cost every other
     * team its reviews.
     *
     * @return array{scanned:int, opened:int, skipped:int, errors:int}
     */
    public function sweepDueReviews(?int $now = null, int $limit = 500): array {
        $now    = $now ?? time();
        $cutoff = $now + ReviewSchedule::NOTICE_SECONDS;

        $result = ['scanned' => 0, 'opened' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($this->decisionMapper->findReviewDue($cutoff, $limit) as $decision) {
            $result['scanned']++;
            try {
                if (!$this->decisionService->isModuleActiveForTeam($decision->getTeamId())) {
                    // The module was switched off after the decision was
                    // approved. Not an error, and not stamped either: if it
                    // comes back on, the review is still due.
                    $result['skipped']++;
                    continue;
                }

                $opened = $this->openPeriodicReview($decision);
                if ($opened !== null) {
                    $result['opened']++;
                } else {
                    $result['skipped']++;
                }

                // Stamp the cycle, not the moment — see the migration.
                $decision->setReviewNotifiedAt($decision->getReviewDueAt());
                $this->decisionMapper->update($decision);
            } catch (\Throwable $e) {
                $result['errors']++;
                $this->logger->warning('[TeamHub][DecisionReviewService] due-review sweep: decision skipped', [
                    'decision_id' => $decision->getId(),
                    'team_id'     => $decision->getTeamId(),
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    // =========================================================================
    // Complete a review
    // =========================================================================

    /**
     * Answer the open review of a decision.
     *
     * Four outcomes, two offered at a time: a **requested** review is a
     * request, so it is `denied` or `accepted`; a **periodic** one asks "is
     * this still right", so it is `kept` or `archived`.
     *
     * **Three of the four leave the decision `approved`**, and that is the
     * rule the file turns on. `denied` and `kept` change nothing but the
     * clock; `accepted` rewrites the wording in place and still changes
     * nothing else — same status, same approver, same approval moment. Only
     * `archived` moves the decision, and it moves it out of force rather
     * than back into drafting. **A review can no longer leave a decision
     * `denied` or `withdrawn`**, because it never puts one back into the
     * proposal lifecycle where those live (v4.11.26, reverting the
     * reopen-for-redrafting half of v4.11.24 — see
     * `DecisionService::reviseApproved()` for what went wrong with it).
     *
     * @param string  $outcome 'denied' | 'accepted' | 'kept' | 'archived'
     * @param ?string $reason  **Required**, ≤1000 chars (v4.11.23). The same
     *                         rule `approve()` and `deny()` have carried
     *                         since v3.71.3: a decision the team is subject
     *                         to does not change — or pointedly not change —
     *                         without a recorded reason.
     * @param ?string $question `accepted` only: the decision's new subject.
     *                          Null falls back to what the requester
     *                          proposed (v4.11.30) and, failing that, leaves
     *                          it as it is.
     * @param ?string $answer   `accepted` only: the decision's new wording,
     *                          with the same fallback. At least one of the
     *                          two has to actually differ — accepting a
     *                          review is *altering* the decision, and an
     *                          accept that changes nothing is a `denied`
     *                          wearing the wrong label.
     *
     *                          **v4.11.30 — sending neither is how an
     *                          approver says "as proposed".** It used to be
     *                          a `400`, because there was nothing on the row
     *                          to mean; a review now carries the requester's
     *                          wording, so an empty pair is the one-click
     *                          *Approve*. It is still a `400` on a review
     *                          that proposed nothing, which is the case the
     *                          old message was written for.
     *
     *                          The approver never touches the attachment.
     *                          The file is the requester's — they are the
     *                          one who knows what it should say — and it
     *                          stays where it was staged on an accept and is
     *                          deleted on anything else.
     *
     * @return array<string, mixed> the serialised decision, because what the
     *         caller renders afterwards is the decision and not the review
     * @throws \RuntimeException|\InvalidArgumentException
     */
    public function complete(
        string  $teamId,
        int     $decisionId,
        string  $outcome,
        ?string $reason,
        string  $actingUserId,
        ?string $question = null,
        ?string $answer = null,
    ): array {
        $this->decisionService->assertModuleEnabledForTeam($teamId);
        $this->memberService->requireMemberLevel($teamId);

        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException('outcome must be denied, accepted, kept or archived');
        }

        // v4.11.23 — **required**, where it used to be optional. Justin: the
        // approvers have to say why. Answering a review is the same act as
        // approving or denying a proposal — somebody asked a question about
        // a decision the team is subject to, and "kept" with no words tells
        // them nothing. `approve()` and `deny()` have required a rationale
        // since v3.71.3 for exactly that reason, and a review without one
        // would be the only decision in the module with no recorded
        // reasoning behind it.
        $reason = trim((string)$reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('A reason is required to answer a review');
        }
        if (mb_strlen($reason) > self::MAX_REASON_LEN) {
            throw new \InvalidArgumentException('Reason exceeds maximum length');
        }

        $decision = $this->loadDecision($teamId, $decisionId);
        $review   = $this->reviewMapper->findOpenForDecision($decisionId);
        if ($review === null) {
            throw new \RuntimeException('No review of this decision is open');
        }

        // The approver gate, from the one place that owns it.
        $this->decisionService->assertApproverFor($teamId, $decision, $actingUserId);

        // **Everything that can refuse the call happens before the review row
        // is closed** (v4.11.26). Accepting validates the new wording, and a
        // refusal thrown after the row had been marked completed would leave
        // a review closed as `accepted` over a decision nothing had altered —
        // a 400 to the caller and a lie in the table, which no retry can
        // clear because the review is no longer open.
        $revision   = null;
        $asProposed = false;
        if ($outcome === DecisionReview::OUTCOME_ACCEPTED) {
            // v4.11.30 — **the requester's proposal is the default.** An
            // approver who sends no wording is approving what was put to
            // them, which is what *Approve* on a review carrying a proposal
            // means; one who sends wording has edited it, which the dialog
            // exists for. Justin: *the approver only approves or denies with
            // a reason, or is able to edit the final wording.* Both roads end
            // in the same `reviseApproved()` call, so there is one place
            // where a decision's wording can change and one audit line for
            // it either way.
            //
            // Field by field rather than all-or-nothing: an approver may
            // retype the wording and leave the question alone, and the
            // proposal still has an answer for the half they did not touch.
            // Read before the fallback, because after it there is no telling
            // the two apart — and the audit line says which happened.
            $asProposed = $question === null && $answer === null;
            if ($question === null) {
                $question = $review->getProposedQuestion();
            }
            if ($answer === null) {
                $answer = $review->getProposedAnswer();
            }

            if ($question === null && $answer === null) {
                throw new \InvalidArgumentException(
                    'Accepting a review means altering the decision — send the new wording, or deny the review instead',
                );
            }

            $revision = $this->decisionService->reviseApproved(
                $teamId,
                $decision,
                $question,
                $answer,
                $actingUserId,
            );
            if ($revision['changed'] === []) {
                // Refused rather than recorded. A `review_accepted` line over
                // an unchanged decision tells a later reader that something
                // was altered, and nothing was.
                throw new \InvalidArgumentException(
                    'The wording is unchanged — alter it, or deny the review instead',
                );
            }
            $decision = $revision['decision'];
        }

        $now = time();
        $review->setStatus(DecisionReview::STATUS_COMPLETED);
        $review->setOutcome($outcome);
        $review->setCompletedBy($actingUserId);
        $review->setCompletedAt($now);
        // The reason joins the motivation rather than replacing it, so the
        // row reads as the exchange it was. A periodic review has no
        // motivation, so the reason stands alone.
        $review->setMotivation(trim(
            (string)$review->getMotivation() . "\n\n" . $reason,
        ));
        /** @var DecisionReview $savedReview */
        $savedReview = $this->reviewMapper->update($review);

        // v4.11.30 — the requester's file. On an **accept** it stays where it
        // was staged: it is now one of the decision's source files, which is
        // the whole point of having been able to put it forward. On any other
        // answer the proposal was refused, and the decision's sources must
        // not quietly grow a document nobody adopted — so the copy goes.
        //
        // Best-effort by design (see `discardStagedAttachment()`): a file
        // that will not delete is a stale copy in a hidden folder, and
        // failing the denial over it would leave the review open instead.
        if ($savedReview->hasAttachment() && $outcome !== DecisionReview::OUTCOME_ACCEPTED) {
            $this->decisionService->discardStagedAttachment((int)$savedReview->getAttachmentFileId());
        }

        if ($outcome === DecisionReview::OUTCOME_ARCHIVED) {
            $saved = $this->retire($decision, 'archived', $actingUserId, $now, [
                'review_id' => $savedReview->getId(),
                'reason'    => $reason,
            ]);
            $this->auditService->log($saved, 'review_archived', $actingUserId, [
                'review_id' => $savedReview->getId(),
                'reason'    => $reason,
            ]);
        } else {
            // The three outcomes that leave the decision in force:
            //
            //  - `denied`   — the request is refused and the decision stands
            //                 exactly as it is. Justin, 2026-10-05: *deny
            //                 means the original decision stands*.
            //  - `kept`     — a periodic review found nothing to change.
            //  - `accepted` — the request is granted: the wording is
            //                 rewritten **in place**. Same decision, new
            //                 state, still approved — never `open`, so the
            //                 proposal lifecycle's `denied` and `withdrawn`
            //                 stay out of reach of a review.
            //
            // All three restart the clock from the completion rather than
            // the original approval — otherwise a decision reviewed today
            // would fall due again on its old date and the review would have
            // bought nothing. The rewording itself has already happened
            // above, before anything was committed to the review row.
            $months = $this->categoryService->reviewPeriodForCategory($teamId, $decision->getCategory());
            $decision->setReviewDueAt(ReviewSchedule::dueAt($now, $months));
            $decision->setReviewNotifiedAt(null);
            /** @var Decision $saved */
            $saved = $this->decisionMapper->update($decision);

            $this->auditService->log(
                $saved,
                match ($outcome) {
                    DecisionReview::OUTCOME_DENIED   => 'review_denied',
                    DecisionReview::OUTCOME_ACCEPTED => 'review_accepted',
                    default                          => 'review_kept',
                },
                $actingUserId,
                [
                    'review_id'      => $savedReview->getId(),
                    'reason'         => $reason,
                    'next_review_at' => $saved->getReviewDueAt(),
                ],
            );

            if ($revision !== null) {
                // Two lines, as `superseded` and `archived` are two: one says
                // the review was accepted, the other says what that did to
                // the record. The previous wording goes in the payload
                // because this is the only place it survives — the row now
                // holds the new text, and a trail that says "revised" without
                // saying from what cannot answer the question it invites.
                $this->auditService->log($saved, 'revised', $actingUserId, [
                    'review_id'         => $savedReview->getId(),
                    'fields'            => $revision['changed'],
                    'previous_question' => $revision['previous']['question'],
                    'previous_answer'   => $revision['previous']['answer'],
                    // v4.11.30 — the file the new wording came from, when
                    // there was one. The decision row holds no pointer to
                    // it: it is one file among the source list, and this is
                    // the only line that says which of them arrived with
                    // this revision and on whose say.
                    'attachment_name'   => $savedReview->hasAttachment()
                        ? $savedReview->getAttachmentName()
                        : null,
                    // Whether the approver took the wording as proposed or
                    // edited it. Both are an accept; they are not the same
                    // act, and the trail should not have to be guessed at.
                    'as_proposed'       => $asProposed,
                ]);
            }
        }

        $this->notifications->reviewCompleted($savedReview, $actingUserId, $saved);

        // Everybody who had a row: the approvers lose theirs, the requester
        // loses their *Waiting for others*, and the approver who answered is
        // in both lists and in neither notification.
        $this->bumpQueues(array_merge(
            $this->approverUids($teamId, $saved),
            [$savedReview->getRequestedBy(), $actingUserId],
        ));

        $this->logger->info('[TeamHub][DecisionReviewService] review completed', [
            'team_id' => $teamId, 'decision_id' => $decisionId,
            'review_id' => $savedReview->getId(), 'outcome' => $outcome,
            'by' => $actingUserId,
        ]);

        return $this->decisionService->get($teamId, $decisionId, $actingUserId);
    }

    /**
     * Retire an approved decision outright, with no review behind it
     * (v4.11.24).
     *
     * Justin: *archive decision is something we want approvers to do on
     * their periodic review or from the action menu.* The periodic half
     * goes through `complete()`; this is the other half — an approver
     * looking at a decision that has plainly served its purpose, with
     * nobody having asked anything.
     *
     * It is **not** a review, and deliberately does not create one: a
     * review is a question somebody asked and this is an answer nobody
     * asked for. The audit says `archived`, the same line the two review
     * roads write, because what happened to the record is the same.
     *
     * Refused while a review is open — that review is the place to decide
     * this, and archiving around it would leave an approver holding a
     * question about a decision that is already gone.
     *
     * @return array<string, mixed> the serialised decision
     */
    public function archive(string $teamId, int $decisionId, string $reason, string $actingUserId): array {
        $this->decisionService->assertModuleEnabledForTeam($teamId);
        $this->memberService->requireMemberLevel($teamId);

        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('A reason is required to archive a decision');
        }
        if (mb_strlen($reason) > self::MAX_REASON_LEN) {
            throw new \InvalidArgumentException('Reason exceeds maximum length');
        }

        $decision = $this->loadDecision($teamId, $decisionId);
        if ($decision->getStatus() !== 'approved') {
            throw new \RuntimeException(
                'Only an approved decision can be archived (current status: ' . $decision->getStatus() . ')',
            );
        }
        if ($this->reviewMapper->findOpenForDecision($decisionId) !== null) {
            throw new \RuntimeException('A review of this decision is open — answer it instead');
        }

        $this->decisionService->assertApproverFor($teamId, $decision, $actingUserId);

        $saved = $this->retire($decision, 'archived', $actingUserId, time(), [
            'reason' => $reason,
        ]);

        $this->bumpQueues(array_merge(
            $this->approverUids($teamId, $saved),
            [$decision->getProposedBy(), $actingUserId],
        ));

        $this->logger->info('[TeamHub][DecisionReviewService] archived', [
            'team_id' => $teamId, 'decision_id' => $decisionId, 'by' => $actingUserId,
        ]);

        return $this->decisionService->get($teamId, $decisionId, $actingUserId);
    }

    // =========================================================================
    // Supersede
    // =========================================================================

    /**
     * Record that a newer decision overrules this one, and retire this one.
     *
     * **The direction.** The button is on the decision being retired and the
     * picker chooses the decision that replaces it, because that is the
     * order somebody thinks in: you are looking at the old decision and you
     * know it no longer holds. What is written is the other way round —
     * `supersedes_id` on the *newer* decision — because that is the column
     * the module has had since v3.64 and a second column pointing back would
     * be a second answer to one question, free to disagree with the first.
     *
     * **What may supersede what.** The replacement must be an approved
     * decision of the same team, must not be the decision itself, must not
     * already supersede something else, and must not itself be retired. Each
     * of those refusals is a sentence the user can act on rather than a
     * constraint violation.
     *
     * @return array<string, mixed> the serialised (now superseded) decision
     * @throws \RuntimeException|\InvalidArgumentException
     */
    public function supersede(
        string $teamId,
        int    $decisionId,
        int    $supersededByDecisionId,
        string $actingUserId,
    ): array {
        $this->decisionService->assertModuleEnabledForTeam($teamId);
        $this->memberService->requireMemberLevel($teamId);

        if ($decisionId === $supersededByDecisionId) {
            throw new \InvalidArgumentException('A decision cannot supersede itself');
        }

        $decision    = $this->loadDecision($teamId, $decisionId);
        $replacement = $this->loadDecision($teamId, $supersededByDecisionId);

        if ($decision->getStatus() !== 'approved') {
            throw new \RuntimeException(
                'Only an approved decision can be superseded (current status: ' . $decision->getStatus() . ')',
            );
        }
        if ($replacement->getStatus() !== 'approved') {
            throw new \InvalidArgumentException(
                'The decision that supersedes this one must be approved itself',
            );
        }
        if ($replacement->getSupersedesId() !== null
            && (int)$replacement->getSupersedesId() !== $decisionId
        ) {
            throw new \InvalidArgumentException(
                'That decision already supersedes another one',
            );
        }

        $this->decisionService->assertApproverFor($teamId, $decision, $actingUserId);

        $now = time();

        // The link first. If retiring then fails, the link is a true
        // statement on its own — the newer decision does replace the older —
        // and the retire paths can be run again. The other order would leave
        // a decision marked superseded with nothing naming its successor,
        // which is the one state the panel cannot render.
        $replacement->setSupersedesId($decisionId);
        $this->decisionMapper->update($replacement);

        $saved = $this->retire($decision, 'superseded', $actingUserId, $now, [
            'superseded_by' => $supersededByDecisionId,
        ]);

        $this->auditService->log($saved, 'superseded', $actingUserId, [
            'superseded_by'       => $supersededByDecisionId,
            'superseded_by_title' => $replacement->getQuestion(),
        ]);

        // v4.11.31 — and the same act from the other end. Justin,
        // 2026-10-06: *when we supersede a decision this is visible on the
        // superseded decision, but not on the decision that supersedes it.*
        //
        // An audit trail is **per decision**, so a supersede that writes
        // only against the retired row leaves the replacement's trail saying
        // nothing happened — and the replacement is the one still governing,
        // the one somebody reads to find out what rests on it. One act, two
        // rows, one on each side.
        //
        // No second column behind it: this row and the Links row the panel
        // now draws both read `supersedes_id`, which stays the only place
        // the relationship is stored.
        $this->auditService->log($replacement, 'supersedes', $actingUserId, [
            'supersedes'       => $decisionId,
            'supersedes_title' => $saved->getQuestion(),
        ]);

        // `retire()` closes an open review on the way through, so whoever
        // held a row for it no longer does.
        $this->bumpQueues(array_merge(
            $this->approverUids($teamId, $saved),
            [$decision->getProposedBy(), $actingUserId],
        ));

        $this->logger->info('[TeamHub][DecisionReviewService] superseded', [
            'team_id' => $teamId, 'decision_id' => $decisionId,
            'superseded_by' => $supersededByDecisionId, 'by' => $actingUserId,
        ]);

        return $this->decisionService->get($teamId, $decisionId, $actingUserId);
    }

    /**
     * v4.11.19 — a picker is for recognising the decision you already have
     * in mind, not for browsing. Four is what the service desk's
     * *Link a decision* picker settled on (v4.11.12,
     * `ServiceTeamController::publicDecisions`) and this is the same act, so
     * it is the same number.
     */
    public const SUPERSEDE_CANDIDATE_LIMIT = 4;

    /** Longer than this is not a search term, it is a paste. */
    public const MAX_SEARCH_TERM_LEN = 200;

    /**
     * The decisions a member may pick as a replacement, for the supersede
     * picker (v4.11.18; searchable in v4.11.19).
     *
     * Approved decisions of the team whose question matches `$term`, newest
     * first, minus the one being superseded, **at most four**. An empty term
     * gives the four most recent, so the picker is useful before the first
     * keystroke on a team with few decisions and honest about being a search
     * on a team with many.
     *
     * Deliberately *not* minus the ones that already supersede something:
     * `supersede()` refuses those with a sentence, and a picker that
     * silently omits a decision somebody is looking for sends them to read
     * the code.
     *
     * @return array<int, array{id:int, question:string, category:?string, resolvedAt:?int}>
     */
    public function supersedeCandidates(string $teamId, int $decisionId, string $term = ''): array {
        $this->decisionService->assertModuleEnabledForTeam($teamId);
        $this->memberService->requireMemberLevel($teamId);

        $term = trim($term);
        if (mb_strlen($term) > self::MAX_SEARCH_TERM_LEN) {
            throw new \InvalidArgumentException('Search term is too long');
        }

        $filters = ['status' => ['approved']];
        if ($term !== '') {
            $filters['q'] = $term;
        }

        // One more than the cap: the decision being superseded may be among
        // the matches, and dropping it afterwards must not leave three.
        $rows = $this->decisionMapper->list(
            $teamId,
            $filters,
            'recent',
            null,
            self::SUPERSEDE_CANDIDATE_LIMIT + 1,
        );

        $out = [];
        foreach ($rows as $row) {
            if ((int)$row->getId() === $decisionId) {
                continue;
            }
            $out[] = [
                'id'         => (int)$row->getId(),
                'question'   => $row->getQuestion(),
                'category'   => $row->getCategory(),
                'resolvedAt' => $row->getResolvedAt() ?? $row->getDecidedAt(),
            ];
            if (count($out) === self::SUPERSEDE_CANDIDATE_LIMIT) {
                break;
            }
        }
        return $out;
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Move a decision into the team's Archive category and give it a
     * retirement status.
     *
     * Both roads — a reviewer archiving, and a supersede — end here, because
     * what happens to the record is the same in both cases and only the
     * reason differs. The reason is the status and the audit line.
     *
     * The original category name is kept in `archived_from_category` so the
     * record still answers "what did Marketing decide". The review clock is
     * cleared: a retired decision is not reviewed again, and leaving a due
     * date would have the daily pass open reviews nobody can answer.
     *
     * @param array<string, mixed> $auditPayload merged into the `archived` line
     * @throws \RuntimeException when the team has no archive category
     */
    private function retire(
        Decision $decision,
        string   $status,
        string   $actingUserId,
        int      $now,
        array    $auditPayload = [],
    ): Decision {
        $teamId  = $decision->getTeamId();
        $archive = $this->categoryService->archiveCategory($teamId);
        if ($archive === null) {
            // Fails loudly and early. A team whose module predates v4.11.18
            // has no archive until the repair step has run, and silently
            // leaving the decision in its old category would make "archived"
            // a status with no archive behind it.
            throw new \RuntimeException(
                'This team has no archive category yet, so the decision cannot be retired',
            );
        }

        $from = (string)$decision->getCategory();

        // An open review on a decision that is being retired has been
        // answered by the retirement, whatever its question was. Closing it
        // here rather than only in `complete()` covers the supersede road:
        // leaving it open would put a task on an approver's queue for a
        // decision nobody can act on any more. A no-op when `complete()`
        // called us, because it closed the review before it did.
        $open = $this->reviewMapper->findOpenForDecision((int)$decision->getId());
        if ($open !== null) {
            $open->setStatus(DecisionReview::STATUS_COMPLETED);
            $open->setOutcome(DecisionReview::OUTCOME_ARCHIVED);
            $open->setCompletedBy($actingUserId);
            $open->setCompletedAt($now);
            $this->reviewMapper->update($open);
            $this->notifications->withdraw((int)$decision->getId());
        }

        $decision->setStatus($status);
        // Only on the first retirement: a decision cannot be archived twice,
        // but if a repair ever re-ran this, the original category is the one
        // worth keeping rather than 'Archive'.
        if ($decision->getArchivedFromCategory() === null && $from !== $archive->getName()) {
            $decision->setArchivedFromCategory($from !== '' ? $from : null);
        }
        $decision->setCategory($archive->getName());
        $decision->setArchivedAt($now);
        $decision->setArchivedBy($actingUserId);
        $decision->setReviewDueAt(null);
        $decision->setReviewNotifiedAt(null);

        // v4.11.31 — the archive is team-only, so arriving in it takes a
        // decision back out of public. Justin, 2026-10-06: *all decisions in
        // the archive category are team only. If it was public before it
        // will be reverted to team only when it's moved.*
        //
        // The reasoning the flag itself carries (`DecisionService::setPublic`)
        // is what decides this: `is_public` says the decision **is** a public
        // record — searchable, listable, something a knowledge item can be
        // built on and point back at. A decision that has stopped governing
        // is none of those, and leaving the flag up would keep publishing a
        // superseded answer to every logged-in account on the instance while
        // the decision that replaced it may not be public at all.
        //
        // Recorded, not silent: `unpublished` is written below with the
        // retirement as its reason. The flag is the one thing here a team
        // can have set on purpose, so its removal is not something to find
        // out about by noticing.
        $wasPublic = $decision->isPublic();
        if ($wasPublic) {
            $decision->setIsPublic(0);
        }

        /** @var Decision $saved */
        $saved = $this->decisionMapper->update($decision);

        $this->auditService->log($saved, 'archived', $actingUserId, array_merge([
            'status'        => $status,
            'from_category' => $from !== '' ? $from : null,
            'to_category'   => $archive->getName(),
        ], $auditPayload));

        if ($wasPublic) {
            // Its own row rather than a key on `archived`: "when did this
            // stop being a public record" is a question somebody asks of the
            // publishing vocabulary, and an answer filed under `archived`
            // would not be found by anybody asking it.
            $this->auditService->log($saved, 'unpublished', $actingUserId, [
                'reason' => 'archived',
            ]);
        }

        return $saved;
    }

    /**
     * A decision of this team, or a \RuntimeException.
     *
     * Mirrors `DecisionService::loadDecisionInTeam`: "not found" rather than
     * 403 for a decision in another team, because a member has no way to
     * know it exists and saying so would confirm it.
     */
    private function loadDecision(string $teamId, int $decisionId): Decision {
        $decision = $this->decisionMapper->findById($decisionId);
        if ($decision === null || $decision->getTeamId() !== $teamId) {
            throw new \RuntimeException('Decision not found');
        }
        return $decision;
    }

    /**
     * Only an approved decision can be reviewed.
     *
     * An open or finalized one is already in front of somebody — asking for
     * a review of it would be asking them to do the thing they are doing. A
     * denied, withdrawn or retired one governs nothing, so there is nothing
     * to review.
     */
    private function assertReviewable(Decision $decision): void {
        if ($decision->getStatus() !== 'approved') {
            throw new \RuntimeException(
                'Only an approved decision can be reviewed (current status: ' . $decision->getStatus() . ')',
            );
        }
    }

    private function insertReview(
        Decision $decision,
        string   $kind,
        string   $requestedBy,
        ?string  $motivation,
        ?string  $proposedQuestion = null,
        ?string  $proposedAnswer = null,
        ?int     $attachmentFileId = null,
        ?string  $attachmentName = null,
    ): DecisionReview {
        $review = new DecisionReview();
        $review->setDecisionId((int)$decision->getId());
        $review->setTeamId($decision->getTeamId());
        $review->setKind($kind);
        $review->setRequestedBy($requestedBy);
        $review->setMotivation($motivation);
        $review->setProposedQuestion($proposedQuestion);
        $review->setProposedAnswer($proposedAnswer);
        $review->setAttachmentFileId($attachmentFileId);
        $review->setAttachmentName($attachmentName);
        $review->setStatus(DecisionReview::STATUS_OPEN);
        $review->setCreatedAt(time());

        /** @var DecisionReview $saved */
        $saved = $this->reviewMapper->insert($review);
        return $saved;
    }

    /**
     * Trim a proposed field, refuse one that is too long, and return null
     * for one that holds nothing (v4.11.30).
     *
     * Null and not `''`: `DecisionReview::hasProposedWording()` is what
     * decides whether the approver gets a one-click *Approve* or is sent to
     * the wording editor, and a blank string that counted as a proposal
     * would offer them a button that approves no words at all.
     */
    private function normalizeProposed(?string $value, string $label): ?string {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > self::MAX_PROPOSED_LEN) {
            throw new \InvalidArgumentException($label . ' exceeds maximum length');
        }
        return $value;
    }

    /**
     * Who the review is with.
     *
     * The category's approver list, or the team's admins when the category
     * has none that matches — the same two-step `assertApproverFor()` makes,
     * read-only. Used to address the notifications; the authorisation is
     * always the assert and never this.
     *
     * @return string[]
     */
    private function approverUids(string $teamId, Decision $decision): array {
        $categoryName = trim((string)$decision->getCategory());
        if ($categoryName !== '') {
            foreach ($this->categoryService->listForTeam($teamId) as $cat) {
                if (($cat['name'] ?? '') === $categoryName) {
                    return array_values(array_filter(
                        (array)($cat['approvers'] ?? []),
                        static fn ($uid): bool => is_string($uid) && $uid !== '',
                    ));
                }
            }
        }

        // No matching category: the decision falls to the team's admins.
        // Read through MemberService so a pending invitation does not count,
        // which is the distinction `getMemberLevelFromDb` exists to make.
        $uids = [];
        try {
            foreach ($this->memberService->getAllEffectiveMembers($teamId) as $member) {
                if ((int)($member['level'] ?? 0) >= 8 && !empty($member['userId'])) {
                    $uids[] = (string)$member['userId'];
                }
            }
        } catch (\Throwable $e) {
            // Addressing a notification is not worth failing the review over.
            $this->logger->debug('[TeamHub][DecisionReviewService] could not resolve team admins', [
                'teamId' => $teamId, 'error' => $e->getMessage(),
            ]);
        }
        return array_values(array_unique($uids));
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(DecisionReview $r): array {
        return [
            'id'            => (int)$r->getId(),
            'decisionId'    => (int)$r->getDecisionId(),
            'teamId'        => $r->getTeamId(),
            'kind'          => $r->getKind(),
            'requestedBy'   => $r->getRequestedBy(),
            'requestedByName' => $this->displayName($r->getRequestedBy()),
            'motivation'    => $r->getMotivation(),
            // v4.11.30 — what the requester asked the decision to say, and
            // the file they asked it to say it from. `hasProposedWording` is
            // sent rather than left to the client to derive, for the reason
            // `isRetired` is on a decision: three surfaces ask the question
            // and one of them getting null-versus-empty wrong is an
            // *Approve* button over nothing.
            'proposedQuestion'   => $r->getProposedQuestion(),
            'proposedAnswer'     => $r->getProposedAnswer(),
            'hasProposedWording' => $r->hasProposedWording(),
            'attachmentFileId'   => $r->hasAttachment() ? (int)$r->getAttachmentFileId() : null,
            'attachmentName'     => $r->hasAttachment() ? $r->getAttachmentName() : null,
            'status'        => $r->getStatus(),
            'outcome'       => $r->getOutcome(),
            'completedBy'   => $r->getCompletedBy(),
            'completedByName' => $this->displayName((string)$r->getCompletedBy()),
            'completedAt'   => $r->getCompletedAt(),
            'createdAt'     => (int)$r->getCreatedAt(),
        ];
    }

    private function displayName(string $uid): string {
        if ($uid === '') {
            return '';
        }
        $name = trim((string)($this->userManager->get($uid)?->getDisplayName() ?? ''));
        return $name !== '' ? $name : $uid;
    }
}
