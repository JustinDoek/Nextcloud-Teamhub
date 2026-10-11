<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_dec_categories — a predefined Decisions category
 * owned by a team. Approver lists live in teamhub_dec_cat_apprs (m:n).
 *
 * icon:        Optional MDI icon name in PascalCase (e.g. 'Briefcase', 'Cog').
 *              Displayed in the category landing grid using the vue-material-
 *              design-icons component for that name. Max 64 chars. Null = no icon.
 * description: Optional short description (≤500 chars).
 *              Shown as a subtitle in the category landing grid.
 *
 * review_period_months (v4.11.18): 0 | N. After how many months an approved
 *              decision in this category needs looking at again by its
 *              approvers. 0 means never, and is what every category written
 *              before v4.11.18 carries. Setting it computes `review_due_at`
 *              on this category's approved decisions; clearing it to 0
 *              clears them again.
 * is_archive   (v4.11.18): 0 | 1. The team's one archive category, created
 *              with the module and never found: it has no approvers (the
 *              only category allowed none), its review period is fixed at 0,
 *              and it is neither renamed nor deleted. A superseded or
 *              archived decision moves into it, which is what makes a
 *              retired decision findable in a place rather than behind a
 *              filter.
 *
 * @method int     getId()
 * @method void    setId(int $id)
 * @method string  getTeamId()
 * @method void    setTeamId(string $teamId)
 * @method string  getName()
 * @method void    setName(string $name)
 * @method ?string getIcon()
 * @method void    setIcon(?string $icon)
 * @method ?string getDescription()
 * @method void    setDescription(?string $description)
 * @method ?string getCreatedBy()
 * @method void    setCreatedBy(?string $createdBy)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 * @method int     getUpdatedAt()
 * @method void    setUpdatedAt(int $updatedAt)
 * @method ?int    getReviewPeriodMonths()
 * @method void    setReviewPeriodMonths(int $reviewPeriodMonths)
 * @method void    setIsArchive(int $isArchive)
 */
class DecisionCategory extends Entity {

    protected string  $teamId      = '';
    protected string  $name        = '';
    protected ?string $icon        = null;
    protected ?string $description = null;
    /**
     * **Nullable so that writing `''` is not a no-op** (v4.11.22).
     *
     * Same trap as `DecisionReview::$kind` — `Entity::setter()` skips a set
     * whose value equals the current one, so with a `''` default
     * `setCreatedBy('')` never marked the field, `QBMapper::insert()` left
     * `created_by` out of the INSERT, and the column is `notnull` with **no
     * default**. Result: a NOT NULL violation on every category whose
     * author is unknown.
     *
     * Which is every category the `SeedDecisionCategories` repair step
     * tries to create, because a repair step has no session and therefore
     * no author — so **no team on any instance ever got its Archive
     * category**, and the step reported "0 created, 0 skipped" while doing
     * it, because `ensureSeedCategories()` caught each failure per category.
     *
     * Defaulting to null rather than making the column nullable keeps this
     * a one-line change with no migration: `'' !== null`, so the field is
     * marked, and the empty string reaches the NOT NULL column legitimately.
     * `createdBy()` below normalises the read.
     */
    protected ?string $createdBy   = null;
    protected int     $createdAt   = 0;
    protected int     $updatedAt   = 0;
    /**
     * v4.11.18. Nullable in the property so a row from a database that has
     * not run the migration yet cannot be read as a third state; the two
     * accessors below normalise, the way `Decision::isPublic()` does.
     */
    protected ?int    $reviewPeriodMonths = 0;
    protected ?int    $isArchive          = 0;

    public function __construct() {
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
        $this->addType('reviewPeriodMonths', 'integer');
        $this->addType('isArchive', 'integer');
    }

    /**
     * v4.11.22 — the author, normalised. Null means nobody: a category the
     * `SeedDecisionCategories` repair step created, which runs with no
     * session and so has no author to record. Display-only either way.
     */
    public function createdBy(): string {
        return (string)($this->createdBy ?? '');
    }

    /** v4.11.18 — the team's archive category. */
    public function isArchive(): bool {
        return (int)($this->isArchive ?? 0) === 1;
    }

    /** v4.11.18 — months, 0 = no review. */
    public function reviewPeriod(): int {
        return max(0, (int)($this->reviewPeriodMonths ?? 0));
    }
}
