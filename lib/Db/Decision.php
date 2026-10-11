<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_decisions — a single decision record.
 *
 * Lifecycle: open → finalized → approved (terminal) OR denied (terminal)
 *            open → withdrawn (terminal)
 *            approved → superseded (terminal) OR archived (terminal)
 * Legacy DB values 'proposed' / 'decided' are mapped to 'open' / 'approved'
 * at the serialisation layer; they are never written by new code.
 *
 * v4.11.18 — the two retirement states. An approved decision is already
 * terminal, so neither adds a way to change a record; what they add is a way
 * to say the record no longer governs:
 *   superseded — a newer decision overrules this one. The newer decision's
 *     `supersedes_id` points back here, which is the only link; there is no
 *     `superseded_by` column to disagree with it.
 *   archived   — a reviewer retired it, with nothing taking its place.
 * Both move the row into the team's Archive category and keep the name of
 * the category it was decided under in `archived_from_category`.
 *
 * impact:  'low' | 'medium' | 'high' — validated at the application layer.
 * level:   'operational' | 'tactical' | 'strategic' — validated at the app
 *   layer. Null in legacy rows; treated as 'operational' at serialisation.
 *   Only shown in the UI when the per-team decisions_level_enabled flag is on.
 * status:  'open' | 'finalized' | 'approved' | 'denied' | 'withdrawn'
 *   | 'superseded' | 'archived'.
 * source_type: 'message' | 'document' | 'external' | null.
 * participants: JSON-encoded array of user-id strings, captured at decision
 *   time. Not maintained live.
 * share_mode (v4.5.42): 'immediate' | 'selected' | 'team' — how the proposal
 *   was opened. 'immediate' finalizes on creation (and is what every
 *   pre-4.5.42 row carries); the other two open a discussion phase.
 *   `selected` restricts visibility to teamhub_decision_audience while the
 *   proposal is open; `team` is visible to the whole team, which is what an
 *   open decision has always been.
 * talk_token / talk_thread_id (v4.5.42): where the discussion lives. Both
 *   nullable — an 'immediate' proposal has no discussion, and Talk sharing is
 *   best-effort, so a proposal whose Talk post failed is still valid.
 * is_public (v4.11.12): 0 | 1 — whether this is a public record rather than a
 *   team-internal one. It says what the decision *is*, not who was given a
 *   link to it: a public decision can be searched, listed and (later)
 *   transformed into a knowledge item that links back to it, which a
 *   secret-URL grant could never be. Every row written before 4.11.12 is 0,
 *   which is the true statement about it. It widens who may *read* the
 *   decision; it changes nothing about who may act on it.
 *
 * @method int     getId()
 * @method void    setId(int $id)
 * @method string  getTeamId()
 * @method void    setTeamId(string $teamId)
 * @method int     getMessageId()
 * @method void    setMessageId(int $messageId)
 * @method string  getProposedBy()
 * @method void    setProposedBy(string $proposedBy)
 * @method ?string getAnsweredBy()
 * @method void    setAnsweredBy(?string $answeredBy)
 * @method ?int    getSelectedCommentId()
 * @method void    setSelectedCommentId(?int $selectedCommentId)
 * @method ?string getCategory()
 * @method void    setCategory(?string $category)
 * @method string  getImpact()
 * @method void    setImpact(string $impact)
 * @method ?string getLevel()
 * @method void    setLevel(?string $level)
 * @method string  getQuestion()
 * @method void    setQuestion(string $question)
 * @method ?string getSelectedAnswer()
 * @method void    setSelectedAnswer(?string $selectedAnswer)
 * @method ?string getParticipants()
 * @method void    setParticipants(?string $participants)
 * @method string  getStatus()
 * @method void    setStatus(string $status)
 * @method ?string getWithdrawnReason()
 * @method void    setWithdrawnReason(?string $withdrawnReason)
 * @method ?string getResolvedBy()
 * @method void    setResolvedBy(?string $resolvedBy)
 * @method ?int    getSupersedesId()
 * @method void    setSupersedesId(?int $supersedesId)
 * @method ?string getSourceType()
 * @method void    setSourceType(?string $sourceType)
 * @method ?string getSourceRef()
 * @method void    setSourceRef(?string $sourceRef)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 * @method ?int    getDecidedAt()
 * @method void    setDecidedAt(?int $decidedAt)
 * @method ?int    getResolvedAt()
 * @method void    setResolvedAt(?int $resolvedAt)
 * @method ?int    getWithdrawnAt()
 * @method void    setWithdrawnAt(?int $withdrawnAt)
 * @method ?int    getMilestoneId()
 * @method void    setMilestoneId(?int $milestoneId)
 * @method string  getShareMode()
 * @method void    setShareMode(string $shareMode)
 * @method ?string getTalkToken()
 * @method void    setTalkToken(?string $talkToken)
 * @method ?int    getTalkThreadId()
 * @method void    setTalkThreadId(?int $talkThreadId)
 * @method void    setIsPublic(int $isPublic)
 * @method ?int    getArchivedAt()
 * @method void    setArchivedAt(?int $archivedAt)
 * @method ?string getArchivedBy()
 * @method void    setArchivedBy(?string $archivedBy)
 * @method ?string getArchivedFromCategory()
 * @method void    setArchivedFromCategory(?string $archivedFromCategory)
 * @method ?int    getReviewDueAt()
 * @method void    setReviewDueAt(?int $reviewDueAt)
 * @method ?int    getReviewNotifiedAt()
 * @method void    setReviewNotifiedAt(?int $reviewNotifiedAt)
 */
