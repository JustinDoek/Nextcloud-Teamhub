<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_dec_reviews — one review cycle of one approved
 * decision (v4.11.18).
 *
 * A review asks the category's approvers to look at a decision that is
 * already approved and still in force. It does not reopen the decision:
 * the row in `teamhub_decisions` keeps its `approved` status and its
 * original approval throughout, and the review's outcome is what moves it.
 *
 * kind:     'requested' — a team member asked, and `motivation` carries
 *             their reasons and `requested_by` their name.
 *           'periodic'  — the category's review period fell due. Nobody
 *             asked, so `requested_by` is empty and `motivation` is null;
 *             naming the system as the requester would be a lie the audit
 *             trail then repeats.
 * status:   'open' | 'completed'. At most one 'open' row per decision —
 *             enforced in the service, because the alternative is a unique
 *             index on (decision_id, status) that would also forbid a
 *             second *completed* review, and a decision may be reviewed
 *             many times over its life.
 * outcome:  'kept' — the decision stands; the review timer restarts from
 *             the completion.
 *           'archived' — the decision is retired: status 'archived', moved
 *             into the team's Archive category, read-only.
 *           Null while the review is open.
 *
 * Terminal in both directions: a completed review is a record and is never
 * reopened. Asking again is a new row, which is the point of the table.
 *
 * **v4.11.30 — the row carries the requester's proposal.** A review used to
 * hold one field a person wrote: the motivation. It now also holds the
 * wording the requester asks the decision to carry and, optionally, the file
 * that wording comes from — because the requester is the one who knows both,
 * and the approver had no way to replace an attachment at all. The approver
 * approves what was proposed, denies it with a reason, or edits the wording
 * before approving; **the attachment is the requester's and the approver
 * never edits it.** Justin, 2026-10-05.
 *
 * @method int     getId()
 * @method void    setId(int $id)
 * @method int     getDecisionId()
 * @method void    setDecisionId(int $decisionId)
 * @method string  getTeamId()
 * @method void    setTeamId(string $teamId)
 * @method string  getKind()
 * @method void    setKind(string $kind)
 * @method string  getRequestedBy()
 * @method void    setRequestedBy(string $requestedBy)
 * @method ?string getMotivation()
 * @method void    setMotivation(?string $motivation)
 * @method ?string getProposedQuestion()
 * @method void    setProposedQuestion(?string $proposedQuestion)
 * @method ?string getProposedAnswer()
 * @method void    setProposedAnswer(?string $proposedAnswer)
 * @method ?int    getAttachmentFileId()
 * @method void    setAttachmentFileId(?int $attachmentFileId)
 * @method void    setAttachmentName(?string $attachmentName)
 * @method string  getStatus()
 * @method void    setStatus(string $status)
 * @method ?string getOutcome()
 * @method void    setOutcome(?string $outcome)
 * @method ?string getCompletedBy()
 * @method void    setCompletedBy(?string $completedBy)
 * @method ?int    getCompletedAt()
 * @method void    setCompletedAt(?int $completedAt)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 */
class DecisionReview extends Entity {

    public const KIND_REQUESTED = 'requested';
    public const KIND_PERIODIC  = 'periodic';

    public const STATUS_OPEN      = 'open';
    public const STATUS_COMPLETED = 'completed';

    /**
     * What the approver decided (v4.11.24 splits the first two out).
     *
     * A **requested** review is a request, so it is answered the way a
     * request is — `denied` or `accepted`:
     *   denied   — the decision stands as it is.
     *   accepted — the decision is altered: the approver rewrites its
     *              wording **in place** and it stays `approved` throughout,
     *              with the same approver and the same approval moment. The
     *              same decision, in its new state; a *different* decision
     *              that invalidates this one is a supersede, not a review.
     *
     *              v4.11.26 — this used to send the decision back to `open`
     *              for redrafting, which let a review end in `denied` and
     *              destroy the decision it was asked about. Nothing in this
     *              table may put an approved decision back into the proposal
     *              lifecycle; `DecisionService::reviseApproved()` has the
     *              long version.
     *
     * A **periodic** review asks "is this still right", so it is answered
     * `kept` or `archived`:
     *   kept     — still right; the clock restarts.
     *   archived — it has served its purpose and is retired.
     *
     * The service accepts any of the four for either kind: which are
     * *offered* is the surface's business, and fixing the pairs here would
     * rule out a periodic review concluding that the decision needs
     * altering, which nobody has asked for and nothing should forbid.
     */
    public const OUTCOME_DENIED   = 'denied';
    public const OUTCOME_ACCEPTED = 'accepted';
    public const OUTCOME_KEPT     = 'kept';
    public const OUTCOME_ARCHIVED = 'archived';

