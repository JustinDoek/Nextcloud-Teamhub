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
 * @method string  getCreatedBy()
 * @method void    setCreatedBy(string $createdBy)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 * @method int     getUpdatedAt()
 * @method void    setUpdatedAt(int $updatedAt)
 */
class DecisionCategory extends Entity {

    protected string  $teamId      = '';
    protected string  $name        = '';
    protected ?string $icon        = null;
    protected ?string $description = null;
    protected string  $createdBy   = '';
    protected int     $createdAt   = 0;
    protected int     $updatedAt   = 0;

    public function __construct() {
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }
}
