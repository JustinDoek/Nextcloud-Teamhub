<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_dec_cat_apprs — m:n approver list per category.
 * Either-one approval semantics: any user listed for a category may approve
 * decisions in that category (Session G Q2).
 *
 * @method int     getId()
 * @method void    setId(int $id)
 * @method int     getCategoryId()
 * @method void    setCategoryId(int $categoryId)
 * @method string  getUserId()
 * @method void    setUserId(string $userId)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 */
class DecisionCategoryApprover extends Entity {

    protected int    $categoryId = 0;
    protected string $userId     = '';
    protected int    $createdAt  = 0;

    public function __construct() {
        $this->addType('categoryId', 'integer');
        $this->addType('createdAt',  'integer');
    }
}