class Decision extends Entity {

    protected string  $teamId            = '';
    protected int     $messageId         = 0;
    protected string  $proposedBy        = '';
    protected ?string $answeredBy        = null;
    protected ?int    $selectedCommentId = null;
    protected ?string $category          = null;
    protected string  $impact            = '';
    protected ?string $level             = null;
    protected string  $question          = '';
    protected ?string $selectedAnswer    = null;
    protected ?string $participants      = null;
    protected string  $status            = '';
    protected ?string $withdrawnReason   = null;
    protected ?string $resolvedBy        = null;
    protected ?int    $supersedesId      = null;
    protected ?string $sourceType        = null;
    protected ?string $sourceRef         = null;
    protected int     $createdAt         = 0;
    protected ?int    $decidedAt         = null;
    // v4.5.25 — when the decision was approved or denied. `decidedAt` is the
    // finalize moment and is deliberately not overwritten, so this is the only
    // record of when it was actually resolved. Null on every row written
    // before 4.5.25; readers fall back to `decidedAt`.
    protected ?int    $resolvedAt        = null;
    protected ?int    $withdrawnAt       = null;
    // v3.97.5 — optional linkage to a teamhub_milestones row. Null on
    // every existing decision + every decision proposed on a non-project
    // team. Soft link — no FK constraint; if the milestone is deleted,
    // the serialiser resolves the label to null and the frontend hides
    // the chip.
    protected ?int    $milestoneId       = null;
    // v4.5.42 — the discussion phase. Defaults to 'immediate' so an entity
    // constructed in code (rather than hydrated from a row) carries the same
    // value the migration backfilled, and no caller has to remember to set it.
    //
    // v4.11.22 — and that is a coincidence holding two files in agreement,
    // not a design. `Entity::setter()` skips a set whose value equals the
    // current one, so `setShareMode('immediate')` never marks the field and
    // the column is left out of the INSERT entirely; this row is correct
    // only because the *database* default is also `'immediate'`. Change one
    // without the other and `share_mode` starts writing something nobody
    // asked for. The same shape cost us every review row and every Archive
    // category in 4.11.18–4.11.21 — DESIGN §2.158. Do not copy it for a new
    // column: give the property a value the column can never hold.
    protected string  $shareMode         = 'immediate';
    protected ?string $talkToken         = null;
    protected ?int    $talkThreadId      = null;
    /**
     * v4.11.12 — 0 | 1. Nullable in the property so a row from a database
     * that has not run the migration yet cannot be read as a third state;
     * `isPublic()` normalises it, the way `WorkflowStep::getAssignee()` does.
     */
    protected ?int    $isPublic          = 0;
    /**
     * v4.11.18 — retirement. All five are null on every row written before
     * this version, which is the true statement about them: nothing has been
     * archived and no category had a review period to fall due.
     *
     * `reviewNotifiedAt` stores the `reviewDueAt` the notice was written for,
     * not the moment of writing — see the migration. A reset moves the due
     * date, the stored value stops matching, and the next cycle gets its own
     * notice without anybody clearing a flag.
     */
    protected ?int    $archivedAt        = null;
    protected ?string $archivedBy        = null;
    protected ?string $archivedFromCategory = null;
    protected ?int    $reviewDueAt       = null;
    protected ?int    $reviewNotifiedAt  = null;

    public function __construct() {
        $this->addType('messageId',         'integer');
        $this->addType('selectedCommentId', 'integer');
        $this->addType('supersedesId',      'integer');
        $this->addType('createdAt',         'integer');
        $this->addType('decidedAt',         'integer');
        $this->addType('resolvedAt',        'integer');
        $this->addType('withdrawnAt',       'integer');
        $this->addType('milestoneId',       'integer');
        $this->addType('talkThreadId',      'integer');
        $this->addType('isPublic',          'integer');
        $this->addType('archivedAt',        'integer');
        $this->addType('reviewDueAt',       'integer');
        $this->addType('reviewNotifiedAt',  'integer');
    }

    /**
     * v4.11.18 — retired, by either road. The two statuses differ in what
     * replaced the decision, not in what a reader may do with it, so the
     * question "is this still in force" has one answer and one caller-facing
     * method rather than two comparisons everywhere.
     */
    public function isRetired(): bool {
        return in_array($this->status, ['superseded', 'archived'], true);
    }

    /** v4.11.12 — a public record, readable outside the team that made it. */
    public function isPublic(): bool {
        return (int)($this->isPublic ?? 0) === 1;
    }
}
