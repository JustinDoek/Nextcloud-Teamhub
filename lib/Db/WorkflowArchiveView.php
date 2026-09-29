<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One row of `teamhub_wf_archive_view` — **an archive projection**
 * (WorkflowHub phase 6, v4.10.21; `docs/workflow-archiving.md`).
 *
 * A projection is *not a copy of the workflow*. It is an index entry: one
 * row saying "archive N is readable as audience A by team T, and here are
 * the keys you can search and sort it by". The requesting team gets one,
 * the handling service team gets one when there is a service team, and
 * both point at the same `teamhub_wf_archive` row and through it at the
 * same instance, steps and events.
 *
 * That is the whole of product rules 4 and 5 in a table: two projections,
 * one record. Rendering a projection reads the authoritative rows and
 * filters them for its audience — the content is never stored twice, so
 * the two views cannot drift apart, and no projection can outlive or
 * contradict the record it points at.
 *
 * The only thing a projection stores that the record does not is
 * `searchText`: a lowercased haystack of the workflow's title and summary,
 * written at archival so a text filter is one indexed table scan instead
 * of rendering every archive in the instance. **It is never rendered** —
 * the reader gets its title from the authoritative record, in their own
 * language, which a stored string could not be. `serviceKey` and
 * `outcome`, likewise, are filter keys.
 *
 * `searchText` for the `service_team` audience holds the same words as the
 * requesting team's: a requester's own summary, never an internal note. An
 * internal note is not searchable, by design — a haystack is the one place
 * where a substring of something invisible can be confirmed to exist.
 *
 * @method int     getArchiveId()
 * @method void    setArchiveId(int $v)
 * @method string  getAudience()
 * @method void    setAudience(string $v)
 * @method string  getTeamId()
 * @method void    setTeamId(string $v)
 * @method string  getRefNumber()
 * @method void    setRefNumber(string $v)
 * @method string  getDefinitionKey()
 * @method void    setDefinitionKey(string $v)
 * @method string  getServiceKey()
 * @method void    setServiceKey(string $v)
 * @method string  getOutcome()
 * @method void    setOutcome(string $v)
 * @method int     getCompletedAt()
 * @method void    setCompletedAt(int $v)
 * @method ?string getSearchText()
 * @method void    setSearchText(?string $v)
 */
class WorkflowArchiveView extends Entity {

    protected int     $archiveId     = 0;
    /** One of `OCA\TeamHub\Workflow\WorkflowArchiveAudience`. */
    protected string  $audience      = '';
    /** The team whose archive this row belongs to — the requester's, or the desk's. */
    protected string  $teamId        = '';
    protected string  $refNumber     = '';
    protected string  $definitionKey = '';
    /** The catalogue service, for a service request; '' otherwise. */
    protected string  $serviceKey    = '';
    protected string  $outcome       = '';
    protected int     $completedAt   = 0;
    protected ?string $searchText    = null;

    public function __construct() {
        $this->addType('archiveId',   'integer');
        $this->addType('completedAt', 'integer');
    }
}
