<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_decisions — a single decision record.
 *
 * Lifecycle: open → finalized → approved (terminal) OR denied (terminal)
 *            open → withdrawn (terminal)
 * Legacy DB values 'proposed' / 'decided' are mapped to 'open' / 'approved'
 * at the serialisation layer; they are never written by new code.
 *
 * impact:  'low' | 'medium' | 'high' — validated at the application layer.
 * level:   'operational' | 'tactical' | 'strategic' — validated at the app
 *   layer. Null in legacy rows; treated as 'operational' at serialisation.
 *   Only shown in the UI when the per-team decisions_level_enabled flag is on.
 * status:  'open' | 'finalized' | 'approved' | 'denied' | 'withdrawn'.
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
    protected string  $shareMode         = 'immediate';
    protected ?string $talkToken         = null;
    protected ?int    $talkThreadId      = null;

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
    }
}