    protected int     $decisionId  = 0;
    protected string  $teamId      = '';
    /**
     * **Empty, not `KIND_REQUESTED` / `STATUS_OPEN`, and this is load-bearing**
     * (v4.11.22).
     *
     * `Entity::setter()` returns early when the value you set equals the
     * property's current value:
     *
     *     if ($args[0] === $this->$name) { return; }
     *     $this->markFieldUpdated($name);
     *
     * So a property default that equals the value the service writes means
     * the field is never marked updated, `QBMapper::insert()` leaves the
     * column out of the INSERT entirely, and **the database decides what
     * goes in it**. These two defaulted to the exact values
     * `DecisionReviewService::insertReview()` sets, so both columns were
     * omitted and Postgres applied their `''` defaults — every review row
     * written between 4.11.18 and 4.11.21 has `kind = ''` and
     * `status = ''`.
     *
     * Nothing errored. The POST answered 200 with the right values, because
     * the response is serialised from the in-memory entity. Only reads came
     * back wrong, and every read that matters filters on `status = 'open'`:
     * no "a review is running" banner, no approver row in My Work, no
     * requester row, and completing one answered "No review of this
     * decision is open".
     *
     * `''` is not a value either column can legitimately hold, so the
     * setter can never short-circuit again.
     */
    protected string  $kind        = '';
    protected string  $requestedBy = '';
    protected ?string $motivation  = null;
    /**
     * What the requester asks the decision to say (v4.11.30).
     *
     * Null on a review that only asks for a look — the 4.11.18 shape, which
     * every row written before this version has — and on every periodic
     * review, where nobody asked anything and there is nothing to propose.
     *
     * Null rather than `''`, and the difference carries weight: *nothing was
     * proposed* is a different fact from *an empty wording was proposed*, and
     * it is the fact the approver's buttons are chosen from. `''` is not a
     * value either column can legitimately hold, because the service refuses
     * a blank proposal — so `hasProposedWording()` can test one and not both.
     */
    protected ?string $proposedQuestion = null;
    protected ?string $proposedAnswer   = null;
    /**
     * The file the requester puts forward, and the name they know it by.
     *
     * **The id of the copy inside `.proposals/{decisionId}/`**, never the id
     * of the requester's original. The original may sit in their private
     * files, which is the whole reason the approver could not review an
     * attachment before: the team folder is circle-shared, so a copy in it
     * is readable by every member, and the approver is one.
     *
     * The copy's filename carries a `review-{id}-` prefix so that a
     * regeneration of the proposal folder — which overwrites by name — can
     * never clobber it. `attachmentName` is what the panel shows instead.
     */
    protected ?int    $attachmentFileId = null;
    protected ?string $attachmentName   = null;
    protected string  $status      = '';
    protected ?string $outcome     = null;
    protected ?string $completedBy = null;
    protected ?int    $completedAt = null;
    protected int     $createdAt   = 0;

    public function __construct() {
        $this->addType('decisionId',       'integer');
        $this->addType('completedAt',      'integer');
        $this->addType('createdAt',        'integer');
        $this->addType('attachmentFileId', 'integer');
    }

    public function isOpen(): bool {
        return $this->status === self::STATUS_OPEN;
    }

    /** A member asked for this one, as opposed to the calendar. */
    public function isRequested(): bool {
        return $this->kind === self::KIND_REQUESTED;
    }

    /**
     * Did the requester say what the decision should now say? (v4.11.30)
     *
     * The one place the question is answered, so that no caller has to test
     * null and `''` separately and no caller gets it half right. What turns
     * on it: whether the approver is offered a one-click *Approve* — there
     * is something to approve — or sent to the wording editor, which is the
     * only thing a bare request can be answered with.
     */
    public function hasProposedWording(): bool {
        return trim((string)$this->proposedQuestion) !== ''
            || trim((string)$this->proposedAnswer) !== '';
    }

    /**
     * The name the requester knows their file by, or `''` when there is no
     * file. Normalised because the column is nullable only to satisfy the
     * Oracle-default rule (migrations skill 1b) — a name paired with an id
     * has no third state, and `''` is the only thing "no name" can mean.
     */
    public function getAttachmentName(): string {
        return $this->attachmentName ?? '';
    }

    /** Did the requester put a file forward? */
    public function hasAttachment(): bool {
        return ($this->attachmentFileId ?? 0) > 0;
    }
}
