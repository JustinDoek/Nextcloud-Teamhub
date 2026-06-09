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
 * @method ?int    getWithdrawnAt()
 * @method void    setWithdrawnAt(?int $withdrawnAt)
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
    protected ?int    $withdrawnAt       = null;

    public function __construct() {
        $this->addType('messageId',         'integer');
        $this->addType('selectedCommentId', 'integer');
        $this->addType('supersedesId',      'integer');
        $this->addType('createdAt',         'integer');
        $this->addType('decidedAt',         'integer');
        $this->addType('withdrawnAt',       'integer');
    }
}
