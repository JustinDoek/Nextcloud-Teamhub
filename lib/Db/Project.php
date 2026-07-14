<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_project — one row per team created from the "Project"
 * template (Project Teams keystone, v3.88.0).
 *
 * The row's existence marks the team as a project. `mode` is the lifecycle
 * discriminator (basic|advanced); `phase` is only meaningful (and non-null)
 * for advanced projects, walking initiation|planning|execution|closing.
 *
 * startDate / targetEnd are nullable Unix timestamps (UTC midnight), matching
 * the teamhub_milestones date convention.
 *
 * @method int      getId()
 * @method void     setId(int $id)
 * @method string   getTeamId()
 * @method void     setTeamId(string $teamId)
 * @method string   getType()
 * @method void     setType(string $type)
 * @method string   getMode()
 * @method void     setMode(string $mode)
 * @method ?string  getPhase()
 * @method void     setPhase(?string $phase)
 * @method ?int     getStartDate()
 * @method void     setStartDate(?int $startDate)
 * @method ?int     getTargetEnd()
 * @method void     setTargetEnd(?int $targetEnd)
 * @method string   getCreatedBy()
 * @method void     setCreatedBy(string $createdBy)
 * @method int      getCreatedAt()
 * @method void     setCreatedAt(int $createdAt)
 * @method int      getUpdatedAt()
 * @method void     setUpdatedAt(int $updatedAt)
 * @method ?string  getCurrency()
 * @method void     setCurrency(?string $currency)
 * @method ?int     getBudgetTotalMinor()
 * @method void     setBudgetTotalMinor(?int $budgetTotalMinor)
 * @method int      getBudgetViewMinLevel()
 * @method void     setBudgetViewMinLevel(int $budgetViewMinLevel)
 * @method int      getTimeViewMinLevel()
 * @method void     setTimeViewMinLevel(int $timeViewMinLevel)
 * @method ?int     getClosingArtifactAt()
 * @method void     setClosingArtifactAt(?int $closingArtifactAt)
 * @method ?int     getCharterConfiguredAt()
 * @method void     setCharterConfiguredAt(?int $charterConfiguredAt)
 * @method ?int     getKickoffMeetingAt()
 * @method void     setKickoffMeetingAt(?int $kickoffMeetingAt)
 * @method ?int     getEvaluationMeetingAt()
 * @method void     setEvaluationMeetingAt(?int $evaluationMeetingAt)
 */
class Project extends Entity {

    protected string  $teamId           = '';
    protected string  $type             = 'project';
    protected string  $mode             = 'basic';
    protected ?string $phase            = null;
    protected ?int    $startDate        = null;
    protected ?int    $targetEnd        = null;
    protected string  $createdBy        = '';
    protected int     $createdAt        = 0;
    protected int     $updatedAt        = 0;
    // Budget page (v3.92.0). Currency is ISO-4217 3-char; total is minor units
    // (e.g. euro cents). Both nullable — a project may exist without a budget.
    protected ?string $currency            = null;
    protected ?int    $budgetTotalMinor    = null;
    // v3.94.0 — single project-level floor for who can see the Budget tab.
    // 1 = every member (default), 4 = moderator+, 8 = admin only. Replaces
    // the per-lane view_min_level which is no longer consulted.
    protected int     $budgetViewMinLevel  = 1;
    // v3.96.0 — same shape as budgetViewMinLevel, gating the Time tab.
    // A user below the floor still sees the tab if they have a
    // teamhub_project_member row (i.e. a named project participant).
    protected int     $timeViewMinLevel    = 1;
    // v3.99.0 — Closing-phase artifact timestamp. Set by
    // ClosingArtifactService::generate to time() on success; the
    // Compass closing_artifact readiness item checks this. Regenerating
    // simply overwrites — always reflects the last run.
    protected ?int    $closingArtifactAt   = null;
    // v3.99.1 — manual-mark timestamps for the two Planning-phase items
    // whose "done" signal can't be checked programmatically. Set from the
    // Compass "Mark as done" button via PUT /project/marks/{markType}.
    protected ?int    $charterConfiguredAt = null;
    protected ?int    $kickoffMeetingAt    = null;
    // v3.99.3 — Closing-phase manual-mark. Advisory (doesn't gate); the
    // user can close the project without it if the retro isn't wanted.
    protected ?int    $evaluationMeetingAt = null;

    public function __construct() {
        $this->addType('startDate',            'integer');
        $this->addType('targetEnd',            'integer');
        $this->addType('createdAt',            'integer');
        $this->addType('updatedAt',            'integer');
        $this->addType('budgetTotalMinor',     'integer');
        $this->addType('budgetViewMinLevel',   'integer');
        $this->addType('timeViewMinLevel',     'integer');
        $this->addType('closingArtifactAt',    'integer');
        $this->addType('charterConfiguredAt',  'integer');
        $this->addType('kickoffMeetingAt',     'integer');
        $this->addType('evaluationMeetingAt',  'integer');
    }
}
