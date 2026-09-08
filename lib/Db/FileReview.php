<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_file_review — one request to review one file (v4.8.18).
 *
 * Lifecycle: open → closed. There is no third state and no way back: a closed
 * review is a record, and another round is a new request. See
 * FILE-REVIEW-PLAN.md §1 D5 and the "no reopening" row in its §5.
 *
 * status:     'open' | 'closed'.
 * file_id:    NC file id, the identity. Survives rename and move.
 * file_name:  display snapshot, used only when the file no longer resolves.
 * talk_token: **the file's own Talk conversation** — the one that opens in the
 *   Files sidebar — not a room TeamHub owns (v4.8.20). Stored as a convenience
 *   handle: the conversation existed before the review in most cases, survives
 *   it always, and is never deleted by us. Nullable, and a null is not a fault
 *   — Talk may be absent, an administrator may have switched file
 *   conversations off, and resolving one is best-effort so that a missing chat
 *   never costs the review.
 * due_at:     optional. Feeds My Work's overdue promotion; null means the
 *   provider emits no due date rather than inventing one.
 *
 * @method int     getId()
 * @method void    setId(int $id)
 * @method string  getTeamId()
 * @method void    setTeamId(string $teamId)
 * @method int     getFileId()
 * @method void    setFileId(int $fileId)
 * @method string  getFileName()
 * @method void    setFileName(string $fileName)
 * @method string  getRequestedBy()
 * @method void    setRequestedBy(string $requestedBy)
 * @method ?string getMessage()
 * @method void    setMessage(?string $message)
 * @method ?int    getDueAt()
 * @method void    setDueAt(?int $dueAt)
 * @method ?string getTalkToken()
 * @method void    setTalkToken(?string $talkToken)
 * @method string  getStatus()
 * @method void    setStatus(string $status)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 * @method ?int    getClosedAt()
 * @method void    setClosedAt(?int $closedAt)
 * @method ?string getClosedBy()
 * @method void    setClosedBy(?string $closedBy)
 */
class FileReview extends Entity {

    public const STATUS_OPEN   = 'open';
    public const STATUS_CLOSED = 'closed';

    protected string  $teamId      = '';
    protected int     $fileId      = 0;
    protected string  $fileName    = '';
    protected string  $requestedBy = '';
    protected ?string $message     = null;
    protected ?int    $dueAt       = null;
    protected ?string $talkToken   = null;
    protected string  $status      = self::STATUS_OPEN;
    protected int     $createdAt   = 0;
    protected ?int    $closedAt    = null;
    protected ?string $closedBy    = null;

    public function __construct() {
        $this->addType('fileId',    'integer');
        $this->addType('dueAt',     'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('closedAt',  'integer');
    }

    public function isOpen(): bool {
        return $this->status === self::STATUS_OPEN;
    }
}
