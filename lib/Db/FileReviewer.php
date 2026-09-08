<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_file_reviewer — one person's obligation within one file
 * review (v4.8.18).
 *
 * `completedAt` is the whole state machine: null is "still owed", any value is
 * "done, at this moment". There is deliberately no boolean beside it — see the
 * migration's docblock for why a flag would be both redundant and a portability
 * hazard.
 *
 * The set is a **snapshot**. It is resolved once, when the request is made;
 * somebody who joins the team afterwards is not added, because "who was asked"
 * has to stay answerable later.
 *
 * A row is never deleted by closing the review, including a row that was never
 * completed — the requester has to be able to see who did not respond. It is
 * deleted only when that person leaves the team, which is the one case where
 * the obligation genuinely ceases to exist.
 *
 * @method int     getId()
 * @method void    setId(int $id)
 * @method int     getReviewId()
 * @method void    setReviewId(int $reviewId)
 * @method string  getUserId()
 * @method void    setUserId(string $userId)
 * @method ?int    getCompletedAt()
 * @method void    setCompletedAt(?int $completedAt)
 * @method ?string getRemark()
 * @method void    setRemark(?string $remark)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 */
class FileReviewer extends Entity {

    protected int     $reviewId    = 0;
    protected string  $userId      = '';
    protected ?int    $completedAt = null;
    protected ?string $remark      = null;
    protected int     $createdAt   = 0;

    public function __construct() {
        $this->addType('reviewId',    'integer');
        $this->addType('completedAt', 'integer');
        $this->addType('createdAt',   'integer');
    }

    public function hasCompleted(): bool {
        return $this->completedAt !== null;
    }
}
