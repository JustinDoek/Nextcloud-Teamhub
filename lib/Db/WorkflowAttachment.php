<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One row of `teamhub_wf_attachment` — a document attached to a workflow
 * (WorkflowHub phase 6, v4.10.21).
 *
 * A **reference to a file that already lives in Nextcloud**, never a copy
 * of it: `fileId` is an `oc_filecache` id and the file stays where its
 * owner put it, with the sharing, versioning and trash Nextcloud already
 * gives it. TeamHub stores the pointer, who attached it, when, on which
 * step — and the one thing Nextcloud cannot know, which is **how far
 * inside this workflow the document may travel**
 * (`OCA\TeamHub\Workflow\WorkflowAttachmentVisibility`).
 *
 * `fileName` is the name at the moment of attaching. It is what the
 * archive renders when the file itself is gone — moved, renamed, deleted,
 * or simply not readable by the person looking at the record — so a
 * completed workflow can still say *what* was handed over even when it can
 * no longer hand it over. It is a label, never an access path: every read
 * resolves `fileId` against the **viewer's own** Nextcloud, so a document
 * listed in an archive opens only for somebody Nextcloud would have let
 * open it anyway.
 *
 * @method int     getInstanceId()
 * @method void    setInstanceId(int $v)
 * @method int     getFileId()
 * @method void    setFileId(int $v)
 * @method string  getFileName()
 * @method void    setFileName(string $v)
 * @method string  getVisibility()
 * @method void    setVisibility(string $v)
 * @method ?string getStepKey()
 * @method void    setStepKey(?string $v)
 * @method string  getAddedBy()
 * @method void    setAddedBy(string $v)
 * @method int     getAddedAt()
 * @method void    setAddedAt(int $v)
 * @method void    setShareId(?string $v)
 * @method ?int    getShareUntil()
 * @method void    setShareUntil(?int $v)
 * @method ?int    getUnsharedAt()
 * @method void    setUnsharedAt(?int $v)
 */
class WorkflowAttachment extends Entity {

    protected int     $instanceId = 0;
    protected int     $fileId     = 0;
    protected string  $fileName   = '';
    /** `requester` or `internal`. No default: the classification is always stated. */
    protected string  $visibility = '';
    protected ?string $stepKey    = null;
    protected string  $addedBy    = '';
    protected int     $addedAt    = 0;
    /**
     * v4.10.38 — the paperclip (`docs/service-builder.md` § 7): the share
     * TeamHub made so the other side can open the file, when it expires and
     * when TeamHub removed it. '' / null for a document attached without one.
     */
    protected ?string $shareId    = '';
    protected ?int    $shareUntil = null;
    protected ?int    $unsharedAt = null;

    public function __construct() {
        $this->addType('instanceId', 'integer');
        $this->addType('fileId',     'integer');
        $this->addType('addedAt',    'integer');
        $this->addType('shareUntil', 'integer');
        $this->addType('unsharedAt', 'integer');
    }

    /** Nextcloud's full share id, or '' when TeamHub made no share for it (v4.10.38). */
    public function getShareId(): string {
        return $this->shareId ?? '';
    }

    /** A share TeamHub made that has not been removed yet (v4.10.38). */
    public function hasLiveShare(): bool {
        return $this->getShareId() !== '' && $this->unsharedAt === null;
    }
}
