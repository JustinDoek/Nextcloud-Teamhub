<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_dec_tasks — decision ↔ Deck card link.
 *
 * @method int    getId()
 * @method void   setId(int $id)
 * @method int    getDecisionId()
 * @method void   setDecisionId(int $decisionId)
 * @method int    getDeckCardId()
 * @method void   setDeckCardId(int $deckCardId)
 * @method int    getCreatedAt()
 * @method void   setCreatedAt(int $createdAt)
 * @method string getCreatedBy()
 * @method void   setCreatedBy(string $createdBy)
 */
class DecisionTaskLink extends Entity {

    protected int    $decisionId = 0;
    protected int    $deckCardId = 0;
    protected int    $createdAt  = 0;
    protected string $createdBy  = '';

    public function __construct() {
        $this->addType('decisionId', 'integer');
        $this->addType('deckCardId', 'integer');
        $this->addType('createdAt',  'integer');
    }
}
